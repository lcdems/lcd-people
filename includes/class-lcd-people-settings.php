<?php
/**
 * LCD People Settings Class
 * 
 * Handles all admin settings pages and configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_People_Settings {
    
    private $main_plugin;
    
    public function __construct($main_plugin) {
        $this->main_plugin = $main_plugin;
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only enqueue on our settings pages
        if (strpos($hook, 'lcd-people-sender-settings') !== false) {
            wp_enqueue_script('jquery-ui-sortable');
        }
    }

    /**
     * Add the settings page to the admin menu
     */
    public function add_settings_page() {
        // ActBlue Settings
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            __('ActBlue Integration Settings', 'lcd-people'),
            __('ActBlue Settings', 'lcd-people'),
            'manage_options',
            'lcd-people-actblue-settings',
            array($this, 'render_settings_page')
        );

        // Sender.net Settings
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            __('Sender.net Integration Settings', 'lcd-people'),
            __('Sender.net Settings', 'lcd-people'),
            'manage_options',
            'lcd-people-sender-settings',
            array($this, 'render_sender_settings_page')
        );

        // Forminator Integration Settings
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            __('Forminator Integration Settings', 'lcd-people'),
            __('Forminator Settings', 'lcd-people'),
            'manage_options',
            'lcd-people-forminator-settings',
            array($this, 'render_forminator_settings_page')
        );

        // User Connection Management
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            __('User Connection Management', 'lcd-people'),
            __('User Connections', 'lcd-people'),
            'manage_options',
            'lcd-people-user-connections',
            array($this, 'render_user_connections_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // ActBlue settings
        register_setting('lcd_people_actblue_settings', 'lcd_people_actblue_username', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_actblue_settings', 'lcd_people_actblue_password', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_actblue_settings', 'lcd_people_actblue_dues_form', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'lcdcc-dues'
        ));

        add_settings_section(
            'lcd_people_actblue_section',
            __('ActBlue Integration Settings', 'lcd-people'),
            array($this, 'render_actblue_settings_section'),
            'lcd-people-actblue-settings'
        );

        add_settings_field(
            'lcd_people_actblue_username',
            __('Username', 'lcd-people'),
            array($this, 'render_actblue_username_field'),
            'lcd-people-actblue-settings',
            'lcd_people_actblue_section'
        );

        add_settings_field(
            'lcd_people_actblue_password',
            __('Password', 'lcd-people'),
            array($this, 'render_actblue_password_field'),
            'lcd-people-actblue-settings',
            'lcd_people_actblue_section'
        );

        add_settings_field(
            'lcd_people_actblue_dues_form',
            __('Dues Form Name', 'lcd-people'),
            array($this, 'render_actblue_dues_form_field'),
            'lcd-people-actblue-settings',
            'lcd_people_actblue_section'
        );

        // Sender.net settings
        register_setting('lcd_people_sender_settings', 'lcd_people_sender_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        // Group assignments - replaces individual group settings
        register_setting('lcd_people_sender_settings', 'lcd_people_sender_group_assignments', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_group_assignments'),
            'default' => array()
        ));

        // Opt-in form settings
        register_setting('lcd_people_sender_settings', 'lcd_people_optin_groups', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_optin_groups'),
            'default' => array()
        ));



        register_setting('lcd_people_sender_settings', 'lcd_people_optin_main_disclaimer', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_sms_disclaimer', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_email_title', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Join Our Email List'
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_sms_title', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Stay Connected with SMS'
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_email_cta', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Continue'
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_sms_cta', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Join SMS List'
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_skip_sms_cta', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'No Thanks, Email Only'
        ));

        // Conversion tracking settings
        register_setting('lcd_people_sender_settings', 'lcd_people_optin_google_gtag_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_google_conversion_label', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_facebook_pixel_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_optin_conversion_value', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        add_settings_section(
            'lcd_people_sender_section',
            __('Sender.net Integration Settings', 'lcd-people'),
            array($this, 'render_sender_settings_section'),
            'lcd-people-sender-settings'
        );

        add_settings_field(
            'lcd_people_sender_token',
            __('API Token', 'lcd-people'),
            array($this, 'render_sender_token_field'),
            'lcd-people-sender-settings',
            'lcd_people_sender_section'
        );

        add_settings_field(
            'lcd_people_sender_group_assignments',
            __('Group Assignments', 'lcd-people'),
            array($this, 'render_group_assignments_field'),
            'lcd-people-sender-settings',
            'lcd_people_sender_section'
        );

        // Opt-in Form Settings Section
        add_settings_section(
            'lcd_people_optin_section',
            __('Opt-in Form Settings', 'lcd-people'),
            array($this, 'render_optin_settings_section'),
            'lcd-people-sender-settings'
        );

        add_settings_field(
            'lcd_people_optin_groups',
            __('Available Groups for Opt-in', 'lcd-people'),
            array($this, 'render_optin_groups_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );



        add_settings_field(
            'lcd_people_optin_email_title',
            __('Email Step Title', 'lcd-people'),
            array($this, 'render_optin_email_title_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );

        add_settings_field(
            'lcd_people_optin_sms_title',
            __('SMS Step Title', 'lcd-people'),
            array($this, 'render_optin_sms_title_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );

        add_settings_field(
            'lcd_people_optin_email_cta',
            __('Email Step CTA Button', 'lcd-people'),
            array($this, 'render_optin_email_cta_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );

        add_settings_field(
            'lcd_people_optin_sms_cta',
            __('SMS Opt-in CTA Button', 'lcd-people'),
            array($this, 'render_optin_sms_cta_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );

        add_settings_field(
            'lcd_people_optin_skip_sms_cta',
            __('Skip SMS CTA Button', 'lcd-people'),
            array($this, 'render_optin_skip_sms_cta_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );

        add_settings_field(
            'lcd_people_optin_main_disclaimer',
            __('Main Form Disclaimer', 'lcd-people'),
            array($this, 'render_optin_main_disclaimer_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );

        add_settings_field(
            'lcd_people_optin_sms_disclaimer',
            __('SMS Checkbox Disclaimer', 'lcd-people'),
            array($this, 'render_optin_sms_disclaimer_field'),
            'lcd-people-sender-settings',
            'lcd_people_optin_section'
        );

        // Conversion Tracking Section
        add_settings_section(
            'lcd_people_tracking_section',
            __('Conversion Tracking', 'lcd-people'),
            array($this, 'render_tracking_settings_section'),
            'lcd-people-sender-settings'
        );

        add_settings_field(
            'lcd_people_optin_google_gtag_id',
            __('Google Analytics 4 Tag ID', 'lcd-people'),
            array($this, 'render_google_gtag_id_field'),
            'lcd-people-sender-settings',
            'lcd_people_tracking_section'
        );

        add_settings_field(
            'lcd_people_optin_google_conversion_label',
            __('Google Ads Conversion Label', 'lcd-people'),
            array($this, 'render_google_conversion_label_field'),
            'lcd-people-sender-settings',
            'lcd_people_tracking_section'
        );

        add_settings_field(
            'lcd_people_optin_facebook_pixel_id',
            __('Facebook Pixel ID', 'lcd-people'),
            array($this, 'render_facebook_pixel_id_field'),
            'lcd-people-sender-settings',
            'lcd_people_tracking_section'
        );

        add_settings_field(
            'lcd_people_optin_conversion_value',
            __('Conversion Value', 'lcd-people'),
            array($this, 'render_conversion_value_field'),
            'lcd-people-sender-settings',
            'lcd_people_tracking_section'
        );

        // CallHub SMS settings
        register_setting('lcd_people_sender_settings', 'lcd_people_callhub_api_domain', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_callhub_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_callhub_sms_tags', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_callhub_dnc_list_name', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        add_settings_section(
            'lcd_people_callhub_section',
            __('CallHub SMS Integration', 'lcd-people'),
            array($this, 'render_callhub_settings_section'),
            'lcd-people-sender-settings'
        );

        add_settings_field(
            'lcd_people_callhub_api_domain',
            __('API Domain', 'lcd-people'),
            array($this, 'render_callhub_api_domain_field'),
            'lcd-people-sender-settings',
            'lcd_people_callhub_section'
        );

        add_settings_field(
            'lcd_people_callhub_api_key',
            __('API Key', 'lcd-people'),
            array($this, 'render_callhub_api_key_field'),
            'lcd-people-sender-settings',
            'lcd_people_callhub_section'
        );

        add_settings_field(
            'lcd_people_callhub_sms_tags',
            __('SMS Opt-In Tag IDs', 'lcd-people'),
            array($this, 'render_callhub_sms_tags_field'),
            'lcd-people-sender-settings',
            'lcd_people_callhub_section'
        );

        add_settings_field(
            'lcd_people_callhub_dnc_list_name',
            __('DNC List Name', 'lcd-people'),
            array($this, 'render_callhub_dnc_list_name_field'),
            'lcd-people-sender-settings',
            'lcd_people_callhub_section'
        );

        add_settings_field(
            'lcd_people_callhub_webhook_url',
            __('Webhook URL', 'lcd-people'),
            array($this, 'render_callhub_webhook_url_field'),
            'lcd-people-sender-settings',
            'lcd_people_callhub_section'
        );

        // Forminator settings
        register_setting('lcd_people_forminator_settings', 'lcd_people_forminator_volunteer_form', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_forminator_settings', 'lcd_people_forminator_volunteer_mappings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_forminator_mappings'),
            'default' => array()
        ));

        add_settings_section(
            'lcd_people_forminator_volunteer_section',
            __('Volunteer Form Integration', 'lcd-people'),
            array($this, 'render_forminator_volunteer_section'),
            'lcd-people-forminator-settings'
        );

        add_settings_field(
            'lcd_people_forminator_volunteer_form',
            __('Volunteer Sign-up Form', 'lcd-people'),
            array($this, 'render_forminator_volunteer_form_field'),
            'lcd-people-forminator-settings',
            'lcd_people_forminator_volunteer_section'
        );

        add_settings_field(
            'lcd_people_forminator_volunteer_mappings',
            __('Field Mappings', 'lcd-people'),
            array($this, 'render_forminator_volunteer_mappings_field'),
            'lcd-people-forminator-settings',
            'lcd_people_forminator_volunteer_section'
        );
    }

    /**
     * Render the ActBlue settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('lcd_people_actblue_settings');
                do_settings_sections('lcd-people-actblue-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_actblue_settings_section() {
        ?>
        <p><?php _e('Configure your ActBlue webhook integration settings.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_actblue_username_field() {
        $username = get_option('lcd_people_actblue_username');
        ?>
        <input type="text" name="lcd_people_actblue_username" value="<?php echo esc_attr($username); ?>" class="regular-text">
        <p class="description"><?php _e('Your ActBlue webhook authentication username', 'lcd-people'); ?></p>
        <?php
    }

    public function render_actblue_password_field() {
        $password = get_option('lcd_people_actblue_password');
        ?>
        <div class="password-toggle-wrapper">
            <input type="password" name="lcd_people_actblue_password" value="<?php echo esc_attr($password); ?>" class="regular-text">
            <span class="dashicons dashicons-visibility toggle-password"></span>
        </div>
        <p class="description"><?php _e('Your ActBlue webhook authentication password', 'lcd-people'); ?></p>
        <?php
    }

    public function render_actblue_dues_form_field() {
        $form = get_option('lcd_people_actblue_dues_form');
        ?>
        <input type="text" name="lcd_people_actblue_dues_form" value="<?php echo esc_attr($form); ?>" class="regular-text">
        <p class="description"><?php _e('The form name for membership dues payments (e.g., lcdcc-dues)', 'lcd-people'); ?></p>
        <?php
    }

    public function render_sender_settings_section() {
        ?>
        <p><?php _e('Configure your Sender.net API integration settings.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_sender_token_field() {
        $token = get_option('lcd_people_sender_token');
        ?>
        <div class="password-toggle-wrapper">
            <input type="password" name="lcd_people_sender_token" value="<?php echo esc_attr($token); ?>" class="regular-text">
            <span class="dashicons dashicons-visibility toggle-password"></span>
        </div>
        <p class="description"><?php _e('Your Sender.net API Bearer Token', 'lcd-people'); ?></p>
        <?php
    }

    public function render_group_assignments_field() {
        $assignments = get_option('lcd_people_sender_group_assignments', array());
        $available_groups = $this->get_sender_groups();
        
        // Migrate old settings to new format if needed
        $this->maybe_migrate_group_settings($assignments);
        
        // Ensure all assignment types are arrays for the new format
        $new_member_groups = $this->normalize_to_array($assignments['new_member'] ?? array());
        $new_volunteer_groups = $this->normalize_to_array($assignments['new_volunteer'] ?? array());
        $email_optin_groups = $this->normalize_to_array($assignments['email_optin'] ?? array());
        $sms_optin_groups = $this->normalize_to_array($assignments['sms_optin'] ?? array());
        
        ?>
        <div id="group-assignments-manager">
            <?php if (empty($available_groups)): ?>
                <p class="notice notice-error inline"><?php _e('No groups found - check your API token and debug log', 'lcd-people'); ?></p>
            <?php else: ?>
                <p class="description" style="margin-bottom: 15px;">
                    <?php _e('Select which Sender.net groups to assign for each action. You can select multiple groups for each.', 'lcd-people'); ?>
                </p>
                
                <table class="form-table group-assignments-form">
                    <tr>
                        <th scope="row">
                            <label for="new_member_groups"><?php _e('New Member Groups', 'lcd-people'); ?></label>
                        </th>
                        <td>
                            <select name="lcd_people_sender_group_assignments[new_member][]" 
                                    id="new_member_groups" 
                                    multiple="multiple" 
                                    class="lcd-multi-select"
                                    style="width: 100%; max-width: 400px;">
                                <?php foreach ($available_groups as $group_id => $group_name): ?>
                                    <option value="<?php echo esc_attr($group_id); ?>" 
                                            <?php selected(in_array($group_id, $new_member_groups)); ?>>
                                        <?php echo esc_html($group_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Assigned when someone becomes a new member (triggers welcome automation).', 'lcd-people'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="new_volunteer_groups"><?php _e('New Volunteer Groups', 'lcd-people'); ?></label>
                        </th>
                        <td>
                            <select name="lcd_people_sender_group_assignments[new_volunteer][]" 
                                    id="new_volunteer_groups" 
                                    multiple="multiple" 
                                    class="lcd-multi-select"
                                    style="width: 100%; max-width: 400px;">
                                <?php foreach ($available_groups as $group_id => $group_name): ?>
                                    <option value="<?php echo esc_attr($group_id); ?>" 
                                            <?php selected(in_array($group_id, $new_volunteer_groups)); ?>>
                                        <?php echo esc_html($group_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Assigned when someone signs up to volunteer.', 'lcd-people'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="email_optin_groups"><?php _e('Email Opt-In Groups', 'lcd-people'); ?></label>
                        </th>
                        <td>
                            <select name="lcd_people_sender_group_assignments[email_optin][]" 
                                    id="email_optin_groups" 
                                    multiple="multiple" 
                                    class="lcd-multi-select"
                                    style="width: 100%; max-width: 400px;">
                                <?php foreach ($available_groups as $group_id => $group_name): ?>
                                    <option value="<?php echo esc_attr($group_id); ?>" 
                                            <?php selected(in_array($group_id, $email_optin_groups)); ?>>
                                        <?php echo esc_html($group_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Automatically added to all email opt-in submissions (hidden from user).', 'lcd-people'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sms_optin_groups"><?php _e('SMS Opt-In Groups', 'lcd-people'); ?></label>
                        </th>
                        <td>
                            <select name="lcd_people_sender_group_assignments[sms_optin][]" 
                                    id="sms_optin_groups" 
                                    multiple="multiple" 
                                    class="lcd-multi-select"
                                    style="width: 100%; max-width: 400px;">
                                <?php foreach ($available_groups as $group_id => $group_name): ?>
                                    <option value="<?php echo esc_attr($group_id); ?>" 
                                            <?php selected(in_array($group_id, $sms_optin_groups)); ?>>
                                        <?php echo esc_html($group_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('Automatically added when user opts into SMS messages.', 'lcd-people'); ?></p>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
        </div>
        
        <style>
        .group-assignments-form th {
            padding: 15px 10px 15px 0;
            width: 200px;
        }
        .group-assignments-form td {
            padding: 15px 10px;
        }
        .lcd-multi-select {
            min-height: 120px;
        }
        .lcd-multi-select option {
            padding: 5px 8px;
        }
        .lcd-multi-select option:checked {
            background: linear-gradient(0deg, #2271b1 0%, #2271b1 100%);
            color: white;
        }
        </style>
        
        <p class="description" style="margin-top: 10px;">
            <?php _e('Hold Ctrl (Windows) or Cmd (Mac) to select multiple groups.', 'lcd-people'); ?>
        </p>
        <p class="description">
            <a href="<?php echo admin_url('edit.php?post_type=lcd_person&page=lcd-people-sender-settings&clear_cache=1'); ?>" onclick="return confirm('This will clear the groups cache and force a new API call. Continue?');">Clear Cache & Retry</a>
            | Check your WordPress debug log for detailed API information.
        </p>
        <?php
    }
    
    /**
     * Helper function to normalize a value to an array
     * Handles migration from single string to array format
     */
    private function normalize_to_array($value) {
        if (empty($value)) {
            return array();
        }
        if (is_array($value)) {
            return $value;
        }
        // Single string value - convert to array
        return array($value);
    }

    public function render_optin_settings_section() {
        ?>
        <p><?php _e('Configure the settings for your custom opt-in form. This form will collect email addresses and optionally phone numbers, then sync them to your Sender.net groups.', 'lcd-people'); ?></p>
        <p><?php _e('You can control which groups users can select from, the order they appear in, which ones are pre-selected by default, and which groups to automatically add to every submission behind the scenes.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_optin_groups_field() {
        $optin_groups = get_option('lcd_people_optin_groups', array());
        $available_groups = $this->get_sender_groups();
        
        // Convert old format to new format if needed
        if (!empty($optin_groups) && is_array($optin_groups) && isset($optin_groups[0]) && is_string($optin_groups[0])) {
            // Old format - convert to new format
            $new_format = array();
            foreach ($optin_groups as $index => $group_id) {
                $new_format[$group_id] = array(
                    'order' => $index + 1,
                    'default' => $index === 0 // First one is default
                );
            }
            $optin_groups = $new_format;
        }
        
        ?>
        <div id="optin-groups-manager">
            <?php if (empty($available_groups)): ?>
                <p class="notice notice-error inline"><?php _e('No groups found - check your API token and debug log', 'lcd-people'); ?></p>
            <?php else: ?>
                <div class="groups-selector">
                    <h4><?php _e('Add Groups', 'lcd-people'); ?></h4>
                    <select id="add-group-select" style="width: 300px;">
                        <option value=""><?php _e('Select a group to add...', 'lcd-people'); ?></option>
                        <?php foreach ($available_groups as $group_id => $group_name): ?>
                            <?php if (!isset($optin_groups[$group_id])): ?>
                                <option value="<?php echo esc_attr($group_id); ?>"><?php echo esc_html($group_name); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="add-group-btn" class="button"><?php _e('Add Group', 'lcd-people'); ?></button>
                </div>
                
                <div class="selected-groups" style="margin-top: 20px;">
                    <h4><?php _e('Selected Groups (drag to reorder)', 'lcd-people'); ?></h4>
                    <div id="sortable-groups" class="sortable-groups">
                        <?php
                        // Sort groups by order
                        uasort($optin_groups, function($a, $b) {
                            return ($a['order'] ?? 999) - ($b['order'] ?? 999);
                        });
                        
                        foreach ($optin_groups as $group_id => $group_data):
                            if (isset($available_groups[$group_id])):
                        ?>
                            <div class="group-item" data-group-id="<?php echo esc_attr($group_id); ?>">
                                <span class="dashicons dashicons-menu drag-handle"></span>
                                <span class="group-name"><?php echo esc_html($available_groups[$group_id]); ?></span>
                                <label class="default-checkbox">
                                    <input type="checkbox" name="lcd_people_optin_groups[<?php echo esc_attr($group_id); ?>][default]" 
                                           value="1" <?php checked(!empty($group_data['default'])); ?>>
                                    <?php _e('Default', 'lcd-people'); ?>
                                </label>
                                <button type="button" class="remove-group button-link-delete"><?php _e('Remove', 'lcd-people'); ?></button>
                                <input type="hidden" name="lcd_people_optin_groups[<?php echo esc_attr($group_id); ?>][order]" 
                                       value="<?php echo esc_attr($group_data['order'] ?? 1); ?>" class="group-order">
                            </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                    <?php if (empty($optin_groups)): ?>
                        <p class="no-groups-message"><?php _e('No groups selected. Add groups above.', 'lcd-people'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <p class="description"><?php _e('Select and configure which groups users can opt-in to. Groups are displayed as checkboxes in the order shown above. Check "Default" to pre-select a group.', 'lcd-people'); ?></p>
        <p class="description">
            <a href="<?php echo admin_url('edit.php?post_type=lcd_person&page=lcd-people-sender-settings&clear_cache=1'); ?>" onclick="return confirm('This will clear the groups cache and force a new API call. Continue?');">Clear Cache & Retry</a>
            | Check your WordPress debug log for detailed API information.
        </p>
        
        <style>
        .sortable-groups {
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
            max-width: 600px;
        }
        .group-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
            background: #fff;
        }
        .group-item:last-child {
            border-bottom: none;
        }
        .group-item:hover {
            background: #f9f9f9;
        }
        .drag-handle {
            cursor: move;
            margin-right: 10px;
            color: #ccc;
        }
        .group-name {
            flex: 1;
            font-weight: 500;
        }
        .default-checkbox {
            margin: 0 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .remove-group {
            color: #a00;
            text-decoration: none;
        }
        .remove-group:hover {
            color: #dc3232;
        }
        .no-groups-message {
            padding: 20px;
            text-align: center;
            color: #666;
            font-style: italic;
        }
        .ui-sortable-helper {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Make groups sortable
            $('#sortable-groups').sortable({
                handle: '.drag-handle',
                update: function(event, ui) {
                    // Update order values
                    $('#sortable-groups .group-item').each(function(index) {
                        $(this).find('.group-order').val(index + 1);
                    });
                }
            });
            
            // Add group functionality
            $('#add-group-btn').click(function() {
                var groupId = $('#add-group-select').val();
                var groupName = $('#add-group-select option:selected').text();
                
                if (!groupId) return;
                
                var nextOrder = $('#sortable-groups .group-item').length + 1;
                var groupHtml = '<div class="group-item" data-group-id="' + groupId + '">' +
                    '<span class="dashicons dashicons-menu drag-handle"></span>' +
                    '<span class="group-name">' + groupName + '</span>' +
                    '<label class="default-checkbox">' +
                        '<input type="checkbox" name="lcd_people_optin_groups[' + groupId + '][default]" value="1">' +
                        '<?php _e('Default', 'lcd-people'); ?>' +
                    '</label>' +
                    '<button type="button" class="remove-group button-link-delete"><?php _e('Remove', 'lcd-people'); ?></button>' +
                    '<input type="hidden" name="lcd_people_optin_groups[' + groupId + '][order]" value="' + nextOrder + '" class="group-order">' +
                '</div>';
                
                $('#sortable-groups').append(groupHtml);
                $('.no-groups-message').hide();
                
                // Remove from dropdown
                $('#add-group-select option[value="' + groupId + '"]').remove();
                $('#add-group-select').val('');
                
                // Refresh sortable
                $('#sortable-groups').sortable('refresh');
            });
            
            // Remove group functionality
            $(document).on('click', '.remove-group', function() {
                var groupItem = $(this).closest('.group-item');
                var groupId = groupItem.data('group-id');
                var groupName = groupItem.find('.group-name').text();
                
                // Add back to dropdown
                $('#add-group-select').append('<option value="' + groupId + '">' + groupName + '</option>');
                
                // Remove from list
                groupItem.remove();
                
                // Show no groups message if empty
                if ($('#sortable-groups .group-item').length === 0) {
                    $('.no-groups-message').show();
                }
                
                // Update order values
                $('#sortable-groups .group-item').each(function(index) {
                    $(this).find('.group-order').val(index + 1);
                });
            });
        });
        </script>
        <?php
    }

    public function render_optin_email_title_field() {
        $value = get_option('lcd_people_optin_email_title', 'Join Our Email List');
        ?>
        <input type="text" name="lcd_people_optin_email_title" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="description"><?php _e('Title shown on the first step of the opt-in form', 'lcd-people'); ?></p>
        <?php
    }

    public function render_optin_sms_title_field() {
        $value = get_option('lcd_people_optin_sms_title', 'Stay Connected with SMS');
        ?>
        <input type="text" name="lcd_people_optin_sms_title" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="description"><?php _e('Title shown on the SMS opt-in step', 'lcd-people'); ?></p>
        <?php
    }

    public function render_optin_email_cta_field() {
        $value = get_option('lcd_people_optin_email_cta', 'Continue');
        ?>
        <input type="text" name="lcd_people_optin_email_cta" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="description"><?php _e('Button text for the email step', 'lcd-people'); ?></p>
        <?php
    }

    public function render_optin_sms_cta_field() {
        $value = get_option('lcd_people_optin_sms_cta', 'Join SMS List');
        ?>
        <input type="text" name="lcd_people_optin_sms_cta" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="description"><?php _e('Button text for opting into SMS', 'lcd-people'); ?></p>
        <?php
    }

    public function render_optin_skip_sms_cta_field() {
        $value = get_option('lcd_people_optin_skip_sms_cta', 'No Thanks, Email Only');
        ?>
        <input type="text" name="lcd_people_optin_skip_sms_cta" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <p class="description"><?php _e('Button text for skipping SMS opt-in', 'lcd-people'); ?></p>
        <?php
    }

    public function render_optin_main_disclaimer_field() {
        $value = get_option('lcd_people_optin_main_disclaimer', '');
        if (empty($value)) {
            $value = 'By signing up, you agree to receive emails from us. You can unsubscribe at any time.';
        }
        ?>
        <textarea name="lcd_people_optin_main_disclaimer" rows="4" class="large-text"><?php echo esc_textarea($value); ?></textarea>
        <p class="description"><?php _e('Disclaimer text shown at the bottom of the email opt-in form. HTML allowed.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_optin_sms_disclaimer_field() {
        $value = get_option('lcd_people_optin_sms_disclaimer', '');
        if (empty($value)) {
            $value = 'By checking this box, you consent to receive text messages from us. Message and data rates may apply. Reply STOP to opt out at any time.';
        }
        ?>
        <textarea name="lcd_people_optin_sms_disclaimer" rows="4" class="large-text"><?php echo esc_textarea($value); ?></textarea>
        <p class="description"><?php _e('Required disclaimer text for SMS opt-in checkbox. This should include information about message rates and opt-out instructions. HTML allowed.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_tracking_settings_section() {
        ?>
        <p><?php _e('Configure conversion tracking for Google Analytics, Google Ads, and Facebook Pixel. Events will be fired when users complete the opt-in process.', 'lcd-people'); ?></p>
        <p><?php _e('Make sure your tracking codes are already installed on your site for these events to be recorded properly.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_google_gtag_id_field() {
        $value = get_option('lcd_people_optin_google_gtag_id', '');
        ?>
        <input type="text" name="lcd_people_optin_google_gtag_id" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="G-XXXXXXXXXX">
        <p class="description"><?php _e('Your Google Analytics 4 Measurement ID (e.g., G-XXXXXXXXXX). Used for Google Analytics events and Google Ads conversions.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_google_conversion_label_field() {
        $value = get_option('lcd_people_optin_google_conversion_label', '');
        ?>
        <input type="text" name="lcd_people_optin_google_conversion_label" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="AbC123dEfG">
        <p class="description"><?php _e('Optional: Google Ads conversion label for tracking conversions (e.g., AbC123dEfG). Leave empty to only track Google Analytics events.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_facebook_pixel_id_field() {
        $value = get_option('lcd_people_optin_facebook_pixel_id', '');
        ?>
        <input type="text" name="lcd_people_optin_facebook_pixel_id" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="1234567890123456">
        <p class="description"><?php _e('Your Facebook Pixel ID (numeric, e.g., 1234567890123456). Used to track Facebook conversion events.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_conversion_value_field() {
        $value = get_option('lcd_people_optin_conversion_value', '');
        ?>
        <input type="text" name="lcd_people_optin_conversion_value" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="0">
        <p class="description"><?php _e('Optional: Monetary value to assign to conversions (e.g., 5.00). Leave empty for no value. Used by both Google Ads and Facebook.', 'lcd-people'); ?></p>
        <?php
    }

    /**
     * CallHub Settings Section and Fields
     */
    public function render_callhub_settings_section() {
        ?>
        <p><?php _e('Configure CallHub for SMS messaging. When users opt-in to SMS, they will be synced to CallHub as contacts. When they opt-out, they will be added to the Do Not Call (DNC) list.', 'lcd-people'); ?></p>
        <p><?php _e('This integration ensures compliance by maintaining a single source of truth for SMS preferences across your preference center, Sender.net, and CallHub.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_callhub_api_domain_field() {
        $value = get_option('lcd_people_callhub_api_domain', '');
        ?>
        <input type="text" name="lcd_people_callhub_api_domain" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="api.callhub.io">
        <p class="description">
            <?php _e('Your CallHub API domain. Find it in CallHub under Settings > API Key (labeled "API Domain").', 'lcd-people'); ?>
            <?php _e('Leave empty to use the default: api.callhub.io', 'lcd-people'); ?>
        </p>
        <?php
    }

    public function render_callhub_api_key_field() {
        $value = get_option('lcd_people_callhub_api_key', '');
        ?>
        <div class="password-toggle-wrapper">
            <input type="password" name="lcd_people_callhub_api_key" value="<?php echo esc_attr($value); ?>" class="regular-text">
            <span class="dashicons dashicons-visibility toggle-password"></span>
        </div>
        <p class="description">
            <?php _e('Your CallHub API key. Find it in CallHub under Settings > API Key.', 'lcd-people'); ?>
            <a href="https://developer.callhub.io/" target="_blank"><?php _e('API Documentation', 'lcd-people'); ?></a>
        </p>
        <?php
        // Test connection if API key is set
        if (!empty($value)) {
            $this->render_callhub_connection_test();
        }
    }

    public function render_callhub_sms_tags_field() {
        $value = get_option('lcd_people_callhub_sms_tags', '');
        ?>
        <input type="text" name="lcd_people_callhub_sms_tags" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="e.g., 12345, 67890">
        <p class="description">
            <?php _e('Enter tag IDs to assign to contacts when they opt-in to SMS. Separate multiple IDs with commas.', 'lcd-people'); ?>
            <br><?php _e('Find tag IDs in CallHub under Settings > Tags - the ID is shown in the tag details or URL.', 'lcd-people'); ?>
        </p>
        <?php
    }

    public function render_callhub_dnc_list_name_field() {
        $value = get_option('lcd_people_callhub_dnc_list_name', '');
        ?>
        <input type="text" name="lcd_people_callhub_dnc_list_name" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="e.g., SMS Opt-Out List">
        <p class="description">
            <?php _e('The exact name of your CallHub DNC (Do Not Call) list. This must match the list name in CallHub exactly.', 'lcd-people'); ?>
            <br><?php _e('When users opt out of SMS, their phone numbers will be added to this list.', 'lcd-people'); ?>
        </p>
        <?php
    }

    public function render_callhub_webhook_url_field() {
        $webhook_url = rest_url('lcd-people/v1/callhub-webhook');
        $api_key = get_option('lcd_people_callhub_api_key', '');
        ?>
        <div style="background: #f0f0f1; padding: 10px; border-left: 4px solid #72aee6; margin-bottom: 10px;">
            <code style="background: white; padding: 5px; display: block; user-select: all;"><?php echo esc_url($webhook_url); ?></code>
        </div>
        
        <?php if (!empty($api_key)): ?>
            <?php $this->render_callhub_webhook_status(); ?>
        <?php else: ?>
            <p class="description" style="color: #d63638;">
                <?php _e('Enter your API key above and save settings to enable webhook registration.', 'lcd-people'); ?>
            </p>
        <?php endif; ?>
        
        <p class="description" style="margin-top: 15px;">
            <strong><?php _e('How it works:', 'lcd-people'); ?></strong> <?php _e('When a user texts STOP to your CallHub number, CallHub will notify this webhook, which automatically:', 'lcd-people'); ?>
        </p>
        <ul style="margin-left: 20px; list-style-type: disc;">
            <li><?php _e('Updates the user\'s SMS preferences in your database', 'lcd-people'); ?></li>
            <li><?php _e('Removes their phone number from Sender.net', 'lcd-people'); ?></li>
            <li><?php _e('Ensures they\'re on the CallHub DNC list', 'lcd-people'); ?></li>
        </ul>
        <?php
    }
    
    /**
     * Render webhook status and registration button
     */
    private function render_callhub_webhook_status() {
        if (!class_exists('LCD_People_CallHub_Handler')) {
            return;
        }
        
        $handler = new LCD_People_CallHub_Handler($this->main_plugin);
        $status = $handler->get_webhook_status();
        
        ?>
        <div id="callhub-webhook-status" style="margin: 15px 0; padding: 10px; background: <?php echo $status['registered'] ? '#d1e7dd' : '#fff3cd'; ?>; border-left: 4px solid <?php echo $status['registered'] ? '#198754' : '#ffc107'; ?>;">
            <p style="margin: 0 0 10px 0;">
                <strong><?php _e('Webhook Status:', 'lcd-people'); ?></strong>
                <?php if ($status['registered']): ?>
                    <span style="color: #198754;">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php echo esc_html($status['message']); ?>
                    </span>
                <?php else: ?>
                    <span style="color: #856404;">
                        <span class="dashicons dashicons-warning"></span>
                        <?php echo esc_html($status['message']); ?>
                    </span>
                <?php endif; ?>
            </p>
            
            <?php if ($status['registered'] && !empty($status['webhooks'])): ?>
                <details style="margin-bottom: 10px;">
                    <summary style="cursor: pointer; color: #666;"><?php _e('Show registered webhooks', 'lcd-people'); ?></summary>
                    <ul style="margin: 10px 0 0 20px; font-size: 12px;">
                        <?php foreach ($status['webhooks'] as $webhook): ?>
                            <li>
                                <strong><?php echo esc_html($webhook['event'] ?? 'unknown'); ?></strong>
                                <?php if (isset($webhook['id'])): ?>
                                    <span style="color: #999;">(ID: <?php echo esc_html($webhook['id']); ?>)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
            
            <button type="button" id="register-callhub-webhook" class="button <?php echo $status['registered'] ? 'button-secondary' : 'button-primary'; ?>">
                <?php echo $status['registered'] ? __('Re-register Webhooks', 'lcd-people') : __('Register Webhooks Now', 'lcd-people'); ?>
            </button>
            <span id="webhook-register-spinner" class="spinner" style="float: none; margin-left: 5px;"></span>
            <span id="webhook-register-result" style="margin-left: 10px;"></span>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#register-callhub-webhook').on('click', function() {
                var $button = $(this);
                var $spinner = $('#webhook-register-spinner');
                var $result = $('#webhook-register-result');
                
                $button.prop('disabled', true);
                $spinner.addClass('is-active');
                $result.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'lcd_register_callhub_webhook',
                        nonce: '<?php echo wp_create_nonce('lcd_callhub_webhook'); ?>'
                    },
                    success: function(response) {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        
                        if (response.success) {
                            $result.html('<span style="color: #198754;">' + response.data.message + '</span>');
                            // Reload after 2 seconds to show updated status
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $result.html('<span style="color: #dc3545;">' + (response.data.message || 'Error registering webhook') + '</span>');
                        }
                    },
                    error: function() {
                        $spinner.removeClass('is-active');
                        $button.prop('disabled', false);
                        $result.html('<span style="color: #dc3545;"><?php _e('Network error. Please try again.', 'lcd-people'); ?></span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render CallHub connection test result
     */
    private function render_callhub_connection_test() {
        // Only test if we have the handler available
        if (!class_exists('LCD_People_CallHub_Handler')) {
            return;
        }
        
        $handler = new LCD_People_CallHub_Handler($this->main_plugin);
        $result = $handler->test_connection();
        
        if ($result['success']) {
            ?>
            <p style="color: #00a32a; margin-top: 5px;">
                <span class="dashicons dashicons-yes-alt"></span>
                <?php echo esc_html($result['message']); ?>
            </p>
            <?php
        } else {
            ?>
            <p style="color: #d63638; margin-top: 5px;">
                <span class="dashicons dashicons-warning"></span>
                <?php echo esc_html($result['message']); ?>
            </p>
            <?php
        }
    }

    /**
     * Maybe migrate old group settings to new format
     */
    private function maybe_migrate_group_settings(&$assignments) {
        // Only migrate if the new format is empty
        if (!empty($assignments)) {
            return;
        }
        
        $migrated = false;
        
        // Migrate old new member group
        $old_member_group = get_option('lcd_people_sender_new_member_group');
        if (!empty($old_member_group)) {
            $assignments['new_member'] = $old_member_group;
            $migrated = true;
        }
        
        // Migrate old new volunteer group
        $old_volunteer_group = get_option('lcd_people_sender_new_volunteer_group');
        if (!empty($old_volunteer_group)) {
            $assignments['new_volunteer'] = $old_volunteer_group;
            $migrated = true;
        }
        
        // Migrate old auto-add groups
        $old_auto_groups = get_option('lcd_people_optin_auto_groups', array());
        if (!empty($old_auto_groups)) {
            $assignments['email_optin'] = $old_auto_groups;
            $migrated = true;
        }
        
        // Save the migrated settings
        if ($migrated) {
            update_option('lcd_people_sender_group_assignments', $assignments);
        }
    }

    public function sanitize_group_assignments($assignments) {
        if (!is_array($assignments)) {
            return array();
        }
        
        $sanitized = array();
        
        // All fields are now multi-select arrays
        if (isset($assignments['new_member']) && is_array($assignments['new_member'])) {
            $sanitized['new_member'] = array_map('sanitize_text_field', $assignments['new_member']);
        }
        
        if (isset($assignments['new_volunteer']) && is_array($assignments['new_volunteer'])) {
            $sanitized['new_volunteer'] = array_map('sanitize_text_field', $assignments['new_volunteer']);
        }
        
        if (isset($assignments['email_optin']) && is_array($assignments['email_optin'])) {
            $sanitized['email_optin'] = array_map('sanitize_text_field', $assignments['email_optin']);
        }
        
        if (isset($assignments['sms_optin']) && is_array($assignments['sms_optin'])) {
            $sanitized['sms_optin'] = array_map('sanitize_text_field', $assignments['sms_optin']);
        }
        
        return $sanitized;
    }

    public function sanitize_optin_groups($groups) {
        if (!is_array($groups)) {
            return array();
        }
        
        $sanitized = array();
        foreach ($groups as $group_id => $group_data) {
            if (is_array($group_data)) {
                $sanitized[sanitize_text_field($group_id)] = array(
                    'order' => intval($group_data['order'] ?? 1),
                    'default' => !empty($group_data['default'])
                );
            }
        }
        
        return $sanitized;
    }



    private function get_sender_groups() {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            return array();
        }

        // Check for cached groups
        $cached_groups = get_transient('lcd_people_sender_groups');
        if ($cached_groups !== false) {
            return $cached_groups;
        }

        $groups = array();
        $response = wp_remote_get('https://api.sender.net/v2/groups', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 15
        ));

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

        // Cache for 1 hour
        set_transient('lcd_people_sender_groups', $groups, HOUR_IN_SECONDS);

        return $groups;
    }

    public function sanitize_forminator_mappings($mappings) {
        if (!is_array($mappings)) {
            return array();
        }
        
        $sanitized = array();
        foreach ($mappings as $form_field => $person_field) {
            $sanitized[sanitize_text_field($form_field)] = sanitize_text_field($person_field);
        }
        
        return $sanitized;
    }

    public function render_forminator_volunteer_section() {
        ?>
        <p><?php _e('Configure integration with Forminator forms for volunteer sign-ups. Select a form and map its fields to person record fields.', 'lcd-people'); ?></p>
        <?php
    }

    public function render_forminator_volunteer_form_field() {
        $selected_form = get_option('lcd_people_forminator_volunteer_form');
        $forms = $this->get_forminator_forms();
        ?>
        <select name="lcd_people_forminator_volunteer_form" id="forminator_volunteer_form">
            <option value=""><?php _e('Select a form...', 'lcd-people'); ?></option>
            <?php foreach ($forms as $form_id => $form_title): ?>
                <option value="<?php echo esc_attr($form_id); ?>" <?php selected($selected_form, $form_id); ?>>
                    <?php echo esc_html($form_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php _e('Choose the Forminator form that will be used for volunteer sign-ups.', 'lcd-people'); ?></p>
        
        <?php if ($selected_form): ?>
            <div id="webhook-info" style="margin-top: 15px; padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6;">
                <h4><?php _e('Webhook URL', 'lcd-people'); ?></h4>
                <p><?php _e('Configure your Forminator form to send webhooks to this form-specific URL:', 'lcd-people'); ?></p>
                <code style="background: white; padding: 5px; display: block; margin: 5px 0;">
                    <?php echo esc_url(rest_url('volunteer-form-' . $selected_form . '/v1/submit')); ?>
                </code>
                <p class="description">
                    <?php _e('In your Forminator form settings, go to Integrations > Webhooks and add this URL. This URL is specific to the selected form for security.', 'lcd-people'); ?>
                </p>
                <p class="description">
                    <strong><?php _e('Form ID:', 'lcd-people'); ?></strong> <code><?php echo esc_html($selected_form); ?></code>
                </p>
            </div>
        <?php endif; ?>
        <?php
    }

    public function render_forminator_volunteer_mappings_field() {
        $selected_form = get_option('lcd_people_forminator_volunteer_form');
        $mappings = get_option('lcd_people_forminator_volunteer_mappings', array());
        
        if (empty($selected_form)) {
            echo '<p class="description">' . __('Please select a form first to configure field mappings.', 'lcd-people') . '</p>';
            return;
        }

        $form_fields = $this->get_forminator_form_fields($selected_form);
        $person_fields = $this->get_person_field_options();

        if (empty($form_fields)) {
            echo '<p class="description">' . __('No fields found for the selected form.', 'lcd-people') . '</p>';
            return;
        }
        ?>
        <div id="field-mappings">
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php _e('Form Field', 'lcd-people'); ?></th>
                        <th><?php _e('Maps to Person Field', 'lcd-people'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($form_fields as $field_id => $field_label): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($field_label); ?></strong><br>
                                <code><?php echo esc_html($field_id); ?></code>
                            </td>
                            <td>
                                <select name="lcd_people_forminator_volunteer_mappings[<?php echo esc_attr($field_id); ?>]">
                                    <option value=""><?php _e('-- Do not map --', 'lcd-people'); ?></option>
                                    <?php foreach ($person_fields as $person_field => $person_label): ?>
                                        <option value="<?php echo esc_attr($person_field); ?>" 
                                                <?php selected(isset($mappings[$field_id]) ? $mappings[$field_id] : '', $person_field); ?>>
                                            <?php echo esc_html($person_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#forminator_volunteer_form').change(function() {
                    if ($(this).val()) {
                        // Reload page to show mappings for selected form
                        window.location.href = window.location.pathname + '?page=lcd-people-forminator-settings&form_selected=' + $(this).val();
                    }
                });
            });
        </script>
        <?php
    }

    private function get_forminator_forms() {
        $forms = array();
        
        // Check if Forminator is active
        if (!class_exists('Forminator_API')) {
            return $forms;
        }

        try {
            $forminator_forms = Forminator_API::get_forms(null, 1, 999); // Get up to 999 forms
            
            if (is_array($forminator_forms)) {
                foreach ($forminator_forms as $form) {
                    if (isset($form->id) && isset($form->settings['formName'])) {
                        $forms[$form->id] = $form->settings['formName'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('LCD People: Error getting Forminator forms: ' . $e->getMessage());
        }

        return $forms;
    }

    private function get_forminator_form_fields($form_id) {
        $fields = array();
        
        if (!class_exists('Forminator_API')) {
            return $fields;
        }

        try {
            $form = Forminator_API::get_form($form_id);
            
            if ($form && isset($form->fields)) {
                foreach ($form->fields as $field) {
                    if (is_object($field) && $field instanceof Forminator_Form_Field_Model) {
                        // Get the field data from the Forminator field model
                        $element_id = null;
                        $field_label = null;
                        
                        // Try to get element_id from slug first, then from raw data
                        if (isset($field->slug)) {
                            $element_id = $field->slug;
                        }
                        
                        // Try to get field label from raw data
                        if (method_exists($field, 'get_field_label')) {
                            $field_label = $field->get_field_label();
                        } elseif (method_exists($field, 'get_raw')) {
                            $raw_data = $field->get_raw();
                            if (isset($raw_data['field_label'])) {
                                $field_label = $raw_data['field_label'];
                            }
                        }
                        
                        // Fallback: try to access raw property directly (though it's protected)
                        if (!$field_label) {
                            try {
                                $reflection = new ReflectionClass($field);
                                $raw_property = $reflection->getProperty('raw');
                                $raw_property->setAccessible(true);
                                $raw_data = $raw_property->getValue($field);
                                
                                if (isset($raw_data['field_label'])) {
                                    $field_label = $raw_data['field_label'];
                                }
                                if (!$element_id && isset($raw_data['element_id'])) {
                                    $element_id = $raw_data['element_id'];
                                }
                            } catch (Exception $e) {
                                // Reflection failed, continue with other methods
                            }
                        }
                        
                        // For name fields, handle sub-fields
                        if ($element_id && $field_label) {
                            $fields[$element_id] = $field_label;
                            
                            // For name fields, also add individual name components
                            $raw_data = null;
                            if (method_exists($field, 'get_raw')) {
                                $raw_data = $field->get_raw();
                            } else {
                                // Fallback: try to access raw property directly using reflection
                                try {
                                    $reflection = new ReflectionClass($field);
                                    $raw_property = $reflection->getProperty('raw');
                                    $raw_property->setAccessible(true);
                                    $raw_data = $raw_property->getValue($field);
                                } catch (Exception $e) {
                                    // Reflection failed
                                }
                            }
                            
                            if ($raw_data && isset($raw_data['type']) && $raw_data['type'] === 'name') {
                                // Add first name and last name as separate options
                                if (isset($raw_data['fname']) && $raw_data['fname']) {
                                    $fields[$element_id . '_first_name'] = $field_label . ' (First Name)';
                                }
                                if (isset($raw_data['lname']) && $raw_data['lname']) {
                                    $fields[$element_id . '_last_name'] = $field_label . ' (Last Name)';
                                }
                                if (isset($raw_data['mname']) && $raw_data['mname']) {
                                    $fields[$element_id . '_middle_name'] = $field_label . ' (Middle Name)';
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('LCD People: Error getting Forminator form fields: ' . $e->getMessage());
        }

        return $fields;
    }

    private function get_person_field_options() {
        return array(
            'first_name' => __('First Name', 'lcd-people'),
            'last_name' => __('Last Name', 'lcd-people'),
            'email' => __('Email', 'lcd-people'),
            'phone' => __('Phone', 'lcd-people'),
            'address' => __('Address', 'lcd-people'),
            'role' => __('Role (will be added as taxonomy)', 'lcd-people'),
            'precinct' => __('Precinct (will be added as taxonomy)', 'lcd-people'),
            'latest_volunteer_submission_id' => __('Latest Volunteer Submission ID', 'lcd-people'),
        );
    }

    public function render_sender_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle cache clearing for Sender.net
        if (isset($_GET['clear_cache']) && $_GET['clear_cache'] == '1') {
            delete_transient('lcd_people_sender_groups');
            add_settings_error('lcd_people_sender_settings', 'cache_cleared', __('Sender.net groups cache cleared. The API will be called again.', 'lcd-people'), 'updated');
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('lcd_people_sender_settings'); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('lcd_people_sender_settings');
                do_settings_sections('lcd-people-sender-settings');
                submit_button();
                ?>
            </form>

            <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #646970; margin-top: 20px;">
                <h3><?php _e('Using the Opt-in Form', 'lcd-people'); ?></h3>
                <p><?php _e('Once configured above, you can use the opt-in form in the following ways:', 'lcd-people'); ?></p>
                <ul>
                    <li><strong><?php _e('Shortcode:', 'lcd-people'); ?></strong> <code>[lcd_optin_form]</code> - <?php _e('Embed the form directly on any page or post', 'lcd-people'); ?></li>
                    <li><strong><?php _e('Modal Trigger:', 'lcd-people'); ?></strong> <code>&lt;button data-modal="optin-form"&gt;Join Our List&lt;/button&gt;</code> - <?php _e('Open the form in a modal', 'lcd-people'); ?></li>
                    <li><strong><?php _e('JavaScript:', 'lcd-people'); ?></strong> <code>LCDModal.open({type: 'optin-form'})</code> - <?php _e('Trigger programmatically', 'lcd-people'); ?></li>
                </ul>
                
                <h4><?php _e('Shortcode Parameters', 'lcd-people'); ?></h4>
                <p><?php _e('You can add extra groups/tags on a per-shortcode basis using these optional parameters:', 'lcd-people'); ?></p>
                <ul>
                    <li><code>sender_groups</code> - <?php _e('Comma-separated Sender.net group IDs to add', 'lcd-people'); ?></li>
                    <li><code>callhub_tags</code> - <?php _e('Comma-separated CallHub tag IDs to add', 'lcd-people'); ?></li>
                </ul>
                <p><strong><?php _e('Example:', 'lcd-people'); ?></strong> <code>[lcd_optin_form sender_groups="abc123,def456" callhub_tags="tag1,tag2"]</code></p>
                
                <p class="description"><?php _e('The form will display groups in the order configured above, with default selections pre-checked. Email and SMS auto-add groups will be automatically assigned based on user selections. All opt-ins will trigger welcome automations.', 'lcd-people'); ?></p>
                
                <?php if (get_option('lcd_people_optin_google_gtag_id') || get_option('lcd_people_optin_facebook_pixel_id')): ?>
                <div style="margin-top: 15px; padding: 10px; background: #d1ecf1; border-left: 4px solid #bee5eb;">
                    <h4 style="margin-top: 0;"><?php _e('Conversion Tracking Active', 'lcd-people'); ?></h4>
                    <p style="margin-bottom: 0;"><?php _e('Conversion events will be automatically fired when users complete the opt-in process:', 'lcd-people'); ?></p>
                    <ul style="margin: 10px 0 0 20px;">
                        <li><strong><?php _e('Email Signup:', 'lcd-people'); ?></strong> <?php _e('Conversion events (Google: conversion, Facebook: CompleteRegistration)', 'lcd-people'); ?></li>
                        <li><strong><?php _e('SMS Opt-in:', 'lcd-people'); ?></strong> <?php _e('Subscribe events (Google: conversion, Facebook: Subscribe)', 'lcd-people'); ?></li>
                        <?php if (get_option('lcd_people_optin_google_conversion_label')): ?>
                        <li><strong><?php _e('Google Ads:', 'lcd-people'); ?></strong> <?php _e('Conversion action will be fired with configured label', 'lcd-people'); ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }



    public function render_forminator_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if Forminator is active
        if (!class_exists('Forminator_API')) {
            ?>
            <div class="wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('Forminator plugin is required for this integration. Please install and activate Forminator.', 'lcd-people'); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('lcd_people_forminator_settings'); ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('lcd_people_forminator_settings');
                do_settings_sections('lcd-people-forminator-settings');
                submit_button();
                ?>
            </form>

            <hr>

            <?php
            // Display last webhook debug info if available
            $debug_info = get_transient('lcd_people_last_webhook_debug');
            if ($debug_info) {
                ?>
                <h2><?php _e('Last Webhook Activity', 'lcd-people'); ?></h2>
                <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #646970;">
                    <p><strong><?php _e('Timestamp:', 'lcd-people'); ?></strong> <?php echo esc_html($debug_info['timestamp']); ?></p>
                    
                    <h3><?php _e('Processing Steps:', 'lcd-people'); ?></h3>
                    <ol>
                        <?php foreach ($debug_info['steps'] as $step): ?>
                            <li style="margin-bottom: 10px;">
                                <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $step['step']))); ?>:</strong>
                                <span style="color: <?php echo $step['status'] === 'success' ? '#00a32a' : '#d63638'; ?>">
                                    <?php echo esc_html($step['message']); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <details>
                        <summary><?php _e('Raw Data Received', 'lcd-people'); ?></summary>
                        <pre style="background: #fff; padding: 10px; overflow: auto;"><?php echo esc_html(print_r($debug_info['data_received'], true)); ?></pre>
                    </details>
                </div>
                <?php
            }
            ?>

            <h2><?php _e('Integration Instructions', 'lcd-people'); ?></h2>
            <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #72aee6;">
                <h3><?php _e('How to set up the integration:', 'lcd-people'); ?></h3>
                <ol>
                    <li><?php _e('Select your volunteer sign-up form from the dropdown above', 'lcd-people'); ?></li>
                    <li><?php _e('Map the form fields to the appropriate person record fields', 'lcd-people'); ?></li>
                    <li><?php _e('Save the settings', 'lcd-people'); ?></li>
                    <li><?php _e('Copy the webhook URL shown above and add it to your Forminator form:', 'lcd-people'); ?>
                        <ul style="margin-top: 10px;">
                            <li><?php _e('Go to your Forminator form editor', 'lcd-people'); ?></li>
                            <li><?php _e('Navigate to Integrations > Webhooks', 'lcd-people'); ?></li>
                            <li><?php _e('Add a new webhook with the URL provided above', 'lcd-people'); ?></li>
                            <li><?php _e('Set the method to POST', 'lcd-people'); ?></li>
                        </ul>
                    </li>
                </ol>
                <p><strong><?php _e('Note:', 'lcd-people'); ?></strong> <?php _e('When someone submits the form, a new person record will be created with the "volunteer" role automatically assigned.', 'lcd-people'); ?></p>
            </div>
        </div>
        <?php
    }

    public function render_user_connections_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle bulk operations
        if (isset($_POST['action']) && $_POST['action'] === 'connect_unconnected_users') {
            check_admin_referer('lcd_people_user_connections');
            $this->connect_unconnected_users();
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('This page helps you manage connections between WordPress users and People records. The plugin automatically connects users during registration, but you can use these tools for existing users.', 'lcd-people'); ?></p>
            </div>

            <h2><?php _e('Statistics', 'lcd-people'); ?></h2>
            <?php $this->render_connection_stats(); ?>

            <hr>

            <h2><?php _e('Bulk Actions', 'lcd-people'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('lcd_people_user_connections'); ?>
                <input type="hidden" name="action" value="connect_unconnected_users">
                <p><?php _e('Connect all unconnected users to their corresponding People records (matching by email).', 'lcd-people'); ?></p>
                <?php submit_button(__('Connect Unconnected Users', 'lcd-people'), 'primary', 'submit', false); ?>
            </form>

            <hr>

            <h2><?php _e('Unconnected Users', 'lcd-people'); ?></h2>
            <?php $this->render_unconnected_users(); ?>
        </div>
        <?php
    }

    /**
     * Render connection statistics
     */
    private function render_connection_stats() {
        global $wpdb;
        
        // Get total users
        $total_users = count_users();
        $user_count = $total_users['total_users'];
        
        // Get connected users
        $connected_users = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '_lcd_person_id'"
        );
        
        // Get total people
        $people_count = wp_count_posts('lcd_person')->publish;
        
        // Get connected people
        $connected_people = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_lcd_person_user_id'"
        );
        
        ?>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('Metric', 'lcd-people'); ?></th>
                    <th><?php _e('Count', 'lcd-people'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php _e('Total WordPress Users', 'lcd-people'); ?></td>
                    <td><?php echo esc_html($user_count); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Connected Users', 'lcd-people'); ?></td>
                    <td><?php echo esc_html($connected_users); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Unconnected Users', 'lcd-people'); ?></td>
                    <td><?php echo esc_html($user_count - $connected_users); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Total People Records', 'lcd-people'); ?></td>
                    <td><?php echo esc_html($people_count); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Connected People', 'lcd-people'); ?></td>
                    <td><?php echo esc_html($connected_people); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Unconnected People', 'lcd-people'); ?></td>
                    <td><?php echo esc_html($people_count - $connected_people); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Connect unconnected users to matching people records
     */
    private function connect_unconnected_users() {
        $lcd_people = $this->main_plugin;
        $connected = 0;
        $created = 0;
        
        // Get users without person connections
        $users = get_users(array(
            'meta_query' => array(
                array(
                    'key' => '_lcd_person_id',
                    'compare' => 'NOT EXISTS'
                )
            )
        ));
        
        foreach ($users as $user) {
            // Try to find existing person by email
            $person = $lcd_people->get_person_by_email($user->user_email);
            
            if ($person) {
                // Connect to existing person
                if ($lcd_people->connect_user_to_person($user->ID, $person->ID)) {
                    $connected++;
                }
            } else {
                // Create new person record
                $person_id = $lcd_people->create_person_from_user($user->ID);
                if ($person_id) {
                    update_user_meta($user->ID, LCD_People::USER_META_KEY, $person_id);
                    $created++;
                }
            }
        }
        
        echo '<div class="notice notice-success"><p>';
        printf(
            __('Connected %d users to existing People records and created %d new People records.', 'lcd-people'),
            $connected,
            $created
        );
        echo '</p></div>';
    }

    /**
     * Render unconnected users table
     */
    private function render_unconnected_users() {
        // Get users without person connections
        $users = get_users(array(
            'meta_query' => array(
                array(
                    'key' => '_lcd_person_id',
                    'compare' => 'NOT EXISTS'
                )
            ),
            'number' => 20 // Limit to first 20 for performance
        ));
        
        if (empty($users)) {
            echo '<p>' . __('All users are connected to People records.', 'lcd-people') . '</p>';
            return;
        }
        
        ?>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php _e('User', 'lcd-people'); ?></th>
                    <th><?php _e('Email', 'lcd-people'); ?></th>
                    <th><?php _e('Registration Date', 'lcd-people'); ?></th>
                    <th><?php _e('Potential Matches', 'lcd-people'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php 
                    $lcd_people = $this->main_plugin;
                    $potential_matches = $lcd_people->find_people_by_email($user->user_email);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($user->display_name); ?></strong><br>
                            <small><?php echo esc_html($user->user_login); ?></small>
                        </td>
                        <td><?php echo esc_html($user->user_email); ?></td>
                        <td><?php echo esc_html($user->user_registered); ?></td>
                        <td>
                            <?php if (!empty($potential_matches)): ?>
                                <?php foreach ($potential_matches as $match): ?>
                                    <a href="<?php echo get_edit_post_link($match->ID); ?>" target="_blank">
                                        <?php echo esc_html($match->post_title); ?>
                                    </a><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <em><?php _e('No matches found', 'lcd-people'); ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (count($users) >= 20): ?>
            <p><em><?php _e('Showing first 20 unconnected users. Use bulk action above to connect all.', 'lcd-people'); ?></em></p>
        <?php endif; ?>
        <?php
    }
} 