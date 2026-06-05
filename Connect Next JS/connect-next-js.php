<?php
/**
 * Plugin Name: Connect Next Js
 * Description: Standalone premium form builder and manager. Stores submissions in local custom database, syncs to Firebase, automatically detects and sends brand-matching Admin and Client confirmation emails via custom SMTP with socket anti-collision delay, provides a customizable form shortcode [firebase_contact_form], and renders a premium admin dashboard.
 * Version: 4.6.0
 * Author: 2H Web Solution
 * Text Domain: fluent-forms-to-firebase
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// 1. Database Table Creation & Safety Check Engine
register_activation_hook(__FILE__, 'ff_firebase_create_db_table');

function ff_firebase_create_db_table() {
    ff_firebase_ensure_table_exists();
}

function ff_firebase_ensure_table_exists() {
    global $wpdb;
    $submissions_table = $wpdb->prefix . 'firebase_form_submissions';
    $forms_table = $wpdb->prefix . 'firebase_forms';
    
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // 1. Create or upgrade Submissions table
    if ($wpdb->get_var("SHOW TABLES LIKE '$submissions_table'") != $submissions_table) {
        $sql = "CREATE TABLE $submissions_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            address text NOT NULL,
            plz_ort varchar(100) NOT NULL,
            form_id bigint(20) DEFAULT 1,
            form_data longtext DEFAULT NULL,
            firebase_doc_id varchar(100) DEFAULT '',
            sync_status varchar(50) DEFAULT 'pending',
            sync_error text DEFAULT NULL,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql);
    } else {
        // Upgrade existing submissions table dynamically
        $row_cols = $wpdb->get_results("SHOW COLUMNS FROM $submissions_table", ARRAY_A);
        $cols = array_column($row_cols, 'Field');
        if (!in_array('form_id', $cols)) {
            $wpdb->query("ALTER TABLE $submissions_table ADD form_id bigint(20) DEFAULT 1");
        }
        if (!in_array('form_data', $cols)) {
            $wpdb->query("ALTER TABLE $submissions_table ADD form_data longtext DEFAULT NULL");
        }
    }

    // 2. Create Forms table if not exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$forms_table'") != $forms_table) {
        $sql = "CREATE TABLE $forms_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            fields_json longtext DEFAULT '',
            settings_json longtext DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql);

        // Seed with default form ID 1
        $default_fields = json_encode(ff_firebase_get_default_form_fields());
        $wpdb->insert($forms_table, array(
            'id' => 1,
            'title' => 'Contact Form',
            'fields_json' => $default_fields,
            'settings_json' => ''
        ));
    } else {
        // Upgrade existing forms table dynamically to add settings_json
        $row_cols = $wpdb->get_results("SHOW COLUMNS FROM $forms_table", ARRAY_A);
        $cols = array_column($row_cols, 'Field');
        if (!in_array('settings_json', $cols)) {
            $wpdb->query("ALTER TABLE $forms_table ADD settings_json longtext DEFAULT ''");
        }
    }

    // 3. Create Logs table if not exists
    $logs_table = $wpdb->prefix . 'firebase_form_logs';
    if ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") != $logs_table) {
        $sql = "CREATE TABLE $logs_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(100) NOT NULL,
            message text NOT NULL,
            status varchar(50) DEFAULT 'info',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql);
    }
}

// 1b. Helper: Save log entries
function ff_firebase_add_log($event_type, $message, $status = 'info') {
    global $wpdb;
    $logs_table = $wpdb->prefix . 'firebase_form_logs';
    $wpdb->insert($logs_table, array(
        'event_type' => sanitize_text_field($event_type),
        'message'    => sanitize_textarea_field($message),
        'status'     => sanitize_text_field($status),
        'created_at' => current_time('mysql')
    ));
}

// 1c. User Login/Logout/Failed Login Logging Hooks & Callbacks
add_action('wp_login', 'ff_firebase_log_user_login', 10, 2);
add_action('wp_login_failed', 'ff_firebase_log_user_login_failed', 10, 2);
add_action('wp_logout', 'ff_firebase_log_user_logout', 10, 1);

function ff_firebase_log_user_login($user_login, $user) {
    if (!$user instanceof WP_User) {
        return;
    }
    $roles = !empty($user->roles) ? implode(', ', $user->roles) : 'no role';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
    $message = sprintf(
        __('Successful login: Username: "%1$s", Role(s): [%2$s], IP Address: %3$s', 'fluent-forms-to-firebase'),
        $user_login,
        $roles,
        $ip
    );
    ff_firebase_add_log('login', $message, 'info');
}

function ff_firebase_log_user_login_failed($username, $error = null) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
    $message = sprintf(
        __('Failed login attempt for username: "%1$s", IP Address: %3$s', 'fluent-forms-to-firebase'),
        $username,
        $ip
    );
    ff_firebase_add_log('login', $message, 'warning');
}

function ff_firebase_log_user_logout($user_id) {
    $user = get_userdata($user_id);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
    if ($user instanceof WP_User) {
        $roles = !empty($user->roles) ? implode(', ', $user->roles) : 'no role';
        $message = sprintf(
            __('Successful logout: Username: "%1$s", Role(s): [%2$s], IP Address: %3$s', 'fluent-forms-to-firebase'),
            $user->user_login,
            $roles,
            $ip
        );
    } else {
        $message = sprintf(
            __('Successful logout: User ID: %1$d, IP Address: %2$s', 'fluent-forms-to-firebase'),
            $user_id,
            $ip
        );
    }
    ff_firebase_add_log('login', $message, 'info');
}

// 2. Register SMTP hooks with PHPMailer
add_action('phpmailer_init', 'ff_firebase_configure_smtp');

function ff_firebase_configure_smtp($phpmailer) {
    global $ff_active_form_settings;
    
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    
    // Merge active form-specific overrides if present
    if (!empty($ff_active_form_settings) && is_array($ff_active_form_settings)) {
        // ONLY override if form-specific SMTP is explicitly enabled.
        // If it is 'no', we keep global SMTP enabled / credentials.
        if (isset($ff_active_form_settings['smtp_enabled']) && $ff_active_form_settings['smtp_enabled'] === 'yes') {
            $options['smtp_enabled'] = 'yes';
            foreach ($ff_active_form_settings as $k => $v) {
                if ($k !== 'smtp_enabled' && $v !== '') {
                    $options[$k] = $v;
                }
            }
        }
    }
    
    if (empty($options['smtp_enabled']) || $options['smtp_enabled'] !== 'yes') {
        return;
    }

    // Defensive check: only configure SMTP if critical details are present
    if (empty($options['smtp_host']) || empty($options['smtp_username']) || empty($options['smtp_password'])) {
        return; 
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = sanitize_text_field($options['smtp_host']);
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = !empty($options['smtp_port']) ? intval($options['smtp_port']) : 587;
    $phpmailer->Username   = sanitize_text_field($options['smtp_username']);
    $phpmailer->Password   = isset($options['smtp_password']) ? $options['smtp_password'] : '';
    
    $encryption = sanitize_text_field($options['smtp_encryption']);
    if ($encryption === 'ssl' || $encryption === 'tls') {
        $phpmailer->SMTPSecure = $encryption;
    } else {
        $phpmailer->SMTPSecure = '';
    }

    if (!empty($options['smtp_from_email'])) {
        $phpmailer->From = sanitize_email($options['smtp_from_email']);
    }
    if (!empty($options['smtp_from_name'])) {
        $phpmailer->FromName = sanitize_text_field($options['smtp_from_name']);
    }

    // Bypasses SSL certificate verification issues common in some hosting setups
    $phpmailer->SMTPOptions = array(
        'ssl' => array(
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        )
    );
}

// 2a. FORCE WP MAIL HEADERS (CRITICAL for siteground, hostinger, cpanel SMTP authentication)
add_filter('wp_mail_from', 'ff_firebase_wp_mail_from', 999);
function ff_firebase_wp_mail_from($original_email_address) {
    global $ff_active_form_settings;
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    
    $smtp_enabled = isset($options['smtp_enabled']) ? $options['smtp_enabled'] : '';
    $smtp_from_email = isset($options['smtp_from_email']) ? $options['smtp_from_email'] : '';
    
    if (!empty($ff_active_form_settings) && is_array($ff_active_form_settings)) {
        if (isset($ff_active_form_settings['smtp_enabled']) && $ff_active_form_settings['smtp_enabled'] === 'yes') {
            $smtp_enabled = 'yes';
            if (isset($ff_active_form_settings['smtp_from_email']) && $ff_active_form_settings['smtp_from_email'] !== '') {
                $smtp_from_email = $ff_active_form_settings['smtp_from_email'];
            }
        }
    }

    if (!empty($smtp_enabled) && $smtp_enabled === 'yes' && !empty($smtp_from_email)) {
        return sanitize_email($smtp_from_email);
    }
    return $original_email_address;
}

add_filter('wp_mail_from_name', 'ff_firebase_wp_mail_from_name', 999);
function ff_firebase_wp_mail_from_name($original_from_name) {
    global $ff_active_form_settings;
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    
    $smtp_enabled = isset($options['smtp_enabled']) ? $options['smtp_enabled'] : '';
    $smtp_from_name = isset($options['smtp_from_name']) ? $options['smtp_from_name'] : '';
    
    if (!empty($ff_active_form_settings) && is_array($ff_active_form_settings)) {
        if (isset($ff_active_form_settings['smtp_enabled']) && $ff_active_form_settings['smtp_enabled'] === 'yes') {
            $smtp_enabled = 'yes';
            if (isset($ff_active_form_settings['smtp_from_name']) && $ff_active_form_settings['smtp_from_name'] !== '') {
                $smtp_from_name = $ff_active_form_settings['smtp_from_name'];
            }
        }
    }

    if (!empty($smtp_enabled) && $smtp_enabled === 'yes' && !empty($smtp_from_name)) {
        return sanitize_text_field($smtp_from_name);
    }
    return $original_from_name;
}

// 2a. Capture and log WordPress mail errors
add_action('wp_mail_failed', 'ff_firebase_capture_mail_error');

function ff_firebase_capture_mail_error($wp_error) {
    if (is_wp_error($wp_error)) {
        $error_msg = $wp_error->get_error_message();
        $error_data = $wp_error->get_error_data();
        
        $log_entry = array(
            'time'    => current_time('mysql'),
            'message' => $error_msg,
            'data'    => $error_data
        );
        
        update_option('ff_firebase_last_mail_error', $log_entry);
        
        // Also log to general error log
        error_log("Fluent Forms to Firebase mail failure: " . $error_msg . " - Data: " . print_r($error_data, true));
    }
}

// 2b. Register AJAX actions for Live SMTP Test Email
add_action('wp_ajax_ff_firebase_test_smtp', 'ff_firebase_ajax_test_smtp');

function ff_firebase_ajax_test_smtp() {
    // 1. Verify credentials and permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized user.', 'log' => 'User does not have manage_options capabilities.'));
    }
    
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ff_firebase_test_smtp_nonce')) {
        wp_send_json_error(array('message' => 'Invalid security token.', 'log' => 'Nonce verification failed.'));
    }
    
    $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
    if (empty($test_email) || !is_email($test_email)) {
        wp_send_json_error(array('message' => 'Invalid email address.', 'log' => 'Please provide a valid email address.'));
    }

    // Set temporary global active form settings if overrides were passed
    global $ff_active_form_settings;
    if (isset($_POST['overrides']) && is_array($_POST['overrides'])) {
        $ff_active_form_settings = $_POST['overrides'];
    }
    
    // 2. Setup PHPMailer debug capture
    global $ff_smtp_debug_log;
    $ff_smtp_debug_log = '';
    
    // Hook to force SMTP debug logging
    add_action('phpmailer_init', 'ff_firebase_enable_smtp_debug', 9999);
    
    // Clear last error before testing
    delete_option('ff_firebase_last_mail_error');
    
    // 3. Attempt to send mail
    $subject = 'ENGIN DENIZ - Live SMTP Test Email';
    $body = '<h1>SMTP Verification</h1><p>If you are reading this email, your custom SMTP configurations inside the ENGIN DENIZ WordPress plugin are perfectly functioning!</p>';
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    $mail_result = wp_mail($test_email, $subject, $body, $headers);
    
    // Clean up
    remove_action('phpmailer_init', 'ff_firebase_enable_smtp_debug', 9999);
    
    if ($mail_result) {
        wp_send_json_success(array(
            'message' => 'Test email successfully sent!',
            'log' => "SMTP Connection Handshake:\n" . $ff_smtp_debug_log
        ));
    } else {
        $extra_info = "Mail returned false.";
        // Check if there was a registered WP error
        $last_err = get_option('ff_firebase_last_mail_error');
        if (!empty($last_err)) {
            $extra_info = $last_err['message'];
        }
        wp_send_json_error(array(
            'message' => 'Failed to send mail. ' . $extra_info,
            'log' => "SMTP Connection Handshake:\n" . $ff_smtp_debug_log . "\n\n[Error Details]: " . $extra_info
        ));
    }
}

function ff_firebase_enable_smtp_debug($phpmailer) {
    global $ff_smtp_debug_log;
    
    // Make sure SMTP is configured
    ff_firebase_configure_smtp($phpmailer);
    
    // Force SMTP Debugging
    $phpmailer->SMTPDebug = 4; // Max output
    $phpmailer->Debugoutput = function($str, $level) {
        global $ff_smtp_debug_log;
        $ff_smtp_debug_log .= trim($str) . "\n";
    };
}


// Brand-matching plain text template for the Admin notification (HTML code removed)
function ff_firebase_get_default_admin_template() {
    return "Hallo Admin,\n\neine neue Formularübermittlung wurde erfolgreich empfangen. Hier sind die erfassten Angaben:\n\n🧑 Name: {name}\n✉️ E-Mail: {email}\n🏠 Adresse: {address}\n📍 PLZ / Ort: {plz_ort}\n📅 Datum/Uhrzeit: {submitted_at}\n\nDiese Nachricht wurde automatisch vom ENGIN DENIZ Web Manager gesendet.";
}

// Brand-matching plain text template for the Client thank-you notification (HTML code removed)
function ff_firebase_get_default_client_template() {
    return "Sehr geehrte(r) {name},\n\nvielen Dank für Ihre Registrierung über unsere Website. Wir haben Ihre Angaben erfolgreich erhalten und in unserem System erfasst.\n\nZusammenfassung Ihrer Angaben:\n- Name: {name}\n- E-Mail: {email}\n- Adresse: {address}\n- PLZ / Ort: {plz_ort}\n- Datum: {submitted_at}\n\nUnser Anwaltsteam wird Ihre Dokumente und Informationen prüfen und sich in Kürze persönlich mit Ihnen in Verbindung setzen.\n\nFalls Sie Fragen haben oder uns zusätzliche Unterlagen zukommen lassen möchten, antworten Sie einfach direkt auf diese E-Mail.\n\nMit freundlichen Grüßen,\nENGIN DENIZ Rechtsanwälte";
}

// Automatically wraps a plain text or HTML email body in a beautiful, responsive, website-branded template matching the current site's name and custom logo
function ff_firebase_wrap_email_body($body, $form_settings = array()) {
    $site_name = get_bloginfo('name');
    $site_url = get_bloginfo('url');
    $custom_logo_id = get_theme_mod('custom_logo');
    $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';

    $logo_html = '';
    if (!empty($logo_url)) {
        $logo_html = '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . ' Logo" style="max-height:60px; max-width:100%; border:none; display:inline-block; vertical-align:middle;">';
    } else {
        $logo_html = '<h2 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: 0.5px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">' . esc_html($site_name) . '</h2>';
    }

    $footer_text = sprintf(
        __('&copy; %d <a href="%s" style="color: #9ca3af; text-decoration: none; font-weight: 600;">%s</a>. All rights reserved.', 'fluent-forms-to-firebase'),
        date('Y'),
        esc_url($site_url),
        esc_html($site_name)
    );

    // Convert plain text newlines to paragraphs safely if not already HTML
    if (!preg_match('/<[a-z][\s\S]*>/i', $body)) {
        $body = wpautop(nl2br($body));
    }

    $template = '<div style="background-color: #f3f4f6; padding: 40px 20px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 5px solid #78951D;">
    <div style="background-color: #3a3a3a; padding: 30px 25px; text-align: center;">
      ' . $logo_html . '
    </div>
    <div style="padding: 40px 30px; font-size: 14px; background: #ffffff;">
      ' . $body . '
    </div>
    <div style="background-color: #fafafa; padding: 25px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb; line-height: 1.5;">
      ' . $footer_text . '
    </div>
  </div>
</div>';

    return $template;
}

// Contrast color helper (YIQ algorithm) to ensure text on colored headers is readable
function ff_firebase_get_contrast_color($hex) {
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) != 6) {
        return '#ffffff';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    return ($yiq >= 128) ? '#111827' : '#ffffff';
}

// Helper to convert hex to rgba for tinted backgrounds (like callouts/alerts)
function ff_firebase_hex_to_rgba($hex, $opacity = 0.05) {
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) != 6) {
        return "rgba(0, 0, 0, $opacity)";
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "rgba($r, $g, $b, $opacity)";
}


// Compiles visual email body based on 10 preset templates
function ff_firebase_compile_email_html($template_id, $primary_color, $bg_color, $text_color, $logo_url, $logo_width, $title, $body_text, $footer_text, $header_bg_color = '', $card_bg_color = '', $footer_bg_color = '', $callout_text = '') {
    $template_id = strval($template_id);
    if (empty($primary_color)) $primary_color = '#78951D';
    if (empty($bg_color)) $bg_color = '#f3f4f6';
    if (empty($text_color)) $text_color = '#1f2937';
    if (empty($logo_width)) $logo_width = 120;

    // Resolve header contrast color dynamically
    $active_header_bg = $header_bg_color;
    if (empty($active_header_bg)) {
        if ($template_id === '6') $active_header_bg = $primary_color;
        else if ($template_id === '9') $active_header_bg = '#111827';
        else if ($template_id === '3') $active_header_bg = '#161920';
        else if ($template_id === '7') $active_header_bg = '#f8fafc';
        else $active_header_bg = '#ffffff';
    }
    $header_contrast = ff_firebase_get_contrast_color($active_header_bg);
    
    $logo_html = '';
    if (!empty($logo_url)) {
        $logo_html = '<img src="' . esc_url($logo_url) . '" alt="Logo" style="max-width: ' . intval($logo_width) . 'px; height: auto; border: none; display: inline-block; vertical-align: middle;">';
    } else {
        $header_text_color = ($template_id === '6' || $template_id === '9' || !empty($header_bg_color)) ? $header_contrast : $text_color;
        if ($template_id === '3' && empty($header_bg_color)) $header_text_color = '#ffffff';
        $logo_html = '<h2 style="margin: 0; color: ' . esc_attr($header_text_color) . '; font-size: 22px; font-weight: 700; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">' . esc_html(get_bloginfo('name')) . '</h2>';
    }

    // Callout alert box layout
    $callout_html = '';
    if (!empty($callout_text) && $callout_text !== '[none]') {
        $callout_bg = ff_firebase_hex_to_rgba($primary_color, 0.05);
        $callout_html = '<div style="border-left: 4px solid ' . esc_attr($primary_color) . '; background-color: ' . esc_attr($callout_bg) . '; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; color: ' . esc_attr($text_color) . '; text-align: left; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">' . esc_html($callout_text) . '</div>';
    }

    if (!preg_match('/<[a-z][\s\S]*>/i', $body_text)) {
        $body_text = wpautop(nl2br($body_text));
    }
    if (!empty($callout_html)) {
        $body_text .= $callout_html;
    }

    if (!preg_match('/<[a-z][\s\S]*>/i', $footer_text)) {
        $footer_text = nl2br($footer_text);
    }

    $html = '';
    
    switch ($template_id) {
        case '2': // Left-Aligned Classic (Corporate)
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 40px 20px; font-family: Georgia, serif; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border-radius: 4px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
    <div style="padding: 30px 40px; border-bottom: 2px solid ' . esc_attr($primary_color) . '; background: ' . ($header_bg_color ? esc_attr($header_bg_color) : 'transparent') . ';">
      ' . $logo_html . '
      <h2 style="margin: 20px 0 0 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : '#111827') . '; font-size: 24px; font-weight: 600; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">' . esc_html($title) . '</h2>
    </div>
    <div style="padding: 40px; font-size: 15px; color: ' . esc_attr($text_color) . ';">
      ' . $body_text . '
    </div>
    <div style="background-color: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : '#f9fafb') . '; padding: 25px 40px; font-size: 11px; color: #6b7280; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; border-top: 1px solid #e5e7eb;">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '3': // Sleek Dark Mode
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 40px 20px; font-family: \'SFMono-Regular\', Consolas, monospace; color: #e5e7eb; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#1e222b') . '; border-radius: 8px; overflow: hidden; border: 1px solid #2e3440; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">
    <div style="background-color: ' . ($header_bg_color ? esc_attr($header_bg_color) : '#161920') . '; padding: 30px; text-align: center; border-bottom: 2px solid ' . esc_attr($primary_color) . ';">
      ' . $logo_html . '
      <h2 style="margin: 15px 0 0 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : esc_attr($primary_color)) . '; font-size: 20px; font-weight: 700; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">' . esc_html($title) . '</h2>
    </div>
    <div style="padding: 35px 30px; font-size: 14px; color: #d8dee9; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">
      ' . $body_text . '
    </div>
    <div style="background-color: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : '#161920') . '; padding: 25px; text-align: center; font-size: 11px; color: #707880; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; border-top: 1px solid #2e3440;">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '4': // Accent Sidebar
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 40px 20px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: table; width: 100%;">
    <div style="display: table-cell; width: 8px; background-color: ' . esc_attr($primary_color) . ';">&nbsp;</div>
    <div style="display: table-cell; vertical-align: top; padding: 35px 30px 25px 30px; background: ' . ($header_bg_color ? esc_attr($header_bg_color) : 'transparent') . ';">
      <div style="margin-bottom: 25px;">
        ' . $logo_html . '
      </div>
      <h2 style="margin: 0 0 20px 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : '#111827') . '; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;">' . esc_html($title) . '</h2>
      <div style="font-size: 14px; color: ' . esc_attr($text_color) . '; margin-bottom: 30px;">
        ' . $body_text . '
      </div>
      <div style="padding-top: 20px; border-top: 1px solid #f3f4f6; font-size: 11px; color: #9ca3af; background: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : 'transparent') . ';">
        ' . $footer_text . '
      </div>
    </div>
  </div>
</div>';
            break;
            
        case '5': // Soft Rounded (Friendly/Modern)
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 50px 20px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.03); padding: 40px 35px;">
    <div style="text-align: center; margin-bottom: 30px; background: ' . ($header_bg_color ? esc_attr($header_bg_color) : 'transparent') . '; padding: ' . ($header_bg_color ? '20px' : '0') . '; border-radius: 12px;">
      <div style="display: inline-block; background-color: #fafafa; padding: 15px 25px; border-radius: 16px; border: 1px solid #f0f0f0;">
        ' . $logo_html . '
      </div>
      <h2 style="margin: 25px 0 0 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : '#0f172a') . '; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">' . esc_html($title) . '</h2>
    </div>
    <div style="font-size: 15px; color: ' . esc_attr($text_color) . '; margin-bottom: 35px;">
      ' . $body_text . '
    </div>
    <div style="text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 25px; background: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : 'transparent') . ';">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '6': // Bold Colored Header Block
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 40px 20px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06);">
    <div style="background-color: ' . ($header_bg_color ? esc_attr($header_bg_color) : esc_attr($primary_color)) . '; padding: 40px 30px; text-align: center; color: ' . esc_attr($header_contrast) . ';">
      ' . $logo_html . '
      <h2 style="margin: 20px 0 0 0; color: ' . esc_attr($header_contrast) . '; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;">' . esc_html($title) . '</h2>
    </div>
    <div style="padding: 35px 30px; font-size: 14px; color: ' . esc_attr($text_color) . ';">
      ' . $body_text . '
    </div>
    <div style="background-color: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : '#fafafa') . '; padding: 25px 30px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #f3f4f6;">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '7': // Two-Tone Minimal Card
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 40px 20px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border-radius: 8px; overflow: hidden; border: 2px solid #e5e7eb;">
    <div style="background-color: ' . ($header_bg_color ? esc_attr($header_bg_color) : '#f8fafc') . '; padding: 25px 30px; border-bottom: 2px solid ' . esc_attr($primary_color) . '; overflow: hidden;">
      <div style="float: left;">' . $logo_html . '</div>
      <div style="float: right; margin-top: 8px;"><h3 style="margin: 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : '#1e293b') . '; font-size: 16px; font-weight: 700; text-align: right;">' . esc_html($title) . '</h3></div>
      <div style="clear: both;"></div>
    </div>
    <div style="padding: 30px; font-size: 14px; color: ' . esc_attr($text_color) . ';">
      ' . $body_text . '
    </div>
    <div style="background-color: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : '#f8fafc') . '; padding: 20px 30px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0;">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '8': // High-Contrast Technical Grid
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 45px 20px; font-family: \'Courier New\', Courier, monospace; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 650px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border: 2px solid #000000; box-shadow: 6px 6px 0px #000000; padding: 30px;">
    <div style="border-bottom: 2px solid #000000; padding-bottom: 20px; margin-bottom: 25px; background: ' . ($header_bg_color ? esc_attr($header_bg_color) : 'transparent') . ';">
      <div style="margin-bottom: 15px;">' . $logo_html . '</div>
      <h2 style="margin: 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : '#000000') . '; font-size: 20px; font-weight: 700; text-transform: uppercase;">[ ' . esc_html($title) . ' ]</h2>
    </div>
    <div style="font-size: 13px; color: ' . esc_attr($text_color) . '; margin-bottom: 30px;">
      ' . $body_text . '
    </div>
    <div style="border-top: 2px solid #000000; padding-top: 20px; font-size: 10px; color: #7f8c8d; text-transform: uppercase; background: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : 'transparent') . ';">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '9': // Elegant Hero Banner
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 40px 20px; font-family: Georgia, serif; color: ' . esc_attr($text_color) . '; line-height: 1.7;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.04); border: 1px solid #f0f0f0;">
    <div style="background: ' . ($header_bg_color ? esc_attr($header_bg_color) : 'linear-gradient(135deg, ' . esc_attr($primary_color) . ' 0%, #111827 100%)') . '; padding: 50px 30px; text-align: center; color: ' . esc_attr($header_contrast) . ';">
      <div style="display: inline-block; margin-bottom: 20px;">' . $logo_html . '</div>
      <h2 style="margin: 0; color: ' . esc_attr($header_contrast) . '; font-size: 26px; font-weight: 700; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; letter-spacing: -0.5px;">' . esc_html($title) . '</h2>
    </div>
    <div style="padding: 40px 35px; font-size: 14px; color: ' . esc_attr($text_color) . '; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">
      ' . $body_text . '
    </div>
    <div style="background-color: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : '#fafafa') . '; padding: 25px 35px; text-align: center; font-size: 11px; color: #9ca3af; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; border-top: 1px solid #f3f4f6;">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '10': // Clean Compact Strip
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 30px 10px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 550px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : 'transparent') . '; padding: 10px;">
    <div style="margin-bottom: 25px; border-bottom: 2px solid ' . esc_attr($primary_color) . '; padding-bottom: 15px; background: ' . ($header_bg_color ? esc_attr($header_bg_color) : 'transparent') . ';">
      ' . $logo_html . '
      <h2 style="margin: 10px 0 0 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : '#111827') . '; font-size: 20px; font-weight: 800; letter-spacing: -0.5px;">' . esc_html($title) . '</h2>
    </div>
    <div style="font-size: 14px; color: ' . esc_attr($text_color) . '; margin-bottom: 30px;">
      ' . $body_text . '
    </div>
    <div style="border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 11px; color: #94a3b8; text-align: left; background: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : 'transparent') . ';">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
            
        case '1': // Modern Centered (Default)
        default:
            $html = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 40px 20px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: ' . esc_attr($text_color) . '; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 6px solid ' . esc_attr($primary_color) . ';">
    <div style="padding: 30px; text-align: center; border-bottom: 1px solid #f3f4f6; background: ' . ($header_bg_color ? esc_attr($header_bg_color) : 'transparent') . ';">
      ' . $logo_html . '
      <h2 style="margin: 15px 0 0 0; color: ' . ($header_bg_color ? esc_attr($header_contrast) : '#111827') . '; font-size: 22px; font-weight: 700;">' . esc_html($title) . '</h2>
    </div>
    <div style="padding: 30px; font-size: 14px; background: ' . ($card_bg_color ? esc_attr($card_bg_color) : '#ffffff') . '; color: ' . esc_attr($text_color) . ';">
      ' . $body_text . '
    </div>
    <div style="background-color: ' . ($footer_bg_color ? esc_attr($footer_bg_color) : '#fafafa') . '; padding: 20px 30px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb;">
      ' . $footer_text . '
    </div>
  </div>
</div>';
            break;
    }
    
    return $html;
}

// Merges overrides and resolves placeholders to render final templated HTML email
function ff_firebase_render_templated_email($type, $sub, $form_settings, $options) {
    global $wpdb;
    
    if ($type !== 'admin' && $type !== 'client') {
        $type = 'admin';
    }
    
    $prefix = $type . '_email_';
    
    // 1. Gather all settings values with overrides & fallbacks
    $template_id = !empty($form_settings[$prefix . 'template_id']) ? $form_settings[$prefix . 'template_id'] : '';
    if (empty($template_id) || $template_id === 'inherit') {
        $template_id = !empty($options[$prefix . 'template_id']) ? $options[$prefix . 'template_id'] : '1';
    }
    
    $primary_color = !empty($form_settings[$prefix . 'primary_color']) ? $form_settings[$prefix . 'primary_color'] : '';
    if (empty($primary_color) || $primary_color === 'inherit') {
        $primary_color = !empty($options[$prefix . 'primary_color']) ? $options[$prefix . 'primary_color'] : '#78951D';
    }
    
    $bg_color = !empty($form_settings[$prefix . 'bg_color']) ? $form_settings[$prefix . 'bg_color'] : '';
    if (empty($bg_color) || $bg_color === 'inherit') {
        $bg_color = !empty($options[$prefix . 'bg_color']) ? $options[$prefix . 'bg_color'] : '#f3f4f6';
    }
    
    $text_color = !empty($form_settings[$prefix . 'text_color']) ? $form_settings[$prefix . 'text_color'] : '';
    if (empty($text_color) || $text_color === 'inherit') {
        $text_color = !empty($options[$prefix . 'text_color']) ? $options[$prefix . 'text_color'] : '#1f2937';
    }

    $header_bg_color = !empty($form_settings[$prefix . 'header_bg_color']) ? $form_settings[$prefix . 'header_bg_color'] : '';
    if (empty($header_bg_color) || $header_bg_color === 'inherit') {
        $header_bg_color = !empty($options[$prefix . 'header_bg_color']) ? $options[$prefix . 'header_bg_color'] : '';
    }

    $card_bg_color = !empty($form_settings[$prefix . 'card_bg_color']) ? $form_settings[$prefix . 'card_bg_color'] : '';
    if (empty($card_bg_color) || $card_bg_color === 'inherit') {
        $card_bg_color = !empty($options[$prefix . 'card_bg_color']) ? $options[$prefix . 'card_bg_color'] : '';
    }

    $footer_bg_color = !empty($form_settings[$prefix . 'footer_bg_color']) ? $form_settings[$prefix . 'footer_bg_color'] : '';
    if (empty($footer_bg_color) || $footer_bg_color === 'inherit') {
        $footer_bg_color = !empty($options[$prefix . 'footer_bg_color']) ? $options[$prefix . 'footer_bg_color'] : '';
    }

    $callout_text = !empty($form_settings[$prefix . 'callout']) ? $form_settings[$prefix . 'callout'] : '';
    if (empty($callout_text) || $callout_text === 'inherit') {
        $callout_text = isset($options[$prefix . 'callout']) ? $options[$prefix . 'callout'] : '';
    }
    
    $logo_url = !empty($form_settings[$prefix . 'logo']) ? $form_settings[$prefix . 'logo'] : '';
    if (empty($logo_url) || $logo_url === 'inherit') {
        $logo_url = !empty($options[$prefix . 'logo']) ? $options[$prefix . 'logo'] : '';
    }
    if (empty($logo_url)) {
        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';
    }
    
    $logo_width = !empty($form_settings[$prefix . 'logo_width']) ? intval($form_settings[$prefix . 'logo_width']) : 0;
    if ($logo_width <= 0 || $form_settings[$prefix . 'logo_width'] === 'inherit') {
        $logo_width = !empty($options[$prefix . 'logo_width']) ? intval($options[$prefix . 'logo_width']) : 120;
    }
    if ($logo_width <= 0) {
        $logo_width = 120;
    }
    
    // Get form setting or inherit
    $title = isset($form_settings[$prefix . 'title']) ? $form_settings[$prefix . 'title'] : 'inherit';
    if ($title === 'inherit') {
        $title = isset($options[$prefix . 'title']) ? $options[$prefix . 'title'] : 'default_fallback';
    }
    if ($title === 'default_fallback') {
        $title = ($type === 'admin') ? 'Neues Formular empfangen' : 'Vielen Dank für Ihre Registrierung';
    }
    
    $body_text = !empty($form_settings[$prefix . 'body']) ? $form_settings[$prefix . 'body'] : '';
    if (empty($body_text) || $body_text === 'inherit') {
        $body_text = !empty($options[$prefix . 'body']) ? $options[$prefix . 'body'] : '';
    }
    if (empty($body_text)) {
        // Fallback to old settings key if exists and is not raw HTML (we will check if new system isn't filled)
        if ($type === 'admin' && !empty($options['email_template']) && !preg_match('/<[a-z][\s\S]*>/i', $options['email_template'])) {
            $body_text = $options['email_template'];
        } else if ($type === 'client' && !empty($options['client_email_template']) && !preg_match('/<[a-z][\s\S]*>/i', $options['client_email_template'])) {
            $body_text = $options['client_email_template'];
        } else {
            $body_text = ($type === 'admin') ? ff_firebase_get_default_admin_template() : ff_firebase_get_default_client_template();
        }
    }
    
    $footer_text = !empty($form_settings[$prefix . 'footer']) ? $form_settings[$prefix . 'footer'] : '';
    if (empty($footer_text) || $footer_text === 'inherit') {
        $footer_text = !empty($options[$prefix . 'footer']) ? $options[$prefix . 'footer'] : '';
    }
    if (empty($footer_text)) {
        $footer_text = ($type === 'admin') ? '&copy; {year} {site_name}. Alle Rechte vorbehalten.' : 'Mit freundlichen Grüßen,\n{site_name}';
    }
    
    // 2. Generate the {all_fields} HTML table (header-less, customized cell padding & email links)
    $form_data = json_decode($sub->form_data, true);
    if (!is_array($form_data)) {
        $form_data = array();
    }
    
    $form_id = intval($sub->form_id);
    $form_row = $wpdb->get_row($wpdb->prepare("SELECT fields_json FROM {$wpdb->prefix}firebase_forms WHERE id = %d", $form_id));
    $fields = array();
    if ($form_row && !empty($form_row->fields_json)) {
        $fields = json_decode(wp_unslash($form_row->fields_json), true);
    }
    if (empty($fields)) {
        $fields = ff_firebase_get_default_form_fields();
    }
    
    $all_fields_html = '<table style="width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #e5e7eb; font-size: 14px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">';
    $all_fields_html .= '<tbody>';
    $bg_toggle = true;
    
    foreach ($fields as $field) {
        $fid = isset($field['id']) ? $field['id'] : '';
        if (empty($fid) || (isset($field['type']) && $field['type'] === 'custom_submit')) {
            continue;
        }
        $label = !empty($field['label']) ? $field['label'] : $fid;
        $value = isset($form_data[$fid]) ? $form_data[$fid] : '';
        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        // Color emails using the Primary brand/accent color
        if (filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
            $value_html = '<a href="mailto:' . esc_attr(trim($value)) . '" style="color: ' . esc_attr($primary_color) . '; text-decoration: none; font-weight: 600;">' . esc_html($value) . '</a>';
        } else {
            $value_html = esc_html($value);
        }

        $row_bg = $bg_toggle ? '#ffffff' : '#f9fafb';
        $bg_toggle = !$bg_toggle;
        $all_fields_html .= '<tr style="background-color: ' . $row_bg . ';"><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">' . esc_html($label) . '</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">' . $value_html . '</td></tr>';
    }
    $all_fields_html .= '</tbody></table>';
    
    // 3. Resolve Placeholders
    $recipient_email = !empty($sub->email) ? $sub->email : '';
    if (empty($recipient_email) && $type === 'client') {
        foreach ($form_data as $k => $v) {
            if (is_string($v) && filter_var(trim($v), FILTER_VALIDATE_EMAIL)) {
                $recipient_email = trim($v);
                break;
            }
        }
    }
    
    $replacements = array(
        '{name}'         => esc_html($sub->name),
        '{email}'        => esc_html($recipient_email),
        '{address}'      => esc_html($sub->address),
        '{plz_ort}'      => esc_html($sub->plz_ort),
        '{submitted_at}' => esc_html(date('Y-m-d H:i:s', strtotime($sub->submitted_at))),
        '{site_name}'    => esc_html(get_bloginfo('name')),
        '{site_url}'     => esc_url(get_bloginfo('url')),
        '{year}'         => date('Y'),
        '{all_fields}'   => $all_fields_html
    );
    
    // Support legacy raw HTML template from previous updates if no visual template is set
    // and if the old template setting exists and actually contains HTML tags
    $legacy_template_key = ($type === 'admin') ? 'email_template' : 'client_email_template';
    $legacy_body = isset($form_settings[$legacy_template_key]) ? $form_settings[$legacy_template_key] : (isset($options[$legacy_template_key]) ? $options[$legacy_template_key] : '');
    
    if (preg_match('/<[a-z][\s\S]*>/i', $legacy_body) && empty($form_settings[$prefix . 'template_id']) && empty($options[$prefix . 'template_id'])) {
        $body_resolved = str_replace(array_keys($replacements), array_values($replacements), $legacy_body);
        return $body_resolved;
    }
    
    $title_resolved = str_replace(array_keys($replacements), array_values($replacements), $title);
    $body_resolved = str_replace(array_keys($replacements), array_values($replacements), $body_text);
    $footer_resolved = str_replace(array_keys($replacements), array_values($replacements), $footer_text);
    
    return ff_firebase_compile_email_html(
        $template_id,
        $primary_color,
        $bg_color,
        $text_color,
        $logo_url,
        $logo_width,
        $title_resolved,
        $body_resolved,
        $footer_resolved,
        $header_bg_color,
        $card_bg_color,
        $footer_bg_color,
        $callout_text
    );
}

// Default dynamic form fields schema
function ff_firebase_get_default_form_fields() {
    return array(
        array('id' => 'name', 'label' => 'Name', 'type' => 'text', 'placeholder' => 'Name', 'required' => true),
        array('id' => 'address', 'label' => 'Address', 'type' => 'text', 'placeholder' => 'Address', 'required' => true),
        array('id' => 'plz_ort', 'label' => 'PLZ / Ort', 'type' => 'text', 'placeholder' => 'PLZ / Ort', 'required' => false),
        array('id' => 'email', 'label' => 'Email', 'type' => 'email', 'placeholder' => 'Email', 'required' => true),
        array('id' => 'submit_btn', 'label' => 'Register', 'type' => 'custom_submit', 'placeholder' => '', 'required' => false)
    );
}

// 3. Add WordPress Settings & Submissions Admin Menu
add_action('admin_menu', 'ff_firebase_add_admin_menu');
add_action('admin_init', 'ff_firebase_settings_init');
add_action('admin_init', 'ff_firebase_save_admin_settings');
add_action('admin_head', 'ff_firebase_admin_custom_css');
add_action('wp_head', 'ff_firebase_print_custom_font_styles', 100);
add_action('admin_head', 'ff_firebase_print_custom_font_styles', 100);
add_action('enqueue_block_editor_assets', 'ff_firebase_enqueue_editor_custom_fonts');
add_filter('block_editor_settings_all', 'ff_firebase_add_custom_fonts_to_gutenberg', 10, 2);
add_filter('wp_theme_json_data_theme', 'ff_firebase_add_custom_fonts_to_theme_json', 20);
add_filter('upload_mimes', 'ff_firebase_allow_font_uploads');
add_filter('wp_check_filetype_and_ext', 'ff_firebase_fix_font_mime_types', 10, 5);

function ff_firebase_add_admin_menu() {
    // Parent Page: Connect Next Js
    add_menu_page(
        'Connect Next Js',
        'Connect Next Js',
        'manage_options',
        'firebase_submissions',
        'ff_firebase_submissions_page',
        'data:image/svg+xml;base64,' . base64_encode('<svg viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg"><circle cx="90" cy="90" r="90" fill="black" /><path d="M149.508 157.52L69.142 54H54V125.97H66.1136V69.3836L139.999 164.845C143.333 162.614 146.509 160.165 149.508 157.52Z" fill="white" /><rect fill="white" height="72" width="12" x="114" y="54" /></svg>'),
        25
    );

    // Override the default inherited parent title for the first submenu item to be 'Submissions'
    add_submenu_page(
        'firebase_submissions',
        'Form Submissions',
        'Submissions',
        'manage_options',
        'firebase_submissions',
        'ff_firebase_submissions_page'
    );

    // 1. Submenu: Forms
    add_submenu_page(
        'firebase_submissions',
        'Visual Form Fields Builder',
        'Forms',
        'manage_options',
        'firebase_forms',
        'ff_firebase_forms_page'
    );

    // 2. Submenu: Firebase
    add_submenu_page(
        'firebase_submissions',
        'Firebase Firestore Setup',
        'Firebase',
        'manage_options',
        'firebase_setup',
        'ff_firebase_setup_page'
    );

    // 3. Submenu: SMTP Options
    add_submenu_page(
        'firebase_submissions',
        'SMTP Server Options',
        'SMTP',
        'manage_options',
        'firebase_smtp',
        'ff_firebase_smtp_page'
    );

    // 4. Submenu: Admin Email
    add_submenu_page(
        'firebase_submissions',
        'Admin Notifications',
        'Admin Email',
        'manage_options',
        'firebase_admin_email',
        'ff_firebase_admin_email_page'
    );

    // 5. Submenu: Client Email
    add_submenu_page(
        'firebase_submissions',
        'Client Thank-You Emails',
        'Client Email',
        'manage_options',
        'firebase_client_email',
        'ff_firebase_client_email_page'
    );

    // 6. Submenu: Google Maps
    add_submenu_page(
        'firebase_submissions',
        'Google Maps Embed API',
        'Google Maps',
        'manage_options',
        'firebase_google_maps',
        'ff_firebase_google_maps_page'
    );

    // 7. Submenu: Google reCAPTCHA
    add_submenu_page(
        'firebase_submissions',
        'Google reCAPTCHA Settings',
        'reCAPTCHA',
        'manage_options',
        'firebase_recaptcha',
        'ff_firebase_recaptcha_page'
    );

    // 8. Submenu: Search & Replace
    add_submenu_page(
        'firebase_submissions',
        'Database Search & Replace',
        'Search & Replace',
        'manage_options',
        'firebase_search_replace',
        'ff_firebase_search_replace_page'
    );

    // 9. Submenu: SSL Setup
    add_submenu_page(
        'firebase_submissions',
        'SSL Setup & Enforcer',
        'SSL Setup',
        'manage_options',
        'firebase_ssl',
        'ff_firebase_ssl_page'
    );

    // 10. Submenu: Log History
    add_submenu_page(
        'firebase_submissions',
        'Activity Log History',
        'Log History',
        'manage_options',
        'firebase_log_history',
        'ff_firebase_log_history_page'
    );

    // 11. Submenu: Custom Fonts
    add_submenu_page(
        'firebase_submissions',
        'Custom Fonts',
        'Custom Fonts',
        'manage_options',
        'firebase_custom_fonts',
        'ff_firebase_custom_fonts_page'
    );
}

function ff_firebase_settings_init() {
    register_setting('ffFirebasePlugin', 'ff_firebase_settings');
}

// 3b. Custom safe settings POST handler to merge and save settings page-by-page
function ff_firebase_save_admin_settings() {
    global $wpdb;
    $forms_table = $wpdb->prefix . 'firebase_forms';

    // 0. Intercept Delete Custom Font via GET request (from admin submenu or builder)
    if (isset($_GET['page']) && $_GET['page'] === 'firebase_custom_fonts' && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['font_name'])) {
        if (!current_user_can('manage_options')) {
            return;
        }
        $font_name = sanitize_text_field($_GET['font_name']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'delete_font_' . $font_name)) {
            $fonts = get_option('ff_firebase_custom_fonts', array());
            if (is_array($fonts) && isset($fonts[$font_name])) {
                unset($fonts[$font_name]);
                update_option('ff_firebase_custom_fonts', $fonts);
                ff_firebase_add_log('settings', "Custom font deleted: {$font_name}.", 'info');
            }
            wp_safe_redirect(admin_url('admin.php?page=firebase_custom_fonts&settings-updated=true'));
            exit;
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete_font' && isset($_GET['font_name'])) {
        if (!current_user_can('manage_options')) {
            return;
        }
        $font_name = sanitize_text_field($_GET['font_name']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'delete_font_' . $font_name)) {
            $fonts = get_option('ff_firebase_custom_fonts', array());
            if (is_array($fonts) && isset($fonts[$font_name])) {
                unset($fonts[$font_name]);
                update_option('ff_firebase_custom_fonts', $fonts);
                ff_firebase_add_log('settings', "Custom font deleted from builder: {$font_name}.", 'info');
            }
            wp_safe_redirect(remove_query_arg(array('action', 'font_name', '_wpnonce'), wp_get_referer()));
            exit;
        }
    }

    // A. Intercept Add/Create New Form
    if (isset($_GET['page']) && $_GET['page'] === 'firebase_forms' && isset($_GET['action']) && $_GET['action'] === 'create') {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ff_firebase_ensure_table_exists();

        // Get count to name it "Untitled Form X"
        $count = intval($wpdb->get_var("SELECT COUNT(*) FROM $forms_table")) + 1;
        $title = 'Untitled Form ' . $count;
        $default_fields = json_encode(ff_firebase_get_default_form_fields());

        $wpdb->insert($forms_table, array(
            'title' => $title,
            'fields_json' => $default_fields
        ));
        $new_id = $wpdb->insert_id;

        // Redirect directly to the Edit Creator for this new form!
        wp_safe_redirect(admin_url('admin.php?page=firebase_forms&action=edit&id=' . $new_id . '&created=true'));
        exit;
    }

    // B. Intercept Delete Form
    if (isset($_GET['page']) && $_GET['page'] === 'firebase_forms' && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Verify nonce for safety
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_form_' . $_GET['id'])) {
            ff_firebase_ensure_table_exists();
            $form_id = intval($_GET['id']);
            
            // Delete the form row
            $wpdb->delete($forms_table, array('id' => $form_id));
            
            // Redirect back to dashboard table list
            wp_safe_redirect(admin_url('admin.php?page=firebase_forms&deleted=true'));
            exit;
        }
    }

    // C. Intercept Save Form Layout (POST submit from builder)
    if (isset($_POST['ff_action_save_form']) && wp_verify_nonce($_POST['ff_save_form_nonce'], 'ff_save_form_action')) {
        if (!current_user_can('manage_options')) {
            return;
        }

        // A. Handle Custom Font Additions / Deletions
        if (isset($_POST['ff_add_custom_font']) && !empty($_POST['ff_new_font_name']) && !empty($_POST['ff_new_font_url'])) {
            $font_name = sanitize_text_field($_POST['ff_new_font_name']);
            $font_url = esc_url_raw($_POST['ff_new_font_url']);
            $fonts = get_option('ff_firebase_custom_fonts', array());
            if (!is_array($fonts)) {
                $fonts = array();
            }
            $fonts[$font_name] = $font_url;
            update_option('ff_firebase_custom_fonts', $fonts);
            ff_firebase_add_log('settings', "New custom font uploaded: {$font_name}.", 'info');
        }

        if (isset($_POST['ff_delete_custom_font']) && $_POST['ff_delete_custom_font'] !== '1') {
            $font_name = sanitize_text_field(wp_unslash($_POST['ff_delete_custom_font']));
            $fonts = get_option('ff_firebase_custom_fonts', array());
            if (is_array($fonts) && isset($fonts[$font_name])) {
                unset($fonts[$font_name]);
                update_option('ff_firebase_custom_fonts', $fonts);
                ff_firebase_add_log('settings', "Custom font deleted: {$font_name}.", 'info');
            }
        }

        ff_firebase_ensure_table_exists();
        $form_id = intval($_POST['ff_form_id']);
        $form_title = sanitize_text_field($_POST['ff_form_title']);
        $fields_json = wp_unslash($_POST['ff_form_fields_json']);

        // Get submitted form-specific settings overrides
        $form_settings = isset($_POST['ff_form_settings']) ? wp_unslash($_POST['ff_form_settings']) : array();
        
        // Explicitly handle checkboxes that are missing from $_POST when unchecked
        $form_settings['smtp_enabled'] = isset($form_settings['smtp_enabled']) ? 'yes' : 'no';
        $form_settings['email_enabled'] = isset($form_settings['email_enabled']) ? 'yes' : 'no';
        $form_settings['client_email_enabled'] = isset($form_settings['client_email_enabled']) ? 'yes' : 'no';
        $form_settings['maps_enabled'] = isset($form_settings['maps_enabled']) ? 'yes' : 'no';
        $form_settings['recaptcha_enabled'] = isset($form_settings['recaptcha_enabled']) ? 'yes' : 'no';

        // Process color picker inherit options
        $color_fields = array(
            'admin_email_primary_color', 'admin_email_bg_color', 'admin_email_text_color', 
            'admin_email_header_bg_color', 'admin_email_card_bg_color', 'admin_email_footer_bg_color',
            'client_email_primary_color', 'client_email_bg_color', 'client_email_text_color',
            'client_email_header_bg_color', 'client_email_card_bg_color', 'client_email_footer_bg_color'
        );
        foreach ($color_fields as $cf) {
            if (isset($form_settings[$cf . '_inherit']) && $form_settings[$cf . '_inherit'] === 'yes') {
                $form_settings[$cf] = 'inherit';
            }
            unset($form_settings[$cf . '_inherit']);
        }

        // Sanitize other settings fields (keeping templates HTML intact)
        $sanitized_settings = array();
        foreach ($form_settings as $key => $val) {
            if ($key === 'email_template' || $key === 'client_email_template') {
                $sanitized_settings[$key] = $val; // Keep HTML templates intact
            } elseif (
                strpos($key, '_body') !== false || 
                strpos($key, '_callout') !== false || 
                strpos($key, '_footer') !== false
            ) {
                $sanitized_settings[$key] = sanitize_textarea_field($val); // Preserves line breaks
            } else {
                $sanitized_settings[$key] = sanitize_text_field($val);
            }
        }
        $settings_json = json_encode($sanitized_settings);

        $wpdb->update(
            $forms_table,
            array(
                'title' => $form_title,
                'fields_json' => $fields_json,
                'settings_json' => $settings_json
            ),
            array('id' => $form_id)
        );

        // Redirect back to the editor with update confirmation
        wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    // D. Intercept Save/Delete Custom Font from Custom Fonts Tab
    if (isset($_POST['ff_action_custom_font_page']) && wp_verify_nonce($_POST['ff_custom_font_nonce'], 'ff_custom_font_action')) {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['ff_add_custom_font']) && !empty($_POST['ff_new_font_name']) && !empty($_POST['ff_new_font_url'])) {
            $font_name = sanitize_text_field($_POST['ff_new_font_name']);
            $font_url = esc_url_raw($_POST['ff_new_font_url']);
            $fonts = get_option('ff_firebase_custom_fonts', array());
            if (!is_array($fonts)) {
                $fonts = array();
            }
            $fonts[$font_name] = $font_url;
            update_option('ff_firebase_custom_fonts', $fonts);
            ff_firebase_add_log('settings', "New custom font uploaded via Custom Fonts Tab: {$font_name}.", 'info');
        }

        if (isset($_POST['ff_delete_custom_font']) && $_POST['ff_delete_custom_font'] !== '1') {
            $font_name = sanitize_text_field(wp_unslash($_POST['ff_delete_custom_font']));
            $fonts = get_option('ff_firebase_custom_fonts', array());
            if (is_array($fonts) && isset($fonts[$font_name])) {
                unset($fonts[$font_name]);
                update_option('ff_firebase_custom_fonts', $fonts);
                ff_firebase_add_log('settings', "Custom font deleted via Custom Fonts Tab: {$font_name}.", 'info');
            }
        }

        // Redirect back with settings-updated = true
        wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    if (isset($_POST['ff_firebase_save_settings_nonce']) && wp_verify_nonce($_POST['ff_firebase_save_settings_nonce'], 'ff_firebase_save_settings_action')) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $existing = get_option('ff_firebase_settings', array());
        if (!is_array($existing)) {
            $existing = array();
        }

        $current_page = isset($_POST['ff_current_settings_page']) ? sanitize_text_field($_POST['ff_current_settings_page']) : '';
        $submitted = isset($_POST['ff_firebase_settings']) && is_array($_POST['ff_firebase_settings']) ? $_POST['ff_firebase_settings'] : array();

        // Explicitly handle checkboxes that are missing from $_POST when unchecked
        if ($current_page === 'smtp') {
            $existing['smtp_enabled'] = isset($submitted['smtp_enabled']) ? 'yes' : 'no';
        }
        if ($current_page === 'admin_email') {
            $existing['email_enabled'] = isset($submitted['email_enabled']) ? 'yes' : 'no';
        }
        if ($current_page === 'client_email') {
            $existing['client_email_enabled'] = isset($submitted['client_email_enabled']) ? 'yes' : 'no';
        }
        if ($current_page === 'ssl') {
            $existing['enforce_ssl'] = isset($submitted['enforce_ssl']) ? 'yes' : 'no';
            $existing['fix_mixed_content'] = isset($submitted['fix_mixed_content']) ? 'yes' : 'no';
        }

        // Merge and sanitize other fields
        foreach ($submitted as $key => $val) {
            if ($key === 'form_fields_json' || $key === 'email_template' || $key === 'client_email_template') {
                $existing[$key] = $val; // Keep JSON and HTML templates intact
            } elseif (
                strpos($key, '_body') !== false || 
                strpos($key, '_callout') !== false || 
                strpos($key, '_footer') !== false
            ) {
                $existing[$key] = sanitize_textarea_field($val); // Preserves line breaks
            } else {
                $existing[$key] = sanitize_text_field($val);
            }
        }

        update_option('ff_firebase_settings', $existing);
        ff_firebase_add_log('settings', "Plugin global settings updated for page: '{$current_page}'.", 'info');

        // Redirect back with settings-updated = true
        wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }
}

// Custom CSS for dark lime theme on plugin admin pages
// Custom CSS for dark lime theme on plugin admin pages
function ff_firebase_admin_custom_css() {
    $screen = get_current_screen();
    $is_plugin_page = false;
    if ($screen && (strpos($screen->id, 'firebase') !== false || strpos($screen->id, 'submissions') !== false)) {
        $is_plugin_page = true;
    }
    if (isset($_GET['page']) && (strpos($_GET['page'], 'firebase') !== false || strpos($_GET['page'], 'submissions') !== false)) {
        $is_plugin_page = true;
    }
    if ($is_plugin_page) {
        ?>
        <style>
            /* Core plugin wrap styling */
            .firebase-plugin-wrap {
                background-color: #3a3a3a !important;
                color: #ffffff !important;
                padding: 25px 30px !important;
                border-radius: 12px !important;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2) !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                margin: 20px 20px 20px 0 !important;
            }
            .firebase-plugin-wrap h1, 
            .firebase-plugin-wrap h2, 
            .firebase-plugin-wrap h3, 
            .firebase-plugin-wrap h4,
            .firebase-plugin-wrap h5,
            .firebase-plugin-wrap th,
            .firebase-plugin-wrap label,
            .firebase-plugin-wrap .description,
            .firebase-plugin-wrap th label,
            .firebase-plugin-wrap p.description,
            .firebase-plugin-wrap td {
                color: #ffffff !important;
            }
            /* Cards layout */
            .firebase-plugin-wrap .card,
            .firebase-plugin-wrap div.card {
                background: #2a2a2a !important;
                border: 1px solid #4a4a4a !important;
                color: #ffffff !important;
                border-radius: 8px !important;
                box-shadow: 0 4px 15px rgba(0,0,0,0.15) !important;
                margin-top: 20px !important;
                padding: 24px !important;
            }
            /* Inputs and select styling */
            .firebase-plugin-wrap input[type="text"],
            .firebase-plugin-wrap input[type="email"],
            .firebase-plugin-wrap input[type="number"],
            .firebase-plugin-wrap input[type="password"],
            .firebase-plugin-wrap textarea,
            .firebase-plugin-wrap select {
                background: #333333 !important;
                border: 1px solid #555555 !important;
                color: #ffffff !important;
                border-radius: 6px !important;
                padding: 8px 12px !important;
                font-size: 14px !important;
                line-height: 1.5 !important;
                box-sizing: border-box !important;
            }
            .firebase-plugin-wrap input[type="text"]:focus,
            .firebase-plugin-wrap input[type="email"]:focus,
            .firebase-plugin-wrap input[type="number"]:focus,
            .firebase-plugin-wrap input[type="password"]:focus,
            .firebase-plugin-wrap textarea:focus,
            .firebase-plugin-wrap select:focus {
                border-color: #78951D !important;
                box-shadow: 0 0 0 2px rgba(120, 149, 29, 0.2) !important;
                outline: none !important;
            }
            .firebase-plugin-wrap select option {
                background-color: #333333 !important;
                color: #ffffff !important;
            }
            /* Custom styling for hyperlinks inside the wrap to ensure visibility and premium look */
            .firebase-plugin-wrap a {
                color: #78951D !important;
                text-decoration: none !important;
                transition: color 0.15s ease !important;
            }
            .firebase-plugin-wrap a:hover {
                color: #ffffff !important;
                text-decoration: underline !important;
            }
            .firebase-plugin-wrap .page-title-action {
                color: #78951D !important;
                background: #333333 !important;
                border-color: #4a4a4a !important;
            }
            .firebase-plugin-wrap .page-title-action:hover {
                color: #ffffff !important;
                background: #4a4a4a !important;
                border-color: #78951D !important;
            }
            /* Code tags styling to be highly readable */
            .firebase-plugin-wrap code,
            .firebase-plugin-wrap table code,
            .firebase-plugin-wrap table td code {
                background: #333333 !important;
                color: #78951D !important;
                border: 1px solid #555555 !important;
                padding: 3px 6px !important;
                border-radius: 4px !important;
                font-family: monospace !important;
                font-size: 13px !important;
                display: inline-block !important;
            }
            /* Notice alert paragraph visibility fix - target both inside and outside wrap */
            .notice,
            .notice-success,
            .notice-error,
            .firebase-plugin-wrap .notice,
            .firebase-plugin-wrap .notice-success,
            .firebase-plugin-wrap .notice-error {
                background: #2a2a2a !important;
                border: 1px solid #4a4a4a !important;
                border-left: 4px solid #4a4a4a !important;
                color: #ffffff !important;
                padding: 12px 20px !important;
                margin: 15px 0 !important;
                border-radius: 4px !important;
            }
            .notice p,
            .notice-success p,
            .notice-error p,
            .firebase-plugin-wrap .notice p,
            .firebase-plugin-wrap .notice-success p,
            .firebase-plugin-wrap .notice-error p {
                color: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .notice-success,
            .firebase-plugin-wrap .notice-success {
                border-left-color: #78951D !important;
            }
            .notice-error,
            .firebase-plugin-wrap .notice-error {
                border-left-color: #ff3333 !important;
            }
            .notice .notice-dismiss::before,
            .firebase-plugin-wrap .notice .notice-dismiss::before {
                color: #ffffff !important;
            }
            .notice .notice-dismiss:hover::before,
            .firebase-plugin-wrap .notice .notice-dismiss:hover::before {
                color: #78951D !important;
            }
            /* Checkbox native overrides utilizing the requested #78951D checked color */
            .firebase-plugin-wrap input[type="checkbox"] {
                accent-color: #78951D !important;
                cursor: pointer !important;
                width: 16px !important;
                height: 16px !important;
                vertical-align: middle !important;
                margin: 0 6px 0 0 !important;
            }
            .firebase-plugin-wrap input[type="checkbox"]:checked::before {
                content: none !important;
                display: none !important;
            }
            .firebase-plugin-wrap td label {
                display: inline-flex !important;
                align-items: center !important;
                gap: 4px !important;
                white-space: nowrap !important;
                flex-shrink: 0 !important;
            }
            /* Style widefat and striped tables inside the plugin wrapper in dark mode to fix white-on-white text */
            .firebase-plugin-wrap table,
            .firebase-plugin-wrap table.widefat,
            .firebase-plugin-wrap table.wp-list-table {
                background: #2a2a2a !important;
                border: 1px solid #4a4a4a !important;
                color: #ffffff !important;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
                border-collapse: collapse !important;
            }
            .firebase-plugin-wrap table.widefat th,
            .firebase-plugin-wrap table.wp-list-table th,
            .firebase-plugin-wrap table th {
                background: #333333 !important;
                color: #ffffff !important;
                border-bottom: 2px solid #4a4a4a !important;
                font-weight: 700 !important;
                padding: 12px 15px !important;
            }
            .firebase-plugin-wrap table.widefat td,
            .firebase-plugin-wrap table.wp-list-table td,
            .firebase-plugin-wrap table td {
                background: #2a2a2a !important;
                color: #ffffff !important;
                border-bottom: 1px solid #4a4a4a !important;
                padding: 12px 15px !important;
            }
            /* Stripe rows background adjustments for dark mode */
            .firebase-plugin-wrap table.striped>tbody>tr:nth-child(odd)>td,
            .firebase-plugin-wrap table.striped>tbody>tr:nth-of-type(odd)>td,
            .firebase-plugin-wrap .striped>tbody>tr:nth-child(odd)>td, 
            .firebase-plugin-wrap .striped>tbody>tr:nth-of-type(odd)>td {
                background-color: #2a2a2a !important;
            }
            .firebase-plugin-wrap table.striped>tbody>tr:nth-child(even)>td,
            .firebase-plugin-wrap table.striped>tbody>tr:nth-of-type(even)>td,
            .firebase-plugin-wrap .striped>tbody>tr:nth-child(even)>td, 
            .firebase-plugin-wrap .striped>tbody>tr:nth-of-type(even)>td {
                background-color: #333333 !important;
            }
            .firebase-plugin-wrap table.widefat tbody tr:hover td,
            .firebase-plugin-wrap table.wp-list-table tbody tr:hover td {
                background-color: #3d3d3d !important;
            }
            /* Style fixes for brand logo selects & width sliders to keep them inside the 25em box */
            .firebase-plugin-wrap td:has(#ff_admin_email_logo) > div,
            .firebase-plugin-wrap td:has(#ff_client_email_logo) > div,
            .firebase-plugin-wrap td:has(#email_logo) > div,
            .firebase-plugin-wrap td:has(#ff_admin_email_logo_width) > div,
            .firebase-plugin-wrap td:has(#ff_client_email_logo_width) > div,
            .firebase-plugin-wrap td:has(#email_logo_width) > div {
                max-width: 25em !important;
                width: 100% !important;
            }
            .firebase-plugin-wrap td:has(#ff_admin_email_logo) input[type="text"],
            .firebase-plugin-wrap td:has(#ff_client_email_logo) input[type="text"],
            .firebase-plugin-wrap td:has(#email_logo) input[type="text"],
            .firebase-plugin-wrap td:has(#ff_admin_email_logo_width) input[type="range"],
            .firebase-plugin-wrap td:has(#ff_client_email_logo_width) input[type="range"],
            .firebase-plugin-wrap td:has(#email_logo_width) input[type="range"] {
                flex: 1 !important;
                min-width: 0 !important;
            }
            /* Turn form-table into block layout for modern responsive forms */
            .firebase-plugin-wrap .form-table,
            .firebase-plugin-wrap .form-table tbody,
            .firebase-plugin-wrap .form-table tr,
            .firebase-plugin-wrap .form-table th,
            .firebase-plugin-wrap .form-table td,
            .firebase-plugin-wrap table.form-table,
            .firebase-plugin-wrap table.form-table tbody,
            .firebase-plugin-wrap table.form-table tr,
            .firebase-plugin-wrap table.form-table th,
            .firebase-plugin-wrap table.form-table td {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
            }
            .firebase-plugin-wrap .form-table th,
            .firebase-plugin-wrap table.form-table th {
                padding: 10px 0 6px 0 !important;
                font-weight: 600 !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .form-table td,
            .firebase-plugin-wrap table.form-table td {
                padding: 0 0 15px 0 !important;
            }
            .firebase-plugin-wrap .form-table input[type="text"],
            .firebase-plugin-wrap .form-table input[type="email"],
            .firebase-plugin-wrap .form-table input[type="number"],
            .firebase-plugin-wrap .form-table input[type="password"],
            .firebase-plugin-wrap .form-table textarea,
            .firebase-plugin-wrap .form-table select {
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            /* Grid layout for side-by-side preview panels */
            .ff-preview-layout-container {
                display: grid !important;
                grid-template-columns: 1fr 1fr 1fr !important;
                gap: 24px !important;
                align-items: flex-start !important;
                margin-top: 20px !important;
                width: 100% !important;
            }
            @media (max-width: 1200px) {
                .ff-preview-layout-container {
                    grid-template-columns: 1fr 1fr !important;
                }
            }
            @media (max-width: 800px) {
                .ff-preview-layout-container {
                    grid-template-columns: 1fr !important;
                }
            }
            /* Submissions Table dark mode styling to resolve white-on-white text issues */
            .firebase-plugin-wrap .table-container {
                background: #2a2a2a !important;
                border: 1px solid #4a4a4a !important;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
                border-radius: 8px !important;
            }
            .firebase-plugin-wrap .submissions-table th {
                background: #333333 !important;
                color: #ffffff !important;
                border-bottom: 2px solid #4a4a4a !important;
                font-weight: 700 !important;
            }
            .firebase-plugin-wrap .submissions-table td {
                border-bottom: 1px solid #4a4a4a !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .submissions-table tr:hover {
                background-color: #333333 !important;
            }
            /* Tab navigation */
            .firebase-plugin-wrap .nav-tab-wrapper,
            .wp-core-ui .firebase-plugin-wrap .nav-tab-wrapper {
                border-bottom: 2px solid #4a4a4a !important;
                padding-bottom: 0 !important;
                margin-bottom: 30px !important;
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
                background: transparent !important;
            }
            .firebase-plugin-wrap .nav-tab,
            .wp-core-ui .firebase-plugin-wrap .nav-tab {
                background: #2a2a2a !important;
                border: 1px solid #4a4a4a !important;
                border-bottom: none !important;
                color: #ffffff !important;
                font-weight: 600 !important;
                padding: 10px 20px !important;
                font-size: 13px !important;
                border-radius: 6px 6px 0 0 !important;
                margin: 0 !important;
                transition: all 0.2s ease !important;
            }
            .firebase-plugin-wrap .nav-tab:hover {
                background: #333333 !important;
                color: #78951D !important;
                border-color: #555555 !important;
            }
            .firebase-plugin-wrap .nav-tab-active,
            .wp-core-ui .firebase-plugin-wrap .nav-tab-active {
                background: #78951D !important;
                border: 1px solid #78951D !important;
                border-bottom: none !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .nav-tab-active:hover,
            .wp-core-ui .firebase-plugin-wrap .nav-tab-active:hover {
                color: #ffffff !important;
                background: #78951D !important;
            }
            /* Primary Button styling */
            .firebase-plugin-wrap .button-primary,
            .firebase-plugin-wrap input[type="submit"],
            .firebase-plugin-wrap button.button-primary,
            .wp-core-ui .firebase-plugin-wrap .button-primary,
            .wp-core-ui .firebase-plugin-wrap .button-primary:hover,
            .wp-core-ui .firebase-plugin-wrap .button-primary:focus,
            .wp-core-ui .firebase-plugin-wrap .button-primary:active {
                background: #78951D !important;
                border-color: #78951D !important;
                color: #ffffff !important;
                font-weight: bold !important;
                text-shadow: none !important;
                box-shadow: 0 4px 12px rgba(120, 149, 29, 0.2) !important;
                transition: all 0.2s ease !important;
                border-radius: 6px !important;
                height: auto !important;
                padding: 8px 20px !important;
                font-size: 14px !important;
            }
            .firebase-plugin-wrap .button-primary:hover,
            .firebase-plugin-wrap input[type="submit"]:hover,
            .firebase-plugin-wrap button.button-primary:hover {
                background: #ffffff !important;
                border-color: #ffffff !important;
                color: #78951D !important;
                box-shadow: 0 4px 12px rgba(255, 255, 255, 0.2) !important;
            }
            /* Secondary Button styling */
            .firebase-plugin-wrap .button,
            .firebase-plugin-wrap .button-secondary,
            .wp-core-ui .firebase-plugin-wrap .button,
            .wp-core-ui .firebase-plugin-wrap .button-secondary {
                background: #333333 !important;
                border-color: #4a4a4a !important;
                color: #ffffff !important;
                border-radius: 6px !important;
                transition: all 0.2s ease !important;
                height: auto !important;
                padding: 6px 16px !important;
            }
            .firebase-plugin-wrap .button:hover,
            .firebase-plugin-wrap .button-secondary:hover {
                background: #4a4a4a !important;
                color: #78951D !important;
                border-color: #78951D !important;
            }
            /* Style standard WP pagination buttons and current page indicator in dark mode */
            .firebase-plugin-wrap .tablenav-pages .current,
            .firebase-plugin-wrap .tablenav-pages span.disabled {
                background: #78951D !important;
                border-color: #78951D !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .tablenav-pages a {
                background: #333333 !important;
                border: 1px solid #4a4a4a !important;
                color: #ffffff !important;
                text-decoration: none !important;
            }
            .firebase-plugin-wrap .tablenav-pages a:hover {
                background: #78951D !important;
                border-color: #78951D !important;
                color: #ffffff !important;
            }
            /* Prevent dashicons inside buttons from taking WordPress blue color */
            .firebase-plugin-wrap .button .dashicons,
            .firebase-plugin-wrap .button-secondary .dashicons,
            .firebase-plugin-wrap .button-primary .dashicons,
            .firebase-plugin-wrap a.button .dashicons,
            .firebase-plugin-wrap button .dashicons {
                color: inherit !important;
            }
            /* Table list layouts (like entries and dash tables) */
            .firebase-plugin-wrap .ff-dash-table-card {
                background: #2a2a2a !important;
                border: 1px solid #4a4a4a !important;
                border-radius: 8px !important;
            }
            .firebase-plugin-wrap .ff-dash-table th {
                background: #333333 !important;
                color: #ffffff !important;
                border-bottom: 2px solid #4a4a4a !important;
            }
            .firebase-plugin-wrap .ff-dash-table td {
                border-bottom: 1px solid #4a4a4a !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .ff-dash-table tbody tr:hover {
                background: #333333 !important;
            }
            /* Form builder specific styles mapping */
            .firebase-plugin-wrap .ff-builder-canvas-wrapper {
                background: #232323 !important;
                border-color: #4a4a4a !important;
            }
            .firebase-plugin-wrap .ff-builder-sidebar {
                background: #2a2a2a !important;
                border-color: #4a4a4a !important;
            }
            .firebase-plugin-wrap .ff-sidebar-tabs {
                background: #232323 !important;
                border-bottom-color: #4a4a4a !important;
            }
            .firebase-plugin-wrap .ff-tab-btn {
                color: #aaaaaa !important;
            }
            .firebase-plugin-wrap .ff-tab-btn:hover {
                color: #78951D !important;
                background: #2d2d2d !important;
            }
            .firebase-plugin-wrap .ff-tab-btn.active {
                color: #78951D !important;
                border-bottom-color: #78951D !important;
                background: #2a2a2a !important;
            }
            .firebase-plugin-wrap .ff-accordion-header {
                background: #232323 !important;
                border-color: #4a4a4a !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .ff-accordion-header:hover {
                background: #2d2d2d !important;
            }
            .firebase-plugin-wrap .ff-accordion-content {
                background: #2a2a2a !important;
                border-color: #4a4a4a !important;
            }
            .firebase-plugin-wrap .ff-grid-btn {
                background: #333333 !important;
                border-color: #4a4a4a !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .ff-grid-btn:hover {
                border-color: #78951D !important;
                color: #78951D !important;
                background: #2a2a2a !important;
            }
            .firebase-plugin-wrap .ff-grid-btn .dashicons {
                color: #78951D !important;
            }
            .firebase-plugin-wrap .ff-canvas-card {
                border-color: transparent !important;
            }
            .firebase-plugin-wrap .ff-canvas-card:hover,
            .firebase-plugin-wrap .ff-canvas-card.active {
                border-color: #4a4a4a !important;
                background: #333333 !important;
            }
            .firebase-plugin-wrap .ff-canvas-card-label {
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .ff-canvas-card-preview {
                background: #232323 !important;
                border-color: #4a4a4a !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .ff-canvas-title-group {
                background: #2a2a2a !important;
                border-color: #4a4a4a !important;
            }
            .firebase-plugin-wrap .ff-canvas-title-input {
                background: #333333 !important;
                border-color: #4a4a4a !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .ff-canvas-title-input:focus {
                border-color: #78951D !important;
                box-shadow: 0 0 0 1px #78951D !important;
            }
            /* Notice and error style adjustments */
            .firebase-plugin-wrap .notice,
            .firebase-plugin-wrap .notice-success,
            .firebase-plugin-wrap .notice-error {
                background: #2a2a2a !important;
                border-left-width: 4px !important;
                color: #ffffff !important;
            }
            .firebase-plugin-wrap .notice-success {
                border-left-color: #78951D !important;
            }
            .firebase-plugin-wrap .notice-error {
                border-left-color: #ff3333 !important;
            }
        </style>
        <?php
    }
}

// 3c. Unified Native Page Header (no custom branding, standard WP layout)
function ff_firebase_admin_page_header($title, $current_page) {
    ?>
    <div class="wrap firebase-plugin-wrap">
        <div class="ff-plugin-header" style="display: flex; align-items: center; gap: 15px; margin-bottom: 25px; border-bottom: 2px solid #78951D; padding-bottom: 20px;">
            <div class="ff-plugin-logo" style="width: 50px; height: 50px; background: #000; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border: 2px solid #78951D;">
                <svg viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg" style="width: 32px; height: 32px;">
                    <circle cx="90" cy="90" r="90" fill="black" />
                    <path d="M149.508 157.52L69.142 54H54V125.97H66.1136V69.3836L139.999 164.845C143.333 162.614 146.509 160.165 149.508 157.52Z" fill="white" />
                    <rect fill="white" height="72" width="12" x="114" y="54" />
                </svg>
            </div>
            <div>
                <h1 style="margin: 0; padding: 0; font-size: 24px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; line-height: 1.2;">Connect Next Js</h1>
                <p style="margin: 3px 0 0 0; font-size: 12px; color: #78951D; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; line-height: 1;">by 2H Web Solution</p>
            </div>
        </div>
        <h2 class="ff-page-title" style="color: #ffffff; font-size: 20px; font-weight: 700; margin-bottom: 20px;"><?php echo esc_html($title); ?></h2>
        
        <form action="" method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('ff_firebase_save_settings_action', 'ff_firebase_save_settings_nonce'); ?>
            <input type="hidden" name="ff_current_settings_page" value="<?php echo esc_attr($current_page); ?>">
    <?php
}

// 3d. Unified Native Page Footer (no custom footer bar, standard WP submit)
function ff_firebase_admin_page_footer() {
    ?>
            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

function ff_firebase_forms_page() {
    global $wpdb;
    ff_firebase_ensure_table_exists();
    
    $forms_table = $wpdb->prefix . 'firebase_forms';
    $submissions_table = $wpdb->prefix . 'firebase_form_submissions';
    
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    $form_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    // VIEW A: Edit Visual Form Creator View
    if ($action === 'edit' && $form_id > 0) {
        wp_enqueue_media();
        $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms_table WHERE id = %d", $form_id));
        if (!$form) {
            echo '<div class="notice notice-error"><p>Form not found.</p></div>';
            return;
        }

        // Handle Submissions Actions in the Form Entries Tab for user convenience
        if (isset($_GET['sub_action']) && isset($_GET['sub_id'])) {
            $sub_id = intval($_GET['sub_id']);
            if ($_GET['sub_action'] === 'delete') {
                check_admin_referer('delete_submission_' . $sub_id);
                $wpdb->delete($submissions_table, array('id' => $sub_id));
                echo '<div class="notice notice-success is-dismissible" style="margin-top:15px;"><p>' . __('Submission successfully deleted.', 'fluent-forms-to-firebase') . '</p></div>';
            }
            if ($_GET['sub_action'] === 'resync') {
                check_admin_referer('resync_submission_' . $sub_id);
                $result = ff_firebase_sync_single_to_firestore($sub_id);
                if ($result['success']) {
                    echo '<div class="notice notice-success is-dismissible" style="margin-top:15px;"><p>' . sprintf(__('Submission successfully synced to Firebase! Document ID: %s', 'fluent-forms-to-firebase'), '<code>' . esc_html($result['doc_id']) . '</code>') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible" style="margin-top:15px;"><p>' . sprintf(__('Firebase Sync Failed: %s', 'fluent-forms-to-firebase'), esc_html($result['error'])) . '</p></div>';
                }
            }
        }
        
        $form_title = $form->title;
        $fields_json = $form->fields_json;
        $fields = json_decode(wp_unslash($fields_json), true);
        if (empty($fields)) {
            $fields = ff_firebase_get_default_form_fields();
        }

        // Fetch form settings override
        $form_settings = array();
        if (!empty($form->settings_json)) {
            $form_settings = json_decode(wp_unslash($form->settings_json), true);
        }
        if (!is_array($form_settings)) {
            $form_settings = array();
        }

        // Fetch global settings to display as placeholders / defaults
        $global_settings = get_option('ff_firebase_settings', array());
        if (!is_array($global_settings)) {
            $global_settings = array();
        }
        $global_project_id = isset($global_settings['project_id']) ? $global_settings['project_id'] : '';
        $global_collection = isset($global_settings['collection_name']) ? $global_settings['collection_name'] : 'submissions';
        
        // Render Custom Form Builder Header (standard WP style with a Back link)
        ?>
        <div class="wrap firebase-plugin-wrap">
            <div class="ff-plugin-header" style="display: flex; align-items: center; gap: 15px; margin-bottom: 25px; border-bottom: 2px solid #78951D; padding-bottom: 20px;">
                <div class="ff-plugin-logo" style="width: 50px; height: 50px; background: #000; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border: 2px solid #78951D;">
                    <svg viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg" style="width: 32px; height: 32px;">
                        <circle cx="90" cy="90" r="90" fill="black" />
                        <path d="M149.508 157.52L69.142 54H54V125.97H66.1136V69.3836L139.999 164.845C143.333 162.614 146.509 160.165 149.508 157.52Z" fill="white" />
                        <rect fill="white" height="72" width="12" x="114" y="54" />
                    </svg>
                </div>
                <div>
                    <h1 style="margin: 0; padding: 0; font-size: 24px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; line-height: 1.2;">Connect Next Js</h1>
                    <p style="margin: 3px 0 0 0; font-size: 12px; color: #78951D; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; line-height: 1;">by 2H Web Solution</p>
                </div>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h2 class="ff-page-title" style="color: #ffffff; font-size: 20px; font-weight: 700; margin: 0;">Edit Form: <?php echo esc_html($form_title); ?></h2>
                <a href="<?php echo admin_url('admin.php?page=firebase_forms'); ?>" class="page-title-action" style="font-weight:600; border-color:#4a4a4a; color:#78951D; background: #333; height: auto; padding: 6px 12px; border-radius: 6px; display: inline-flex; align-items: center;"><span class="dashicons dashicons-arrow-left-alt2" style="font-size:16px; margin-top:2px; margin-right:4px;"></span> Back to Forms</a>
            </div>
            
            <?php if (isset($_GET['created'])) : ?>
                <div class="notice notice-success is-dismissible" style="margin-top:15px;"><p>Form successfully created! Start customizing your fields below.</p></div>
            <?php endif; ?>
            
            <form action="" method="post" style="margin-top:20px;">
                <?php wp_nonce_field('ff_save_form_action', 'ff_save_form_nonce'); ?>
                <input type="hidden" name="ff_action_save_form" value="1">
                <input type="hidden" name="ff_form_id" value="<?php echo $form_id; ?>">

                <!-- Top Tab Navigation Wrapper -->
                <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                    <a href="#ff-editor-panel" class="nav-tab nav-tab-active" data-panel="ff-editor-panel">Form Editor</a>
                    <a href="#ff-firebase-panel" class="nav-tab" data-panel="ff-firebase-panel">Firebase Connection</a>
                    <a href="#ff-smtp-panel" class="nav-tab" data-panel="ff-smtp-panel">SMTP Settings</a>
                    <a href="#ff-admin-email-panel" class="nav-tab" data-panel="ff-admin-email-panel">Admin Notifications</a>
                    <a href="#ff-client-email-panel" class="nav-tab" data-panel="ff-client-email-panel">Client Thank-You</a>
                    <a href="#ff-entries-panel" class="nav-tab" data-panel="ff-entries-panel">Form Entries</a>
                    <a href="#ff-maps-panel" class="nav-tab" data-panel="ff-maps-panel">Google Maps</a>
                    <a href="#ff-recaptcha-panel" class="nav-tab" data-panel="ff-recaptcha-panel">reCAPTCHA</a>
                    <a href="#ff-style-panel" class="nav-tab" data-panel="ff-style-panel">Style Settings</a>
                </h2>

                <!-- Panel 1: Form Editor -->
                <div id="ff-editor-panel" class="ff-panel-content active">
        <?php
        
        // Visual Creator Body
        ?>
        <style>
            .ff-builder-workspace {
                display: flex;
                gap: 24px;
                margin-top: 20px;
                align-items: flex-start;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            }
            
            /* Left Canvas Pane */
            .ff-builder-canvas-wrapper {
                flex: 1;
                background: #f0f0f1;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 30px 20px;
                min-height: 700px;
                box-sizing: border-box;
            }
            .ff-canvas-title-group {
                margin-bottom: 20px;
                background: #ffffff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 15px 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
            }
            .ff-canvas-title-group label {
                display: block;
                font-size: 11px;
                font-weight: 600;
                color: #646970;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 6px;
            }
            .ff-canvas-title-input {
                width: 100%;
                max-width: 500px;
                padding: 6px 10px !important;
                font-size: 14px !important;
                border: 1px solid #8c8f94 !important;
                border-radius: 4px !important;
                line-height: 1.5 !important;
            }
            .ff-canvas-title-input:focus {
                border-color: #78951D !important;
                box-shadow: 0 0 0 1px #78951D !important;
                outline: 2px solid transparent !important;
            }
            .ff-builder-canvas {
                background: #ffffff;
                border: 1px dashed #c3c4c7;
                border-radius: 4px;
                min-height: 520px;
                padding: 24px;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                gap: 16px;
                box-shadow: inset 0 2px 4px rgba(0,0,0,.02);
            }
            .ff-canvas-empty {
                text-align: center;
                padding: 100px 20px;
                color: #646970;
            }
            .ff-canvas-empty .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #c3c4c7;
                margin-bottom: 12px;
            }
            .ff-canvas-empty p {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #2c3338;
            }
            .ff-canvas-empty span {
                font-size: 12px;
                color: #8c8f94;
                display: block;
                margin-top: 5px;
            }

            /* Canvas Input Card */
            .ff-canvas-card {
                background: transparent;
                border: 1px solid transparent;
                border-radius: 4px;
                padding: 16px 20px;
                position: relative;
                cursor: pointer;
                box-sizing: border-box;
                transition: all 0.15s;
            }
            .ff-canvas-card:hover,
            .ff-canvas-card.active {
                border: 1px solid #ccd0d4 !important;
                background: #f5f5f5 !important;
                box-shadow: none !important;
                margin: 0;
            }
            .ff-canvas-card-label {
                display: block;
                font-weight: 600;
                font-size: 13px;
                color: #1d2327;
                margin-bottom: 8px;
            }
            .ff-canvas-card-required-star {
                color: #d63638;
                margin-right: 4px;
            }
            .ff-canvas-card-preview {
                width: 100%;
                background: #ffffff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 8px 12px;
                font-size: 13px;
                color: #2c3338;
                pointer-events: none;
                box-sizing: border-box;
            }
            
            /* Custom Canvas Mockups */
            .ff-mock-stars {
                display: flex;
                gap: 5px;
                margin: 5px 0;
            }
            .ff-mock-stars .dashicons-star-filled {
                color: #ffb900;
                font-size: 20px;
                width: 20px;
                height: 20px;
            }
            .ff-mock-captcha {
                display: inline-flex;
                align-items: center;
                gap: 15px;
                padding: 12px 18px;
                border: 1px solid #dcdcde;
                background: #f6f7f7;
                border-radius: 4px;
                font-size: 12px;
                color: #2c3338;
            }
            .ff-mock-nps {
                display: flex;
                gap: 5px;
                margin-top: 8px;
            }
            .ff-mock-nps-btn {
                width: 32px;
                height: 32px;
                border: 1px solid #ccd0d4;
                background: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                font-weight: 600;
                border-radius: 4px;
                color: #2c3338;
            }
            .ff-mock-columns {
                display: flex;
                gap: 15px;
                margin-top: 5px;
            }
            .ff-mock-col-cell {
                flex: 1;
                border: 1px dashed #ccd0d4;
                border-radius: 4px;
                background: #fafafa;
                padding: 15px 10px;
                text-align: center;
                color: #8c8f94;
                font-size: 11px;
            }

            /* Toolbars */
            .ff-canvas-card-toolbar {
                position: absolute;
                top: -14px;
                right: 15px;
                background: #1d2327;
                border-radius: 4px;
                display: none;
                gap: 1px;
                padding: 3px;
                box-shadow: 0 2px 5px rgba(0,0,0,.15);
                z-index: 10;
            }
            .ff-canvas-card:hover .ff-canvas-card-toolbar,
            .ff-canvas-card.active .ff-canvas-card-toolbar {
                display: flex;
            }
            .ff-toolbar-btn {
                background: none;
                border: none;
                color: #c3c4c7;
                cursor: pointer;
                width: 24px;
                height: 24px;
                border-radius: 2px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: color 0.15s, background 0.15s;
                outline: none;
            }
            .ff-toolbar-btn:hover {
                color: #ffffff;
                background: #353b3f;
            }
            .ff-toolbar-btn.delete:hover {
                background: #d63638;
            }
            .ff-toolbar-btn .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }

            /* Insert Divider */
            .ff-insert-divider {
                position: relative;
                height: 24px;
                margin: -12px 0;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0;
                transition: opacity 0.2s;
                z-index: 5;
            }
            .ff-insert-divider:hover,
            .ff-canvas-card:hover + .ff-insert-divider,
            .ff-insert-divider:hover + .ff-canvas-card {
                opacity: 1;
            }
            .ff-insert-line {
                display: none;
            }
            .ff-insert-btn {
                position: relative;
                background: #1d2327;
                color: #ffffff;
                border: none;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                box-shadow: 0 1px 3px rgba(0,0,0,.2);
                outline: none;
                transition: transform 0.2s, background 0.2s;
                padding: 0;
            }
            .ff-insert-btn:hover {
                transform: scale(1.15);
                background: #000000;
            }
            .ff-insert-btn .dashicons {
                font-size: 14px;
                width: 14px;
                height: 14px;
            }

            /* Right Sidebar Tabs Panel */
            .ff-builder-sidebar {
                width: 380px;
                background: #ffffff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
                box-sizing: border-box;
                position: sticky;
                top: 50px;
            }
            .ff-sidebar-tabs {
                display: flex;
                border-bottom: 1px solid #dcdcde;
                background: #f6f7f7;
                border-top-left-radius: 4px;
                border-top-right-radius: 4px;
            }
            .ff-tab-btn {
                flex: 1;
                padding: 12px 6px;
                text-align: center;
                font-size: 12px;
                font-weight: 600;
                color: #50575e;
                background: none;
                border: none;
                border-bottom: 3px solid transparent;
                cursor: pointer;
                transition: all 0.15s;
                outline: none;
            }
            .ff-tab-btn:hover {
                color: #78951D;
                background: #f0f0f1;
            }
            .ff-tab-btn.active {
                color: #78951D;
                border-bottom-color: #78951D;
                background: #ffffff;
            }
            
            .ff-tab-content {
                display: none;
                padding: 18px;
                box-sizing: border-box;
                max-height: 650px;
                overflow-y: auto;
            }
            .ff-tab-content.active {
                display: block;
            }

            /* Search elements */
            .ff-search-wrapper {
                position: relative;
                margin-bottom: 18px;
            }
            .ff-search-input {
                width: 100%;
                padding: 8px 12px 8px 32px !important;
                font-size: 13px !important;
                line-height: 1.5 !important;
                border: 1px solid #8c8f94 !important;
                border-radius: 4px !important;
                background: #ffffff !important;
                box-sizing: border-box;
            }
            .ff-search-input:focus {
                border-color: #78951D !important;
                box-shadow: 0 0 0 1px #78951D !important;
                outline: 2px solid transparent !important;
            }
            .ff-search-icon {
                position: absolute;
                left: 10px;
                top: 50%;
                transform: translateY(-50%);
                color: #646970;
                font-size: 15px;
                width: 15px;
                height: 15px;
            }

            /* Sidebar Collapsible Accordions */
            .ff-accordion {
                margin-bottom: 10px;
            }
            .ff-accordion-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 14px;
                background: #f6f7f7;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 600;
                font-size: 12px;
                color: #2c3338;
                user-select: none;
                transition: background 0.15s;
            }
            .ff-accordion-header:hover {
                background: #f0f0f1;
            }
            .ff-accordion.open .ff-accordion-header {
                border-bottom-left-radius: 0;
                border-bottom-right-radius: 0;
                background: #f0f0f1;
            }
            .ff-accordion-content {
                display: none;
                padding: 15px;
                border: 1px solid #ccd0d4;
                border-top: none;
                border-bottom-left-radius: 4px;
                border-bottom-right-radius: 4px;
                background: #ffffff;
                box-sizing: border-box;
            }
            .ff-accordion.open .ff-accordion-content {
                display: block;
            }
            .ff-accordion-header .dashicons-arrow-down-alt2 {
                font-size: 14px;
                width: 14px;
                height: 14px;
                color: #646970;
                transition: transform 0.2s;
            }
            .ff-accordion.open .ff-accordion-header .dashicons-arrow-down-alt2 {
                transform: rotate(180deg);
            }

            /* 2-Column Fields grids */
            .ff-fields-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            .ff-grid-btn {
                display: flex;
                align-items: center;
                gap: 8px;
                background: #ffffff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 10px 8px;
                font-size: 11px;
                color: #2c3338;
                cursor: pointer;
                transition: all 0.15s;
                text-align: left;
                box-shadow: 0 1px 2px rgba(0,0,0,.03);
                outline: none;
            }
            .ff-grid-btn:hover {
                border-color: #78951D;
                color: #78951D;
                background: #f0f6fc;
            }
            .ff-grid-btn .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                color: #646970;
                margin-top: 1px;
            }
            .ff-grid-btn:hover .dashicons {
                color: #78951D;
            }

            /* Customizer Panel */
            .ff-customizer-placeholder {
                text-align: center;
                padding: 50px 10px;
                color: #646970;
            }
            .ff-customizer-placeholder .dashicons {
                font-size: 32px;
                width: 32px;
                height: 32px;
                color: #c3c4c7;
                margin-bottom: 8px;
            }
            .ff-customizer-placeholder p {
                margin: 0;
                font-size: 13px;
            }
            .ff-customizer-form {
                display: flex;
                flex-direction: column;
                gap: 14px;
            }
            .ff-customizer-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .ff-customizer-group label {
                font-weight: 600;
                font-size: 12px;
                color: #2c3338;
            }
            .ff-customizer-input, .ff-customizer-select, .ff-customizer-textarea {
                width: 100%;
                padding: 6px 10px !important;
                font-size: 13px !important;
                border: 1px solid #8c8f94 !important;
                border-radius: 4px !important;
                line-height: 1.5 !important;
            }
            .ff-customizer-input:focus, .ff-customizer-select:focus, .ff-customizer-textarea:focus {
                border-color: #78951D !important;
                box-shadow: 0 0 0 1px #78951D !important;
                outline: 2px solid transparent !important;
            }
            .ff-customizer-checkbox-row {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 5px 0;
            }
            .ff-customizer-checkbox-row input[type="checkbox"] {
                margin: 0;
            }

            /* History list */
            .ff-history-list {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .ff-history-item {
                display: flex;
                gap: 10px;
                font-size: 12px;
                line-height: 1.5;
                color: #2c3338;
                padding-bottom: 8px;
                border-bottom: 1px solid #f0f0f1;
            }
            .ff-history-time {
                font-weight: 600;
                color: #646970;
                font-family: monospace;
                flex-shrink: 0;
            }
        </style>

        <div class="ff-builder-workspace">
            
            <!-- Left Layout Canvas -->
            <div class="ff-builder-canvas-wrapper">
                <div class="ff-canvas-title-group">
                    <label for="form_title_input">Form Header Title</label>
                    <input type="text" id="form_title_input" name="ff_form_title" value="<?php echo esc_attr($form_title); ?>" class="ff-canvas-title-input" placeholder="e.g. Registration / Enquiry" required>
                </div>

                <div class="ff-builder-canvas" id="ff_builder_canvas">
                    <!-- Dynamic rendering in JS -->
                </div>

                <!-- Database json submit target -->
                <textarea name="ff_form_fields_json" id="form_fields_json" style="display:none;"></textarea>
                
                <!-- Submit save actions standard WP button -->
                <div style="margin-top: 25px; padding: 0 5px;">
                    <?php submit_button('Save Form Configurations', 'primary', 'submit', false, array('style' => 'background:#78951D; border-color:#78951D; font-weight:600; padding:6px 24px; font-size:13px; min-height:36px; border-radius:4px;')); ?>
                </div>
            </div>

            <!-- Right Sidebar Panel -->
            <div class="ff-builder-sidebar">
                <div class="ff-sidebar-tabs">
                    <button type="button" class="ff-tab-btn active" data-tab="fields">Input Fields</button>
                    <button type="button" class="ff-tab-btn" data-tab="customizer">Input Customization</button>
                    <button type="button" class="ff-tab-btn" data-tab="history">History</button>
                </div>

                <!-- Tab 1: Input Fields -->
                <div class="ff-tab-content active" id="ff_tab_fields">
                    <div class="ff-search-wrapper">
                        <span class="dashicons dashicons-search ff-search-icon"></span>
                        <input type="text" class="ff-search-input" id="ff_field_search" placeholder="Search (press '/' to focus)">
                    </div>

                    <!-- Accordion 1: General Fields -->
                    <div class="ff-accordion open">
                        <div class="ff-accordion-header">
                            <span>General Fields</span>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </div>
                        <div class="ff-accordion-content">
                            <div class="ff-fields-grid">
                                <button type="button" class="ff-grid-btn" data-type="name_fields" data-label="Name Fields">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <span>Name Fields</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="email" data-label="Email">
                                    <span class="dashicons dashicons-email"></span>
                                    <span>Email</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="text" data-label="Simple Text">
                                    <span class="dashicons dashicons-editor-textcolor"></span>
                                    <span>Simple Text</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="mask_input" data-label="Mask Input">
                                    <span class="dashicons dashicons-editor-code"></span>
                                    <span>Mask Input</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="textarea" data-label="Text Area">
                                    <span class="dashicons dashicons-editor-paragraph"></span>
                                    <span>Text Area</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="address_fields" data-label="Address Fields">
                                    <span class="dashicons dashicons-location-alt"></span>
                                    <span>Address Fields</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="country_list" data-label="Country List">
                                    <span class="dashicons dashicons-translation"></span>
                                    <span>Country List</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="number" data-label="Numeric Field">
                                    <span class="dashicons dashicons-editor-ol"></span>
                                    <span>Numeric Field</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="select" data-label="Dropdown">
                                    <span class="dashicons dashicons-menu-alt2"></span>
                                    <span>Dropdown</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="radio" data-label="Radio Field">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <span>Radio Field</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="checkbox" data-label="Checkbox">
                                    <span class="dashicons dashicons-forms"></span>
                                    <span>Checkbox</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="multiple_choice" data-label="Multiple Choice">
                                    <span class="dashicons dashicons-editor-ul"></span>
                                    <span>Multiple Choice</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="url" data-label="Website URL">
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <span>Website URL</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="date" data-label="Time & Date">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <span>Time & Date</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="image_upload" data-label="Image Upload">
                                    <span class="dashicons dashicons-format-image"></span>
                                    <span>Image Upload</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="file_upload" data-label="File Upload">
                                    <span class="dashicons dashicons-cloud-upload"></span>
                                    <span>File Upload</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="html" data-label="Custom HTML">
                                    <span class="dashicons dashicons-editor-code"></span>
                                    <span>Custom HTML</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="tel" data-label="Phone">
                                    <span class="dashicons dashicons-phone"></span>
                                    <span>Phone</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Accordion 2: Advanced Fields -->
                    <div class="ff-accordion">
                        <div class="ff-accordion-header">
                            <span>Advanced Fields</span>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </div>
                        <div class="ff-accordion-content">
                            <div class="ff-fields-grid">
                                <button type="button" class="ff-grid-btn" data-type="hidden" data-label="Hidden Field">
                                    <span class="dashicons dashicons-hidden"></span>
                                    <span>Hidden Field</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="section_break" data-label="Section Break">
                                    <span class="dashicons dashicons-minus"></span>
                                    <span>Section Break</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="recaptcha" data-label="reCaptcha">
                                    <span class="dashicons dashicons-shield"></span>
                                    <span>reCaptcha</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="hcaptcha" data-label="hCaptcha">
                                    <span class="dashicons dashicons-shield-alt"></span>
                                    <span>hCaptcha</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="turnstile" data-label="Turnstile">
                                    <span class="dashicons dashicons-shield"></span>
                                    <span>Turnstile</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="shortcode" data-label="Shortcode">
                                    <span class="dashicons dashicons-editor-code"></span>
                                    <span>Shortcode</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="terms_conditions" data-label="Terms & Conditions">
                                    <span class="dashicons dashicons-index-card"></span>
                                    <span>Terms & Conditions</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="action_hook" data-label="Action Hook">
                                    <span class="dashicons dashicons-admin-plugins"></span>
                                    <span>Action Hook</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="form_step" data-label="Form Step">
                                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                                    <span>Form Step</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="ratings" data-label="Ratings">
                                    <span class="dashicons dashicons-star-filled"></span>
                                    <span>Ratings</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="checkable_grid" data-label="Checkable Grid">
                                    <span class="dashicons dashicons-grid-view"></span>
                                    <span>Checkable Grid</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="gdpr" data-label="GDPR Agreement">
                                    <span class="dashicons dashicons-shield-alt"></span>
                                    <span>GDPR Agreement</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="password" data-label="Password">
                                    <span class="dashicons dashicons-lock"></span>
                                    <span>Password</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="range_slider" data-label="Range Slider">
                                    <span class="dashicons dashicons-leftright"></span>
                                    <span>Range Slider</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="nps" data-label="Net Promoter Score">
                                    <span class="dashicons dashicons-thumbs-up"></span>
                                    <span>Net Promoter Score</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="chained_select" data-label="Chained Select">
                                    <span class="dashicons dashicons-networking"></span>
                                    <span>Chained Select</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="color_picker" data-label="Color Picker">
                                    <span class="dashicons dashicons-art"></span>
                                    <span>Color Picker</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="repeat_field" data-label="Repeat Field">
                                    <span class="dashicons dashicons-update"></span>
                                    <span>Repeat Field</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="post_selection" data-label="Post/CPT Selection">
                                    <span class="dashicons dashicons-admin-post"></span>
                                    <span>Post Selection</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="quiz_score" data-label="Quiz Score">
                                    <span class="dashicons dashicons-awards"></span>
                                    <span>Quiz Score</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="dynamic_field" data-label="Dynamic Field">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    <span>Dynamic Field</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="rich_text" data-label="Rich Text Input">
                                    <span class="dashicons dashicons-editor-kitchensink"></span>
                                    <span>Rich Text Input</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="save_resume" data-label="Save & Resume">
                                    <span class="dashicons dashicons-upload"></span>
                                    <span>Save & Resume</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="ranking_field" data-label="Ranking Field">
                                    <span class="dashicons dashicons-editor-ol"></span>
                                    <span>Ranking Field</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="custom_submit" data-label="Custom Submit Button">
                                    <span class="dashicons dashicons-arrow-right-alt"></span>
                                    <span>Custom Submit</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Accordion 3: Container -->
                    <div class="ff-accordion">
                        <div class="ff-accordion-header">
                            <span>Container</span>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </div>
                        <div class="ff-accordion-content">
                            <div class="ff-fields-grid">
                                <button type="button" class="ff-grid-btn" data-type="column_1" data-label="One Column Container">
                                    <span class="dashicons dashicons-align-center"></span>
                                    <span>1 Column</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="column_2" data-label="Two Column Container">
                                    <span class="dashicons dashicons-columns"></span>
                                    <span>2 Columns</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="column_3" data-label="Three Column Container">
                                    <span class="dashicons dashicons-columns"></span>
                                    <span>3 Columns</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="column_4" data-label="Four Column Container">
                                    <span class="dashicons dashicons-columns"></span>
                                    <span>4 Columns</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="column_5" data-label="Five Column Container">
                                    <span class="dashicons dashicons-columns"></span>
                                    <span>5 Columns</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="column_6" data-label="Six Column Container">
                                    <span class="dashicons dashicons-columns"></span>
                                    <span>6 Columns</span>
                                </button>
                                <button type="button" class="ff-grid-btn" data-type="accordion_tab" data-label="Accordion/Tab">
                                    <span class="dashicons dashicons-index-card"></span>
                                    <span>Accordion/Tab</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Input Customization -->
                <div class="ff-tab-content" id="ff_tab_customizer">
                    <div id="ff_customizer_panel">
                        <!-- Customizer controls -->
                    </div>
                </div>

                <!-- Tab 3: History -->
                <div class="ff-tab-content" id="ff_tab_history">
                    <div class="ff-history-list" id="ff_history_list">
                        <!-- History elements -->
                    </div>
                </div>
            </div>
        </div> <!-- Closes ff-builder-workspace -->
        </div> <!-- Closes ff-editor-panel -->

        <!-- Panel 2: Firebase Connection -->
        <div id="ff-firebase-panel" class="ff-panel-content" style="display:none;">
            <?php
            $project_id = isset($form_settings['project_id']) ? $form_settings['project_id'] : '';
            $api_key = isset($form_settings['api_key']) ? $form_settings['api_key'] : '';
            $collection_name = isset($form_settings['collection_name']) ? $form_settings['collection_name'] : '';
            ?>
            <div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Firebase Firestore Specific Overrides</h3>
                <p class="description" style="margin-bottom: 20px;">If left blank, this form will dynamically fall back to the global Firebase settings configured in the main sidebar setup menu.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ff_project_id">Firebase Project ID</label></th>
                            <td>
                                <input type="text" id="ff_project_id" name="ff_form_settings[project_id]" value="<?php echo esc_attr($project_id); ?>" class="regular-text" placeholder="e.g. engindeniz-3876e (Global fallback: <?php echo esc_attr($global_project_id); ?>)">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_api_key">Firebase Web API Key</label></th>
                            <td>
                                <input type="text" id="ff_api_key" name="ff_form_settings[api_key]" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="Optional Web API Key override">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_collection_name">Firestore Collection Name</label></th>
                            <td>
                                <input type="text" id="ff_collection_name" name="ff_form_settings[collection_name]" value="<?php echo esc_attr($collection_name); ?>" class="regular-text" placeholder="e.g. submissions (Global fallback: <?php echo esc_attr($global_collection); ?>)">
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top: 25px;">
                    <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#78951D; border-color:#78951D; font-weight:600;')); ?>
                </div>
            </div>
        </div>

        <!-- Panel 3: SMTP Settings -->
        <div id="ff-smtp-panel" class="ff-panel-content" style="display:none;">
            <?php
            $smtp_enabled = isset($form_settings['smtp_enabled']) ? $form_settings['smtp_enabled'] : '';
            $smtp_host = isset($form_settings['smtp_host']) ? $form_settings['smtp_host'] : '';
            $smtp_port = isset($form_settings['smtp_port']) ? $form_settings['smtp_port'] : '';
            $smtp_encryption = isset($form_settings['smtp_encryption']) ? $form_settings['smtp_encryption'] : 'none';
            $smtp_username = isset($form_settings['smtp_username']) ? $form_settings['smtp_username'] : '';
            $smtp_password = isset($form_settings['smtp_password']) ? $form_settings['smtp_password'] : '';
            $smtp_from_email = isset($form_settings['smtp_from_email']) ? $form_settings['smtp_from_email'] : '';
            $smtp_from_name = isset($form_settings['smtp_from_name']) ? $form_settings['smtp_from_name'] : '';
            ?>
            <div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">SMTP Server Specific Overrides</h3>
                <p class="description" style="margin-bottom: 20px;">Override global SMTP settings to route this specific form's autoresponders through a custom mail domain.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ff_smtp_enabled">Enable Custom SMTP Overrides</label></th>
                            <td>
                                <input type="checkbox" id="ff_smtp_enabled" name="ff_form_settings[smtp_enabled]" value="yes" <?php checked($smtp_enabled, 'yes'); ?>>
                                <span class="description">Activate custom mail routing for this form</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_smtp_host">SMTP Host Server</label></th>
                            <td>
                                <input type="text" id="ff_smtp_host" name="ff_form_settings[smtp_host]" value="<?php echo esc_attr($smtp_host); ?>" class="regular-text" placeholder="e.g. smtp.gmail.com">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_smtp_port">SMTP Port</label></th>
                            <td>
                                <input type="number" id="ff_smtp_port" name="ff_form_settings[smtp_port]" value="<?php echo esc_attr($smtp_port); ?>" class="small-text" placeholder="587">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_smtp_encryption">Encryption Security</label></th>
                            <td>
                                <select id="ff_smtp_encryption" name="ff_form_settings[smtp_encryption]">
                                    <option value="none" <?php selected($smtp_encryption, 'none'); ?>>None</option>
                                    <option value="ssl" <?php selected($smtp_encryption, 'ssl'); ?>>SSL (Port 465)</option>
                                    <option value="tls" <?php selected($smtp_encryption, 'tls'); ?>>TLS (Port 587)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_smtp_username">SMTP Username</label></th>
                            <td>
                                <input type="text" id="ff_smtp_username" name="ff_form_settings[smtp_username]" value="<?php echo esc_attr($smtp_username); ?>" class="regular-text" placeholder="e.g. support@domain.com">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_smtp_password">SMTP Password</label></th>
                            <td>
                                <input type="password" id="ff_smtp_password" name="ff_form_settings[smtp_password]" value="<?php echo esc_attr($smtp_password); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_smtp_from_email">From Email Address</label></th>
                            <td>
                                <input type="email" id="ff_smtp_from_email" name="ff_form_settings[smtp_from_email]" value="<?php echo esc_attr($smtp_from_email); ?>" class="regular-text" placeholder="e.g. support@domain.com">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_smtp_from_name">From Sender Name</label></th>
                            <td>
                                <input type="text" id="ff_smtp_from_name" name="ff_form_settings[smtp_from_name]" value="<?php echo esc_attr($smtp_from_name); ?>" class="regular-text" placeholder="e.g. Support Desk">
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style="margin-top: 25px;">
                    <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#78951D; border-color:#78951D; font-weight:600;')); ?>
                </div>
            </div>

            <!-- Diagnostics for Form-Specific SMTP -->
            <div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Form-Specific Live Connection Diagnostics</h3>
                <p class="description">Verify SMTP configurations specifically using this form's credentials before committing.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ff_form_smtp_test_email">Test Recipient Email</label></th>
                            <td>
                                <input type="email" id="ff_form_smtp_test_email" placeholder="e.g. test@gmail.com" class="regular-text">
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p>
                    <button type="button" id="ff_form_smtp_test_btn" class="button button-secondary">Test Connection</button>
                </p>
                
                <div id="ff_form_smtp_test_results" style="display: none; margin-top: 20px;">
                    <div id="ff_form_smtp_test_status_box" style="padding: 10px; border-left: 4px solid #ffb900; background: #fff8e5; font-weight: 600; margin-bottom: 15px;">
                        Diagnostic Result: <span id="ff_form_smtp_test_status"></span>
                    </div>
                    <div>
                        <strong>Raw SMTP Handshake Log:</strong>
                        <pre id="ff_form_smtp_test_log" style="background: #f6f7f7; border: 1px solid #ccd0d4; padding: 10px; font-family: monospace; font-size: 11px; white-space: pre-wrap; max-height: 300px; overflow-y: auto; margin-top: 5px;"></pre>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel 4: Admin Notifications -->
        <div id="ff-admin-email-panel" class="ff-panel-content" style="display:none;">
            <?php
            $email_enabled = isset($form_settings['email_enabled']) ? $form_settings['email_enabled'] : '';
            $email_to = isset($form_settings['email_to']) ? $form_settings['email_to'] : '';
            $email_subject = isset($form_settings['email_subject']) ? $form_settings['email_subject'] : '';

            $admin_template_id     = isset($form_settings['admin_email_template_id']) ? $form_settings['admin_email_template_id'] : 'inherit';
            $admin_primary_color   = isset($form_settings['admin_email_primary_color']) ? $form_settings['admin_email_primary_color'] : '';
            $admin_bg_color        = isset($form_settings['admin_email_bg_color']) ? $form_settings['admin_email_bg_color'] : '';
            $admin_text_color      = isset($form_settings['admin_email_text_color']) ? $form_settings['admin_email_text_color'] : '';
            $admin_header_bg_color = isset($form_settings['admin_email_header_bg_color']) ? $form_settings['admin_email_header_bg_color'] : 'inherit';
            $admin_card_bg_color   = isset($form_settings['admin_email_card_bg_color']) ? $form_settings['admin_email_card_bg_color'] : 'inherit';
            $admin_footer_bg_color = isset($form_settings['admin_email_footer_bg_color']) ? $form_settings['admin_email_footer_bg_color'] : 'inherit';
            $admin_callout         = isset($form_settings['admin_email_callout']) ? $form_settings['admin_email_callout'] : '';
            $admin_logo            = isset($form_settings['admin_email_logo']) ? $form_settings['admin_email_logo'] : '';
            $admin_logo_width      = isset($form_settings['admin_email_logo_width']) ? $form_settings['admin_email_logo_width'] : '';
            $admin_title           = isset($form_settings['admin_email_title']) ? $form_settings['admin_email_title'] : '';
            $admin_body            = isset($form_settings['admin_email_body']) ? $form_settings['admin_email_body'] : '';
            $admin_footer          = isset($form_settings['admin_email_footer']) ? $form_settings['admin_email_footer'] : '';

            // Global settings for visual preview fallbacks
            $g_template_id     = isset($global_settings['admin_email_template_id']) ? $global_settings['admin_email_template_id'] : '1';
            $g_primary_color   = isset($global_settings['admin_email_primary_color']) ? $global_settings['admin_email_primary_color'] : '#78951D';
            $g_bg_color        = isset($global_settings['admin_email_bg_color']) ? $global_settings['admin_email_bg_color'] : '#f3f4f6';
            $g_text_color      = isset($global_settings['admin_email_text_color']) ? $global_settings['admin_email_text_color'] : '#1f2937';
            $g_header_bg_color = isset($global_settings['admin_email_header_bg_color']) ? $global_settings['admin_email_header_bg_color'] : '#3a3a3a';
            $g_card_bg_color   = isset($global_settings['admin_email_card_bg_color']) ? $global_settings['admin_email_card_bg_color'] : '#ffffff';
            $g_footer_bg_color = isset($global_settings['admin_email_footer_bg_color']) ? $global_settings['admin_email_footer_bg_color'] : '#fafafa';
            $g_callout         = isset($global_settings['admin_email_callout']) ? $global_settings['admin_email_callout'] : '';
            $g_logo            = isset($global_settings['admin_email_logo']) ? $global_settings['admin_email_logo'] : '';
            $g_logo_width      = isset($global_settings['admin_email_logo_width']) ? intval($global_settings['admin_email_logo_width']) : 120;
            $g_title           = isset($global_settings['admin_email_title']) ? $global_settings['admin_email_title'] : 'Neues Formular empfangen';
            $g_body            = isset($global_settings['admin_email_body']) ? $global_settings['admin_email_body'] : ff_firebase_get_default_admin_template();
            $g_footer          = isset($global_settings['admin_email_footer']) ? $global_settings['admin_email_footer'] : '&copy; {year} {site_name}. Alle Rechte vorbehalten.';
            ?>
            <div class="ff-preview-layout-container">
                <!-- Controls -->
                <div style="min-width: 0; width: 100%;">
                    <div class="card" style="margin-top: 0; padding: 20px; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Admin Notification Overrides</h3>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="ff_email_enabled">Enable Admin Notifications</label></th>
                                    <td>
                                        <input type="checkbox" id="ff_email_enabled" name="ff_form_settings[email_enabled]" value="yes" <?php checked($email_enabled, 'yes'); ?>>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_email_to">Recipient Admin Email</label></th>
                                    <td>
                                        <input type="email" id="ff_email_to" name="ff_form_settings[email_to]" value="<?php echo esc_attr($email_to); ?>" class="regular-text" placeholder="Default: Global Recipient">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_email_subject">Email Subject</label></th>
                                    <td>
                                        <input type="text" id="ff_email_subject" name="ff_form_settings[email_subject]" value="<?php echo esc_attr($email_subject); ?>" class="regular-text" placeholder="Default: New Client Registration">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card" style="padding: 20px; margin-top: 20px; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Visual Layout overrides</h3>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_template_id">Choose Template</label></th>
                                    <td>
                                        <select id="ff_admin_email_template_id" name="ff_form_settings[admin_email_template_id]" style="width: 100%;">
                                            <option value="inherit" <?php selected($admin_template_id, 'inherit'); ?>>Inherit Global (Template <?php echo esc_attr($g_template_id); ?>)</option>
                                            <option value="1" <?php selected($admin_template_id, '1'); ?>>Template 1: Modern Centered (Default)</option>
                                            <option value="2" <?php selected($admin_template_id, '2'); ?>>Template 2: Left-Aligned Classic (Corporate)</option>
                                            <option value="3" <?php selected($admin_template_id, '3'); ?>>Template 3: Sleek Dark Mode</option>
                                            <option value="4" <?php selected($admin_template_id, '4'); ?>>Template 4: Accent Sidebar</option>
                                            <option value="5" <?php selected($admin_template_id, '5'); ?>>Template 5: Soft Rounded (Friendly/Modern)</option>
                                            <option value="6" <?php selected($admin_template_id, '6'); ?>>Template 6: Bold Colored Header Block</option>
                                            <option value="7" <?php selected($admin_template_id, '7'); ?>>Template 7: Two-Tone Minimal Card</option>
                                            <option value="8" <?php selected($admin_template_id, '8'); ?>>Template 8: High-Contrast Technical Grid</option>
                                            <option value="9" <?php selected($admin_template_id, '9'); ?>>Template 9: Elegant Hero Banner</option>
                                            <option value="10" <?php selected($admin_template_id, '10'); ?>>Template 10: Clean Compact Strip</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_logo">Select Brand Logo</label></th>
                                    <td>
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            <input type="text" id="ff_admin_email_logo" name="ff_form_settings[admin_email_logo]" value="<?php echo esc_attr($admin_logo); ?>" class="regular-text" style="flex: 1;" placeholder="<?php echo !empty($g_logo) ? esc_attr($g_logo) : 'Inherit Global/Site Logo'; ?>">
                                            <button type="button" class="button ff-upload-logo-btn" data-target="ff_admin_email_logo">Upload/Select</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_logo_width">Logo Display Width</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="range" id="ff_admin_email_logo_width_slider" min="50" max="400" step="10" value="<?php echo !empty($admin_logo_width) ? esc_attr($admin_logo_width) : $g_logo_width; ?>" style="flex: 1;" oninput="document.getElementById('ff_admin_email_logo_width').value = this.value; jQuery('#ff_admin_email_logo_width').trigger('change');">
                                            <input type="number" id="ff_admin_email_logo_width" name="ff_form_settings[admin_email_logo_width]" value="<?php echo esc_attr($admin_logo_width); ?>" style="width: 70px;" min="50" max="400" step="10" placeholder="<?php echo $g_logo_width; ?>" oninput="document.getElementById('ff_admin_email_logo_width_slider').value = this.value; jQuery(this).trigger('change');"> px
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_primary_color">Theme Accent Color</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_admin_email_primary_color" name="ff_form_settings[admin_email_primary_color]" value="<?php echo (!empty($admin_primary_color) && $admin_primary_color !== 'inherit') ? esc_attr($admin_primary_color) : $g_primary_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($admin_primary_color) || $admin_primary_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($admin_primary_color) && $admin_primary_color !== 'inherit') ? esc_html($admin_primary_color) : esc_html($g_primary_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_admin_email_primary_color_inherit" name="ff_form_settings[admin_email_primary_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_admin_email_primary_color" data-global="<?php echo esc_attr($g_primary_color); ?>" <?php checked(empty($admin_primary_color) || $admin_primary_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_bg_color">Wrapper Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_admin_email_bg_color" name="ff_form_settings[admin_email_bg_color]" value="<?php echo (!empty($admin_bg_color) && $admin_bg_color !== 'inherit') ? esc_attr($admin_bg_color) : $g_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($admin_bg_color) || $admin_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($admin_bg_color) && $admin_bg_color !== 'inherit') ? esc_html($admin_bg_color) : esc_html($g_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_admin_email_bg_color_inherit" name="ff_form_settings[admin_email_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_admin_email_bg_color" data-global="<?php echo esc_attr($g_bg_color); ?>" <?php checked(empty($admin_bg_color) || $admin_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_header_bg_color">Header Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_admin_email_header_bg_color" name="ff_form_settings[admin_email_header_bg_color]" value="<?php echo (!empty($admin_header_bg_color) && $admin_header_bg_color !== 'inherit') ? esc_attr($admin_header_bg_color) : $g_header_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($admin_header_bg_color) || $admin_header_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($admin_header_bg_color) && $admin_header_bg_color !== 'inherit') ? esc_html($admin_header_bg_color) : esc_html($g_header_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_admin_email_header_bg_color_inherit" name="ff_form_settings[admin_email_header_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_admin_email_header_bg_color" data-global="<?php echo esc_attr($g_header_bg_color); ?>" <?php checked(empty($admin_header_bg_color) || $admin_header_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_card_bg_color">Card Container Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_admin_email_card_bg_color" name="ff_form_settings[admin_email_card_bg_color]" value="<?php echo (!empty($admin_card_bg_color) && $admin_card_bg_color !== 'inherit') ? esc_attr($admin_card_bg_color) : $g_card_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($admin_card_bg_color) || $admin_card_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($admin_card_bg_color) && $admin_card_bg_color !== 'inherit') ? esc_html($admin_card_bg_color) : esc_html($g_card_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_admin_email_card_bg_color_inherit" name="ff_form_settings[admin_email_card_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_admin_email_card_bg_color" data-global="<?php echo esc_attr($g_card_bg_color); ?>" <?php checked(empty($admin_card_bg_color) || $admin_card_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_footer_bg_color">Footer Area Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_admin_email_footer_bg_color" name="ff_form_settings[admin_email_footer_bg_color]" value="<?php echo (!empty($admin_footer_bg_color) && $admin_footer_bg_color !== 'inherit') ? esc_attr($admin_footer_bg_color) : $g_footer_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($admin_footer_bg_color) || $admin_footer_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($admin_footer_bg_color) && $admin_footer_bg_color !== 'inherit') ? esc_html($admin_footer_bg_color) : esc_html($g_footer_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_admin_email_footer_bg_color_inherit" name="ff_form_settings[admin_email_footer_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_admin_email_footer_bg_color" data-global="<?php echo esc_attr($g_footer_bg_color); ?>" <?php checked(empty($admin_footer_bg_color) || $admin_footer_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_text_color">Message Text Color</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_admin_email_text_color" name="ff_form_settings[admin_email_text_color]" value="<?php echo (!empty($admin_text_color) && $admin_text_color !== 'inherit') ? esc_attr($admin_text_color) : $g_text_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($admin_text_color) || $admin_text_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($admin_text_color) && $admin_text_color !== 'inherit') ? esc_html($admin_text_color) : esc_html($g_text_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_admin_email_text_color_inherit" name="ff_form_settings[admin_email_text_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_admin_email_text_color" data-global="<?php echo esc_attr($g_text_color); ?>" <?php checked(empty($admin_text_color) || $admin_text_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Column 2: Message Details -->
                <div style="min-width: 0; width: 100%;">
                    <div class="card" style="padding: 20px; margin-top: 0; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Message Details</h3>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_title">Email Heading/Title</label></th>
                                    <td>
                                        <input type="text" id="ff_admin_email_title" name="ff_form_settings[admin_email_title]" value="<?php echo esc_attr($admin_title); ?>" class="large-text" style="width: 100%;" placeholder="<?php echo esc_attr($g_title); ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_callout_select">Callout/Alert Box Text</label></th>
                                    <td>
                                        <select id="ff_admin_email_callout_select" style="width: 100%; margin-bottom: 8px;">
                                            <option value="inherit" <?php selected(empty($admin_callout) || $admin_callout === 'inherit'); ?>>Inherit Global (<?php echo !empty($g_callout) ? esc_attr(substr($g_callout, 0, 30)) . '...' : 'Disabled'; ?>)</option>
                                            <option value="none" <?php selected($admin_callout === '[none]'); ?>>Disable Alert Box</option>
                                            <option value="custom" <?php selected(!empty($admin_callout) && $admin_callout !== 'inherit' && $admin_callout !== '[none]'); ?>>Custom Alert Box Text</option>
                                        </select>
                                        <textarea id="ff_admin_email_callout" name="ff_form_settings[admin_email_callout]" rows="3" class="large-text" style="width: 100%; font-family: sans-serif; font-size: 13px; line-height: 1.5; <?php if (empty($admin_callout) || $admin_callout === 'inherit' || $admin_callout === '[none]') echo 'display: none;'; ?>" placeholder="e.g. A new inquiry is awaiting review."><?php echo ($admin_callout !== 'inherit' && $admin_callout !== '[none]') ? esc_textarea($admin_callout) : ''; ?></textarea>
                                        <p class="description">Adds a styled notice box with a thick primary left border and light tinted background below the message body.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_body">Email Body Text</label></th>
                                    <td>
                                        <textarea id="ff_admin_email_body" name="ff_form_settings[admin_email_body]" rows="8" class="large-text" style="width: 100%; font-family: sans-serif; font-size: 13px; line-height: 1.5;" placeholder="<?php echo esc_attr($g_body); ?>"><?php echo esc_textarea($admin_body); ?></textarea>
                                        <p class="description" style="margin-top: 10px; line-height: 1.5;">
                                            <strong>Placeholders:</strong> <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code>, <code>{all_fields}</code> (beautiful grid table).
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_admin_email_footer">Email Footer Text</label></th>
                                    <td>
                                        <input type="text" id="ff_admin_email_footer" name="ff_form_settings[admin_email_footer]" value="<?php echo esc_attr($admin_footer); ?>" class="large-text" style="width: 100%;" placeholder="<?php echo esc_attr($g_footer); ?>">
                                        <p class="description"><strong>Placeholders:</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{year}</code></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div style="margin-top: 25px;">
                            <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#78951D; border-color:#78951D; font-weight:600;')); ?>
                        </div>
                    </div>
                </div>

                <!-- Preview -->
                <div style="position: sticky; top: 40px; min-width: 0; width: 100%;">
                    <div class="card" style="margin-top: 0; padding: 20px; background: #2a2a2a; border: 1px solid #4a4a4a; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: #fff; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-visibility" style="color: #78951D;"></span> Live Override Preview
                        </h3>
                        <div style="background: #ffffff; border-radius: 6px; overflow: hidden; border: 1px solid #4a4a4a; position: relative; height: auto;">
                            <iframe id="ff_admin_email_preview_iframe" style="width: 100%; height: 500px; border: none; background: #f3f4f6; display: block;"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel 5: Client Thank-You -->
        <div id="ff-client-email-panel" class="ff-panel-content" style="display:none;">
            <?php
            $client_email_enabled = isset($form_settings['client_email_enabled']) ? $form_settings['client_email_enabled'] : '';
            $client_email_subject = isset($form_settings['client_email_subject']) ? $form_settings['client_email_subject'] : '';

            $client_template_id     = isset($form_settings['client_email_template_id']) ? $form_settings['client_email_template_id'] : 'inherit';
            $client_primary_color   = isset($form_settings['client_email_primary_color']) ? $form_settings['client_email_primary_color'] : '';
            $client_bg_color        = isset($form_settings['client_email_bg_color']) ? $form_settings['client_email_bg_color'] : '';
            $client_text_color      = isset($form_settings['client_email_text_color']) ? $form_settings['client_email_text_color'] : '';
            $client_header_bg_color = isset($form_settings['client_email_header_bg_color']) ? $form_settings['client_email_header_bg_color'] : 'inherit';
            $client_card_bg_color   = isset($form_settings['client_email_card_bg_color']) ? $form_settings['client_email_card_bg_color'] : 'inherit';
            $client_footer_bg_color = isset($form_settings['client_email_footer_bg_color']) ? $form_settings['client_email_footer_bg_color'] : 'inherit';
            $client_callout         = isset($form_settings['client_email_callout']) ? $form_settings['client_email_callout'] : '';
            $client_logo            = isset($form_settings['client_email_logo']) ? $form_settings['client_email_logo'] : '';
            $client_logo_width      = isset($form_settings['client_email_logo_width']) ? $form_settings['client_email_logo_width'] : '';
            $client_title           = isset($form_settings['client_email_title']) ? $form_settings['client_email_title'] : '';
            $client_body            = isset($form_settings['client_email_body']) ? $form_settings['client_email_body'] : '';
            $client_footer          = isset($form_settings['client_email_footer']) ? $form_settings['client_email_footer'] : '';

            // Global settings for visual preview fallbacks
            $g_client_template_id     = isset($global_settings['client_email_template_id']) ? $global_settings['client_email_template_id'] : '1';
            $g_client_primary_color   = isset($global_settings['client_email_primary_color']) ? $global_settings['client_email_primary_color'] : '#78951D';
            $g_client_bg_color        = isset($global_settings['client_email_bg_color']) ? $global_settings['client_email_bg_color'] : '#f3f4f6';
            $g_client_text_color      = isset($global_settings['client_email_text_color']) ? $global_settings['client_email_text_color'] : '#1f2937';
            $g_client_header_bg_color = isset($global_settings['client_email_header_bg_color']) ? $global_settings['client_email_header_bg_color'] : '#3a3a3a';
            $g_client_card_bg_color   = isset($global_settings['client_email_card_bg_color']) ? $global_settings['client_email_card_bg_color'] : '#ffffff';
            $g_client_footer_bg_color = isset($global_settings['client_email_footer_bg_color']) ? $global_settings['client_email_footer_bg_color'] : '#fafafa';
            $g_client_callout         = isset($global_settings['client_email_callout']) ? $global_settings['client_email_callout'] : '';
            $g_client_logo            = isset($global_settings['client_email_logo']) ? $global_settings['client_email_logo'] : '';
            $g_client_logo_width      = isset($global_settings['client_email_logo_width']) ? intval($global_settings['client_email_logo_width']) : 120;
            $g_client_title           = isset($global_settings['client_email_title']) ? $global_settings['client_email_title'] : 'Vielen Dank für Ihre Registrierung';
            $g_client_body            = isset($global_settings['client_email_body']) ? $global_settings['client_email_body'] : ff_firebase_get_default_client_template();
            $g_client_footer          = isset($global_settings['client_email_footer']) ? $global_settings['client_email_footer'] : 'Mit freundlichen Grüßen,\n{site_name}';
            ?>
            <div class="ff-preview-layout-container">
                <!-- Controls -->
                <div style="min-width: 0; width: 100%;">
                    <div class="card" style="margin-top: 0; padding: 20px; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Client Confirmation / Thank-You Overrides</h3>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_enabled">Enable Client Thank-You Emails</label></th>
                                    <td>
                                        <input type="checkbox" id="ff_client_email_enabled" name="ff_form_settings[client_email_enabled]" value="yes" <?php checked($client_email_enabled, 'yes'); ?>>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_subject">Email Subject</label></th>
                                    <td>
                                        <input type="text" id="ff_client_email_subject" name="ff_form_settings[client_email_subject]" value="<?php echo esc_attr($client_email_subject); ?>" class="regular-text" style="width: 100%;" placeholder="e.g. Vielen Dank für Ihre Registrierung">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card" style="padding: 20px; margin-top: 20px; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Visual Layout overrides</h3>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_template_id">Choose Template</label></th>
                                    <td>
                                        <select id="ff_client_email_template_id" name="ff_form_settings[client_email_template_id]" style="width: 100%;">
                                            <option value="inherit" <?php selected($client_template_id, 'inherit'); ?>>Inherit Global (Template <?php echo esc_attr($g_client_template_id); ?>)</option>
                                            <option value="1" <?php selected($client_template_id, '1'); ?>>Template 1: Modern Centered (Default)</option>
                                            <option value="2" <?php selected($client_template_id, '2'); ?>>Template 2: Left-Aligned Classic (Corporate)</option>
                                            <option value="3" <?php selected($client_template_id, '3'); ?>>Template 3: Sleek Dark Mode</option>
                                            <option value="4" <?php selected($client_template_id, '4'); ?>>Template 4: Accent Sidebar</option>
                                            <option value="5" <?php selected($client_template_id, '5'); ?>>Template 5: Soft Rounded (Friendly/Modern)</option>
                                            <option value="6" <?php selected($client_template_id, '6'); ?>>Template 6: Bold Colored Header Block</option>
                                            <option value="7" <?php selected($client_template_id, '7'); ?>>Template 7: Two-Tone Minimal Card</option>
                                            <option value="8" <?php selected($client_template_id, '8'); ?>>Template 8: High-Contrast Technical Grid</option>
                                            <option value="9" <?php selected($client_template_id, '9'); ?>>Template 9: Elegant Hero Banner</option>
                                            <option value="10" <?php selected($client_template_id, '10'); ?>>Template 10: Clean Compact Strip</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_logo">Select Brand Logo</label></th>
                                    <td>
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            <input type="text" id="ff_client_email_logo" name="ff_form_settings[client_email_logo]" value="<?php echo esc_attr($client_logo); ?>" class="regular-text" style="flex: 1;" placeholder="<?php echo !empty($g_client_logo) ? esc_attr($g_client_logo) : 'Inherit Global/Site Logo'; ?>">
                                            <button type="button" class="button ff-upload-logo-btn" data-target="ff_client_email_logo">Upload/Select</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_logo_width">Logo Display Width</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="range" id="ff_client_email_logo_width_slider" min="50" max="400" step="10" value="<?php echo !empty($client_logo_width) ? esc_attr($client_logo_width) : $g_client_logo_width; ?>" style="flex: 1;" oninput="document.getElementById('ff_client_email_logo_width').value = this.value; jQuery('#ff_client_email_logo_width').trigger('change');">
                                            <input type="number" id="ff_client_email_logo_width" name="ff_form_settings[client_email_logo_width]" value="<?php echo esc_attr($client_logo_width); ?>" style="width: 70px;" min="50" max="400" step="10" placeholder="<?php echo $g_client_logo_width; ?>" oninput="document.getElementById('ff_client_email_logo_width_slider').value = this.value; jQuery(this).trigger('change');"> px
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_primary_color">Theme Accent Color</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_client_email_primary_color" name="ff_form_settings[client_email_primary_color]" value="<?php echo (!empty($client_primary_color) && $client_primary_color !== 'inherit') ? esc_attr($client_primary_color) : $g_client_primary_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($client_primary_color) || $client_primary_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($client_primary_color) && $client_primary_color !== 'inherit') ? esc_html($client_primary_color) : esc_html($g_client_primary_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_client_email_primary_color_inherit" name="ff_form_settings[client_email_primary_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_client_email_primary_color" data-global="<?php echo esc_attr($g_client_primary_color); ?>" <?php checked(empty($client_primary_color) || $client_primary_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_bg_color">Wrapper Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_client_email_bg_color" name="ff_form_settings[client_email_bg_color]" value="<?php echo (!empty($client_bg_color) && $client_bg_color !== 'inherit') ? esc_attr($client_bg_color) : $g_client_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($client_bg_color) || $client_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($client_bg_color) && $client_bg_color !== 'inherit') ? esc_html($client_bg_color) : esc_html($g_client_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_client_email_bg_color_inherit" name="ff_form_settings[client_email_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_client_email_bg_color" data-global="<?php echo esc_attr($g_client_bg_color); ?>" <?php checked(empty($client_bg_color) || $client_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_header_bg_color">Header Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_client_email_header_bg_color" name="ff_form_settings[client_email_header_bg_color]" value="<?php echo (!empty($client_header_bg_color) && $client_header_bg_color !== 'inherit') ? esc_attr($client_header_bg_color) : $g_client_header_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($client_header_bg_color) || $client_header_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($client_header_bg_color) && $client_header_bg_color !== 'inherit') ? esc_html($client_header_bg_color) : esc_html($g_client_header_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_client_email_header_bg_color_inherit" name="ff_form_settings[client_email_header_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_client_email_header_bg_color" data-global="<?php echo esc_attr($g_client_header_bg_color); ?>" <?php checked(empty($client_header_bg_color) || $client_header_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_card_bg_color">Card Container Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_client_email_card_bg_color" name="ff_form_settings[client_email_card_bg_color]" value="<?php echo (!empty($client_card_bg_color) && $client_card_bg_color !== 'inherit') ? esc_attr($client_card_bg_color) : $g_client_card_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($client_card_bg_color) || $client_card_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($client_card_bg_color) && $client_card_bg_color !== 'inherit') ? esc_html($client_card_bg_color) : esc_html($g_client_card_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_client_email_card_bg_color_inherit" name="ff_form_settings[client_email_card_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_client_email_card_bg_color" data-global="<?php echo esc_attr($g_client_card_bg_color); ?>" <?php checked(empty($client_card_bg_color) || $client_card_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_footer_bg_color">Footer Area Background</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_client_email_footer_bg_color" name="ff_form_settings[client_email_footer_bg_color]" value="<?php echo (!empty($client_footer_bg_color) && $client_footer_bg_color !== 'inherit') ? esc_attr($client_footer_bg_color) : $g_client_footer_bg_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($client_footer_bg_color) || $client_footer_bg_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($client_footer_bg_color) && $client_footer_bg_color !== 'inherit') ? esc_html($client_footer_bg_color) : esc_html($g_client_footer_bg_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_client_email_footer_bg_color_inherit" name="ff_form_settings[client_email_footer_bg_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_client_email_footer_bg_color" data-global="<?php echo esc_attr($g_client_footer_bg_color); ?>" <?php checked(empty($client_footer_bg_color) || $client_footer_bg_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_text_color">Message Text Color</label></th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="color" id="ff_client_email_text_color" name="ff_form_settings[client_email_text_color]" value="<?php echo (!empty($client_text_color) && $client_text_color !== 'inherit') ? esc_attr($client_text_color) : $g_client_text_color; ?>" style="width: 50px; height: 35px; border: 1px solid #ccd0d4; padding: 0; cursor: pointer; border-radius: 4px;" <?php disabled(empty($client_text_color) || $client_text_color === 'inherit'); ?>>
                                            <span style="font-family: monospace;"><?php echo (!empty($client_text_color) && $client_text_color !== 'inherit') ? esc_html($client_text_color) : esc_html($g_client_text_color) . ' (Global)'; ?></span>
                                            <label style="font-size: 11px; margin-left: 10px; cursor: pointer; user-select: none;">
                                                <input type="checkbox" id="ff_client_email_text_color_inherit" name="ff_form_settings[client_email_text_color_inherit]" value="yes" class="ff-color-inherit-toggle" data-target="ff_client_email_text_color" data-global="<?php echo esc_attr($g_client_text_color); ?>" <?php checked(empty($client_text_color) || $client_text_color === 'inherit'); ?>> Inherit
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Column 2: Message Details -->
                <div style="min-width: 0; width: 100%;">
                    <div class="card" style="padding: 20px; margin-top: 0; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Message Details</h3>
                        
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_title">Email Heading/Title</label></th>
                                    <td>
                                        <input type="text" id="ff_client_email_title" name="ff_form_settings[client_email_title]" value="<?php echo esc_attr($client_title); ?>" class="large-text" style="width: 100%;" placeholder="<?php echo esc_attr($g_client_title); ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_callout_select">Callout/Alert Box Text</label></th>
                                    <td>
                                        <select id="ff_client_email_callout_select" style="width: 100%; margin-bottom: 8px;">
                                            <option value="inherit" <?php selected(empty($client_callout) || $client_callout === 'inherit'); ?>>Inherit Global (<?php echo !empty($g_client_callout) ? esc_attr(substr($g_client_callout, 0, 30)) . '...' : 'Disabled'; ?>)</option>
                                            <option value="none" <?php selected($client_callout === '[none]'); ?>>Disable Alert Box</option>
                                            <option value="custom" <?php selected(!empty($client_callout) && $client_callout !== 'inherit' && $client_callout !== '[none]'); ?>>Custom Alert Box Text</option>
                                        </select>
                                        <textarea id="ff_client_email_callout" name="ff_form_settings[client_email_callout]" rows="3" class="large-text" style="width: 100%; font-family: sans-serif; font-size: 13px; line-height: 1.5; <?php if (empty($client_callout) || $client_callout === 'inherit' || $client_callout === '[none]') echo 'display: none;'; ?>" placeholder="e.g. Thank you for your message."><?php echo ($client_callout !== 'inherit' && $client_callout !== '[none]') ? esc_textarea($client_callout) : ''; ?></textarea>
                                        <p class="description">Adds a styled notice box with a thick primary left border and light tinted background below the message body.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_body">Email Body Text</label></th>
                                    <td>
                                        <textarea id="ff_client_email_body" name="ff_form_settings[client_email_body]" rows="8" class="large-text" style="width: 100%; font-family: sans-serif; font-size: 13px; line-height: 1.5;" placeholder="<?php echo esc_attr($g_client_body); ?>"><?php echo esc_textarea($client_body); ?></textarea>
                                        <p class="description" style="margin-top: 10px; line-height: 1.5;">
                                            <strong>Placeholders:</strong> <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code>, <code>{all_fields}</code> (beautiful grid table).
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="ff_client_email_footer">Email Footer Text</label></th>
                                    <td>
                                        <input type="text" id="ff_client_email_footer" name="ff_form_settings[client_email_footer]" value="<?php echo esc_attr($client_footer); ?>" class="large-text" style="width: 100%;" placeholder="<?php echo esc_attr($g_client_footer); ?>">
                                        <p class="description"><strong>Placeholders:</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{year}</code></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div style="margin-top: 25px;">
                            <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#78951D; border-color:#78951D; font-weight:600;')); ?>
                        </div>
                    </div>
                </div>

                <!-- Preview -->
                <div style="position: sticky; top: 40px; min-width: 0; width: 100%;">
                    <div class="card" style="margin-top: 0; padding: 20px; background: #2a2a2a; border: 1px solid #4a4a4a; border-radius: 8px;">
                        <h3 style="margin-top: 0; color: #fff; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-visibility" style="color: #78951D;"></span> Live Override Preview
                        </h3>
                        <div style="background: #ffffff; border-radius: 6px; overflow: hidden; border: 1px solid #4a4a4a; position: relative; height: auto;">
                            <iframe id="ff_client_email_preview_iframe" style="width: 100%; height: 500px; border: none; background: #f3f4f6; display: block;"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel 6: Form Entries -->
        <div id="ff-entries-panel" class="ff-panel-content" style="display:none;">
            <?php
            $submissions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $submissions_table WHERE form_id = %d ORDER BY submitted_at DESC", $form_id));
            ?>
            <div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Submission Entries for: <?php echo esc_html($form_title); ?></h3>
                <p class="description">View local submission database entries recorded specifically for this form.</p>
                
                <div class="table-container" style="margin-top: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; overflow: hidden;">
                    <table class="submissions-table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th width="5%">ID</th>
                                <th width="15%">Name</th>
                                <th width="18%">Email</th>
                                <th width="22%">Address</th>
                                <th width="12%">PLZ / Ort</th>
                                <th width="13%">Firebase Sync Status</th>
                                <th width="15%">Submitted At</th>
                                <th width="10%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($submissions)) : ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #646970;">
                                        No entries found for this form.
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($submissions as $sub) : ?>
                                    <tr>
                                        <td><?php echo esc_html($sub->id); ?></td>
                                        <td>
                                            <strong><?php echo esc_html($sub->name); ?></strong>
                                            <br>
                                            <a href="#" class="ff-view-details-link" data-data="<?php echo esc_attr($sub->form_data); ?>" data-id="<?php echo $sub->id; ?>" data-name="<?php echo esc_attr($sub->name); ?>" data-email="<?php echo esc_attr($sub->email); ?>" style="color:#78951D; font-weight:600; font-size:11px; text-decoration:none;">View Custom Fields</a>
                                        </td>
                                        <td><a href="mailto:<?php echo esc_attr($sub->email); ?>"><?php echo esc_html($sub->email); ?></a></td>
                                        <td><?php echo esc_html($sub->address); ?></td>
                                        <td><?php echo esc_html($sub->plz_ort); ?></td>
                                        <td>
                                            <?php if ($sub->sync_status === 'synced') : ?>
                                                <span class="firebase-status-badge status-synced" title="Synced Document Path" style="display:inline-block; padding:4px 10px; border-radius:4px; font-weight:600; font-size:11px; text-transform:uppercase; background-color:#d1e7dd; color:#0f5132;">Synced</span>
                                                <br><code style="font-size: 10px; color: #646970; display: block; margin-top: 4px;"><?php echo esc_html($sub->firebase_doc_id); ?></code>
                                            <?php elseif ($sub->sync_status === 'failed') : ?>
                                                <span class="firebase-status-badge status-failed" title="Failed to Sync" style="display:inline-block; padding:4px 10px; border-radius:4px; font-weight:600; font-size:11px; text-transform:uppercase; background-color:#f8d7da; color:#842029;">Failed</span>
                                                <br><span style="font-size: 10px; color: #b32d2e; display: block; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 4px;"><?php echo esc_html($sub->sync_error); ?></span>
                                            <?php else : ?>
                                                <span class="firebase-status-badge status-pending" style="display:inline-block; padding:4px 10px; border-radius:4px; font-weight:600; font-size:11px; text-transform:uppercase; background-color:#fff3cd; color:#664d03;">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(date('Y-m-d H:i', strtotime($sub->submitted_at))); ?></td>
                                        <td class="actions-column">
                                            <?php
                                            $resync_url = wp_nonce_url(admin_url('admin.php?page=firebase_forms&action=edit&id=' . $form_id . '&sub_action=resync&sub_id=' . $sub->id . '#ff-entries-panel'), 'resync_submission_' . $sub->id);
                                            $delete_url = wp_nonce_url(admin_url('admin.php?page=firebase_forms&action=edit&id=' . $form_id . '&sub_action=delete&sub_id=' . $sub->id . '#ff-entries-panel'), 'delete_submission_' . $sub->id);
                                            ?>
                                            <a href="<?php echo esc_url($resync_url); ?>" class="resync-link" style="color: #78951D !important; text-decoration: none; font-weight: 500;" title="Retry syncing to Firebase">Resync</a>
                                            <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" style="color: #b32d2e !important; text-decoration: none; font-weight: 500;" onclick="return confirm('Are you sure you want to delete this submission?')" title="Delete locally">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Panel 7: Google Maps -->
        <div id="ff-maps-panel" class="ff-panel-content" style="display:none;">
            <?php
            $maps_enabled = isset($form_settings['maps_enabled']) ? $form_settings['maps_enabled'] : 'no';
            $maps_api_key = isset($form_settings['maps_api_key']) ? $form_settings['maps_api_key'] : '';
            $maps_address = isset($form_settings['maps_address']) ? $form_settings['maps_address'] : 'Vienna, Austria';
            $maps_zoom = isset($form_settings['maps_zoom']) ? intval($form_settings['maps_zoom']) : 14;
            $maps_type = isset($form_settings['maps_type']) ? $form_settings['maps_type'] : 'roadmap';
            $maps_height = isset($form_settings['maps_height']) ? intval($form_settings['maps_height']) : 400;

            // Load saved CSS Filter values (default fallback enfolded)
            $maps_blur = isset($form_settings['maps_blur']) ? intval($form_settings['maps_blur']) : 0;
            $maps_brightness = isset($form_settings['maps_brightness']) ? intval($form_settings['maps_brightness']) : 100;
            $maps_contrast = isset($form_settings['maps_contrast']) ? intval($form_settings['maps_contrast']) : 100;
            $maps_saturation = isset($form_settings['maps_saturation']) ? intval($form_settings['maps_saturation']) : 100;
            $maps_hue = isset($form_settings['maps_hue']) ? intval($form_settings['maps_hue']) : 0;

            // Retrieve global maps API key fallback
            $global_settings = get_option('ff_firebase_settings', array());
            $global_maps_key = isset($global_settings['maps_api_key']) ? $global_settings['maps_api_key'] : '';
            ?>
            <div style="display: flex; gap: 24px; margin-top: 20px; align-items: flex-start; flex-wrap: wrap;">
                <!-- Left Sidebar: Elementor-Style Dark Builder Panel -->
                <div style="flex: 1 1 360px; max-width: 420px; background: #26292c; border: 1px solid #1f2124; border-radius: 4px; color: #a4afb7; font-family: -apple-system, BlinkMacSystemFont, sans-serif; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin: 0; min-width: 320px;">
                    
                    <!-- Header Bar -->
                    <div style="background: #1d2124; border-bottom: 1px solid #191b1d; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between;">
                        <h3 style="margin: 0; color: #fff; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Edit Google Maps</h3>
                        <span class="dashicons dashicons-arrow-down-alt2" style="color: #a4afb7; font-size: 16px; width: 16px; height: 16px; cursor: pointer;"></span>
                    </div>

                    <!-- Sidebar Tab Navigation -->
                    <div style="background: #202326; border-bottom: 1px solid #1c1f21; display: flex;">
                        <a href="#" class="ff-ele-tab active" data-ele-tab="ele-content" style="flex: 1; padding: 12px 5px; color: #a4afb7; text-decoration: none; font-size: 10px; font-weight: 600; text-transform: uppercase; border-bottom: 3px solid transparent; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 4px; border-right: 1px solid #1c1f21;">
                            <span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; margin-top:-2px;"></span> Content
                        </a>
                        <a href="#" class="ff-ele-tab" data-ele-tab="ele-style" style="flex: 1; padding: 12px 5px; color: #a4afb7; text-decoration: none; font-size: 10px; font-weight: 600; text-transform: uppercase; border-bottom: 3px solid transparent; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 4px; border-right: 1px solid #1c1f21;">
                            <span class="dashicons dashicons-admin-customizer" style="font-size: 14px; width: 14px; height: 14px; margin-top:-2px;"></span> Style
                        </a>
                        <a href="#" class="ff-ele-tab" data-ele-tab="ele-advanced" style="flex: 1; padding: 12px 5px; color: #a4afb7; text-decoration: none; font-size: 10px; font-weight: 600; text-transform: uppercase; border-bottom: 3px solid transparent; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; gap: 4px;">
                            <span class="dashicons dashicons-admin-generic" style="font-size: 14px; width: 14px; height: 14px; margin-top:-2px;"></span> Advanced
                        </a>
                    </div>

                    <style>
                        .ff-ele-tab:hover { color: #fff !important; background: #23272a; }
                        .ff-ele-tab.active { color: #ec527c !important; border-bottom-color: #ec527c !important; background: #1d2124; }
                        .ff-ele-field-group { margin-bottom: 18px; }
                        .ff-ele-label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 8px; color: #e0e6ed; }
                        .ff-ele-input { background: #1d2124; border: 1px solid #1c1f21; border-radius: 3px; color: #fff; padding: 8px 12px; width: 100%; box-sizing: border-box; font-size: 13px; transition: border-color 0.2s; }
                        .ff-ele-input:focus { border-color: #ec527c; outline: none; }
                        .ff-ele-slider-row { display: flex; align-items: center; gap: 10px; }
                        .ff-ele-slider-val { background: #1d2124; border: 1px solid #1c1f21; color: #fff; padding: 4px 8px; border-radius: 3px; font-size: 11px; min-width: 30px; text-align: center; font-weight: 600; }
                        .ff-ele-range { flex-grow: 1; accent-color: #ec527c; }
                    </style>

                    <!-- Sidebar Body Panels -->
                    <div style="padding: 20px; background: #26292c; flex-grow: 1;">
                        
                        <!-- A. CONTENT TAB PANEL -->
                        <div id="ele-content" class="ff-ele-panel-content">
                            <div class="ff-ele-field-group" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #2f3338; padding-bottom: 15px;">
                                <label class="ff-ele-label" for="ff_maps_enabled" style="margin-bottom: 0;">Enable Google Maps</label>
                                <input type="checkbox" id="ff_maps_enabled" name="ff_form_settings[maps_enabled]" value="yes" <?php checked($maps_enabled, 'yes'); ?> style="accent-color: #ec527c; width: 16px; height: 16px; cursor: pointer;">
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label" for="ff_maps_api_key">Google Maps API Key</label>
                                <input type="password" id="ff_maps_api_key" name="ff_form_settings[maps_api_key]" value="<?php echo esc_attr($maps_api_key); ?>" class="ff-ele-input" placeholder="<?php echo !empty($global_maps_key) ? 'Using Global API Key Fallback' : 'Enter API Key'; ?>" data-global-key="<?php echo esc_attr($global_maps_key); ?>">
                                <p style="font-size: 11px; color: #8c9ba5; margin-top: 5px; margin-bottom: 0; line-height: 1.4;">
                                    <?php if (!empty($global_maps_key)) : ?>
                                        Using global API key. Override here if desired.
                                    <?php else : ?>
                                        Configured under Submissions -> Google Maps.
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label" for="ff_maps_address">Location / Address</label>
                                <div style="position: relative;">
                                    <input type="text" id="ff_maps_address" name="ff_form_settings[maps_address]" value="<?php echo esc_attr($maps_address); ?>" class="ff-ele-input" placeholder="e.g. London Eye, London">
                                    <span class="dashicons dashicons-database" style="position: absolute; right: 10px; top: 10px; color: #8c9ba5; font-size: 16px; width: 16px; height: 16px;"></span>
                                </div>
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label" for="ff_maps_zoom">Zoom Level</label>
                                <div class="ff-ele-slider-row">
                                    <input type="range" id="ff_maps_zoom" name="ff_form_settings[maps_zoom]" min="1" max="21" value="<?php echo esc_attr($maps_zoom); ?>" class="ff-ele-range">
                                    <div id="ff_maps_zoom_val_box" class="ff-ele-slider-val"><?php echo esc_html($maps_zoom); ?></div>
                                </div>
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label" for="ff_maps_height">Height (px)</label>
                                <div class="ff-ele-slider-row">
                                    <input type="range" id="ff_maps_height" name="ff_form_settings[maps_height]" min="100" max="1000" value="<?php echo esc_attr($maps_height); ?>" class="ff-ele-range">
                                    <div id="ff_maps_height_val_box" class="ff-ele-slider-val"><?php echo esc_html($maps_height); ?></div>
                                </div>
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label" for="ff_maps_type">Map View Type</label>
                                <select id="ff_maps_type" name="ff_form_settings[maps_type]" class="ff-ele-input" style="cursor: pointer;">
                                    <option value="roadmap" <?php selected($maps_type, 'roadmap'); ?>>roadmap (Standard)</option>
                                    <option value="satellite" <?php selected($maps_type, 'satellite'); ?>>satellite (Satellite)</option>
                                    <option value="hybrid" <?php selected($maps_type, 'hybrid'); ?>>hybrid (Hybrid)</option>
                                    <option value="terrain" <?php selected($maps_type, 'terrain'); ?>>terrain (Terrain)</option>
                                </select>
                            </div>
                        </div>

                        <!-- B. STYLE TAB PANEL -->
                        <div id="ele-style" class="ff-ele-panel-content" style="display:none;">
                            <!-- Normal / Hover buttons bar -->
                            <div style="background: #202326; border-radius: 3px; display: flex; padding: 2px; margin-bottom: 18px;">
                                <button type="button" class="ff-ele-sub-tab active" style="flex: 1; border: none; background: transparent; color: #fff; font-size: 11px; padding: 6px; border-radius: 2px; font-weight: 600; cursor: pointer;">Normal</button>
                                <button type="button" class="ff-ele-sub-tab" style="flex: 1; border: none; background: transparent; color: #a4afb7; font-size: 11px; padding: 6px; border-radius: 2px; font-weight: 600; cursor: not-allowed;" disabled>Hover</button>
                            </div>

                            <!-- CSS Filters Section Accordion -->
                            <div style="background: #1d2124; border: 1px solid #1c1f21; border-radius: 3px; overflow: hidden;">
                                <div style="padding: 12px 15px; border-bottom: 1px solid #1c1f21; display: flex; justify-content: space-between; align-items: center; background: #202326;">
                                    <span style="font-size: 11px; font-weight: 700; color: #e0e6ed; text-transform: uppercase; letter-spacing: 0.5px;">CSS Filters</span>
                                    <span class="dashicons dashicons-edit" style="color: #ec527c; font-size: 16px; width: 16px; height: 16px; cursor: pointer;"></span>
                                </div>
                                <div style="padding: 15px;">
                                    <!-- Sliders for CSS Filters -->
                                    <div class="ff-ele-field-group">
                                        <label class="ff-ele-label" style="font-size: 10px; color: #8c9ba5;">Blur (px)</label>
                                        <div class="ff-ele-slider-row">
                                            <input type="range" id="ff_maps_filter_blur" name="ff_form_settings[maps_blur]" min="0" max="10" value="<?php echo esc_attr($maps_blur); ?>" class="ff-ele-range">
                                            <div id="ff_maps_blur_val" class="ff-ele-slider-val"><?php echo esc_html($maps_blur); ?></div>
                                        </div>
                                    </div>

                                    <div class="ff-ele-field-group">
                                        <label class="ff-ele-label" style="font-size: 10px; color: #8c9ba5;">Brightness (%)</label>
                                        <div class="ff-ele-slider-row">
                                            <input type="range" id="ff_maps_filter_brightness" name="ff_form_settings[maps_brightness]" min="0" max="200" value="<?php echo esc_attr($maps_brightness); ?>" class="ff-ele-range">
                                            <div id="ff_maps_brightness_val" class="ff-ele-slider-val"><?php echo esc_html($maps_brightness); ?></div>
                                        </div>
                                    </div>

                                    <div class="ff-ele-field-group">
                                        <label class="ff-ele-label" style="font-size: 10px; color: #8c9ba5;">Contrast (%)</label>
                                        <div class="ff-ele-slider-row">
                                            <input type="range" id="ff_maps_filter_contrast" name="ff_form_settings[maps_contrast]" min="0" max="200" value="<?php echo esc_attr($maps_contrast); ?>" class="ff-ele-range">
                                            <div id="ff_maps_contrast_val" class="ff-ele-slider-val"><?php echo esc_html($maps_contrast); ?></div>
                                        </div>
                                    </div>

                                    <div class="ff-ele-field-group">
                                        <label class="ff-ele-label" style="font-size: 10px; color: #8c9ba5;">Saturation (%)</label>
                                        <div class="ff-ele-slider-row">
                                            <input type="range" id="ff_maps_filter_saturation" name="ff_form_settings[maps_saturation]" min="0" max="200" value="<?php echo esc_attr($maps_saturation); ?>" class="ff-ele-range">
                                            <div id="ff_maps_saturation_val" class="ff-ele-slider-val"><?php echo esc_html($maps_saturation); ?></div>
                                        </div>
                                    </div>

                                    <div class="ff-ele-field-group" style="margin-bottom: 5px;">
                                        <label class="ff-ele-label" style="font-size: 10px; color: #8c9ba5;">Hue (deg)</label>
                                        <div class="ff-ele-slider-row">
                                            <input type="range" id="ff_maps_filter_hue" name="ff_form_settings[maps_hue]" min="0" max="360" value="<?php echo esc_attr($maps_hue); ?>" class="ff-ele-range">
                                            <div id="ff_maps_hue_val" class="ff-ele-slider-val"><?php echo esc_html($maps_hue); ?></div>
                                        </div>
                                        <!-- Rainbow hue bar -->
                                        <div style="height: 6px; border-radius: 3px; background: linear-gradient(to right, #ff0000, #ffff00, #00ff00, #00ffff, #0000ff, #ff00ff, #ff0000); margin-top: 8px; width: 100%;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- C. ADVANCED TAB PANEL -->
                        <div id="ele-advanced" class="ff-ele-panel-content" style="display:none;">
                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label">Margin</label>
                                <div style="display: flex; gap: 6px; text-align: center;">
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                </div>
                                <span style="font-size: 9px; color: #8c9ba5; display: block; margin-top: 4px; text-align: center; text-transform: uppercase;">Top &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Right &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Bottom &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Left</span>
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label">Padding</label>
                                <div style="display: flex; gap: 6px; text-align: center;">
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                    <input type="text" class="ff-ele-input" value="0" style="padding: 6px; text-align: center;" disabled>
                                </div>
                                <span style="font-size: 9px; color: #8c9ba5; display: block; margin-top: 4px; text-align: center; text-transform: uppercase;">Top &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Right &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Bottom &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Left</span>
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label">CSS ID</label>
                                <input type="text" class="ff-ele-input" placeholder="e.g. my-custom-map" disabled>
                            </div>

                            <div class="ff-ele-field-group">
                                <label class="ff-ele-label">CSS Classes</label>
                                <input type="text" class="ff-ele-input" placeholder="e.g. elementor-map-wrapper" disabled>
                            </div>
                        </div>

                    </div>

                    <!-- Sidebar Footer Action (Save) -->
                    <div style="background: #202326; border-top: 1px solid #1c1f21; padding: 15px 20px;">
                        <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#ec527c; border-color:#ec527c; font-weight:700; width:100%; border-radius:3px; padding:10px; transition: background 0.2s, border-color 0.2s;')); ?>
                        <style>
                            #submit:hover { background: #d63d66 !important; border-color: #d63d66 !important; }
                        </style>
                    </div>
                </div>

                <!-- Right Panel: Elementor-Style Live Preview Card -->
                <div style="flex: 1 1 400px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
                    <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-location" style="color: #ec527c; font-size: 20px; width: 20px; height: 20px;"></span>
                        Live Interactive Map Preview
                    </h3>
                    <p class="description" style="margin-bottom: 15px;">Hover over the map preview container to activate editing overlay and click to auto-focus controls.</p>
                    
                    <div id="ff_maps_preview_placeholder" style="display: none; background: #f6f7f7; border: 1px dashed #c3c4c7; border-radius: 4px; text-align: center; padding: 60px 20px; color: #646970;">
                        <span class="dashicons dashicons-google" style="font-size: 40px; width: 40px; height: 40px; color: #c3c4c7; margin-bottom: 10px;"></span>
                        <p style="margin: 0; font-weight: 600; color: #2c3338;">Google Maps Integration is Disabled</p>
                        <span style="font-size: 12px; color: #8c8f94; display: block; margin-top: 5px;">Check "Enable Google Maps" to load the interactive preview.</span>
                    </div>

                    <div id="ff_maps_preview_no_key" style="display: none; background: #fff8e5; border-left: 4px solid #ffb900; padding: 15px; margin-bottom: 15px; color: #3c3214; font-size: 12px; border-radius: 0 4px 4px 0;">
                        <strong>API Key Required:</strong> Using standard fallback map preview since no custom API Key is set. Configure your global key under <strong>Submissions -> Google Maps</strong>.
                    </div>
                    
                    <!-- Maps Preview Container with Hover Border & Edit Button overlay -->
                    <div id="ff_maps_preview_container_wrapper" style="position: relative; border-radius: 4px; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.06); cursor: pointer;">
                        
                        <!-- Hover Edit Button Overlay -->
                        <button type="button" id="ff_maps_preview_hover_edit_btn" style="position: absolute; top: 15px; right: 15px; background: #ec527c; color: #fff; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 6px rgba(0,0,0,0.2); border: none; cursor: pointer; opacity: 0; transition: opacity 0.2s ease, transform 0.2s ease; z-index: 10; outline: none;">
                            <span class="dashicons dashicons-edit" style="font-size: 16px; width: 16px; height: 16px; margin-left: 2px; margin-top: 2px;"></span>
                        </button>

                        <!-- Inner Preview Iframe container -->
                        <div id="ff_maps_preview_container" style="border: 1px solid #ccd0d4; border-radius: 4px; overflow: hidden; background: #f0f0f1; line-height: 0;">
                            <iframe id="ff_maps_preview_iframe" width="100%" height="400" frameborder="0" style="border:0; display: block; transition: filter 0.2s ease;" allowfullscreen src=""></iframe>
                        </div>
                    </div>
                    
                    <style>
                        #ff_maps_preview_container_wrapper:hover {
                            outline: 3px solid #ec527c;
                            outline-offset: -3px;
                        }
                        #ff_maps_preview_container_wrapper:hover #ff_maps_preview_hover_edit_btn {
                            opacity: 1;
                        }
                        #ff_maps_preview_hover_edit_btn:hover {
                            transform: scale(1.1);
                            background: #d63d66;
                        }
                        /* Highlights focused active field in left panel */
                        .ff-ele-field-highlight {
                            border-color: #ec527c !important;
                            box-shadow: 0 0 0 2px rgba(236, 82, 124, 0.2) !important;
                        }
                    </style>
                </div>
        </div>

        <!-- Panel 8: reCAPTCHA -->
        <div id="ff-recaptcha-panel" class="ff-panel-content" style="display:none;">
            <?php
            $recaptcha_enabled = isset($form_settings['recaptcha_enabled']) ? $form_settings['recaptcha_enabled'] : 'no';
            
            // Retrieve global recaptcha site key
            $global_settings = get_option('ff_firebase_settings', array());
            $recaptcha_type = isset($global_settings['recaptcha_type']) ? $global_settings['recaptcha_type'] : 'v2';
            $recaptcha_site_key = isset($global_settings['recaptcha_site_key']) ? $global_settings['recaptcha_site_key'] : '';
            ?>
            <div style="display: flex; gap: 24px; margin-top: 20px; align-items: flex-start; flex-wrap: wrap;">
                <!-- Left Sidebar: Elementor-Style Dark Builder Panel -->
                <div style="flex: 1 1 360px; max-width: 420px; background: #26292c; border: 1px solid #1f2124; border-radius: 4px; color: #a4afb7; font-family: -apple-system, BlinkMacSystemFont, sans-serif; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin: 0; min-width: 320px;">
                    
                    <!-- Header Bar -->
                    <div style="background: #1d2124; border-bottom: 1px solid #191b1d; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between;">
                        <h3 style="margin: 0; color: #fff; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Edit reCAPTCHA</h3>
                        <span class="dashicons dashicons-arrow-down-alt2" style="color: #a4afb7; font-size: 16px; width: 16px; height: 16px;"></span>
                    </div>

                    <!-- Sidebar Body Panels -->
                    <div style="padding: 20px; background: #26292c; flex-grow: 1;">
                        <div class="ff-ele-field-group" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #2f3338; padding-bottom: 15px; margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_recaptcha_enabled" style="margin-bottom: 0;">Enable reCAPTCHA</label>
                            <input type="checkbox" id="ff_recaptcha_enabled" name="ff_form_settings[recaptcha_enabled]" value="yes" <?php checked($recaptcha_enabled, 'yes'); ?> style="accent-color: #ec527c; width: 16px; height: 16px; cursor: pointer;">
                        </div>

                        <div class="ff-ele-field-group">
                            <label class="ff-ele-label">Active Type</label>
                            <div style="background: #1d2124; border: 1px solid #1c1f21; border-radius: 3px; color: #fff; padding: 10px 12px; font-size: 13px; font-weight: 600;">
                                <?php if ($recaptcha_type === 'v3') : ?>
                                    Google reCAPTCHA v3 (Invisible)
                                <?php else : ?>
                                    Google reCAPTCHA v2 (Checkbox)
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ff-ele-field-group" style="margin-top: 15px;">
                            <p style="font-size: 11px; color: #8c9ba5; margin: 0; line-height: 1.4;">
                                reCAPTCHA is verified on form submission. Global keys must be set in your WordPress Dashboard under <strong>Submissions -> reCAPTCHA</strong>.
                            </p>
                        </div>
                    </div>

                    <!-- Sidebar Footer Action (Save) -->
                    <div style="background: #202326; border-top: 1px solid #1c1f21; padding: 15px 20px;">
                        <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#ec527c; border-color:#ec527c; font-weight:700; width:100%; border-radius:3px; padding:10px; transition: background 0.2s, border-color 0.2s;')); ?>
                    </div>
                </div>

                <!-- Right Panel: Live Preview Card -->
                <div style="flex: 1 1 400px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
                    <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-shield" style="color: #ec527c; font-size: 20px; width: 20px; height: 20px;"></span>
                        reCAPTCHA Preview Layout
                    </h3>
                    <p class="description" style="margin-bottom: 15px;">This is how reCAPTCHA renders right before the form's submit button.</p>

                    <!-- Preview Container -->
                    <div id="ff_recaptcha_preview_wrapper" style="border: 1px solid #ccd0d4; border-radius: 4px; padding: 25px; background: #f8f9fa; display: flex; justify-content: center; align-items: center; min-height: 120px;">
                        <?php if ($recaptcha_enabled !== 'yes') : ?>
                            <div style="color: #8c9ba5; font-size: 13px; font-style: italic;">reCAPTCHA is disabled on this form.</div>
                        <?php elseif (empty($recaptcha_site_key)) : ?>
                            <!-- Beautiful mockup checkbox if keys are missing -->
                            <div style="background: #ffffff; border: 1px solid #d3d3d3; border-radius: 3px; width: 300px; height: 74px; box-shadow: 0px 0px 4px 1px rgba(0,0,0,0.08); display: flex; align-items: center; justify-content: space-between; padding: 0 12px; font-family: Roboto, helvetica, arial, sans-serif; box-sizing: border-box;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 24px; height: 24px; border: 2px solid #c1c1c1; border-radius: 2px; background: #fff; cursor: pointer; transition: background 0.2s;" onmouseenter="this.style.borderColor='#b2b2b2'" onmouseleave="this.style.borderColor='#c1c1c1'"></div>
                                    <span style="font-size: 14px; color: #2c2c2c; font-weight: 400;">I'm not a robot</span>
                                </div>
                                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;">
                                    <img src="https://www.gstatic.com/recaptcha/api2/logo_48.png" style="width: 32px; height: 32px;" alt="">
                                    <span style="font-size: 8px; color: #555555;">reCAPTCHA</span>
                                    <span style="font-size: 8px; color: #555555;"><a href="#" style="text-decoration: none; color: #555;">Privacy</a> - <a href="#" style="text-decoration: none; color: #555;">Terms</a></span>
                                </div>
                            </div>
                        <?php else : ?>
                            <?php if ($recaptcha_type === 'v3') : ?>
                                <div style="text-align: center; color: #2e7d32; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-yes-alt" style="font-size: 20px; width: 20px; height: 20px;"></span>
                                    reCAPTCHA v3 (Invisible) is active and running in the background.
                                </div>
                            <?php else : ?>
                                <!-- Render actual active recaptcha v2 -->
                                <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($recaptcha_site_key); ?>"></div>
                                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel 9: Style Settings -->
        <div id="ff-style-panel" class="ff-panel-content" style="display:none;">
            <?php
            $font_family = isset($form_settings['font_family']) ? $form_settings['font_family'] : '';
            $bg_color = isset($form_settings['bg_color']) ? $form_settings['bg_color'] : '';
            $label_color = isset($form_settings['label_color']) ? $form_settings['label_color'] : '';
            $input_color = isset($form_settings['input_color']) ? $form_settings['input_color'] : '';
            $btn_bg_color = isset($form_settings['btn_bg_color']) ? $form_settings['btn_bg_color'] : '';
            $btn_text_color = isset($form_settings['btn_text_color']) ? $form_settings['btn_text_color'] : '';
            $label_size = isset($form_settings['label_size']) ? $form_settings['label_size'] : '';
            $input_size = isset($form_settings['input_size']) ? $form_settings['input_size'] : '';
            $max_width = isset($form_settings['max_width']) ? $form_settings['max_width'] : '';
            $custom_fonts = get_option('ff_firebase_custom_fonts', array());
            if (!is_array($custom_fonts)) {
                $custom_fonts = array();
            }
            ?>
            <div style="display: flex; gap: 24px; margin-top: 20px; align-items: flex-start; flex-wrap: wrap;">
                <!-- Left Sidebar: Elementor-Style Dark Builder Panel -->
                <div style="flex: 1 1 360px; max-width: 420px; background: #26292c; border: 1px solid #1f2124; border-radius: 4px; color: #a4afb7; font-family: -apple-system, BlinkMacSystemFont, sans-serif; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin: 0; min-width: 320px;">
                    
                    <!-- Header Bar -->
                    <div style="background: #1d2124; border-bottom: 1px solid #191b1d; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between;">
                        <h3 style="margin: 0; color: #fff; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Form Styling</h3>
                        <span class="dashicons dashicons-admin-appearance" style="color: #a4afb7; font-size: 16px; width: 16px; height: 16px;"></span>
                    </div>

                    <!-- Sidebar Body Panels -->
                    <div style="padding: 20px; background: #26292c; flex-grow: 1;">
                        <!-- Font Family -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_font_family">Font Family</label>
                            <select id="ff_style_font_family" name="ff_form_settings[font_family]" style="width:100%; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                                <option value="" <?php selected($font_family, ''); ?>>Default (Inherit)</option>
                                <optgroup label="Standard Fonts">
                                    <option value="Arial, sans-serif" <?php selected($font_family, 'Arial, sans-serif'); ?>>Arial</option>
                                    <option value="'Helvetica Neue', Helvetica, sans-serif" <?php selected($font_family, "'Helvetica Neue', Helvetica, sans-serif"); ?>>Helvetica</option>
                                    <option value="'Times New Roman', Times, serif" <?php selected($font_family, "'Times New Roman', Times, serif"); ?>>Times New Roman</option>
                                    <option value="Georgia, serif" <?php selected($font_family, 'Georgia, serif'); ?>>Georgia</option>
                                    <option value="'Courier New', Courier, monospace" <?php selected($font_family, "'Courier New', Courier, monospace"); ?>>Courier New</option>
                                    <option value="Verdana, Geneva, sans-serif" <?php selected($font_family, 'Verdana, Geneva, sans-serif'); ?>>Verdana</option>
                                    <option value="'Trebuchet MS', Helvetica, sans-serif" <?php selected($font_family, "'Trebuchet MS', Helvetica, sans-serif"); ?>>Trebuchet MS</option>
                                    <option value="system-ui, sans-serif" <?php selected($font_family, 'system-ui, sans-serif'); ?>>System UI</option>
                                </optgroup>
                                <?php if (!empty($custom_fonts)) : ?>
                                    <optgroup label="Custom Fonts">
                                        <?php foreach ($custom_fonts as $name => $url) : ?>
                                            <option value="<?php echo esc_attr("'$name'"); ?>" <?php selected($font_family, "'$name'"); ?>><?php echo esc_html($name); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Max Width -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_max_width">Max Width (px or %)</label>
                            <input type="text" id="ff_style_max_width" name="ff_form_settings[max_width]" value="<?php echo esc_attr($max_width); ?>" placeholder="e.g. 600px or 100%" style="width:100%; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                        </div>

                        <!-- Form Background Color -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_bg_color">Form Background Color</label>
                            <div style="display:flex; gap:8px;">
                                <input type="color" id="ff_style_bg_color_picker" value="<?php echo (!empty($bg_color) && strpos($bg_color, '#') === 0) ? esc_attr($bg_color) : '#ffffff'; ?>" style="width:40px; height:32px; border:none; padding:0; cursor:pointer; background:none;">
                                <input type="text" id="ff_style_bg_color" name="ff_form_settings[bg_color]" value="<?php echo esc_attr($bg_color); ?>" placeholder="e.g. #ffffff or transparent" style="flex:1; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                            </div>
                        </div>

                        <!-- Label Color -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_label_color">Label Color</label>
                            <div style="display:flex; gap:8px;">
                                <input type="color" id="ff_style_label_color_picker" value="<?php echo (!empty($label_color) && strpos($label_color, '#') === 0) ? esc_attr($label_color) : '#333333'; ?>" style="width:40px; height:32px; border:none; padding:0; cursor:pointer; background:none;">
                                <input type="text" id="ff_style_label_color" name="ff_form_settings[label_color]" value="<?php echo esc_attr($label_color); ?>" placeholder="e.g. #333333" style="flex:1; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                            </div>
                        </div>

                        <!-- Label Size -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_label_size">Label Font Size (px)</label>
                            <input type="number" id="ff_style_label_size" name="ff_form_settings[label_size]" value="<?php echo esc_attr($label_size); ?>" placeholder="e.g. 13" min="8" max="72" style="width:100%; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                        </div>

                        <!-- Input Text Color -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_input_color">Input Text & Border Color</label>
                            <div style="display:flex; gap:8px;">
                                <input type="color" id="ff_style_input_color_picker" value="<?php echo (!empty($input_color) && strpos($input_color, '#') === 0) ? esc_attr($input_color) : '#333333'; ?>" style="width:40px; height:32px; border:none; padding:0; cursor:pointer; background:none;">
                                <input type="text" id="ff_style_input_color" name="ff_form_settings[input_color]" value="<?php echo esc_attr($input_color); ?>" placeholder="e.g. #333333" style="flex:1; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                            </div>
                        </div>

                        <!-- Input Size -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_input_size">Input Font Size (px)</label>
                            <input type="number" id="ff_style_input_size" name="ff_form_settings[input_size]" value="<?php echo esc_attr($input_size); ?>" placeholder="e.g. 14" min="8" max="72" style="width:100%; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                        </div>

                        <!-- Button Background Color -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_btn_bg_color">Button Background Color</label>
                            <div style="display:flex; gap:8px;">
                                <input type="color" id="ff_style_btn_bg_color_picker" value="<?php echo (!empty($btn_bg_color) && strpos($btn_bg_color, '#') === 0) ? esc_attr($btn_bg_color) : '#78951D'; ?>" style="width:40px; height:32px; border:none; padding:0; cursor:pointer; background:none;">
                                <input type="text" id="ff_style_btn_bg_color" name="ff_form_settings[btn_bg_color]" value="<?php echo esc_attr($btn_bg_color); ?>" placeholder="e.g. #78951D" style="flex:1; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                            </div>
                        </div>

                        <!-- Button Text Color -->
                        <div class="ff-ele-field-group" style="margin-bottom: 18px;">
                            <label class="ff-ele-label" for="ff_style_btn_text_color">Button Text Color</label>
                            <div style="display:flex; gap:8px;">
                                <input type="color" id="ff_style_btn_text_color_picker" value="<?php echo (!empty($btn_text_color) && strpos($btn_text_color, '#') === 0) ? esc_attr($btn_text_color) : '#ffffff'; ?>" style="width:40px; height:32px; border:none; padding:0; cursor:pointer; background:none;">
                                <input type="text" id="ff_style_btn_text_color" name="ff_form_settings[btn_text_color]" value="<?php echo esc_attr($btn_text_color); ?>" placeholder="e.g. #ffffff" style="flex:1; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Footer Action (Save) -->
                    <div style="background: #202326; border-top: 1px solid #1c1f21; padding: 15px 20px;">
                        <?php submit_button('Save Style Settings', 'primary', 'submit', false, array('style' => 'background:#78951D; border-color:#78951D; font-weight:700; width:100%; border-radius:3px; padding:10px; transition: background 0.2s, border-color 0.2s; height:auto; text-shadow:none; box-shadow:none; color:#ffffff;')); ?>
                    </div>
                </div>

                <!-- Right Side: Font Uploader + Style Live Preview -->
                <div style="flex: 1 1 400px; display: flex; flex-direction: column; gap: 20px; box-sizing: border-box;">
                    <!-- Custom Font Uploader Card -->
                    <div style="background: #26292c; border: 1px solid #1f2124; border-radius: 4px; color: #a4afb7; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0; color: #fff; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #2f3338; padding-bottom: 10px; margin-bottom: 15px; display: flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-upload" style="color: #78951D; font-size: 16px; width: 16px; height: 16px;"></span>
                            Upload Custom Font
                        </h3>
                        
                        <div style="margin-bottom: 12px;">
                            <label style="display:block; font-size:11px; margin-bottom:5px; color:#a4afb7; font-weight:600;">Font Name</label>
                            <input type="text" name="ff_new_font_name" placeholder="e.g. Outfit Bold" style="width:100%; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label style="display:block; font-size:11px; margin-bottom:5px; color:#a4afb7; font-weight:600;">Font File (.woff2, .woff, .ttf, .otf)</label>
                            <div style="display:flex; gap:8px;">
                                <input type="text" id="ff_new_font_url" name="ff_new_font_url" placeholder="Paste URL or upload..." style="flex:1; background:#1d2124; border:1px solid #1c1f21; color:#fff; border-radius:3px; padding:6px 10px;">
                                <button type="button" class="button ff-select-font-btn" style="background:#78951D; border-color:#78951D; color:#fff; font-weight:600; text-shadow:none; height:auto; padding:5px 12px;">Select File</button>
                            </div>
                        </div>
                        
                        <button type="submit" name="ff_add_custom_font" value="1" class="button button-primary" style="background:#78951D; border-color:#78951D; font-weight:700; text-shadow:none; width:100%; padding:8px; height:auto; border-radius:3px; box-shadow:none;">Add Font</button>
                    </div>

                    <!-- Uploaded Custom Fonts List -->
                    <div style="background: #26292c; border: 1px solid #1f2124; border-radius: 4px; color: #a4afb7; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0; color: #fff; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #2f3338; padding-bottom: 10px; margin-bottom: 15px; display: flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-editor-paragraph" style="color: #78951D; font-size: 16px; width: 16px; height: 16px;"></span>
                            Uploaded Custom Fonts
                        </h3>
                        
                        <?php if (empty($custom_fonts)) : ?>
                            <p style="font-size:12px; font-style:italic; margin:0; color:#8c9ba5;">No custom fonts uploaded yet.</p>
                        <?php else : ?>
                            <input type="hidden" id="ff_delete_font_name" name="ff_delete_font_name" value="">
                            <ul style="margin:0; padding:0; list-style:none;">
                                <?php foreach ($custom_fonts as $name => $url) : ?>
                                    <li style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #2f3338; padding:8px 0; font-size:12px;">
                                        <div style="flex-grow:1; min-width:0; padding-right:10px;">
                                            <strong style="color:#fff; display:block;"><?php echo esc_html($name); ?></strong>
                                            <span style="font-size:10px; color:#8c9ba5; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html($url); ?></span>
                                        </div>
                                        <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'delete_font', 'font_name' => urlencode($name)), admin_url('admin.php?page=firebase_forms&action=edit&id=' . $form_id)), 'delete_font_' . $name); ?>" style="color:#f44336; text-decoration:none; font-weight:600; font-size:12px;" onclick="return confirm('Are you sure you want to delete this font?');">Delete</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>

                    <!-- Live Style Preview Card -->
                    <div style="background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                        <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-visibility" style="color: #78951D; font-size: 20px; width: 20px; height: 20px;"></span>
                            Style Live Preview
                        </h3>
                        <p class="description" style="margin-bottom: 20px;">See how your styles and fonts render. Press <strong>Save Style Settings</strong> to apply.</p>
                        
                        <!-- Styled Form Mockup Container -->
                        <div id="ff_style_preview_container" style="border: 1px solid #ccd0d4; border-radius: 6px; padding: 25px; background: #ffffff; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: background-color 0.2s;">
                            <h4 id="ff_style_preview_title" style="margin-top:0; margin-bottom:20px; font-size:18px; font-weight:700; color:#1f2937; text-align:center;">Contact Form</h4>
                            
                            <div style="margin-bottom: 15px;">
                                <label id="ff_style_preview_label" style="display:block; margin-bottom:6px; font-weight:600; font-size:13px; color:#4b5563; text-transform:uppercase; letter-spacing:0.5px;">Name</label>
                                <input type="text" id="ff_style_preview_input" value="John Doe" disabled style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #1f2937; background-color: #f9fafb; box-sizing: border-box;">
                            </div>
                            
                            <div>
                                <button type="button" id="ff_style_preview_submit" disabled style="display:block; width:100%; padding:12px; background-color:#78951D; color:#ffffff; border:none; border-radius:30px; font-size:14px; font-weight:600; cursor:default; text-align:center; text-transform:uppercase; letter-spacing:0.5px;">Submit</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        </form> <!-- Closes the main form -->
        </div> <!-- Closes wrap -->

        <!-- Top Navigation Tabs Switcher Script -->
        <script>
        jQuery(document).ready(function($) {
            // Horizontal Top Navigation Tabs Switcher
            $('.nav-tab-wrapper a').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('data-panel');
                
                // Remove active class from all tabs & hide all panels
                $('.nav-tab-wrapper a').removeClass('nav-tab-active');
                $('.ff-panel-content').removeClass('active').hide();
                
                // Add active class to clicked tab & show matching panel
                $(this).addClass('nav-tab-active');
                $('#' + target).addClass('active').show();
                
                // Render visual email previews when clicking tabs
                if (target === 'ff-admin-email-panel' && typeof updateAdminPreview === 'function') {
                    updateAdminPreview();
                }
                if (target === 'ff-client-email-panel' && typeof updateClientPreview === 'function') {
                    updateClientPreview();
                }
                
                if (target === 'ff-maps-panel' && typeof updateMapsPreview === 'function') {
                    updateMapsPreview();
                }
                
                // Update URL hash for persistent deep linking
                window.location.hash = target;
            });

            // Check if URL has a hash for page load deep-linking
            if (window.location.hash) {
                var hash = window.location.hash.substring(1);
                // Strips sub_id or query string if present
                hash = hash.split('?')[0];
                var tab = $('.nav-tab-wrapper a[data-panel="' + hash + '"]');
                if (tab.length) {
                    tab.trigger('click');
                }
            }

            // Connection Diagnostics for Form-Specific SMTP
            $('#ff_form_smtp_test_btn').on('click', function(e) {
                e.preventDefault();
                var email = $('#ff_form_smtp_test_email').val();
                if (!email) { alert('Please enter a test email address.'); return; }
                
                var btn = $(this);
                btn.prop('disabled', true).text('Testing connection...');
                
                var resultsDiv = $('#ff_form_smtp_test_results');
                var statusBox = $('#ff_form_smtp_test_status_box');
                var statusSpan = $('#ff_form_smtp_test_status');
                var logPre = $('#ff_form_smtp_test_log');
                
                resultsDiv.show();
                statusBox.css('border-left-color', '#ffb900').css('background', '#fff8e5').css('color', '#3c3214');
                statusSpan.html('Initiating SMTP socket connection with overrides...');
                logPre.text('Contacting server... This might take up to 10 seconds.');
                
                // Collect overrides from the input fields in the panel
                var overrides = {
                    smtp_enabled: 'yes',
                    smtp_host: $('#ff_smtp_host').val(),
                    smtp_port: $('#ff_smtp_port').val(),
                    smtp_encryption: $('#ff_smtp_encryption').val(),
                    smtp_username: $('#ff_smtp_username').val(),
                    smtp_password: $('#ff_smtp_password').val(),
                    smtp_from_email: $('#ff_smtp_from_email').val(),
                    smtp_from_name: $('#ff_smtp_from_name').val()
                };
                
                $.post(ajaxurl, {
                    action: 'ff_firebase_test_smtp',
                    nonce: '<?php echo wp_create_nonce("ff_firebase_test_smtp_nonce"); ?>',
                    test_email: email,
                    overrides: overrides
                }, function(res) {
                    btn.prop('disabled', false).text('Test Connection');
                    if (res.success) {
                        statusBox.css('border-left-color', '#46b450').css('background', '#ecf7ed').css('color', '#2c5e3b');
                        statusSpan.html('SMTP Verification Email successfully sent to ' + email + '!');
                    } else {
                        statusBox.css('border-left-color', '#dc3232').css('background', '#fbeae9').css('color', '#761919');
                        statusSpan.html('Failed: ' + (res.data.message || 'SMTP authentication failed.'));
                    }
                    logPre.text(res.data.log || 'No diagnostic handshake was captured.');
                }).fail(function() {
                    btn.prop('disabled', false).text('Test Connection');
                    statusBox.css('border-left-color', '#dc3232').css('background', '#fbeae9').css('color', '#761919');
                    statusSpan.html('Ajax HTTP connection error.');
                    logPre.text('Could not communicate with admin-ajax.php.');
                });
            });

            // Google Maps Live Interactive Preview binders
            function updateMapsPreview() {
                var enabled = $('#ff_maps_enabled').is(':checked');
                var apiKey = $('#ff_maps_api_key').val().trim();
                if (!apiKey) {
                    apiKey = $('#ff_maps_api_key').data('global-key') || '';
                }
                var address = $('#ff_maps_address').val().trim();
                var zoom = $('#ff_maps_zoom').val();
                var mapType = $('#ff_maps_type').val();
                var height = $('#ff_maps_height').val();

                // Update tab range numeric indicators
                $('#ff_maps_zoom_val_box').text(zoom);
                $('#ff_maps_height_val_box').text(height);

                if (!enabled) {
                    $('#ff_maps_preview_placeholder').show();
                    $('#ff_maps_preview_container_wrapper').hide();
                    $('#ff_maps_preview_no_key').hide();
                    return;
                }

                $('#ff_maps_preview_placeholder').hide();
                $('#ff_maps_preview_container_wrapper').show();

                // Set container height dynamically matching slider
                $('#ff_maps_preview_iframe').attr('height', height);

                var src = '';
                if (apiKey) {
                    $('#ff_maps_preview_no_key').hide();
                    src = 'https://www.google.com/maps/embed/v1/place?key=' + encodeURIComponent(apiKey) + '&q=' + encodeURIComponent(address || 'Vienna, Austria') + '&zoom=' + zoom + '&maptype=' + mapType;
                } else {
                    $('#ff_maps_preview_no_key').show();
                    var tLetter = 'm';
                    if (mapType === 'satellite') tLetter = 'k';
                    else if (mapType === 'hybrid') tLetter = 'h';
                    else if (mapType === 'terrain') tLetter = 'p';
                    src = 'https://maps.google.com/maps?q=' + encodeURIComponent(address || 'Vienna, Austria') + '&z=' + zoom + '&t=' + tLetter + '&output=embed';
                }

                if ($('#ff_maps_preview_iframe').attr('src') !== src) {
                    $('#ff_maps_preview_iframe').attr('src', src);
                }

                // Parse and apply CSS Filters in real-time
                var blur = $('#ff_maps_filter_blur').val();
                var brightness = $('#ff_maps_filter_brightness').val();
                var contrast = $('#ff_maps_filter_contrast').val();
                var saturation = $('#ff_maps_filter_saturation').val();
                var hue = $('#ff_maps_filter_hue').val();

                // Update filter numeric labels
                $('#ff_maps_blur_val').text(blur);
                $('#ff_maps_brightness_val').text(brightness);
                $('#ff_maps_contrast_val').text(contrast);
                $('#ff_maps_saturation_val').text(saturation);
                $('#ff_maps_hue_val').text(hue);

                var filterString = 'blur(' + blur + 'px) brightness(' + brightness + '%) contrast(' + contrast + '%) saturate(' + saturation + '%) hue-rotate(' + hue + 'deg)';
                $('#ff_maps_preview_iframe').css('filter', filterString).css('-webkit-filter', filterString);
            }

            // Bind change/input events for instant real-time live preview updates
            $('#ff_maps_enabled, #ff_maps_type').on('change', updateMapsPreview);
            $('#ff_maps_api_key, #ff_maps_address, #ff_maps_zoom, #ff_maps_height').on('input', updateMapsPreview);
            
            // CSS Filters change/input listeners
            $('#ff_maps_filter_blur, #ff_maps_filter_brightness, #ff_maps_filter_contrast, #ff_maps_filter_saturation, #ff_maps_filter_hue').on('input', updateMapsPreview);

            // Left Sidebar Elementor Tabs Switcher
            $('.ff-ele-tab').on('click', function(e) {
                e.preventDefault();
                $('.ff-ele-tab').removeClass('active');
                $('.ff-ele-panel-content').hide();

                $(this).addClass('active');
                var tabTarget = $(this).attr('data-ele-tab');
                $('#' + tabTarget).show();
            });

            // Map Preview Hover Click-to-Edit binder
            $('#ff_maps_preview_container_wrapper, #ff_maps_preview_hover_edit_btn').on('click', function(e) {
                e.preventDefault();
                // Select and activate Content Tab on Left Sidebar
                $('.ff-ele-tab[data-ele-tab="ele-content"]').trigger('click');
                
                // Pulsing highlight effect on the Address Input field
                var addressInput = $('#ff_maps_address');
                addressInput.addClass('ff-ele-field-highlight');
                addressInput.focus();
                
                // Smooth scroll to sidebar top if scrolled out
                var sidebar = $('.ff-ele-tab').parent().parent();
                $('html, body').animate({
                    scrollTop: sidebar.offset().top - 100
                }, 500);

                setTimeout(function() {
                    addressInput.removeClass('ff-ele-field-highlight');
                }, 2000);
            });

            // --- Custom Form Styling Real-Time Preview Scripts ---
            function updateLiveStylePreviews() {
                var fontFamily = $('#ff_style_font_family').val();
                var bgColor = $('#ff_style_bg_color').val();
                var labelColor = $('#ff_style_label_color').val();
                var inputColor = $('#ff_style_input_color').val();
                var btnBgColor = $('#ff_style_btn_bg_color').val();
                var btnTextColor = $('#ff_style_btn_text_color').val();
                var labelSize = $('#ff_style_label_size').val();
                var inputSize = $('#ff_style_input_size').val();
                var maxWidth = $('#ff_style_max_width').val();

                // 1. Update Style Settings tab's preview box
                var previewContainer = $('#ff_style_preview_container');
                previewContainer.css('background-color', bgColor ? bgColor : '#ffffff');
                previewContainer.css('max-width', maxWidth ? maxWidth : '100%');

                if (fontFamily) {
                    previewContainer.css('font-family', fontFamily);
                    $('#ff_style_preview_label').css('font-family', fontFamily);
                    $('#ff_style_preview_input').css('font-family', fontFamily);
                    $('#ff_style_preview_submit').css('font-family', fontFamily);
                } else {
                    previewContainer.css('font-family', '');
                    $('#ff_style_preview_label').css('font-family', '');
                    $('#ff_style_preview_input').css('font-family', '');
                    $('#ff_style_preview_submit').css('font-family', '');
                }

                $('#ff_style_preview_label').css('color', labelColor ? labelColor : '#4b5563');
                $('#ff_style_preview_label').css('font-size', labelSize ? labelSize + 'px' : '13px');
                $('#ff_style_preview_input').css('color', inputColor ? inputColor : '#1f2937').css('border-color', inputColor ? inputColor : '#d1d5db');
                $('#ff_style_preview_input').css('font-size', inputSize ? inputSize + 'px' : '14px');
                $('#ff_style_preview_submit').css('background-color', btnBgColor ? btnBgColor : '#78951D');
                $('#ff_style_preview_submit').css('color', btnTextColor ? btnTextColor : '#ffffff');

                // 2. Update Editor Preview Canvas Styles in Head
                var styleTag = $('#ff-builder-dynamic-styles');
                if (!styleTag.length) {
                    styleTag = $('<style id="ff-builder-dynamic-styles"></style>').appendTo('head');
                }

                var dynamicCss = '';

                // Generate font faces for custom fonts enqueued
                <?php
                $custom_fonts_for_js = get_option('ff_firebase_custom_fonts', array());
                if (is_array($custom_fonts_for_js)) {
                    foreach ($custom_fonts_for_js as $name => $url) {
                        echo "                dynamicCss += \"@font-face { font-family: '" . esc_js($name) . "'; src: url('" . esc_url($url) . "'); }\\n\";\n";
                    }
                }
                ?>

                // Canvas styles
                dynamicCss += '#ff_builder_canvas {';
                if (bgColor) dynamicCss += ' background-color: ' + bgColor + ' !important;';
                if (maxWidth) dynamicCss += ' max-width: ' + maxWidth + ' !important;';
                if (fontFamily) dynamicCss += ' font-family: ' + fontFamily + ' !important;';
                dynamicCss += '}\n';

                // Label styles
                dynamicCss += '#ff_builder_canvas .ff-canvas-card-label-text, #ff_builder_canvas .ff-canvas-card-label {';
                if (labelColor) dynamicCss += ' color: ' + labelColor + ' !important;';
                if (labelSize) dynamicCss += ' font-size: ' + labelSize + 'px !important;';
                if (fontFamily) dynamicCss += ' font-family: ' + fontFamily + ' !important;';
                dynamicCss += '}\n';

                // Input styles
                dynamicCss += '#ff_builder_canvas .ff-canvas-card-preview {';
                if (inputColor) dynamicCss += ' color: ' + inputColor + ' !important; border-color: ' + inputColor + ' !important;';
                if (inputSize) dynamicCss += ' font-size: ' + inputSize + 'px !important;';
                if (fontFamily) dynamicCss += ' font-family: ' + fontFamily + ' !important;';
                dynamicCss += '}\n';

                // Submit button styles
                dynamicCss += '#ff_builder_canvas .ff-form-submit, #ff_builder_canvas button.ff-canvas-card-preview, #ff_builder_canvas input[type="submit"].ff-canvas-card-preview {';
                if (btnBgColor) dynamicCss += ' background-color: ' + btnBgColor + ' !important;';
                if (btnTextColor) dynamicCss += ' color: ' + btnTextColor + ' !important;';
                if (fontFamily) dynamicCss += ' font-family: ' + fontFamily + ' !important;';
                dynamicCss += '}\n';

                styleTag.html(dynamicCss);
            }

            // Bind change/input events for styling updates
            $('#ff_style_font_family, #ff_style_bg_color, #ff_style_label_color, #ff_style_input_color, #ff_style_btn_bg_color, #ff_style_btn_text_color, #ff_style_label_size, #ff_style_input_size, #ff_style_max_width').on('input change', updateLiveStylePreviews);

            // Sync color picker inputs
            function syncColorPicker(pickerId, inputId) {
                $(document).on('input', '#' + pickerId, function() {
                    $('#' + inputId).val($(this).val()).trigger('input');
                });
                $(document).on('input', '#' + inputId, function() {
                    var val = $(this).val();
                    if (val && val.indexOf('#') === 0 && val.length <= 7) {
                        $('#' + pickerId).val(val);
                    }
                });
            }
            syncColorPicker('ff_style_bg_color_picker', 'ff_style_bg_color');
            syncColorPicker('ff_style_label_color_picker', 'ff_style_label_color');
            syncColorPicker('ff_style_input_color_picker', 'ff_style_input_color');
            syncColorPicker('ff_style_btn_bg_color_picker', 'ff_style_btn_bg_color');
            syncColorPicker('ff_style_btn_text_color_picker', 'ff_style_btn_text_color');

            // WP Media Uploader jQuery binding
            $(document).on('click', '.ff-select-font-btn', function(e) {
                e.preventDefault();
                var button = $(this);
                var targetInput = $('#ff_new_font_url');

                var custom_uploader = wp.media({
                    title: 'Select Font File',
                    button: {
                        text: 'Use Selected Font'
                    },
                    multiple: false
                }).on('select', function() {
                    var attachment = custom_uploader.state().get('selection').first().toJSON();
                    targetInput.val(attachment.url).trigger('input');
                }).open();
            });

            // --- Visual Email Customizer Real-Time Preview Scripts ---
            window.gAdminTemplateId     = "<?php echo esc_js($g_template_id); ?>";
            window.gAdminPrimaryColor   = "<?php echo esc_js($g_primary_color); ?>";
            window.gAdminBgColor        = "<?php echo esc_js($g_bg_color); ?>";
            window.gAdminTextColor      = "<?php echo esc_js($g_text_color); ?>";
            window.gAdminHeaderBgColor  = "<?php echo esc_js($g_header_bg_color); ?>";
            window.gAdminCardBgColor    = "<?php echo esc_js($g_card_bg_color); ?>";
            window.gAdminFooterBgColor  = "<?php echo esc_js($g_footer_bg_color); ?>";
            window.gAdminCallout        = "<?php echo esc_js($g_callout); ?>";
            window.gAdminLogo           = "<?php echo esc_js($g_logo); ?>";
            window.gAdminLogoWidth      = parseInt("<?php echo esc_js($g_logo_width); ?>") || 120;
            window.gAdminTitle          = "<?php echo esc_js($g_title); ?>";
            window.gAdminBody           = "<?php echo esc_js($g_body); ?>";
            window.gAdminFooter         = "<?php echo esc_js($g_footer); ?>";

            window.gClientTemplateId     = "<?php echo esc_js($g_client_template_id); ?>";
            window.gClientPrimaryColor   = "<?php echo esc_js($g_client_primary_color); ?>";
            window.gClientBgColor        = "<?php echo esc_js($g_client_bg_color); ?>";
            window.gClientTextColor      = "<?php echo esc_js($g_client_text_color); ?>";
            window.gClientHeaderBgColor  = "<?php echo esc_js($g_client_header_bg_color); ?>";
            window.gClientCardBgColor    = "<?php echo esc_js($g_client_card_bg_color); ?>";
            window.gClientFooterBgColor  = "<?php echo esc_js($g_client_footer_bg_color); ?>";
            window.gClientCallout        = "<?php echo esc_js($g_client_callout); ?>";
            window.gClientLogo           = "<?php echo esc_js($g_client_logo); ?>";
            window.gClientLogoWidth      = parseInt("<?php echo esc_js($g_client_logo_width); ?>") || 120;
            window.gClientTitle          = "<?php echo esc_js($g_client_title); ?>";
            window.gClientBody           = "<?php echo esc_js($g_client_body); ?>";
            window.gClientFooter         = "<?php echo esc_js($g_client_footer); ?>";
            
            var mockSiteName = "<?php echo esc_js(get_bloginfo('name')); ?>";
            var mockSiteUrl = "<?php echo esc_js(get_bloginfo('url')); ?>";

            function getContrastColor(hex) {
                if (!hex) return '#ffffff';
                hex = hex.replace('#', '');
                if (hex.length === 3) {
                    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
                }
                if (hex.length !== 6) return '#ffffff';
                var r = parseInt(hex.substr(0, 2), 16);
                var g = parseInt(hex.substr(2, 2), 16);
                var b = parseInt(hex.substr(4, 2), 16);
                var yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
                return (yiq >= 128) ? '#111827' : '#ffffff';
            }

            function wpautop(text) {
                if (!text) return '';
                return '<p>' + text.trim().replace(/\r?\n\r?\n/g, '</p><p>').replace(/\r?\n/g, '<br>') + '</p>';
            }

            // Shared Template Renderer in JS
            function getTemplateHtml(id, primaryColor, bgColor, textColor, logoHtml, title, body, footer, contrastColor, headerBgColor, cardBgColor, footerBgColor) {
                id = String(id);
                var activeHeaderBg = headerBgColor ? headerBgColor : 'transparent';
                var activeCardBg   = cardBgColor ? cardBgColor : '#ffffff';
                var activeFooterBg = footerBgColor ? footerBgColor : 'transparent';

                switch (id) {
                    case '2':
                        return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: Georgia, serif; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 4px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">' +
                                '<div style="padding: 30px 40px; border-bottom: 2px solid ' + primaryColor + '; background: ' + activeHeaderBg + ';">' +
                                  logoHtml +
                                  '<h2 style="margin: 20px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 24px; font-weight: 600; font-family: sans-serif;">' + title + '</h2>' +
                                '</div>' +
                                '<div style="padding: 40px; font-size: 15px; color: ' + textColor + ';">' +
                                  body +
                                '</div>' +
                                '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#f9fafb') + '; padding: 25px 40px; font-size: 11px; color: #6b7280; font-family: sans-serif; border-top: 1px solid #e5e7eb;">' +
                                  footer +
                                </div>' +
                              '</div>' +
                            '</div>';
                    case '3':
                        return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: monospace; color: #e5e7eb; line-height: 1.6;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + (cardBgColor ? cardBgColor : '#1e222b') + '; border-radius: 8px; overflow: hidden; border: 1px solid #2e3440; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">' +
                                '<div style="background-color: ' + (headerBgColor ? headerBgColor : '#161920') + '; padding: 30px; text-align: center; border-bottom: 2px solid ' + primaryColor + ';">' +
                                  logoHtml +
                                  '<h2 style="margin: 15px 0 0 0; color: ' + (headerBgColor ? contrastColor : primaryColor) + '; font-size: 20px; font-weight: 700; font-family: sans-serif;">' + title + '</h2>' +
                                '</div>' +
                                '<div style="padding: 35px 30px; font-size: 14px; color: #d8dee9; font-family: sans-serif;">' +
                                  body +
                                '</div>' +
                                '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#161920') + '; padding: 25px; text-align: center; font-size: 11px; color: #707880; font-family: sans-serif; border-top: 1px solid #2e3440;">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '4':
                        return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: table; width: 100%;">' +
                                '<div style="display: table-cell; width: 8px; background-color: ' + primaryColor + ';">&nbsp;</div>' +
                                '<div style="display: table-cell; vertical-align: top; padding: 35px 30px 25px 30px; background: ' + activeHeaderBg + ';">' +
                                  '<div style="margin-bottom: 25px;">' + logoHtml + '</div>' +
                                  '<h2 style="margin: 0 0 20px 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                                  '<div style="font-size: 14px; color: ' + textColor + '; margin-bottom: 30px;">' +
                                    body +
                                  '</div>' +
                                  '<div style="padding-top: 20px; border-top: 1px solid #f3f4f6; font-size: 11px; color: #9ca3af; background: ' + activeFooterBg + ';">' +
                                    footer +
                                  '</div>' +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '5':
                        return '<div style="background-color: ' + bgColor + '; padding: 50px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.03); padding: 40px 35px;">' +
                                '<div style="text-align: center; margin-bottom: 30px; background: ' + activeHeaderBg + '; padding: ' + (headerBgColor ? '20px' : '0') + '; border-radius: 12px;">' +
                                  '<div style="display: inline-block; background-color: #fafafa; padding: 15px 25px; border-radius: 16px; border: 1px solid #f0f0f0;">' +
                                    logoHtml +
                                  '</div>' +
                                  '<h2 style="margin: 25px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#0f172a') + '; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                                '</div>' +
                                '<div style="font-size: 15px; color: ' + textColor + '; margin-bottom: 35px;">' +
                                  body +
                                '</div>' +
                                '<div style="text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 25px; background: ' + activeFooterBg + ';">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '6':
                        return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06);">' +
                                '<div style="background-color: ' + (headerBgColor ? headerBgColor : primaryColor) + '; padding: 40px 30px; text-align: center; color: ' + contrastColor + ';">' +
                                  logoHtml +
                                  '<h2 style="margin: 20px 0 0 0; color: ' + contrastColor + '; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;">' + title + '</h2>' +
                                '</div>' +
                                '<div style="padding: 35px 30px; font-size: 14px; color: ' + textColor + ';">' +
                                  body +
                                '</div>' +
                                '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 25px 30px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #f3f4f6;">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '7':
                        return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; border: 2px solid #e5e7eb;">' +
                                '<div style="background-color: ' + (headerBgColor ? headerBgColor : '#f8fafc') + '; padding: 25px 30px; border-bottom: 2px solid ' + primaryColor + '; overflow: hidden;">' +
                                  '<div style="float: left;">' + logoHtml + '</div>' +
                                  '<div style="float: right; margin-top: 8px;"><h3 style="margin: 0; color: ' + (headerBgColor ? contrastColor : '#1e293b') + '; font-size: 16px; font-weight: 700; text-align: right;">' + title + '</h3></div>' +
                                  '<div style="clear: both;"></div>' +
                                '</div>' +
                                '<div style="padding: 30px; font-size: 14px; color: ' + textColor + ';">' +
                                  body +
                                '</div>' +
                                '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#f8fafc') + '; padding: 20px 30px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0;">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '8':
                        return '<div style="background-color: ' + bgColor + '; padding: 45px 20px; font-family: monospace; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 650px; margin: 0 auto; background: ' + activeCardBg + '; border: 2px solid #000000; box-shadow: 6px 6px 0px #000000; padding: 30px;">' +
                                '<div style="border-bottom: 2px solid #000000; padding-bottom: 20px; margin-bottom: 25px; background: ' + activeHeaderBg + ';">' +
                                  '<div style="margin-bottom: 15px;">' + logoHtml + '</div>' +
                                  '<h2 style="margin: 0; color: ' + (headerBgColor ? contrastColor : '#000000') + '; font-size: 20px; font-weight: 700; text-transform: uppercase;">[ ' + title + ' ]</h2>' +
                                '</div>' +
                                '<div style="font-size: 13px; color: ' + textColor + '; margin-bottom: 30px;">' +
                                  body +
                                '</div>' +
                                '<div style="border-top: 2px solid #000000; padding-top: 20px; font-size: 10px; color: #7f8c8d; text-transform: uppercase; background: ' + activeFooterBg + ';">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '9':
                        return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: Georgia, serif; color: ' + textColor + '; line-height: 1.7;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.04); border: 1px solid #f0f0f0;">' +
                                '<div style="background: ' + (headerBgColor ? headerBgColor : 'linear-gradient(135deg, ' + primaryColor + ' 0%, #111827 100%)') + '; padding: 50px 30px; text-align: center; color: ' + contrastColor + ';">' +
                                  '<div style="display: inline-block; margin-bottom: 20px;">' + logoHtml + '</div>' +
                                  '<h2 style="margin: 0; color: ' + contrastColor + '; font-size: 26px; font-weight: 700; font-family: sans-serif; letter-spacing: -0.5px;">' + title + '</h2>' +
                                '</div>' +
                                '<div style="padding: 40px 35px; font-size: 14px; color: ' + textColor + '; font-family: sans-serif;">' +
                                  body +
                                '</div>' +
                                '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 25px 35px; text-align: center; font-size: 11px; color: #9ca3af; font-family: sans-serif; border-top: 1px solid #f3f4f6;">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '10':
                        return '<div style="background-color: ' + bgColor + '; padding: 30px 10px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 550px; margin: 0 auto; background: ' + activeCardBg + '; padding: 10px;">' +
                                '<div style="margin-bottom: 25px; border-bottom: 2px solid ' + primaryColor + '; padding-bottom: 15px; background: ' + activeHeaderBg + ';">' +
                                  logoHtml +
                                  '<h2 style="margin: 10px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 20px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                                '</div>' +
                                '<div style="font-size: 14px; color: ' + textColor + '; margin-bottom: 30px;">' +
                                  body +
                                '</div>' +
                                '<div style="border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 11px; color: #94a3b8; text-align: left; background: ' + activeFooterBg + ';">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                    case '1':
                    default:
                        return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                              '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 6px solid ' + primaryColor + ';">' +
                                '<div style="padding: 30px; text-align: center; border-bottom: 1px solid #f3f4f6; background: ' + activeHeaderBg + ';">' +
                                  logoHtml +
                                  '<h2 style="margin: 15px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 22px; font-weight: 700;">' + title + '</h2>' +
                                '</div>' +
                                '<div style="padding: 30px; font-size: 14px; background: ' + activeCardBg + '; color: ' + textColor + ';">' +
                                  body +
                                '</div>' +
                                '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 20px 30px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb;">' +
                                  footer +
                                '</div>' +
                              '</div>' +
                            '</div>';
                }
            }

            // Handle color picker inherit toggle in form builder
            $(document).on('change', '.ff-color-inherit-toggle', function() {
                var isChecked = $(this).is(':checked');
                var targetId = $(this).data('target');
                var globalVal = $(this).data('global');
                var picker = $('#' + targetId);
                
                if (isChecked) {
                    picker.prop('disabled', true);
                    picker.val(globalVal);
                    picker.next('span').text(globalVal.toUpperCase() + ' (Global)');
                } else {
                    picker.prop('disabled', false);
                    picker.next('span').text(picker.val().toUpperCase());
                }
                
                // Trigger preview update
                if (targetId.indexOf('admin') !== -1) {
                    updateAdminPreview();
                } else {
                    updateClientPreview();
                }
            });

            // Handle callout select toggle in form builder
            $(document).on('change', '#ff_admin_email_callout_select', function() {
                var val = $(this).val();
                var textarea = $('#ff_admin_email_callout');
                if (val === 'custom') {
                    textarea.show();
                    if (textarea.val() === '[none]' || textarea.val() === 'inherit') {
                        textarea.val('');
                    }
                } else if (val === 'none') {
                    textarea.hide().val('[none]');
                } else {
                    textarea.hide().val(''); // Empty string is treated as inherit
                }
                updateAdminPreview();
            });

            $(document).on('change', '#ff_client_email_callout_select', function() {
                var val = $(this).val();
                var textarea = $('#ff_client_email_callout');
                if (val === 'custom') {
                    textarea.show();
                    if (textarea.val() === '[none]' || textarea.val() === 'inherit') {
                        textarea.val('');
                    }
                } else if (val === 'none') {
                    textarea.hide().val('[none]');
                } else {
                    textarea.hide().val(''); // Empty string is treated as inherit
                }
                updateClientPreview();
            });

            // --- ADMIN PREVIEW LOGIC ---
            window.updateAdminPreview = function() {
                var templateId   = $('#ff_admin_email_template_id').val();
                if (templateId === 'inherit') {
                    templateId = window.gAdminTemplateId;
                }
                var primaryColor = $('#ff_admin_email_primary_color_inherit').is(':checked') ? window.gAdminPrimaryColor : $('#ff_admin_email_primary_color').val();
                var bgColor      = $('#ff_admin_email_bg_color_inherit').is(':checked') ? window.gAdminBgColor : $('#ff_admin_email_bg_color').val();
                var textColor    = $('#ff_admin_email_text_color_inherit').is(':checked') ? window.gAdminTextColor : $('#ff_admin_email_text_color').val();
                var headerBgColor  = $('#ff_admin_email_header_bg_color_inherit').is(':checked') ? window.gAdminHeaderBgColor : $('#ff_admin_email_header_bg_color').val();
                var cardBgColor    = $('#ff_admin_email_card_bg_color_inherit').is(':checked') ? window.gAdminCardBgColor : $('#ff_admin_email_card_bg_color').val();
                var footerBgColor  = $('#ff_admin_email_footer_bg_color_inherit').is(':checked') ? window.gAdminFooterBgColor : $('#ff_admin_email_footer_bg_color').val();
                
                var calloutSelect = $('#ff_admin_email_callout_select').val();
                var calloutText = '';
                if (calloutSelect === 'custom') {
                    calloutText = $('#ff_admin_email_callout').val();
                } else if (calloutSelect === 'inherit') {
                    calloutText = window.gAdminCallout;
                }

                var logoUrl      = $('#ff_admin_email_logo').val() || window.gAdminLogo;
                var logoWidth    = parseInt($('#ff_admin_email_logo_width').val()) || window.gAdminLogoWidth;
                var title        = $('#ff_admin_email_title').val() || window.gAdminTitle;
                var body         = $('#ff_admin_email_body').val() || window.gAdminBody;
                var footer       = $('#ff_admin_email_footer').val() || window.gAdminFooter;

                var activeHeaderBg = headerBgColor;
                if (!activeHeaderBg) {
                    if (templateId === '6') activeHeaderBg = primaryColor;
                    else if (templateId === '9') activeHeaderBg = '#111827';
                    else if (templateId === '3') activeHeaderBg = '#161920';
                    else if (templateId === '7') activeHeaderBg = '#f8fafc';
                    else activeHeaderBg = '#ffffff';
                }
                var contrastColor = getContrastColor(activeHeaderBg);

                var logoHtml = '';
                if (logoUrl) {
                    logoHtml = '<img src="' + logoUrl + '" alt="Logo" style="max-width: ' + logoWidth + 'px; height: auto; border: none; display: inline-block; vertical-align: middle;">';
                } else {
                    var headerTextColor = (templateId === '6' || templateId === '9' || headerBgColor) ? contrastColor : textColor;
                    if (templateId === '3' && !headerBgColor) headerTextColor = '#ffffff';
                    logoHtml = '<h2 style="margin: 0; color: ' + headerTextColor + '; font-size: 22px; font-weight: 700; font-family: sans-serif;">' + mockSiteName + '</h2>';
                }

                // Callout alert box preview compiler
                var calloutHtml = '';
                if (calloutText && calloutText !== '[none]') {
                    var r = parseInt(primaryColor.substr(1, 2), 16) || 0;
                    var g = parseInt(primaryColor.substr(3, 2), 16) || 0;
                    var b = parseInt(primaryColor.substr(5, 2), 16) || 0;
                    var calloutBg = 'rgba(' + r + ',' + g + ',' + b + ', 0.05)';
                    calloutHtml = '<div style="border-left: 4px solid ' + primaryColor + '; background-color: ' + calloutBg + '; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; color: ' + textColor + '; text-align: left; font-family: sans-serif;">' + calloutText + '</div>';
                }

                var bodyHtml = wpautop(body) + calloutHtml;
                var footerHtml = footer.replace(/\n/g, '<br>');

                var mockTable = '<table style="width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #e5e7eb; font-size: 14px; font-family: sans-serif;">' +
                    '<tbody>' +
                    '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Name</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">John Doe</td></tr>' +
                    '<tr style="background-color: #f9fafb;"><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Email</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;"><a href="mailto:john.doe@example.com" style="color: ' + primaryColor + '; text-decoration: none; font-weight: 600;">john.doe@example.com</a></td></tr>' +
                    '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Adresse</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">Stephansplatz 1, Vienna</td></tr>' +
                    '</tbody></table>';

                var replacements = {
                    '{name}': 'John Doe',
                    '{email}': 'john.doe@example.com',
                    '{address}': 'Stephansplatz 1, Vienna',
                    '{plz_ort}': '1010 Wien',
                    '{submitted_at}': '2026-06-05 19:15:00',
                    '{site_name}': mockSiteName,
                    '{site_url}': mockSiteUrl,
                    '{year}': '2026',
                    '{all_fields}': mockTable
                };

                function replacePlaceholders(str) {
                    var res = str;
                    for (var key in replacements) {
                        res = res.split(key).join(replacements[key]);
                    }
                    return res;
                }

                var titleResolved  = replacePlaceholders(title);
                var bodyResolved   = replacePlaceholders(bodyHtml);
                var footerResolved = replacePlaceholders(footerHtml);

                var templateHtml = getTemplateHtml(templateId, primaryColor, bgColor, textColor, logoHtml, titleResolved, bodyResolved, footerResolved, contrastColor, headerBgColor, cardBgColor, footerBgColor);

                var iframe = document.getElementById('ff_admin_email_preview_iframe');
                if (iframe) {
                    var doc = iframe.contentWindow.document;
                    doc.open();
                    doc.write(templateHtml);
                    doc.close();
                    doc.body.style.margin = '0';
                    doc.body.style.overflow = 'hidden';
                    setTimeout(function() {
                        var height = doc.documentElement.scrollHeight || doc.body.scrollHeight;
                        iframe.style.height = (height + 15) + 'px';
                    }, 50);
                }
            };

            // --- CLIENT PREVIEW LOGIC ---
            window.updateClientPreview = function() {
                var templateId   = $('#ff_client_email_template_id').val();
                if (templateId === 'inherit') {
                    templateId = window.gClientTemplateId;
                }
                var primaryColor = $('#ff_client_email_primary_color_inherit').is(':checked') ? window.gClientPrimaryColor : $('#ff_client_email_primary_color').val();
                var bgColor      = $('#ff_client_email_bg_color_inherit').is(':checked') ? window.gClientBgColor : $('#ff_client_email_bg_color').val();
                var textColor    = $('#ff_client_email_text_color_inherit').is(':checked') ? window.gClientTextColor : $('#ff_client_email_text_color').val();
                var headerBgColor  = $('#ff_client_email_header_bg_color_inherit').is(':checked') ? window.gClientHeaderBgColor : $('#ff_client_email_header_bg_color').val();
                var cardBgColor    = $('#ff_client_email_card_bg_color_inherit').is(':checked') ? window.gClientCardBgColor : $('#ff_client_email_card_bg_color').val();
                var footerBgColor  = $('#ff_client_email_footer_bg_color_inherit').is(':checked') ? window.gClientFooterBgColor : $('#ff_client_email_footer_bg_color').val();
                
                var calloutSelect = $('#ff_client_email_callout_select').val();
                var calloutText = '';
                if (calloutSelect === 'custom') {
                    calloutText = $('#ff_client_email_callout').val();
                } else if (calloutSelect === 'inherit') {
                    calloutText = window.gClientCallout;
                }

                var logoUrl      = $('#ff_client_email_logo').val() || window.gClientLogo;
                var logoWidth    = parseInt($('#ff_client_email_logo_width').val()) || window.gClientLogoWidth;
                var title        = $('#ff_client_email_title').val() || window.gClientTitle;
                var body         = $('#ff_client_email_body').val() || window.gClientBody;
                var footer       = $('#ff_client_email_footer').val() || window.gClientFooter;

                var activeHeaderBg = headerBgColor;
                if (!activeHeaderBg) {
                    if (templateId === '6') activeHeaderBg = primaryColor;
                    else if (templateId === '9') activeHeaderBg = '#111827';
                    else if (templateId === '3') activeHeaderBg = '#161920';
                    else if (templateId === '7') activeHeaderBg = '#f8fafc';
                    else activeHeaderBg = '#ffffff';
                }
                var contrastColor = getContrastColor(activeHeaderBg);

                var logoHtml = '';
                if (logoUrl) {
                    logoHtml = '<img src="' + logoUrl + '" alt="Logo" style="max-width: ' + logoWidth + 'px; height: auto; border: none; display: inline-block; vertical-align: middle;">';
                } else {
                    var headerTextColor = (templateId === '6' || templateId === '9' || headerBgColor) ? contrastColor : textColor;
                    if (templateId === '3' && !headerBgColor) headerTextColor = '#ffffff';
                    logoHtml = '<h2 style="margin: 0; color: ' + headerTextColor + '; font-size: 22px; font-weight: 700; font-family: sans-serif;">' + mockSiteName + '</h2>';
                }

                // Callout alert box preview compiler
                var calloutHtml = '';
                if (calloutText && calloutText !== '[none]') {
                    var r = parseInt(primaryColor.substr(1, 2), 16) || 0;
                    var g = parseInt(primaryColor.substr(3, 2), 16) || 0;
                    var b = parseInt(primaryColor.substr(5, 2), 16) || 0;
                    var calloutBg = 'rgba(' + r + ',' + g + ',' + b + ', 0.05)';
                    calloutHtml = '<div style="border-left: 4px solid ' + primaryColor + '; background-color: ' + calloutBg + '; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; color: ' + textColor + '; text-align: left; font-family: sans-serif;">' + calloutText + '</div>';
                }

                var bodyHtml = wpautop(body) + calloutHtml;
                var footerHtml = footer.replace(/\n/g, '<br>');

                var mockTable = '<table style="width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #e5e7eb; font-size: 14px; font-family: sans-serif;">' +
                    '<tbody>' +
                    '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Name</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">John Doe</td></tr>' +
                    '<tr style="background-color: #f9fafb;"><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Email</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;"><a href="mailto:john.doe@example.com" style="color: ' + primaryColor + '; text-decoration: none; font-weight: 600;">john.doe@example.com</a></td></tr>' +
                    '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Adresse</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">Stephansplatz 1, Vienna</td></tr>' +
                    '</tbody></table>';

                var replacements = {
                    '{name}': 'John Doe',
                    '{email}': 'john.doe@example.com',
                    '{address}': 'Stephansplatz 1, Vienna',
                    '{plz_ort}': '1010 Wien',
                    '{submitted_at}': '2026-06-05 19:15:00',
                    '{site_name}': mockSiteName,
                    '{site_url}': mockSiteUrl,
                    '{year}': '2026',
                    '{all_fields}': mockTable
                };

                function replacePlaceholders(str) {
                    var res = str;
                    for (var key in replacements) {
                        res = res.split(key).join(replacements[key]);
                    }
                    return res;
                }

                var titleResolved  = replacePlaceholders(title);
                var bodyResolved   = replacePlaceholders(bodyHtml);
                var footerResolved = replacePlaceholders(footerHtml);

                var templateHtml = getTemplateHtml(templateId, primaryColor, bgColor, textColor, logoHtml, titleResolved, bodyResolved, footerResolved, contrastColor, headerBgColor, cardBgColor, footerBgColor);

                var iframe = document.getElementById('ff_client_email_preview_iframe');
                if (iframe) {
                    var doc = iframe.contentWindow.document;
                    doc.open();
                    doc.write(templateHtml);
                    doc.close();
                    doc.body.style.margin = '0';
                    doc.body.style.overflow = 'hidden';
                    setTimeout(function() {
                        var height = doc.documentElement.scrollHeight || doc.body.scrollHeight;
                        iframe.style.height = (height + 15) + 'px';
                    }, 50);
                }
            };

            // Bind update events to customizers
            $('#ff_admin_email_template_id, #ff_admin_email_primary_color, #ff_admin_email_bg_color, #ff_admin_email_header_bg_color, #ff_admin_email_card_bg_color, #ff_admin_email_footer_bg_color, #ff_admin_email_text_color, #ff_admin_email_logo, #ff_admin_email_logo_width, #ff_admin_email_title, #ff_admin_email_body, #ff_admin_email_footer, #ff_admin_email_callout').on('change input keyup', updateAdminPreview);
            $('#ff_client_email_template_id, #ff_client_email_primary_color, #ff_client_email_bg_color, #ff_client_email_header_bg_color, #ff_client_email_card_bg_color, #ff_client_email_footer_bg_color, #ff_client_email_text_color, #ff_client_email_logo, #ff_client_email_logo_width, #ff_client_email_title, #ff_client_email_body, #ff_client_email_footer, #ff_client_email_callout').on('change input keyup', updateClientPreview);

            // Synchronize override color picker labels
            $('.ff-panel-content input[type="color"]').on('change input', function() {
                var label = $(this).val().toUpperCase();
                var textSpan = $(this).next('span');
                textSpan.text(label);
            });

            // WP Media Uploader for builder email logo override
            $(document).on('click', '.ff-upload-logo-btn', function(e) {
                e.preventDefault();
                var button = $(this);
                var targetId = button.data('target');
                var frame = wp.media({
                    title: 'Select Override Email Logo',
                    button: { text: 'Use this image' },
                    multiple: false
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#' + targetId).val(attachment.url).trigger('change');
                });
                frame.open();
            });

            // Run initial update for style preview mapping
            updateLiveStylePreviews();

            // Initial trigger execution
            updateMapsPreview();
        });
        </script>

        <script>
            jQuery(document).ready(function($) {
                var formFields = <?php echo json_encode($fields); ?>;
                
                // Ensure there is always a custom_submit button in formFields so it is fully editable, deletable, and hoverable
                var hasSubmit = formFields.some(function(f) { return f.type === 'custom_submit'; });
                if (!hasSubmit) {
                    formFields.push({
                        id: 'submit_btn',
                        label: 'Register',
                        type: 'custom_submit',
                        placeholder: '',
                        required: false
                    });
                }

                var activeFieldIdx = null;
                var historyLogs = [];

                // Focus on Search Bar on '/' keypress
                $(document).on('keydown', function(e) {
                    if (e.key === '/' && !$(e.target).is('input, textarea, select')) {
                        e.preventDefault();
                        $('#ff_field_search').focus().select();
                    }
                });

                function logAction(msg) {
                    var now = new Date();
                    var timeStr = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'});
                    historyLogs.unshift({ time: timeStr, message: msg });
                    renderHistory();
                }

                function renderHistory() {
                    var historyContainer = $('#ff_history_list');
                    historyContainer.empty();
                    if (historyLogs.length === 0) {
                        historyContainer.append('<div class="ff-history-item" style="color:#8c8f94; font-style:italic;">No history entries yet. Make changes to see logs.</div>');
                        return;
                    }
                    historyLogs.forEach(function(log) {
                        var item = $('<div class="ff-history-item">' +
                            '<span class="ff-history-time">[' + log.time + ']</span>' +
                            '<span>' + escapeHtml(log.message) + '</span>' +
                        '</div>');
                        historyContainer.append(item);
                    });
                }

                $('.ff-tab-btn').on('click', function(e) {
                    e.preventDefault();
                    var tabId = $(this).data('tab');
                    switchSidebarTab(tabId);
                });

                function switchSidebarTab(tabId) {
                    $('.ff-tab-btn').removeClass('active');
                    $('.ff-tab-btn[data-tab="' + tabId + '"]').addClass('active');
                    $('.ff-tab-content').removeClass('active');
                    $('#ff_tab_' + tabId).addClass('active');
                }

                $('.ff-accordion-header').on('click', function(e) {
                    e.preventDefault();
                    var acc = $(this).parent('.ff-accordion');
                    acc.toggleClass('open');
                });

                $('#ff_field_search').on('input', function() {
                    var query = $(this).val().toLowerCase().trim();
                    $('.ff-grid-btn').each(function() {
                        var label = $(this).data('label').toLowerCase();
                        if (label.indexOf(query) !== -1) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                });

                function saveFieldsJSON() {
                    $('#form_fields_json').val(JSON.stringify(formFields));
                }

                // Complex dynamic canvas previews loop
                function renderFieldBuilder() {
                    var canvas = $('#ff_builder_canvas');
                    canvas.empty();

                    if (formFields.length === 0) {
                        canvas.append('<div class="ff-canvas-empty">' +
                            '<span class="dashicons dashicons-layout"></span>' +
                            '<p>Your form is empty.</p>' +
                            '<span>Click or insert field types from the sidebar to start building.</span>' +
                        '</div>');
                        saveFieldsJSON();
                        renderCustomizer();
                        return;
                    }

                    formFields.forEach(function(field, idx) {
                        var insertPointBefore = $('<div class="ff-insert-divider" data-index="' + idx + '">' +
                            '<div class="ff-insert-line"></div>' +
                            '<button type="button" class="ff-insert-btn" title="Insert field here" data-index="' + idx + '"><span class="dashicons dashicons-plus"></span></button>' +
                        '</div>');
                        canvas.append(insertPointBefore);
                        var requiredStar = field.required ? '<span class="ff-canvas-card-required-star" style="color:#d63638; margin-right:4px;">*</span>' : '';
                        var activeClass = (activeFieldIdx === idx) ? 'active' : '';
                        
                        var placeholderText = field.placeholder ? escapeHtml(field.placeholder) : '';
                        var inputHtml = '';
                        
                        // Render tailored previews based on field.type
                        if (field.type === 'textarea') {
                            inputHtml = '<textarea class="ff-canvas-card-preview" rows="2" placeholder="' + placeholderText + '" disabled></textarea>';
                        } else if (field.type === 'select') {
                            inputHtml = '<select class="ff-canvas-card-preview" disabled><option>' + (placeholderText ? placeholderText : '- Select -') + '</option></select>';
                        } else if (field.type === 'ratings') {
                            inputHtml = '<div class="ff-mock-stars">' +
                                '<span class="dashicons dashicons-star-filled"></span>' +
                                '<span class="dashicons dashicons-star-filled"></span>' +
                                '<span class="dashicons dashicons-star-filled"></span>' +
                                '<span class="dashicons dashicons-star-filled"></span>' +
                                '<span class="dashicons dashicons-star-filled"></span>' +
                            '</div>';
                        } else if (field.type === 'password') {
                            inputHtml = '<div style="position:relative;"><input type="password" class="ff-canvas-card-preview" value="********" placeholder="Enter password" disabled><span class="dashicons dashicons-hidden" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#646970; font-size:16px;"></span></div>';
                        } else if (field.type === 'color_picker') {
                            inputHtml = '<div style="display:flex; gap:8px;"><div style="width:24px; height:24px; border:1px solid #ccd0d4; border-radius:4px; background:#78951D; flex-shrink:0;"></div><input type="text" class="ff-canvas-card-preview" value="#78951D" disabled></div>';
                        } else if (field.type === 'nps') {
                            inputHtml = '<div class="ff-mock-nps">' +
                                '<div class="ff-mock-nps-btn">1</div><div class="ff-mock-nps-btn">2</div><div class="ff-mock-nps-btn">3</div><div class="ff-mock-nps-btn">4</div><div class="ff-mock-nps-btn">5</div>' +
                                '<div class="ff-mock-nps-btn">6</div><div class="ff-mock-nps-btn">7</div><div class="ff-mock-nps-btn">8</div><div class="ff-mock-nps-btn">9</div><div class="ff-mock-nps-btn">10</div>' +
                            '</div>';
                        } else if (field.type === 'gdpr' || field.type === 'terms_conditions') {
                            inputHtml = '<div style="display:flex; align-items:center; gap:8px;"><input type="checkbox" checked disabled><span style="font-size:12px; color:#646970;">I agree to the privacy statements, GDPR rules, and terms of service conditions.</span></div>';
                        } else if (field.type === 'section_break') {
                            inputHtml = '<hr style="border:none; border-top:1px dashed #ccd0d4; margin:10px 0;">';
                        } else if (field.type === 'date') {
                            inputHtml = '<div style="position:relative;"><input type="text" class="ff-canvas-card-preview" placeholder="' + (placeholderText ? placeholderText : 'YYYY-MM-DD') + '" disabled><span class="dashicons dashicons-calendar-alt" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#646970; font-size:16px;"></span></div>';
                        } else if (field.type === 'file_upload' || field.type === 'image_upload') {
                            inputHtml = '<div class="ff-canvas-card-preview" style="text-align:center; padding:15px; border:1px dashed #ccd0d4; background:#fafafa; display:flex; flex-direction:column; align-items:center; gap:5px;">' +
                                '<span class="dashicons dashicons-cloud-upload" style="font-size:24px; color:#8c8f94; width:24px; height:24px;"></span>' +
                                '<span>Drag & drop or upload files here</span>' +
                            '</div>';
                        } else if (field.type === 'name_fields') {
                            inputHtml = '<div style="display:flex; gap:12px;"><input type="text" class="ff-canvas-card-preview" style="flex:1;" placeholder="First Name" disabled><input type="text" class="ff-canvas-card-preview" style="flex:1;" placeholder="Last Name" disabled></div>';
                        } else if (field.type === 'address_fields') {
                            inputHtml = '<div style="display:flex; flex-direction:column; gap:8px;">' +
                                '<input type="text" class="ff-canvas-card-preview" placeholder="Street Address" disabled>' +
                                '<div style="display:flex; gap:12px;"><input type="text" class="ff-canvas-card-preview" style="flex:1;" placeholder="City" disabled><input type="text" class="ff-canvas-card-preview" style="flex:1;" placeholder="PLZ / Ort" disabled></div>' +
                            '</div>';
                        } else if (field.type === 'range_slider') {
                            inputHtml = '<div style="display:flex; align-items:center; gap:10px; margin-top:8px;"><span style="font-size:11px; color:#8c8f94;">Min: 0</span><div style="flex:1; height:4px; background:#ccd0d4; position:relative; border-radius:2px;"><div style="position:absolute; left:30%; width:12px; height:12px; border-radius:50%; background:#78951D; top:-4px; box-shadow:0 1px 3px rgba(0,0,0,0.2);"></div></div><span style="font-size:11px; color:#8c8f94;">Max: 100</span></div>';
                        } else if (field.type.indexOf('column_') === 0) {
                            var colsCount = parseInt(field.type.replace('column_', ''), 10);
                            var colsMarkup = '';
                            for (var c = 1; c <= colsCount; c++) {
                                colsMarkup += '<div class="ff-mock-col-cell">Col ' + c + '</div>';
                            }
                            inputHtml = '<div class="ff-mock-columns">' + colsMarkup + '</div>';
                        } else if (field.type === 'recaptcha') {
                            inputHtml = '<div style="background: #ffffff; border: 1px solid #d3d3d3; border-radius: 3px; width: 302px; height: 76px; box-shadow: 0px 0px 4px 1px rgba(0,0,0,0.08); display: flex; align-items: center; justify-content: space-between; padding: 0 12px; font-family: Roboto, helvetica, arial, sans-serif; box-sizing: border-box; color: #2c2c2c; margin-top: 5px;">' +
                                '<div style="display: flex; align-items: center; gap: 10px;">' +
                                    '<div style="width: 24px; height: 24px; border: 2px solid #c1c1c1; border-radius: 2px; background: #fff;"></div>' +
                                    '<span style="font-size: 14px; font-weight: 400; color: #2c2c2c;">I\'m not a robot</span>' +
                                '</div>' +
                                '<div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;">' +
                                    '<img src="https://www.gstatic.com/recaptcha/api2/logo_48.png" style="width: 32px; height: 32px;" alt="">' +
                                    '<span style="font-size: 8px; color: #555555; line-height: 1.1;">reCAPTCHA</span>' +
                                    '<span style="font-size: 8px; color: #555555; line-height: 1.1;"><a href="#" style="text-decoration: none; color: #555; pointer-events: none;">Privacy</a> - <a href="#" style="text-decoration: none; color: #555; pointer-events: none;">Terms</a></span>' +
                                '</div>' +
                            '</div>';
                        } else if (field.type === 'hcaptcha' || field.type === 'turnstile') {
                            var providerText = (field.type === 'hcaptcha') ? 'hCaptcha Protection' : 'Cloudflare Turnstile Badge';
                            inputHtml = '<div class="ff-mock-captcha"><span class="dashicons dashicons-shield"></span><strong>' + providerText + ' Mockup Box</strong></div>';
                        } else if (field.type === 'html') {
                            inputHtml = '<div class="ff-canvas-card-preview" style="font-family:monospace; font-size:11px; background:#fafafa; border:1px solid #ccd0d4; padding:8px; white-space:pre-wrap;">' + (field.options ? escapeHtml(field.options) : '&lt;div class="custom-html"&gt;Type custom HTML code inside the Customizer sidebar drawer.&lt;/div&gt;') + '</div>';
                        } else if (field.type === 'custom_submit') {
                            var btnText = field.label ? field.label : 'Submit';
                            inputHtml = '<div style="padding: 5px 0;"><button type="button" class="ff-form-submit" style="background:#78951D; border-color:#78951D; color:#ffffff; font-weight:600; font-size:14px; padding:10px 20px; border-radius:4px; height:auto; cursor:default; border:none; display:inline-block; margin-top:0;">' + escapeHtml(btnText) + '</button></div>';
                        } else if (field.type === 'shortcode' || field.type === 'action_hook') {
                            var textVal = (field.type === 'shortcode') ? '[shortcode]' : 'Hook: active_action_event';
                            inputHtml = '<div class="ff-canvas-card-preview" style="font-family:monospace; font-size:11px; background:#f0f6fc; color:#78951D; border:1px solid #c8d7e1; padding:6px 10px;">' + textVal + '</div>';
                        } else {
                            inputHtml = '<input type="text" class="ff-canvas-card-preview" placeholder="' + placeholderText + '" disabled>';
                        }
 
                        var showLabelText = (field.type !== 'text' && field.type !== 'email' && field.type !== 'tel' && field.type !== 'textarea' && field.type !== 'custom_submit');
                        var labelTextHtml = showLabelText ? '<span class="ff-canvas-card-label-text">' + escapeHtml(field.label) + '</span>' : '';

                        var card = $('<div class="ff-canvas-card ' + activeClass + '" data-index="' + idx + '">' +
                            '<span class="ff-canvas-card-label">' +
                                requiredStar + labelTextHtml +
                            '</span>' +
                            inputHtml +
                            '<div class="ff-canvas-card-toolbar">' +
                                '<button type="button" class="ff-toolbar-btn move-up" title="Move Up" data-index="' + idx + '"><span class="dashicons dashicons-move"></span></button>' +
                                '<button type="button" class="ff-toolbar-btn move-down" title="Move Down" data-index="' + idx + '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
                                '<button type="button" class="ff-toolbar-btn edit" title="Edit Properties" data-index="' + idx + '"><span class="dashicons dashicons-edit"></span></button>' +
                                '<button type="button" class="ff-toolbar-btn duplicate" title="Duplicate Field" data-index="' + idx + '"><span class="dashicons dashicons-admin-page"></span></button>' +
                                '<button type="button" class="ff-toolbar-btn delete" title="Delete Field" data-index="' + idx + '"><span class="dashicons dashicons-trash"></span></button>' +
                            '</div>' +
                        '</div>');
                        canvas.append(card);
                    });

                    var lastIdx = formFields.length;
                    var insertPointAfter = $('<div class="ff-insert-divider" data-index="' + lastIdx + '">' +
                        '<div class="ff-insert-line"></div>' +
                        '<button type="button" class="ff-insert-btn" title="Insert field here" data-index="' + lastIdx + '"><span class="dashicons dashicons-plus"></span></button>' +
                    '</div>');
                    canvas.append(insertPointAfter);

                    // Only append dynamic Submit mockup at the bottom of the canvas if the user has NOT added an editable custom_submit button card
                    var customSubmitField = formFields.find(function(f) { return f.type === 'custom_submit'; });
                    if (!customSubmitField) {
                        var mockSubmit = $('<div class="ff-mock-submit-wrapper" style="padding:10px 10px; margin-top:15px;">' +
                            '<button type="button" class="ff-form-submit" style="background:#78951D; border-color:#78951D; color:#ffffff; font-weight:600; font-size:14px; padding:10px 20px; border-radius:4px; height:auto; cursor:default; pointer-events:none; border:none; transition:none; text-transform:none; letter-spacing:normal; width:auto; display:inline-block; margin-top:0;">Register</button>' +
                        '</div>');
                        canvas.append(mockSubmit);
                    }

                    canvas.find('.ff-canvas-card:first-of-type .move-up').css('opacity', 0.25).css('cursor', 'not-allowed').prop('disabled', true);
                    canvas.find('.ff-canvas-card:last-of-type .move-down').css('opacity', 0.25).css('cursor', 'not-allowed').prop('disabled', true);

                    saveFieldsJSON();
                    renderCustomizer();
                }

                function renderCustomizer() {
                    var container = $('#ff_customizer_panel');
                    container.empty();

                    if (activeFieldIdx === null || activeFieldIdx >= formFields.length) {
                        container.append('<div class="ff-customizer-placeholder">' +
                            '<span class="dashicons dashicons-edit"></span>' +
                            '<p>Please select an input field on the canvas to configure its settings.</p>' +
                        '</div>');
                        return;
                    }

                    var field = formFields[activeFieldIdx];
                    var reqChecked = field.required ? 'checked' : '';
                    
                    var optionsRowHtml = '';
                    if (field.type === 'select' || field.type === 'radio' || field.type === 'checkbox' || field.type === 'multiple_choice') {
                        var optionsVal = field.options ? field.options : "Option 1\nOption 2\nOption 3";
                        optionsRowHtml = '<div class="ff-customizer-group">' +
                            '<label>Options List (One per line)</label>' +
                            '<textarea class="ff-customizer-options ff-customizer-textarea" rows="4">' + escapeHtml(optionsVal) + '</textarea>' +
                            '<p class="description" style="margin-top:2px;">Type nested selections list.</p>' +
                        '</div>';
                    } else if (field.type === 'html') {
                        var htmlVal = field.options ? field.options : '<div class="custom-html">\n  <h4>Custom Title</h4>\n  <p>Place paragraphs here.</p>\n</div>';
                        optionsRowHtml = '<div class="ff-customizer-group">' +
                            '<label>Custom HTML Code Markup</label>' +
                            '<textarea class="ff-customizer-options ff-customizer-textarea" rows="8" style="font-family:monospace; font-size:11px;">' + escapeHtml(htmlVal) + '</textarea>' +
                        '</div>';
                    }

                    var customizerHtml = '<div class="ff-customizer-form">' +
                        '<h4 style="margin:0 0 5px 0; font-size:13px; color:#78951D; text-transform:uppercase; letter-spacing:0.5px;">Customizing: ' + escapeHtml(field.type.toUpperCase()) + '</h4>' +
                        '<div class="ff-customizer-group">' +
                            '<label>Button Text</label>' +
                            '<input type="text" class="ff-customizer-label ff-customizer-input" value="' + escapeHtml(field.label) + '">' +
                        '</div>' +
                        '<div class="ff-customizer-group">' +
                            '<label>Field Input ID (Unique slug)</label>' +
                            '<input type="text" class="ff-customizer-id ff-customizer-input" value="' + escapeHtml(field.id) + '">' +
                        '</div>' +
                    '</div>';

                    if (field.type !== 'custom_submit') {
                        customizerHtml = '<div class="ff-customizer-form">' +
                            '<h4 style="margin:0 0 5px 0; font-size:13px; color:#78951D; text-transform:uppercase; letter-spacing:0.5px;">Customizing: ' + escapeHtml(field.type.toUpperCase()) + '</h4>' +
                            '<div class="ff-customizer-group">' +
                                '<label>Field Label</label>' +
                                '<input type="text" class="ff-customizer-label ff-customizer-input" value="' + escapeHtml(field.label) + '">' +
                            '</div>' +
                            '<div class="ff-customizer-group">' +
                                '<label>Field Input ID (Unique slug)</label>' +
                                '<input type="text" class="ff-customizer-id ff-customizer-input" value="' + escapeHtml(field.id) + '">' +
                            '</div>' +
                            '<div class="ff-customizer-group">' +
                                '<label>Placeholder Text</label>' +
                                '<input type="text" class="ff-customizer-placeholder ff-customizer-input" value="' + escapeHtml(field.placeholder || '') + '">' +
                            '</div>' +
                            optionsRowHtml +
                            '<div class="ff-customizer-checkbox-row">' +
                                '<input type="checkbox" id="customizer_req" class="ff-customizer-required" ' + reqChecked + '>' +
                                '<label for="customizer_req">Required Field</label>' +
                            '</div>' +
                        '</div>';
                    }
                    var form = $(customizerHtml);
                    
                    container.append(form);
                }

                // Grid append button click
                $('.ff-grid-btn').on('click', function(e) {
                    e.preventDefault();
                    var type = $(this).data('type');
                    var label = $(this).data('label');
                    
                    var uniqueId = type + '_' + Math.random().toString(36).substr(2, 5);
                    var newField = {
                        id: uniqueId,
                        label: type === 'custom_submit' ? 'Register' : label,
                        type: type,
                        placeholder: type === 'custom_submit' ? '' : 'Enter your ' + label.toLowerCase(),
                        required: false
                    };

                    if (type === 'select' || type === 'radio' || type === 'checkbox' || type === 'multiple_choice') {
                        newField.options = "Option 1\nOption 2\nOption 3";
                    }

                    formFields.push(newField);
                    activeFieldIdx = formFields.length - 1;
                    
                    renderFieldBuilder();
                    switchSidebarTab('customizer');
                    logAction('Added new field: ' + label);
                });

                $(document).on('click', '.ff-canvas-card', function(e) {
                    if ($(e.target).closest('.ff-canvas-card-toolbar').length > 0) return;
                    var idx = parseInt($(this).data('index'), 10);
                    activeFieldIdx = idx;
                    $('.ff-canvas-card').removeClass('active');
                    $(this).addClass('active');
                    renderCustomizer();
                    switchSidebarTab('customizer');
                });

                $(document).on('click', '.ff-toolbar-btn.move-up', function(e) {
                    e.preventDefault();
                    var idx = parseInt($(this).data('index'), 10);
                    if (idx > 0) {
                        var temp = formFields[idx];
                        formFields[idx] = formFields[idx - 1];
                        formFields[idx - 1] = temp;
                        activeFieldIdx = (activeFieldIdx === idx) ? idx - 1 : ((activeFieldIdx === idx - 1) ? idx : activeFieldIdx);
                        renderFieldBuilder();
                        logAction('Moved field up: ' + temp.label);
                    }
                });

                $(document).on('click', '.ff-toolbar-btn.move-down', function(e) {
                    e.preventDefault();
                    var idx = parseInt($(this).data('index'), 10);
                    if (idx < formFields.length - 1) {
                        var temp = formFields[idx];
                        formFields[idx] = formFields[idx + 1];
                        formFields[idx + 1] = temp;
                        activeFieldIdx = (activeFieldIdx === idx) ? idx + 1 : ((activeFieldIdx === idx + 1) ? idx : activeFieldIdx);
                        renderFieldBuilder();
                        logAction('Moved field down: ' + temp.label);
                    }
                });

                $(document).on('click', '.ff-toolbar-btn.duplicate', function(e) {
                    e.preventDefault();
                    var idx = parseInt($(this).data('index'), 10);
                    var sourceField = formFields[idx];
                    var clone = $.extend(true, {}, sourceField);
                    clone.id = clone.type + '_' + Math.random().toString(36).substr(2, 5);
                    clone.label = clone.label + ' Copy';
                    
                    formFields.splice(idx + 1, 0, clone);
                    activeFieldIdx = idx + 1;
                    renderFieldBuilder();
                    switchSidebarTab('customizer');
                    logAction('Duplicated field: ' + sourceField.label);
                });

                $(document).on('click', '.ff-toolbar-btn.edit', function(e) {
                    e.preventDefault();
                    var idx = parseInt($(this).data('index'), 10);
                    activeFieldIdx = idx;
                    $('.ff-canvas-card').removeClass('active');
                    $(".ff-canvas-card[data-index='" + idx + "']").addClass('active');
                    renderCustomizer();
                    switchSidebarTab('customizer');
                });

                $(document).on('click', '.ff-toolbar-btn.delete', function(e) {
                    e.preventDefault();
                    var idx = parseInt($(this).data('index'), 10);
                    var label = formFields[idx].label;
                    if (confirm('Are you sure you want to delete the field "' + label + '"?')) {
                        formFields.splice(idx, 1);
                        activeFieldIdx = (activeFieldIdx === idx) ? null : ((activeFieldIdx > idx) ? activeFieldIdx - 1 : activeFieldIdx);
                        renderFieldBuilder();
                        logAction('Deleted field: ' + label);
                    }
                });

                $(document).on('click', '.ff-insert-btn', function(e) {
                    e.preventDefault();
                    var insertIdx = parseInt($(this).data('index'), 10);
                    var uniqueId = 'text_' + Math.random().toString(36).substr(2, 5);
                    var newField = {
                        id: uniqueId,
                        label: 'Simple Text',
                        type: 'text',
                        placeholder: 'Enter text here',
                        required: false
                    };
                    formFields.splice(insertIdx, 0, newField);
                    activeFieldIdx = insertIdx;
                    renderFieldBuilder();
                    switchSidebarTab('customizer');
                    logAction('Inserted field in canvas');
                });

                $(document).on('input', '.ff-customizer-label', function() {
                    var idx = activeFieldIdx;
                    if (idx === null) return;
                    var val = $(this).val();
                    formFields[idx].label = val;
                    if (formFields[idx].type === 'custom_submit') {
                        $(".ff-canvas-card[data-index='" + idx + "'] .ff-form-submit").text(val);
                    } else {
                        $(".ff-canvas-card[data-index='" + idx + "'] .ff-canvas-card-label-text").text(val);
                    }
                    saveFieldsJSON();
                });

                $(document).on('input', '.ff-customizer-placeholder', function() {
                    var idx = activeFieldIdx;
                    if (idx === null) return;
                    var val = $(this).val();
                    formFields[idx].placeholder = val;
                    $(".ff-canvas-card[data-index='" + idx + "'] .ff-canvas-card-preview").attr('placeholder', val);
                    saveFieldsJSON();
                });

                $(document).on('input', '.ff-customizer-id', function() {
                    var idx = activeFieldIdx;
                    if (idx === null) return;
                    var val = $(this).val().toLowerCase().replace(/[^a-z0-9_]/g, '_');
                    $(this).val(val);
                    formFields[idx].id = val;
                    saveFieldsJSON();
                });

                $(document).on('change', '.ff-customizer-required', function() {
                    var idx = activeFieldIdx;
                    if (idx === null) return;
                    var val = $(this).is(':checked');
                    formFields[idx].required = val;
                    if (val) {
                        $(".ff-canvas-card[data-index='" + idx + "'] .ff-canvas-card-required-star").show();
                    } else {
                        $(".ff-canvas-card[data-index='" + idx + "'] .ff-canvas-card-required-star").hide();
                    }
                    saveFieldsJSON();
                });

                $(document).on('input', '.ff-customizer-options', function() {
                    var idx = activeFieldIdx;
                    if (idx === null) return;
                    var val = $(this).val();
                    formFields[idx].options = val;
                    if (formFields[idx].type === 'html') {
                        $(".ff-canvas-card[data-index='" + idx + "'] .ff-canvas-card-preview").text(val);
                    }
                    saveFieldsJSON();
                });

                function escapeHtml(str) {
                    if (!str) return '';
                    return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                }

                renderFieldBuilder();
            });
        </script>
        <?php
        echo '</div>'; // Closes wrap
    }
    
    // VIEW B: Forms list Dashboard View
    else {
        // Query form rows
        $forms = $wpdb->get_results("SELECT * FROM $forms_table ORDER BY id ASC");
        
        // Count entries per form
        $counts_rows = $wpdb->get_results("SELECT form_id, COUNT(*) as count FROM $submissions_table GROUP BY form_id", ARRAY_A);
        $entries_counts = array();
        foreach ($counts_rows as $row) {
            $entries_counts[intval($row['form_id'])] = intval($row['count']);
        }
        
        ff_firebase_admin_page_header('Forms Dashboard Manager', 'forms');
        ?>
        
        <?php if (isset($_GET['deleted'])) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:15px;"><p>Form successfully deleted.</p></div>
        <?php endif; ?>
        
        <!-- Dashboard Toolbar -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:25px; margin-bottom:15px; font-family:-apple-system, BlinkMacSystemFont, sans-serif;">
            <div style="display:flex; gap:10px;">
                <select style="border-radius:4px; font-size:13px; min-height:30px; border-color:#ccd0d4; padding:3px 24px 3px 8px; cursor:pointer;">
                    <option>Active</option>
                    <option>Trash</option>
                </select>
                <a href="<?php echo admin_url('admin.php?page=firebase_forms&action=create'); ?>" class="button button-primary" style="background:#78951D; border-color:#78951D; font-weight:600; min-height:30px; padding:0 14px; border-radius:4px; display:inline-flex; align-items:center; gap:4px; text-decoration:none;">
                    <span class="dashicons dashicons-plus" style="font-size:16px; width:16px; height:16px; display:inline-block; line-height:1;"></span>
                    Add New Form
                </a>
            </div>
            
            <div style="display:flex; gap:8px;">
                <div style="position:relative;">
                    <span class="dashicons dashicons-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#646970; font-size:15px;"></span>
                    <input type="text" id="ff_dash_search" placeholder="Search Forms" style="border-radius:4px; border:1px solid #8c8f94; font-size:13px; padding:4px 8px 4px 28px; width:220px;">
                </div>
                <button type="button" class="button" style="border-color:#ccd0d4; color:#2c3338; border-radius:4px; display:inline-flex; align-items:center; gap:4px; min-height:30px; padding:0 12px;">
                    <span class="dashicons dashicons-filter" style="font-size:16px; width:16px; height:16px; display:inline-block; line-height:1;"></span>
                    Filter
                </button>
            </div>
        </div>
        
        <!-- Forms list Dashboard table layout matching screenshot -->
        <style>
            .ff-dash-table-card {
                background: #2a2a2a;
                border: 1px solid #4a4a4a;
                box-shadow: 0 4px 15px rgba(0,0,0,.15);
                border-radius: 8px;
                overflow: hidden;
                font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            }
            .ff-dash-table {
                width: 100%;
                border-collapse: collapse;
            }
            .ff-dash-table th, .ff-dash-table td {
                padding: 14px 18px;
                text-align: left;
                font-size: 13px;
                border-bottom: 1px solid #4a4a4a;
                color: #ffffff;
            }
            .ff-dash-table th {
                background: #333333;
                font-weight: 700;
                color: #ffffff;
                border-bottom: 2px solid #4a4a4a;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.5px;
            }
            .ff-dash-table tbody tr {
                transition: background 0.15s;
            }
            .ff-dash-table tbody tr:hover {
                background: #333333;
            }
            .ff-row-actions {
                font-size: 11px;
                color: #8c8f94;
                margin-top: 5px;
                opacity: 0;
                transition: opacity 0.15s;
            }
            .ff-dash-table tbody tr:hover .ff-row-actions {
                opacity: 1;
            }
            .ff-row-actions a {
                text-decoration: none;
                font-weight: 600;
                color: #78951D !important;
            }
            .ff-form-title-link {
                color: #78951D !important;
                text-decoration: none !important;
            }
            .ff-form-title-link:hover {
                color: #ffffff !important;
                text-decoration: underline !important;
            }
            .ff-row-actions a.delete {
                color: #ff3333;
            }
            .ff-row-actions a:hover {
                color: #ffffff;
            }
            .ff-row-actions a.delete:hover {
                color: #ff6666;
            }
            .ff-shortcode-tag {
                background: #333333;
                border: 1px solid #4a4a4a;
                padding: 4px 10px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 11px;
                color: #78951D;
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }
            .ff-shortcode-tag .dashicons {
                font-size: 12px;
                width: 12px;
                height: 12px;
                color: #78951D;
            }
        </style>
        
        <div class="ff-dash-table-card">
            <table class="ff-dash-table" id="ff_dash_table">
                <thead>
                    <tr>
                        <th width="8%">ID</th>
                        <th width="42%">Title</th>
                        <th width="35%">ShortCode</th>
                        <th width="15%">Entries</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forms)) : ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:50px; color:#646970;">
                                <span class="dashicons dashicons-layout" style="font-size:36px; width:36px; height:36px; color:#c3c4c7; margin-bottom:8px;"></span>
                                <p style="margin:0; font-weight:600;">No forms found.</p>
                                <p style="margin:5px 0 0 0; font-size:12px;"><a href="<?php echo admin_url('admin.php?page=firebase_forms&action=create'); ?>">Click here to add your first form!</a></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($forms as $f) : ?>
                            <?php 
                            $fid = intval($f->id);
                            $edit_url = admin_url('admin.php?page=firebase_forms&action=edit&id=' . $fid);
                            $delete_url = wp_nonce_url(admin_url('admin.php?page=firebase_forms&action=delete&id=' . $fid), 'delete_form_' . $fid);
                            $entries_url = admin_url('admin.php?page=firebase_submissions&form_id=' . $fid);
                            
                            $form_entries = isset($entries_counts[$fid]) ? $entries_counts[$fid] : 0;
                            ?>
                            <tr data-title="<?php echo esc_attr(strtolower($f->title)); ?>">
                                <td><?php echo $fid; ?></td>
                                <td>
                                    <a href="<?php echo $edit_url; ?>" class="ff-form-title-link" style="text-decoration:none; font-weight:700; font-size:14px;"><?php echo esc_html($f->title); ?></a>
                                    <div class="ff-row-actions">
                                        <a href="<?php echo $edit_url; ?>">Edit</a> | 
                                        <a href="<?php echo $entries_url; ?>">Entries</a> | 
                                        <a href="<?php echo $delete_url; ?>" class="delete" onclick="return confirm('Are you sure you want to delete this form and all its structures?')">Delete</a>
                                    </div>
                                </td>
                                <td>
                                    <span class="ff-shortcode-tag" title="Click to copy shortcode">
                                        <span class="dashicons dashicons-admin-page"></span>
                                        <code>[firebase_contact_form id="<?php echo $fid; ?>"]</code>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo $entries_url; ?>" style="text-decoration:none; font-weight:600; color:#78951D;"><?php echo $form_entries; ?> entries</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Standard WP pagination footer bar mockup matching screenshot -->
        <div style="display:flex; justify-content:flex-end; align-items:center; margin-top:20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; gap:10px; font-size:12px;">
            <div style="color:#646970;">Total <?php echo count($forms); ?></div>
            <select style="border-radius:4px; font-size:12px; border-color:#ccd0d4; padding:3px 24px 3px 8px; cursor:pointer;">
                <option>10/page</option>
                <option>20/page</option>
                <option>50/page</option>
            </select>
            <div style="display:flex; align-items:center; gap:5px;">
                <button type="button" class="button" disabled style="min-height:28px; padding:0 8px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px;"><span class="dashicons dashicons-arrow-left-alt2" style="font-size:14px; width:14px; height:14px; display:inline-block; line-height:1; margin:0;"></span></button>
                <span style="background:#78951D; color:#ffffff; font-weight:600; padding:4px 10px; border-radius:4px; border:1px solid #78951D; line-height:1.2;">1</span>
                <button type="button" class="button" disabled style="min-height:28px; padding:0 8px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px;"><span class="dashicons dashicons-arrow-right-alt2" style="font-size:14px; width:14px; height:14px; display:inline-block; line-height:1; margin:0;"></span></button>
            </div>
            <div style="display:flex; align-items:center; gap:5px; color:#2c3338;">
                <span>Go to</span>
                <input type="text" value="1" style="width:36px; height:28px; text-align:center; border-radius:4px; border:1px solid #8c8f94; padding:0; font-size:12px;" disabled>
            </div>
        </div>
        
        <script>
            jQuery(document).ready(function($) {
                // Search forms instantly in list dashboard
                $('#ff_dash_search').on('input', function() {
                    var query = $(this).val().toLowerCase().trim();
                    $('#ff_dash_table tbody tr').each(function() {
                        var title = $(this).data('title');
                        if (!title) return;
                        if (title.indexOf(query) !== -1) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                });
            });
        </script>
        
        <?php
        ff_firebase_admin_page_footer();
    }
}

function ff_firebase_setup_page() {
    $options = get_option('ff_firebase_settings');
    $project_id = isset($options['project_id']) ? $options['project_id'] : '';
    $api_key = isset($options['api_key']) ? $options['api_key'] : '';
    $collection_name = isset($options['collection_name']) ? $options['collection_name'] : 'submissions';

    ff_firebase_admin_page_header('Firebase Setup', 'firebase');
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Firebase Firestore Setup</h2>
        <p>Configure your Firebase credentials to save submissions to Firestore.</p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="project_id">Firebase Project ID</label></th>
                    <td>
                        <input type="text" id="project_id" name="ff_firebase_settings[project_id]" value="<?php echo esc_attr($project_id); ?>" class="regular-text" placeholder="e.g. engindeniz-3876e">
                        <p class="description">Required. Found in your Firebase Console Settings.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="api_key">Firebase Web API Key</label></th>
                    <td>
                        <input type="text" id="api_key" name="ff_firebase_settings[api_key]" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="e.g. AIzaSyA1...">
                        <p class="description">Optional. Used if Firestore rules require client authentication.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="collection_name">Firestore Collection Name</label></th>
                    <td>
                        <input type="text" id="collection_name" name="ff_firebase_settings[collection_name]" value="<?php echo esc_attr($collection_name); ?>" class="regular-text">
                        <p class="description">Path where documents will be created. Defaults to "submissions".</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
    ff_firebase_admin_page_footer();
}

function ff_firebase_smtp_page() {
    $options = get_option('ff_firebase_settings');
    $smtp_enabled = isset($options['smtp_enabled']) ? $options['smtp_enabled'] : '';
    $smtp_host = isset($options['smtp_host']) ? $options['smtp_host'] : '';
    $smtp_port = isset($options['smtp_port']) ? $options['smtp_port'] : '587';
    $smtp_encryption = isset($options['smtp_encryption']) ? $options['smtp_encryption'] : 'none';
    $smtp_username = isset($options['smtp_username']) ? $options['smtp_username'] : '';
    $smtp_password = isset($options['smtp_password']) ? $options['smtp_password'] : '';
    $smtp_from_email = isset($options['smtp_from_email']) ? $options['smtp_from_email'] : '';
    $smtp_from_name = isset($options['smtp_from_name']) ? $options['smtp_from_name'] : '';
    $last_err = get_option('ff_firebase_last_mail_error');

    ff_firebase_admin_page_header('SMTP Options', 'smtp');
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>SMTP Server Configuration</h2>
        <p>Redirect WordPress outgoing mail through your authenticated SMTP server.</p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="smtp_enabled">Enable Custom SMTP</label></th>
                    <td>
                        <input type="checkbox" id="smtp_enabled" name="ff_firebase_settings[smtp_enabled]" value="yes" <?php checked($smtp_enabled, 'yes'); ?>>
                        <span class="description">Use custom SMTP details instead of default hosting mail</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smtp_host">SMTP Host Server</label></th>
                    <td>
                        <input type="text" id="smtp_host" name="ff_firebase_settings[smtp_host]" value="<?php echo esc_attr($smtp_host); ?>" class="regular-text" placeholder="e.g. smtp.gmail.com">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smtp_port">SMTP Port</label></th>
                    <td>
                        <input type="number" id="smtp_port" name="ff_firebase_settings[smtp_port]" value="<?php echo esc_attr($smtp_port); ?>" class="small-text" placeholder="587">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smtp_encryption">Encryption Security</label></th>
                    <td>
                        <select id="smtp_encryption" name="ff_firebase_settings[smtp_encryption]">
                            <option value="none" <?php selected($smtp_encryption, 'none'); ?>>None</option>
                            <option value="ssl" <?php selected($smtp_encryption, 'ssl'); ?>>SSL (Port 465)</option>
                            <option value="tls" <?php selected($smtp_encryption, 'tls'); ?>>TLS (Port 587)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smtp_username">SMTP Username</label></th>
                    <td>
                        <input type="text" id="smtp_username" name="ff_firebase_settings[smtp_username]" value="<?php echo esc_attr($smtp_username); ?>" class="regular-text" placeholder="e.g. office@domain.com">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smtp_password">SMTP Password</label></th>
                    <td>
                        <input type="password" id="smtp_password" name="ff_firebase_settings[smtp_password]" value="<?php echo esc_attr($smtp_password); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smtp_from_email">From Email Address</label></th>
                    <td>
                        <input type="email" id="smtp_from_email" name="ff_firebase_settings[smtp_from_email]" value="<?php echo esc_attr($smtp_from_email); ?>" class="regular-text" placeholder="e.g. office@domain.com">
                        <p class="description">Envelope headers must match SMTP Username to authenticate on strict hosts.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="smtp_from_name">From Sender Name</label></th>
                    <td>
                        <input type="text" id="smtp_from_name" name="ff_firebase_settings[smtp_from_name]" value="<?php echo esc_attr($smtp_from_name); ?>" class="regular-text" placeholder="e.g. Website Manager">
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Live SMTP Tester -->
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Live SMTP Connection Diagnostics</h2>
        <p>Send a live test email and view the raw connection handshake transaction log.</p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="ff_smtp_test_email">Test Recipient Email</label></th>
                    <td>
                        <input type="email" id="ff_smtp_test_email" placeholder="e.g. test@gmail.com" class="regular-text">
                        <p class="description">The email address to receive the diagnostic test.</p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <p>
            <button type="button" id="ff_smtp_test_btn" class="button button-secondary">Run Diagnostics</button>
        </p>
        
        <div id="ff_smtp_test_results" style="display: none; margin-top: 20px;">
            <div id="ff_smtp_test_status_box" style="padding: 10px; border-left: 4px solid #ffb900; background: #fff8e5; font-weight: 600; margin-bottom: 15px;">
                Diagnostic Result: <span id="ff_smtp_test_status"></span>
            </div>
            <div>
                <strong>Raw SMTP Handshake Log:</strong>
                <pre id="ff_smtp_test_log" style="background: #f6f7f7; border: 1px solid #ccd0d4; padding: 10px; font-family: monospace; font-size: 11px; white-space: pre-wrap; max-height: 300px; overflow-y: auto; margin-top: 5px;"></pre>
            </div>
        </div>
    </div>

    <!-- Last Mail Error Status Card -->
    <?php if (!empty($last_err)) : ?>
        <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #d63638; padding: 20px;">
            <h2 style="color: #d63638; margin-top: 0;">Last Recorded Delivery Error</h2>
            <p>WordPress caught a failed email attempt. Here are the logs for debugging:</p>
            <div style="background: #fff5f5; border: 1px solid #fecaca; padding: 12px; margin-bottom: 15px;">
                <strong>Error Message:</strong> <?php echo esc_html($last_err['message']); ?><br>
                <strong>Timestamp:</strong> <?php echo esc_html($last_err['time']); ?>
            </div>
            <?php if (!empty($last_err['data'])) : ?>
                <pre style="background: #f6f7f7; border: 1px solid #ccd0d4; padding: 10px; font-family: monospace; font-size: 11px;"><?php echo esc_html(print_r($last_err['data'], true)); ?></pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <script>
        jQuery(document).ready(function($) {
            $('#ff_smtp_test_btn').on('click', function(e) {
                e.preventDefault();
                var email = $('#ff_smtp_test_email').val();
                if (!email) { alert('Please enter a test email address.'); return; }
                
                var btn = $(this);
                btn.prop('disabled', true).text('Running diagnostics...');
                
                var resultsDiv = $('#ff_smtp_test_results');
                var statusBox = $('#ff_smtp_test_status_box');
                var statusSpan = $('#ff_smtp_test_status');
                var logPre = $('#ff_smtp_test_log');
                
                resultsDiv.show();
                statusBox.css('border-left-color', '#ffb900').css('background', '#fff8e5').css('color', '#3c3214');
                statusSpan.html('Initiating SMTP socket connection, authenticating and sending...');
                logPre.text('Contacting server and authenticating... This might take up to 10 seconds.');
                
                $.post(ajaxurl, {
                    action: 'ff_firebase_test_smtp',
                    test_email: email,
                    nonce: '<?php echo wp_create_nonce("ff_firebase_test_smtp_nonce"); ?>'
                }, function(response) {
                    btn.prop('disabled', false).text('Run Diagnostics');
                    if (response.success) {
                        statusBox.css('border-left-color', '#00a32a').css('background', '#f0fdf4').css('color', '#14532d');
                        statusSpan.html('✔ Success! SMTP connection negotiated and test email successfully delivered to ' + email);
                    } else {
                        statusBox.css('border-left-color', '#d63638').css('background', '#fcf1f2').css('color', '#842029');
                        statusSpan.html('❌ Failed! ' + (response.data.message || ''));
                    }
                    logPre.text(response.data.log);
                }).fail(function() {
                    btn.prop('disabled', false).text('Run Diagnostics');
                    statusBox.css('border-left-color', '#d63638').css('background', '#fcf1f2').css('color', '#842029');
                    statusSpan.html('❌ Network Request Error. WordPress admin-ajax.php returned a bad response.');
                    logPre.text('Failed to receive response from WordPress Ajax handler.');
                });
            });
        });
    </script>
    <?php
    ff_firebase_admin_page_footer();
}

function ff_firebase_admin_email_page() {
    wp_enqueue_media();
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    $email_enabled = isset($options['email_enabled']) ? $options['email_enabled'] : '';
    $email_to = isset($options['email_to']) ? $options['email_to'] : get_option('admin_email');
    $email_subject = isset($options['email_subject']) ? $options['email_subject'] : 'New Client Registration';

    $template_id     = isset($options['admin_email_template_id']) ? $options['admin_email_template_id'] : '1';
    $primary_color   = isset($options['admin_email_primary_color']) ? $options['admin_email_primary_color'] : '#78951D';
    $bg_color        = isset($options['admin_email_bg_color']) ? $options['admin_email_bg_color'] : '#f3f4f6';
    $text_color      = isset($options['admin_email_text_color']) ? $options['admin_email_text_color'] : '#1f2937';
    $header_bg_color = isset($options['admin_email_header_bg_color']) ? $options['admin_email_header_bg_color'] : '#3a3a3a';
    $card_bg_color   = isset($options['admin_email_card_bg_color']) ? $options['admin_email_card_bg_color'] : '#ffffff';
    $footer_bg_color = isset($options['admin_email_footer_bg_color']) ? $options['admin_email_footer_bg_color'] : '#fafafa';
    $callout         = isset($options['admin_email_callout']) ? $options['admin_email_callout'] : '';
    $logo            = isset($options['admin_email_logo']) ? $options['admin_email_logo'] : '';
    $logo_width      = isset($options['admin_email_logo_width']) ? intval($options['admin_email_logo_width']) : 120;
    $title           = isset($options['admin_email_title']) ? $options['admin_email_title'] : 'Neues Formular empfangen';
    $body            = isset($options['admin_email_body']) ? $options['admin_email_body'] : ff_firebase_get_default_admin_template();
    $footer          = isset($options['admin_email_footer']) ? $options['admin_email_footer'] : '&copy; {year} {site_name}. Alle Rechte vorbehalten.';

    ff_firebase_admin_page_header('Admin Email Settings', 'admin_email');
    ?>
    <div class="ff-preview-layout-container">
        <!-- Left Column: Controls -->
        <div style="min-width: 0; width: 100%;">
            <div class="card" style="margin-top: 0; padding: 20px;">
                <h2>Admin Notification Settings</h2>
                <p class="description">Configure recipients and templates for automated administrative email alerts.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="email_enabled">Enable Admin Notifications</label></th>
                            <td>
                                <input type="checkbox" id="email_enabled" name="ff_firebase_settings[email_enabled]" value="yes" <?php checked($email_enabled, 'yes'); ?>>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_to">Recipient Admin Email</label></th>
                            <td>
                                <input type="email" id="email_to" name="ff_firebase_settings[email_to]" value="<?php echo esc_attr($email_to); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_subject">Email Subject</label></th>
                            <td>
                                <input type="text" id="email_subject" name="ff_firebase_settings[email_subject]" value="<?php echo esc_attr($email_subject); ?>" class="regular-text">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="padding: 20px; margin-top: 20px;">
                <h2>Visual Layout Settings</h2>
                <p class="description">Customize the color theme, logo, and text fields without using code or HTML.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="email_template_id">Choose Template</label></th>
                            <td>
                                <select id="email_template_id" name="ff_firebase_settings[admin_email_template_id]" style="width: 100%;">
                                    <option value="1" <?php selected($template_id, '1'); ?>>Template 1: Modern Centered (Default)</option>
                                    <option value="2" <?php selected($template_id, '2'); ?>>Template 2: Left-Aligned Classic (Corporate)</option>
                                    <option value="3" <?php selected($template_id, '3'); ?>>Template 3: Sleek Dark Mode</option>
                                    <option value="4" <?php selected($template_id, '4'); ?>>Template 4: Accent Sidebar</option>
                                    <option value="5" <?php selected($template_id, '5'); ?>>Template 5: Soft Rounded (Friendly/Modern)</option>
                                    <option value="6" <?php selected($template_id, '6'); ?>>Template 6: Bold Colored Header Block</option>
                                    <option value="7" <?php selected($template_id, '7'); ?>>Template 7: Two-Tone Minimal Card</option>
                                    <option value="8" <?php selected($template_id, '8'); ?>>Template 8: High-Contrast Technical Grid</option>
                                    <option value="9" <?php selected($template_id, '9'); ?>>Template 9: Elegant Hero Banner</option>
                                    <option value="10" <?php selected($template_id, '10'); ?>>Template 10: Clean Compact Strip</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_logo">Select Brand Logo</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" id="email_logo" name="ff_firebase_settings[admin_email_logo]" value="<?php echo esc_attr($logo); ?>" class="regular-text" style="flex: 1;" placeholder="Logo Image URL">
                                    <button type="button" class="button ff-upload-logo-btn" data-target="email_logo">Upload/Select</button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_logo_width">Logo Display Width</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="range" id="email_logo_width_slider" min="50" max="400" step="10" value="<?php echo esc_attr($logo_width); ?>" style="flex: 1;" oninput="document.getElementById('email_logo_width').value = this.value; jQuery('#email_logo_width').trigger('change');">
                                    <input type="number" id="email_logo_width" name="ff_firebase_settings[admin_email_logo_width]" value="<?php echo esc_attr($logo_width); ?>" style="width: 70px;" min="50" max="400" step="10" oninput="document.getElementById('email_logo_width_slider').value = this.value; jQuery(this).trigger('change');"> px
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_primary_color">Theme Accent Color</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_primary_color" name="ff_firebase_settings[admin_email_primary_color]" value="<?php echo esc_attr($primary_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($primary_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_bg_color">Wrapper Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_bg_color" name="ff_firebase_settings[admin_email_bg_color]" value="<?php echo esc_attr($bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_header_bg_color">Header Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_header_bg_color" name="ff_firebase_settings[admin_email_header_bg_color]" value="<?php echo esc_attr($header_bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($header_bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_card_bg_color">Card Container Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_card_bg_color" name="ff_firebase_settings[admin_email_card_bg_color]" value="<?php echo esc_attr($card_bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($card_bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_footer_bg_color">Footer Area Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_footer_bg_color" name="ff_firebase_settings[admin_email_footer_bg_color]" value="<?php echo esc_attr($footer_bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($footer_bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_text_color">Message Text Color</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_text_color" name="ff_firebase_settings[admin_email_text_color]" value="<?php echo esc_attr($text_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($text_color); ?></span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Column 2: Message Details -->
        <div style="min-width: 0; width: 100%;">
            <div class="card" style="padding: 20px; margin-top: 0;">
                <h2>Message Details</h2>
                <p class="description">Define the heading and plain body text. HTML tags are not required.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="email_title">Email Heading/Title</label></th>
                            <td>
                                <input type="text" id="email_title" name="ff_firebase_settings[admin_email_title]" value="<?php echo esc_attr($title); ?>" class="large-text" style="width: 100%;">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_callout">Callout/Alert Box Text</label></th>
                            <td>
                                <input type="text" id="email_callout" name="ff_firebase_settings[admin_email_callout]" value="<?php echo esc_attr($callout); ?>" class="large-text" style="width: 100%;" placeholder="e.g. A new inquiry is awaiting review and follow-up.">
                                <p class="description">Adds a styled notice box with a thick primary left border and light tinted background below the message body.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_body">Email Body Text</label></th>
                            <td>
                                <textarea id="email_body" name="ff_firebase_settings[admin_email_body]" rows="10" class="large-text" style="width: 100%; font-family: sans-serif; font-size: 13px; line-height: 1.5;"><?php echo esc_textarea($body); ?></textarea>
                                <p class="description" style="margin-top: 10px; line-height: 1.5;">
                                    <strong>Placeholders:</strong> <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code>, <code>{all_fields}</code> (beautiful grid table).
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_footer">Email Footer Text</label></th>
                            <td>
                                <input type="text" id="email_footer" name="ff_firebase_settings[admin_email_footer]" value="<?php echo esc_attr($footer); ?>" class="large-text" style="width: 100%;">
                                <p class="description"><strong>Placeholders:</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{year}</code></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Column: Sticky Live Preview -->
        <div style="position: sticky; top: 40px; min-width: 0; width: 100%;">
            <div class="card" style="margin-top: 0; padding: 20px; background: #2a2a2a; border: 1px solid #4a4a4a; border-radius: 8px;">
                <h3 style="margin-top: 0; color: #fff; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-visibility" style="color: #78951D;"></span> Live Email Preview
                </h3>
                <div style="background: #ffffff; border-radius: 6px; overflow: hidden; border: 1px solid #4a4a4a; position: relative; height: auto;">
                    <iframe id="email_preview_iframe" style="width: 100%; height: 580px; border: none; background: #f3f4f6; display: block;"></iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Preview Compiler & Media Uploader Logic -->
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var mockSiteName = "<?php echo esc_js(get_bloginfo('name')); ?>";
        var mockSiteUrl = "<?php echo esc_js(get_bloginfo('url')); ?>";
        
        // WP Media Uploader
        $(document).on('click', '.ff-upload-logo-btn', function(e) {
            e.preventDefault();
            var button = $(this);
            var targetId = button.data('target');
            var frame = wp.media({
                title: 'Select Email Logo',
                button: { text: 'Use this image' },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#' + targetId).val(attachment.url).trigger('change');
            });
            frame.open();
        });

        // Trigger updates when inputs change
        $('input[type="color"]').on('change input', function() {
            $(this).next('span').text($(this).val().toUpperCase());
        });

        function getContrastColor(hex) {
            hex = hex.replace('#', '');
            if (hex.length === 3) {
                hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            }
            if (hex.length !== 6) return '#ffffff';
            var r = parseInt(hex.substr(0, 2), 16);
            var g = parseInt(hex.substr(2, 2), 16);
            var b = parseInt(hex.substr(4, 2), 16);
            var yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
            return (yiq >= 128) ? '#111827' : '#ffffff';
        }

        function wpautop(text) {
            if (!text) return '';
            return '<p>' + text.trim().replace(/\r?\n\r?\n/g, '</p><p>').replace(/\r?\n/g, '<br>') + '</p>';
        }

        function updatePreview() {
            var templateId     = $('#email_template_id').val();
            var primaryColor   = $('#email_primary_color').val();
            var bgColor        = $('#email_bg_color').val();
            var textColor      = $('#email_text_color').val();
            var headerBgColor  = $('#email_header_bg_color').val();
            var cardBgColor    = $('#email_card_bg_color').val();
            var footerBgColor  = $('#email_footer_bg_color').val();
            var calloutText    = $('#email_callout').val();
            var logoUrl        = $('#email_logo').val();
            var logoWidth      = parseInt($('#email_logo_width').val()) || 120;
            var title          = $('#email_title').val();
            var body           = $('#email_body').val();
            var footer         = $('#email_footer').val();

            // Resolve active header background for text contrast calculation
            var activeHeaderBg = headerBgColor;
            if (!activeHeaderBg) {
                if (templateId === '6') activeHeaderBg = primaryColor;
                else if (templateId === '9') activeHeaderBg = '#111827';
                else if (templateId === '3') activeHeaderBg = '#161920';
                else if (templateId === '7') activeHeaderBg = '#f8fafc';
                else activeHeaderBg = '#ffffff';
            }
            var contrastColor = getContrastColor(activeHeaderBg);

            var logoHtml = '';
            if (logoUrl) {
                logoHtml = '<img src="' + logoUrl + '" alt="Logo" style="max-width: ' + logoWidth + 'px; height: auto; border: none; display: inline-block; vertical-align: middle;">';
            } else {
                var headerTextColor = (templateId === '6' || templateId === '9' || headerBgColor) ? contrastColor : textColor;
                if (templateId === '3' && !headerBgColor) headerTextColor = '#ffffff';
                logoHtml = '<h2 style="margin: 0; color: ' + headerTextColor + '; font-size: 22px; font-weight: 700; font-family: sans-serif;">' + mockSiteName + '</h2>';
            }

            // Callout box preview compiler
            var calloutHtml = '';
            if (calloutText) {
                var r = parseInt(primaryColor.substr(1, 2), 16) || 0;
                var g = parseInt(primaryColor.substr(3, 2), 16) || 0;
                var b = parseInt(primaryColor.substr(5, 2), 16) || 0;
                var calloutBg = 'rgba(' + r + ',' + g + ',' + b + ', 0.05)';
                calloutHtml = '<div style="border-left: 4px solid ' + primaryColor + '; background-color: ' + calloutBg + '; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; color: ' + textColor + '; text-align: left; font-family: sans-serif;">' + calloutText + '</div>';
            }

            var bodyHtml = wpautop(body) + calloutHtml;
            var footerHtml = footer.replace(/\n/g, '<br>');

            var mockTable = '<table style="width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #e5e7eb; font-size: 14px; font-family: sans-serif;">' +
                '<tbody>' +
                '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Name</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">John Doe</td></tr>' +
                '<tr style="background-color: #f9fafb;"><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Email</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;"><a href="mailto:john.doe@example.com" style="color: ' + primaryColor + '; text-decoration: none; font-weight: 600;">john.doe@example.com</a></td></tr>' +
                '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Adresse</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">Stephansplatz 1, Vienna</td></tr>' +
                '</tbody></table>';

            var replacements = {
                '{name}': 'John Doe',
                '{email}': 'john.doe@example.com',
                '{address}': 'Stephansplatz 1, Vienna',
                '{plz_ort}': '1010 Wien',
                '{submitted_at}': '2026-06-05 19:15:00',
                '{site_name}': mockSiteName,
                '{site_url}': mockSiteUrl,
                '{year}': '2026',
                '{all_fields}': mockTable
            };

            function replacePlaceholders(str) {
                var res = str;
                for (var key in replacements) {
                    res = res.split(key).join(replacements[key]);
                }
                return res;
            }

            var titleResolved  = replacePlaceholders(title);
            var bodyResolved   = replacePlaceholders(bodyHtml);
            var footerResolved = replacePlaceholders(footerHtml);

            // Access compiled template html from JS templates mirror
            var templateHtml = getTemplateHtml(templateId, primaryColor, bgColor, textColor, logoHtml, titleResolved, bodyResolved, footerResolved, contrastColor, headerBgColor, cardBgColor, footerBgColor);

            var iframe = document.getElementById('email_preview_iframe');
            if (iframe) {
                var doc = iframe.contentWindow.document;
                doc.open();
                doc.write(templateHtml);
                doc.close();
                doc.body.style.margin = '0';
                doc.body.style.overflow = 'hidden';
                setTimeout(function() {
                    var height = doc.documentElement.scrollHeight || doc.body.scrollHeight;
                    iframe.style.height = (height + 15) + 'px';
                }, 50);
            }
        }

        // Shared template compilation
        function getTemplateHtml(id, primaryColor, bgColor, textColor, logoHtml, title, body, footer, contrastColor, headerBgColor, cardBgColor, footerBgColor) {
            id = String(id);
            var activeHeaderBg = headerBgColor ? headerBgColor : 'transparent';
            var activeCardBg   = cardBgColor ? cardBgColor : '#ffffff';
            var activeFooterBg = footerBgColor ? footerBgColor : 'transparent';

            switch (id) {
                case '2':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: Georgia, serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 4px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">' +
                            '<div style="padding: 30px 40px; border-bottom: 2px solid ' + primaryColor + '; background: ' + activeHeaderBg + ';">' +
                              logoHtml +
                              '<h2 style="margin: 20px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 24px; font-weight: 600; font-family: sans-serif;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 40px; font-size: 15px; color: ' + textColor + ';">' +
                              body +
                              '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#f9fafb') + '; padding: 25px 40px; font-size: 11px; color: #6b7280; font-family: sans-serif; border-top: 1px solid #e5e7eb;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '3':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: monospace; color: #e5e7eb; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + (cardBgColor ? cardBgColor : '#1e222b') + '; border-radius: 8px; overflow: hidden; border: 1px solid #2e3440; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">' +
                            '<div style="background-color: ' + (headerBgColor ? headerBgColor : '#161920') + '; padding: 30px; text-align: center; border-bottom: 2px solid ' + primaryColor + ';">' +
                              logoHtml +
                              '<h2 style="margin: 15px 0 0 0; color: ' + (headerBgColor ? contrastColor : primaryColor) + '; font-size: 20px; font-weight: 700; font-family: sans-serif;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 35px 30px; font-size: 14px; color: #d8dee9; font-family: sans-serif;">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#161920') + '; padding: 25px; text-align: center; font-size: 11px; color: #707880; font-family: sans-serif; border-top: 1px solid #2e3440;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '4':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: table; width: 100%;">' +
                            '<div style="display: table-cell; width: 8px; background-color: ' + primaryColor + ';">&nbsp;</div>' +
                            '<div style="display: table-cell; vertical-align: top; padding: 35px 30px 25px 30px; background: ' + activeHeaderBg + ';">' +
                              '<div style="margin-bottom: 25px;">' + logoHtml + '</div>' +
                              '<h2 style="margin: 0 0 20px 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                              '<div style="font-size: 14px; color: ' + textColor + '; margin-bottom: 30px;">' +
                                body +
                              '</div>' +
                              '<div style="padding-top: 20px; border-top: 1px solid #f3f4f6; font-size: 11px; color: #9ca3af; background: ' + activeFooterBg + ';">' +
                                footer +
                              '</div>' +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '5':
                    return '<div style="background-color: ' + bgColor + '; padding: 50px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.03); padding: 40px 35px;">' +
                            '<div style="text-align: center; margin-bottom: 30px; background: ' + activeHeaderBg + '; padding: ' + (headerBgColor ? '20px' : '0') + '; border-radius: 12px;">' +
                              '<div style="display: inline-block; background-color: #fafafa; padding: 15px 25px; border-radius: 16px; border: 1px solid #f0f0f0;">' +
                                logoHtml +
                              '</div>' +
                              '<h2 style="margin: 25px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#0f172a') + '; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="font-size: 15px; color: ' + textColor + '; margin-bottom: 35px;">' +
                              body +
                            '</div>' +
                            '<div style="text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 25px; background: ' + activeFooterBg + ';">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '6':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06);">' +
                            '<div style="background-color: ' + (headerBgColor ? headerBgColor : primaryColor) + '; padding: 40px 30px; text-align: center; color: ' + contrastColor + ';">' +
                              logoHtml +
                              '<h2 style="margin: 20px 0 0 0; color: ' + contrastColor + '; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 35px 30px; font-size: 14px; color: ' + textColor + ';">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 25px 30px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #f3f4f6;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '7':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; border: 2px solid #e5e7eb;">' +
                            '<div style="background-color: ' + (headerBgColor ? headerBgColor : '#f8fafc') + '; padding: 25px 30px; border-bottom: 2px solid ' + primaryColor + '; overflow: hidden;">' +
                              '<div style="float: left;">' + logoHtml + '</div>' +
                              '<div style="float: right; margin-top: 8px;"><h3 style="margin: 0; color: ' + (headerBgColor ? contrastColor : '#1e293b') + '; font-size: 16px; font-weight: 700; text-align: right;">' + title + '</h3></div>' +
                              '<div style="clear: both;"></div>' +
                            '</div>' +
                            '<div style="padding: 30px; font-size: 14px; color: ' + textColor + ';">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#f8fafc') + '; padding: 20px 30px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '8':
                    return '<div style="background-color: ' + bgColor + '; padding: 45px 20px; font-family: monospace; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 650px; margin: 0 auto; background: ' + activeCardBg + '; border: 2px solid #000000; box-shadow: 6px 6px 0px #000000; padding: 30px;">' +
                            '<div style="border-bottom: 2px solid #000000; padding-bottom: 20px; margin-bottom: 25px; background: ' + activeHeaderBg + ';">' +
                              '<div style="margin-bottom: 15px;">' + logoHtml + '</div>' +
                              '<h2 style="margin: 0; color: ' + (headerBgColor ? contrastColor : '#000000') + '; font-size: 20px; font-weight: 700; text-transform: uppercase;">[ ' + title + ' ]</h2>' +
                            '</div>' +
                            '<div style="font-size: 13px; color: ' + textColor + '; margin-bottom: 30px;">' +
                              body +
                            '</div>' +
                            '<div style="border-top: 2px solid #000000; padding-top: 20px; font-size: 10px; color: #7f8c8d; text-transform: uppercase; background: ' + activeFooterBg + ';">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '9':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: Georgia, serif; color: ' + textColor + '; line-height: 1.7;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.04); border: 1px solid #f0f0f0;">' +
                            '<div style="background: ' + (headerBgColor ? headerBgColor : 'linear-gradient(135deg, ' + primaryColor + ' 0%, #111827 100%)') + '; padding: 50px 30px; text-align: center; color: ' + contrastColor + ';">' +
                              '<div style="display: inline-block; margin-bottom: 20px;">' + logoHtml + '</div>' +
                              '<h2 style="margin: 0; color: ' + contrastColor + '; font-size: 26px; font-weight: 700; font-family: sans-serif; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 40px 35px; font-size: 14px; color: ' + textColor + '; font-family: sans-serif;">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 25px 35px; text-align: center; font-size: 11px; color: #9ca3af; font-family: sans-serif; border-top: 1px solid #f3f4f6;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '10':
                    return '<div style="background-color: ' + bgColor + '; padding: 30px 10px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 550px; margin: 0 auto; background: ' + activeCardBg + '; padding: 10px;">' +
                            '<div style="margin-bottom: 25px; border-bottom: 2px solid ' + primaryColor + '; padding-bottom: 15px; background: ' + activeHeaderBg + ';">' +
                              logoHtml +
                              '<h2 style="margin: 10px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 20px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="font-size: 14px; color: ' + textColor + '; margin-bottom: 30px;">' +
                              body +
                            '</div>' +
                            '<div style="border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 11px; color: #94a3b8; text-align: left; background: ' + activeFooterBg + ';">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '1':
                default:
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 6px solid ' + primaryColor + ';">' +
                            '<div style="padding: 30px; text-align: center; border-bottom: 1px solid #f3f4f6; background: ' + activeHeaderBg + ';">' +
                              logoHtml +
                              '<h2 style="margin: 15px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 22px; font-weight: 700;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 30px; font-size: 14px; background: ' + activeCardBg + '; color: ' + textColor + ';">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 20px 30px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
            }
        }

        // Bind events
        $('#email_template_id, #email_primary_color, #email_bg_color, #email_text_color, #email_header_bg_color, #email_card_bg_color, #email_footer_bg_color, #email_callout, #email_logo, #email_logo_width, #email_title, #email_body, #email_footer').on('change input keyup', updatePreview);
        
        // Initial load
        setTimeout(updatePreview, 150);
    });
    </script>
    <?php
    ff_firebase_admin_page_footer();
}

function ff_firebase_client_email_page() {
    wp_enqueue_media();
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    $client_email_enabled = isset($options['client_email_enabled']) ? $options['client_email_enabled'] : '';
    $client_email_subject = isset($options['client_email_subject']) ? $options['client_email_subject'] : 'Vielen Dank für Ihre Registrierung';

    $template_id     = isset($options['client_email_template_id']) ? $options['client_email_template_id'] : '1';
    $primary_color   = isset($options['client_email_primary_color']) ? $options['client_email_primary_color'] : '#78951D';
    $bg_color        = isset($options['client_email_bg_color']) ? $options['client_email_bg_color'] : '#f3f4f6';
    $text_color      = isset($options['client_email_text_color']) ? $options['client_email_text_color'] : '#1f2937';
    $header_bg_color = isset($options['client_email_header_bg_color']) ? $options['client_email_header_bg_color'] : '#3a3a3a';
    $card_bg_color   = isset($options['client_email_card_bg_color']) ? $options['client_email_card_bg_color'] : '#ffffff';
    $footer_bg_color = isset($options['client_email_footer_bg_color']) ? $options['client_email_footer_bg_color'] : '#fafafa';
    $callout         = isset($options['client_email_callout']) ? $options['client_email_callout'] : '';
    $logo            = isset($options['client_email_logo']) ? $options['client_email_logo'] : '';
    $logo_width      = isset($options['client_email_logo_width']) ? intval($options['client_email_logo_width']) : 120;
    $title           = isset($options['client_email_title']) ? $options['client_email_title'] : 'Vielen Dank für Ihre Registrierung';
    $body            = isset($options['client_email_body']) ? $options['client_email_body'] : ff_firebase_get_default_client_template();
    $footer          = isset($options['client_email_footer']) ? $options['client_email_footer'] : 'Mit freundlichen Grüßen,\n{site_name}';

    ff_firebase_admin_page_header('Client Email Settings', 'client_email');
    ?>
    <div class="ff-preview-layout-container">
        <!-- Left Column: Controls -->
        <div style="min-width: 0; width: 100%;">
            <div class="card" style="margin-top: 0; padding: 20px;">
                <h2>Client Thank-You Email</h2>
                <p class="description">Configure recipients and templates for automated client confirmation emails.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="client_email_enabled">Enable Client Thank-You Emails</label></th>
                            <td>
                                <input type="checkbox" id="client_email_enabled" name="ff_firebase_settings[client_email_enabled]" value="yes" <?php checked($client_email_enabled, 'yes'); ?>>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="client_email_subject">Email Subject</label></th>
                            <td>
                                <input type="text" id="client_email_subject" name="ff_firebase_settings[client_email_subject]" value="<?php echo esc_attr($client_email_subject); ?>" class="regular-text" style="width: 100%;">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="padding: 20px; margin-top: 20px;">
                <h2>Visual Layout Settings</h2>
                <p class="description">Customize the color theme, logo, and text fields without using code or HTML.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="email_template_id">Choose Template</label></th>
                            <td>
                                <select id="email_template_id" name="ff_firebase_settings[client_email_template_id]" style="width: 100%;">
                                    <option value="1" <?php selected($template_id, '1'); ?>>Template 1: Modern Centered (Default)</option>
                                    <option value="2" <?php selected($template_id, '2'); ?>>Template 2: Left-Aligned Classic (Corporate)</option>
                                    <option value="3" <?php selected($template_id, '3'); ?>>Template 3: Sleek Dark Mode</option>
                                    <option value="4" <?php selected($template_id, '4'); ?>>Template 4: Accent Sidebar</option>
                                    <option value="5" <?php selected($template_id, '5'); ?>>Template 5: Soft Rounded (Friendly/Modern)</option>
                                    <option value="6" <?php selected($template_id, '6'); ?>>Template 6: Bold Colored Header Block</option>
                                    <option value="7" <?php selected($template_id, '7'); ?>>Template 7: Two-Tone Minimal Card</option>
                                    <option value="8" <?php selected($template_id, '8'); ?>>Template 8: High-Contrast Technical Grid</option>
                                    <option value="9" <?php selected($template_id, '9'); ?>>Template 9: Elegant Hero Banner</option>
                                    <option value="10" <?php selected($template_id, '10'); ?>>Template 10: Clean Compact Strip</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_logo">Select Brand Logo</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" id="email_logo" name="ff_firebase_settings[client_email_logo]" value="<?php echo esc_attr($logo); ?>" class="regular-text" style="flex: 1;" placeholder="Logo Image URL">
                                    <button type="button" class="button ff-upload-logo-btn" data-target="email_logo">Upload/Select</button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_logo_width">Logo Display Width</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="range" id="email_logo_width_slider" min="50" max="400" step="10" value="<?php echo esc_attr($logo_width); ?>" style="flex: 1;" oninput="document.getElementById('email_logo_width').value = this.value; jQuery('#email_logo_width').trigger('change');">
                                    <input type="number" id="email_logo_width" name="ff_firebase_settings[client_email_logo_width]" value="<?php echo esc_attr($logo_width); ?>" style="width: 70px;" min="50" max="400" step="10" oninput="document.getElementById('email_logo_width_slider').value = this.value; jQuery(this).trigger('change');"> px
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_primary_color">Theme Accent Color</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_primary_color" name="ff_firebase_settings[client_email_primary_color]" value="<?php echo esc_attr($primary_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($primary_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_bg_color">Wrapper Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_bg_color" name="ff_firebase_settings[client_email_bg_color]" value="<?php echo esc_attr($bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_header_bg_color">Header Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_header_bg_color" name="ff_firebase_settings[client_email_header_bg_color]" value="<?php echo esc_attr($header_bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($header_bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_card_bg_color">Card Container Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_card_bg_color" name="ff_firebase_settings[client_email_card_bg_color]" value="<?php echo esc_attr($card_bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($card_bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_footer_bg_color">Footer Area Background</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_footer_bg_color" name="ff_firebase_settings[client_email_footer_bg_color]" value="<?php echo esc_attr($footer_bg_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($footer_bg_color); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_text_color">Message Text Color</label></th>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <input type="color" id="email_text_color" name="ff_firebase_settings[client_email_text_color]" value="<?php echo esc_attr($text_color); ?>" style="width: 50px; height: 35px; border: 1px solid #555; padding: 0; cursor: pointer; border-radius: 4px;">
                                    <span style="font-family: monospace;"><?php echo esc_html($text_color); ?></span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Column 2: Message Details -->
        <div style="min-width: 0; width: 100%;">
            <div class="card" style="padding: 20px; margin-top: 0;">
                <h2>Message Details</h2>
                <p class="description">Define the heading and plain body text. HTML tags are not required.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="email_title">Email Heading/Title</label></th>
                            <td>
                                <input type="text" id="email_title" name="ff_firebase_settings[client_email_title]" value="<?php echo esc_attr($title); ?>" class="large-text" style="width: 100%;">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_callout">Callout/Alert Box Text</label></th>
                            <td>
                                <input type="text" id="email_callout" name="ff_firebase_settings[client_email_callout]" value="<?php echo esc_attr($callout); ?>" class="large-text" style="width: 100%;" placeholder="e.g. Vielen Dank für Ihr Vertrauen in unser Team.">
                                <p class="description">Adds a styled notice box with a thick primary left border and light tinted background below the message body.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_body">Email Body Text</label></th>
                            <td>
                                <textarea id="email_body" name="ff_firebase_settings[client_email_body]" rows="10" class="large-text" style="width: 100%; font-family: sans-serif; font-size: 13px; line-height: 1.5;"><?php echo esc_textarea($body); ?></textarea>
                                <p class="description" style="margin-top: 10px; line-height: 1.5;">
                                    <strong>Placeholders:</strong> <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code>, <code>{all_fields}</code> (beautiful grid table).
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email_footer">Email Footer Text</label></th>
                            <td>
                                <input type="text" id="email_footer" name="ff_firebase_settings[client_email_footer]" value="<?php echo esc_attr($footer); ?>" class="large-text" style="width: 100%;">
                                <p class="description"><strong>Placeholders:</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{year}</code></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right Column: Sticky Live Preview -->
        <div style="position: sticky; top: 40px; min-width: 0; width: 100%;">
            <div class="card" style="margin-top: 0; padding: 20px; background: #2a2a2a; border: 1px solid #4a4a4a; border-radius: 8px;">
                <h3 style="margin-top: 0; color: #fff; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-visibility" style="color: #78951D;"></span> Live Email Preview
                </h3>
                <div style="background: #ffffff; border-radius: 6px; overflow: hidden; border: 1px solid #4a4a4a; position: relative; height: auto;">
                    <iframe id="email_preview_iframe" style="width: 100%; height: 580px; border: none; background: #f3f4f6; display: block;"></iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Preview Compiler & Media Uploader Logic -->
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var mockSiteName = "<?php echo esc_js(get_bloginfo('name')); ?>";
        var mockSiteUrl = "<?php echo esc_js(get_bloginfo('url')); ?>";
        
        // WP Media Uploader
        $(document).on('click', '.ff-upload-logo-btn', function(e) {
            e.preventDefault();
            var button = $(this);
            var targetId = button.data('target');
            var frame = wp.media({
                title: 'Select Email Logo',
                button: { text: 'Use this image' },
                multiple: false
            });
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $('#' + targetId).val(attachment.url).trigger('change');
            });
            frame.open();
        });

        // Trigger updates when inputs change
        $('input[type="color"]').on('change input', function() {
            $(this).next('span').text($(this).val().toUpperCase());
        });

        function getContrastColor(hex) {
            hex = hex.replace('#', '');
            if (hex.length === 3) {
                hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            }
            if (hex.length !== 6) return '#ffffff';
            var r = parseInt(hex.substr(0, 2), 16);
            var g = parseInt(hex.substr(2, 2), 16);
            var b = parseInt(hex.substr(4, 2), 16);
            var yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
            return (yiq >= 128) ? '#111827' : '#ffffff';
        }

        function wpautop(text) {
            if (!text) return '';
            return '<p>' + text.trim().replace(/\r?\n\r?\n/g, '</p><p>').replace(/\r?\n/g, '<br>') + '</p>';
        }

        function updatePreview() {
            var templateId     = $('#email_template_id').val();
            var primaryColor   = $('#email_primary_color').val();
            var bgColor        = $('#email_bg_color').val();
            var textColor      = $('#email_text_color').val();
            var headerBgColor  = $('#email_header_bg_color').val();
            var cardBgColor    = $('#email_card_bg_color').val();
            var footerBgColor  = $('#email_footer_bg_color').val();
            var calloutText    = $('#email_callout').val();
            var logoUrl        = $('#email_logo').val();
            var logoWidth      = parseInt($('#email_logo_width').val()) || 120;
            var title          = $('#email_title').val();
            var body           = $('#email_body').val();
            var footer         = $('#email_footer').val();

            // Resolve active header background for text contrast calculation
            var activeHeaderBg = headerBgColor;
            if (!activeHeaderBg) {
                if (templateId === '6') activeHeaderBg = primaryColor;
                else if (templateId === '9') activeHeaderBg = '#111827';
                else if (templateId === '3') activeHeaderBg = '#161920';
                else if (templateId === '7') activeHeaderBg = '#f8fafc';
                else activeHeaderBg = '#ffffff';
            }
            var contrastColor = getContrastColor(activeHeaderBg);

            var logoHtml = '';
            if (logoUrl) {
                logoHtml = '<img src="' + logoUrl + '" alt="Logo" style="max-width: ' + logoWidth + 'px; height: auto; border: none; display: inline-block; vertical-align: middle;">';
            } else {
                var headerTextColor = (templateId === '6' || templateId === '9' || headerBgColor) ? contrastColor : textColor;
                if (templateId === '3' && !headerBgColor) headerTextColor = '#ffffff';
                logoHtml = '<h2 style="margin: 0; color: ' + headerTextColor + '; font-size: 22px; font-weight: 700; font-family: sans-serif;">' + mockSiteName + '</h2>';
            }

            // Callout box preview compiler
            var calloutHtml = '';
            if (calloutText) {
                var r = parseInt(primaryColor.substr(1, 2), 16) || 0;
                var g = parseInt(primaryColor.substr(3, 2), 16) || 0;
                var b = parseInt(primaryColor.substr(5, 2), 16) || 0;
                var calloutBg = 'rgba(' + r + ',' + g + ',' + b + ', 0.05)';
                calloutHtml = '<div style="border-left: 4px solid ' + primaryColor + '; background-color: ' + calloutBg + '; padding: 15px; margin: 20px 0; border-radius: 4px; font-size: 14px; color: ' + textColor + '; text-align: left; font-family: sans-serif;">' + calloutText + '</div>';
            }

            var bodyHtml = wpautop(body) + calloutHtml;
            var footerHtml = footer.replace(/\n/g, '<br>');

            var mockTable = '<table style="width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #e5e7eb; font-size: 14px; font-family: sans-serif;">' +
                '<tbody>' +
                '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Name</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">John Doe</td></tr>' +
                '<tr style="background-color: #f9fafb;"><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Email</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;"><a href="mailto:john.doe@example.com" style="color: ' + primaryColor + '; text-decoration: none; font-weight: 600;">john.doe@example.com</a></td></tr>' +
                '<tr><td style="padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: 600; color: #1f2937; width: 35%;">Adresse</td><td style="padding: 12px 15px; border: 1px solid #e5e7eb; color: #4b5563;">Stephansplatz 1, Vienna</td></tr>' +
                '</tbody></table>';

            var replacements = {
                '{name}': 'John Doe',
                '{email}': 'john.doe@example.com',
                '{address}': 'Stephansplatz 1, Vienna',
                '{plz_ort}': '1010 Wien',
                '{submitted_at}': '2026-06-05 19:15:00',
                '{site_name}': mockSiteName,
                '{site_url}': mockSiteUrl,
                '{year}': '2026',
                '{all_fields}': mockTable
            };

            function replacePlaceholders(str) {
                var res = str;
                for (var key in replacements) {
                    res = res.split(key).join(replacements[key]);
                }
                return res;
            }

            var titleResolved  = replacePlaceholders(title);
            var bodyResolved   = replacePlaceholders(bodyHtml);
            var footerResolved = replacePlaceholders(footerHtml);

            // Access compiled template html from JS templates mirror
            var templateHtml = getTemplateHtml(templateId, primaryColor, bgColor, textColor, logoHtml, titleResolved, bodyResolved, footerResolved, contrastColor, headerBgColor, cardBgColor, footerBgColor);

            var iframe = document.getElementById('email_preview_iframe');
            if (iframe) {
                var doc = iframe.contentWindow.document;
                doc.open();
                doc.write(templateHtml);
                doc.close();
                doc.body.style.margin = '0';
                doc.body.style.overflow = 'hidden';
                setTimeout(function() {
                    var height = doc.documentElement.scrollHeight || doc.body.scrollHeight;
                    iframe.style.height = (height + 15) + 'px';
                }, 50);
            }
        }

        // Shared template compilation
        function getTemplateHtml(id, primaryColor, bgColor, textColor, logoHtml, title, body, footer, contrastColor, headerBgColor, cardBgColor, footerBgColor) {
            id = String(id);
            var activeHeaderBg = headerBgColor ? headerBgColor : 'transparent';
            var activeCardBg   = cardBgColor ? cardBgColor : '#ffffff';
            var activeFooterBg = footerBgColor ? footerBgColor : 'transparent';

            switch (id) {
                case '2':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: Georgia, serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 4px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">' +
                            '<div style="padding: 30px 40px; border-bottom: 2px solid ' + primaryColor + '; background: ' + activeHeaderBg + ';">' +
                              logoHtml +
                              '<h2 style="margin: 20px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 24px; font-weight: 600; font-family: sans-serif;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 40px; font-size: 15px; color: ' + textColor + ';">' +
                              body +
                              '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#f9fafb') + '; padding: 25px 40px; font-size: 11px; color: #6b7280; font-family: sans-serif; border-top: 1px solid #e5e7eb;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '3':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: monospace; color: #e5e7eb; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + (cardBgColor ? cardBgColor : '#1e222b') + '; border-radius: 8px; overflow: hidden; border: 1px solid #2e3440; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">' +
                            '<div style="background-color: ' + (headerBgColor ? headerBgColor : '#161920') + '; padding: 30px; text-align: center; border-bottom: 2px solid ' + primaryColor + ';">' +
                              logoHtml +
                              '<h2 style="margin: 15px 0 0 0; color: ' + (headerBgColor ? contrastColor : primaryColor) + '; font-size: 20px; font-weight: 700; font-family: sans-serif;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 35px 30px; font-size: 14px; color: #d8dee9; font-family: sans-serif;">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#161920') + '; padding: 25px; text-align: center; font-size: 11px; color: #707880; font-family: sans-serif; border-top: 1px solid #2e3440;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '4':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 6px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: table; width: 100%;">' +
                            '<div style="display: table-cell; width: 8px; background-color: ' + primaryColor + ';">&nbsp;</div>' +
                            '<div style="display: table-cell; vertical-align: top; padding: 35px 30px 25px 30px; background: ' + activeHeaderBg + ';">' +
                              '<div style="margin-bottom: 25px;">' + logoHtml + '</div>' +
                              '<h2 style="margin: 0 0 20px 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                              '<div style="font-size: 14px; color: ' + textColor + '; margin-bottom: 30px;">' +
                                body +
                              '</div>' +
                              '<div style="padding-top: 20px; border-top: 1px solid #f3f4f6; font-size: 11px; color: #9ca3af; background: ' + activeFooterBg + ';">' +
                                footer +
                              '</div>' +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '5':
                    return '<div style="background-color: ' + bgColor + '; padding: 50px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.03); padding: 40px 35px;">' +
                            '<div style="text-align: center; margin-bottom: 30px; background: ' + activeHeaderBg + '; padding: ' + (headerBgColor ? '20px' : '0') + '; border-radius: 12px;">' +
                              '<div style="display: inline-block; background-color: #fafafa; padding: 15px 25px; border-radius: 16px; border: 1px solid #f0f0f0;">' +
                                logoHtml +
                              '</div>' +
                              '<h2 style="margin: 25px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#0f172a') + '; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="font-size: 15px; color: ' + textColor + '; margin-bottom: 35px;">' +
                              body +
                            '</div>' +
                            '<div style="text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 25px; background: ' + activeFooterBg + ';">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '6':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06);">' +
                            '<div style="background-color: ' + (headerBgColor ? headerBgColor : primaryColor) + '; padding: 40px 30px; text-align: center; color: ' + contrastColor + ';">' +
                              logoHtml +
                              '<h2 style="margin: 20px 0 0 0; color: ' + contrastColor + '; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 35px 30px; font-size: 14px; color: ' + textColor + ';">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 25px 30px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #f3f4f6;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '7':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; border: 2px solid #e5e7eb;">' +
                            '<div style="background-color: ' + (headerBgColor ? headerBgColor : '#f8fafc') + '; padding: 25px 30px; border-bottom: 2px solid ' + primaryColor + '; overflow: hidden;">' +
                              '<div style="float: left;">' + logoHtml + '</div>' +
                              '<div style="float: right; margin-top: 8px;"><h3 style="margin: 0; color: ' + (headerBgColor ? contrastColor : '#1e293b') + '; font-size: 16px; font-weight: 700; text-align: right;">' + title + '</h3></div>' +
                              '<div style="clear: both;"></div>' +
                            '</div>' +
                            '<div style="padding: 30px; font-size: 14px; color: ' + textColor + ';">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#f8fafc') + '; padding: 20px 30px; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '8':
                    return '<div style="background-color: ' + bgColor + '; padding: 45px 20px; font-family: monospace; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 650px; margin: 0 auto; background: ' + activeCardBg + '; border: 2px solid #000000; box-shadow: 6px 6px 0px #000000; padding: 30px;">' +
                            '<div style="border-bottom: 2px solid #000000; padding-bottom: 20px; margin-bottom: 25px; background: ' + activeHeaderBg + ';">' +
                              '<div style="margin-bottom: 15px;">' + logoHtml + '</div>' +
                              '<h2 style="margin: 0; color: ' + (headerBgColor ? contrastColor : '#000000') + '; font-size: 20px; font-weight: 700; text-transform: uppercase;">[ ' + title + ' ]</h2>' +
                            '</div>' +
                            '<div style="font-size: 13px; color: ' + textColor + '; margin-bottom: 30px;">' +
                              body +
                            '</div>' +
                            '<div style="border-top: 2px solid #000000; padding-top: 20px; font-size: 10px; color: #7f8c8d; text-transform: uppercase; background: ' + activeFooterBg + ';">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '9':
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: Georgia, serif; color: ' + textColor + '; line-height: 1.7;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.04); border: 1px solid #f0f0f0;">' +
                            '<div style="background: ' + (headerBgColor ? headerBgColor : 'linear-gradient(135deg, ' + primaryColor + ' 0%, #111827 100%)') + '; padding: 50px 30px; text-align: center; color: ' + contrastColor + ';">' +
                              '<div style="display: inline-block; margin-bottom: 20px;">' + logoHtml + '</div>' +
                              '<h2 style="margin: 0; color: ' + contrastColor + '; font-size: 26px; font-weight: 700; font-family: sans-serif; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 40px 35px; font-size: 14px; color: ' + textColor + '; font-family: sans-serif;">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 25px 35px; text-align: center; font-size: 11px; color: #9ca3af; font-family: sans-serif; border-top: 1px solid #f3f4f6;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '10':
                    return '<div style="background-color: ' + bgColor + '; padding: 30px 10px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 550px; margin: 0 auto; background: ' + activeCardBg + '; padding: 10px;">' +
                            '<div style="margin-bottom: 25px; border-bottom: 2px solid ' + primaryColor + '; padding-bottom: 15px; background: ' + activeHeaderBg + ';">' +
                              logoHtml +
                              '<h2 style="margin: 10px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 20px; font-weight: 800; letter-spacing: -0.5px;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="font-size: 14px; color: ' + textColor + '; margin-bottom: 30px;">' +
                              body +
                            '</div>' +
                            '<div style="border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 11px; color: #94a3b8; text-align: left; background: ' + activeFooterBg + ';">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
                case '1':
                default:
                    return '<div style="background-color: ' + bgColor + '; padding: 40px 20px; font-family: sans-serif; color: ' + textColor + '; line-height: 1.6;">' +
                          '<div style="max-width: 600px; margin: 0 auto; background: ' + activeCardBg + '; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 6px solid ' + primaryColor + ';">' +
                            '<div style="padding: 30px; text-align: center; border-bottom: 1px solid #f3f4f6; background: ' + activeHeaderBg + ';">' +
                              logoHtml +
                              '<h2 style="margin: 15px 0 0 0; color: ' + (headerBgColor ? contrastColor : '#111827') + '; font-size: 22px; font-weight: 700;">' + title + '</h2>' +
                            '</div>' +
                            '<div style="padding: 30px; font-size: 14px; background: ' + activeCardBg + '; color: ' + textColor + ';">' +
                              body +
                            '</div>' +
                            '<div style="background-color: ' + (footerBgColor ? footerBgColor : '#fafafa') + '; padding: 20px 30px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb;">' +
                              footer +
                            '</div>' +
                          '</div>' +
                        '</div>';
            }
        }

        // Bind events
        $('#email_template_id, #email_primary_color, #email_bg_color, #email_text_color, #email_header_bg_color, #email_card_bg_color, #email_footer_bg_color, #email_callout, #email_logo, #email_logo_width, #email_title, #email_body, #email_footer').on('change input keyup', updatePreview);
        
        // Initial load
        setTimeout(updatePreview, 150);
    });
    </script>
    <?php
    ff_firebase_admin_page_footer();
}

function ff_firebase_google_maps_page() {
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    $maps_api_key = isset($options['maps_api_key']) ? $options['maps_api_key'] : '';

    ff_firebase_admin_page_header('Google Maps Integration', 'google_maps');
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px; border-radius: 4px; border: 1px solid #ccd0d4; background: #ffffff;">
        <h2 style="font-size: 20px; font-weight: 700; color: #1d2327; margin-top: 0; margin-bottom: 5px;">Google Maps Embed API</h2>
        <p class="description" style="font-size: 13px; color: #646970; margin-bottom: 20px; line-height: 1.5;">
            Google Maps Embed API is a free service by Google that allows embedding Google Maps in your site. For more details, visit Google Maps <a href="https://developers.google.com/maps/documentation/embed/get-api-key" target="_blank" style="color: #78951D; text-decoration: none; font-weight: 600;">Using API Keys</a> page.
        </p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row" style="width: 200px;"><label for="maps_api_key" style="font-weight: 600; color: #2c3338;">API Key</label></th>
                    <td>
                        <div style="display: flex; gap: 8px; align-items: center; max-width: 500px;">
                            <input type="password" id="maps_api_key" name="ff_firebase_settings[maps_api_key]" value="<?php echo esc_attr($maps_api_key); ?>" class="regular-text" style="flex: 1; border-radius: 4px; padding: 6px 10px;">
                            <button type="button" class="button" onclick="var x = document.getElementById('maps_api_key'); x.type = x.type === 'password' ? 'text' : 'password';"><span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span></button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
    ff_firebase_admin_page_footer();
}

function ff_firebase_recaptcha_page() {
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    $recaptcha_type = isset($options['recaptcha_type']) ? $options['recaptcha_type'] : 'v2';
    $recaptcha_site_key = isset($options['recaptcha_site_key']) ? $options['recaptcha_site_key'] : '';
    $recaptcha_secret_key = isset($options['recaptcha_secret_key']) ? $options['recaptcha_secret_key'] : '';

    ff_firebase_admin_page_header('Google reCAPTCHA Integration', 'recaptcha');
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px; border-radius: 4px; border: 1px solid #ccd0d4; background: #ffffff;">
        <h2 style="font-size: 20px; font-weight: 700; color: #1d2327; margin-top: 0; margin-bottom: 5px;">Google reCAPTCHA Settings</h2>
        <p class="description" style="font-size: 13px; color: #646970; margin-bottom: 20px; line-height: 1.5;">
            Google reCAPTCHA protects your website from spam and abuse. Configure your keys below. You can get keys from the <a href="https://www.google.com/recaptcha/admin" target="_blank" style="color: #78951D; text-decoration: none; font-weight: 600;">Google reCAPTCHA Admin Console</a>.
        </p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row" style="width: 200px;"><label for="recaptcha_type" style="font-weight: 600; color: #2c3338;">reCAPTCHA Type</label></th>
                    <td>
                        <select id="recaptcha_type" name="ff_firebase_settings[recaptcha_type]" style="border-radius: 4px; padding: 5px 8px; min-width: 200px;">
                            <option value="v2" <?php selected($recaptcha_type, 'v2'); ?>>reCAPTCHA v2 Checkbox ("I'm not a robot")</option>
                            <option value="v3" <?php selected($recaptcha_type, 'v3'); ?>>reCAPTCHA v3 Invisible (Scoring system)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row" style="width: 200px;"><label for="recaptcha_site_key" style="font-weight: 600; color: #2c3338;">Site Key</label></th>
                    <td>
                        <input type="text" id="recaptcha_site_key" name="ff_firebase_settings[recaptcha_site_key]" value="<?php echo esc_attr($recaptcha_site_key); ?>" class="regular-text" style="border-radius: 4px; padding: 6px 10px; width: 100%; max-width: 500px;">
                    </td>
                </tr>
                <tr>
                    <th scope="row" style="width: 200px;"><label for="recaptcha_secret_key" style="font-weight: 600; color: #2c3338;">Secret Key</label></th>
                    <td>
                        <div style="display: flex; gap: 8px; align-items: center; max-width: 500px;">
                            <input type="password" id="recaptcha_secret_key" name="ff_firebase_settings[recaptcha_secret_key]" value="<?php echo esc_attr($recaptcha_secret_key); ?>" class="regular-text" style="flex: 1; border-radius: 4px; padding: 6px 10px;">
                            <button type="button" class="button" onclick="var x = document.getElementById('recaptcha_secret_key'); x.type = x.type === 'password' ? 'text' : 'password';"><span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span></button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
    ff_firebase_admin_page_footer();
}

function ff_firebase_search_replace_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $message = '';
    $results = array();
    $error = '';
    $search = '';
    $replace = '';
    $targets = array('post_content');
    $case_sensitive = false;
    $dry_run = true;
    
    $action = isset($_POST['ff_action']) ? sanitize_text_field($_POST['ff_action']) : '';

    if (!empty($action) && wp_verify_nonce($_POST['ff_search_replace_nonce'], 'ff_search_replace_action')) {
        $search = isset($_POST['ff_search_for']) ? wp_unslash($_POST['ff_search_for']) : '';
        $replace = isset($_POST['ff_replace_with']) ? wp_unslash($_POST['ff_replace_with']) : '';
        $targets = isset($_POST['ff_search_targets']) ? $_POST['ff_search_targets'] : array();
        $case_sensitive = isset($_POST['ff_case_sensitive']) && $_POST['ff_case_sensitive'] === 'yes';
        $dry_run = isset($_POST['ff_dry_run']) && $_POST['ff_dry_run'] === 'yes';

        if (empty($search)) {
            $error = 'Search text cannot be empty.';
        } else {
            global $wpdb;
            $valid_targets = array(
                'post_content' => 'post_content',
                'post_title'   => 'post_title',
                'post_excerpt' => 'post_excerpt'
            );

            $selected_targets = array();
            foreach ($targets as $t) {
                if (isset($valid_targets[$t])) {
                    $selected_targets[] = $valid_targets[$t];
                }
            }

            if (empty($selected_targets)) {
                $error = 'Please select at least one search target.';
            } else {
                $posts_table = $wpdb->posts;
                $sql = "SELECT ID, post_title, post_content, post_excerpt, post_type FROM $posts_table WHERE post_status NOT IN ('inherit', 'trash', 'auto-draft')";
                $all_posts = $wpdb->get_results($sql);

                $total_matches = 0;
                $matching_posts = array();

                foreach ($all_posts as $post) {
                    $post_matches = 0;
                    $updated_fields = array();

                    foreach ($selected_targets as $col) {
                        $content = $post->$col;
                        if (empty($content)) {
                            continue;
                        }

                        if (function_exists('mb_substr_count')) {
                            if ($case_sensitive) {
                                $count = mb_substr_count($content, $search);
                            } else {
                                $count = mb_substr_count(mb_strtolower($content), mb_strtolower($search));
                            }
                        } else {
                            if ($case_sensitive) {
                                $count = substr_count($content, $search);
                            } else {
                                $count = substr_count(strtolower($content), strtolower($search));
                            }
                        }

                        if ($count > 0) {
                            $post_matches += $count;
                            $updated_fields[$col] = $count;
                        }
                    }

                    if ($post_matches > 0) {
                        $total_matches += $post_matches;
                        $matching_posts[] = array(
                            'id'      => $post->ID,
                            'title'   => $post->post_title,
                            'type'    => $post->post_type,
                            'matches' => $post_matches,
                            'fields'  => $updated_fields,
                            'post_obj'=> $post
                        );
                    }
                }

                if ($action === 'replace') {
                    $exec_dry_run = isset($_POST['ff_execution_dry_run']) && $_POST['ff_execution_dry_run'] === 'yes';
                    $selected_post_ids = isset($_POST['ff_replace_post_ids']) ? array_map('intval', $_POST['ff_replace_post_ids']) : array();
                    
                    if (empty($selected_post_ids)) {
                        $error = 'No pages or posts were selected for replacement.';
                    } else {
                        $replaced_count = 0;
                        $affected_posts_count = 0;

                        foreach ($matching_posts as $m_post) {
                            if (!in_array($m_post['id'], $selected_post_ids)) {
                                continue;
                            }

                            $update_data = array();
                            $post_obj = $m_post['post_obj'];

                            foreach ($m_post['fields'] as $col => $cnt) {
                                $content = $post_obj->$col;
                                if ($case_sensitive) {
                                    $new_content = str_replace($search, $replace, $content);
                                } else {
                                    $new_content = str_ireplace($search, $replace, $content);
                                }
                                $update_data[$col] = $new_content;
                            }

                            if (!empty($update_data)) {
                                if (!$exec_dry_run) {
                                    $wpdb->update($posts_table, $update_data, array('ID' => $m_post['id']));
                                }
                                $replaced_count += $m_post['matches'];
                                $affected_posts_count++;
                            }
                        }

                        if ($exec_dry_run) {
                            $message = sprintf('Dry Run Complete: Would replace %d occurrence(s) in %d selected page(s)/post(s). No changes were made to the database.', $replaced_count, $affected_posts_count);
                            ff_firebase_add_log('search_replace', "Dry Run replacement simulated: would replace {$replaced_count} occurrences of '{$search}' with '{$replace}' in {$affected_posts_count} selected posts.", 'info');
                        } else {
                            $message = sprintf('Success: Replaced %d occurrence(s) in %d selected page(s)/post(s).', $replaced_count, $affected_posts_count);
                            ff_firebase_add_log('search_replace', "Live replacement executed: successfully replaced {$replaced_count} occurrences of '{$search}' with '{$replace}' in {$affected_posts_count} selected posts.", 'success');
                            $matching_posts = array(); // Clear results after live update
                        }
                    }
                } else {
                    if ($dry_run) {
                        $message = sprintf('Dry Run Complete: Found %d match(es) across %d page(s)/post(s). No changes were made to the database.', $total_matches, count($matching_posts));
                        ff_firebase_add_log('search_replace', "Search query run (Dry Run Mode): found {$total_matches} occurrences of '{$search}' across " . count($matching_posts) . " posts.", 'info');
                    } else {
                        $message = sprintf('Search Complete: Found %d match(es) across %d page(s)/post(s). Use the table below to select pages for replacement.', $total_matches, count($matching_posts));
                        ff_firebase_add_log('search_replace', "Search query run (Live Ready Mode): found {$total_matches} occurrences of '{$search}' across " . count($matching_posts) . " posts.", 'info');
                    }
                }
            }
        }
    }
    ?>
    <div class="wrap firebase-plugin-wrap">
        <h1>Database Search & Replace</h1>
        <p class="description">Search for text across your WordPress posts, pages, and excerpts, and replace them safely. You can select specific pages to apply the replacement to.</p>

        <?php if (!empty($error)) : ?>
            <div class="notice notice-error"><p><strong>Error:</strong> <?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if (!empty($message)) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <div class="notice notice-warning" style="margin-top: 15px;">
            <p>
                <strong>Warning:</strong> Database write operations are permanent. Please ensure you have a database backup before executing any live replacements.
            </p>
        </div>

        <form action="" method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('ff_search_replace_action', 'ff_search_replace_nonce'); ?>

            <div class="card" style="padding: 20px; margin-bottom: 20px; border-radius: 4px; border: 1px solid #ccd0d4; background: #fff; max-width: 800px;">
                <h2 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Search Parameters</h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ff_search_for">Search For</label></th>
                            <td>
                                <input type="text" id="ff_search_for" name="ff_search_for" value="<?php echo esc_attr($search); ?>" class="regular-text" required placeholder="Text to find...">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_replace_with">Replace With</label></th>
                            <td>
                                <input type="text" id="ff_replace_with" name="ff_replace_with" value="<?php echo esc_attr($replace); ?>" class="regular-text" placeholder="Text to replace with...">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Search Targets</th>
                            <td>
                                <label style="margin-right: 15px; display: inline-block;">
                                    <input type="checkbox" name="ff_search_targets[]" value="post_content" <?php checked(in_array('post_content', $targets)); ?>>
                                    Post / Page Content
                                </label>
                                <label style="margin-right: 15px; display: inline-block;">
                                    <input type="checkbox" name="ff_search_targets[]" value="post_title" <?php checked(in_array('post_title', $targets)); ?>>
                                    Post / Page Title
                                </label>
                                <label style="display: inline-block;">
                                    <input type="checkbox" name="ff_search_targets[]" value="post_excerpt" <?php checked(in_array('post_excerpt', $targets)); ?>>
                                    Post / Page Excerpt
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Options</th>
                            <td>
                                <label style="margin-right: 15px; display: inline-block;">
                                    <input type="checkbox" name="ff_case_sensitive" value="yes" <?php checked($case_sensitive); ?>>
                                    Case-Sensitive Search
                                </label>
                                <label style="display: inline-block;">
                                    <input type="checkbox" name="ff_dry_run" value="yes" <?php checked($dry_run); ?>>
                                    Dry Run (Preview Only)
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit" style="margin-bottom: 0; padding-bottom: 0;">
                    <button type="submit" name="ff_action" value="search" class="button button-primary" style="background: #78951D; border-color: #78951D; color: #fff; text-shadow: none;">Search & Preview Matches</button>
                </p>
            </div>

            <?php if (!empty($action) && empty($error) && !empty($matching_posts)) : ?>
                <div class="card" style="padding: 20px; border-radius: 4px; border: 1px solid #ccd0d4; background: #fff; max-width: 800px; margin-top: 20px; box-sizing: border-box;">
                    <h2 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Select Pages to Apply Replacement</h2>
                    <p class="description">Select the checkbox next to the pages where you want to perform the replacement, then click <strong>"Replace in Selected Pages"</strong>.</p>
                    
                    <div style="margin-top: 15px; margin-bottom: 15px;">
                        <button type="button" class="button button-small" onclick="var checks = document.querySelectorAll('.ff-replace-check'); checks.forEach(c => c.checked = true);">Select All</button>
                        <button type="button" class="button button-small" onclick="var checks = document.querySelectorAll('.ff-replace-check'); checks.forEach(c => c.checked = false);">Deselect All</button>
                    </div>

                    <table class="wp-list-table widefat fixed striped table-view-list" style="margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;"><input type="checkbox" checked onclick="var checks = document.querySelectorAll('.ff-replace-check'); var main = this; checks.forEach(c => c.checked = main.checked);"></th>
                                <th style="width: 80px;">Post ID</th>
                                <th>Title</th>
                                <th style="width: 100px;">Type</th>
                                <th style="width: 120px;">Matches Found</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matching_posts as $res) : ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="ff_replace_post_ids[]" value="<?php echo intval($res['id']); ?>" class="ff-replace-check" checked>
                                    </td>
                                    <td><code><?php echo intval($res['id']); ?></code></td>
                                    <td>
                                        <strong>
                                            <a href="<?php echo get_edit_post_link($res['id']); ?>" target="_blank">
                                                <?php echo esc_html($res['title'] ? $res['title'] : '(No Title)'); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td><span style="background: #e2e8f0; color: #4a5568; font-size: 11px; padding: 2px 6px; border-radius: 4px; font-weight: bold; text-transform: uppercase;"><?php echo esc_html($res['type']); ?></span></td>
                                    <td>
                                        <strong><?php echo intval($res['matches']); ?></strong>
                                        <span style="font-size: 11px; color:#646970; display:block;">
                                            (<?php 
                                                $field_breakdown = array();
                                                foreach ($res['fields'] as $col => $cnt) {
                                                    $col_label = ($col === 'post_content') ? 'Content' : (($col === 'post_title') ? 'Title' : 'Excerpt');
                                                    $field_breakdown[] = "$col_label: $cnt";
                                                }
                                                echo implode(', ', $field_breakdown);
                                            ?>)
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top: 15px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                        <label style="font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; color: #2c3338;">
                            <input type="checkbox" name="ff_execution_dry_run" value="yes" checked>
                            Run as Dry Run (Preview Only - No DB modifications)
                        </label>
                    </div>
                    <p class="submit" style="margin-top: 15px; margin-bottom: 0; padding-bottom: 0;">
                        <button type="submit" name="ff_action" value="replace" class="button button-primary" style="background: #78951D; border-color: #78951D; color: #fff; text-shadow: none;">Replace in Selected Pages</button>
                    </p>
                </div>
            <?php endif; ?>

        </form>
    </div>
    <?php
}

// 4. Register Custom REST API Submission Endpoint
add_action('rest_api_init', function () {
    register_rest_route('firebase-form/v1', '/submit', array(
        'methods'             => 'POST',
        'callback'            => 'ff_firebase_handle_api_submission',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('firebase-form/v1', '/settings', array(
        'methods'             => 'GET',
        'callback'            => 'ff_firebase_get_api_settings',
        'permission_callback' => '__return_true',
    ));
});

function ff_firebase_get_api_settings(WP_REST_Request $request) {
    global $wpdb;
    ff_firebase_ensure_table_exists();

    $form_id = $request->get_param('id') ? intval($request->get_param('id')) : 1;
    $forms_table = $wpdb->prefix . 'firebase_forms';
    
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms_table WHERE id = %d", $form_id));
    $form_settings = array();
    if (!$form) {
        // Fallback to old global options
        $options = get_option('ff_firebase_settings');
        $form_title = isset($options['form_title']) ? $options['form_title'] : 'Registration / Enquiry';
        $fields_json = isset($options['form_fields_json']) ? $options['form_fields_json'] : '';
    } else {
        $form_title = $form->title;
        $fields_json = $form->fields_json;
        if (!empty($form->settings_json)) {
            $form_settings = json_decode(wp_unslash($form->settings_json), true);
        }
    }
    
    if (!is_array($form_settings)) {
        $form_settings = array();
    }
    
    $fields = json_decode(wp_unslash($fields_json), true);
    if (empty($fields)) {
        $fields = ff_firebase_get_default_form_fields();
    }

    // Retrieve global API Key fallback
    $global_settings = get_option('ff_firebase_settings', array());
    $global_maps_key = isset($global_settings['maps_api_key']) ? $global_settings['maps_api_key'] : '';

    $maps_api_key = isset($form_settings['maps_api_key']) ? $form_settings['maps_api_key'] : '';
    if (empty($maps_api_key)) {
        $maps_api_key = $global_maps_key;
    }

    return new WP_REST_Response(array(
        'success'            => true,
        'form_title'         => $form_title,
        'fields'             => $fields,
        'maps_enabled'       => isset($form_settings['maps_enabled']) && $form_settings['maps_enabled'] === 'yes',
        'maps_api_key'       => $maps_api_key,
        'maps_address'       => isset($form_settings['maps_address']) ? $form_settings['maps_address'] : 'Vienna, Austria',
        'maps_zoom'          => isset($form_settings['maps_zoom']) ? intval($form_settings['maps_zoom']) : 14,
        'maps_type'          => isset($form_settings['maps_type']) ? $form_settings['maps_type'] : 'roadmap',
        'maps_height'        => isset($form_settings['maps_height']) ? intval($form_settings['maps_height']) : 400,
        'recaptcha_enabled'  => isset($form_settings['recaptcha_enabled']) && $form_settings['recaptcha_enabled'] === 'yes',
        'recaptcha_type'     => isset($global_settings['recaptcha_type']) ? $global_settings['recaptcha_type'] : 'v2',
        'recaptcha_site_key' => isset($global_settings['recaptcha_site_key']) ? $global_settings['recaptcha_site_key'] : '',
    ), 200);
}

function ff_firebase_handle_api_submission(WP_REST_Request $request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'firebase_form_submissions';
    $forms_table = $wpdb->prefix . 'firebase_forms';

    ff_firebase_ensure_table_exists();

    $params = $request->get_json_params();
    if (empty($params)) {
        $params = $request->get_body_params();
    }

    $form_id = isset($params['form_id']) ? intval($params['form_id']) : 1;

    // Fetch the form fields structure to extract name and email
    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms_table WHERE id = %d", $form_id));

    // Validate reCAPTCHA if enabled or present as a field
    $form_settings = array();
    if ($form && !empty($form->settings_json)) {
        $form_settings = json_decode(wp_unslash($form->settings_json), true);
    }
    if (!is_array($form_settings)) {
        $form_settings = array();
    }

    $fields_decoded = array();
    if ($form) {
        $fields_decoded = json_decode(wp_unslash($form->fields_json), true);
    }
    if (!is_array($fields_decoded)) {
        $fields_decoded = array();
    }

    $recaptcha_field_present = false;
    foreach ($fields_decoded as $field) {
        if (isset($field['type']) && $field['type'] === 'recaptcha') {
            $recaptcha_field_present = true;
            break;
        }
    }

    $recaptcha_enabled = isset($form_settings['recaptcha_enabled']) ? $form_settings['recaptcha_enabled'] : 'no';
    $recaptcha_required = ($recaptcha_enabled === 'yes' || $recaptcha_field_present);

    if ($recaptcha_required) {
        $global_settings = get_option('ff_firebase_settings', array());
        $recaptcha_secret_key = isset($global_settings['recaptcha_secret_key']) ? $global_settings['recaptcha_secret_key'] : '';
        $recaptcha_type = isset($global_settings['recaptcha_type']) ? $global_settings['recaptcha_type'] : 'v2';

        if (empty($recaptcha_secret_key)) {
            return new WP_Error('recaptcha_config_error', 'reCAPTCHA is enabled on this form, but global Secret Key is missing in admin settings.', array('status' => 400));
        }

        $token = isset($params['g-recaptcha-response']) ? sanitize_text_field($params['g-recaptcha-response']) : '';
        if (empty($token)) {
            return new WP_Error('recaptcha_missing', 'reCAPTCHA token is missing. Please complete the reCAPTCHA verification.', array('status' => 400));
        }

        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $verify_response = wp_remote_post($verify_url, array(
            'body' => array(
                'secret'   => $recaptcha_secret_key,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            )
        ));

        if (is_wp_error($verify_response)) {
            return new WP_Error('recaptcha_conn_error', 'Failed to connect to Google reCAPTCHA server.', array('status' => 400));
        }

        $body = json_decode(wp_remote_retrieve_body($verify_response), true);
        if (empty($body['success'])) {
            ff_firebase_add_log('submission', "reCAPTCHA verification failed for form ID {$form_id}.", 'error');
            return new WP_Error('recaptcha_failed', 'reCAPTCHA verification failed. Please try again.', array('status' => 400));
        }

        if ($recaptcha_type === 'v3' && isset($body['score']) && $body['score'] < 0.5) {
            ff_firebase_add_log('submission', "reCAPTCHA verification failed (spam score {$body['score']} too low) for form ID {$form_id}.", 'error');
            return new WP_Error('recaptcha_failed', 'reCAPTCHA verification failed: spam score too low.', array('status' => 400));
        }
    }

    $fields = array();
    if ($form) {
        $fields = json_decode(wp_unslash($form->fields_json), true);
    }
    if (empty($fields)) {
        $fields = ff_firebase_get_default_form_fields();
    }

    // Extract dynamic entries matching core fields for backward compatibility
    $name = '';
    $email = '';
    $address = '';
    $plz_ort = '';

    foreach ($fields as $field) {
        $fid = $field['id'];
        if (isset($params[$fid])) {
            $val = $params[$fid];
            if ($field['type'] === 'email') {
                $email = sanitize_email($val);
            } else if ($field['type'] === 'name_fields' || $fid === 'name' || $fid === 'first_name') {
                if (is_array($val)) {
                    $name = sanitize_text_field(implode(' ', $val));
                } else {
                    $name = sanitize_text_field($val);
                }
            } else if ($field['type'] === 'address_fields' || $fid === 'address') {
                if (is_array($val)) {
                    $address = sanitize_textarea_field(implode(', ', $val));
                } else {
                    $address = sanitize_textarea_field($val);
                }
            } else if ($fid === 'plz_ort' || $fid === 'zip' || $fid === 'city') {
                $plz_ort = sanitize_text_field($val);
            }
        }
    }

    // Fallback automatic email auto-detection if email column is empty
    if (empty($email)) {
        foreach ($params as $k => $v) {
            if (is_string($v) && filter_var(trim($v), FILTER_VALIDATE_EMAIL)) {
                $email = sanitize_email(trim($v));
                break;
            }
        }
    }
    if (empty($name)) {
        $name = isset($params['name']) ? sanitize_text_field($params['name']) : 'Form Submitter';
    }

    // Save to local database
    $insert_result = $wpdb->insert(
        $table_name,
        array(
            'name'         => $name,
            'email'        => $email,
            'address'      => $address,
            'plz_ort'      => $plz_ort,
            'form_id'      => $form_id,
            'form_data'    => json_encode($params),
            'sync_status'  => 'pending',
            'submitted_at' => current_time('mysql', 1)
        )
    );

    if (!$insert_result) {
        ff_firebase_add_log('submission', "Failed to store submission in database from Name: {$name}, Email: {$email}.", 'error');
        return new WP_Error('db_error', 'Failed to store submission in WordPress database.', array('status' => 500));
    }

    $submission_id = $wpdb->insert_id;
    ff_firebase_add_log('submission', "New form submission received from {$name} ({$email}) for form ID {$form_id}. Saved as submission ID {$submission_id}.", 'success');

    // Trigger Firebase sync immediately
    $sync_result = ff_firebase_sync_single_to_firestore($submission_id);

    // Trigger SMTP Email Notification immediately to Admin
    ff_firebase_send_admin_email_notification($submission_id);

    // Trigger SMTP Thank-You Email immediately to Client
    ff_firebase_send_client_thankyou_email($submission_id);

    return new WP_REST_Response(array(
        'success'       => true,
        'message'       => 'Submission stored locally, email sent, and synced to Firebase.',
        'id'            => $submission_id,
        'firebase_sync' => $sync_result
    ), 200);
}

function ff_firebase_sync_single_to_firestore($id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'firebase_form_submissions';

    $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
    if (!$submission) {
        return array('success' => false, 'error' => 'Submission not found.');
    }

    // Load form settings override
    $form_id = isset($submission->form_id) ? intval($submission->form_id) : 1;
    $forms_table = $wpdb->prefix . 'firebase_forms';
    $form_row = $wpdb->get_row($wpdb->prepare("SELECT settings_json FROM $forms_table WHERE id = %d", $form_id));
    $form_settings = array();
    if ($form_row && !empty($form_row->settings_json)) {
        $form_settings = json_decode(wp_unslash($form_row->settings_json), true);
    }
    if (!is_array($form_settings)) {
        $form_settings = array();
    }

    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }

    // Merge overrides with global settings
    $project_id = !empty($form_settings['project_id']) ? sanitize_text_field($form_settings['project_id']) : (!empty($options['project_id']) ? sanitize_text_field($options['project_id']) : '');
    $collection = !empty($form_settings['collection_name']) ? sanitize_text_field($form_settings['collection_name']) : (!empty($options['collection_name']) ? sanitize_text_field($options['collection_name']) : 'submissions');
    $api_key    = !empty($form_settings['api_key']) ? sanitize_text_field($form_settings['api_key']) : (!empty($options['api_key']) ? sanitize_text_field($options['api_key']) : '');

    if (empty($project_id) || empty($collection)) {
        $error_msg = 'Firebase configuration is missing in Settings for this form.';
        $wpdb->update($table_name, array('sync_status' => 'failed', 'sync_error' => $error_msg), array('id' => $id));
        ff_firebase_add_log('firebase_sync', "Submission ID {$id} sync failed: {$error_msg}", 'error');
        return array('success' => false, 'error' => $error_msg);
    }

    // Dynamically build firestore fields based on custom form_data payload
    $formDataJson = isset($submission->form_data) ? $submission->form_data : '';
    $formData = json_decode(wp_unslash($formDataJson), true);
    if (!is_array($formData) || empty($formData)) {
        $formData = array(
            'name'    => $submission->name,
            'email'   => $submission->email,
            'address' => $submission->address,
            'plz_ort' => $submission->plz_ort
        );
    }

    $firestore_fields = array(
        'submitted_at' => array('timestampValue' => gmdate('Y-m-d\TH:i:s\Z', strtotime($submission->submitted_at))),
        'source'       => array('stringValue' => 'WordPress Custom Form Manager')
    );

    foreach ($formData as $k => $v) {
        if ($k === 'form_id') {
            $firestore_fields['form_id'] = array('integerValue' => intval($v));
            continue;
        }
        if (is_array($v)) {
            $v = json_encode($v);
        }
        $firestore_fields[sanitize_key($k)] = array('stringValue' => strval($v));
    }

    $document_data = array(
        'fields' => (object)$firestore_fields
    );

    $url = "https://firestore.googleapis.com/v1/projects/{$project_id}/databases/(default)/documents/{$collection}";
    if (!empty($api_key)) {
        $url .= "?key=" . $api_key;
    }

    $response = wp_remote_post($url, [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body'    => json_encode($document_data),
        'method'  => 'POST',
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        $err = $response->get_error_message();
        $wpdb->update($table_name, array('sync_status' => 'failed', 'sync_error' => $err), array('id' => $id));
        ff_firebase_add_log('firebase_sync', "Submission ID {$id} sync connection error: {$err}.", 'error');
        return array('success' => false, 'error' => $err);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code >= 200 && $code < 300) {
        $res_data = json_decode($body, true);
        $doc_path = isset($res_data['name']) ? $res_data['name'] : '';
        $doc_id   = '';
        if (!empty($doc_path)) {
            $parts  = explode('/', $doc_path);
            $doc_id = end($parts);
        }

        $wpdb->update(
            $table_name,
            array(
                'sync_status'     => 'synced',
                'firebase_doc_id' => $doc_id,
                'sync_error'      => NULL
            ),
            array('id' => $id)
        );
        ff_firebase_add_log('firebase_sync', "Submission ID {$id} successfully synced to Firestore. Document ID: {$doc_id}.", 'success');
        return array('success' => true, 'doc_id' => $doc_id);
    } else {
        $res_data = json_decode($body, true);
        $err_msg  = isset($res_data['error']['message']) ? $res_data['error']['message'] : 'HTTP ' . $code;
        $wpdb->update(
            $table_name,
            array(
                'sync_status' => 'failed',
                'sync_error'  => $err_msg
            ),
            array('id' => $id)
        );
        ff_firebase_add_log('firebase_sync', "Submission ID {$id} failed to sync to Firestore: {$err_msg}.", 'error');
        return array('success' => false, 'error' => $err_msg);
    }
}

// 6. Action: Send Email Notification to ADMIN
function ff_firebase_send_admin_email_notification($id) {
    global $wpdb, $ff_active_form_settings;
    $table_name = $wpdb->prefix . 'firebase_form_submissions';
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
    if (!$sub) {
        return;
    }

    // Load form settings override
    $form_id = isset($sub->form_id) ? intval($sub->form_id) : 1;
    $forms_table = $wpdb->prefix . 'firebase_forms';
    $form_row = $wpdb->get_row($wpdb->prepare("SELECT settings_json FROM $forms_table WHERE id = %d", $form_id));
    $form_settings = array();
    if ($form_row && !empty($form_row->settings_json)) {
        $form_settings = json_decode(wp_unslash($form_row->settings_json), true);
    }
    if (!is_array($form_settings)) {
        $form_settings = array();
    }
    
    // Set active form settings globally for PHPMailer / filters
    $ff_active_form_settings = $form_settings;

    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }

    // Merge overrides with global settings
    // Enabled if explicitly enabled in form, or if form is 'no' (or empty) but enabled globally
    $email_enabled = (isset($form_settings['email_enabled']) && $form_settings['email_enabled'] === 'yes') ||
                     ((empty($form_settings['email_enabled']) || $form_settings['email_enabled'] === 'no') && isset($options['email_enabled']) && $options['email_enabled'] === 'yes');
    if (!$email_enabled) {
        return; // Admin notifications are disabled
    }

    $to      = !empty($form_settings['email_to']) ? sanitize_email($form_settings['email_to']) : (!empty($options['email_to']) ? sanitize_email($options['email_to']) : get_option('admin_email'));
    $subject = !empty($form_settings['email_subject']) ? sanitize_text_field($form_settings['email_subject']) : (!empty($options['email_subject']) ? sanitize_text_field($options['email_subject']) : 'New Client Registration - ENGIN DENIZ');
    
    $body = ff_firebase_render_templated_email('admin', $sub, $form_settings, $options);

    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mail_result = wp_mail($to, $subject, $body, $headers);
    if ($mail_result) {
        ff_firebase_add_log('email', "Admin notification email sent successfully to {$to}.", 'success');
    } else {
        ff_firebase_add_log('email', "Failed to send Admin notification email to {$to}.", 'error');
    }
}

// 7. Action: Send Thank-You Confirmation Email to CLIENT (SMTP Anti-collision delay enabled)
function ff_firebase_send_client_thankyou_email($id) {
    global $wpdb, $ff_active_form_settings;
    $table_name = $wpdb->prefix . 'firebase_form_submissions';
    
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
    if (!$sub) {
        return;
    }

    // Load form settings override
    $form_id = isset($sub->form_id) ? intval($sub->form_id) : 1;
    $forms_table = $wpdb->prefix . 'firebase_forms';
    $form_row = $wpdb->get_row($wpdb->prepare("SELECT settings_json FROM $forms_table WHERE id = %d", $form_id));
    $form_settings = array();
    if ($form_row && !empty($form_row->settings_json)) {
        $form_settings = json_decode(wp_unslash($form_row->settings_json), true);
    }
    if (!is_array($form_settings)) {
        $form_settings = array();
    }
    
    // Set active form settings globally for PHPMailer / filters
    $ff_active_form_settings = $form_settings;

    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }

    // Merge overrides with global settings
    // Enabled if explicitly enabled in form, or if form is 'no' (or empty) but enabled globally
    $client_email_enabled = (isset($form_settings['client_email_enabled']) && $form_settings['client_email_enabled'] === 'yes') ||
                            ((empty($form_settings['client_email_enabled']) || $form_settings['client_email_enabled'] === 'no') && isset($options['client_email_enabled']) && $options['client_email_enabled'] === 'yes');
    if (!$client_email_enabled) {
        return; // Client emails are disabled
    }

    // DYNAMIC AUTOMATIC EMAIL RECIPIENT DETECTION
    $recipient_email = '';
    
    if (!empty($sub->email) && filter_var(trim($sub->email), FILTER_VALIDATE_EMAIL)) {
        $recipient_email = trim($sub->email);
    } else {
        // Fallback: Search all other fields just in case they structured the builder differently
        $search_fields = array($sub->name, $sub->address, $sub->plz_ort);
        foreach ($search_fields as $val) {
            $val = trim($val);
            if (filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $recipient_email = $val;
                break;
            }
        }
    }

    if (empty($recipient_email)) {
        error_log("Fluent Forms to Firebase: Cannot send client thank-you email. No valid email address was detected in the form fields.");
        ff_firebase_add_log('email', "Cannot send client thank-you email. No valid email address was detected.", 'warning');
        return;
    }

    $to      = sanitize_email($recipient_email);
    $subject = !empty($form_settings['client_email_subject']) ? sanitize_text_field($form_settings['client_email_subject']) : (!empty($options['client_email_subject']) ? sanitize_text_field($options['client_email_subject']) : 'Vielen Dank für Ihre Registrierung - ENGIN DENIZ');
    
    $body = ff_firebase_render_templated_email('client', $sub, $form_settings, $options);

    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Add a small 1-second delay to prevent SMTP socket collision / rate limiting
    sleep(1);

    $mail_result = wp_mail($to, $subject, $body, $headers);

    if (!$mail_result) {
        error_log("Fluent Forms to Firebase: SMTP Thank-You email failed to send to client: " . $to);
        ff_firebase_add_log('email', "Failed to send Client thank-you email to {$to}.", 'error');
    } else {
        error_log("Fluent Forms to Firebase: SMTP Thank-You email successfully sent to client: " . $to);
        ff_firebase_add_log('email', "Client thank-you email sent successfully to {$to}.", 'success');
    }
}

// 8. Register Shortcode [firebase_contact_form] to render beautiful, DYNAMIC field form anywhere in WP
add_shortcode('firebase_contact_form', 'ff_firebase_contact_form_shortcode');

function ff_firebase_contact_form_shortcode($atts) {
    global $wpdb;
    ff_firebase_ensure_table_exists();

    $atts = shortcode_atts(array(
        'id' => 1,
    ), $atts, 'firebase_contact_form');

    $form_id_num = intval($atts['id']);
    $forms_table = $wpdb->prefix . 'firebase_forms';

    $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms_table WHERE id = %d", $form_id_num));
    if (!$form) {
        // Fallback to old global options
        $options = get_option('ff_firebase_settings');
        $form_title = isset($options['form_title']) ? esc_html($options['form_title']) : 'Registration / Enquiry';
        $fields_json = isset($options['form_fields_json']) ? $options['form_fields_json'] : '';
    } else {
        $form_title = esc_html($form->title);
        $fields_json = $form->fields_json;
    }

    $fields = json_decode(wp_unslash($fields_json), true);
    if (empty($fields)) {
        $fields = ff_firebase_get_default_form_fields();
    }

    $form_settings = array();
    if ($form && !empty($form->settings_json)) {
        $form_settings = json_decode(wp_unslash($form->settings_json), true);
    }
    if (!is_array($form_settings)) {
        $form_settings = array();
    }

    $global_settings = get_option('ff_firebase_settings', array());
    $recaptcha_type = isset($global_settings['recaptcha_type']) ? $global_settings['recaptcha_type'] : 'v2';
    $recaptcha_site_key = isset($global_settings['recaptcha_site_key']) ? $global_settings['recaptcha_site_key'] : '';
    $recaptcha_enabled = isset($form_settings['recaptcha_enabled']) ? $form_settings['recaptcha_enabled'] : 'no';

    $recaptcha_field_present = false;
    foreach ($fields as $field) {
        if (isset($field['type']) && $field['type'] === 'recaptcha') {
            $recaptcha_field_present = true;
            break;
        }
    }
    $recaptcha_active = ($recaptcha_enabled === 'yes' || $recaptcha_field_present);
    $render_default_recaptcha = ($recaptcha_enabled === 'yes' && !$recaptcha_field_present);

    $form_id = 'firebase_form_' . uniqid();
    wp_enqueue_style('dashicons');
    
    ob_start();
    ?>
    <div class="firebase-form-wrapper" id="<?php echo $form_id; ?>-wrapper">
        <style>
            <?php
            $clean_font_family = trim($font_family, "'");
            $custom_fonts = get_option('ff_firebase_custom_fonts', array());
            if (!empty($clean_font_family) && is_array($custom_fonts) && isset($custom_fonts[$clean_font_family])) :
            ?>
            @font-face {
                font-family: '<?php echo esc_html($clean_font_family); ?>';
                src: url('<?php echo esc_url($custom_fonts[$clean_font_family]); ?>');
            }
            <?php endif; ?>

            .firebase-form-wrapper {
                max-width: <?php echo !empty($max_width) ? esc_html($max_width) : '600px'; ?>;
                margin: 20px auto;
                padding: 30px;
                background: <?php echo !empty($bg_color) ? esc_html($bg_color) : '#ffffff'; ?>;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
                border-radius: 8px;
                font-family: <?php echo !empty($font_family) ? esc_html($font_family) : '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'; ?>;
                box-sizing: border-box;
            }
            .firebase-form-title {
                margin-top: 0;
                margin-bottom: 24px;
                font-size: 24px;
                font-weight: 700;
                color: #1f2937;
                text-align: center;
            }
            .ff-form-group {
                margin-bottom: 20px;
                box-sizing: border-box;
            }
            .ff-form-label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                font-size: <?php echo !empty($label_size) ? intval($label_size) . 'px' : '13px'; ?>;
                color: <?php echo !empty($label_color) ? esc_html($label_color) : '#4b5563'; ?>;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                <?php if (!empty($font_family)) : ?>font-family: <?php echo esc_html($font_family); ?>;<?php endif; ?>
            }
            .ff-form-input {
                width: 100%;
                padding: 12px 16px;
                border: 1px solid <?php echo !empty($input_color) ? esc_html($input_color) : '#d1d5db'; ?>;
                border-radius: 6px;
                font-size: <?php echo !empty($input_size) ? intval($input_size) . 'px' : '14px'; ?>;
                color: <?php echo !empty($input_color) ? esc_html($input_color) : '#1f2937'; ?>;
                background-color: #f9fafb;
                box-sizing: border-box;
                transition: all 0.2s ease-in-out;
                <?php if (!empty($font_family)) : ?>font-family: <?php echo esc_html($font_family); ?>;<?php endif; ?>
            }
            .ff-form-input:focus {
                border-color: <?php echo !empty($input_color) ? esc_html($input_color) : '#c31524'; ?>;
                background-color: #ffffff;
                outline: none;
                box-shadow: 0 0 0 3px <?php echo !empty($input_color) ? esc_html($input_color) . '26' : 'rgba(195, 21, 36, 0.15)'; ?>;
            }
            .ff-form-submit {
                display: block;
                width: 100%;
                padding: 14px;
                background-color: <?php echo !empty($btn_bg_color) ? esc_html($btn_bg_color) : '#000000'; ?>;
                color: <?php echo !empty($btn_text_color) ? esc_html($btn_text_color) : '#ffffff'; ?>;
                border: none;
                border-radius: 30px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: background-color 0.2s;
                margin-top: 25px;
                text-align: center;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                <?php if (!empty($font_family)) : ?>font-family: <?php echo esc_html($font_family); ?>;<?php endif; ?>
            }
            .ff-form-submit:hover {
                background-color: <?php echo !empty($btn_bg_color) ? esc_html($btn_bg_color) : '#c31524'; ?>;
                opacity: 0.9;
            }
            .ff-form-submit:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .ff-message {
                margin-top: 15px;
                padding: 12px;
                border-radius: 6px;
                font-size: 14px;
                text-align: center;
                display: none;
            }
            .ff-message-success {
                background-color: #d1e7dd;
                color: #0f5132;
                border: 1px solid #badbcc;
                display: block;
            }
            .ff-message-error {
                background-color: #f8d7da;
                color: #842029;
                border: 1px solid #f5c2c7;
                display: block;
            }
        </style>

        <form id="<?php echo $form_id; ?>" class="firebase-contact-form" enctype="multipart/form-data">
            <h3 class="firebase-form-title"><?php echo $form_title; ?></h3>
            
            <?php 
            $has_custom_submit = false;
            foreach ($fields as $field) {
                if ($field['type'] === 'custom_submit') {
                    $has_custom_submit = true;
                    break;
                }
            }
            
            foreach ($fields as $field) : ?>
                <?php
                $fid = esc_attr($field['id']);
                $flabel = esc_html($field['label']);
                $ftype = esc_attr($field['type']);
                $fplaceholder = esc_attr($field['placeholder']);
                $frequired = !empty($field['required']) ? 'required' : '';
                
                if ($ftype === 'hidden') {
                    ?>
                    <input type="hidden" name="<?php echo $fid; ?>" value="<?php echo esc_attr($fplaceholder); ?>">
                    <?php
                    continue;
                }
                
                if ($ftype === 'custom_submit') {
                    ?>
                    <?php if ($render_default_recaptcha && !empty($recaptcha_site_key)) : ?>
                        <?php if ($recaptcha_type === 'v2') : ?>
                            <div class="ff-form-group" style="display: flex; justify-content: center; margin-bottom: 15px;">
                                <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($recaptcha_site_key); ?>"></div>
                            </div>
                            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                        <?php else : ?>
                            <script src="https://www.google.com/recaptcha/api.js?render=<?php echo esc_attr($recaptcha_site_key); ?>"></script>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="ff-form-group">
                        <button type="submit" class="ff-form-submit" id="<?php echo $form_id; ?>-custom-submit" style="margin-top: 10px;"><?php echo $flabel; ?></button>
                    </div>
                    <?php
                    continue;
                }
                ?>
                <div class="ff-form-group">
                    <?php if ($ftype !== 'section_break' && $ftype !== 'html' && $ftype !== 'shortcode') : ?>
                        <label class="ff-form-label"><?php echo $flabel; ?><?php if ($frequired) echo ' <span style="color:#d63638;">*</span>'; ?></label>
                    <?php endif; ?>
                    
                    <?php
                    switch ($ftype) {
                        case 'textarea':
                            ?>
                            <textarea name="<?php echo $fid; ?>" class="ff-form-input" placeholder="<?php echo $fplaceholder; ?>" <?php echo $frequired; ?> rows="4"></textarea>
                            <?php
                            break;
                            
                        case 'select':
                        case 'country_list':
                            ?>
                            <select name="<?php echo $fid; ?>" class="ff-form-input" <?php echo $frequired; ?>>
                                <option value=""><?php echo !empty($fplaceholder) ? $fplaceholder : '-- Choose --'; ?></option>
                                <?php
                                if ($ftype === 'country_list') {
                                    $options_arr = array('Germany', 'Austria', 'Switzerland', 'France', 'Italy', 'Netherlands', 'United Kingdom', 'United States');
                                } else {
                                    $raw_options = isset($field['options']) ? $field['options'] : '';
                                    $options_arr = array_filter(array_map('trim', explode("\n", str_replace('\n', "\n", $raw_options))));
                                    if (empty($options_arr)) {
                                        $options_arr = array('Option 1', 'Option 2', 'Option 3');
                                    }
                                }
                                foreach ($options_arr as $opt) :
                                    $opt = esc_attr($opt);
                                    echo "<option value=\"$opt\">" . esc_html($opt) . "</option>";
                                endforeach;
                                ?>
                            </select>
                            <?php
                            break;
                            
                        case 'radio':
                            ?>
                            <div class="ff-radio-options" style="display: flex; flex-direction: column; gap: 8px; margin-top: 5px;">
                                <?php
                                $raw_options = isset($field['options']) ? $field['options'] : "Option 1\nOption 2\nOption 3";
                                $options_arr = array_filter(array_map('trim', explode("\n", str_replace('\n', "\n", $raw_options))));
                                if (empty($options_arr)) {
                                    $options_arr = array('Option 1', 'Option 2', 'Option 3');
                                }
                                foreach ($options_arr as $index => $opt) :
                                    $opt_val = esc_attr($opt);
                                    $opt_id = $form_id . '_' . $fid . '_' . $index;
                                    ?>
                                    <label for="<?php echo $opt_id; ?>" style="display: inline-flex; align-items: center; font-size: 14px; color: #4b5563; cursor: pointer; gap: 8px;">
                                        <input type="radio" id="<?php echo $opt_id; ?>" name="<?php echo $fid; ?>" value="<?php echo $opt_val; ?>" <?php echo $frequired; ?> style="margin:0;">
                                        <span><?php echo esc_html($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php
                            break;
                            
                        case 'checkbox':
                        case 'multiple_choice':
                            ?>
                            <div class="ff-checkbox-options" style="display: flex; flex-direction: column; gap: 8px; margin-top: 5px;">
                                <?php
                                $raw_options = isset($field['options']) ? $field['options'] : "Option 1\nOption 2\nOption 3";
                                $options_arr = array_filter(array_map('trim', explode("\n", str_replace('\n', "\n", $raw_options))));
                                if (empty($options_arr)) {
                                    $options_arr = array('Option 1', 'Option 2', 'Option 3');
                                }
                                foreach ($options_arr as $index => $opt) :
                                    $opt_val = esc_attr($opt);
                                    $opt_id = $form_id . '_' . $fid . '_' . $index;
                                    ?>
                                    <label for="<?php echo $opt_id; ?>" style="display: inline-flex; align-items: center; font-size: 14px; color: #4b5563; cursor: pointer; gap: 8px;">
                                        <input type="checkbox" id="<?php echo $opt_id; ?>" name="<?php echo $fid; ?>[]" value="<?php echo $opt_val; ?>" style="margin:0;">
                                        <span><?php echo esc_html($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php
                            break;
                            
                        case 'ratings':
                            $input_el_id = 'input-' . $form_id . '-' . $fid;
                            $container_el_id = 'rating-' . $form_id . '-' . $fid;
                            ?>
                            <div class="ff-rating-container" style="display: flex; align-items: center; gap: 6px; margin-top: 5px;" id="<?php echo $container_el_id; ?>">
                                <input type="hidden" name="<?php echo $fid; ?>" id="<?php echo $input_el_id; ?>" value="" <?php echo $frequired; ?>>
                                <?php for ($s = 1; $s <= 5; $s++) : ?>
                                    <span class="dashicons dashicons-star-empty ff-rating-star" data-value="<?php echo $s; ?>" style="font-size: 28px; width: 28px; height: 28px; color: #ccc; cursor: pointer; transition: color 0.15s;"></span>
                                <?php endfor; ?>
                            </div>
                            <script>
                                (function() {
                                    var container = document.getElementById('<?php echo $container_el_id; ?>');
                                    var hiddenInput = document.getElementById('<?php echo $input_el_id; ?>');
                                    var stars = container.querySelectorAll('.ff-rating-star');
                                    
                                    stars.forEach(function(star) {
                                        star.addEventListener('click', function() {
                                            var val = this.getAttribute('data-value');
                                            hiddenInput.value = val;
                                            updateStars(val);
                                        });
                                        
                                        star.addEventListener('mouseenter', function() {
                                            var val = this.getAttribute('data-value');
                                            stars.forEach(function(s) {
                                                if (parseInt(s.getAttribute('data-value')) <= parseInt(val)) {
                                                    s.style.color = '#ffcc00';
                                                } else {
                                                    s.style.color = '#ccc';
                                                }
                                            });
                                        });
                                    });
                                    
                                    container.addEventListener('mouseleave', function() {
                                        var currentVal = hiddenInput.value || 0;
                                        updateStars(currentVal);
                                    });
                                    
                                    function updateStars(val) {
                                        stars.forEach(function(s) {
                                            var sVal = s.getAttribute('data-value');
                                            if (parseInt(sVal) <= parseInt(val)) {
                                                s.classList.remove('dashicons-star-empty');
                                                s.classList.add('dashicons-star-filled');
                                                s.style.color = '#ffb900';
                                            } else {
                                                s.classList.remove('dashicons-star-filled');
                                                s.classList.add('dashicons-star-empty');
                                                s.style.color = '#ccc';
                                            }
                                        });
                                    }
                                })();
                            </script>
                            <?php
                            break;
                            
                        case 'password':
                            $input_el_id = 'input-' . $form_id . '-' . $fid;
                            $toggle_el_id = 'toggle-' . $form_id . '-' . $fid;
                            ?>
                            <div style="position: relative;">
                                <input type="password" id="<?php echo $input_el_id; ?>" name="<?php echo $fid; ?>" class="ff-form-input" placeholder="<?php echo $fplaceholder; ?>" <?php echo $frequired; ?> style="padding-right: 40px;">
                                <span class="dashicons dashicons-hidden ff-password-toggle" id="<?php echo $toggle_el_id; ?>" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #646970; font-size: 18px; width:18px; height:18px; cursor: pointer; user-select: none;"></span>
                            </div>
                            <script>
                                document.getElementById('<?php echo $toggle_el_id; ?>').addEventListener('click', function() {
                                    var input = document.getElementById('<?php echo $input_el_id; ?>');
                                    if (input.type === 'password') {
                                        input.type = 'text';
                                        this.classList.remove('dashicons-hidden');
                                        this.classList.add('dashicons-visibility');
                                    } else {
                                        input.type = 'password';
                                        this.classList.remove('dashicons-visibility');
                                        this.classList.add('dashicons-hidden');
                                    }
                                });
                            </script>
                            <?php
                            break;
                            
                        case 'color_picker':
                            $picker_el_id = 'picker-' . $form_id . '-' . $fid;
                            $input_el_id = 'input-' . $form_id . '-' . $fid;
                            ?>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <div style="width: 44px; height: 44px; border-radius: 6px; overflow: hidden; border: 1px solid #d1d5db; position: relative; flex-shrink: 0; background: #fff;">
                                    <input type="color" id="<?php echo $picker_el_id; ?>" class="ff-color-picker-input" value="#c31524" style="position: absolute; top: -5px; left: -5px; width: 54px; height: 54px; border: 0; padding: 0; cursor: pointer; background: transparent;">
                                </div>
                                <input type="text" id="<?php echo $input_el_id; ?>" name="<?php echo $fid; ?>" class="ff-form-input" value="#c31524" style="flex: 1;" <?php echo $frequired; ?>>
                            </div>
                            <script>
                                (function() {
                                    var picker = document.getElementById('<?php echo $picker_el_id; ?>');
                                    var input = document.getElementById('<?php echo $input_el_id; ?>');
                                    picker.addEventListener('input', function() {
                                        input.value = this.value;
                                    });
                                    input.addEventListener('input', function() {
                                        if(/^#[0-9A-F]{6}$/i.test(this.value)) {
                                            picker.value = this.value;
                                        }
                                    });
                                })();
                            </script>
                            <?php
                            break;
                            
                        case 'nps':
                            $input_el_id = 'input-' . $form_id . '-' . $fid;
                            ?>
                            <div class="ff-nps-container" style="display: flex; flex-direction: column; gap: 6px; margin-top: 5px;">
                                <input type="hidden" name="<?php echo $fid; ?>" id="<?php echo $input_el_id; ?>" value="" <?php echo $frequired; ?>>
                                <div style="display: flex; gap: 4px; overflow-x: auto; padding-bottom: 4px;">
                                    <?php for ($n = 1; $n <= 10; $n++) : ?>
                                        <button type="button" class="ff-nps-btn" data-value="<?php echo $n; ?>" style="flex: 1; min-width: 32px; height: 38px; border: 1px solid #d1d5db; background: #ffffff; color: #4b5563; font-size: 13px; font-weight: 600; border-radius: 4px; cursor: pointer; transition: all 0.15s; outline: none;"><?php echo $n; ?></button>
                                    <?php endfor; ?>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-top: 2px;">
                                    <span>Not Likely</span>
                                    <span>Extremely Likely</span>
                                </div>
                            </div>
                            <script>
                                (function() {
                                    var hiddenInput = document.getElementById('<?php echo $input_el_id; ?>');
                                    var buttons = hiddenInput.parentElement.querySelectorAll('.ff-nps-btn');
                                    buttons.forEach(function(btn) {
                                        btn.addEventListener('click', function(e) {
                                            e.preventDefault();
                                            var val = this.getAttribute('data-value');
                                            hiddenInput.value = val;
                                            
                                            buttons.forEach(function(b) {
                                                if (b.getAttribute('data-value') === val) {
                                                    b.style.backgroundColor = '#c31524';
                                                    b.style.color = '#ffffff';
                                                    b.style.borderColor = '#c31524';
                                                } else {
                                                    b.style.backgroundColor = '#ffffff';
                                                    b.style.color = '#4b5563';
                                                    b.style.borderColor = '#d1d5db';
                                                }
                                            });
                                        });
                                    });
                                })();
                            </script>
                            <?php
                            break;
                            
                        case 'gdpr':
                        case 'terms_conditions':
                            ?>
                            <label style="display: inline-flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 13px; color: #4b5563; line-height: 1.4; margin-top: 4px;">
                                <input type="checkbox" name="<?php echo $fid; ?>" value="Yes" <?php echo $frequired; ?> style="margin-top: 3px;">
                                <span>I agree and accept the privacy statements, GDPR guidelines, and terms of service.</span>
                            </label>
                            <?php
                            break;
                            
                        case 'section_break':
                            ?>
                            <div style="margin: 35px 0 20px 0; border-top: 1px dashed #e5e7eb; padding-top: 15px;">
                                <?php if (!empty($flabel)) : ?>
                                    <h4 style="margin: 0 0 5px 0; font-size: 16px; font-weight: 700; color: #1f2937;"><?php echo $flabel; ?></h4>
                                <?php endif; ?>
                                <?php if (!empty($fplaceholder)) : ?>
                                    <p style="margin: 0; font-size: 13px; color: #6b7280;"><?php echo $fplaceholder; ?></p>
                                <?php endif; ?>
                            </div>
                            <?php
                            break;
                            
                        case 'range_slider':
                            $slider_el_id = 'slider-' . $form_id . '-' . $fid;
                            $val_el_id = 'val-' . $form_id . '-' . $fid;
                            ?>
                            <div style="display: flex; flex-direction: column; gap: 6px; margin-top: 5px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <input type="range" id="<?php echo $slider_el_id; ?>" name="<?php echo $fid; ?>" min="0" max="100" value="50" class="ff-range-input" style="flex: 1; accent-color: #c31524; cursor: pointer;">
                                    <span id="<?php echo $val_el_id; ?>" style="background: #e5e7eb; color: #1f2937; font-size: 12px; font-weight: bold; padding: 4px 10px; border-radius: 4px; min-width: 28px; text-align: center;">50</span>
                                </div>
                            </div>
                            <script>
                                (function() {
                                    var sld = document.getElementById('<?php echo $slider_el_id; ?>');
                                    var valDisp = document.getElementById('<?php echo $val_el_id; ?>');
                                    sld.addEventListener('input', function() {
                                        valDisp.textContent = this.value;
                                    });
                                })();
                            </script>
                            <?php
                            break;
                            
                        case 'recaptcha':
                            if (!empty($recaptcha_site_key)) {
                                if ($recaptcha_type === 'v2') {
                                    ?>
                                    <div class="ff-form-group" style="margin-top: 5px;">
                                        <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($recaptcha_site_key); ?>"></div>
                                    </div>
                                    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                                    <?php
                                } else {
                                    ?>
                                    <div style="font-size: 12px; color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 5px; margin-top: 5px;">
                                        <span class="dashicons dashicons-shield" style="font-size: 18px; width: 18px; height: 18px;"></span>
                                        Google reCAPTCHA v3 Protected (Invisible)
                                    </div>
                                    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo esc_attr($recaptcha_site_key); ?>"></script>
                                    <?php
                                }
                            } else {
                                ?>
                                <div style="border: 1px solid #d3d3d3; padding: 15px; background: #fff5f5; color: #c31524; font-size: 13px; border-radius: 6px; margin-top: 5px; font-family: sans-serif;">
                                    <strong>reCAPTCHA Error:</strong> Please configure your Site Key and Secret Key under <strong>Submissions -> reCAPTCHA</strong>.
                                </div>
                                <?php
                            }
                            break;

                        case 'hcaptcha':
                        case 'turnstile':
                            ?>
                            <div class="ff-protection-box" style="display: inline-flex; align-items: center; gap: 12px; padding: 12px 18px; border: 1px solid #d1d5db; background: #f9fafb; border-radius: 6px; font-size: 13px; color: #4b5563; font-weight: 500; margin-top: 5px;">
                                <input type="checkbox" name="<?php echo $fid; ?>" value="Verified" required style="margin: 0;" checked>
                                <span class="dashicons dashicons-shield" style="font-size: 20px; width: 20px; height: 20px; color: #10b981; margin-top: 2px;"></span>
                                <span><?php echo ($ftype === 'hcaptcha') ? 'hCaptcha Spambot Protection' : 'Cloudflare Turnstile Verified'; ?></span>
                            </div>
                            <?php
                            break;
                            
                        case 'html':
                            ?>
                            <div class="ff-custom-html-wrapper" style="margin-top: 5px;">
                                <?php
                                $custom_html = isset($field['options']) ? $field['options'] : '';
                                if (empty($custom_html)) {
                                    $custom_html = '<div class="custom-html"><p>Custom HTML field</p></div>';
                                }
                                echo do_shortcode($custom_html);
                                ?>
                            </div>
                            <?php
                            break;
                            
                        case 'shortcode':
                            ?>
                            <div class="ff-shortcode-wrapper" style="margin-top: 5px;">
                                <?php
                                $sc_text = isset($field['options']) ? $field['options'] : '';
                                if (!empty($sc_text)) {
                                    echo do_shortcode($sc_text);
                                }
                                ?>
                            </div>
                            <?php
                            break;
                            
                        case 'image_upload':
                        case 'file_upload':
                            $file_input_id = 'file-' . $form_id . '-' . $fid;
                            $filename_id = 'filename-' . $form_id . '-' . $fid;
                            ?>
                            <div class="ff-file-upload-container" style="margin-top: 5px;">
                                <div style="border: 2px dashed #ccd0d4; border-radius: 6px; padding: 20px; text-align: center; background: #fafafa; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.borderColor='#c31524';this.style.background='#fff5f5';" onmouseout="this.style.borderColor='#ccd0d4';this.style.background='#fafafa';" onclick="document.getElementById('<?php echo $file_input_id; ?>').click();">
                                    <span class="dashicons dashicons-cloud-upload" style="font-size: 32px; width: 32px; height: 32px; color: #8c8f94; margin-bottom: 6px;"></span>
                                    <div style="font-size: 14px; font-weight: 600; color: #4b5563;">Click to upload or drag files here</div>
                                    <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">Supports PNG, JPG, PDF up to 10MB</div>
                                    <input type="file" id="<?php echo $file_input_id; ?>" name="<?php echo $fid; ?>" style="display: none;" onchange="var fileEl = document.getElementById('<?php echo $filename_id; ?>'); if (this.files[0]) { fileEl.textContent = 'Selected: ' + this.files[0].name; fileEl.style.display = 'block'; }">
                                    <div id="<?php echo $filename_id; ?>" style="display: none; margin-top: 8px; font-size: 12px; font-weight: 700; color: #10b981;"></div>
                                </div>
                            </div>
                            <?php
                            break;
                            
                        case 'name_fields':
                            ?>
                            <div class="ff-name-fields-row" style="display: flex; gap: 15px;">
                                <div style="flex: 1;">
                                    <label class="ff-form-sub-label" style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">First Name</label>
                                    <input type="text" name="<?php echo $fid; ?>[first_name]" class="ff-form-input" placeholder="First Name" <?php echo $frequired; ?>>
                                </div>
                                <div style="flex: 1;">
                                    <label class="ff-form-sub-label" style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Last Name</label>
                                    <input type="text" name="<?php echo $fid; ?>[last_name]" class="ff-form-input" placeholder="Last Name" <?php echo $frequired; ?>>
                                </div>
                            </div>
                            <?php
                            break;
                            
                        case 'address_fields':
                            ?>
                            <div class="ff-address-fields-row" style="display: flex; flex-direction: column; gap: 10px;">
                                <div>
                                    <label class="ff-form-sub-label" style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">Street Address</label>
                                    <input type="text" name="<?php echo $fid; ?>[street]" class="ff-form-input" placeholder="Street Address" <?php echo $frequired; ?>>
                                </div>
                                <div style="display: flex; gap: 15px;">
                                    <div style="flex: 1;">
                                        <label class="ff-form-sub-label" style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">City</label>
                                        <input type="text" name="<?php echo $fid; ?>[city]" class="ff-form-input" placeholder="City" <?php echo $frequired; ?>>
                                    </div>
                                    <div style="flex: 1;">
                                        <label class="ff-form-sub-label" style="font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase;">PLZ / Ort</label>
                                        <input type="text" name="<?php echo $fid; ?>[zip]" class="ff-form-input" placeholder="e.g. 10115" <?php echo $frequired; ?>>
                                    </div>
                                </div>
                            </div>
                            <?php
                            break;
                            
                        case 'column_1':
                        case 'column_2':
                        case 'column_3':
                        case 'column_4':
                        case 'column_5':
                        case 'column_6':
                            $cols = 1;
                            if ($ftype === 'column_2') $cols = 2;
                            elseif ($ftype === 'column_3') $cols = 3;
                            elseif ($ftype === 'column_4') $cols = 4;
                            elseif ($ftype === 'column_5') $cols = 5;
                            elseif ($ftype === 'column_6') $cols = 6;
                            ?>
                            <div class="ff-container-row" style="display: flex; gap: 15px; margin: 15px 0;">
                                <?php for ($c = 1; $c <= $cols; $c++) : ?>
                                    <div style="flex: 1; border: 1px dashed #e5e7eb; border-radius: 4px; padding: 12px; background: #f9fafb; text-align: center; font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;">
                                        Col <?php echo $c; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <?php
                            break;
                            
                        case 'date':
                            ?>
                            <input type="date" name="<?php echo $fid; ?>" class="ff-form-input" <?php echo $frequired; ?>>
                            <?php
                            break;
                            
                        default:
                            ?>
                            <input type="<?php echo $ftype; ?>" name="<?php echo $fid; ?>" class="ff-form-input" placeholder="<?php echo $fplaceholder; ?>" <?php echo $frequired; ?>>
                            <?php
                            break;
                    }
                    ?>
                </div>
            <?php endforeach; ?>
            
            <?php if (!$has_custom_submit) : ?>
                <?php if ($render_default_recaptcha && !empty($recaptcha_site_key)) : ?>
                    <?php if ($recaptcha_type === 'v2') : ?>
                        <div class="ff-form-group" style="display: flex; justify-content: center; margin-bottom: 15px;">
                            <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($recaptcha_site_key); ?>"></div>
                        </div>
                        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                    <?php else : ?>
                        <script src="https://www.google.com/recaptcha/api.js?render=<?php echo esc_attr($recaptcha_site_key); ?>"></script>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="submit" class="ff-form-submit" id="<?php echo $form_id; ?>-submit">Register</button>
            <?php endif; ?>
            
            <div class="ff-message" id="<?php echo $form_id; ?>-msg"></div>
        </form>

        <?php if (isset($form_settings['maps_enabled']) && $form_settings['maps_enabled'] === 'yes') : 
            $maps_address = isset($form_settings['maps_address']) ? $form_settings['maps_address'] : 'Vienna, Austria';
            $maps_zoom = isset($form_settings['maps_zoom']) ? intval($form_settings['maps_zoom']) : 14;
            $maps_type = isset($form_settings['maps_type']) ? $form_settings['maps_type'] : 'roadmap';
            $maps_height = isset($form_settings['maps_height']) ? intval($form_settings['maps_height']) : 400;
            
            $global_settings = get_option('ff_firebase_settings', array());
            $global_maps_key = isset($global_settings['maps_api_key']) ? $global_settings['maps_api_key'] : '';
            $maps_api_key = isset($form_settings['maps_api_key']) ? $form_settings['maps_api_key'] : '';
            if (empty($maps_api_key)) {
                $maps_api_key = $global_maps_key;
            }
            
            if (!empty($maps_api_key)) {
                $embed_src = 'https://www.google.com/maps/embed/v1/place?key=' . esc_attr($maps_api_key) . '&q=' . urlencode($maps_address) . '&zoom=' . intval($maps_zoom) . '&maptype=' . esc_attr($maps_type);
            } else {
                $tLetter = 'm';
                if ($maps_type === 'satellite') $tLetter = 'k';
                elseif ($maps_type === 'hybrid') $tLetter = 'h';
                elseif ($maps_type === 'terrain') $tLetter = 'p';
                $embed_src = 'https://maps.google.com/maps?q=' . urlencode($maps_address) . '&z=' . intval($maps_zoom) . '&t=' . $tLetter . '&output=embed';
            }

            // Extract CSS filters for public front-end rendering
            $maps_blur = isset($form_settings['maps_blur']) ? intval($form_settings['maps_blur']) : 0;
            $maps_brightness = isset($form_settings['maps_brightness']) ? intval($form_settings['maps_brightness']) : 100;
            $maps_contrast = isset($form_settings['maps_contrast']) ? intval($form_settings['maps_contrast']) : 100;
            $maps_saturation = isset($form_settings['maps_saturation']) ? intval($form_settings['maps_saturation']) : 100;
            $maps_hue = isset($form_settings['maps_hue']) ? intval($form_settings['maps_hue']) : 0;
            
            $filter_style = "blur({$maps_blur}px) brightness({$maps_brightness}%) contrast({$maps_contrast}%) saturate({$maps_saturation}%) hue-rotate({$maps_hue}deg)";
            ?>
            <div class="firebase-form-map-container" style="margin-top: 25px; border-radius: 6px; overflow: hidden; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05); line-height: 0;">
                <iframe width="100%" height="<?php echo $maps_height; ?>" frameborder="0" style="border:0; display: block; filter: <?php echo esc_attr($filter_style); ?>; -webkit-filter: <?php echo esc_attr($filter_style); ?>;" allowfullscreen src="<?php echo $embed_src; ?>"></iframe>
            </div>
        <?php endif; ?>

        <script>
            document.getElementById('<?php echo $form_id; ?>').addEventListener('submit', function(e) {
                e.preventDefault();
                
                var form = this;
                var submitBtns = form.querySelectorAll('button[type="submit"]');
                var msgDiv = document.getElementById('<?php echo $form_id; ?>-msg');
                
                function proceedSubmit(recaptchaToken) {
                    submitBtns.forEach(function(btn) {
                        btn.disabled = true;
                        btn.textContent = 'Sending...';
                    });
                    
                    msgDiv.style.display = 'none';
                    msgDiv.className = 'ff-message';
                    
                    var formData = {};
                    var fd = new FormData(form);
                    fd.forEach(function(value, key) {
                        if (key.endsWith('[]')) {
                            var realKey = key.slice(0, -2);
                            if (!formData[realKey]) {
                                formData[realKey] = [];
                            }
                            formData[realKey].push(value);
                        } else if (formData[key]) {
                            if (!Array.isArray(formData[key])) {
                                formData[key] = [formData[key]];
                            }
                            formData[key].push(value);
                        } else {
                            formData[key] = value;
                        }
                    });
                    formData['form_id'] = <?php echo $form_id_num; ?>;
                    if (recaptchaToken) {
                        formData['g-recaptcha-response'] = recaptchaToken;
                    }
                    
                    fetch('/wp-json/firebase-form/v1/submit', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(formData)
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        submitBtns.forEach(function(btn) {
                            btn.disabled = false;
                            if (btn.id.includes('custom')) {
                                btn.textContent = '<?php 
                                    $custom_submit_label = 'Register';
                                    foreach ($fields as $field) {
                                        if ($field['type'] === 'custom_submit') {
                                            $custom_submit_label = $field['label'];
                                            break;
                                        }
                                    }
                                    echo esc_js($custom_submit_label); 
                                ?>';
                            } else {
                                btn.textContent = 'Register';
                            }
                        });
                        
                        if (data.success) {
                            msgDiv.textContent = 'Registration sent successfully!';
                            msgDiv.classList.add('ff-message-success');
                            form.reset();
                            
                            // Reset Gold Stars
                            form.querySelectorAll('.ff-rating-star').forEach(function(s) {
                                s.classList.remove('dashicons-star-filled');
                                s.classList.add('dashicons-star-empty');
                                s.style.color = '#ccc';
                            });
                            
                            // Reset NPS buttons
                            form.querySelectorAll('.ff-nps-btn').forEach(function(b) {
                                b.style.backgroundColor = '#ffffff';
                                b.style.color = '#4b5563';
                                b.style.borderColor = '#d1d5db';
                            });

                            if (typeof grecaptcha !== 'undefined' && grecaptcha.reset) {
                                grecaptcha.reset();
                            }
                        } else {
                            var err = data.message || 'Something went wrong. Please check your entries.';
                            msgDiv.textContent = err;
                            msgDiv.classList.add('ff-message-error');
                        }
                    })
                    .catch(function(error) {
                        submitBtns.forEach(function(btn) {
                            btn.disabled = false;
                            if (btn.id.includes('custom')) {
                                btn.textContent = '<?php echo esc_js($custom_submit_label); ?>';
                            } else {
                                btn.textContent = 'Register';
                            }
                        });
                        msgDiv.textContent = 'Failed to connect. Please try again later.';
                        msgDiv.classList.add('ff-message-error');
                    });
                }

                if (typeof grecaptcha !== 'undefined' && <?php echo ($recaptcha_active && $recaptcha_type === 'v3' && !empty($recaptcha_site_key)) ? 'true' : 'false'; ?>) {
                    submitBtns.forEach(function(btn) {
                        btn.disabled = true;
                        btn.textContent = 'Verifying...';
                    });
                    grecaptcha.ready(function() {
                        grecaptcha.execute('<?php echo esc_js($recaptcha_site_key); ?>', {action: 'submit'})
                            .then(function(token) {
                                proceedSubmit(token);
                            })
                            .catch(function(err) {
                                submitBtns.forEach(function(btn) {
                                    btn.disabled = false;
                                    if (btn.id.includes('custom')) {
                                        btn.textContent = '<?php echo esc_js($custom_submit_label); ?>';
                                    } else {
                                        btn.textContent = 'Register';
                                    }
                                });
                                msgDiv.textContent = 'reCAPTCHA verification failed. Please try again.';
                                msgDiv.classList.add('ff-message-error');
                                msgDiv.style.display = 'block';
                            });
                    });
                } else {
                    proceedSubmit();
                }
            });
        </script>
    </div>
    <?php
    return ob_get_clean();
}

// 9. WP Admin Submissions Table Dashboard Page
function ff_firebase_submissions_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'firebase_form_submissions';
    $forms_table = $wpdb->prefix . 'firebase_forms';

    ff_firebase_ensure_table_exists();

    // Handle Admin Action Events (Delete / Resync)
    if (isset($_GET['action']) && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        
        if ($_GET['action'] === 'delete') {
            check_admin_referer('delete_submission_' . $id);
            $wpdb->delete($table_name, array('id' => $id));
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Submission successfully deleted from local WordPress storage.', 'fluent-forms-to-firebase') . '</p></div>';
        } 
        
        if ($_GET['action'] === 'resync') {
            check_admin_referer('resync_submission_' . $id);
            $result = ff_firebase_sync_single_to_firestore($id);
            if ($result['success']) {
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('Submission successfully synced to Firebase! Document ID: %s', 'fluent-forms-to-firebase'), '<code>' . esc_html($result['doc_id']) . '</code>') . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(__('Firebase Sync Failed: %s', 'fluent-forms-to-firebase'), esc_html($result['error'])) . '</p></div>';
            }
        }
    }

    // Filter by Form ID
    $active_form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
    
    if ($active_form_id > 0) {
        $submissions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE form_id = %d ORDER BY submitted_at DESC", $active_form_id));
    } else {
        $submissions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY submitted_at DESC");
    }

    // Query all form names for filter drop
    $forms = $wpdb->get_results("SELECT id, title FROM $forms_table ORDER BY id ASC");
    ?>
    <div class="wrap firebase-plugin-wrap">
        <div class="ff-plugin-header" style="display: flex; align-items: center; gap: 15px; margin-bottom: 25px; border-bottom: 2px solid #78951D; padding-bottom: 20px;">
            <div class="ff-plugin-logo" style="width: 50px; height: 50px; background: #000; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border: 2px solid #78951D;">
                <svg viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg" style="width: 32px; height: 32px;">
                    <circle cx="90" cy="90" r="90" fill="black" />
                    <path d="M149.508 157.52L69.142 54H54V125.97H66.1136V69.3836L139.999 164.845C143.333 162.614 146.509 160.165 149.508 157.52Z" fill="white" />
                    <rect fill="white" height="72" width="12" x="114" y="54" />
                </svg>
            </div>
            <div>
                <h1 style="margin: 0; padding: 0; font-size: 24px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; line-height: 1.2;">Connect Next Js</h1>
                <p style="margin: 3px 0 0 0; font-size: 12px; color: #78951D; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; line-height: 1;">by 2H Web Solution</p>
            </div>
        </div>
        <h2 class="ff-page-title" style="color: #ffffff; font-size: 20px; font-weight: 700; margin-bottom: 20px;"><?php _e('Form Submissions Log', 'fluent-forms-to-firebase'); ?></h2>

        <!-- Form Filter dropdown matching Fluent Forms dashboard list -->
        <div style="margin-top: 20px; margin-bottom: 20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; display:flex; align-items:center; gap:8px;">
            <label for="ff_form_filter" style="font-weight:600; font-size:13px; color:#2c3338; margin-right:4px;">Filter by Form:</label>
            <select id="ff_form_filter" style="border-radius:4px; border-color:#ccd0d4; padding:3px 24px 3px 12px; font-size:13px; min-height:30px; cursor:pointer;" onchange="window.location.href = addQueryParameter(window.location.href, 'form_id', this.value)">
                <option value="0"><?php _e('All Forms', 'fluent-forms-to-firebase'); ?></option>
                <?php foreach ($forms as $f) : ?>
                    <option value="<?php echo intval($f->id); ?>" <?php selected($active_form_id, $f->id); ?>><?php echo esc_html($f->title); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <script>
            function addQueryParameter(url, key, value) {
                var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
                var separator = url.indexOf('?') !== -1 ? "&" : "?";
                if (url.match(re)) {
                    return url.replace(re, '$1' + key + "=" + value + '$2');
                } else {
                    return url + separator + key + "=" + value;
                }
            }
        </script>

        <style>
            .firebase-status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 4px;
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
            }
            .status-synced {
                background-color: #d1e7dd;
                color: #0f5132;
            }
            .status-pending {
                background-color: #fff3cd;
                color: #664d03;
            }
            .status-failed {
                background-color: #f8d7da;
                color: #842029;
            }
            .table-container {
                margin-top: 20px;
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 3px rgba(0,0,0,.05);
                border-radius: 4px;
                overflow: hidden;
            }
            .submissions-table {
                width: 100%;
                border-collapse: collapse;
            }
            .submissions-table th, .submissions-table td {
                padding: 14px 16px;
                text-align: left;
                border-bottom: 1px solid #f0f0f1;
            }
            .submissions-table th {
                background: #f6f7f7;
                font-weight: 700;
                border-bottom: 2px solid #dcdcde;
            }
            .submissions-table tr:hover {
                background-color: #f9f9f9;
            }
            .actions-column a {
                margin-right: 12px;
                text-decoration: none;
                font-weight: 500;
            }
            .delete-link {
                color: #b32d2e !important;
            }
            .delete-link:hover {
                color: #e00 !important;
            }
            .resync-link {
                color: #78951D !important;
            }
            .resync-link:hover {
                color: #135e96 !important;
            }
        </style>

        <div class="table-container">
            <table class="submissions-table">
                <thead>
                    <tr>
                        <th width="5%">ID</th>
                        <th width="15%">Name</th>
                        <th width="18%">Email</th>
                        <th width="22%">Address</th>
                        <th width="12%">PLZ / Ort</th>
                        <th width="13%">Firebase Sync Status</th>
                        <th width="15%">Submitted At</th>
                        <th width="10%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)) : ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: #646970;">
                                <?php _e('No submissions found.', 'fluent-forms-to-firebase'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($submissions as $sub) : ?>
                            <tr>
                                <td><?php echo esc_html($sub->id); ?></td>
                                <td>
                                    <strong><?php echo esc_html($sub->name); ?></strong>
                                    <br>
                                    <a href="#" class="ff-view-details-link" data-data="<?php echo esc_attr($sub->form_data); ?>" data-id="<?php echo $sub->id; ?>" data-name="<?php echo esc_attr($sub->name); ?>" data-email="<?php echo esc_attr($sub->email); ?>" style="color:#78951D; font-weight:600; font-size:11px; text-decoration:none;">View Custom Fields</a>
                                </td>
                                <td><a href="mailto:<?php echo esc_attr($sub->email); ?>"><?php echo esc_html($sub->email); ?></a></td>
                                <td><?php echo esc_html($sub->address); ?></td>
                                <td><?php echo esc_html($sub->plz_ort); ?></td>
                                <td>
                                    <?php if ($sub->sync_status === 'synced') : ?>
                                        <span class="firebase-status-badge status-synced" title="Synced Document Path"><?php _e('Synced', 'fluent-forms-to-firebase'); ?></span>
                                        <br><code style="font-size: 10px; color: #646970; display: block; margin-top: 4px;"><?php echo esc_html($sub->firebase_doc_id); ?></code>
                                    <?php elseif ($sub->sync_status === 'failed') : ?>
                                        <span class="firebase-status-badge status-failed" title="Failed to Sync"><?php _e('Failed', 'fluent-forms-to-firebase'); ?></span>
                                        <br><span style="font-size: 10px; color: #b32d2e; display: block; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 4px;"><?php echo esc_html($sub->sync_error); ?></span>
                                    <?php else : ?>
                                        <span class="firebase-status-badge status-pending"><?php _e('Pending', 'fluent-forms-to-firebase'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($sub->submitted_at))); ?></td>
                                <td class="actions-column">
                                    <?php
                                    $resync_url = wp_nonce_url(admin_url('admin.php?page=firebase_submissions&action=resync&id=' . $sub->id), 'resync_submission_' . $sub->id);
                                    $delete_url = wp_nonce_url(admin_url('admin.php?page=firebase_submissions&action=delete&id=' . $sub->id), 'delete_submission_' . $sub->id);
                                    ?>
                                    <a href="<?php echo esc_url($resync_url); ?>" class="resync-link" title="<?php esc_attr_e('Retry syncing to Firebase', 'fluent-forms-to-firebase'); ?>"><?php _e('Resync', 'fluent-forms-to-firebase'); ?></a>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="delete-link" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this submission from WordPress?', 'fluent-forms-to-firebase'); ?>')" title="<?php esc_attr_e('Delete locally', 'fluent-forms-to-firebase'); ?>"><?php _e('Delete', 'fluent-forms-to-firebase'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- View Details Modal Markup -->
    <div id="ff_details_modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#ffffff; border-radius:6px; border:1px solid #ccd0d4; width:520px; max-width:90%; box-shadow:0 4px 15px rgba(0,0,0,0.2); overflow:hidden; font-family:-apple-system, BlinkMacSystemFont, sans-serif;">
            <div style="background:#f6f7f7; border-bottom:1px solid #ccd0d4; padding:15px 20px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:15px; color:#1d2327;">Submission Custom Fields (Entry ID: <span id="ff_modal_sub_id"></span>)</h3>
                <button type="button" id="ff_modal_close_btn" style="background:none; border:none; color:#646970; cursor:pointer; padding:0; outline:none;"><span class="dashicons dashicons-no-alt" style="font-size:20px; width:20px; height:20px; margin-top:2px;"></span></button>
            </div>
            <div style="padding:20px; max-height:420px; overflow-y:auto;" id="ff_modal_body">
                <!-- Loaded dynamically -->
            </div>
            <div style="background:#f6f7f7; border-top:1px solid #ccd0d4; padding:12px 20px; text-align:right;">
                <button type="button" id="ff_modal_ok_btn" class="button button-secondary" style="font-weight:600; min-height:30px;">Close Details</button>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('.ff-view-details-link').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            var name = $(this).data('name');
            var email = $(this).data('email');
            var rawData = $(this).data('data');
            
            $('#ff_modal_sub_id').text(id);
            var body = $('#ff_modal_body');
            body.empty();
            
            var table = $('<table style="width:100%; border-collapse:collapse; font-size:13px; line-height:1.5;"></table>');
            table.append('<tr><td style="padding:8px 0; font-weight:700; width:35%; border-bottom:1px solid #eee; color:#646970;">Form Submission ID</td><td style="padding:8px 0; border-bottom:1px solid #eee;">' + id + '</td></tr>');
            table.append('<tr><td style="padding:8px 0; font-weight:700; border-bottom:1px solid #eee; color:#646970;">Name</td><td style="padding:8px 0; border-bottom:1px solid #eee; font-weight:600;">' + escapeHtml(name) + '</td></tr>');
            table.append('<tr><td style="padding:8px 0; font-weight:700; border-bottom:1px solid #eee; color:#646970;">Email</td><td style="padding:8px 0; border-bottom:1px solid #eee;"><a href="mailto:' + escapeHtml(email) + '" style="color:#78951D; text-decoration:none;">' + escapeHtml(email) + '</a></td></tr>');
            
            if (rawData) {
                var data = {};
                try {
                    data = (typeof rawData === 'object') ? rawData : JSON.parse(rawData);
                } catch(err) {
                    console.error(err);
                }
                
                var hasCustom = false;
                for (var key in data) {
                    if (data.hasOwnProperty(key)) {
                        if (key === 'name' || key === 'email') continue; // already outputted
                        hasCustom = true;
                        var label = key.toUpperCase().replace(/_/g, ' ');
                        var val = data[key];
                        if (typeof val === 'object' && val !== null) {
                            val = JSON.stringify(val);
                        }
                        table.append('<tr><td style="padding:8px 0; font-weight:700; border-bottom:1px solid #eee; color:#646970;">' + escapeHtml(label) + '</td><td style="padding:8px 0; border-bottom:1px solid #eee;">' + escapeHtml(val) + '</td></tr>');
                    }
                }
                if (!hasCustom) {
                    table.append('<tr><td colspan="2" style="padding:15px 0; color:#8c8f94; font-style:italic; text-align:center;">No additional custom fields were submitted.</td></tr>');
                }
            } else {
                table.append('<tr><td colspan="2" style="padding:15px 0; color:#8c8f94; font-style:italic; text-align:center;">No additional dynamic field payload recorded.</td></tr>');
            }
            
            body.append(table);
            $('#ff_details_modal').css('display', 'flex');
        });
        
        $('#ff_modal_close_btn, #ff_modal_ok_btn').on('click', function(e) {
            e.preventDefault();
            $('#ff_details_modal').hide();
        });
        
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') $('#ff_details_modal').hide();
        });
        $('#ff_details_modal').on('click', function(e) {
            if (e.target === this) $('#ff_details_modal').hide();
        });
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    });
    </script>
    <?php
}

// 10. Register Gutenberg Block for Admin Editor Sidebar
add_action('init', 'ff_firebase_register_gutenberg_block');
function ff_firebase_register_gutenberg_block() {
    if (function_exists('register_block_type')) {
        register_block_type('fluent-forms-to-firebase/firebase-form', array(
            'render_callback' => 'ff_firebase_render_gutenberg_block',
            'attributes'      => array(
                'formId' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
            ),
        ));

        register_block_type('fluent-forms-to-firebase/google-maps', array(
            'render_callback' => 'ff_firebase_render_google_maps_block',
            'attributes'      => array(
                'address' => array(
                    'type'    => 'string',
                    'default' => 'Vienna, Austria',
                ),
                'zoom'    => array(
                    'type'    => 'number',
                    'default' => 14,
                ),
                'height'  => array(
                    'type'    => 'number',
                    'default' => 400,
                ),
                'mapType' => array(
                    'type'    => 'string',
                    'default' => 'roadmap',
                ),
            ),
        ));
    }
}

function ff_firebase_render_gutenberg_block($attributes) {
    if (empty($attributes['formId'])) {
        return '<div class="firebase-form-placeholder" style="padding: 20px; border: 1px dashed #ccc; text-align: center; color: #888;">-- Please select a Firebase Form to render --</div>';
    }
    
    $form_id = intval($attributes['formId']);
    return ff_firebase_contact_form_shortcode(array('id' => $form_id));
}

function ff_firebase_render_google_maps_block($attributes) {
    $address  = isset($attributes['address']) ? $attributes['address'] : 'Vienna, Austria';
    $zoom     = isset($attributes['zoom']) ? intval($attributes['zoom']) : 14;
    $height   = isset($attributes['height']) ? intval($attributes['height']) : 400;
    $map_type = isset($attributes['mapType']) ? $attributes['mapType'] : 'roadmap';

    $global_settings = get_option('ff_firebase_settings', array());
    $api_key = isset($global_settings['maps_api_key']) ? $global_settings['maps_api_key'] : '';

    if ($api_key) {
        $src = 'https://www.google.com/maps/embed/v1/place?key=' . urlencode($api_key) . '&q=' . urlencode($address) . '&zoom=' . $zoom . '&maptype=' . urlencode($map_type);
    } else {
        $t_letter = 'm';
        if ($map_type === 'satellite') {
            $t_letter = 'k';
        } elseif ($map_type === 'hybrid') {
            $t_letter = 'h';
        } elseif ($map_type === 'terrain') {
            $t_letter = 'p';
        }
        $src = 'https://maps.google.com/maps?q=' . urlencode($address) . '&z=' . $zoom . '&t=' . $t_letter . '&output=embed';
    }

    $output = '<div class="ff-firebase-google-maps-block-wrapper" style="width:100%; overflow:hidden; border-radius:4px; line-height:0;">';
    $output .= '<iframe src="' . esc_url($src) . '" width="100%" height="' . esc_attr($height) . '" frameborder="0" style="border:0; display:block;" allowfullscreen></iframe>';
    $output .= '</div>';

    return $output;
}

add_action('enqueue_block_editor_assets', 'ff_firebase_enqueue_block_assets');
function ff_firebase_enqueue_block_assets() {
    global $wpdb;
    $forms_table = $wpdb->prefix . 'firebase_forms';
    
    ff_firebase_ensure_table_exists();
    $forms = $wpdb->get_results("SELECT id, title FROM $forms_table ORDER BY id ASC", ARRAY_A);
    if (empty($forms)) {
        $forms = array(array('id' => 1, 'title' => 'Contact Form'));
    }

    $js_url = plugins_url('fluent-forms-to-firebase-block.js', __FILE__);
    
    wp_enqueue_script(
        'ff-firebase-gutenberg-block',
        $js_url,
        array('wp-blocks', 'wp-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-server-side-render'),
        '1.0.0',
        true
    );

    wp_localize_script('ff-firebase-gutenberg-block', 'ff_firebase_block_forms', $forms);

    // Localize the global maps API key fallback so the React Gutenberg editor can retrieve it in real-time
    $global_settings = get_option('ff_firebase_settings', array());
    $global_maps_key = isset($global_settings['maps_api_key']) ? $global_settings['maps_api_key'] : '';
    wp_localize_script('ff-firebase-gutenberg-block', 'ff_firebase_global_maps_key', array('key' => $global_maps_key));
}

// 11. Register WPGraphQL Custom Fields and Query Endpoints
add_action('graphql_register_types', 'ff_firebase_register_graphql_types');
function ff_firebase_register_graphql_types() {
    if (!function_exists('register_graphql_object_type') || !function_exists('register_graphql_field')) {
        return; // WPGraphQL is not active
    }

    // A. Register Field Item Object Type
    register_graphql_object_type('FirebaseFormField', array(
        'description' => 'A single field configuration inside the Firebase Form',
        'fields'      => array(
            'id'          => array('type' => 'String'),
            'label'       => array('type' => 'String'),
            'type'        => array('type' => 'String'),
            'placeholder' => array('type' => 'String'),
            'required'    => array('type' => 'Boolean'),
            'options'     => array('type' => 'String'),
        ),
    ));

    // B. Register Form Object Type
    register_graphql_object_type('FirebaseFormType', array(
        'description' => 'A custom Firebase form structure',
        'fields'      => array(
            'id'               => array('type' => 'Int'),
            'title'            => array('type' => 'String'),
            'fields'           => array('type' => array('list_of' => 'FirebaseFormField')),
            'settings'         => array('type' => 'String'),
            'mapsEnabled'      => array('type' => 'Boolean'),
            'mapsApiKey'       => array('type' => 'String'),
            'mapsAddress'      => array('type' => 'String'),
            'mapsZoom'         => array('type' => 'Int'),
            'mapsType'         => array('type' => 'String'),
            'mapsHeight'       => array('type' => 'Int'),
            'recaptchaEnabled' => array('type' => 'Boolean'),
            'recaptchaType'    => array('type' => 'String'),
            'recaptchaSiteKey' => array('type' => 'String'),
        ),
    ));

    // C. Register Root Query Field "firebaseForm(id: Int!)"
    register_graphql_field('RootQuery', 'firebaseForm', array(
        'type'        => 'FirebaseFormType',
        'description' => 'Fetch a custom Firebase Form by ID',
        'args'        => array(
            'id' => array(
                'type'        => 'Int',
                'description' => 'The unique Form ID',
            ),
        ),
        'resolve'     => function($source, $args, $context, $info) {
            global $wpdb;
            $form_id = !empty($args['id']) ? intval($args['id']) : 1;
            $forms_table = $wpdb->prefix . 'firebase_forms';
            
            ff_firebase_ensure_table_exists();
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM $forms_table WHERE id = %d", $form_id));
            if (!$form) {
                return null;
            }

            $fields_decoded = json_decode(wp_unslash($form->fields_json), true);
            if (empty($fields_decoded)) {
                $fields_decoded = ff_firebase_get_default_form_fields();
            }

            $fields_typed = array();
            foreach ($fields_decoded as $f) {
                $fields_typed[] = array(
                    'id'          => isset($f['id']) ? strval($f['id']) : '',
                    'label'       => isset($f['label']) ? strval($f['label']) : '',
                    'type'        => isset($f['type']) ? strval($f['type']) : '',
                    'placeholder' => isset($f['placeholder']) ? strval($f['placeholder']) : '',
                    'required'    => !empty($f['required']),
                    'options'     => isset($f['options']) ? strval($f['options']) : '',
                );
            }

            $form_settings = array();
            if (!empty($form->settings_json)) {
                $form_settings = json_decode(wp_unslash($form->settings_json), true);
            }
            if (!is_array($form_settings)) {
                $form_settings = array();
            }

            // Retrieve global API Key fallback
            $global_settings = get_option('ff_firebase_settings', array());
            $global_maps_key = isset($global_settings['maps_api_key']) ? $global_settings['maps_api_key'] : '';

            $maps_api_key = isset($form_settings['maps_api_key']) ? $form_settings['maps_api_key'] : '';
            if (empty($maps_api_key)) {
                $maps_api_key = $global_maps_key;
            }

            return array(
                'id'                => intval($form->id),
                'title'             => $form->title,
                'fields'            => $fields_typed,
                'settings'          => $form->settings_json,
                'mapsEnabled'       => isset($form_settings['maps_enabled']) && $form_settings['maps_enabled'] === 'yes',
                'mapsApiKey'        => $maps_api_key,
                'mapsAddress'       => isset($form_settings['maps_address']) ? $form_settings['maps_address'] : 'Vienna, Austria',
                'mapsZoom'          => isset($form_settings['maps_zoom']) ? intval($form_settings['maps_zoom']) : 14,
                'mapsType'          => isset($form_settings['maps_type']) ? $form_settings['maps_type'] : 'roadmap',
                'mapsHeight'        => isset($form_settings['maps_height']) ? intval($form_settings['maps_height']) : 400,
                'recaptchaEnabled'  => isset($form_settings['recaptcha_enabled']) && $form_settings['recaptcha_enabled'] === 'yes',
                'recaptchaType'     => isset($global_settings['recaptcha_type']) ? $global_settings['recaptcha_type'] : 'v2',
                'recaptchaSiteKey'  => isset($global_settings['recaptcha_site_key']) ? $global_settings['recaptcha_site_key'] : '',
            );
        }
    ));
}

// 10. HTTP to HTTPS SSL Setup & Enforcer Features
function ff_firebase_ssl_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Retrieve global settings
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }

    $enforce_ssl = isset($options['enforce_ssl']) ? $options['enforce_ssl'] : 'no';
    $fix_mixed_content = isset($options['fix_mixed_content']) ? $options['fix_mixed_content'] : 'no';

    $is_currently_ssl = is_ssl();
    
    // Check if site and home URL are using https
    $home_url = get_home_url();
    $site_url = get_site_url();
    $home_has_https = (strpos($home_url, 'https://') === 0);
    $site_has_https = (strpos($site_url, 'https://') === 0);

    // Call unified native header
    ff_firebase_admin_page_header('SSL Setup & Enforcer', 'ssl');
    ?>
    <div class="card" style="padding: 20px; margin-bottom: 20px; border-radius: 4px; border: 1px solid #4a4a4a; background: #2a2a2a; max-width: 800px; font-family: -apple-system, BlinkMacSystemFont, sans-serif;">
        <h2 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px; color: #ffffff;">SSL Status Overview</h2>
        
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px; padding: 15px; background: <?php echo $is_currently_ssl ? '#132b1a' : '#3d1616'; ?>; border: 1px solid <?php echo $is_currently_ssl ? '#1a592c' : '#822121'; ?>; border-radius: 6px;">
            <span class="dashicons <?php echo $is_currently_ssl ? 'dashicons-lock' : 'dashicons-warning'; ?>" style="font-size: 32px; width: 32px; height: 32px; color: <?php echo $is_currently_ssl ? '#78951D' : '#ff3333'; ?>;"></span>
            <div>
                <h4 style="margin: 0 0 5px 0; color: <?php echo $is_currently_ssl ? '#78951D' : '#ff3333'; ?>; font-size: 14px; font-weight: 700;">
                    <?php echo $is_currently_ssl ? 'SSL Connection Active' : 'SSL Connection Inactive / Not Detected'; ?>
                </h4>
                <p style="margin: 0; font-size: 13px; color: #cccccc;">
                    <?php 
                    if ($is_currently_ssl) {
                        echo 'Your website is successfully loaded over a secure HTTPS connection. You can safely enforce SSL redirects below.';
                    } else {
                        echo 'Your website is loaded over insecure HTTP. Ensure you have an SSL certificate installed before enforcing redirect to prevent lockout.';
                    }
                    ?>
                </p>
            </div>
        </div>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row" style="width: 200px;"><label for="enforce_ssl" style="font-weight: 600; color: #ffffff;">Enforce HTTPS Redirect</label></th>
                    <td>
                        <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 8px; color: #ffffff;">
                            <input type="checkbox" id="enforce_ssl" name="ff_firebase_settings[enforce_ssl]" value="yes" <?php checked($enforce_ssl, 'yes'); ?>>
                            Redirect all insecure HTTP requests to HTTPS (301 Permanent Redirect)
                        </label>
                        <p class="description" style="margin-top: 5px; color: #cccccc;">This automatically redirects users accessing the HTTP version of your site to the secure HTTPS version.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fix_mixed_content" style="font-weight: 600; color: #ffffff;">Fix Mixed Content</label></th>
                    <td>
                        <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 8px; color: #ffffff;">
                            <input type="checkbox" id="fix_mixed_content" name="ff_firebase_settings[fix_mixed_content]" value="yes" <?php checked($fix_mixed_content, 'yes'); ?>>
                            Dynamically rewrite mixed content resource links
                        </label>
                        <p class="description" style="margin-top: 5px; color: #cccccc;">Forces script, stylesheet, image, and iframe URLs inside page output buffers to use HTTPS dynamically, fixing security warnings.</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card" style="padding: 20px; border-radius: 4px; border: 1px solid #4a4a4a; background: #2a2a2a; max-width: 800px; font-family: -apple-system, BlinkMacSystemFont, sans-serif;">
        <h2 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px; color: #ffffff;">URL Configuration Check</h2>
        <p class="description" style="margin-bottom: 15px; color: #cccccc;">WordPress uses the URL settings below to generate links. If SSL is fully working, it is recommended to update these to HTTPS in standard WordPress settings.</p>
        
        <table class="widefat striped" style="border: 0; box-shadow: none;">
            <thead>
                <tr>
                    <th style="font-weight: 600; width: 220px; color: #ffffff;">Setting</th>
                    <th style="font-weight: 600; color: #ffffff;">Current URL</th>
                    <th style="font-weight: 600; width: 120px; text-align: center; color: #ffffff;">Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="color: #ffffff;"><strong>WordPress Address (Site URL)</strong></td>
                    <td><code><?php echo esc_html($site_url); ?></code></td>
                    <td style="text-align: center;">
                        <?php if ($site_has_https) : ?>
                            <span style="color: #78951D; font-weight: 600;"><span class="dashicons dashicons-yes-alt" style="vertical-align: sub;"></span> HTTPS</span>
                        <?php else : ?>
                            <span style="color: #ff3333; font-weight: 600;"><span class="dashicons dashicons-warning" style="vertical-align: sub;"></span> HTTP</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="color: #ffffff;"><strong>Site Address (Home URL)</strong></td>
                    <td><code><?php echo esc_html($home_url); ?></code></td>
                    <td style="text-align: center;">
                        <?php if ($home_has_https) : ?>
                            <span style="color: #78951D; font-weight: 600;"><span class="dashicons dashicons-yes-alt" style="vertical-align: sub;"></span> HTTPS</span>
                        <?php else : ?>
                            <span style="color: #ff3333; font-weight: 600;"><span class="dashicons dashicons-warning" style="vertical-align: sub;"></span> HTTP</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if (!$site_has_https || !$home_has_https) : ?>
            <div style="margin-top: 15px; padding: 10px; background: #3b2f15; border: 1px solid #826221; border-radius: 4px;">
                <p style="margin: 0; font-size: 12px; color: #f59e0b;">
                    <span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; color: #f59e0b;"></span> 
                    To permanently fix these settings, go to <a href="<?php echo admin_url('options-general.php'); ?>" target="_blank" style="color: #78951D; text-decoration: underline; font-weight: 600;">Settings -> General</a> and update both URL addresses to start with <code>https://</code>.
                </p>
            </div>
        <?php endif; ?>
    </div>
    <?php
    ff_firebase_admin_page_footer();
}

// Redirect all insecure HTTP requests to HTTPS
add_action('template_redirect', 'ff_firebase_enforce_ssl_redirect', 1);
add_action('admin_init', 'ff_firebase_enforce_ssl_redirect', 1);
function ff_firebase_enforce_ssl_redirect() {
    if (defined('WP_CLI') && WP_CLI) return;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;
    if (defined('DOING_CRON') && DOING_CRON) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;

    $options = get_option('ff_firebase_settings', array());
    $enforce_ssl = isset($options['enforce_ssl']) ? $options['enforce_ssl'] : 'no';
    
    if (!is_ssl() && $enforce_ssl === 'yes') {
        if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
            $redirect_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            wp_redirect($redirect_url, 301);
            exit;
        }
    }
}

// Dynamic mixed content fixer buffer
add_action('template_redirect', 'ff_firebase_start_mixed_content_buffer', 2);
function ff_firebase_start_mixed_content_buffer() {
    $options = get_option('ff_firebase_settings', array());
    $fix_mixed_content = isset($options['fix_mixed_content']) ? $options['fix_mixed_content'] : 'no';
    
    if ($fix_mixed_content === 'yes') {
        ob_start('ff_firebase_fix_mixed_content_callback');
    }
}

function ff_firebase_fix_mixed_content_callback($buffer) {
    if (empty($buffer)) {
        return $buffer;
    }
    
    $home_url = get_home_url();
    $home_url_http = str_replace('https://', 'http://', $home_url);
    
    // 1. Rewrite absolute links matching our own domain to HTTPS
    if (strpos($home_url_http, 'http://') === 0) {
        $buffer = str_replace($home_url_http, $home_url, $buffer);
    }
    
    // 2. Rewrite src="http:// to src="https://
    $buffer = preg_replace('/src=["\']http:\/\//i', 'src="https://', $buffer);
    
    // 3. Rewrite href="http:// for asset styles, scripts, and fonts
    $buffer = preg_replace('/href=["\']http:\/\/([^"\']+\.(?:css|js|woff|woff2|ttf|eot|png|jpg|jpeg|gif|svg|webp))/i', 'href="https://$1', $buffer);
    
    // 4. Rewrite iframe links and data attributes
    $buffer = preg_replace('/data-src=["\']http:\/\//i', 'data-src="https://', $buffer);
    
    return $buffer;
}

// 11. Activity Log History Display Dashboard
function ff_firebase_log_history_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    global $wpdb;
    $logs_table = $wpdb->prefix . 'firebase_form_logs';
    ff_firebase_ensure_table_exists();

    // Handle Clear Logs action
    if (isset($_POST['ff_clear_logs']) && wp_verify_nonce($_POST['ff_clear_logs_nonce'], 'ff_clear_logs_action')) {
        $wpdb->query("TRUNCATE TABLE $logs_table");
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Log history cleared successfully.', 'fluent-forms-to-firebase') . '</p></div>';
        ff_firebase_add_log('settings', 'Activity log history cleared.', 'info');
    }

    // Filters
    $filter_type = isset($_GET['event_type']) ? sanitize_text_field($_GET['event_type']) : 'all';
    $filter_status = isset($_GET['log_status']) ? sanitize_text_field($_GET['log_status']) : 'all';

    // Build Query
    $where = array();
    $params = array();

    if ($filter_type !== 'all') {
        $where[] = "event_type = %s";
        $params[] = $filter_type;
    }
    if ($filter_status !== 'all') {
        $where[] = "status = %s";
        $params[] = $filter_status;
    }

    $where_sql = '';
    if (!empty($where)) {
        $where_sql = "WHERE " . implode(" AND ", $where);
    }

    $sql = "SELECT * FROM $logs_table $where_sql ORDER BY id DESC LIMIT 200";
    if (!empty($params)) {
        $logs = $wpdb->get_results($wpdb->prepare($sql, $params));
    } else {
        $logs = $wpdb->get_results($sql);
    }

    // Get event types for filter dropdown
    $event_types = array(
        'all'            => 'All Event Types',
        'submission'     => 'Form Submissions',
        'firebase_sync'  => 'Firebase Sync',
        'email'          => 'SMTP Mail',
        'login'          => 'User Logins',
        'search_replace' => 'Search & Replace',
        'ssl'            => 'SSL Settings',
        'settings'       => 'General Settings'
    );

    // Get statuses for filter dropdown
    $statuses = array(
        'all'     => 'All Statuses',
        'success' => 'Success',
        'info'    => 'Info',
        'error'   => 'Error',
        'warning' => 'Warning'
    );

    ?>
    <div class="wrap firebase-plugin-wrap">
        <h1 class="wp-heading-inline"><?php _e('Activity Log History', 'fluent-forms-to-firebase'); ?></h1>
        <hr class="wp-header-end">

        <!-- Controls / Filters bar -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; margin-bottom:20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; flex-wrap: wrap; gap: 15px;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap: wrap;">
                <label for="ff_log_type_filter" style="font-weight:600; font-size:13px; color:#2c3338;">Type:</label>
                <select id="ff_log_type_filter" style="border-radius:4px; border-color:#ccd0d4; padding:3px 24px 3px 12px; font-size:13px; min-height:30px; cursor:pointer;" onchange="window.location.href = addQueryParameter(window.location.href, 'event_type', this.value)">
                    <?php foreach ($event_types as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($filter_type, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="ff_log_status_filter" style="font-weight:600; font-size:13px; color:#2c3338; margin-left:10px;">Status:</label>
                <select id="ff_log_status_filter" style="border-radius:4px; border-color:#ccd0d4; padding:3px 24px 3px 12px; font-size:13px; min-height:30px; cursor:pointer;" onchange="window.location.href = addQueryParameter(window.location.href, 'log_status', this.value)">
                    <?php foreach ($statuses as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($filter_status, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <form action="" method="post" onsubmit="return confirm('Are you sure you want to permanently clear the activity log history?');">
                <?php wp_nonce_field('ff_clear_logs_action', 'ff_clear_logs_nonce'); ?>
                <button type="submit" name="ff_clear_logs" value="1" class="button button-secondary" style="color: #b32d2e; border-color: #b32d2e;">Clear Log History</button>
            </form>
        </div>

        <script>
            function addQueryParameter(url, key, value) {
                var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
                var separator = url.indexOf('?') !== -1 ? "&" : "?";
                if (url.match(re)) {
                    return url.replace(re, '$1' + key + "=" + value + '$2');
                } else {
                    return url + separator + key + "=" + value;
                }
            }
        </script>

        <div style="background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04);">
            <table class="wp-list-table widefat fixed striped table-view-list" style="border: 0;">
                <thead>
                    <tr>
                        <th style="width: 160px; font-weight: 600;">Timestamp</th>
                        <th style="width: 140px; font-weight: 600;">Event Type</th>
                        <th style="width: 100px; font-weight: 600;">Status</th>
                        <th style="font-weight: 600;">Log Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 20px; color: #646970;">No logs found matching your filters.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($logs as $log) : 
                            $status_bg = '#f0f0f1';
                            $status_color = '#2c3338';
                            if ($log->status === 'success') {
                                $status_bg = '#d1e7dd';
                                $status_color = '#0f5132';
                            } elseif ($log->status === 'error') {
                                $status_bg = '#f8d7da';
                                $status_color = '#842029';
                            } elseif ($log->status === 'warning') {
                                $status_bg = '#fff3cd';
                                $status_color = '#664d03';
                            }
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($log->created_at); ?></code></td>
                                <td>
                                    <span style="font-weight: 600; text-transform: uppercase; font-size: 11px; background: #e2e8f0; color: #475569; padding: 3px 8px; border-radius: 4px;">
                                        <?php echo esc_html(str_replace('_', ' ', $log->event_type)); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight: 600; text-transform: uppercase; font-size: 11px; background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 3px 8px; border-radius: 4px;">
                                        <?php echo esc_html($log->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// 11. Custom Fonts Subpage Render
function ff_firebase_custom_fonts_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    wp_enqueue_media();
    $custom_fonts = get_option('ff_firebase_custom_fonts', array());
    if (!is_array($custom_fonts)) {
        $custom_fonts = array();
    }
    ?>
    <div class="wrap firebase-plugin-wrap" style="max-width: 800px;">
        <h1 class="wp-heading-inline">Global Custom Fonts Manager</h1>
        <hr class="wp-header-end">
        
        <?php if (isset($_GET['settings-updated'])) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p>Fonts library updated successfully!</p></div>
        <?php endif; ?>

        <p class="description" style="font-size: 13px; color: #cccccc; margin-bottom: 25px; line-height: 1.5; margin-top: 15px;">
            Upload your custom web fonts (.woff2, .woff, .ttf, .otf) here. Once added, these fonts will load globally on your site and will appear in the Gutenberg Block Editor typography selectors for pages and posts.
        </p>

        <form action="" method="post">
            <?php wp_nonce_field('ff_custom_font_action', 'ff_custom_font_nonce'); ?>
            <input type="hidden" name="ff_action_custom_font_page" value="1">
            <input type="hidden" id="ff_delete_font_name" name="ff_delete_font_name" value="">

            <!-- Registered Fonts Table -->
            <div class="card" style="margin-top: 20px; padding: 20px; border-radius: 4px; border: 1px solid #4a4a4a; background: #2a2a2a; box-shadow: none;">
                <h2 style="font-size: 18px; font-weight: 700; color: #ffffff; margin-top: 0; margin-bottom: 15px; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px;">Registered Fonts Library</h2>
                <?php if (empty($custom_fonts)) : ?>
                    <p style="font-size:13px; font-style:italic; color:#cccccc; margin: 0;">No custom fonts uploaded yet.</p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped table-view-list" style="border: 0; box-shadow: none; margin: 0;">
                        <thead>
                            <tr>
                                <th style="font-weight: 600; padding: 8px 10px; width: 200px; color: #ffffff;">Font Name</th>
                                <th style="font-weight: 600; padding: 8px 10px; color: #ffffff;">Font File URL</th>
                                <th style="font-weight: 600; padding: 8px 10px; width: 80px; text-align: right; color: #ffffff;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($custom_fonts as $name => $url) : ?>
                                <tr>
                                    <td style="padding: 10px; font-weight: 600; color: #ffffff !important;"><?php echo esc_html($name); ?></td>
                                    <td style="padding: 10px; color: #cccccc !important; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo esc_html($url); ?></td>
                                    <td style="padding: 10px; text-align: right;">
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=firebase_custom_fonts&action=delete&font_name=' . urlencode($name)), 'delete_font_' . $name); ?>" style="color:#ff3333; text-decoration:none; font-weight:600; font-size:12px;" onclick="return confirm('Are you sure you want to delete this font?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Upload & Register Card -->
            <div class="card" style="margin-top: 20px; padding: 20px; border-radius: 4px; border: 1px solid #4a4a4a; background: #2a2a2a; box-shadow: none;">
                <h2 style="font-size: 18px; font-weight: 700; color: #ffffff; margin-top: 0; margin-bottom: 15px; border-bottom: 1px solid #4a4a4a; padding-bottom: 10px;">Upload & Register Font</h2>
                
                <div style="margin-bottom: 15px;">
                    <label style="display:block; font-weight: 600; margin-bottom: 5px; color:#ffffff;">Font File (.woff2, .woff, .ttf, .otf)</label>
                    <div style="display:flex; gap:8px; max-width: 600px;">
                        <input type="text" id="ff_new_font_url" name="ff_new_font_url" placeholder="Upload or paste font URL..." style="flex:1; border-radius:4px; padding:6px 10px;" required>
                        <button type="button" class="button ff-select-font-btn" style="background:#78951D; border-color:#78951D; color:#fff; font-weight:600; text-shadow:none;">Select File</button>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display:block; font-weight: 600; margin-bottom: 5px; color:#ffffff;">Font Name</label>
                    <input type="text" id="ff_new_font_name" name="ff_new_font_name" placeholder="e.g. Outfit Bold (Auto-filled on select)" style="width:100%; max-width: 600px; border-radius:4px; padding:6px 10px;" required>
                </div>

                <button type="submit" name="ff_add_custom_font" value="1" class="button button-primary" style="background:#78951D; border-color:#78951D; font-weight:700; text-shadow:none; color: #ffffff;">Add Font to Library</button>
            </div>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // WP Media Uploader jQuery binding
        $(document).on('click', '.ff-select-font-btn', function(e) {
            e.preventDefault();
            var targetInput = $('#ff_new_font_url');
            var fontNameInput = $('#ff_new_font_name');

            var custom_uploader = wp.media({
                title: 'Select Font File',
                button: {
                    text: 'Use Selected Font'
                },
                multiple: false
            }).on('select', function() {
                var attachment = custom_uploader.state().get('selection').first().toJSON();
                targetInput.val(attachment.url).trigger('input');
                
                if (fontNameInput.length && !fontNameInput.val()) {
                    var filename = attachment.filename || '';
                    var dotIdx = filename.lastIndexOf('.');
                    if (dotIdx > 0) {
                        filename = filename.substring(0, dotIdx);
                    }
                    var friendlyName = filename.replace(/[-_]/g, ' ')
                        .replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                    fontNameInput.val(friendlyName);
                }
            }).open();
        });

        // Auto-extract font name on manual paste
        $('#ff_new_font_url').on('input', function() {
            var url = $(this).val();
            var fontNameInput = $('#ff_new_font_name');
            if (url && fontNameInput.length && !fontNameInput.val()) {
                var filename = url.substring(url.lastIndexOf('/') + 1);
                var qIdx = filename.indexOf('?');
                if (qIdx > 0) {
                    filename = filename.substring(0, qIdx);
                }
                var dotIdx = filename.lastIndexOf('.');
                if (dotIdx > 0) {
                    filename = filename.substring(0, dotIdx);
                }
                var friendlyName = decodeURIComponent(filename)
                    .replace(/[-_]/g, ' ')
                    .replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                fontNameInput.val(friendlyName);
            }
        });
    });
    </script>
    <?php
}

// 12. Helper Gutenberg Block Editor & Head font-face enqueuers
function ff_firebase_print_custom_font_styles() {
    $custom_fonts = get_option('ff_firebase_custom_fonts', array());
    if (!empty($custom_fonts) && is_array($custom_fonts)) {
        echo '<style id="ff-firebase-global-custom-fonts">' . "\n";
        foreach ($custom_fonts as $name => $url) {
            echo "@font-face { font-family: '" . esc_html($name) . "'; src: url('" . esc_url($url) . "'); font-display: swap; }\n";
        }
        echo '</style>' . "\n";
    }
}

function ff_firebase_enqueue_editor_custom_fonts() {
    $custom_fonts = get_option('ff_firebase_custom_fonts', array());
    if (!empty($custom_fonts) && is_array($custom_fonts)) {
        $css = '';
        foreach ($custom_fonts as $name => $url) {
            $css .= "@font-face { font-family: '" . esc_html($name) . "'; src: url('" . esc_url($url) . "'); font-display: swap; }\n";
        }
        // Register virtual style handle for block editor assets
        wp_register_style('ff-firebase-custom-fonts-editor-css', false);
        wp_enqueue_style('ff-firebase-custom-fonts-editor-css');
        wp_add_inline_style('ff-firebase-custom-fonts-editor-css', $css);

        // Fallback for classic editor styling hooks
        wp_add_inline_style('wp-edit-blocks', $css);
    }
}

function ff_firebase_inject_fonts_helper(&$target_array, $new_fonts) {
    if (!is_array($target_array)) {
        $target_array = array();
    }
    
    // Force empty array to be initialized as grouped
    if (empty($target_array)) {
        $target_array = array(
            'theme'   => array(),
            'default' => array(),
            'custom'  => array()
        );
    }
    
    // Convert flat array to grouped format (under theme preset key)
    $is_nested = isset($target_array['theme']) || isset($target_array['default']) || isset($target_array['custom']);
    if (!$is_nested) {
        $flat_fonts = $target_array;
        $target_array = array(
            'theme'   => $flat_fonts,
            'default' => array(),
            'custom'  => array()
        );
    }
    
    if (!isset($target_array['custom']) || !is_array($target_array['custom'])) {
        $target_array['custom'] = array();
    }
    
    // Merge into custom group avoiding duplicate slugs
    $existing_slugs = array();
    foreach ($target_array['custom'] as $font) {
        if (is_array($font) && isset($font['slug'])) {
            $existing_slugs[] = $font['slug'];
        }
    }
    
    // Check theme and default lists to prevent duplicate slugs
    if (isset($target_array['theme']) && is_array($target_array['theme'])) {
        foreach ($target_array['theme'] as $font) {
            if (is_array($font) && isset($font['slug'])) {
                $existing_slugs[] = $font['slug'];
            }
        }
    }
    if (isset($target_array['default']) && is_array($target_array['default'])) {
        foreach ($target_array['default'] as $font) {
            if (is_array($font) && isset($font['slug'])) {
                $existing_slugs[] = $font['slug'];
            }
        }
    }

    foreach ($new_fonts as $font) {
        if (!in_array($font['slug'], $existing_slugs)) {
            $target_array['custom'][] = $font;
        }
    }
}

function ff_firebase_add_custom_fonts_to_gutenberg($editor_settings, $editor_context) {
    $custom_fonts = get_option('ff_firebase_custom_fonts', array());
    if (!empty($custom_fonts) && is_array($custom_fonts)) {
        $new_fonts = array();
        foreach ($custom_fonts as $name => $url) {
            $new_fonts[] = array(
                'fontFamily' => esc_attr($name),
                'name'       => esc_html($name),
                'slug'       => sanitize_title($name),
            );
        }

        // Only modify the official canonical key: settings.typography.fontFamilies
        if (!isset($editor_settings['settings'])) {
            $editor_settings['settings'] = array();
        }
        if (!isset($editor_settings['settings']['typography'])) {
            $editor_settings['settings']['typography'] = array();
        }
        if (!isset($editor_settings['settings']['typography']['fontFamilies'])) {
            $editor_settings['settings']['typography']['fontFamilies'] = array();
        }
        
        ff_firebase_inject_fonts_helper($editor_settings['settings']['typography']['fontFamilies'], $new_fonts);
    }
    return $editor_settings;
}

function ff_firebase_add_custom_fonts_to_theme_json($theme_json) {
    $custom_fonts = get_option('ff_firebase_custom_fonts', array());
    if (!empty($custom_fonts) && is_array($custom_fonts)) {
        $data = $theme_json->get_data();
        $version = isset($data['version']) ? $data['version'] : 2;

        // Retrieve existing font families and safely flatten them if they are grouped (theme, default, custom keys)
        $existing_fonts = array();
        if (isset($data['settings']['typography']['fontFamilies'])) {
            $existing = $data['settings']['typography']['fontFamilies'];
            if (is_array($existing)) {
                $is_nested = isset($existing['theme']) || isset($existing['default']) || isset($existing['custom']);
                if ($is_nested) {
                    foreach (array('theme', 'default', 'custom') as $group) {
                        if (isset($existing[$group]) && is_array($existing[$group])) {
                            $existing_fonts = array_merge($existing_fonts, $existing[$group]);
                        }
                    }
                } else {
                    $existing_fonts = $existing;
                }
            }
        }

        // Prepare our new custom fonts without fontFace (prevents parse/schema crashes with absolute upload URLs)
        $new_fonts = array();
        foreach ($custom_fonts as $name => $url) {
            $new_fonts[] = array(
                'fontFamily' => esc_attr($name),
                'name'       => esc_html($name),
                'slug'       => sanitize_title($name),
            );
        }

        // Merge, avoiding duplicates by slug
        $merged_fonts = $existing_fonts;
        $existing_slugs = array();
        foreach ($existing_fonts as $font) {
            if (is_array($font) && isset($font['slug'])) {
                $existing_slugs[] = $font['slug'];
            }
        }

        foreach ($new_fonts as $font) {
            if (!in_array($font['slug'], $existing_slugs)) {
                $merged_fonts[] = $font;
            }
        }

        $new_data = array(
            'version'  => $version,
            'settings' => array(
                'typography' => array(
                    'fontFamilies' => $merged_fonts,
                ),
            ),
        );

        return $theme_json->update_with($new_data);
    }
    return $theme_json;
}

// 13. Enable Custom Font Uploads in Media Library & Bypass Server Mime Type Restrictions
function ff_firebase_allow_font_uploads($mimes) {
    $mimes['ttf']   = 'font/ttf';
    $mimes['otf']   = 'font/otf';
    $mimes['woff']  = 'font/woff';
    $mimes['woff2'] = 'font/woff2';
    $mimes['eot']   = 'application/vnd.ms-fontobject';
    return $mimes;
}

function ff_firebase_fix_font_mime_types($data, $file, $filename, $mimes, $real_mime) {
    if (!empty($data['ext']) && !empty($data['type'])) {
        return $data;
    }

    $wp_file_type = wp_check_filetype($filename, $mimes);
    $ext = $wp_file_type['ext'];

    $font_mimes = array(
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'eot'   => 'application/vnd.ms-fontobject'
    );

    if (array_key_exists($ext, $font_mimes)) {
        $data['ext']  = $ext;
        $data['type'] = $font_mimes[$ext];
    }

    return $data;
}