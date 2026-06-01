(function(blocks, editor, components, element, serverSideRender) {
    var el = element.createElement;
    var SelectControl = components.SelectControl;
    var InspectorControls = editor.InspectorControls;
    var PanelBody = components.PanelBody;
    var ServerSideRender = serverSideRender || (window.wp && window.wp.serverSideRender);
    var TextControl = components.TextControl;
    var RangeControl = components.RangeControl;

    // Slanted premium logo matching Fluent Forms
    var slantIcon = el('svg', { 
        width: 20, 
        height: 20, 
        viewBox: '0 0 100 100',
        style: { fill: '#0091ff' } 
    },
        el('rect', { width: 100, height: 100, rx: 20, fill: '#0091ff' }),
        el('path', { d: 'M30,25 L80,25 L70,37 L20,37 Z M25,44 L75,44 L65,56 L15,56 Z M20,63 L55,63 L45,75 L10,75 Z', fill: '#ffffff' })
    );

    blocks.registerBlockType('fluent-forms-to-firebase/firebase-form', {
        title: 'Firebase Form',
        description: 'Select a form to display and customize its appearance.',
        icon: slantIcon,
        category: 'text',
        attributes: {
            formId: {
                type: 'string',
                default: ''
            }
        },
        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            var forms = window.ff_firebase_block_forms || [];
            var options = [{ value: '', label: '-- Select a form --' }];
            forms.forEach(function(f) {
                options.push({ value: f.id.toString(), label: f.title });
            });

            // Right Sidebar Controls panel
            var inspector = el(InspectorControls, {},
                el(PanelBody, { title: 'Form Settings', initialOpen: true },
                    el(SelectControl, {
                        label: 'Select a Form',
                        value: attributes.formId,
                        options: options,
                        onChange: function(newFormId) {
                            setAttributes({ formId: newFormId });
                        }
                    })
                )
            );

            // Setup Placeholder Card (If NO form is selected)
            if (!attributes.formId) {
                var logoSvg = el('div', {
                    style: {
                        width: '80px',
                        height: '80px',
                        background: '#0091ff',
                        borderRadius: '16px',
                        display: 'flex',
                        flexDirection: 'column',
                        justifyContent: 'center',
                        alignItems: 'center',
                        gap: '6px',
                        padding: '10px',
                        boxSizing: 'border-box',
                        marginBottom: '15px',
                        marginLeft: 'auto',
                        marginRight: 'auto'
                    }
                },
                    el('div', { style: { width: '48px', height: '10px', background: '#ffffff', borderRadius: '5px', transform: 'skewX(-20deg)' } }),
                    el('div', { style: { width: '48px', height: '10px', background: '#ffffff', borderRadius: '5px', transform: 'skewX(-20deg)' } }),
                    el('div', { style: { width: '32px', height: '10px', background: '#ffffff', borderRadius: '5px', transform: 'skewX(-20deg)', marginRight: '16px' } })
                );

                return el('div', {
                    style: {
                        padding: '30px',
                        border: '1px dashed #ccd0d4',
                        background: '#ffffff',
                        borderRadius: '4px',
                        textAlign: 'center',
                        maxWidth: '500px',
                        marginLeft: 'auto',
                        marginRight: 'auto',
                        boxShadow: '0 1px 3px rgba(0,0,0,0.04)'
                    }
                },
                    logoSvg,
                    el('label', {
                        style: {
                            display: 'block',
                            fontSize: '11px',
                            fontWeight: '700',
                            textTransform: 'uppercase',
                            color: '#1d2327',
                            letterSpacing: '0.5px',
                            marginBottom: '8px',
                            textAlign: 'left'
                        }
                    }, 'Select a Form'),
                    el(SelectControl, {
                        value: attributes.formId,
                        options: options,
                        onChange: function(newFormId) {
                            setAttributes({ formId: newFormId });
                        }
                    }),
                    el('p', {
                        style: {
                            fontSize: '12px',
                            color: '#646970',
                            marginTop: '10px',
                            textAlign: 'left',
                            fontStyle: 'italic'
                        }
                    }, 'Select a form to display and customize its appearance.')
                );
            }

            // Live Dynamic Render (If a form IS selected)
            return el('div', {
                style: {
                    border: '1px solid #ccd0d4',
                    background: '#f9fafb',
                    borderRadius: '6px',
                    padding: '15px'
                }
            },
                inspector,
                el('div', {
                    style: {
                        background: '#ffffff',
                        border: '1px solid #ccd0d4',
                        borderRadius: '4px',
                        padding: '20px'
                    }
                },
                    el(ServerSideRender, {
                        block: 'fluent-forms-to-firebase/firebase-form',
                        attributes: attributes
                    })
                )
            );
        },
        save: function() {
            return null;
        }
    });

    blocks.registerBlockType('fluent-forms-to-firebase/google-maps', {
        title: 'Google Maps',
        description: 'Configure and embed an interactive Google Map on the page, just like in Elementor.',
        icon: 'location-alt',
        category: 'text',
        attributes: {
            address: {
                type: 'string',
                default: 'Vienna, Austria'
            },
            zoom: {
                type: 'number',
                default: 14
            },
            height: {
                type: 'number',
                default: 400
            },
            mapType: {
                type: 'string',
                default: 'roadmap'
            }
        },
        edit: function(props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            var inspector = el(InspectorControls, {},
                el(PanelBody, { title: 'Map Settings', initialOpen: true },
                    el(TextControl, {
                        label: 'Location / Address',
                        value: attributes.address,
                        onChange: function(newAddress) {
                            setAttributes({ address: newAddress });
                        }
                    }),
                    el(RangeControl, {
                        label: 'Zoom Level',
                        value: attributes.zoom,
                        min: 1,
                        max: 21,
                        onChange: function(newZoom) {
                            setAttributes({ zoom: newZoom });
                        }
                    }),
                    el(TextControl, {
                        label: 'Map Height (px)',
                        value: attributes.height,
                        type: 'number',
                        onChange: function(newHeight) {
                            setAttributes({ height: parseInt(newHeight, 10) || 400 });
                        }
                    }),
                    el(SelectControl, {
                        label: 'Map View Type',
                        value: attributes.mapType,
                        options: [
                            { value: 'roadmap', label: 'roadmap (Standard)' },
                            { value: 'satellite', label: 'satellite (Satellite)' },
                            { value: 'hybrid', label: 'hybrid (Hybrid)' },
                            { value: 'terrain', label: 'terrain (Terrain)' }
                        ],
                        onChange: function(newType) {
                            setAttributes({ mapType: newType });
                        }
                    })
                )
            );

            // Compute Preview Iframe Source URL with global API key fallback localization enfolded
            var apiKey = (window.ff_firebase_global_maps_key && window.ff_firebase_global_maps_key.key) ? window.ff_firebase_global_maps_key.key : '';
            var src = '';
            if (apiKey) {
                src = 'https://www.google.com/maps/embed/v1/place?key=' + encodeURIComponent(apiKey) + '&q=' + encodeURIComponent(attributes.address) + '&zoom=' + attributes.zoom + '&maptype=' + attributes.mapType;
            } else {
                var tLetter = 'm';
                if (attributes.mapType === 'satellite') tLetter = 'k';
                else if (attributes.mapType === 'hybrid') tLetter = 'h';
                else if (attributes.mapType === 'terrain') tLetter = 'p';
                src = 'https://maps.google.com/maps?q=' + encodeURIComponent(attributes.address) + '&z=' + attributes.zoom + '&t=' + tLetter + '&output=embed';
            }

            return el('div', {
                style: {
                    border: '1px solid #ccd0d4',
                    background: '#ffffff',
                    borderRadius: '6px',
                    padding: '20px',
                    boxShadow: '0 2px 8px rgba(0,0,0,0.05)',
                    maxWidth: '600px',
                    marginLeft: 'auto',
                    marginRight: 'auto',
                    boxSizing: 'border-box'
                }
            },
                inspector,
                el('h4', {
                    style: {
                        marginTop: 0,
                        marginBottom: '12px',
                        fontSize: '14px',
                        color: '#1d2327',
                        display: 'flex',
                        alignItems: 'center',
                        gap: '6px',
                        fontWeight: '700'
                    }
                },
                    el('span', { className: 'dashicons dashicons-location-alt', style: { color: '#0091ff', fontSize: '18px', width: '18px', height: '18px' } }),
                    'Google Maps Block Preview'
                ),
                el('div', {
                    style: {
                        border: '1px solid #e5e7eb',
                        borderRadius: '4px',
                        overflow: 'hidden',
                        lineHeight: 0
                    }
                },
                    el('iframe', {
                        src: src,
                        width: '100%',
                        height: attributes.height,
                        frameborder: '0',
                        style: { border: 0, display: 'block' }
                    })
                )
            );
        },
        save: function() {
            return null; // Server-side render callback used on front-end
        }
    });
})(
    window.wp.blocks,
    window.wp.blockEditor || window.wp.editor,
    window.wp.components,
    window.wp.element,
    window.wp.serverSideRender
);
