<?php
/**
 * Plugin Name: Fluent Forms to Firebase Firestore
 * Description: Standalone premium form builder and manager. Stores submissions in local custom database, syncs to Firebase, automatically detects and sends brand-matching Admin and Client confirmation emails via custom SMTP with socket anti-collision delay, provides a customizable form shortcode [firebase_contact_form], and renders a premium admin dashboard.
 * Version: 4.6.0
 * Author: Antigravity AI
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

// Dynamically wraps a simple text/HTML email body in a responsive, branded HTML email template featuring custom colors, logo, and footer
function ff_firebase_wrap_email_body($body, $form_settings = array()) {
    $options = get_option('ff_firebase_settings', array());
    if (!is_array($options)) {
        $options = array();
    }
    
    // Determine visual style overrides
    $bg_color = !empty($form_settings['email_bg_color']) ? $form_settings['email_bg_color'] : (!empty($options['email_bg_color']) ? $options['email_bg_color'] : '#f3f4f6');
    $header_bg = !empty($form_settings['email_header_bg_color']) ? $form_settings['email_header_bg_color'] : (!empty($options['email_header_bg_color']) ? $options['email_header_bg_color'] : '#000000');
    $accent_color = !empty($form_settings['email_accent_color']) ? $form_settings['email_accent_color'] : (!empty($options['email_accent_color']) ? $options['email_accent_color'] : '#d71921');
    $logo_url = !empty($form_settings['email_logo']) ? $form_settings['email_logo'] : (!empty($options['email_logo']) ? $options['email_logo'] : '');
    $footer_text = !empty($form_settings['email_footer_text']) ? $form_settings['email_footer_text'] : (!empty($options['email_footer_text']) ? $options['email_footer_text'] : "<strong>ENGIN DENIZ Lawyers for Real Estate Law GmbH</strong><br>Marc-Aurel-Straße 6/5, 1010 Vienna<br>t +43 1 514 30 | f +43 1 514 30 9 | lawfirm@engin-deniz.com");

    $logo_html = '';
    if (!empty($logo_url)) {
        $logo_html = '<img src="' . esc_url($logo_url) . '" alt="Brand Logo" style="max-height:60px; max-width:100%; border:none; display:inline-block; vertical-align:middle;">';
    } else {
        $logo_html = '<h2 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">ENGIN <span style="color: ' . esc_attr($accent_color) . ';">DENIZ</span></h2>
        <p style="margin: 5px 0 0 0; color: #9ca3af; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">Rechtsanwälte / Lawyers</p>';
    }

    // Convert plain text newlines to paragraphs safely if not already HTML
    if (!preg_match('/<[a-z][\s\S]*>/i', $body)) {
        $body = wpautop($body);
    }

    $template = '<div style="background-color: ' . esc_attr($bg_color) . '; padding: 30px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
  <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 5px solid ' . esc_attr($accent_color) . ';">
    <div style="background-color: ' . esc_attr($header_bg) . '; padding: 25px; text-align: center;">
      ' . $logo_html . '
    </div>
    <div style="padding: 30px; font-size: 14px;">
      ' . $body . '
    </div>
    <div style="background-color: #fafafa; padding: 25px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb; line-height: 1.5;">
      ' . nl2br($footer_text) . '
    </div>
  </div>
</div>';

    return $template;
}

// Renders the dedicated Visual Styling Design panel to customize background, header, logo, and footer
function ff_firebase_render_visual_styling_card($prefix, $settings) {
    $bg_color = isset($settings['email_bg_color']) ? $settings['email_bg_color'] : '#f3f4f6';
    $header_bg = isset($settings['email_header_bg_color']) ? $settings['email_header_bg_color'] : '#000000';
    $accent_color = isset($settings['email_accent_color']) ? $settings['email_accent_color'] : '#d71921';
    $logo_url = isset($settings['email_logo']) ? $settings['email_logo'] : '';
    $footer_text = isset($settings['email_footer_text']) ? $settings['email_footer_text'] : "ENGIN DENIZ Lawyers for Real Estate Law GmbH\nMarc-Aurel-Straße 6/5, 1010 Vienna\nt +43 1 514 30 | f +43 1 514 30 9 | lawfirm@engin-deniz.com";

    ?>
    <div class="card ff-visual-styling-card" style="max-width: 100%; padding: 20px; margin-top: 20px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px; border-left: 4px solid #2271b1 !important;">
        <h3 style="margin-top: 0; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327; display: flex; align-items: center; gap: 6px;">
            <span class="dashicons dashicons-art" style="color:#2271b1; font-size: 18px; width: 18px; height: 18px;"></span> Visual Email Template Design
        </h3>
        <p class="description" style="margin-bottom: 20px;">Customize colors, brand logo, and footer design of the email layout visually without coding or raw HTML structures.</p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row" style="width: 25%;"><label>Container Background Color</label></th>
                    <td>
                        <input type="color" name="<?php echo $prefix; ?>[email_bg_color]" value="<?php echo esc_attr($bg_color); ?>" style="vertical-align: middle; width:50px; height:30px; border-radius:4px; border:1px solid #ccd0d4; padding:0; cursor:pointer;" class="ff-color-picker-input">
                        <input type="text" value="<?php echo esc_attr($bg_color); ?>" style="width: 100px; vertical-align: middle; margin-left: 8px; font-family: monospace;" readonly class="ff-color-picker-text">
                        <span class="description" style="margin-left: 15px;">Background surrounding the email content box.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Header Background Color</label></th>
                    <td>
                        <input type="color" name="<?php echo $prefix; ?>[email_header_bg_color]" value="<?php echo esc_attr($header_bg); ?>" style="vertical-align: middle; width:50px; height:30px; border-radius:4px; border:1px solid #ccd0d4; padding:0; cursor:pointer;" class="ff-color-picker-input">
                        <input type="text" value="<?php echo esc_attr($header_bg); ?>" style="width: 100px; vertical-align: middle; margin-left: 8px; font-family: monospace;" readonly class="ff-color-picker-text">
                        <span class="description" style="margin-left: 15px;">Background of the top logo header banner.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Theme Accent Color</label></th>
                    <td>
                        <input type="color" name="<?php echo $prefix; ?>[email_accent_color]" value="<?php echo esc_attr($accent_color); ?>" style="vertical-align: middle; width:50px; height:30px; border-radius:4px; border:1px solid #ccd0d4; padding:0; cursor:pointer;" class="ff-color-picker-input">
                        <input type="text" value="<?php echo esc_attr($accent_color); ?>" style="width: 100px; vertical-align: middle; margin-left: 8px; font-family: monospace;" readonly class="ff-color-picker-text">
                        <span class="description" style="margin-left: 15px;">Used for top border header line and email highlights.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Brand Logo Image</label></th>
                    <td>
                        <div style="display: flex; gap: 10px; align-items: center; max-width: 500px;">
                            <input type="text" id="<?php echo $prefix; ?>_email_logo" name="<?php echo $prefix; ?>[email_logo]" value="<?php echo esc_attr($logo_url); ?>" class="regular-text ff-logo-url-field" style="flex:1;">
                            <button type="button" class="button ff-upload-logo-btn" data-target="<?php echo $prefix; ?>_email_logo">Select Logo</button>
                            <button type="button" class="button button-link-delete ff-clear-logo-btn" style="color:#b32d2e; text-decoration:none;" onclick="jQuery('#<?php echo $prefix; ?>_email_logo').val('').trigger('change');">Clear</button>
                        </div>
                        <p class="description">Select a logo image from the media library. If blank, standard text logo is displayed.</p>
                        <div class="ff-logo-preview-box" style="margin-top: 10px; padding: 10px; border: 1px solid #ccd0d4; border-radius:4px; display: <?php echo empty($logo_url) ? 'none' : 'block'; ?>; background: #eaeaea; max-width: 200px; text-align: center;">
                            <img src="<?php echo esc_url($logo_url); ?>" style="max-height: 50px; max-width: 100%;" class="ff-logo-preview-img">
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label>Email Footer Text</label></th>
                    <td>
                        <textarea name="<?php echo $prefix; ?>[email_footer_text]" rows="4" class="large-text" placeholder="e.g. Company Address and Contact Details" style="font-family: inherit; font-size: 13px;"><?php echo esc_textarea($footer_text); ?></textarea>
                        <p class="description">Contact details, address info, or legal text displayed at the bottom of the email.</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
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

function ff_firebase_add_admin_menu() {
    // Parent Page: Submissions
    add_menu_page(
        'Form Submissions',
        'Submissions',
        'manage_options',
        'firebase_submissions',
        'ff_firebase_submissions_page',
        'dashicons-feedback',
        25
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
}

function ff_firebase_settings_init() {
    register_setting('ffFirebasePlugin', 'ff_firebase_settings');
}

// 3b. Custom safe settings POST handler to merge and save settings page-by-page
function ff_firebase_save_admin_settings() {
    global $wpdb;
    $forms_table = $wpdb->prefix . 'firebase_forms';

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

        // Sanitize other settings fields (keeping templates HTML intact)
        $sanitized_settings = array();
        foreach ($form_settings as $key => $val) {
            if ($key === 'email_template' || $key === 'client_email_template') {
                $sanitized_settings[$key] = $val; // Keep HTML templates intact
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

    if (isset($_POST['ff_firebase_save_settings_nonce']) && wp_verify_nonce($_POST['ff_firebase_save_settings_nonce'], 'ff_firebase_save_settings_action')) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $existing = get_option('ff_firebase_settings', array());
        if (!is_array($existing)) {
            $existing = array();
        }

        if (isset($_POST['ff_firebase_settings']) && is_array($_POST['ff_firebase_settings'])) {
            $submitted = $_POST['ff_firebase_settings'];
            $current_page = isset($_POST['ff_current_settings_page']) ? sanitize_text_field($_POST['ff_current_settings_page']) : '';

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

            // Merge and sanitize other fields
            foreach ($submitted as $key => $val) {
                if ($key === 'form_fields_json' || $key === 'email_template' || $key === 'client_email_template') {
                    $existing[$key] = $val; // Keep JSON and HTML templates intact
                } else {
                    $existing[$key] = sanitize_text_field($val);
                }
            }

            update_option('ff_firebase_settings', $existing);

            // Redirect back with settings-updated = true
            wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
            exit;
        }
    }
}

// 3b-extra. Premium Click & Drag-Drop Placeholder Tag Bar Helper for Emails
function ff_firebase_render_email_tag_bar($textarea_id) {
    ?>
    <div class="ff-email-tag-bar-container" style="margin-bottom: 12px; font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #fafafa; border: 1px solid #ccd0d4; padding: 15px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,.03); border-left: 4px solid #2271b1 !important;">
        <div style="font-weight: 700; font-size: 13px; color: #1d2327; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
            <span class="dashicons dashicons-tag" style="font-size: 18px; width: 18px; height: 18px; color: #2271b1; margin-top: 1px;"></span>
            Click or Drag simple blocks into your template below:
        </div>
        <div class="ff-tag-pills" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 15px;">
            <span class="ff-tag-pill" draggable="true" data-tag="{name}" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; cursor: grab; user-select: none; box-shadow: 0 2px 4px rgba(0,0,0,0.08); transition: all 0.15s; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 6px rgba(0,0,0,0.12)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.08)'">🧑 Name</span>
            <span class="ff-tag-pill" draggable="true" data-tag="{email}" style="background: linear-gradient(135deg, #10b981, #047857); color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; cursor: grab; user-select: none; box-shadow: 0 2px 4px rgba(0,0,0,0.08); transition: all 0.15s; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 6px rgba(0,0,0,0.12)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.08)'">✉️ Email</span>
            <span class="ff-tag-pill" draggable="true" data-tag="{address}" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; cursor: grab; user-select: none; box-shadow: 0 2px 4px rgba(0,0,0,0.08); transition: all 0.15s; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 6px rgba(0,0,0,0.12)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.08)'">🏠 Address</span>
            <span class="ff-tag-pill" draggable="true" data-tag="{plz_ort}" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; cursor: grab; user-select: none; box-shadow: 0 2px 4px rgba(0,0,0,0.08); transition: all 0.15s; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 6px rgba(0,0,0,0.12)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.08)'">📍 PLZ / Ort</span>
            <span class="ff-tag-pill" draggable="true" data-tag="{submitted_at}" style="background: linear-gradient(135deg, #ec4899, #be185d); color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; cursor: grab; user-select: none; box-shadow: 0 2px 4px rgba(0,0,0,0.08); transition: all 0.15s; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 6px rgba(0,0,0,0.12)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.08)'">📅 Submitted At</span>
        </div>
        <div class="ff-template-actions" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <button type="button" class="button ff-load-simple-text" data-textarea="<?php echo $textarea_id; ?>" style="background: #ffffff; border-color: #ccd0d4; font-weight: 600; font-size: 12px; height: 30px; line-height: 28px; border-radius: 4px;">📝 Simple Words Template</button>
            <button type="button" class="button ff-load-premium-branded" data-textarea="<?php echo $textarea_id; ?>" style="background: #f0f6fc; border-color: #ccd0d4; color: #2271b1; font-weight: 600; font-size: 12px; height: 30px; line-height: 28px; border-radius: 4px;">✨ Premium Branded Template</button>
            <button type="button" class="button button-primary ff-preview-email" data-textarea="<?php echo $textarea_id; ?>" style="background: #000000; border-color: #000000; font-weight: 700; font-size: 12px; height: 30px; line-height: 28px; border-radius: 4px; color: #fff;">👁️ Live Preview Email</button>
            <button type="button" class="button button-link-delete ff-clear-editor" data-textarea="<?php echo $textarea_id; ?>" style="color: #b32d2e; font-weight: 600; text-decoration: none; font-size: 12px; margin-left: auto;">❌ Clear Editor</button>
        </div>
    </div>
    <?php
    static $ff_email_scripts_printed = false;
    if (!$ff_email_scripts_printed) {
        $ff_email_scripts_printed = true;
        ?>
        <style>
            .ff-tag-pill {
                cursor: grab;
            }
            .ff-tag-pill:active {
                cursor: grabbing;
                opacity: 0.8;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            // Drag Start
            $(document).on('dragstart', '.ff-tag-pill', function(e) {
                var tag = $(this).data('tag');
                e.originalEvent.dataTransfer.setData('text/plain', tag);
            });

            // Click to Insert
            $(document).on('click', '.ff-tag-pill', function(e) {
                e.preventDefault();
                var tag = $(this).data('tag');
                var container = $(this).closest('.ff-email-tag-bar-container');
                var textarea = container.next('textarea');
                if (!textarea.length) {
                    textarea = container.closest('td').find('textarea');
                }
                if (textarea.length) {
                    insertAtCursor(textarea[0], tag);
                }
            });

            // Drop Event on Textareas
            $(document).on('dragover', '#email_template, #client_email_template, #ff_email_template, #ff_client_email_template', function(e) {
                e.preventDefault();
            });

            $(document).on('drop', '#email_template, #client_email_template, #ff_email_template, #ff_client_email_template', function(e) {
                e.preventDefault();
                var tag = e.originalEvent.dataTransfer.getData('text/plain');
                if (tag && tag.startsWith('{') && tag.endsWith('}')) {
                    insertAtCursor(this, tag);
                }
            });

            function insertAtCursor(myField, myValue) {
                if (document.selection) {
                    myField.focus();
                    sel = document.selection.createRange();
                    sel.text = myValue;
                } else if (myField.selectionStart || myField.selectionStart == '0') {
                    var startPos = myField.selectionStart;
                    var endPos = myField.selectionEnd;
                    myField.value = myField.value.substring(0, startPos)
                        + myValue
                        + myField.value.substring(endPos, myField.value.length);
                    myField.focus();
                    myField.selectionStart = startPos + myValue.length;
                    myField.selectionEnd = startPos + myValue.length;
                } else {
                    myField.value += myValue;
                    myField.focus();
                }
                $(myField).trigger('change');
            }

            // Load Simple Text Template
            $(document).on('click', '.ff-load-simple-text', function(e) {
                e.preventDefault();
                var txId = $(this).data('textarea');
                var textarea = document.getElementById(txId);
                if (!textarea) return;
                
                var isClient = txId.toLowerCase().includes('client');
                var template = "";
                if (isClient) {
                    template = "Sehr geehrte(r) {name},\n\nvielen Dank für Ihre Registrierung über unsere Website.\n\nWir haben Ihre Angaben erfolgreich erhalten:\n- Name: {name}\n- E-Mail: {email}\n- Adresse: {address}\n- PLZ / Ort: {plz_ort}\n- Datum: {submitted_at}\n\nUnser Team wird sich in Kürze persönlich bei Ihnen melden.\n\nMit freundlichen Grüßen,\nENGIN DENIZ Rechtsanwälte";
                } else {
                    template = "Hallo Admin,\n\neine neue Registrierung wurde eingereicht:\n\n- Name: {name}\n- E-Mail: {email}\n- Adresse: {address}\n- PLZ / Ort: {plz_ort}\n- Datum: {submitted_at}\n\nBitte prüfen Sie die Details im WordPress Dashboard.\n\nMit freundlichen Grüßen,\nWeb Manager";
                }
                
                if (confirm("Are you sure you want to replace your current template with a Simple Words text layout?")) {
                    textarea.value = template;
                    $(textarea).trigger('change');
                }
            });

            // WordPress Media Library Uploader for Email Logo
            $(document).on('click', '.ff-upload-logo-btn', function(e) {
                e.preventDefault();
                var button = $(this);
                var targetId = button.data('target');
                var targetInput = $('#' + targetId);
                
                var file_frame = wp.media.frames.file_frame = wp.media({
                    title: 'Select or Upload Brand Logo',
                    button: {
                        text: 'Use this Logo'
                    },
                    multiple: false
                });

                file_frame.on('select', function() {
                    var attachment = file_frame.state().get('selection').first().toJSON();
                    targetInput.val(attachment.url).trigger('change');
                });

                file_frame.open();
            });

            // Update Logo preview real-time
            $(document).on('change keyup input', '.ff-logo-url-field', function() {
                var val = $(this).val();
                var previewBox = $(this).closest('td').find('.ff-logo-preview-box');
                var previewImg = $(this).closest('td').find('.ff-logo-preview-img');
                if (val) {
                    previewImg.attr('src', val);
                    previewBox.show();
                } else {
                    previewBox.hide();
                }
            });

            // Sync Color pickers
            $(document).on('input change', '.ff-color-picker-input', function() {
                var val = $(this).val();
                $(this).next('.ff-color-picker-text').val(val);
            });

            // Load Premium Branded Template
            $(document).on('click', '.ff-load-premium-branded', function(e) {
                e.preventDefault();
                var txId = $(this).data('textarea');
                var textarea = document.getElementById(txId);
                if (!textarea) return;
                
                var isClient = txId.toLowerCase().includes('client');
                var template = isClient ? getPremiumClientTemplate() : getPremiumAdminTemplate();
                
                if (confirm("Are you sure you want to replace your current template with a clean plain text premium layout?")) {
                    textarea.value = template;
                    $(textarea).trigger('change');
                }
            });

            // Clear Editor
            $(document).on('click', '.ff-clear-editor', function(e) {
                e.preventDefault();
                var txId = $(this).data('textarea');
                var textarea = document.getElementById(txId);
                if (!textarea) return;
                if (confirm("Are you sure you want to clear the editor?")) {
                    textarea.value = "";
                    $(textarea).trigger('change');
                }
            });

            // Live Email Preview with visual wrapper compiler enfolded
            $(document).on('click', '.ff-preview-email', function(e) {
                e.preventDefault();
                var txId = $(this).data('textarea');
                var textarea = document.getElementById(txId);
                if (!textarea) return;
                
                var htmlContent = textarea.value;
                if (!htmlContent.trim()) {
                    alert("The template is empty. Write some text or load a template first!");
                    return;
                }

                // Scrape Visual Design settings in active panel or page context
                var parentContext = $(textarea).closest('.ff-panel-content, .card, .wrap');
                var bgColor = parentContext.find('input[name*="[email_bg_color]"]').val() || '#f3f4f6';
                var headerBg = parentContext.find('input[name*="[email_header_bg_color]"]').val() || '#000000';
                var accentColor = parentContext.find('input[name*="[email_accent_color]"]').val() || '#d71921';
                var logoUrl = parentContext.find('input[name*="[email_logo]"]').val() || '';
                var footerText = parentContext.find('textarea[name*="[email_footer_text]"]').val() || "ENGIN DENIZ Lawyers for Real Estate Law GmbH\nMarc-Aurel-Straße 6/5, 1010 Vienna";

                // Format simple text content with simulated paragraph wrappers
                var bodyText = htmlContent;
                if (!/<[a-z][\s\S]*>/i.test(bodyText)) {
                    bodyText = bodyText.split(/\r?\n\r?\n/).map(function(p) {
                        return '<p style="margin: 0 0 1.5em 0;">' + p.replace(/\r?\n/g, '<br>') + '</p>';
                    }).join('');
                }

                // Dynamic replacements
                bodyText = bodyText
                    .replace(/{name}/g, "Dr. Adeel Chaudhry")
                    .replace(/{email}/g, "super.adeel.123@gmail.com")
                    .replace(/{address}/g, "Marc-Aurel-Straße 6/5")
                    .replace(/{plz_ort}/g, "1010 Wien")
                    .replace(/{submitted_at}/g, new Date().toLocaleString());

                // Build Logo element
                var logoHtml = '';
                if (logoUrl) {
                    logoHtml = '<img src="' + escapeHtml(logoUrl) + '" alt="Brand Logo" style="max-height:60px; max-width:100%; border:none; display:inline-block; vertical-align:middle;">';
                } else {
                    logoHtml = '<h2 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">ENGIN <span style="color: ' + accentColor + ';">DENIZ</span></h2>' +
                    '<p style="margin: 5px 0 0 0; color: #9ca3af; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">Rechtsanwälte / Lawyers</p>';
                }

                // Compile into standard responsive template mockup enfolded
                var previewHtml = '<div style="background-color: ' + bgColor + '; padding: 30px; font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">' +
                  '<div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-top: 5px solid ' + accentColor + ';">' +
                    '<div style="background-color: ' + headerBg + '; padding: 25px; text-align: center;">' +
                      logoHtml +
                    '</div>' +
                    '<div style="padding: 30px; font-size: 14px;">' +
                      bodyText +
                    '</div>' +
                    '<div style="background-color: #fafafa; padding: 25px; text-align: center; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb; line-height: 1.5; white-space: pre-wrap;">' +
                      escapeHtml(footerText).replace(/\n/g, '<br>') +
                    '</div>' +
                  '</div>' +
                '</div>';

                var modal = $('<div id="ff_email_preview_modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:999999; display:flex; align-items:center; justify-content:center;">' +
                    '<div style="background:#fff; border-radius:8px; width:700px; max-width:90%; height:80%; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.3); font-family:-apple-system,BlinkMacSystemFont,sans-serif;">' +
                        '<div style="background:#f6f7f7; border-bottom:1px solid #ccd0d4; padding:15px 20px; display:flex; justify-content:space-between; align-items:center;">' +
                            '<h3 style="margin:0; font-size:15px; color:#1d2327;">📧 Live Email Delivery Preview (Mock Data)</h3>' +
                            '<button type="button" class="ff-close-preview" style="background:none; border:none; cursor:pointer; font-size:20px; color:#646970;">&times;</button>' +
                        '</div>' +
                        '<div style="flex:1; background:#f3f4f6; padding:10px; box-sizing:border-box;">' +
                            '<iframe id="ff_preview_frame" style="width:100%; height:100%; border:none; background:#fff; border-radius:4px;"></iframe>' +
                        '</div>' +
                        '<div style="background:#f6f7f7; border-top:1px solid #ccd0d4; padding:12px 20px; text-align:right;">' +
                            '<button type="button" class="button button-secondary ff-close-preview">Close Preview</button>' +
                        '</div>' +
                    '</div>' +
                '</div>');

                $('body').append(modal);

                var doc = document.getElementById('ff_preview_frame').contentWindow.document;
                doc.open();
                doc.write(previewHtml);
                doc.close();

                $('.ff-close-preview').on('click', function() {
                    modal.remove();
                });
                
                $(document).on('keydown.ff_preview', function(e) {
                    if (e.key === 'Escape') {
                        modal.remove();
                        $(document).off('keydown.ff_preview');
                    }
                });
            });

            function escapeHtml(str) {
                return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
            }

            function getPremiumAdminTemplate() {
                return `<?php echo str_replace('`', '\`', ff_firebase_get_default_admin_template()); ?>`;
            }

            function getPremiumClientTemplate() {
                return `<?php echo str_replace('`', '\`', ff_firebase_get_default_client_template()); ?>`;
            }
        });
        </script>
        <?php
    }
}

// 3c. Unified Native Page Header (no custom branding, standard WP layout)
function ff_firebase_admin_page_header($title, $current_page) {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html($title); ?></h1>
        
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
        <div class="wrap">
            <h1 style="display:inline-block; margin-right:15px;">Edit Form: <?php echo esc_html($form_title); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=firebase_forms'); ?>" class="page-title-action" style="font-weight:600; border-color:#ccd0d4; color:#2271b1;"><span class="dashicons dashicons-arrow-left-alt2" style="font-size:16px; margin-top:2px; margin-right:4px;"></span> Back to Forms</a>
            
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
                border-color: #2271b1 !important;
                box-shadow: 0 0 0 1px #2271b1 !important;
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
                color: #2271b1;
                background: #f0f0f1;
            }
            .ff-tab-btn.active {
                color: #2271b1;
                border-bottom-color: #2271b1;
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
                border-color: #2271b1 !important;
                box-shadow: 0 0 0 1px #2271b1 !important;
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
                border-color: #2271b1;
                color: #2271b1;
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
                color: #2271b1;
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
                border-color: #2271b1 !important;
                box-shadow: 0 0 0 1px #2271b1 !important;
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
                    <?php submit_button('Save Form Configurations', 'primary', 'submit', false, array('style' => 'background:#2271b1; border-color:#2271b1; font-weight:600; padding:6px 24px; font-size:13px; min-height:36px; border-radius:4px;')); ?>
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
                    <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#2271b1; border-color:#2271b1; font-weight:600;')); ?>
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
                    <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#2271b1; border-color:#2271b1; font-weight:600;')); ?>
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
            $email_template = isset($form_settings['email_template']) ? $form_settings['email_template'] : '';
            ?>
            <div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Admin Notification Overrides</h3>
                <p class="description" style="margin-bottom: 20px;">Configure custom recipient and templates for admin notification alerts specifically for this form.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ff_email_enabled">Enable Admin Notifications</label></th>
                            <td>
                                <input type="checkbox" id="ff_email_enabled" name="ff_form_settings[email_enabled]" value="yes" <?php checked($email_enabled, 'yes'); ?>>
                                <span class="description">Receive an email alert when this form is submitted</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_email_to">Recipient Admin Email</label></th>
                            <td>
                                <input type="email" id="ff_email_to" name="ff_form_settings[email_to]" value="<?php echo esc_attr($email_to); ?>" class="regular-text" placeholder="e.g. admin@domain.com">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_email_subject">Email Subject</label></th>
                            <td>
                                <input type="text" id="ff_email_subject" name="ff_form_settings[email_subject]" value="<?php echo esc_attr($email_subject); ?>" class="regular-text" placeholder="Default: New Client Registration">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_email_template">HTML Body Template</label></th>
                            <td>
                                <?php ff_firebase_render_email_tag_bar('ff_email_template'); ?>
                                <?php 
                                wp_editor($email_template, 'ff_email_template', array(
                                    'textarea_name' => 'ff_form_settings[email_template]',
                                    'textarea_rows' => 12,
                                    'media_buttons' => true,
                                    'teeny'         => false,
                                    'quicktags'     => true
                                )); 
                                ?>
                                <p class="description">Available Placeholders: <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php ff_firebase_render_visual_styling_card('ff_form_settings', $form_settings); ?>
                <div style="margin-top: 25px;">
                    <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#2271b1; border-color:#2271b1; font-weight:600;')); ?>
                </div>
            </div>
        </div>

        <!-- Panel 5: Client Thank-You -->
        <div id="ff-client-email-panel" class="ff-panel-content" style="display:none;">
            <?php
            $client_email_enabled = isset($form_settings['client_email_enabled']) ? $form_settings['client_email_enabled'] : '';
            $client_email_subject = isset($form_settings['client_email_subject']) ? $form_settings['client_email_subject'] : '';
            $client_email_template = isset($form_settings['client_email_template']) ? $form_settings['client_email_template'] : '';
            ?>
            <div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px; box-sizing: border-box; background: #ffffff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 12px; color: #1d2327;">Client Confirmation / Thank-You Overrides</h3>
                <p class="description" style="margin-bottom: 20px;">Configure custom branded thank-you emails sent to clients specifically for this form's submissions.</p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ff_client_email_enabled">Enable Client Thank-You Emails</label></th>
                            <td>
                                <input type="checkbox" id="ff_client_email_enabled" name="ff_form_settings[client_email_enabled]" value="yes" <?php checked($client_email_enabled, 'yes'); ?>>
                                <span class="description">Send a thank-you/confirmation email directly to the client</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_client_email_subject">Email Subject</label></th>
                            <td>
                                <input type="text" id="ff_client_email_subject" name="ff_form_settings[client_email_subject]" value="<?php echo esc_attr($client_email_subject); ?>" class="regular-text" placeholder="e.g. Vielen Dank für Ihre Registrierung">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ff_client_email_template">HTML Body Template</label></th>
                            <td>
                                <?php ff_firebase_render_email_tag_bar('ff_client_email_template'); ?>
                                <?php 
                                wp_editor($client_email_template, 'ff_client_email_template', array(
                                    'textarea_name' => 'ff_form_settings[client_email_template]',
                                    'textarea_rows' => 12,
                                    'media_buttons' => true,
                                    'teeny'         => false,
                                    'quicktags'     => true
                                )); 
                                ?>
                                <p class="description">Available Placeholders: <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php ff_firebase_render_visual_styling_card('ff_form_settings', $form_settings); ?>
                <div style="margin-top: 25px;">
                    <?php submit_button('Save Form Settings', 'primary', 'submit', false, array('style' => 'background:#2271b1; border-color:#2271b1; font-weight:600;')); ?>
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
                                            <a href="#" class="ff-view-details-link" data-data="<?php echo esc_attr($sub->form_data); ?>" data-id="<?php echo $sub->id; ?>" data-name="<?php echo esc_attr($sub->name); ?>" data-email="<?php echo esc_attr($sub->email); ?>" style="color:#2271b1; font-weight:600; font-size:11px; text-decoration:none;">View Custom Fields</a>
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
                                            <a href="<?php echo esc_url($resync_url); ?>" class="resync-link" style="color: #2271b1 !important; text-decoration: none; font-weight: 500;" title="Retry syncing to Firebase">Resync</a>
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
                
                // Dynamic TinyMCE re-init for dynamic tabs rendering
                if (target === 'ff-admin-email-panel' || target === 'ff-client-email-panel') {
                    var editorId = (target === 'ff-admin-email-panel') ? 'ff_email_template' : 'ff_client_email_template';
                    if (window.tinymce && window.tinymce.get(editorId)) {
                        window.tinymce.execCommand('mceRemoveEditor', true, editorId);
                        window.tinymce.execCommand('mceAddEditor', true, editorId);
                    }
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
                            inputHtml = '<div style="display:flex; gap:8px;"><div style="width:24px; height:24px; border:1px solid #ccd0d4; border-radius:4px; background:#2271b1; flex-shrink:0;"></div><input type="text" class="ff-canvas-card-preview" value="#2271b1" disabled></div>';
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
                            inputHtml = '<div style="display:flex; align-items:center; gap:10px; margin-top:8px;"><span style="font-size:11px; color:#8c8f94;">Min: 0</span><div style="flex:1; height:4px; background:#ccd0d4; position:relative; border-radius:2px;"><div style="position:absolute; left:30%; width:12px; height:12px; border-radius:50%; background:#2271b1; top:-4px; box-shadow:0 1px 3px rgba(0,0,0,0.2);"></div></div><span style="font-size:11px; color:#8c8f94;">Max: 100</span></div>';
                        } else if (field.type.indexOf('column_') === 0) {
                            var colsCount = parseInt(field.type.replace('column_', ''), 10);
                            var colsMarkup = '';
                            for (var c = 1; c <= colsCount; c++) {
                                colsMarkup += '<div class="ff-mock-col-cell">Col ' + c + '</div>';
                            }
                            inputHtml = '<div class="ff-mock-columns">' + colsMarkup + '</div>';
                        } else if (field.type === 'recaptcha' || field.type === 'hcaptcha' || field.type === 'turnstile') {
                            var providerText = (field.type === 'recaptcha') ? 'reCAPTCHA Verification' : ((field.type === 'hcaptcha') ? 'hCaptcha Protection' : 'Cloudflare Turnstile Badge');
                            inputHtml = '<div class="ff-mock-captcha"><span class="dashicons dashicons-shield"></span><strong>' + providerText + ' Mockup Box</strong></div>';
                        } else if (field.type === 'html') {
                            inputHtml = '<div class="ff-canvas-card-preview" style="font-family:monospace; font-size:11px; background:#fafafa; border:1px solid #ccd0d4; padding:8px; white-space:pre-wrap;">' + (field.options ? escapeHtml(field.options) : '&lt;div class="custom-html"&gt;Type custom HTML code inside the Customizer sidebar drawer.&lt;/div&gt;') + '</div>';
                        } else if (field.type === 'custom_submit') {
                            var btnText = field.label ? field.label : 'Submit';
                            inputHtml = '<div style="padding: 5px 0;"><button type="button" class="ff-form-submit" style="background:#2271b1; border-color:#2271b1; color:#ffffff; font-weight:600; font-size:14px; padding:10px 20px; border-radius:4px; height:auto; cursor:default; border:none; display:inline-block; margin-top:0;">' + escapeHtml(btnText) + '</button></div>';
                        } else if (field.type === 'shortcode' || field.type === 'action_hook') {
                            var textVal = (field.type === 'shortcode') ? '[shortcode]' : 'Hook: active_action_event';
                            inputHtml = '<div class="ff-canvas-card-preview" style="font-family:monospace; font-size:11px; background:#f0f6fc; color:#2271b1; border:1px solid #c8d7e1; padding:6px 10px;">' + textVal + '</div>';
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
                            '<button type="button" class="ff-form-submit" style="background:#2271b1; border-color:#2271b1; color:#ffffff; font-weight:600; font-size:14px; padding:10px 20px; border-radius:4px; height:auto; cursor:default; pointer-events:none; border:none; transition:none; text-transform:none; letter-spacing:normal; width:auto; display:inline-block; margin-top:0;">Register</button>' +
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
                        '<h4 style="margin:0 0 5px 0; font-size:13px; color:#2271b1; text-transform:uppercase; letter-spacing:0.5px;">Customizing: ' + escapeHtml(field.type.toUpperCase()) + '</h4>' +
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
                            '<h4 style="margin:0 0 5px 0; font-size:13px; color:#2271b1; text-transform:uppercase; letter-spacing:0.5px;">Customizing: ' + escapeHtml(field.type.toUpperCase()) + '</h4>' +
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
                <select style="border-radius:4px; font-size:13px; min-height:30px; border-color:#ccd0d4; padding:2px 8px;">
                    <option>Active</option>
                    <option>Trash</option>
                </select>
                <a href="<?php echo admin_url('admin.php?page=firebase_forms&action=create'); ?>" class="button button-primary" style="background:#2271b1; border-color:#2271b1; font-weight:600; min-height:30px; line-height:28px; padding:0 14px; border-radius:4px;"><span class="dashicons dashicons-plus" style="font-size:15px; margin-top:6px; margin-right:3px;"></span> Add New Form</a>
            </div>
            
            <div style="display:flex; gap:8px;">
                <div style="position:relative;">
                    <span class="dashicons dashicons-search" style="position:absolute; left:8px; top:50%; transform:translateY(-50%); color:#646970; font-size:15px;"></span>
                    <input type="text" id="ff_dash_search" placeholder="Search Forms" style="border-radius:4px; border:1px solid #8c8f94; font-size:13px; padding:4px 8px 4px 28px; width:220px;">
                </div>
                <button type="button" class="button" style="border-color:#ccd0d4; color:#2c3338; border-radius:4px;"><span class="dashicons dashicons-filter" style="font-size:14px; margin-top:7px; margin-right:3px;"></span> Filter</button>
            </div>
        </div>
        
        <!-- Forms list Dashboard table layout matching screenshot -->
        <style>
            .ff-dash-table-card {
                background: #ffffff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
                border-radius: 4px;
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
                border-bottom: 1px solid #f0f0f1;
            }
            .ff-dash-table th {
                background: #f6f7f7;
                font-weight: 700;
                color: #2c3338;
                border-bottom: 2px solid #dcdcde;
                text-transform: uppercase;
                font-size: 11px;
                letter-spacing: 0.5px;
            }
            .ff-dash-table tbody tr {
                transition: background 0.15s;
            }
            .ff-dash-table tbody tr:hover {
                background: #fdfdfd;
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
                color: #2271b1;
            }
            .ff-row-actions a.delete {
                color: #b32d2e;
            }
            .ff-row-actions a:hover {
                color: #135e96;
            }
            .ff-row-actions a.delete:hover {
                color: #d63638;
            }
            .ff-shortcode-tag {
                background: #f0f0f1;
                border: 1px solid #ccd0d4;
                padding: 4px 10px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 11px;
                color: #1d2327;
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }
            .ff-shortcode-tag .dashicons {
                font-size: 12px;
                width: 12px;
                height: 12px;
                color: #646970;
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
                                    <a href="<?php echo $edit_url; ?>" style="text-decoration:none; font-weight:700; color:#1d2327; font-size:14px;"><?php echo esc_html($f->title); ?></a>
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
                                    <a href="<?php echo $entries_url; ?>" style="text-decoration:none; font-weight:600; color:#2271b1;"><?php echo $form_entries; ?> entries</a>
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
            <select style="border-radius:4px; font-size:12px; border-color:#ccd0d4; padding:2px 8px;">
                <option>10/page</option>
                <option>20/page</option>
                <option>50/page</option>
            </select>
            <div style="display:flex; align-items:center; gap:5px;">
                <button type="button" class="button" disabled style="min-height:28px; line-height:26px; border-radius:4px; padding:0 8px;"><span class="dashicons dashicons-arrow-left-alt2" style="font-size:12px; margin-top:6px;"></span></button>
                <span style="background:#2271b1; color:#ffffff; font-weight:600; padding:4px 10px; border-radius:4px; border:1px solid #2271b1;">1</span>
                <button type="button" class="button" disabled style="min-height:28px; line-height:26px; border-radius:4px; padding:0 8px;"><span class="dashicons dashicons-arrow-right-alt2" style="font-size:12px; margin-top:6px;"></span></button>
            </div>
            <div style="display:flex; align-items:center; gap:5px; color:#2c3338;">
                <span>Go to</span>
                <input type="text" value="1" style="width:36px; text-align:center; border-radius:4px; border:1px solid #8c8f94; padding:3px 0; font-size:12px;" disabled>
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
    $options = get_option('ff_firebase_settings');
    $email_enabled = isset($options['email_enabled']) ? $options['email_enabled'] : '';
    $email_to = isset($options['email_to']) ? $options['email_to'] : get_option('admin_email');
    $email_subject = isset($options['email_subject']) ? $options['email_subject'] : 'New Client Registration';
    $email_template = isset($options['email_template']) ? $options['email_template'] : ff_firebase_get_default_admin_template();

    ff_firebase_admin_page_header('Admin Email Settings', 'admin_email');
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Admin Notification Settings</h2>
        <p>Configure settings for the automated email alerts sent to the website administrator upon a new client registration.</p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="email_enabled">Enable Admin Notifications</label></th>
                    <td>
                        <input type="checkbox" id="email_enabled" name="ff_firebase_settings[email_enabled]" value="yes" <?php checked($email_enabled, 'yes'); ?>>
                        <span class="description">Receive an email alert when a new submission occurs</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="email_to">Recipient Admin Email</label></th>
                    <td>
                        <input type="email" id="email_to" name="ff_firebase_settings[email_to]" value="<?php echo esc_attr($email_to); ?>" class="regular-text">
                        <p class="description">Where notification emails will be sent.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="email_subject">Email Subject</label></th>
                    <td>
                        <input type="text" id="email_subject" name="ff_firebase_settings[email_subject]" value="<?php echo esc_attr($email_subject); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="email_template">HTML Body Template</label></th>
                    <td>
                        <?php ff_firebase_render_email_tag_bar('email_template'); ?>
                        <?php 
                        wp_editor($email_template, 'email_template', array(
                            'textarea_name' => 'ff_firebase_settings[email_template]',
                            'textarea_rows' => 12,
                            'media_buttons' => true,
                            'teeny'         => false,
                            'quicktags'     => true
                        )); 
                        ?>
                        <p class="description">Available Placeholders: <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code></p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php ff_firebase_render_visual_styling_card('ff_firebase_settings', $options); ?>
    <?php
    ff_firebase_admin_page_footer();
}

function ff_firebase_client_email_page() {
    wp_enqueue_media();
    $options = get_option('ff_firebase_settings');
    $client_email_enabled = isset($options['client_email_enabled']) ? $options['client_email_enabled'] : '';
    $client_email_subject = isset($options['client_email_subject']) ? $options['client_email_subject'] : 'Vielen Dank für Ihre Registrierung';
    $client_email_template = isset($options['client_email_template']) ? $options['client_email_template'] : ff_firebase_get_default_client_template();

    ff_firebase_admin_page_header('Client Email Settings', 'client_email');
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Client Thank-You Email</h2>
        <p>Configure settings for the branded confirmation thank-you email sent automatically to the client after registration.</p>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="client_email_enabled">Enable Client Thank-You Emails</label></th>
                    <td>
                        <input type="checkbox" id="client_email_enabled" name="ff_firebase_settings[client_email_enabled]" value="yes" <?php checked($client_email_enabled, 'yes'); ?>>
                        <span class="description">Send a thank-you/confirmation email directly to the client</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="client_email_subject">Email Subject</label></th>
                    <td>
                        <input type="text" id="client_email_subject" name="ff_firebase_settings[client_email_subject]" value="<?php echo esc_attr($client_email_subject); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="client_email_template">HTML Body Template</label></th>
                    <td>
                        <?php ff_firebase_render_email_tag_bar('client_email_template'); ?>
                        <?php 
                        wp_editor($client_email_template, 'client_email_template', array(
                            'textarea_name' => 'ff_firebase_settings[client_email_template]',
                            'textarea_rows' => 12,
                            'media_buttons' => true,
                            'teeny'         => false,
                            'quicktags'     => true
                        )); 
                        ?>
                        <p class="description">Available Placeholders: <code>{name}</code>, <code>{email}</code>, <code>{address}</code>, <code>{plz_ort}</code>, <code>{submitted_at}</code></p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php ff_firebase_render_visual_styling_card('ff_firebase_settings', $options); ?>
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
            Google Maps Embed API is a free service by Google that allows embedding Google Maps in your site. For more details, visit Google Maps <a href="https://developers.google.com/maps/documentation/embed/get-api-key" target="_blank" style="color: #2271b1; text-decoration: none; font-weight: 600;">Using API Keys</a> page.
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
        'success'      => true,
        'form_title'   => $form_title,
        'fields'       => $fields,
        'maps_enabled' => isset($form_settings['maps_enabled']) && $form_settings['maps_enabled'] === 'yes',
        'maps_api_key' => $maps_api_key,
        'maps_address' => isset($form_settings['maps_address']) ? $form_settings['maps_address'] : 'Vienna, Austria',
        'maps_zoom'    => isset($form_settings['maps_zoom']) ? intval($form_settings['maps_zoom']) : 14,
        'maps_type'    => isset($form_settings['maps_type']) ? $form_settings['maps_type'] : 'roadmap',
        'maps_height'  => isset($form_settings['maps_height']) ? intval($form_settings['maps_height']) : 400,
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
        return new WP_Error('db_error', 'Failed to store submission in WordPress database.', array('status' => 500));
    }

    $submission_id = $wpdb->insert_id;

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
    
    $body_template = !empty($form_settings['email_template']) ? $form_settings['email_template'] : (!empty($options['email_template']) ? $options['email_template'] : ff_firebase_get_default_admin_template());

    $body = str_replace(
        array('{name}', '{email}', '{address}', '{plz_ort}', '{submitted_at}'),
        array(
            esc_html($sub->name),
            esc_html($sub->email),
            esc_html($sub->address),
            esc_html($sub->plz_ort),
            esc_html(date('Y-m-d H:i:s', strtotime($sub->submitted_at)))
        ),
        $body_template
    );

    $body = ff_firebase_wrap_email_body($body, $form_settings);

    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($to, $subject, $body, $headers);
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
        return;
    }

    $to      = sanitize_email($recipient_email);
    $subject = !empty($form_settings['client_email_subject']) ? sanitize_text_field($form_settings['client_email_subject']) : (!empty($options['client_email_subject']) ? sanitize_text_field($options['client_email_subject']) : 'Vielen Dank für Ihre Registrierung - ENGIN DENIZ');
    
    $body_template = !empty($form_settings['client_email_template']) ? $form_settings['client_email_template'] : (!empty($options['client_email_template']) ? $options['client_email_template'] : ff_firebase_get_default_client_template());

    $body = str_replace(
        array('{name}', '{email}', '{address}', '{plz_ort}', '{submitted_at}'),
        array(
            esc_html($sub->name),
            esc_html($recipient_email),
            esc_html($sub->address),
            esc_html($sub->plz_ort),
            esc_html(date('Y-m-d H:i:s', strtotime($sub->submitted_at)))
        ),
        $body_template
    );

    $body = ff_firebase_wrap_email_body($body, $form_settings);

    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Add a small 1-second delay to prevent SMTP socket collision / rate limiting
    sleep(1);

    $mail_result = wp_mail($to, $subject, $body, $headers);

    if (!$mail_result) {
        error_log("Fluent Forms to Firebase: SMTP Thank-You email failed to send to client: " . $to);
    } else {
        error_log("Fluent Forms to Firebase: SMTP Thank-You email successfully sent to client: " . $to);
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

    $form_id = 'firebase_form_' . uniqid();
    wp_enqueue_style('dashicons');
    
    ob_start();
    ?>
    <div class="firebase-form-wrapper" id="<?php echo $form_id; ?>-wrapper">
        <style>
            .firebase-form-wrapper {
                max-width: 600px;
                margin: 20px auto;
                padding: 30px;
                background: #ffffff;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
                border-radius: 8px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
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
                font-size: 13px;
                color: #4b5563;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .ff-form-input {
                width: 100%;
                padding: 12px 16px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-size: 14px;
                color: #1f2937;
                background-color: #f9fafb;
                box-sizing: border-box;
                transition: all 0.2s ease-in-out;
            }
            .ff-form-input:focus {
                border-color: #c31524;
                background-color: #ffffff;
                outline: none;
                box-shadow: 0 0 0 3px rgba(195, 21, 36, 0.15);
            }
            .ff-form-submit {
                display: block;
                width: 100%;
                padding: 14px;
                background-color: #000000;
                color: #ffffff;
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
            }
            .ff-form-submit:hover {
                background-color: #c31524;
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
                        case 'hcaptcha':
                        case 'turnstile':
                            ?>
                            <div class="ff-protection-box" style="display: inline-flex; align-items: center; gap: 12px; padding: 12px 18px; border: 1px solid #d1d5db; background: #f9fafb; border-radius: 6px; font-size: 13px; color: #4b5563; font-weight: 500; margin-top: 5px;">
                                <input type="checkbox" name="<?php echo $fid; ?>" value="Verified" required style="margin: 0;" checked>
                                <span class="dashicons dashicons-shield" style="font-size: 20px; width: 20px; height: 20px; color: #10b981; margin-top: 2px;"></span>
                                <span><?php echo ($ftype === 'recaptcha') ? 'Google reCAPTCHA v2 Protected' : (($ftype === 'hcaptcha') ? 'hCaptcha Spambot Protection' : 'Cloudflare Turnstile Verified'); ?></span>
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
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php _e('Form Submissions Log', 'fluent-forms-to-firebase'); ?></h1>
        <hr class="wp-header-end">

        <!-- Form Filter dropdown matching Fluent Forms dashboard list -->
        <div style="margin-top: 20px; margin-bottom: 20px; font-family:-apple-system, BlinkMacSystemFont, sans-serif; display:flex; align-items:center; gap:8px;">
            <label for="ff_form_filter" style="font-weight:600; font-size:13px; color:#2c3338; margin-right:4px;">Filter by Form:</label>
            <select id="ff_form_filter" style="border-radius:4px; border-color:#ccd0d4; padding:3px 12px; font-size:13px; min-height:30px;" onchange="window.location.href = addQueryParameter(window.location.href, 'form_id', this.value)">
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
                color: #2271b1 !important;
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
                                    <a href="#" class="ff-view-details-link" data-data="<?php echo esc_attr($sub->form_data); ?>" data-id="<?php echo $sub->id; ?>" data-name="<?php echo esc_attr($sub->name); ?>" data-email="<?php echo esc_attr($sub->email); ?>" style="color:#2271b1; font-weight:600; font-size:11px; text-decoration:none;">View Custom Fields</a>
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
            table.append('<tr><td style="padding:8px 0; font-weight:700; border-bottom:1px solid #eee; color:#646970;">Email</td><td style="padding:8px 0; border-bottom:1px solid #eee;"><a href="mailto:' + escapeHtml(email) + '" style="color:#2271b1; text-decoration:none;">' + escapeHtml(email) + '</a></td></tr>');
            
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
            'id'          => array('type' => 'Int'),
            'title'       => array('type' => 'String'),
            'fields'      => array('type' => array('list_of' => 'FirebaseFormField')),
            'settings'    => array('type' => 'String'),
            'mapsEnabled' => array('type' => 'Boolean'),
            'mapsApiKey'  => array('type' => 'String'),
            'mapsAddress' => array('type' => 'String'),
            'mapsZoom'    => array('type' => 'Int'),
            'mapsType'    => array('type' => 'String'),
            'mapsHeight'  => array('type' => 'Int'),
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
                'id'          => intval($form->id),
                'title'       => $form->title,
                'fields'      => $fields_typed,
                'settings'    => $form->settings_json,
                'mapsEnabled' => isset($form_settings['maps_enabled']) && $form_settings['maps_enabled'] === 'yes',
                'mapsApiKey'  => $maps_api_key,
                'mapsAddress' => isset($form_settings['maps_address']) ? $form_settings['maps_address'] : 'Vienna, Austria',
                'mapsZoom'    => isset($form_settings['maps_zoom']) ? intval($form_settings['maps_zoom']) : 14,
                'mapsType'    => isset($form_settings['maps_type']) ? $form_settings['maps_type'] : 'roadmap',
                'mapsHeight'  => isset($form_settings['maps_height']) ? intval($form_settings['maps_height']) : 400,
            );
        }
    ));
}