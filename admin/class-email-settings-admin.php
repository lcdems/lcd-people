<?php
/**
 * LCD Email Settings Administration
 * 
 * Consolidated email settings management with its own top-level menu.
 * Handles Sender.net configuration, opt-in settings, and email templates.
 * 
 * @package LCD_People
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_Email_Settings_Admin {
    private static $instance = null;
    private $plugin_instance;

    // Menu slug constants
    const MENU_SLUG = 'lcd-email-settings';
    const SENDER_CONFIG_SLUG = 'lcd-email-sender-config';
    const OPTIN_SETTINGS_SLUG = 'lcd-email-optin-settings';
    const TEMPLATES_SLUG = 'lcd-email-templates';

    public static function get_instance($plugin_instance = null) {
        if (null === self::$instance) {
            self::$instance = new self($plugin_instance);
        }
        return self::$instance;
    }

    private function __construct($plugin_instance) {
        $this->plugin_instance = $plugin_instance;
        
        // Add menu pages
        add_action('admin_menu', array($this, 'add_menu_pages'), 9);
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_lcd_email_test_sender_connection', array($this, 'ajax_test_sender_connection'));
        add_action('wp_ajax_lcd_email_test_template', array($this, 'ajax_test_template'));
        add_action('wp_ajax_lcd_email_test_wpmail', array($this, 'ajax_test_wpmail'));
        add_action('wp_ajax_lcd_email_refresh_groups', array($this, 'ajax_refresh_groups'));
    }

    /**
     * Add menu pages
     */
    public function add_menu_pages() {
        // Main Email Settings menu
        add_menu_page(
            __('Email Settings', 'lcd-people'),
            __('Email Settings', 'lcd-people'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_sender_config_page'),
            'dashicons-email-alt',
            31 // Position after Comments (25) and before Media (10) in admin menu
        );

        // Sender Config (default/first page)
        add_submenu_page(
            self::MENU_SLUG,
            __('Sender.net Configuration', 'lcd-people'),
            __('Sender Config', 'lcd-people'),
            'manage_options',
            self::MENU_SLUG, // Same as parent to make it the default
            array($this, 'render_sender_config_page')
        );

        // Opt-in Settings
        add_submenu_page(
            self::MENU_SLUG,
            __('Opt-in Form Settings', 'lcd-people'),
            __('Opt-in Settings', 'lcd-people'),
            'manage_options',
            self::OPTIN_SETTINGS_SLUG,
            array($this, 'render_optin_settings_page')
        );

        // Templates
        add_submenu_page(
            self::MENU_SLUG,
            __('Email Templates', 'lcd-people'),
            __('Templates', 'lcd-people'),
            'manage_options',
            self::TEMPLATES_SLUG,
            array($this, 'render_templates_page')
        );
    }

    /**
     * Register all settings
     */
    public function register_settings() {
        // ===========================================
        // SENDER CONFIG PAGE SETTINGS
        // ===========================================
        register_setting('lcd_email_sender_config', 'lcd_people_sender_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_email_sender_config', 'lcd_people_email_settings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_all_email_settings'),
            'default' => array()
        ));

        register_setting('lcd_email_sender_config', 'lcd_people_sender_group_assignments', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_group_assignments'),
            'default' => array()
        ));

        // Sender Config - API Section
        add_settings_section(
            'lcd_email_api_section',
            __('Sender.net API Configuration', 'lcd-people'),
            array($this, 'render_api_section'),
            self::MENU_SLUG
        );

        add_settings_field(
            'lcd_people_sender_token',
            __('API Token', 'lcd-people'),
            array($this, 'render_api_token_field'),
            self::MENU_SLUG,
            'lcd_email_api_section'
        );

        add_settings_field(
            'sender_transactional_enabled',
            __('Transactional Emails', 'lcd-people'),
            array($this, 'render_transactional_enabled_field'),
            self::MENU_SLUG,
            'lcd_email_api_section'
        );

        // Sender Config - Group Assignments Section
        add_settings_section(
            'lcd_email_groups_section',
            __('Automatic Group Assignments', 'lcd-people'),
            array($this, 'render_groups_section'),
            self::MENU_SLUG
        );

        add_settings_field(
            'lcd_people_sender_group_assignments',
            __('Group Assignments', 'lcd-people'),
            array($this, 'render_group_assignments_field'),
            self::MENU_SLUG,
            'lcd_email_groups_section'
        );

        // ===========================================
        // OPT-IN SETTINGS PAGE
        // ===========================================
        register_setting('lcd_email_optin_settings', 'lcd_people_optin_groups', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_optin_groups'),
            'default' => array()
        ));

        register_setting('lcd_email_optin_settings', 'lcd_people_optin_main_disclaimer', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default' => ''
        ));

        register_setting('lcd_email_optin_settings', 'lcd_people_optin_sms_disclaimer', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default' => ''
        ));

        register_setting('lcd_email_optin_settings', 'lcd_people_optin_google_gtag_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_email_optin_settings', 'lcd_people_optin_google_conversion_label', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_email_optin_settings', 'lcd_people_optin_facebook_pixel_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_email_optin_settings', 'lcd_people_optin_conversion_value', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        // Opt-in - Form Groups Section
        add_settings_section(
            'lcd_email_optin_groups_section',
            __('Opt-in Form Groups', 'lcd-people'),
            array($this, 'render_optin_groups_section'),
            self::OPTIN_SETTINGS_SLUG
        );

        add_settings_field(
            'lcd_people_optin_groups',
            __('Available Groups', 'lcd-people'),
            array($this, 'render_optin_groups_field'),
            self::OPTIN_SETTINGS_SLUG,
            'lcd_email_optin_groups_section'
        );

        // Opt-in - Disclaimers Section
        add_settings_section(
            'lcd_email_optin_disclaimers_section',
            __('Form Disclaimers', 'lcd-people'),
            array($this, 'render_disclaimers_section'),
            self::OPTIN_SETTINGS_SLUG
        );

        add_settings_field(
            'lcd_people_optin_main_disclaimer',
            __('Main Disclaimer', 'lcd-people'),
            array($this, 'render_main_disclaimer_field'),
            self::OPTIN_SETTINGS_SLUG,
            'lcd_email_optin_disclaimers_section'
        );

        add_settings_field(
            'lcd_people_optin_sms_disclaimer',
            __('SMS Disclaimer', 'lcd-people'),
            array($this, 'render_sms_disclaimer_field'),
            self::OPTIN_SETTINGS_SLUG,
            'lcd_email_optin_disclaimers_section'
        );

        // Opt-in - Conversion Tracking Section
        add_settings_section(
            'lcd_email_optin_tracking_section',
            __('Conversion Tracking', 'lcd-people'),
            array($this, 'render_tracking_section'),
            self::OPTIN_SETTINGS_SLUG
        );

        add_settings_field(
            'lcd_people_optin_google_gtag_id',
            __('Google Analytics 4 Tag ID', 'lcd-people'),
            array($this, 'render_google_gtag_field'),
            self::OPTIN_SETTINGS_SLUG,
            'lcd_email_optin_tracking_section'
        );

        add_settings_field(
            'lcd_people_optin_google_conversion_label',
            __('Google Ads Conversion Label', 'lcd-people'),
            array($this, 'render_google_conversion_field'),
            self::OPTIN_SETTINGS_SLUG,
            'lcd_email_optin_tracking_section'
        );

        add_settings_field(
            'lcd_people_optin_facebook_pixel_id',
            __('Facebook Pixel ID', 'lcd-people'),
            array($this, 'render_facebook_pixel_field'),
            self::OPTIN_SETTINGS_SLUG,
            'lcd_email_optin_tracking_section'
        );

        add_settings_field(
            'lcd_people_optin_conversion_value',
            __('Conversion Value', 'lcd-people'),
            array($this, 'render_conversion_value_field'),
            self::OPTIN_SETTINGS_SLUG,
            'lcd_email_optin_tracking_section'
        );

        // ===========================================
        // TEMPLATES PAGE SETTINGS
        // ===========================================
        // Note: lcd_people_email_settings is already registered above with sanitize_all_email_settings
        // We register it again here for the templates settings group
        register_setting('lcd_email_templates', 'lcd_people_email_settings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_all_email_settings'),
            'default' => array()
        ));

        // Templates - Email Controls Section  
        add_settings_section(
            'lcd_email_controls_section',
            __('Email Type Controls', 'lcd-people'),
            array($this, 'render_email_controls_section'),
            self::TEMPLATES_SLUG
        );

        // Templates - Sender.net Campaigns Section
        add_settings_section(
            'lcd_email_sender_templates_section',
            __('Sender.net Transactional Campaigns', 'lcd-people'),
            array($this, 'render_sender_templates_section'),
            self::TEMPLATES_SLUG
        );

        // Templates - WordPress Mail Section
        add_settings_section(
            'lcd_email_wpmail_section',
            __('WordPress Mail Templates (Fallback)', 'lcd-people'),
            array($this, 'render_wpmail_section'),
            self::TEMPLATES_SLUG
        );

        // Add fields for each email type
        foreach ($this->get_email_types() as $type => $label) {
            // Email enabled toggle
            add_settings_field(
                'email_enabled_' . $type,
                $label,
                array($this, 'render_email_enabled_field'),
                self::TEMPLATES_SLUG,
                'lcd_email_controls_section',
                array('type' => $type, 'label' => $label)
            );

            // Sender.net campaign ID field
            add_settings_field(
                'sender_campaign_' . $type,
                $label,
                array($this, 'render_sender_campaign_field'),
                self::TEMPLATES_SLUG,
                'lcd_email_sender_templates_section',
                array('type' => $type, 'label' => $label)
            );

            // WordPress mail template
            add_settings_field(
                'wpmail_template_' . $type,
                $label,
                array($this, 'render_wpmail_template_field'),
                self::TEMPLATES_SLUG,
                'lcd_email_wpmail_section',
                array('type' => $type, 'label' => $label)
            );
        }

        // Token expiry field
        add_settings_field(
            'token_expiry_hours',
            __('Claim Token Expiry (Hours)', 'lcd-people'),
            array($this, 'render_token_expiry_field'),
            self::TEMPLATES_SLUG,
            'lcd_email_controls_section'
        );
    }

    /**
     * Get email types
     */
    public function get_email_types() {
        return array(
            'claim_existing_user' => __('Account Claim - Existing User', 'lcd-people'),
            'claim_create_account' => __('Account Claim - Create Account', 'lcd-people'),
            'claim_no_records' => __('Account Claim - No Records Found', 'lcd-people')
        );
    }

    /**
     * Get email type descriptions
     */
    public function get_email_type_descriptions() {
        return array(
            'claim_existing_user' => __('Sent when someone tries to claim an account but already has a WordPress user account.', 'lcd-people'),
            'claim_create_account' => __('Sent when someone can create an account for their existing member record.', 'lcd-people'),
            'claim_no_records' => __('Sent when someone tries to claim an account but no member records are found.', 'lcd-people')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Check if we're on one of our pages
        $our_pages = array(
            'toplevel_page_' . self::MENU_SLUG,
            'email-settings_page_' . self::OPTIN_SETTINGS_SLUG,
            'email-settings_page_' . self::TEMPLATES_SLUG
        );

        if (!in_array($hook, $our_pages)) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue admin styles
        wp_enqueue_style(
            'lcd-email-settings-admin',
            plugins_url('assets/css/email-settings-admin.css', dirname(__FILE__)),
            array(),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/email-settings-admin.css')
        );

        // Inline script for AJAX functionality
        $inline_script = $this->get_admin_inline_script();
        wp_add_inline_script('jquery', $inline_script);
    }

    /**
     * Get inline admin script
     */
    private function get_admin_inline_script() {
        return "
        jQuery(document).ready(function($) {
            // Test Sender.net connection
            $('#test-sender-connection').on('click', function() {
                var btn = $(this);
                var result = $('#sender-connection-result');
                
                btn.prop('disabled', true).text('" . esc_js(__('Testing...', 'lcd-people')) . "');
                result.html('<span style=\"color: #666;\">" . esc_js(__('Testing connection...', 'lcd-people')) . "</span>');
                
                $.post(ajaxurl, {
                    action: 'lcd_email_test_sender_connection',
                    nonce: '" . wp_create_nonce('lcd_email_admin') . "'
                }, function(response) {
                    btn.prop('disabled', false).text('" . esc_js(__('Test Connection', 'lcd-people')) . "');
                    if (response.success) {
                        result.html('<span style=\"color: #46b450;\">✓ ' + response.data.message + '</span>');
                    } else {
                        result.html('<span style=\"color: #dc3232;\">✗ ' + response.data.message + '</span>');
                    }
                });
            });

            // Refresh groups
            $('#refresh-sender-groups').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('" . esc_js(__('Refreshing...', 'lcd-people')) . "');
                
                $.post(ajaxurl, {
                    action: 'lcd_email_refresh_groups',
                    nonce: '" . wp_create_nonce('lcd_email_admin') . "'
                }, function(response) {
                    btn.prop('disabled', false).text('" . esc_js(__('Refresh Groups', 'lcd-people')) . "');
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                });
            });

            // Test template email
            $(document).on('click', '.test-template-btn', function() {
                var btn = $(this);
                var type = btn.data('email-type');
                var campaignId = btn.data('campaign-id');
                
                var email = prompt('" . esc_js(__('Enter email address to send test to:', 'lcd-people')) . "');
                if (!email) return;
                
                btn.prop('disabled', true).text('" . esc_js(__('Sending...', 'lcd-people')) . "');
                
                $.post(ajaxurl, {
                    action: 'lcd_email_test_template',
                    nonce: '" . wp_create_nonce('lcd_email_admin') . "',
                    email_type: type,
                    campaign_id: campaignId,
                    test_email: email
                }, function(response) {
                    btn.prop('disabled', false).text('" . esc_js(__('Test', 'lcd-people')) . "');
                    var result = $('#test-result-' + type);
                    if (response.success) {
                        result.html('<span style=\"color: #46b450;\">✓ ' + response.data.message + '</span>').show();
                    } else {
                        result.html('<span style=\"color: #dc3232;\">✗ ' + response.data.message + '</span>').show();
                    }
                });
            });

            // Toggle test button visibility on input
            $(document).on('input', '.campaign-id-input', function() {
                var input = $(this);
                var btn = input.siblings('.test-template-btn');
                if (input.val().trim()) {
                    btn.data('campaign-id', input.val().trim()).show();
                } else {
                    btn.hide();
                }
            });

            // Sortable opt-in groups
            if ($('#sortable-optin-groups').length) {
                $('#sortable-optin-groups').sortable({
                    handle: '.drag-handle',
                    update: function() {
                        updateGroupOrders();
                    }
                });
            }

            // Add opt-in group
            $('#add-optin-group-btn').on('click', function() {
                var select = $('#add-optin-group');
                var groupId = select.val();
                var groupName = select.find('option:selected').text();
                
                if (!groupId) {
                    alert('" . esc_js(__('Please select a group to add.', 'lcd-people')) . "');
                    return;
                }
                
                var nextOrder = $('#sortable-optin-groups .optin-group-item').length + 1;
                
                var newItem = '<div class=\"optin-group-item\" data-group-id=\"' + groupId + '\">' +
                    '<span class=\"drag-handle dashicons dashicons-menu\"></span>' +
                    '<span class=\"group-name\">' + $('<div>').text(groupName).html() + '</span>' +
                    '<label>' +
                        '<input type=\"checkbox\" name=\"lcd_people_optin_groups[' + groupId + '][default]\" value=\"1\"> " . esc_js(__('Default', 'lcd-people')) . "' +
                    '</label>' +
                    '<input type=\"hidden\" class=\"group-order-input\" name=\"lcd_people_optin_groups[' + groupId + '][order]\" value=\"' + nextOrder + '\">' +
                    '<button type=\"button\" class=\"button-link remove-group\" data-group-id=\"' + groupId + '\">' +
                        '<span class=\"dashicons dashicons-no\"></span>' +
                    '</button>' +
                '</div>';
                
                $('#sortable-optin-groups').append(newItem);
                
                // Remove from dropdown
                select.find('option[value=\"' + groupId + '\"]').remove();
                select.val('');
                
                updateGroupOrders();
            });

            // Remove opt-in group
            $(document).on('click', '.remove-group', function() {
                var btn = $(this);
                var groupId = btn.data('group-id');
                var groupItem = btn.closest('.optin-group-item');
                var groupName = groupItem.find('.group-name').text();
                
                // Add back to dropdown
                $('#add-optin-group').append('<option value=\"' + groupId + '\">' + $('<div>').text(groupName).html() + '</option>');
                
                // Remove from list
                groupItem.remove();
                
                updateGroupOrders();
            });
        });

        function updateGroupOrders() {
            jQuery('#sortable-optin-groups .optin-group-item').each(function(index) {
                jQuery(this).find('.group-order-input').val(index + 1);
            });
        }
        ";
    }

    // ===========================================
    // PAGE RENDER METHODS
    // ===========================================

    /**
     * Render Sender Config page
     */
    public function render_sender_config_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Sender.net Configuration', 'lcd-people'); ?></h1>
            
            <?php $this->render_connection_status(); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('lcd_email_sender_config');
                do_settings_sections(self::MENU_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render Opt-in Settings page
     */
    public function render_optin_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Opt-in Form Settings', 'lcd-people'); ?></h1>
            
            <p class="description">
                <?php _e('Configure the opt-in form that collects email addresses and phone numbers. Use the shortcode', 'lcd-people'); ?>
                <code>[lcd_optin_form]</code>
                <?php _e('to display the form on any page.', 'lcd-people'); ?>
            </p>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('lcd_email_optin_settings');
                do_settings_sections(self::OPTIN_SETTINGS_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render Templates page
     */
    public function render_templates_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Email Templates', 'lcd-people'); ?></h1>
            
            <?php $this->render_template_status(); ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('lcd_email_templates');
                do_settings_sections(self::TEMPLATES_SLUG);
                submit_button();
                ?>
            </form>
            
            <?php $this->render_template_variables_help(); ?>
        </div>
        <?php
    }

    /**
     * Render connection status box
     */
    private function render_connection_status() {
        $token = get_option('lcd_people_sender_token');
        ?>
        <div class="card" style="max-width: 600px; margin-bottom: 20px;">
            <h2><?php _e('Connection Status', 'lcd-people'); ?></h2>
            <?php if (!empty($token)) : ?>
                <p style="color: #46b450;">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php _e('API Token configured', 'lcd-people'); ?>
                </p>
            <?php else : ?>
                <p style="color: #dc3232;">
                    <span class="dashicons dashicons-warning"></span>
                    <?php _e('API Token not configured', 'lcd-people'); ?>
                </p>
            <?php endif; ?>
            <p>
                <button type="button" id="test-sender-connection" class="button button-secondary">
                    <?php _e('Test Connection', 'lcd-people'); ?>
                </button>
                <button type="button" id="refresh-sender-groups" class="button button-secondary">
                    <?php _e('Refresh Groups', 'lcd-people'); ?>
                </button>
            </p>
            <div id="sender-connection-result"></div>
        </div>
        <?php
    }

    /**
     * Render template status
     */
    private function render_template_status() {
        $options = get_option('lcd_people_email_settings', array());
        $sender_enabled = !empty($options['sender_transactional_enabled']) && !empty(get_option('lcd_people_sender_token'));
        ?>
        <div class="notice <?php echo $sender_enabled ? 'notice-success' : 'notice-warning'; ?>">
            <p>
                <?php if ($sender_enabled) : ?>
                    <strong><?php _e('✓ Sender.net Transactional Emails Enabled', 'lcd-people'); ?></strong> - 
                    <?php _e('Emails will be sent via Sender.net transactional campaigns.', 'lcd-people'); ?>
                <?php else : ?>
                    <strong><?php _e('Using WordPress wp_mail()', 'lcd-people'); ?></strong> - 
                    <?php _e('Enable Sender.net in', 'lcd-people'); ?>
                    <a href="<?php echo admin_url('admin.php?page=' . self::MENU_SLUG); ?>"><?php _e('Sender Config', 'lcd-people'); ?></a>
                    <?php _e('for better deliverability.', 'lcd-people'); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    // ===========================================
    // SECTION RENDER METHODS
    // ===========================================

    public function render_api_section() {
        echo '<p>' . __('Configure your Sender.net API connection. Get your API token from your Sender.net account settings.', 'lcd-people') . '</p>';
    }

    public function render_groups_section() {
        echo '<p>' . __('Automatically add subscribers to groups when they perform certain actions.', 'lcd-people') . '</p>';
    }

    public function render_optin_groups_section() {
        echo '<p>' . __('Select which groups appear in the opt-in form for users to choose from. Drag to reorder.', 'lcd-people') . '</p>';
    }

    public function render_disclaimers_section() {
        echo '<p>' . __('Configure the legal disclaimers shown on the opt-in form.', 'lcd-people') . '</p>';
    }

    public function render_tracking_section() {
        echo '<p>' . __('Track opt-in form conversions with Google Analytics and Facebook.', 'lcd-people') . '</p>';
    }

    public function render_sender_templates_section() {
        $options = get_option('lcd_people_email_settings', array());
        $sender_enabled = !empty($options['sender_transactional_enabled']);
        
        if (!$sender_enabled) {
            echo '<p class="notice notice-warning inline">' . __('Sender.net transactional emails are disabled. Enable them in Sender Config to use campaign templates.', 'lcd-people') . '</p>';
        } else {
            echo '<p>' . __('Enter the transactional campaign IDs from your Sender.net dashboard.', 'lcd-people') . '</p>';
        }
    }

    public function render_email_controls_section() {
        // Hidden field to indicate Templates page was submitted (for checkbox handling)
        echo '<input type="hidden" name="lcd_people_email_settings[_templates_submitted]" value="1">';
        echo '<p>' . __('Enable or disable specific email types and configure general settings.', 'lcd-people') . '</p>';
    }

    public function render_wpmail_section() {
        echo '<p>' . __('WordPress mail templates are used as a fallback when Sender.net is not available.', 'lcd-people') . '</p>';
    }

    // ===========================================
    // FIELD RENDER METHODS - SENDER CONFIG
    // ===========================================

    public function render_api_token_field() {
        $value = get_option('lcd_people_sender_token', '');
        ?>
        <input type="password" 
               name="lcd_people_sender_token" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               placeholder="<?php esc_attr_e('Enter your Sender.net API token', 'lcd-people'); ?>">
        <p class="description">
            <?php _e('Find your API token in Sender.net → Settings → API access tokens', 'lcd-people'); ?>
        </p>
        <?php
    }

    public function render_transactional_enabled_field() {
        $options = get_option('lcd_people_email_settings', array());
        $value = !empty($options['sender_transactional_enabled']);
        $token = get_option('lcd_people_sender_token');
        ?>
        <!-- Hidden field to indicate this field was submitted (checkboxes don't submit when unchecked) -->
        <input type="hidden" name="lcd_people_email_settings[_sender_config_submitted]" value="1">
        <label>
            <input type="checkbox" 
                   name="lcd_people_email_settings[sender_transactional_enabled]" 
                   value="1" 
                   <?php checked($value); ?>
                   <?php disabled(empty($token)); ?>>
            <?php _e('Enable Sender.net for transactional emails (account claim, etc.)', 'lcd-people'); ?>
        </label>
        <?php if (empty($token)) : ?>
            <p class="description" style="color: #dc3232;"><?php _e('Configure API token first to enable.', 'lcd-people'); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_group_assignments_field() {
        $assignments = get_option('lcd_people_sender_group_assignments', array());
        $groups = $this->get_sender_groups();
        
        if (empty($groups)) {
            echo '<p class="notice notice-warning inline">' . __('No groups found. Check your API token or click Refresh Groups.', 'lcd-people') . '</p>';
            return;
        }

        $assignment_types = array(
            'new_member' => __('New Member Groups', 'lcd-people'),
            'new_volunteer' => __('New Volunteer Groups', 'lcd-people'),
            'email_optin' => __('Email Opt-in Groups', 'lcd-people'),
            'sms_optin' => __('SMS Opt-in Groups', 'lcd-people')
        );

        foreach ($assignment_types as $key => $label) :
            $current = $this->normalize_to_array($assignments[$key] ?? array());
        ?>
        <div style="margin-bottom: 15px;">
            <label><strong><?php echo esc_html($label); ?></strong></label>
            <select name="lcd_people_sender_group_assignments[<?php echo esc_attr($key); ?>][]" 
                    multiple="multiple" 
                    style="width: 100%; max-width: 400px; min-height: 80px;">
                <?php foreach ($groups as $id => $name) : ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected(in_array($id, $current)); ?>>
                        <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endforeach;
    }

    // ===========================================
    // FIELD RENDER METHODS - OPT-IN SETTINGS
    // ===========================================

    public function render_optin_groups_field() {
        $optin_groups = get_option('lcd_people_optin_groups', array());
        $available_groups = $this->get_sender_groups();
        
        if (empty($available_groups)) {
            echo '<p class="notice notice-warning inline">' . __('No groups found. Configure API token in Sender Config first.', 'lcd-people') . '</p>';
            return;
        }

        // Sort by order
        uasort($optin_groups, function($a, $b) {
            return ($a['order'] ?? 999) - ($b['order'] ?? 999);
        });
        ?>
        <div id="optin-groups-manager">
            <div id="sortable-optin-groups" class="sortable-groups">
                <?php foreach ($optin_groups as $group_id => $group_data) : 
                    if (!isset($available_groups[$group_id])) continue;
                ?>
                <div class="optin-group-item" data-group-id="<?php echo esc_attr($group_id); ?>">
                    <span class="drag-handle dashicons dashicons-menu"></span>
                    <span class="group-name"><?php echo esc_html($available_groups[$group_id]); ?></span>
                    <label>
                        <input type="checkbox" 
                               name="lcd_people_optin_groups[<?php echo esc_attr($group_id); ?>][default]" 
                               value="1"
                               <?php checked(!empty($group_data['default'])); ?>>
                        <?php _e('Default', 'lcd-people'); ?>
                    </label>
                    <input type="hidden" 
                           class="group-order-input"
                           name="lcd_people_optin_groups[<?php echo esc_attr($group_id); ?>][order]" 
                           value="<?php echo esc_attr($group_data['order'] ?? 1); ?>">
                    <button type="button" class="button-link remove-group" data-group-id="<?php echo esc_attr($group_id); ?>">
                        <span class="dashicons dashicons-no"></span>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div style="margin-top: 15px;">
                <select id="add-optin-group">
                    <option value=""><?php _e('Add a group...', 'lcd-people'); ?></option>
                    <?php foreach ($available_groups as $id => $name) : 
                        if (isset($optin_groups[$id])) continue;
                    ?>
                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="add-optin-group-btn" class="button"><?php _e('Add Group', 'lcd-people'); ?></button>
            </div>
        </div>
        <?php
    }

    public function render_main_disclaimer_field() {
        $value = get_option('lcd_people_optin_main_disclaimer', '');
        ?>
        <textarea name="lcd_people_optin_main_disclaimer" rows="3" class="large-text"><?php echo esc_textarea($value); ?></textarea>
        <p class="description"><?php _e('Shown at the bottom of the opt-in form. Leave empty to hide.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_sms_disclaimer_field() {
        $value = get_option('lcd_people_optin_sms_disclaimer', '');
        ?>
        <textarea name="lcd_people_optin_sms_disclaimer" rows="3" class="large-text"><?php echo esc_textarea($value); ?></textarea>
        <p class="description"><?php _e('Shown next to the SMS consent checkbox.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_google_gtag_field() {
        $value = get_option('lcd_people_optin_google_gtag_id', '');
        ?>
        <input type="text" name="lcd_people_optin_google_gtag_id" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="G-XXXXXXXXXX">
        <?php
    }

    public function render_google_conversion_field() {
        $value = get_option('lcd_people_optin_google_conversion_label', '');
        ?>
        <input type="text" name="lcd_people_optin_google_conversion_label" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="AW-XXXXXXXXX/XXXXXXXXXXX">
        <?php
    }

    public function render_facebook_pixel_field() {
        $value = get_option('lcd_people_optin_facebook_pixel_id', '');
        ?>
        <input type="text" name="lcd_people_optin_facebook_pixel_id" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="XXXXXXXXXXXXXXXX">
        <?php
    }

    public function render_conversion_value_field() {
        $value = get_option('lcd_people_optin_conversion_value', '');
        ?>
        <input type="text" name="lcd_people_optin_conversion_value" value="<?php echo esc_attr($value); ?>" class="small-text" placeholder="1.00">
        <p class="description"><?php _e('Optional monetary value for conversion tracking.', 'lcd-people'); ?></p>
        <?php
    }

    // ===========================================
    // FIELD RENDER METHODS - TEMPLATES
    // ===========================================

    public function render_sender_campaign_field($args) {
        $options = get_option('lcd_people_email_settings', array());
        $campaign_id = $options['sender_campaigns'][$args['type']] ?? '';
        $sender_enabled = !empty($options['sender_transactional_enabled']);
        $descriptions = $this->get_email_type_descriptions();
        
        if (!$sender_enabled) {
            echo '<p class="description" style="color: #666; font-style: italic;">' . __('Enable Sender.net transactional emails in Sender Config first.', 'lcd-people') . '</p>';
            return;
        }
        ?>
        <div class="campaign-field-wrapper">
            <p class="description" style="margin-bottom: 8px; padding: 8px; background: #f9f9f9; border-left: 4px solid #72aee6;">
                <?php echo esc_html($descriptions[$args['type']] ?? ''); ?>
            </p>
            <input type="text" 
                   name="lcd_people_email_settings[sender_campaigns][<?php echo esc_attr($args['type']); ?>]" 
                   value="<?php echo esc_attr($campaign_id); ?>" 
                   class="regular-text campaign-id-input"
                   placeholder="<?php esc_attr_e('Transactional campaign ID', 'lcd-people'); ?>">
            <button type="button" 
                    class="button test-template-btn" 
                    data-email-type="<?php echo esc_attr($args['type']); ?>"
                    data-campaign-id="<?php echo esc_attr($campaign_id); ?>"
                    style="<?php echo empty($campaign_id) ? 'display:none;' : ''; ?>">
                <?php _e('Test', 'lcd-people'); ?>
            </button>
            <div id="test-result-<?php echo esc_attr($args['type']); ?>" style="margin-top: 5px;"></div>
        </div>
        <?php
    }

    public function render_email_enabled_field($args) {
        $options = get_option('lcd_people_email_settings', array());
        $enabled = isset($options[$args['type'] . '_enabled']) ? $options[$args['type'] . '_enabled'] : 1;
        ?>
        <label>
            <input type="checkbox" 
                   name="lcd_people_email_settings[<?php echo esc_attr($args['type']); ?>_enabled]" 
                   value="1" 
                   <?php checked($enabled); ?>>
            <?php printf(__('Enable %s emails', 'lcd-people'), $args['label']); ?>
        </label>
        <?php
    }

    public function render_token_expiry_field() {
        $options = get_option('lcd_people_email_settings', array());
        $value = $options['token_expiry_hours'] ?? 24;
        ?>
        <input type="number" 
               name="lcd_people_email_settings[token_expiry_hours]" 
               value="<?php echo esc_attr($value); ?>" 
               min="1" 
               max="168" 
               class="small-text">
        <?php _e('hours', 'lcd-people'); ?>
        <p class="description"><?php _e('How long account claim tokens remain valid (1-168 hours).', 'lcd-people'); ?></p>
        <?php
    }

    public function render_wpmail_template_field($args) {
        $options = get_option('lcd_people_email_settings', array());
        $enabled = isset($options[$args['type'] . '_enabled']) ? $options[$args['type'] . '_enabled'] : 1;
        
        if (!$enabled) {
            echo '<p class="description" style="color: #666; font-style: italic;">' . 
                 sprintf(__('%s emails are disabled.', 'lcd-people'), $args['label']) . '</p>';
            return;
        }
        
        $subject = $options['wpmail_templates'][$args['type']]['subject'] ?? '';
        $content = $options['wpmail_templates'][$args['type']]['content'] ?? '';
        
        // Get default template if empty
        if (empty($subject) && empty($content)) {
            $default = $this->get_default_template($args['type']);
            if (preg_match('/^Subject: (.+)$/m', $default, $matches)) {
                $subject = $matches[1];
            }
            $content = preg_replace('/^Subject: .+\n/m', '', $default);
        }
        ?>
        <div class="wpmail-template-wrapper" style="margin-bottom: 20px;">
            <label><strong><?php _e('Subject:', 'lcd-people'); ?></strong></label>
            <input type="text" 
                   name="lcd_people_email_settings[wpmail_templates][<?php echo esc_attr($args['type']); ?>][subject]" 
                   value="<?php echo esc_attr($subject); ?>" 
                   class="large-text"
                   style="margin-bottom: 10px;">
            
            <label><strong><?php _e('Content:', 'lcd-people'); ?></strong></label>
            <textarea name="lcd_people_email_settings[wpmail_templates][<?php echo esc_attr($args['type']); ?>][content]" 
                      rows="8" 
                      class="large-text" 
                      style="font-family: monospace;"><?php echo esc_textarea($content); ?></textarea>
        </div>
        <?php
    }

    /**
     * Render template variables help
     */
    private function render_template_variables_help() {
        ?>
        <div class="card" style="margin-top: 20px; max-width: 800px;">
            <h3><?php _e('Template Variables', 'lcd-people'); ?></h3>
            <p><?php _e('Use these variables in your templates:', 'lcd-people'); ?></p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h4><?php _e('User Variables', 'lcd-people'); ?></h4>
                    <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{name}}</code>, <code>{{email}}</code>
                    
                    <h4><?php _e('Membership Variables', 'lcd-people'); ?></h4>
                    <code>{{membership_status}}</code>, <code>{{start_date}}</code>, <code>{{end_date}}</code>
                </div>
                <div>
                    <h4><?php _e('URL Variables', 'lcd-people'); ?></h4>
                    <code>{{login_url}}</code>, <code>{{create_account_url}}</code>, <code>{{reset_password_url}}</code>
                    
                    <h4><?php _e('Site Variables', 'lcd-people'); ?></h4>
                    <code>{{site_name}}</code>, <code>{{site_url}}</code>, <code>{{contact_email}}</code>
                </div>
            </div>
        </div>
        <?php
    }

    // ===========================================
    // SANITIZATION METHODS
    // ===========================================

    /**
     * Unified sanitize callback for all email settings
     * Handles fields from both Sender Config and Templates pages
     */
    public function sanitize_all_email_settings($input) {
        $existing = get_option('lcd_people_email_settings', array());
        $sanitized = $existing;
        
        if (!is_array($input)) {
            return $sanitized;
        }

        // === From Sender Config page ===
        
        // Check if Sender Config page was submitted (via hidden field)
        if (!empty($input['_sender_config_submitted'])) {
            // Transactional enabled checkbox
            $sanitized['sender_transactional_enabled'] = !empty($input['sender_transactional_enabled']) ? 1 : 0;
        }

        // === From Templates page ===
        
        // Check if Templates page was submitted (via hidden field)
        if (!empty($input['_templates_submitted'])) {
            // Email type enabled toggles (checkboxes)
            foreach (array_keys($this->get_email_types()) as $type) {
                $key = $type . '_enabled';
                $sanitized[$key] = isset($input[$key]) ? 1 : 0;
            }
        }

        // Token expiry
        if (isset($input['token_expiry_hours'])) {
            $sanitized['token_expiry_hours'] = max(1, min(168, intval($input['token_expiry_hours'])));
        }

        // Sender campaign IDs
        if (isset($input['sender_campaigns']) && is_array($input['sender_campaigns'])) {
            if (!isset($sanitized['sender_campaigns'])) {
                $sanitized['sender_campaigns'] = array();
            }
            foreach ($input['sender_campaigns'] as $type => $id) {
                $sanitized['sender_campaigns'][$type] = sanitize_text_field($id);
            }
        }

        // WP Mail templates
        if (isset($input['wpmail_templates']) && is_array($input['wpmail_templates'])) {
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

        return $sanitized;
    }

    public function sanitize_group_assignments($input) {
        if (!is_array($input)) {
            return array();
        }

        $sanitized = array();
        foreach ($input as $key => $values) {
            if (is_array($values)) {
                $sanitized[$key] = array_map('sanitize_text_field', $values);
            } else {
                $sanitized[$key] = array(sanitize_text_field($values));
            }
        }

        return $sanitized;
    }

    public function sanitize_optin_groups($input) {
        if (!is_array($input)) {
            return array();
        }

        $sanitized = array();
        foreach ($input as $group_id => $data) {
            $sanitized[sanitize_text_field($group_id)] = array(
                'order' => intval($data['order'] ?? 1),
                'default' => !empty($data['default'])
            );
        }

        return $sanitized;
    }

    // ===========================================
    // AJAX HANDLERS
    // ===========================================

    public function ajax_test_sender_connection() {
        check_ajax_referer('lcd_email_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')));
        }

        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            wp_send_json_error(array('message' => __('API token not configured.', 'lcd-people')));
        }

        $response = wp_remote_get('https://api.sender.net/v2/groups', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            wp_send_json_success(array('message' => __('Connection successful!', 'lcd-people')));
        } else {
            wp_send_json_error(array('message' => sprintf(__('API returned status %d', 'lcd-people'), $code)));
        }
    }

    public function ajax_refresh_groups() {
        check_ajax_referer('lcd_email_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')));
        }

        delete_transient('lcd_people_sender_groups');
        $groups = $this->get_sender_groups();

        if (!empty($groups)) {
            wp_send_json_success(array('message' => sprintf(__('Refreshed %d groups.', 'lcd-people'), count($groups))));
        } else {
            wp_send_json_error(array('message' => __('No groups found or API error.', 'lcd-people')));
        }
    }

    public function ajax_test_template() {
        check_ajax_referer('lcd_email_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')));
        }

        $email_type = sanitize_text_field($_POST['email_type'] ?? '');
        $campaign_id = sanitize_text_field($_POST['campaign_id'] ?? '');
        $test_email = sanitize_email($_POST['test_email'] ?? '');

        if (empty($campaign_id) || empty($test_email)) {
            wp_send_json_error(array('message' => __('Missing required fields.', 'lcd-people')));
        }

        $sender_handler = $this->get_sender_handler();
        if (!$sender_handler) {
            wp_send_json_error(array('message' => __('Sender handler not available.', 'lcd-people')));
        }

        $result = $sender_handler->test_transactional_connection($campaign_id, $test_email);

        if ($result === true) {
            wp_send_json_success(array('message' => __('Test email sent!', 'lcd-people')));
        } elseif (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_error(array('message' => $sender_handler->get_last_email_error() ?: __('Failed to send.', 'lcd-people')));
        }
    }

    // ===========================================
    // HELPER METHODS
    // ===========================================

    private function get_sender_handler() {
        if ($this->plugin_instance && method_exists($this->plugin_instance, 'get_sender_handler')) {
            return $this->plugin_instance->get_sender_handler();
        }
        
        if (class_exists('LCD_People_Sender_Handler') && class_exists('LCD_People')) {
            return new LCD_People_Sender_Handler(LCD_People::get_instance());
        }
        
        return null;
    }

    private function get_sender_groups() {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            return array();
        }

        $cached = get_transient('lcd_people_sender_groups');
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get('https://api.sender.net/v2/groups', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ),
            'timeout' => 15
        ));

        $groups = array();
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['data']) && is_array($body['data'])) {
                foreach ($body['data'] as $group) {
                    if (isset($group['id']) && isset($group['title'])) {
                        $groups[$group['id']] = $group['title'];
                    }
                }
            }
        }

        set_transient('lcd_people_sender_groups', $groups, HOUR_IN_SECONDS);
        return $groups;
    }

    private function normalize_to_array($value) {
        if (is_array($value)) {
            return $value;
        }
        if (empty($value)) {
            return array();
        }
        return array($value);
    }

    private function get_default_template($type) {
        $site_name = get_bloginfo('name');
        
        $templates = array(
            'claim_existing_user' => "Subject: Account Access Instructions - {$site_name}\n\nHello!\n\nYou requested account access for {$site_name}.\n\nGood news! You already have an account with us.\n\nLogin: {{login_url}}\nReset Password: {{reset_password_url}}\n\nBest regards,\n{$site_name} Team",
            
            'claim_create_account' => "Subject: Create Your Account - {$site_name}\n\nHello {{first_name}}!\n\nWe found your record! Create your account:\n\nName: {{name}}\nEmail: {{email}}\nStatus: {{membership_status}}\n\nCreate Account: {{create_account_url}}\n\nThis link expires in {{token_expiry_hours}} hours.\n\nBest regards,\n{$site_name} Team",
            
            'claim_no_records' => "Subject: Account Access Instructions - {$site_name}\n\nHello!\n\nWe couldn't find any records for this email.\n\nTo get started:\n- Become a Member: {{membership_url}}\n- Volunteer: {{volunteer_url}}\n- Contact Us: {{contact_email}}\n\nBest regards,\n{$site_name} Team"
        );

        return $templates[$type] ?? '';
    }

    // ===========================================
    // EMAIL SENDING METHODS
    // ===========================================

    /**
     * Check if Sender.net transactional emails are enabled
     */
    public function is_sender_transactional_enabled() {
        $token = get_option('lcd_people_sender_token');
        $options = get_option('lcd_people_email_settings', array());
        $enabled = isset($options['sender_transactional_enabled']) ? $options['sender_transactional_enabled'] : 0;
        return !empty($token) && $enabled;
    }

    /**
     * Get token expiry time from settings
     */
    public function get_token_expiry_hours() {
        $options = get_option('lcd_people_email_settings', array());
        return isset($options['token_expiry_hours']) ? $options['token_expiry_hours'] : 24;
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
        
        // Try Sender.net first if enabled and configured
        if ($this->is_sender_transactional_enabled()) {
            $campaign_id = isset($options['sender_campaigns'][$email_type]) ? $options['sender_campaigns'][$email_type] : '';
            
            if (!empty($campaign_id)) {
                $sender_handler = $this->get_sender_handler();
                if ($sender_handler) {
                    try {
                        $result = $sender_handler->send_transactional_email(
                            $recipient_email,
                            $campaign_id,
                            $template_vars
                        );
                        
                        if ($result) {
                            return true;
                        }
                        
                        error_log('LCD People: Sender.net error for ' . $email_type . ': ' . $sender_handler->get_last_email_error());
                        // Fall back to WP Mail
                    } catch (Exception $e) {
                        error_log('LCD People: Sender.net exception for ' . $email_type . ': ' . $e->getMessage());
                        // Fall back to WP Mail
                    }
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
}

