<?php
/**
 * LCD People Frontend
 * 
 * Handles all front-end functionality for the LCD People plugin
 * 
 * @package LCD_People
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_People_Frontend {
    private static $instance = null;
    
    /**
     * Get instance of the class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Add shortcode for member profile
        add_shortcode('lcd_member_profile', array($this, 'render_member_profile'));
        
        // Add template redirect for member profile page
        add_action('template_redirect', array($this, 'maybe_load_profile_template'));
        
        // Enqueue frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Add filter to register page template
        add_filter('theme_page_templates', array($this, 'register_page_template'));
        
        // Add filter to include our template file
        add_filter('template_include', array($this, 'load_page_template'));
    }
    
    /**
     * Register the page template with WordPress
     */
    public function register_page_template($templates) {
        // Add our template to the list of available templates
        $templates['template-member-profile.php'] = __('Member Profile', 'lcd-people');
        $templates['template-claim-account.php'] = __('Claim Account', 'lcd-people');
        return $templates;
    }
    
    /**
     * Load the custom page template when selected
     */
    public function load_page_template($template) {
        global $post;
        
        if (!$post) {
            return $template;
        }
        
        // Get the template name assigned to the current page
        $page_template = get_post_meta($post->ID, '_wp_page_template', true);
        
        // If our template is selected, load it from our plugin
        if ('template-member-profile.php' === $page_template) {
            $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/template-member-profile.php';
            
            if (file_exists($template_path)) {
                return $template_path;
            }
        }
        
        if ('template-claim-account.php' === $page_template) {
            $template_path = plugin_dir_path(dirname(__FILE__)) . 'templates/template-claim-account.php';
            
            if (file_exists($template_path)) {
                return $template_path;
            }
        }
        
        return $template;
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only load on profile pages and claim account pages
        $current_post = get_post();
        $is_claim_page = is_page_template('template-claim-account.php');
        $is_profile_page = is_page_template('template-member-profile.php');
        $has_profile_shortcode = $current_post && has_shortcode($current_post->post_content, 'lcd_member_profile');
        
        if (!$is_profile_page && !$is_claim_page && !$has_profile_shortcode) {
            return;
        }
        
        wp_enqueue_style(
            'lcd-people-frontend',
            plugins_url('/assets/css/frontend.css', dirname(__FILE__)),
            array(),
            '1.0.0'
        );
        
        // Load claim account specific assets if on claim account page
        if (is_page_template('template-claim-account.php')) {
            wp_enqueue_style(
                'lcd-people-claim-account',
                plugins_url('/assets/css/claim-account.css', dirname(__FILE__)),
                array(),
                '1.0.0'
            );
            
            wp_enqueue_script(
                'lcd-people-claim-account',
                plugins_url('/assets/js/claim-account.js', dirname(__FILE__)),
                array('jquery'),
                '1.0.0',
                true
            );
            
            wp_localize_script('lcd-people-claim-account', 'lcdClaimAccountVars', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('lcd_claim_account'),
                'login_url' => wp_login_url(),
                'reset_password_url' => wp_lostpassword_url(),
                'contact_email' => get_option('admin_email', 'info@example.com'),
                'member_dashboard_url' => home_url('/member-dashboard'),
            ));
        }
        
        wp_enqueue_script(
            'lcd-people-frontend',
            plugins_url('/assets/js/frontend.js', dirname(__FILE__)),
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_localize_script('lcd-people-frontend', 'lcdPeopleFrontend', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lcd_people_frontend'),
            'subscription_nonce' => wp_create_nonce('lcd_subscription_nonce'),
            'strings' => array(
                'loading' => __('Loading...', 'lcd-people'),
                'error' => __('An error occurred. Please try again.', 'lcd-people'),
                'confirm_unsubscribe' => __('Are you sure you want to unsubscribe from all email communications? This action cannot be undone.', 'lcd-people'),
                'updating' => __('Updating...', 'lcd-people'),
                'processing' => __('Processing...', 'lcd-people'),
                'success' => __('Changes saved successfully!', 'lcd-people'),
                'update_preferences_btn' => __('Update Preferences', 'lcd-people'),
                'phone_required' => __('Please enter a phone number first.', 'lcd-people'),
                'sms_optin_btn' => __('Opt In to SMS', 'lcd-people'),
                'sms_optout_btn' => __('Yes, Opt Out', 'lcd-people'),
                'sms_optin_success' => __('You have successfully opted in to SMS messages!', 'lcd-people'),
                'sms_optout_success' => __('You have been opted out of SMS messages.', 'lcd-people')
            )
        ));
    }
    
    /**
     * Load profile template if member profile page
     */
    public function maybe_load_profile_template() {
        if (is_page_template('template-member-profile.php')) {
            // Redirect to login if not logged in
            if (!is_user_logged_in()) {
                wp_redirect(wp_login_url(get_permalink()));
                exit;
            }
        }
    }
    
    /**
     * Get current user's person record
     */
    public function get_current_user_person() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $user_id = get_current_user_id();
        $person_id = $this->get_person_by_user_id($user_id);
        
        if (!$person_id) {
            return false;
        }
        
        return get_post($person_id);
    }
    
    /**
     * Get person by user ID
     */
    public function get_person_by_user_id($user_id) {
        $args = array(
            'post_type' => 'lcd_person',
            'meta_key' => '_lcd_person_user_id',
            'meta_value' => $user_id,
            'posts_per_page' => 1,
            'fields' => 'ids'
        );

        $query = new WP_Query($args);
        return $query->posts ? $query->posts[0] : false;
    }
    
    /**
     * Render member profile shortcode
     */
    public function render_member_profile($atts) {
        $atts = shortcode_atts(array(
            'redirect_login' => true,
        ), $atts);
        
        // If not logged in and redirect is true, show login message
        if (!is_user_logged_in() && $atts['redirect_login']) {
            return sprintf(
                '<div class="lcd-member-login-required">%s</div>',
                sprintf(
                    __('Please <a href="%s">log in</a> to view your member profile.', 'lcd-people'),
                    wp_login_url(get_permalink())
                )
            );
        }
        
        // Check if user has a person record
        $person = $this->get_current_user_person();
        $current_user = wp_get_current_user();
        
        // Initialize variables with defaults
        $person_id = null;
        $first_name = $current_user->first_name ?: ($current_user->display_name ?: $current_user->user_login);
        $last_name = $current_user->last_name ?: '';
        $email = $current_user->user_email;
        $phone = '';
        $address = '';
        $membership_status = '';
        $membership_type = '';
        $is_sustaining = false;
        $start_date = '';
        $end_date = '';
        $roles = array();
        $precincts = array();
        $has_person_record = false;
        
        if ($person) {
            // User has a person record - get all the data
            $has_person_record = true;
            $person_id = $person->ID;
            $first_name = get_post_meta($person_id, '_lcd_person_first_name', true) ?: $first_name;
            $last_name = get_post_meta($person_id, '_lcd_person_last_name', true) ?: $last_name;
            $email = get_post_meta($person_id, '_lcd_person_email', true) ?: $email;
            $phone = get_post_meta($person_id, '_lcd_person_phone', true);
            $address = get_post_meta($person_id, '_lcd_person_address', true);
            
            // Get membership details
            $membership_status = get_post_meta($person_id, '_lcd_person_membership_status', true);
            $membership_type = get_post_meta($person_id, '_lcd_person_membership_type', true);
            $is_sustaining = get_post_meta($person_id, '_lcd_person_is_sustaining', true);
            $start_date = get_post_meta($person_id, '_lcd_person_start_date', true);
            $end_date = get_post_meta($person_id, '_lcd_person_end_date', true);
            
            // Get taxonomies
            $roles = wp_get_post_terms($person_id, 'lcd_role', array('fields' => 'names'));
            $precincts = wp_get_post_terms($person_id, 'lcd_precinct', array('fields' => 'names'));
        }
        
        // Format dates nicely if they exist
        $start_date = $start_date ? date_i18n(get_option('date_format'), strtotime($start_date)) : '';
        $end_date = $end_date ? date_i18n(get_option('date_format'), strtotime($end_date)) : '';
        
        // Start output buffer
        ob_start();
        ?>
        <div class="lcd-member-profile">
            <h2><?php echo esc_html(sprintf(__('%s\'s Member Profile', 'lcd-people'), $first_name)); ?></h2>
            
            <?php if (!$has_person_record): ?>
                <div class="lcd-member-no-record-notice">
                    <p><em><?php _e('No active membership record found.', 'lcd-people'); ?></em></p>
                </div>
            <?php endif; ?>
            
            <!-- Tab Navigation -->
            <div class="lcd-member-tabs">
                <div class="lcd-member-tab-nav" role="tablist">
                    <button class="lcd-tab-button active" 
                            data-tab="membership"
                            role="tab"
                            aria-controls="membership-tab"
                            aria-selected="true"
                            id="membership-tab-button">
                        <?php _e('Membership Info', 'lcd-people'); ?>
                    </button>
                    <button class="lcd-tab-button" 
                            data-tab="subscriptions"
                            role="tab"
                            aria-controls="subscriptions-tab"
                            aria-selected="false"
                            id="subscriptions-tab-button">
                        <?php _e('Communication Preferences', 'lcd-people'); ?>
                    </button>
                </div>
                
                <!-- Membership Tab Content -->
                <div class="lcd-tab-content active" 
                     id="membership-tab"
                     role="tabpanel"
                     aria-labelledby="membership-tab-button">
                    <?php echo $this->render_membership_tab($membership_status, $membership_type, $is_sustaining, $start_date, $end_date, $first_name, $last_name, $email, $phone, $address, $roles, $precincts); ?>
                </div>
                
                <!-- Subscription Preferences Tab Content -->
                <div class="lcd-tab-content" 
                     id="subscriptions-tab"
                     role="tabpanel"
                     aria-labelledby="subscriptions-tab-button">
                    <?php echo $this->render_subscription_preferences_tab($email); ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the membership tab content
     */
    private function render_membership_tab($membership_status, $membership_type, $is_sustaining, $start_date, $end_date, $first_name, $last_name, $email, $phone, $address, $roles, $precincts) {
        ob_start();
        ?>
        <div class="lcd-member-profile-section lcd-member-status">
            <h3><?php _e('Membership Status', 'lcd-people'); ?></h3>
            <?php if ($membership_status === 'active'): ?>
                <div class="lcd-membership-badge active">
                    <?php _e('Active Member', 'lcd-people'); ?>
                </div>
            <?php elseif ($membership_status === 'grace'): ?>
                <div class="lcd-membership-badge grace">
                    <?php _e('Grace Period', 'lcd-people'); ?>
                </div>
            <?php elseif ($membership_status === 'expired'): ?>
                <div class="lcd-membership-badge expired">
                    <?php _e('Expired Membership', 'lcd-people'); ?>
                </div>
            <?php elseif ($membership_status === 'inactive'): ?>
                <div class="lcd-membership-badge inactive">
                    <?php _e('Inactive Member', 'lcd-people'); ?>
                </div>
            <?php else: ?>
                <div class="lcd-membership-badge none">
                    <?php _e('Not a Member', 'lcd-people'); ?>
                </div>
                <div class="lcd-membership-badge none">
                    <a href="<?php echo home_url('/membership'); ?>"><?php _e('Learn about membership', 'lcd-people'); ?></a>
                </div>
            <?php endif; ?>
            
            <?php if ($is_sustaining): ?>
                <div class="lcd-membership-badge sustaining">
                    <?php _e('Sustaining Member', 'lcd-people'); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($membership_type): ?>
                <p>
                    <strong><?php _e('Type:', 'lcd-people'); ?></strong>
                    <?php echo esc_html(ucfirst($membership_type)); ?>
                </p>
            <?php endif; ?>
            
            <?php if ($start_date || $end_date): ?>
                <p class="lcd-membership-dates">
                    <?php if ($start_date): ?>
                        <span class="lcd-membership-start">
                            <strong><?php _e('Start Date:', 'lcd-people'); ?></strong>
                            <?php echo esc_html($start_date); ?>
                        </span>
                    <?php endif; ?>
                    
                    <?php if ($end_date): ?>
                        <span class="lcd-membership-end">
                            <strong><?php _e('End Date:', 'lcd-people'); ?></strong>
                            <?php echo esc_html($end_date); ?>
                        </span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="lcd-member-profile-section lcd-member-info">
            <h3><?php _e('Contact Information', 'lcd-people'); ?></h3>
            <p>
                <strong><?php _e('Name:', 'lcd-people'); ?></strong>
                <?php echo esc_html(trim("$first_name $last_name")); ?>
            </p>
            
            <?php if ($email): ?>
            <p>
                <strong><?php _e('Email:', 'lcd-people'); ?></strong>
                <?php echo esc_html($email); ?>
            </p>
            <?php endif; ?>
            
            <?php if ($phone): ?>
            <p>
                <strong><?php _e('Phone:', 'lcd-people'); ?></strong>
                <?php echo esc_html($phone); ?>
            </p>
            <?php endif; ?>
            
            <?php if ($address): ?>
            <p>
                <strong><?php _e('Address:', 'lcd-people'); ?></strong>
                <?php echo esc_html($address); ?>
            </p>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($roles) || !empty($precincts)): ?>
        <div class="lcd-member-profile-section lcd-member-roles">
            <h3><?php _e('Organizational Information', 'lcd-people'); ?></h3>
            
            <?php if (!empty($roles)): ?>
            <p>
                <strong><?php _e('Roles:', 'lcd-people'); ?></strong>
                <?php echo esc_html(implode(', ', $roles)); ?>
            </p>
            <?php endif; ?>
            
            <?php if (!empty($precincts)): ?>
            <p>
                <strong><?php _e('Precinct:', 'lcd-people'); ?></strong>
                <?php echo esc_html(implode(', ', $precincts)); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the subscription preferences tab content
     */
    private function render_subscription_preferences_tab($email) {
        // Get SMS disclaimer from settings
        $sms_disclaimer = get_option('lcd_people_optin_sms_disclaimer', 'By checking this box, you consent to receive text messages from us. Message and data rates may apply. Reply STOP to opt out at any time.');
        
        ob_start();
        ?>
        <div class="lcd-subscription-preferences">
            <div id="subscription-loading" class="lcd-loading-state" style="display: none;">
                <div class="lcd-spinner"></div>
                <p><?php _e('Loading subscription preferences...', 'lcd-people'); ?></p>
            </div>
            
            <div id="subscription-error" class="lcd-error-message" style="display: none;">
                <p class="error-text"></p>
                <button type="button" class="lcd-btn lcd-btn-secondary retry-btn">
                    <?php _e('Try Again', 'lcd-people'); ?>
                </button>
            </div>
            
            <div id="subscription-form-container" style="display: none;">
                <!-- Email Preferences Section -->
                <div class="lcd-preferences-section">
                    <h3><?php _e('Email Preferences', 'lcd-people'); ?></h3>
                    <p><?php _e('Manage your email subscriptions and communication preferences below.', 'lcd-people'); ?></p>
                    
                    <form id="subscription-preferences-form">
                        <div class="subscription-form-group">
                            <label for="sub-email"><?php _e('Email Address', 'lcd-people'); ?></label>
                            <input type="email" id="sub-email" name="email" readonly>
                            <p class="description"><?php _e('Your email address cannot be changed here. Contact us if you need to update it.', 'lcd-people'); ?></p>
                        </div>
                        
                        <div class="subscription-form-group">
                            <label for="sub-first-name"><?php _e('First Name', 'lcd-people'); ?> <span class="required">*</span></label>
                            <input type="text" id="sub-first-name" name="first_name" required>
                        </div>
                        
                        <div class="subscription-form-group">
                            <label for="sub-last-name"><?php _e('Last Name', 'lcd-people'); ?> <span class="required">*</span></label>
                            <input type="text" id="sub-last-name" name="last_name" required>
                        </div>
                        
                        <div class="subscription-form-group">
                            <label for="sub-phone"><?php _e('Phone Number', 'lcd-people'); ?></label>
                            <input type="tel" id="sub-phone" name="phone" placeholder="(555) 123-4567">
                            <input type="hidden" id="sub-phone-original" name="phone_original" value="">
                        </div>
                        
                        <div class="subscription-form-group">
                            <label><?php _e('Email Interests', 'lcd-people'); ?></label>
                            <div id="subscription-groups" class="subscription-checkbox-group">
                                <!-- Groups will be populated via JavaScript -->
                            </div>
                        </div>
                        
                        <div class="subscription-form-actions">
                            <button type="submit" class="lcd-btn lcd-btn-primary">
                                <?php _e('Update Preferences', 'lcd-people'); ?>
                            </button>
                            <button type="button" id="unsubscribe-all-btn" class="lcd-btn lcd-btn-danger">
                                <?php _e('Unsubscribe from All Emails', 'lcd-people'); ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- SMS Preferences Section -->
                <div class="lcd-preferences-section lcd-sms-preferences-section">
                    <h3><?php _e('SMS Preferences', 'lcd-people'); ?></h3>
                    <p><?php _e('Manage your text message preferences below.', 'lcd-people'); ?></p>
                    
                    <div class="lcd-sms-toggle-container">
                        <div class="lcd-toggle-wrapper">
                            <label class="lcd-toggle">
                                <input type="checkbox" id="sms-optin-toggle" name="sms_optin_toggle">
                                <span class="lcd-toggle-slider"></span>
                            </label>
                            <label for="sms-optin-toggle" class="lcd-toggle-label">
                                <?php _e('Opt in to SMS messages', 'lcd-people'); ?>
                            </label>
                        </div>
                        
                        <!-- SMS Opt-in Panel (shown when toggle is ON but not yet opted in) -->
                        <div id="sms-optin-panel" class="lcd-sms-panel" style="display: none;">
                            <div class="lcd-sms-consent-wrapper">
                                <label class="subscription-checkbox-label">
                                    <input type="checkbox" id="sms-consent-checkbox" name="sms_consent_checkbox" value="1">
                                    <span class="checkmark"></span>
                                    <span class="consent-text">
                                        <?php echo wp_kses_post($sms_disclaimer); ?>
                                    </span>
                                </label>
                            </div>
                            <div class="lcd-sms-actions">
                                <button type="button" id="sms-optin-btn" class="lcd-btn lcd-btn-primary" disabled>
                                    <?php _e('Opt In to SMS', 'lcd-people'); ?>
                                </button>
                            </div>
                            <p class="lcd-sms-phone-notice" id="sms-phone-required-notice" style="display: none;">
                                <?php _e('Please add your phone number above and save your profile before opting in to SMS.', 'lcd-people'); ?>
                            </p>
                        </div>
                        
                        <!-- SMS Opted-in Status (shown when user is already opted in) -->
                        <div id="sms-optedin-status" class="lcd-sms-panel" style="display: none;">
                            <p class="lcd-sms-status-text">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('You are currently opted in to SMS messages.', 'lcd-people'); ?>
                            </p>
                        </div>
                        
                        <!-- SMS Opt-out Confirmation (shown when toggling OFF) -->
                        <div id="sms-optout-confirm" class="lcd-sms-panel lcd-sms-warning" style="display: none;">
                            <p class="lcd-warning-text">
                                <span class="dashicons dashicons-warning"></span>
                                <?php _e('Are you sure you want to opt out of SMS messages? You will no longer receive text updates from us.', 'lcd-people'); ?>
                            </p>
                            <div class="lcd-sms-actions">
                                <button type="button" id="sms-optout-confirm-btn" class="lcd-btn lcd-btn-danger">
                                    <?php _e('Yes, Opt Out', 'lcd-people'); ?>
                                </button>
                                <button type="button" id="sms-optout-cancel-btn" class="lcd-btn lcd-btn-secondary">
                                    <?php _e('Cancel', 'lcd-people'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <!-- SMS Not Opted-in Status (shown when toggle is OFF and not opted in) -->
                        <div id="sms-not-optedin-status" class="lcd-sms-panel" style="display: none;">
                            <p class="lcd-sms-status-text lcd-muted">
                                <?php _e('You are not currently opted in to SMS messages.', 'lcd-people'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Phone Number Change Warning Modal -->
                <div id="phone-change-warning" class="lcd-modal-overlay" style="display: none;">
                    <div class="lcd-modal-content">
                        <h4><?php _e('Phone Number Change Detected', 'lcd-people'); ?></h4>
                        <p><?php _e('You have changed your phone number. If you save this change, your SMS opt-in status will be reset and you will need to re-opt in with your new number.', 'lcd-people'); ?></p>
                        <div class="lcd-modal-actions">
                            <button type="button" id="phone-change-confirm-btn" class="lcd-btn lcd-btn-primary">
                                <?php _e('Continue & Save', 'lcd-people'); ?>
                            </button>
                            <button type="button" id="phone-change-cancel-btn" class="lcd-btn lcd-btn-secondary">
                                <?php _e('Cancel', 'lcd-people'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Phone Removal Warning Modal -->
                <div id="phone-removal-warning" class="lcd-modal-overlay" style="display: none;">
                    <div class="lcd-modal-content">
                        <h4><?php _e('Phone Number Removal', 'lcd-people'); ?></h4>
                        <p><?php _e('You are removing your phone number while still opted in to SMS messages. This will automatically opt you out of SMS messages.', 'lcd-people'); ?></p>
                        <div class="lcd-modal-actions">
                            <button type="button" id="phone-removal-confirm-btn" class="lcd-btn lcd-btn-primary">
                                <?php _e('Remove & Opt Out', 'lcd-people'); ?>
                            </button>
                            <button type="button" id="phone-removal-cancel-btn" class="lcd-btn lcd-btn-secondary">
                                <?php _e('Cancel', 'lcd-people'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div id="subscription-success" class="lcd-success-message" style="display: none;">
                    <h4><?php _e('Success!', 'lcd-people'); ?></h4>
                    <p class="success-text"></p>
                </div>
            </div>
            
            <div id="subscription-not-found" class="lcd-info-message" style="display: none;">
                <h4><?php _e('No Subscription Found', 'lcd-people'); ?></h4>
                <p><?php _e('You are not currently subscribed to our email list.', 'lcd-people'); ?></p>
                <p><?php _e('Would you like to subscribe?', 'lcd-people'); ?></p>
                <a href="#" class="lcd-btn lcd-btn-primary" data-modal="optin-form">
                    <?php _e('Subscribe Now', 'lcd-people'); ?>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get volunteer interest form submission data
     */
    private function get_volunteer_submission_data($submission_id) {
        global $wpdb;
        
        // Check if Forminator tables exist
        $entries_table = $wpdb->prefix . 'frmt_form_entry';
        $meta_table = $wpdb->prefix . 'frmt_form_entry_meta';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$entries_table'") != $entries_table) {
            return false;
        }

        try {
            // Get the submission entry from database
            $entry = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $entries_table WHERE entry_id = %d",
                $submission_id
            ));
            
            if (!$entry) {
                return false;
            }

            // Get form name if possible
            $form_name = '';
            if (class_exists('Forminator_API')) {
                try {
                    $form = Forminator_API::get_form($entry->form_id);
                    $form_name = $form && isset($form->settings['formName']) ? $form->settings['formName'] : '';
                } catch (Exception $e) {
                    // Fallback to form ID if we can't get the name
                    $form_name = 'Form ID: ' . $entry->form_id;
                }
            }

            // Get field labels and visibility from the form definition
            $field_labels = array();
            $visible_fields = array();
            if (class_exists('Forminator_API')) {
                try {
                    $form_fields = Forminator_API::get_form_fields($entry->form_id);
                    
                    if ($form_fields && is_array($form_fields)) {
                        foreach ($form_fields as $field) {
                            // Use reflection to access the protected raw property
                            $field_data = null;
                            if (is_object($field)) {
                                try {
                                    $reflection = new ReflectionClass($field);
                                    $rawProperty = $reflection->getProperty('raw');
                                    $rawProperty->setAccessible(true);
                                    $field_data = $rawProperty->getValue($field);
                                } catch (Exception $e) {
                                    continue;
                                }
                            }
                            
                            if (!$field_data) {
                                continue;
                            }
                            
                            $field_id = isset($field_data['element_id']) ? $field_data['element_id'] : '';
                            $field_label = isset($field_data['field_label']) ? $field_data['field_label'] : '';
                            $field_type = isset($field_data['type']) ? $field_data['type'] : '';
                            
                            // Skip hidden fields and internal fields
                            if ($field_type === 'hidden' || 
                                (isset($field_data['visibility']) && $field_data['visibility'] === 'hidden') ||
                                $this->is_internal_field($field_id, $field_type)) {
                                continue;
                            }
                            
                            if ($field_id && $field_label) {
                                $field_labels[$field_id] = $field_label;
                                $visible_fields[$field_id] = true;
                                
                                // Handle name field sub-components
                                if ($field_type === 'name') {
                                    if (isset($field_data['fname']) && $field_data['fname']) {
                                        $fname_id = $field_id . '-first-name';
                                        $field_labels[$fname_id] = isset($field_data['fname_label']) ? $field_data['fname_label'] : $field_label . ' (First Name)';
                                        $visible_fields[$fname_id] = true;
                                    }
                                    if (isset($field_data['lname']) && $field_data['lname']) {
                                        $lname_id = $field_id . '-last-name';
                                        $field_labels[$lname_id] = isset($field_data['lname_label']) ? $field_data['lname_label'] : $field_label . ' (Last Name)';
                                        $visible_fields[$lname_id] = true;
                                    }
                                    if (isset($field_data['mname']) && $field_data['mname']) {
                                        $mname_id = $field_id . '-middle-name';
                                        $field_labels[$mname_id] = isset($field_data['mname_label']) ? $field_data['mname_label'] : $field_label . ' (Middle Name)';
                                        $visible_fields[$mname_id] = true;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Continue without field labels from form
                }
            }

            // Format the submission data
            $submission_data = array(
                'form_name' => $form_name,
                'date_created' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->date_created)),
                'fields' => array()
            );

            // Get field data from meta table
            $meta_data = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM $meta_table WHERE entry_id = %d",
                $submission_id
            ));

            if ($meta_data) {
                foreach ($meta_data as $meta) {
                    $field_value = maybe_unserialize($meta->meta_value);
                    
                    // Skip empty values
                    if (empty($field_value)) {
                        continue;
                    }
                    
                    // Handle different field value structures
                    if (is_array($field_value)) {
                        if (isset($field_value['value'])) {
                            $field_value = $field_value['value'];
                        }
                        if (is_array($field_value)) {
                            // Convert array elements to strings, handling objects
                            $string_values = array();
                            foreach ($field_value as $value) {
                                if (is_object($value)) {
                                    // Convert object to string representation
                                    if (isset($value->value)) {
                                        $string_values[] = $value->value;
                                    } elseif (isset($value->label)) {
                                        $string_values[] = $value->label;
                                    } else {
                                        $string_values[] = json_encode($value);
                                    }
                                } elseif (is_array($value)) {
                                    $string_values[] = implode(' ', $value);
                                } else {
                                    $string_values[] = (string) $value;
                                }
                            }
                            $field_value = implode(', ', $string_values);
                        }
                    } elseif (is_object($field_value)) {
                        // Handle object values
                        if (isset($field_value->value)) {
                            $field_value = $field_value->value;
                        } elseif (isset($field_value->label)) {
                            $field_value = $field_value->label;
                        } else {
                            $field_value = json_encode($field_value);
                        }
                    }
                    
                    // Skip fields that are not visible or are internal
                    // If we have form field data, only show fields that are explicitly marked as visible
                    // Otherwise, fall back to checking if the field is internal
                    if (!empty($visible_fields)) {
                        // We have form field data, so only show fields that are marked as visible
                        if (!isset($visible_fields[$meta->meta_key])) {
                            continue;
                        }
                    } else {
                        // No form field data available, fall back to internal field check
                        if ($this->is_internal_field($meta->meta_key)) {
                            continue;
                        }
                    }
                    
                    // Get field label - first try from form definition, then fallback
                    $field_label = '';
                    if (isset($field_labels[$meta->meta_key])) {
                        $field_label = $field_labels[$meta->meta_key];
                    } else {
                        // Only convert to label if it's not an internal field
                        if (!$this->is_internal_field($meta->meta_key)) {
                            $field_label = $this->convert_forminator_field_key_to_label($meta->meta_key);
                        }
                    }
                    
                    if (!empty($field_value) && !empty($field_label)) {
                        $submission_data['fields'][$field_label] = $field_value;
                    }
                }
            }

            return $submission_data;

        } catch (Exception $e) {
            // Log error if needed
            error_log('LCD People Frontend: Error fetching Forminator submission data: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a field is internal/hidden and should not be displayed
     */
    private function is_internal_field($field_key, $field_type = '') {
        // List of internal field patterns that should be hidden
        $internal_patterns = array(
            '/^_/',                           // Fields starting with underscore
            '/^entry_id$/',                   // Entry ID
            '/^form_id$/',                    // Form ID
            '/^date_created$/',               // Date created
            '/^date_updated$/',               // Date updated
            '/^ip$/',                         // IP address
            '/^user_agent$/',                 // User agent
            '/^referer$/',                    // Referer
            '/^submission_id$/',              // Submission ID
            '/^forminator_/',                 // Forminator internal fields
            '/^_forminator_/',                // Forminator internal fields with underscore
            '/^honeypot/',                    // Honeypot fields
            '/^captcha/',                     // Captcha fields
            '/^recaptcha/',                   // reCAPTCHA fields
            '/^hcaptcha/',                    // hCaptcha fields
            '/^csrf/',                        // CSRF tokens
            '/^nonce/',                       // Nonce fields
            '/^token/',                       // Token fields
            '/^_wp_/',                        // WordPress internal fields
            '/^wp_/',                         // WordPress fields
            '/^admin_/',                      // Admin fields
            '/^debug_/',                      // Debug fields
            '/^test_/',                       // Test fields
            '/^temp_/',                       // Temporary fields
            '/^tmp_/',                        // Temporary fields
            '/^system_/',                     // System fields
            '/^internal_/',                   // Internal fields
            '/^meta_/',                       // Meta fields
            '/^tracking_/',                   // Tracking fields
            '/^analytics_/',                  // Analytics fields
            '/^utm_/',                        // UTM parameters
            '/^gclid$/',                      // Google Click ID
            '/^fbclid$/',                     // Facebook Click ID
            '/^source$/',                     // Source tracking
            '/^medium$/',                     // Medium tracking
            '/^campaign$/',                   // Campaign tracking
            '/^referrer$/',                   // Referrer tracking
            '/^landing_page$/',               // Landing page tracking
            '/^session_/',                    // Session data
            '/^browser_/',                    // Browser data
            '/^device_/',                     // Device data
            '/^location_/',                   // Location data
            '/^timestamp$/',                  // Timestamp fields
            '/^created_at$/',                 // Created timestamp
            '/^updated_at$/',                 // Updated timestamp
            '/^status$/',                     // Status fields (unless specifically visible)
            '/^workflow_/',                   // Workflow fields
            '/^automation_/',                 // Automation fields
            '/^hidden-\d+$/',                 // Forminator hidden fields pattern
            '/^field-\d+-hidden$/',           // Alternative hidden field pattern
        );
        
        // Check field type
        if ($field_type === 'hidden' || $field_type === 'captcha' || $field_type === 'honeypot') {
            return true;
        }
        
        // Check field key against patterns
        foreach ($internal_patterns as $pattern) {
            if (preg_match($pattern, $field_key)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Convert Forminator field key to readable label
     */
    private function convert_forminator_field_key_to_label($field_key) {
        // Handle specific Forminator field patterns based on webhook data structure
        if (preg_match('/^name-\d+-first-name$/', $field_key)) {
            return 'First Name';
        } elseif (preg_match('/^name-\d+-last-name$/', $field_key)) {
            return 'Last Name';
        } elseif (preg_match('/^name-\d+-middle-name$/', $field_key)) {
            return 'Middle Name';
        } elseif (preg_match('/^email-\d+$/', $field_key)) {
            return 'Email Address';
        } elseif (preg_match('/^phone-\d+$/', $field_key)) {
            return 'Phone Number';
        } elseif (preg_match('/^address-\d+$/', $field_key)) {
            return 'Address';
        } elseif (preg_match('/^text-\d+$/', $field_key)) {
            return 'Text Field';
        } elseif (preg_match('/^textarea-\d+$/', $field_key)) {
            return 'Text Area';
        } elseif (preg_match('/^select-\d+$/', $field_key)) {
            return 'Select Field';
        } elseif (preg_match('/^radio-\d+$/', $field_key)) {
            return 'Radio Field';
        } elseif (preg_match('/^checkbox-\d+$/', $field_key)) {
            return 'Checkbox Field';
        } elseif (preg_match('/^date-\d+$/', $field_key)) {
            return 'Date Field';
        } elseif (preg_match('/^time-\d+$/', $field_key)) {
            return 'Time Field';
        } elseif (preg_match('/^number-\d+$/', $field_key)) {
            return 'Number Field';
        } else {
            // Convert field key to a more readable format
            $label = str_replace(array('-', '_'), ' ', $field_key);
            $label = preg_replace('/\d+/', '', $label); // Remove numbers
            $label = trim($label);
            return ucwords($label);
        }
    }
}

// Initialize the frontend class
function LCD_People_Frontend_Init() {
    return LCD_People_Frontend::get_instance();
} 