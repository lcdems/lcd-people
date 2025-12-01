<?php
/**
 * LCD People Opt-in Handler
 * 
 * Handles the custom opt-in form functionality including:
 * - Shortcode registration
 * - Modal integration  
 * - 2-step email/SMS opt-in process
 * - Sender.net synchronization
 * 
 * @package LCD_People
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_People_Optin_Handler {
    
    /**
     * Main plugin instance
     * @var LCD_People
     */
    private $main_plugin;
    
    /**
     * Constructor
     * 
     * @param LCD_People $main_plugin Main plugin instance
     */
    public function __construct($main_plugin) {
        $this->main_plugin = $main_plugin;
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register shortcode
        add_shortcode('lcd_optin_form', array($this, 'render_optin_form_shortcode'));
        
        // AJAX handlers
        add_action('wp_ajax_lcd_optin_submit_email', array($this, 'ajax_submit_email'));
        add_action('wp_ajax_nopriv_lcd_optin_submit_email', array($this, 'ajax_submit_email'));
        
        add_action('wp_ajax_lcd_optin_submit_final', array($this, 'ajax_submit_final'));
        add_action('wp_ajax_nopriv_lcd_optin_submit_final', array($this, 'ajax_submit_final'));
        
        add_action('wp_ajax_lcd_optin_submit_combined', array($this, 'ajax_submit_combined'));
        add_action('wp_ajax_nopriv_lcd_optin_submit_combined', array($this, 'ajax_submit_combined'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add modal integration
        add_action('wp_footer', array($this, 'add_modal_integration'));
        
        // Subscription preferences AJAX handlers
        add_action('wp_ajax_lcd_get_subscription_preferences', array($this, 'ajax_get_subscription_preferences'));
        add_action('wp_ajax_lcd_update_subscription_preferences', array($this, 'ajax_update_subscription_preferences'));
        add_action('wp_ajax_lcd_unsubscribe_from_all', array($this, 'ajax_unsubscribe_from_all'));
    }
    
    /**
     * Enqueue scripts for opt-in functionality
     */
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        
        // Enqueue opt-in form CSS
        wp_enqueue_style(
            'lcd-people-optin-form',
            plugins_url('assets/css/optin-form.css', dirname(__FILE__)),
            array(),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/optin-form.css')
        );
        
        // Enqueue opt-in form JavaScript
        wp_enqueue_script(
            'lcd-people-optin-form',
            plugins_url('assets/js/optin-form.js', dirname(__FILE__)),
            array('jquery'),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/optin-form.js'),
            true
        );
        
        wp_localize_script('lcd-people-optin-form', 'lcdOptinVars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lcd_optin_nonce'),
            'strings' => array(
                'processing' => __('Processing...', 'lcd-people'),
                'error' => __('An error occurred. Please try again.', 'lcd-people'),
                'invalid_email' => __('Please enter a valid email address.', 'lcd-people'),
                'required_fields' => __('Please fill in all required fields.', 'lcd-people'),
                'required_consent' => __('Please accept the terms and conditions.', 'lcd-people'),
                'success' => __('Thank you for joining our list!', 'lcd-people')
            ),
            'tracking' => array(
                'google_gtag_id' => get_option('lcd_people_optin_google_gtag_id', ''),
                'google_conversion_label' => get_option('lcd_people_optin_google_conversion_label', ''),
                'facebook_pixel_id' => get_option('lcd_people_optin_facebook_pixel_id', ''),
                'conversion_value' => get_option('lcd_people_optin_conversion_value', '')
            )
        ));
    }
    
    /**
     * Render opt-in form shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Form HTML
     */
    public function render_optin_form_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'modal' => 'false',
            'type' => 'two-step' // 'two-step' (default) or 'combined' (all fields in one form)
        ), $atts);
        
        return $this->render_optin_form($atts['modal'] === 'true', $atts['type']);
    }
    
    /**
     * Render the opt-in form HTML
     * 
     * @param bool $is_modal Whether this is for modal display
     * @param string $form_type Type of form ('two-step' or 'combined')
     * @return string Form HTML
     */
    public function render_optin_form($is_modal = false, $form_type = 'two-step') {
        $settings = $this->get_optin_settings();
        $available_groups = $this->get_available_groups();
        
        // Note: Empty available_groups is OK - form can work with just auto-add groups
        // from email_optin/sms_optin settings
        
        // Check if we should show SMS step directly (for 10 DLC review)
        $force_step = null;
        if (isset($_GET['view']) && $_GET['view'] === 'sms') {
            $force_step = 'sms';
        }
        
        $container_class = $is_modal ? 'lcd-optin-modal' : 'lcd-optin-embedded';
        
        ob_start();
        include __DIR__ . '/../templates/optin-form.php';
        return ob_get_clean();
    }
    
    /**
     * Handle email step submission
     */
    public function ajax_submit_email() {
        check_ajax_referer('lcd_optin_nonce', 'nonce');
        
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $groups = array_map('sanitize_text_field', $_POST['groups'] ?? array());
        $main_consent = !empty($_POST['main_consent']);
        
        // Validation
        if (empty($first_name) || empty($last_name) || empty($email)) {
            wp_send_json_error(array(
                'message' => __('Please fill in all required fields.', 'lcd-people')
            ));
        }
        
        if (!is_email($email)) {
            wp_send_json_error(array(
                'message' => __('Please enter a valid email address.', 'lcd-people')
            ));
        }
        
        // Note: Empty $groups is OK - form can work with just auto-add groups
        // from email_optin/sms_optin settings
        
        // Validate main consent if disclaimer is configured
        $settings = $this->get_optin_settings();
        if (!empty($settings['main_disclaimer']) && !$main_consent) {
            wp_send_json_error(array(
                'message' => __('Please accept the terms and conditions.', 'lcd-people')
            ));
        }
        
        // Immediately sync to Sender.net for email signup (without SMS)
        $sync_result = $this->sync_to_sender($email, $first_name, $last_name, $groups, null);
        
        if (!$sync_result['success']) {
            wp_send_json_error(array(
                'message' => $sync_result['message'] ?? __('An error occurred. Please try again.', 'lcd-people')
            ));
        }
        
        // Store in session for SMS step
        $session_data = array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'groups' => $groups,
            'timestamp' => time()
        );
        
        // Use transients instead of sessions for better compatibility
        $session_key = 'lcd_optin_' . wp_generate_password(20, false);
        set_transient($session_key, $session_data, 30 * MINUTE_IN_SECONDS); // 30 minutes
        
        wp_send_json_success(array(
            'message' => __('Thank you! You\'ve been added to our email list. Would you like to receive text messages too?', 'lcd-people'),
            'session_key' => $session_key
        ));
    }
    
    /**
     * Handle final submission (SMS opt-in only)
     */
    public function ajax_submit_final() {
        check_ajax_referer('lcd_optin_nonce', 'nonce');
        
        $session_key = sanitize_text_field($_POST['session_key'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $sms_consent = !empty($_POST['sms_consent']);
        $main_consent = !empty($_POST['main_consent']);
        
        // Check if session exists
        if (empty($session_key)) {
            wp_send_json_error(array(
                'message' => __('Please start from the beginning of our sign-up process to subscribe.', 'lcd-people'),
                'needs_redirect' => true
            ));
        }
        
        // Validate main consent if disclaimer is configured
        $settings = $this->get_optin_settings();
        if (!empty($settings['main_disclaimer']) && !$main_consent) {
            wp_send_json_error(array(
                'message' => __('Please accept the terms and conditions.', 'lcd-people')
            ));
        }
        
        // Retrieve session data
        $session_data = get_transient($session_key);
        if (!$session_data) {
            wp_send_json_error(array(
                'message' => __('Session expired. Please start over.', 'lcd-people')
            ));
        }
        
        $email = $session_data['email'];
        $first_name = $session_data['first_name'];
        $last_name = $session_data['last_name'];
        
        // Clean up session
        delete_transient($session_key);
        
        // If SMS consent given, update user with SMS info
        if ($sms_consent) {
            // Validate phone if SMS consent given
            if (empty($phone)) {
                wp_send_json_error(array(
                    'message' => __('Phone number is required for SMS updates.', 'lcd-people')
                ));
            }
            
            // Format phone number
            $phone = $this->format_phone_number($phone);
            
            // Update user with SMS info (this adds SMS groups and phone number)
            $result = $this->update_user_sms_preferences(
                $email,
                $first_name,
                $last_name,
                $phone
            );
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'message' => __('Great! You\'ve been added to our SMS list too.', 'lcd-people')
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $result['message'] ?? __('An error occurred adding SMS. Your email subscription is still active.', 'lcd-people')
                ));
            }
        } else {
            // User chose to skip SMS
            wp_send_json_success(array(
                'message' => __('Thank you! You\'re all set with email updates.', 'lcd-people')
            ));
        }
    }
    
    /**
     * Handle combined form submission (all fields at once)
     */
    public function ajax_submit_combined() {
        check_ajax_referer('lcd_optin_nonce', 'nonce');
        
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $groups = array_map('sanitize_text_field', $_POST['groups'] ?? array());
        $sms_consent = !empty($_POST['sms_consent']);
        $main_consent = !empty($_POST['main_consent']);
        
        // Validation - only email is required
        if (empty($email)) {
            wp_send_json_error(array(
                'message' => __('Please enter your email address.', 'lcd-people')
            ));
        }
        
        if (!is_email($email)) {
            wp_send_json_error(array(
                'message' => __('Please enter a valid email address.', 'lcd-people')
            ));
        }
        
        // Validate main consent if disclaimer is configured
        $settings = $this->get_optin_settings();
        if (!empty($settings['main_disclaimer']) && !$main_consent) {
            wp_send_json_error(array(
                'message' => __('Please accept the terms and conditions.', 'lcd-people')
            ));
        }
        
        // If phone is provided, SMS consent is required
        if (!empty($phone) && !$sms_consent) {
            wp_send_json_error(array(
                'message' => __('Please accept the SMS consent to receive text messages.', 'lcd-people')
            ));
        }
        
        // Format phone number if provided
        $formatted_phone = !empty($phone) ? $this->format_phone_number($phone) : null;
        
        // Sync to Sender.net (with SMS only if phone provided and consent given)
        $sync_result = $this->sync_to_sender($email, $first_name, $last_name, $groups, ($sms_consent && $formatted_phone) ? $formatted_phone : null);
        
        if (!$sync_result['success']) {
            wp_send_json_error(array(
                'message' => $sync_result['message'] ?? __('An error occurred. Please try again.', 'lcd-people')
            ));
        }
        
        // Success message based on whether SMS was included
        if ($sms_consent && !empty($phone)) {
            wp_send_json_success(array(
                'message' => __('Thank you! You\'ve been added to our email and SMS lists.', 'lcd-people')
            ));
        } else {
            wp_send_json_success(array(
                'message' => __('Thank you! You\'ve been added to our email list.', 'lcd-people')
            ));
        }
    }
    
    /**
     * Update user with SMS preferences
     * 
     * @param string $email
     * @param string $first_name
     * @param string $last_name  
     * @param string $phone
     * @return array Result with success status and message
     */
    private function update_user_sms_preferences($email, $first_name, $last_name, $phone) {
        $token = get_option('lcd_people_sender_token');
        
        if (empty($token)) {
            return array(
                'success' => false,
                'message' => __('Email service not configured.', 'lcd-people')
            );
        }
        
        // Get SMS-specific groups
        $group_assignments = get_option('lcd_people_sender_group_assignments', array());
        $sms_groups = $group_assignments['sms_optin'] ?? array();
        
        // Format phone number
        $phone = $this->format_phone_number($phone);
        
        // Prepare subscriber data for SMS update
        $subscriber_data = array(
            'email' => $email,
            'firstname' => $first_name,
            'lastname' => $last_name,
            'phone' => $phone,
            'groups' => $sms_groups,
            'trigger_automation' => true,
            'trigger_groups' => true
        );
        
        // Update subscriber with SMS info
        $response = wp_remote_post('https://api.sender.net/v2/subscribers', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($subscriber_data),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Network error. Please try again.', 'lcd-people')
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status === 200 || $status === 201) {
            return array(
                'success' => true,
                'message' => __('SMS preferences updated successfully!', 'lcd-people')
            );
        } else {
            // Log the error for debugging
            error_log('LCD People SMS Update: Sender.net API error - Status: ' . $status . ', Body: ' . wp_remote_retrieve_body($response));
            
            return array(
                'success' => false,
                'message' => isset($body['message']) ? $body['message'] : __('SMS update failed. Please try again.', 'lcd-people')
            );
        }
    }
    
    /**
     * Sync subscriber to Sender.net
     * 
     * @param string $email
     * @param string $first_name
     * @param string $last_name  
     * @param array $groups Selected groups by user
     * @param string|null $phone
     * @return array Result with success status and message
     */
    private function sync_to_sender($email, $first_name, $last_name, $groups, $phone = null) {
        $token = get_option('lcd_people_sender_token');
        
        if (empty($token)) {
            return array(
                'success' => false,
                'message' => __('Email service not configured.', 'lcd-people')
            );
        }
        
        // Add auto-add groups to the selected groups
        $group_assignments = get_option('lcd_people_sender_group_assignments', array());
        $auto_groups = $group_assignments['email_optin'] ?? array();
        
        // Add SMS opt-in groups if phone number was provided
        if (!empty($phone)) {
            $sms_groups = $group_assignments['sms_optin'] ?? array();
            $auto_groups = array_merge($auto_groups, $sms_groups);
        }
        
        $all_groups = array_merge($groups, $auto_groups);
        $all_groups = array_unique($all_groups); // Remove duplicates
        
        // Prepare subscriber data
        $subscriber_data = array(
            'email' => $email,
            'firstname' => $first_name,
            'lastname' => $last_name,
            'groups' => $all_groups,
            'trigger_automation' => true, // Always trigger automations for opt-ins
            'trigger_groups' => true
        );
        
        // Add phone if provided
        if (!empty($phone)) {
            $subscriber_data['phone'] = $phone;
        }
        
        // Try to create/update subscriber
        $response = wp_remote_post('https://api.sender.net/v2/subscribers', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($subscriber_data),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Network error. Please try again.', 'lcd-people')
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status === 200 || $status === 201) {
            return array(
                'success' => true,
                'message' => __('Successfully subscribed!', 'lcd-people')
            );
        } else {
            // Log the error for debugging
            error_log('LCD People Opt-in: Sender.net API error - Status: ' . $status . ', Body: ' . wp_remote_retrieve_body($response));
            
            return array(
                'success' => false,
                'message' => isset($body['message']) ? $body['message'] : __('Subscription failed. Please try again.', 'lcd-people')
            );
        }
    }
    
    /**
     * Format phone number for consistency
     * 
     * @param string $phone
     * @return string
     */
    private function format_phone_number($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Add +1 country code for US numbers if not already present
        if (strlen($phone) === 10) {
            $phone = '+1' . $phone;
        } elseif (strlen($phone) === 11 && $phone[0] === '1') {
            $phone = '+' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Get opt-in form settings
     * 
     * @return array
     */
    private function get_optin_settings() {
        return array(
            'email_title' => get_option('lcd_people_optin_email_title', 'Join Our Email List'),
            'sms_title' => get_option('lcd_people_optin_sms_title', 'Stay Connected with SMS'),
            'email_cta' => get_option('lcd_people_optin_email_cta', 'Continue'),
            'sms_cta' => get_option('lcd_people_optin_sms_cta', 'Join SMS List'),
            'skip_sms_cta' => get_option('lcd_people_optin_skip_sms_cta', 'No Thanks, Email Only'),
            'main_disclaimer' => get_option('lcd_people_optin_main_disclaimer', 'By signing up, you agree to receive emails from us. You can unsubscribe at any time.'),
            'sms_disclaimer' => get_option('lcd_people_optin_sms_disclaimer', 'By checking this box, you consent to receive text messages from us. Message and data rates may apply. Reply STOP to opt out at any time.')
        );
    }
    
    /**
     * Get available groups for opt-in
     * 
     * @return array
     */
    private function get_available_groups() {
        $optin_groups = get_option('lcd_people_optin_groups', array());
        if (empty($optin_groups)) {
            return array();
        }
        
        // Convert old format to new format if needed
        if (isset($optin_groups[0]) && is_string($optin_groups[0])) {
            // Old format - convert to new format
            $new_format = array();
            foreach ($optin_groups as $index => $group_id) {
                $new_format[$group_id] = array(
                    'order' => $index + 1,
                    'default' => $index === 0
                );
            }
            $optin_groups = $new_format;
        }
        
        // Get all groups from Sender.net
        $all_groups = $this->get_sender_groups();
        
        // Build available groups with metadata
        $available_groups = array();
        
        // Sort by order
        uasort($optin_groups, function($a, $b) {
            return ($a['order'] ?? 999) - ($b['order'] ?? 999);
        });
        
        foreach ($optin_groups as $group_id => $group_data) {
            if (isset($all_groups[$group_id])) {
                $available_groups[$group_id] = array(
                    'name' => $all_groups[$group_id],
                    'default' => !empty($group_data['default'])
                );
            }
        }
        
        return $available_groups;
    }
    
    /**
     * Get Sender.net groups
     */
    private function get_sender_groups() {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            return array();
        }

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

        set_transient('lcd_people_sender_groups', $groups, HOUR_IN_SECONDS);
        return $groups;
    }
    
    /**
     * Add modal integration to footer
     */
    public function add_modal_integration() {
        // Only add if we're on a page that might use the modal
        if (is_admin()) {
            return;
        }
        
        // Pre-generate the modal content
        $modal_content = $this->render_optin_form(true);
        // Properly escape for JavaScript using JSON encoding
        $modal_content_js = json_encode($modal_content);
        $modal_title_js = json_encode(get_option('lcd_people_optin_email_title', 'Join Our Email List'));
        
        ?>
        <script type="text/javascript">
        // Store the opt-in content for modal use - the modal integration JS will handle the rest
        window.lcdOptinModalContent = {
            title: <?php echo $modal_title_js; ?>,
            content: <?php echo $modal_content_js; ?>,
            size: 'medium',
            className: 'lcd-optin-modal-wrapper'
        };
        </script>
        <?php
    }
    

    
    /**
     * Get subscription preferences for a user
     */
    public function ajax_get_subscription_preferences() {
        check_ajax_referer('lcd_subscription_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to view subscription preferences.', 'lcd-people')));
        }
        
        $user = wp_get_current_user();
        $preferences = $this->get_user_subscription_preferences($user->user_email);
        
        if ($preferences['success']) {
            wp_send_json_success($preferences['data']);
        } else {
            wp_send_json_error(array('message' => $preferences['message']));
        }
    }
    
    /**
     * Update subscription preferences for a user
     */
    public function ajax_update_subscription_preferences() {
        check_ajax_referer('lcd_subscription_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to update subscription preferences.', 'lcd-people')));
        }
        
        $user = wp_get_current_user();
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $groups = array_map('sanitize_text_field', $_POST['groups'] ?? array());
        $sms_consent = !empty($_POST['sms_consent']);
        

        
        // Validation
        if (empty($first_name) || empty($last_name)) {
            wp_send_json_error(array('message' => __('First and last name are required.', 'lcd-people')));
        }
        
        // Format phone number if provided
        if (!empty($phone)) {
            $phone = $this->format_phone_number($phone);
        }
        
        $result = $this->update_user_subscription_preferences(
            $user->user_email,
            $first_name,
            $last_name,
            $groups,
            $sms_consent && !empty($phone) ? $phone : null
        );

        
        if ($result['success']) {
            wp_send_json_success(array('message' => __('Subscription preferences updated successfully!', 'lcd-people')));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Unsubscribe user from all email communications
     */
    public function ajax_unsubscribe_from_all() {
        check_ajax_referer('lcd_subscription_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to unsubscribe.', 'lcd-people')));
        }
        
        $user = wp_get_current_user();
        $result = $this->unsubscribe_user_from_all($user->user_email);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => __('You have been unsubscribed from all email communications.', 'lcd-people')));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Get user's current subscription preferences from Sender.net
     * 
     * @param string $email User's email address
     * @return array Result with success status and data
     */
    public function get_user_subscription_preferences($email) {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            return array(
                'success' => false,
                'message' => __('Email service not configured.', 'lcd-people')
            );
        }
        
        // Get subscriber from Sender.net using the correct API endpoint
        $response = wp_remote_get('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Unable to retrieve subscription information.', 'lcd-people')
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);
        

        
        if ($status !== 200) {
            return array(
                'success' => false,
                'message' => __('Email address not found in our system.', 'lcd-people')
            );
        }
        
        // The response structure from Sender.net API - data is nested
        $subscriber_data = isset($body['data']) ? $body['data'] : $body;
        $available_groups = $this->get_available_groups();
        

        
        // Get user's current groups - check multiple possible response structures
        $user_groups = array();
        if (isset($subscriber_data['groups']) && is_array($subscriber_data['groups'])) {
            $user_groups = $subscriber_data['groups'];
        } elseif (isset($subscriber_data['subscriber_tags']) && is_array($subscriber_data['subscriber_tags'])) {
            foreach ($subscriber_data['subscriber_tags'] as $tag) {
                if (is_array($tag) && isset($tag['id'])) {
                    $user_groups[] = $tag['id'];
                } elseif (is_string($tag)) {
                    $user_groups[] = $tag;
                }
            }
        }
        
        // Get fallback data from WordPress user if available
        $current_user = wp_get_current_user();
        $fallback_first_name = '';
        $fallback_last_name = '';
        
        if ($current_user && $current_user->ID > 0) {
            $fallback_first_name = $current_user->first_name ?: '';
            $fallback_last_name = $current_user->last_name ?: '';
            
            // If no first name from user meta, try to parse display name
            if (empty($fallback_first_name) && !empty($current_user->display_name)) {
                $name_parts = explode(' ', trim($current_user->display_name), 2);
                $fallback_first_name = $name_parts[0] ?? '';
                $fallback_last_name = $name_parts[1] ?? '';
            }
        }
        
        return array(
            'success' => true,
            'data' => array(
                'email' => $subscriber_data['email'] ?? $email,
                'first_name' => $subscriber_data['firstname'] ?: $fallback_first_name,
                'last_name' => $subscriber_data['lastname'] ?: $fallback_last_name,
                'phone' => $subscriber_data['phone'] ?? '',
                'is_active' => isset($subscriber_data['status']['email']) ? ($subscriber_data['status']['email'] === 'active') : true,
                'user_groups' => $user_groups,
                'available_groups' => $available_groups,
                'subscriber_id' => $subscriber_data['id'] ?? null // Add subscriber ID for phone removal
            )
        );
    }
    
    /**
     * Update user's subscription preferences in Sender.net
     * 
     * @param string $email User's email address
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param array $groups Selected groups
     * @param string|null $phone Phone number (null to remove SMS)
     * @return array Result with success status and message
     */
    public function update_user_subscription_preferences($email, $first_name, $last_name, $groups, $phone = null) {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            return array(
                'success' => false,
                'message' => __('Email service not configured.', 'lcd-people')
            );
        }
        
        // First get current user data to see what groups they're currently in
        $current_prefs = $this->get_user_subscription_preferences($email);
        if (!$current_prefs['success']) {
            return $current_prefs; // Return the error
        }
        
        $current_groups = $current_prefs['data']['user_groups'];
        
        // Add auto-add groups to the selected groups
        $group_assignments = get_option('lcd_people_sender_group_assignments', array());
        $auto_groups = $group_assignments['email_optin'] ?? array();
        
        // Add SMS opt-in groups if phone number was provided
        if (!empty($phone)) {
            $sms_groups = $group_assignments['sms_optin'] ?? array();
            $auto_groups = array_merge($auto_groups, $sms_groups);
        }
        
        $desired_groups = array_merge($groups, $auto_groups);
        $desired_groups = array_unique($desired_groups); // Remove duplicates
        
        // Calculate groups to add and remove
        $groups_to_add = array_diff($desired_groups, $current_groups);
        $groups_to_remove = array_diff($current_groups, $desired_groups);
        
        $errors = array();
        
        // Remove from groups first
        foreach ($groups_to_remove as $group_id) {
            $result = $this->remove_subscriber_from_group($email, $group_id, $token);
            if (!$result['success']) {
                $errors[] = sprintf(__('Failed to remove from group %s: %s', 'lcd-people'), $group_id, $result['message']);
            }
        }
        
        // Add to new groups
        foreach ($groups_to_add as $group_id) {
            $result = $this->add_subscriber_to_group($email, $group_id, $token);
            if (!$result['success']) {
                $errors[] = sprintf(__('Failed to add to group %s: %s', 'lcd-people'), $group_id, $result['message']);
            }
        }
        
        // Update basic subscriber info (name, phone)
        // Pass null for phone if SMS consent is not given, to trigger phone removal
        $phone_to_update = !empty($phone) ? $phone : null;
        
        // Get subscriber ID for phone removal if needed
        $subscriber_id = null;
        if (is_null($phone_to_update) || trim($phone_to_update) === '') {
            $subscriber_id = isset($current_prefs['data']['subscriber_id']) ? $current_prefs['data']['subscriber_id'] : null;
        }
        
        $update_result = $this->update_subscriber_info($email, $first_name, $last_name, $phone_to_update, $token, $subscriber_id);
        if (!$update_result['success']) {
            $errors[] = sprintf(__('Failed to update contact info: %s', 'lcd-people'), $update_result['message']);
        }
        
        if (empty($errors)) {
            return array(
                'success' => true,
                'message' => __('Subscription preferences updated successfully!', 'lcd-people')
            );
        } else {
            return array(
                'success' => false,
                'message' => implode(' ', $errors)
            );
        }
    }
    
    /**
     * Add subscriber to a group
     */
    private function add_subscriber_to_group($email, $group_id, $token) {
        $response = wp_remote_post("https://api.sender.net/v2/subscribers/groups/{$group_id}", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode(array(
                'subscribers' => array($email)
            )),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => __('Network error', 'lcd-people'));
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status === 200 || $status === 201) {
            return array('success' => true);
        } else {
            $response_data = json_decode($body, true);
            return array(
                'success' => false, 
                'message' => isset($response_data['message']) ? $response_data['message'] : "HTTP {$status}"
            );
        }
    }
    
    /**
     * Remove subscriber from a group
     */
    private function remove_subscriber_from_group($email, $group_id, $token) {
        $response = wp_remote_request("https://api.sender.net/v2/subscribers/groups/{$group_id}", array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode(array(
                'subscribers' => array($email)
            )),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => __('Network error', 'lcd-people'));
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status === 200 || $status === 204) {
            return array('success' => true);
        } else {
            $response_data = json_decode($body, true);
            return array(
                'success' => false, 
                'message' => isset($response_data['message']) ? $response_data['message'] : "HTTP {$status}"
            );
        }
    }
    
    /**
     * Update subscriber basic info (name, phone)
     */
    private function update_subscriber_info($email, $first_name, $last_name, $phone, $token, $subscriber_id = null) {
        $errors = array();
        
        // First, update basic info (name)
        $subscriber_data = array(
            'firstname' => $first_name,
            'lastname' => $last_name,
            'trigger_automation' => false
        );
        
        // Add phone if provided
        if (!empty($phone)) {
            $subscriber_data['phone'] = $phone;
        }
        
        $response = wp_remote_request('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($subscriber_data),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $errors[] = __('Network error updating subscriber info', 'lcd-people');
        } else {
            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status !== 200 && $status !== 201) {
                $response_data = json_decode($body, true);
                $errors[] = isset($response_data['message']) ? $response_data['message'] : "HTTP {$status}";
            }
        }
        
        // If phone is empty/null, remove it using the dedicated endpoint
        if (is_null($phone) || trim($phone) === '') {
            if ($subscriber_id) {
                $remove_result = $this->remove_subscriber_phone($subscriber_id, $token);
                if (!$remove_result['success']) {
                    $errors[] = sprintf(__('Failed to remove phone: %s', 'lcd-people'), $remove_result['message']);
                }
            } else {
                $errors[] = __('Unable to remove phone: subscriber ID not found', 'lcd-people');
            }
        }
        
        if (empty($errors)) {
            return array('success' => true);
        } else {
            return array(
                'success' => false, 
                'message' => implode('. ', $errors)
            );
        }
    }
    
    /**
     * Remove phone number from subscriber using Sender.net's dedicated endpoint
     */
    private function remove_subscriber_phone($subscriber_id, $token) {
        $response = wp_remote_request('https://api.sender.net/v2/subscribers/' . urlencode($subscriber_id) . '/remove_phone', array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode(array()), // Empty body as per API docs
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => __('Network error', 'lcd-people'));
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status === 200 || $status === 204) {
            return array('success' => true);
        } else {
            $response_data = json_decode($body, true);
            return array(
                'success' => false, 
                'message' => isset($response_data['message']) ? $response_data['message'] : "HTTP {$status}"
            );
        }
    }
    
    /**
     * Unsubscribe user from all email communications
     * 
     * @param string $email User's email address
     * @return array Result with success status and message
     */
    public function unsubscribe_user_from_all($email) {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            return array(
                'success' => false,
                'message' => __('Email service not configured.', 'lcd-people')
            );
        }
        
        // Unsubscribe from Sender.net using the correct API format
        $response = wp_remote_request('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode(array(
                'subscriber_status' => 'UNSUBSCRIBED', // Use the correct status field
                'groups' => array(), // Remove from all groups
                'trigger_automation' => false
            )),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Network error. Please try again.', 'lcd-people')
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Log the response for debugging
        error_log('LCD People Unsubscribe - Status: ' . $status . ', Response: ' . wp_remote_retrieve_body($response));
        
        if ($status === 200 || $status === 201) {
            return array(
                'success' => true,
                'message' => __('You have been unsubscribed from all email communications.', 'lcd-people')
            );
        } else {
            return array(
                'success' => false,
                'message' => isset($body['message']) ? $body['message'] : sprintf(__('Failed to unsubscribe (Error %d). Please try again.', 'lcd-people'), $status)
            );
        }
    }
} 