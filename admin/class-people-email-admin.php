<?php
/**
 * LCD People Email Administration
 * 
 * Handles email template management and settings for people-related emails
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_People_Email_Admin {
    private static $instance = null;
    private $plugin_instance;

    public static function get_instance($plugin_instance = null) {
        if (null === self::$instance) {
            self::$instance = new self($plugin_instance);
        }
        return self::$instance;
    }

    private function __construct($plugin_instance) {
        $this->plugin_instance = $plugin_instance;
        
        // Hook into admin_menu to add email settings page
        add_action('admin_menu', array($this, 'add_email_pages'), 20);
        
        // Register settings
        add_action('admin_init', array($this, 'register_email_settings'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_lcd_people_test_template_email', array($this, 'ajax_test_template_email'));
        add_action('wp_ajax_lcd_people_test_wpmail_template', array($this, 'ajax_test_wpmail_template'));
    }

    public function add_email_pages() {
        // Add main settings page
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            __('Email Settings', 'lcd-people'),
            __('Email Settings', 'lcd-people'),
            'manage_options',
            'lcd-people-email-settings',
            array($this, 'email_settings_page_callback')
        );
        
        // Add templates page
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            __('Email Templates', 'lcd-people'),
            __('Email Templates', 'lcd-people'),
            'manage_options',
            'lcd-people-email-templates',
            array($this, 'email_templates_page_callback')
        );
    }

    public function register_email_settings() {
        // Register settings for both pages
        register_setting(
            'lcd_people_email_settings',
            'lcd_people_email_settings',
            array($this, 'sanitize_email_settings')
        );

        // Check if Zeptomail is available and enabled
        $zeptomail_enabled = false;
        if (class_exists('LCD_Zeptomail')) {
            $zeptomail_enabled = LCD_Zeptomail::get_instance()->is_enabled();
        }
        
        // Email Settings page sections
        // Zeptomail Integration Section
        add_settings_section(
            'lcd_people_zeptomail_integration',
            __('Zeptomail Integration Status', 'lcd-people'),
            array($this, 'zeptomail_integration_section_callback'),
            'lcd_people_email_settings'
        );

        // Email Controls Section
        add_settings_section(
            'lcd_people_email_controls',
            __('Email Controls', 'lcd-people'),
            array($this, 'email_controls_section_callback'),
            'lcd_people_email_settings'
        );

        // Add email type enabled fields
        foreach ($this->get_email_types() as $email_type => $email_label) {
            add_settings_field(
                $email_type . '_enabled',
                $email_label,
                array($this, 'email_template_enabled_field_callback'),
                'lcd_people_email_settings',
                'lcd_people_email_controls',
                array('type' => $email_type, 'label' => $email_label)
            );
        }

        // Token expiry setting
        add_settings_field(
            'token_expiry_hours',
            __('Claim Token Expiry (Hours)', 'lcd-people'),
            array($this, 'token_expiry_field_callback'),
            'lcd_people_email_settings',
            'lcd_people_email_controls'
        );

        // Email Templates page sections
        if ($zeptomail_enabled) {
            // Zeptomail Templates Section
            add_settings_section(
                'lcd_people_zeptomail_templates',
                __('Zeptomail Template Keys', 'lcd-people'),
                array($this, 'zeptomail_templates_section_callback'),
                'lcd_people_email_templates'
            );

            // Add Zeptomail template fields
            foreach ($this->get_email_types() as $email_type => $email_label) {
                add_settings_field(
                    'zeptomail_template_' . $email_type,
                    $email_label,
                    array($this, 'zeptomail_template_field_callback'),
                    'lcd_people_email_templates',
                    'lcd_people_zeptomail_templates',
                    array('type' => $email_type, 'label' => $email_label)
                );
            }
        }

        // WP Mail Templates Section (always available as fallback)
        add_settings_section(
            'lcd_people_wpmail_templates',
            __('WordPress Mail Templates', 'lcd-people'),
            array($this, 'wpmail_templates_section_callback'),
            'lcd_people_email_templates'
        );

        // Add WP Mail template fields
        foreach ($this->get_email_types() as $email_type => $email_label) {
            add_settings_field(
                'wpmail_template_' . $email_type,
                $email_label,
                array($this, 'wpmail_template_field_callback'),
                'lcd_people_email_templates',
                'lcd_people_wpmail_templates',
                array('type' => $email_type, 'label' => $email_label)
            );
        }
    }

    public function get_email_types() {
        return array(
            'claim_existing_user' => __('Account Claim - Existing User', 'lcd-people'),
            'claim_create_account' => __('Account Claim - Create Account', 'lcd-people'),
            'claim_no_records' => __('Account Claim - No Records Found', 'lcd-people')
        );
    }

    public function get_email_type_descriptions() {
        return array(
            'claim_existing_user' => __('Sent when someone tries to claim an account but already has a WordPress user account.', 'lcd-people'),
            'claim_create_account' => __('Sent when someone can create an account for their existing member record.', 'lcd-people'),
            'claim_no_records' => __('Sent when someone tries to claim an account but no member records are found.', 'lcd-people')
        );
    }

    /**
     * Email Settings Page Callback
     */
    public function email_settings_page_callback() {
        ?>
        <div class="wrap">
            <h1><?php _e('People Email Settings', 'lcd-people'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('lcd_people_email_settings');
                ?>
                <input type="hidden" name="lcd_people_email_settings[form_page]" value="email_settings">
                <?php
                do_settings_sections('lcd_people_email_settings');
                submit_button();
                ?>
            </form>

            <?php
            // Only show connection test if centralized Zeptomail plugin is available
            if (class_exists('LCD_Zeptomail')) : ?>
                <div class="lcd-connection-test">
                    <h3><?php _e('Connection Test', 'lcd-people'); ?></h3>
                    <p><?php printf(__('Test your Zeptomail connection from the <a href="%s" target="_blank">centralized Zeptomail settings page</a>.', 'lcd-people'), admin_url('options-general.php?page=lcd-zeptomail-settings')); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Email Templates Page Callback
     */
    public function email_templates_page_callback() {
        ?>
        <div class="wrap">
            <h1><?php _e('People Email Templates', 'lcd-people'); ?></h1>
            
            <?php 
            // Show email method status notice
            if (class_exists('LCD_Zeptomail') && LCD_Zeptomail::get_instance()->is_enabled()) : ?>
                <div class="notice notice-info">
                    <p><strong><?php _e('✓ Using Zeptomail', 'lcd-people'); ?></strong> - <?php _e('People emails will be sent via Zeptomail using the template keys you configure below.', 'lcd-people'); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning">
                    <p><strong><?php _e('Using WordPress wp_mail()', 'lcd-people'); ?></strong> - <?php _e('People emails will be sent using WordPress built-in email functionality. For better deliverability, consider configuring the', 'lcd-people'); ?> <a href="<?php echo admin_url('options-general.php?page=lcd-zeptomail-settings'); ?>"><?php _e('centralized Zeptomail integration', 'lcd-people'); ?></a>.</p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('lcd_people_email_settings');
                ?>
                <input type="hidden" name="lcd_people_email_settings[form_page]" value="email_templates">
                <?php
                do_settings_sections('lcd_people_email_templates');
                submit_button();
                ?>
            </form>

            <?php $this->render_email_template_help(); ?>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts for email template testing
     */
    public function enqueue_admin_scripts($hook) {
        // Only enqueue on our email settings pages
        if ($hook !== 'lcd_person_page_lcd-people-email-settings' && $hook !== 'lcd_person_page_lcd-people-email-templates') {
            return;
        }
        
        // Enqueue our admin script
        wp_enqueue_script('jquery');
        
        // Add inline script for email template testing
        $script = "
        jQuery(document).ready(function($) {
            // Show/hide test template buttons when template keys are entered
            $(document).on('input', '.template-key-input', function() {
                var input = $(this);
                var emailType = input.data('email-type');
                var testBtn = input.siblings('.test-template-btn');
                var templateKey = input.val().trim();
                
                if (templateKey) {
                    testBtn.attr('data-template-key', templateKey).show();
                } else {
                    testBtn.hide();
                }
            });
        
             // Test Zeptomail template
            $(document).on('click', '.test-template-btn', function(e) {
                e.preventDefault();
                var button = $(this);
                var emailType = button.data('email-type');
                var templateKey = button.data('template-key');
                
                // Prompt for email address
                var testEmail = prompt('Enter an email address to send the test email to:');
                if (!testEmail || !testEmail.trim()) {
                    return;
                }
                
                testEmail = testEmail.trim();
                
                // Simple email validation
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(testEmail)) {
                    alert('Please enter a valid email address.');
                    return;
                }
                
                var resultDiv = $('#test-result-' + emailType);
                resultDiv.html('<span style=\"color: #666;\">Sending test email...</span>').show();
                
                button.prop('disabled', true).text('Sending...');
                
                $.post(ajaxurl, {
                    action: 'lcd_people_test_template_email',
                    nonce: '" . wp_create_nonce('lcd_test_template_email') . "',
                    email_type: emailType,
                    template_key: templateKey,
                    test_email: testEmail
                }, function(response) {
                    button.prop('disabled', false).text('Test Template');
                    if (response.success) {
                        resultDiv.html('<div class=\"notice notice-success inline\"><p>' + response.data.message + '</p></div>').show();
                    } else {
                        resultDiv.html('<div class=\"notice notice-error inline\"><p>' + response.data.message + '</p></div>').show();
                    }
                }).fail(function() {
                    button.prop('disabled', false).text('Test Template');
                    resultDiv.html('<div class=\"notice notice-error inline\"><p>AJAX request failed.</p></div>').show();
                });
            });
            
                         // Test WP Mail template
            $(document).on('click', '.test-wpmail-template-btn', function(e) {
                e.preventDefault();
                var button = $(this);
                var emailType = button.data('email-type');
                
                // Prompt for email address
                var testEmail = prompt('Enter an email address to send the test email to:');
                if (!testEmail || !testEmail.trim()) {
                    return;
                }
                
                testEmail = testEmail.trim();
                
                // Simple email validation
                var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(testEmail)) {
                    alert('Please enter a valid email address.');
                    return;
                }
                
                // Get current form values
                var subjectField = $('#wpmail_subject_' + emailType);
                var contentField = $('textarea[name=\"lcd_people_email_settings[wpmail_templates][' + emailType + '][content]\"]');
                
                var resultDiv = $('#test-wpmail-result-' + emailType);
                resultDiv.html('<span style=\"color: #666;\">Sending test email...</span>').show();
                
                button.prop('disabled', true).text('Sending...');
                
                $.post(ajaxurl, {
                    action: 'lcd_people_test_wpmail_template',
                    nonce: '" . wp_create_nonce('lcd_test_wpmail_template') . "',
                    email_type: emailType,
                    test_email: testEmail,
                    subject: subjectField.val(),
                    content: contentField.val()
                }, function(response) {
                    button.prop('disabled', false).text('Test Template');
                    if (response.success) {
                        resultDiv.html('<div class=\"notice notice-success inline\"><p>' + response.data.message + '</p></div>').show();
                    } else {
                        resultDiv.html('<div class=\"notice notice-error inline\"><p>' + response.data.message + '</p></div>').show();
                    }
                }).fail(function() {
                    button.prop('disabled', false).text('Test Template');
                    resultDiv.html('<div class=\"notice notice-error inline\"><p>AJAX request failed.</p></div>').show();
                });
            });
        });
        ";
        
        wp_add_inline_script('jquery', $script);
    }

    public function zeptomail_integration_section_callback() {
        if (class_exists('LCD_Zeptomail')) {
            $zeptomail_enabled = LCD_Zeptomail::get_instance()->is_enabled();
            if ($zeptomail_enabled) {
                echo '<p>' . __('Zeptomail is properly configured and enabled for this site. People emails will be sent via Zeptomail.', 'lcd-people') . '</p>';
            } else {
                echo '<p class="notice notice-warning inline">' . __('Zeptomail plugin is installed but not enabled or configured.', 'lcd-people') . '</p>';
            }
        } else {
            echo '<p class="notice notice-info inline">' . __('Zeptomail plugin is not installed. WordPress mail will be used instead.', 'lcd-people') . '</p>';
        }
    }

    public function email_controls_section_callback() {
        echo '<p>' . __('Configure general email behavior and settings.', 'lcd-people') . '</p>';
    }

    public function zeptomail_templates_section_callback() {
        echo '<p>' . __('Configure Zeptomail template keys for each email type. These templates should be created in your Zeptomail dashboard.', 'lcd-people') . '</p>';
    }

    public function wpmail_templates_section_callback() {
        $zeptomail_enabled = class_exists('LCD_Zeptomail') && LCD_Zeptomail::get_instance()->is_enabled();
        if ($zeptomail_enabled) {
            echo '<p>' . __('WordPress mail templates serve as fallback when Zeptomail is unavailable.', 'lcd-people') . '</p>';
        } else {
            echo '<p>' . __('Configure email templates for WordPress mail delivery.', 'lcd-people') . '</p>';
        }
    }

    public function token_expiry_field_callback() {
        $options = get_option('lcd_people_email_settings', array());
        $value = isset($options['token_expiry_hours']) ? $options['token_expiry_hours'] : 24;
        echo '<input type="number" name="lcd_people_email_settings[token_expiry_hours]" value="' . esc_attr($value) . '" min="1" max="168" /> ';
        echo '<p class="description">' . __('How long account claim tokens remain valid (1-168 hours).', 'lcd-people') . '</p>';
    }

    public function email_template_enabled_field_callback($args) {
        $options = get_option('lcd_people_email_settings', array());
        $value = isset($options[$args['type'] . '_enabled']) ? $options[$args['type'] . '_enabled'] : '1';
        $description = $this->get_email_type_descriptions()[$args['type']] ?? '';
        ?>
        <label>
            <input type="checkbox" id="<?php echo esc_attr($args['type']); ?>_enabled" name="lcd_people_email_settings[<?php echo esc_attr($args['type']); ?>_enabled]" value="1" <?php checked('1', $value); ?> />
            <?php printf(__('Enable %s emails', 'lcd-people'), $args['label']); ?>
        </label>
        <?php if ($description) : ?>
            <p class="description"><?php echo esc_html($description); ?></p>
        <?php endif; ?>
        <?php
    }

    public function zeptomail_template_field_callback($args) {
        $options = get_option('lcd_people_email_settings', array());
        $template_key = $options['template_mapping'][$args['type']] ?? '';
        $email_enabled = $options[$args['type'] . '_enabled'] ?? 1;
        $descriptions = $this->get_email_type_descriptions();
        
        // Only show template mapping if the email type is enabled
        if (!$email_enabled) {
            echo '<p class="description" style="color: #666; font-style: italic;">' . 
                 sprintf(__('%s emails are disabled. Enable them in Email Settings to configure templates.', 'lcd-people'), $args['label']) . 
                 '</p>';
            return;
        }
        
        echo '<div class="template-mapping-row" data-email-type="' . esc_attr($args['type']) . '">';
        
        // Show email type description
        if (isset($descriptions[$args['type']])) {
            echo '<p class="description" style="margin-bottom: 10px; padding: 8px; background: #f9f9f9; border-left: 4px solid #72aee6;">';
            echo '<strong>' . esc_html($args['label']) . ':</strong> ' . esc_html($descriptions[$args['type']]);
            echo '</p>';
        }
        
        echo '<div class="template-input-group">';
        echo '<input type="text" name="lcd_people_email_settings[template_mapping][' . esc_attr($args['type']) . ']" value="' . esc_attr($template_key) . '" class="regular-text template-key-input" data-email-type="' . esc_attr($args['type']) . '" placeholder="' . esc_attr__('Enter template key (e.g., 2d6f.117fe5b8.k1.f38e8b50-1e7f-11e8-9b33-5254004d4f8f.178b1ae70a4)', 'lcd-people') . '">';
        
        if (!empty($template_key)) {
            echo '<button type="button" class="button button-secondary test-template-btn" data-email-type="' . esc_attr($args['type']) . '" data-template-key="' . esc_attr($template_key) . '">' . __('Test Template', 'lcd-people') . '</button>';
        } else {
            echo '<button type="button" class="button button-secondary test-template-btn" data-email-type="' . esc_attr($args['type']) . '" style="display: none;">' . __('Test Template', 'lcd-people') . '</button>';
        }
        
        echo '</div>';
        echo '<p class="description">' . sprintf(__('Enter the ZeptoMail template key for %s emails. Find this in your ZeptoMail dashboard under Templates.', 'lcd-people'), $args['label']) . '</p>';
        echo '<div class="template-test-result" id="test-result-' . esc_attr($args['type']) . '" style="display: none;"></div>';
        echo '</div>';
    }

    public function wpmail_template_field_callback($args) {
        $options = get_option('lcd_people_email_settings', array());
        $email_enabled = $options[$args['type'] . '_enabled'] ?? 1;
        $subject = $options['wpmail_templates'][$args['type']]['subject'] ?? '';
        $content = $options['wpmail_templates'][$args['type']]['content'] ?? '';
        $descriptions = $this->get_email_type_descriptions();
        
        // Only show wp_mail template fields if email type is enabled
        if (!$email_enabled) {
            echo '<p class="description" style="color: #666; font-style: italic;">' . 
                 sprintf(__('%s emails are disabled. Enable them in Email Settings to configure templates.', 'lcd-people'), $args['label']) . 
                 '</p>';
            return;
        }
        
        // If no content exists, populate with default template
        if (empty($subject) && empty($content)) {
            $default_template = $this->get_default_template($args['type']);
            $lines = explode("\n", $default_template);
            foreach ($lines as $line) {
                if (strpos($line, 'Subject:') === 0) {
                    $subject = trim(substr($line, 8));
                    break;
                }
            }
            $content = trim(str_replace('Subject: ' . $subject . "\n", '', $default_template));
        }
        
        echo '<div class="wpmail-template-section">';
        
        // Show email type description
        if (isset($descriptions[$args['type']])) {
            echo '<h3>' . esc_html($args['label']) . '</h3>';
            echo '<p class="description" style="margin-bottom: 15px; padding: 8px; background: #f9f9f9; border-left: 4px solid #72aee6;">';
            echo '<strong>' . esc_html($args['label']) . ':</strong> ' . esc_html($descriptions[$args['type']]);
            echo '</p>';
        }
        
        // Subject field
        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row"><label for="wpmail_subject_' . esc_attr($args['type']) . '">' . __('Subject Line', 'lcd-people') . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="wpmail_subject_' . esc_attr($args['type']) . '" name="lcd_people_email_settings[wpmail_templates][' . esc_attr($args['type']) . '][subject]" value="' . esc_attr($subject) . '" class="large-text" placeholder="' . esc_attr__('Email subject line...', 'lcd-people') . '">';
        echo '<p class="description">' . __('Subject line for the email. You can use merge variables like {{first_name}}, {{site_name}}, etc.', 'lcd-people') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        
        // Content field
        echo '<h4>' . __('Email Content', 'lcd-people') . '</h4>';
        echo '<textarea name="lcd_people_email_settings[wpmail_templates][' . esc_attr($args['type']) . '][content]" rows="15" class="large-text" style="width: 100%; font-family: monospace;">' . esc_textarea($content) . '</textarea>';
        echo '<p class="description">' . __('Email content. You can use text formatting and merge variables like {{first_name}}, {{email}}, {{create_account_url}}, etc.', 'lcd-people') . '</p>';
        
        // Add test button for wp_mail templates
        echo '<div style="margin-top: 10px;">';
        echo '<input type="email" class="wpmail-test-email" data-type="' . esc_attr($args['type']) . '" placeholder="test@example.com" style="margin-right: 10px;" />';
        echo '<button type="button" class="button button-secondary test-wpmail-template-btn" data-email-type="' . esc_attr($args['type']) . '">' . __('Test Template', 'lcd-people') . '</button>';
        echo '<div class="template-test-result" id="test-wpmail-result-' . esc_attr($args['type']) . '" style="display: none; margin-top: 10px;"></div>';
        echo '</div>';
        
        echo '</div>';
        echo '<hr style="margin: 30px 0;">';
    }

    private function get_default_template($email_type) {
        $site_name = get_bloginfo('name');
        
        $templates = array(
            'claim_existing_user' => sprintf(__("Subject: Account Access Instructions - %s\n\nHello!\n\nYou requested account access for %s.\n\nGood news! You already have an account with us. Here are your options:\n\n- Login to Your Account: {{login_url}}\n- Reset Password: {{reset_password_url}}\n\nIf you're having trouble accessing your account, please contact us for assistance.\n\nBest regards,\n%s Team", 'lcd-people'), $site_name, $site_name, $site_name),
            
            'claim_create_account' => sprintf(__("Subject: Create Your Account - %s\n\nHello {{first_name}}!\n\nYou requested account access for %s.\n\nWe found your membership record! You can now create your user account:\n\nName: {{name}}\nEmail: {{email}}\nStatus: {{membership_status}}\n\nCreate Your Account: {{create_account_url}}\n\nThis link will expire in {{token_expiry_hours}} hours for security reasons.\n\nBest regards,\n%s Team", 'lcd-people'), $site_name, $site_name, $site_name),
            
            'claim_no_records' => sprintf(__("Subject: Account Access Instructions - %s\n\nHello!\n\nYou requested account access for %s.\n\nWe couldn't find any membership or volunteer records for this email address.\n\nTo get started with us, you can:\n- Become a Member: {{membership_url}}\n- Sign Up to Volunteer: {{volunteer_url}}\n- Contact Us: {{contact_email}}\n\nOnce you have a membership or volunteer record, you'll be able to create an account.\n\nBest regards,\n%s Team", 'lcd-people'), $site_name, $site_name, $site_name)
        );
        
        return isset($templates[$email_type]) ? $templates[$email_type] : '';
    }

    public function sanitize_email_settings($input) {
        // Get existing settings to preserve data from other pages
        $existing_settings = get_option('lcd_people_email_settings', array());
        $sanitized = $existing_settings;
        
        if (!is_array($input)) {
            return $sanitized;
        }
        
        // Determine which page we're on by checking the hidden form field
        $form_page = $input['form_page'] ?? '';
        $is_email_settings_page = ($form_page === 'email_settings');
        $is_email_templates_page = ($form_page === 'email_templates');
        
        // Sanitize token expiry hours (if present in input)
        if (isset($input['token_expiry_hours'])) {
            $sanitized['token_expiry_hours'] = max(1, min(168, intval($input['token_expiry_hours'])));
        }
        
        // Handle email enabled settings checkboxes properly
        if ($is_email_settings_page) {
            $email_types = array_keys($this->get_email_types());
            
            // When on email settings page, process all email type checkboxes
            // (unchecked checkboxes won't be in $input, so we need to set them to 0)
            foreach ($email_types as $type) {
                $field_name = $type . '_enabled';
                $sanitized[$field_name] = isset($input[$field_name]) ? 1 : 0;
            }
        }
        
        // Sanitize template mappings (if present in input)
        if (isset($input['template_mapping']) && is_array($input['template_mapping'])) {
            // Initialize template_mapping array if it doesn't exist
            if (!isset($sanitized['template_mapping'])) {
                $sanitized['template_mapping'] = array();
            }
            foreach ($input['template_mapping'] as $type => $template_key) {
                $sanitized['template_mapping'][$type] = sanitize_text_field($template_key);
            }
        }
        
        // Sanitize wp_mail templates (if present in input)
        if (isset($input['wpmail_templates']) && is_array($input['wpmail_templates'])) {
            // Initialize wpmail_templates array if it doesn't exist
            if (!isset($sanitized['wpmail_templates'])) {
                $sanitized['wpmail_templates'] = array();
            }
            foreach ($input['wpmail_templates'] as $type => $template) {
                if (is_array($template)) {
                    $sanitized['wpmail_templates'][$type] = array(
                        'subject' => sanitize_text_field($template['subject'] ?? ''),
                        'content' => wp_kses_post($template['content'] ?? '')
                    );
                }
            }
        }
        
        // Remove the form_page field from being saved to the database
        unset($sanitized['form_page']);
        
        return $sanitized;
    }

    private function render_email_template_help() {
        ?>
        <div class="template-help">
            <h3><?php _e('Template Variables', 'lcd-people'); ?></h3>
            <p><?php _e('You can use the following variables in your email templates:', 'lcd-people'); ?></p>
            
            <h4><?php _e('User/Person Variables', 'lcd-people'); ?></h4>
            <ul>
                <li><code>{{first_name}}</code> - <?php _e('First name', 'lcd-people'); ?></li>
                <li><code>{{last_name}}</code> - <?php _e('Last name', 'lcd-people'); ?></li>
                <li><code>{{name}}</code> - <?php _e('Full name', 'lcd-people'); ?></li>
                <li><code>{{email}}</code> - <?php _e('Email address', 'lcd-people'); ?></li>
                <li><code>{{phone}}</code> - <?php _e('Phone number', 'lcd-people'); ?></li>
                <li><code>{{member_id}}</code> - <?php _e('Member ID', 'lcd-people'); ?></li>
            </ul>
            
            <h4><?php _e('Membership Variables', 'lcd-people'); ?></h4>
            <ul>
                <li><code>{{membership_status}}</code> - <?php _e('Membership status', 'lcd-people'); ?></li>
                <li><code>{{membership_type}}</code> - <?php _e('Membership type', 'lcd-people'); ?></li>
                <li><code>{{start_date}}</code> - <?php _e('Membership start date', 'lcd-people'); ?></li>
                <li><code>{{end_date}}</code> - <?php _e('Membership end date', 'lcd-people'); ?></li>
                <li><code>{{cancellation_date}}</code> - <?php _e('Cancellation date', 'lcd-people'); ?></li>
            </ul>
            
            <h4><?php _e('URL Variables', 'lcd-people'); ?></h4>
            <ul>
                <li><code>{{site_url}}</code> - <?php _e('Site homepage URL', 'lcd-people'); ?></li>
                <li><code>{{login_url}}</code> - <?php _e('Login page URL', 'lcd-people'); ?></li>
                <li><code>{{reset_password_url}}</code> - <?php _e('Password reset URL', 'lcd-people'); ?></li>
                <li><code>{{create_account_url}}</code> - <?php _e('Account creation URL', 'lcd-people'); ?></li>
                <li><code>{{profile_url}}</code> - <?php _e('User profile URL', 'lcd-people'); ?></li>
                <li><code>{{events_url}}</code> - <?php _e('Events page URL', 'lcd-people'); ?></li>
                <li><code>{{membership_url}}</code> - <?php _e('Membership signup URL', 'lcd-people'); ?></li>
                <li><code>{{volunteer_url}}</code> - <?php _e('Volunteer signup URL', 'lcd-people'); ?></li>
                <li><code>{{renewal_url}}</code> - <?php _e('Membership renewal URL', 'lcd-people'); ?></li>
            </ul>
            
            <h4><?php _e('Site Variables', 'lcd-people'); ?></h4>
            <ul>
                <li><code>{{site_name}}</code> - <?php _e('Site name', 'lcd-people'); ?></li>
                <li><code>{{contact_email}}</code> - <?php _e('Site admin email', 'lcd-people'); ?></li>
            </ul>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Handle Zeptomail test emails
            $('.test-zeptomail-template').on('click', function() {
                var button = $(this);
                var template = button.data('template');
                var type = button.data('type');
                var email = button.siblings().find('.zeptomail-test-email').val();
                
                if (!email) {
                    alert('<?php _e('Please enter a test email address.', 'lcd-people'); ?>');
                    return;
                }
                
                button.prop('disabled', true).text('<?php _e('Sending...', 'lcd-people'); ?>');
                
                $.post(ajaxurl, {
                    action: 'lcd_people_test_template_email',
                    template_key: template,
                    email_type: type,
                    test_email: email,
                    _ajax_nonce: '<?php echo wp_create_nonce('lcd_people_test_email'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('<?php _e('Test email sent successfully!', 'lcd-people'); ?>');
                    } else {
                        alert('<?php _e('Error:', 'lcd-people'); ?> ' + response.data.message);
                    }
                    button.prop('disabled', false).text('<?php _e('Send Test', 'lcd-people'); ?>');
                }).fail(function() {
                    alert('<?php _e('Failed to send test email.', 'lcd-people'); ?>');
                    button.prop('disabled', false).text('<?php _e('Send Test', 'lcd-people'); ?>');
                });
            });
            
            // Handle WP Mail test emails
            $('.test-wpmail-template').on('click', function() {
                var button = $(this);
                var type = button.data('type');
                var email = button.siblings().find('.wpmail-test-email').val();
                
                if (!email) {
                    alert('<?php _e('Please enter a test email address.', 'lcd-people'); ?>');
                    return;
                }
                
                button.prop('disabled', true).text('<?php _e('Sending...', 'lcd-people'); ?>');
                
                $.post(ajaxurl, {
                    action: 'lcd_people_test_wpmail_template',
                    email_type: type,
                    test_email: email,
                    _ajax_nonce: '<?php echo wp_create_nonce('lcd_people_test_email'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('<?php _e('Test email sent successfully!', 'lcd-people'); ?>');
                    } else {
                        alert('<?php _e('Error:', 'lcd-people'); ?> ' + response.data.message);
                    }
                    button.prop('disabled', false).text('<?php _e('Send Test', 'lcd-people'); ?>');
                }).fail(function() {
                    alert('<?php _e('Failed to send test email.', 'lcd-people'); ?>');
                    button.prop('disabled', false).text('<?php _e('Send Test', 'lcd-people'); ?>');
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_test_template_email() {
        check_ajax_referer('lcd_test_template_email', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')));
        }
        
        $email_type = sanitize_text_field($_POST['email_type'] ?? '');
        $template_key = sanitize_text_field($_POST['template_key'] ?? '');
        $test_email = sanitize_email($_POST['test_email'] ?? '');
        
        if (empty($email_type) || empty($template_key) || empty($test_email)) {
            wp_send_json_error(array('message' => __('Missing required fields.', 'lcd-people')));
        }
        
        if (!class_exists('LCD_Zeptomail')) {
            wp_send_json_error(array('message' => __('Zeptomail plugin not available.', 'lcd-people')));
        }
        
        // Create test template variables
        $template_vars = $this->get_test_template_variables();
        
        try {
            $result = LCD_Zeptomail::get_instance()->send_template_email(
                $test_email,
                $template_key,
                $template_vars
            );
            
            if ($result) {
                wp_send_json_success(array(
                    'message' => sprintf(__('Test %s email sent successfully to %s using template key: %s', 'lcd-people'), 
                        $this->get_email_types()[$email_type] ?? $email_type, 
                        $test_email, 
                        $template_key
                    )
                ));
            } else {
                wp_send_json_error(array('message' => __('Failed to send test email. Please check your template key and email settings.', 'lcd-people')));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_test_wpmail_template() {
        check_ajax_referer('lcd_test_wpmail_template', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')));
        }
        
        $email_type = sanitize_text_field($_POST['email_type'] ?? '');
        $test_email = sanitize_email($_POST['test_email'] ?? '');
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $content = wp_kses_post($_POST['content'] ?? '');
        
        if (empty($email_type) || empty($test_email)) {
            wp_send_json_error(array('message' => __('Missing required fields.', 'lcd-people')));
        }
        
        // Get template from current form data or settings
        $options = get_option('lcd_people_email_settings', array());
        
        if (empty($subject) && empty($content)) {
            // Get from settings if not provided in form
            if (isset($options['wpmail_templates'][$email_type])) {
                $subject = $options['wpmail_templates'][$email_type]['subject'] ?? '';
                $content = $options['wpmail_templates'][$email_type]['content'] ?? '';
            }
            
            // If still empty, use default template
            if (empty($subject) && empty($content)) {
                $default_template = $this->get_default_template($email_type);
                $lines = explode("\n", $default_template);
                foreach ($lines as $line) {
                    if (strpos($line, 'Subject:') === 0) {
                        $subject = trim(substr($line, 8));
                        break;
                    }
                }
                $content = trim(str_replace('Subject: ' . $subject . "\n", '', $default_template));
            }
        }
        
        if (empty($subject)) {
            wp_send_json_error(array('message' => __('No template configured for this email type.', 'lcd-people')));
        }
        
        // Replace template variables with test data
        $template_vars = $this->get_test_template_variables();
        foreach ($template_vars as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }
        
        $site_name = get_bloginfo('name');
        $from_email = get_option('admin_email');
        
        // Send test email
        $result = wp_mail(
            $test_email,
            $subject,
            $content,
            array(
                'From: ' . $site_name . ' <' . $from_email . '>',
                'Content-Type: text/plain; charset=UTF-8'
            )
        );
        
        if ($result) {
            wp_send_json_success(array(
                'message' => sprintf(__('Test %s email sent successfully to %s using wp_mail template', 'lcd-people'), 
                    $this->get_email_types()[$email_type] ?? $email_type, 
                    $test_email
                )
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to send test email. Please check your wp_mail configuration.', 'lcd-people')));
        }
    }

    private function get_test_template_variables() {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $contact_email = get_option('admin_email');
        
        return array(
            'first_name' => 'John',
            'last_name' => 'Doe',
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'phone' => '(555) 123-4567',
            'member_id' => '12345',
            'membership_status' => 'Active',
            'membership_type' => 'Paid',
            'start_date' => date('Y-m-d', strtotime('-1 year')),
            'end_date' => date('Y-m-d', strtotime('+1 year')),
            'cancellation_date' => date('Y-m-d'),
            'site_name' => $site_name,
            'site_url' => $site_url,
            'contact_email' => $contact_email,
            'login_url' => wp_login_url(),
            'reset_password_url' => wp_lostpassword_url(),
            'create_account_url' => home_url('/claim-account?token=test-token'),
            'profile_url' => home_url('/profile'),
            'events_url' => home_url('/events'),
            'membership_url' => home_url('/membership'),
            'volunteer_url' => home_url('/volunteer'),
            'renewal_url' => home_url('/renew-membership')
        );
    }

    /**
     * Send an email using the configured templates
     */
    public function send_people_email($email_type, $recipient_email, $template_vars = array()) {
        $options = get_option('lcd_people_email_settings', array());
        
        // Check if this email type is enabled
        $enabled = isset($options[$email_type . '_enabled']) ? $options[$email_type . '_enabled'] : 1;
        if (!$enabled) {
            return false; // Email type disabled
        }
        
        // Try Zeptomail first if available and configured
        if (class_exists('LCD_Zeptomail') && LCD_Zeptomail::get_instance()->is_enabled()) {
            $template_key = isset($options['template_mapping'][$email_type]) ? $options['template_mapping'][$email_type] : '';
            
            if (!empty($template_key)) {
                try {
                    return LCD_Zeptomail::get_instance()->send_template_email(
                        $recipient_email,
                        $template_key,
                        $template_vars
                    );
                } catch (Exception $e) {
                    error_log('LCD People: Zeptomail error for ' . $email_type . ': ' . $e->getMessage());
                    // Fall back to WP Mail
                }
            }
        }
        
        // Use WP Mail as fallback
        $subject = '';
        $content = '';
        
        if (isset($options['wpmail_templates'][$email_type])) {
            $subject = $options['wpmail_templates'][$email_type]['subject'] ?? '';
            $content = $options['wpmail_templates'][$email_type]['content'] ?? '';
        }
        
        // If no template is configured, use default
        if (empty($subject) && empty($content)) {
            $default_template = $this->get_default_template($email_type);
            $lines = explode("\n", $default_template);
            foreach ($lines as $line) {
                if (strpos($line, 'Subject:') === 0) {
                    $subject = trim(substr($line, 8));
                    break;
                }
            }
            $content = trim(str_replace('Subject: ' . $subject . "\n", '', $default_template));
        }
        
        if (empty($subject)) {
            return false;
        }
        
        // Replace template variables
        foreach ($template_vars as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }
        
        $site_name = get_bloginfo('name');
        $from_email = get_option('admin_email');
        
        return wp_mail(
            $recipient_email,
            $subject,
            $content,
            array(
                'From: ' . $site_name . ' <' . $from_email . '>',
                'Content-Type: text/plain; charset=UTF-8'
            )
        );
    }

    /**
     * Get token expiry time from settings
     */
    public function get_token_expiry_hours() {
        $options = get_option('lcd_people_email_settings', array());
        return isset($options['token_expiry_hours']) ? $options['token_expiry_hours'] : 24;
    }
} 