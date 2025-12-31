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

        // NOTE: Sender.net settings, opt-in settings, conversion tracking, and CallHub settings
        // have been moved to the new Email Settings admin class (LCD_Email_Settings_Admin).
        // See admin/class-email-settings-admin.php

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

    // NOTE: Sender.net, opt-in, and CallHub field render methods have been moved to LCD_Email_Settings_Admin
    // See admin/class-email-settings-admin.php

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

    // NOTE: Old Sender.net settings pages have been removed. Settings are now in LCD_Email_Settings_Admin.
    // See admin/class-email-settings-admin.php

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