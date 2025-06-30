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
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add modal integration
        add_action('wp_footer', array($this, 'add_modal_integration'));
    }
    
    /**
     * Enqueue scripts for opt-in functionality
     */
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        
        wp_localize_script('jquery', 'lcdOptinVars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lcd_optin_nonce'),
            'strings' => array(
                'processing' => __('Processing...', 'lcd-people'),
                'error' => __('An error occurred. Please try again.', 'lcd-people'),
                'invalid_email' => __('Please enter a valid email address.', 'lcd-people'),
                'required_fields' => __('Please fill in all required fields.', 'lcd-people'),
                'success' => __('Thank you for joining our list!', 'lcd-people')
            )
        ));
        
        // Add inline script for opt-in functionality
        wp_add_inline_script('jquery', $this->get_optin_javascript());
    }
    
    /**
     * Render opt-in form shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Form HTML
     */
    public function render_optin_form_shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'modal' => 'false'
        ), $atts);
        
        return $this->render_optin_form($atts['modal'] === 'true');
    }
    
    /**
     * Render the opt-in form HTML
     * 
     * @param bool $is_modal Whether this is for modal display
     * @return string Form HTML
     */
    public function render_optin_form($is_modal = false) {
        $settings = $this->get_optin_settings();
        $available_groups = $this->get_available_groups();
        
        if (empty($available_groups)) {
            return '<div class="lcd-optin-not-configured"><p>' . __('Opt-in form is not properly configured. Please configure groups in the Sender.net settings.', 'lcd-people') . '</p></div>';
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
        
        if (empty($groups)) {
            wp_send_json_error(array(
                'message' => __('Please select at least one interest.', 'lcd-people')
            ));
        }
        
        // Store in session for next step
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
            'message' => __('Information saved. Please complete the next step.', 'lcd-people'),
            'session_key' => $session_key
        ));
    }
    
    /**
     * Handle final submission (with or without SMS)
     */
    public function ajax_submit_final() {
        check_ajax_referer('lcd_optin_nonce', 'nonce');
        
        $session_key = sanitize_text_field($_POST['session_key'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $sms_consent = !empty($_POST['sms_consent']);
        
        if (empty($session_key)) {
            wp_send_json_error(array(
                'message' => __('Session expired. Please start over.', 'lcd-people')
            ));
        }
        
        // Retrieve session data
        $session_data = get_transient($session_key);
        if (!$session_data) {
            wp_send_json_error(array(
                'message' => __('Session expired. Please start over.', 'lcd-people')
            ));
        }
        
        // Clean up session
        delete_transient($session_key);
        
        // Validate phone if SMS consent given
        if ($sms_consent && empty($phone)) {
            wp_send_json_error(array(
                'message' => __('Phone number is required for SMS updates.', 'lcd-people')
            ));
        }
        
        // Format phone number
        if (!empty($phone)) {
            $phone = $this->format_phone_number($phone);
        }
        
        // Sync to Sender.net
        $result = $this->sync_to_sender(
            $session_data['email'],
            $session_data['first_name'],
            $session_data['last_name'],
            $session_data['groups'],
            $sms_consent ? $phone : null
        );
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $sms_consent 
                    ? __('Thank you! You\'ve been added to our email and SMS lists.', 'lcd-people')
                    : __('Thank you! You\'ve been added to our email list.', 'lcd-people')
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message'] ?? __('An error occurred. Please try again.', 'lcd-people')
            ));
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
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof LCDModal !== 'undefined') {
                // Store the opt-in content for modal use
                window.lcdOptinModalContent = {
                    title: <?php echo $modal_title_js; ?>,
                    content: <?php echo $modal_content_js; ?>,
                    size: 'medium',
                    className: 'lcd-optin-modal-wrapper'
                };
                
                // Listen for modal triggers with high priority (capture phase)
                document.addEventListener('click', function(e) {
                    if (e.target.getAttribute('data-modal') === 'optin-form' || 
                        e.target.closest('[data-modal="optin-form"]')) {
                        e.preventDefault();
                        e.stopPropagation(); // Prevent other modal handlers from firing
                        e.stopImmediatePropagation(); // Stop all other listeners on this element
                        
                        // Open modal with opt-in content
                        LCDModal.open({
                            title: window.lcdOptinModalContent.title,
                            content: window.lcdOptinModalContent.content,
                            size: 'medium',
                            className: 'lcd-optin-modal-wrapper',
                            showCloseButton: true
                        });
                    }
                }, true); // Use capture phase to run before other handlers
                
                console.log('LCD Opt-in: Modal integration ready. Use data-modal="optin-form" to trigger.');
            } else {
                console.warn('LCD Opt-in: LCDModal not found. Modal integration disabled.');
                // Fallback: Handle modal triggers manually
                document.addEventListener('click', function(e) {
                    if (e.target.getAttribute('data-modal') === 'optin-form') {
                        e.preventDefault();
                        alert('Modal system not available. Please use the shortcode [lcd_optin_form] instead.');
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    /**
     * Get JavaScript for opt-in functionality
     * 
     * @return string
     */
    private function get_optin_javascript() {
        return "
        window.lcdOptinForm = {
            currentStep: 'email',
            sessionKey: null,
            
            init: function() {
                this.bindEvents();
            },
            
            bindEvents: function() {
                var self = this;
                
                // Email form submission
                jQuery(document).on('submit', '#lcd-optin-email-form', function(e) {
                    e.preventDefault();
                    self.submitEmailStep();
                });
                
                // SMS form submission
                jQuery(document).on('submit', '#lcd-optin-sms-form', function(e) {
                    e.preventDefault();
                    self.submitFinalStep(true);
                });
                
                // Skip SMS button
                jQuery(document).on('click', '#lcd-skip-sms-btn', function(e) {
                    e.preventDefault();
                    self.submitFinalStep(false);
                });
                
                // SMS consent checkbox
                jQuery(document).on('change', '#lcd-optin-sms-consent', function() {
                    var consentGiven = jQuery(this).is(':checked');
                    var phoneField = jQuery('#lcd-optin-phone');
                    var submitBtn = jQuery('#lcd-sms-optin-btn');
                    
                    if (consentGiven) {
                        phoneField.prop('required', true);
                        submitBtn.prop('disabled', false);
                    } else {
                        phoneField.prop('required', false);
                        submitBtn.prop('disabled', true);
                    }
                });
            },
            
            submitEmailStep: function() {
                var formData = {
                    action: 'lcd_optin_submit_email',
                    nonce: lcdOptinVars.nonce,
                    first_name: jQuery('#lcd-optin-first-name').val(),
                    last_name: jQuery('#lcd-optin-last-name').val(),
                    email: jQuery('#lcd-optin-email').val(),
                    groups: []
                };
                
                // Get selected groups
                jQuery('input[name=\"groups[]\"]:checked').each(function() {
                    formData.groups.push(jQuery(this).val());
                });
                
                // Ensure at least one group is selected
                if (formData.groups.length === 0) {
                    // Auto-select the first group if none are selected
                    var firstGroup = jQuery('input[name=\"groups[]\"]').first();
                    if (firstGroup.length) {
                        firstGroup.prop('checked', true);
                        formData.groups.push(firstGroup.val());
                    }
                }
                
                this.showLoading();
                
                var self = this;
                jQuery.post(lcdOptinVars.ajaxurl, formData)
                    .done(function(response) {
                        if (response.success) {
                            self.sessionKey = response.data.session_key;
                            self.showStep('sms');
                        } else {
                            self.showError(response.data.message || lcdOptinVars.strings.error);
                        }
                    })
                    .fail(function() {
                        self.showError(lcdOptinVars.strings.error);
                    });
            },
            
            submitFinalStep: function(includeSMS) {
                var formData = {
                    action: 'lcd_optin_submit_final',
                    nonce: lcdOptinVars.nonce,
                    session_key: this.sessionKey
                };
                
                if (includeSMS) {
                    formData.phone = jQuery('#lcd-optin-phone').val();
                    formData.sms_consent = jQuery('#lcd-optin-sms-consent').is(':checked') ? 1 : 0;
                }
                
                this.showLoading();
                
                var self = this;
                jQuery.post(lcdOptinVars.ajaxurl, formData)
                    .done(function(response) {
                        if (response.success) {
                            self.showSuccess(response.data.message);
                        } else {
                            self.showError(response.data.message || lcdOptinVars.strings.error);
                        }
                    })
                    .fail(function() {
                        self.showError(lcdOptinVars.strings.error);
                    });
            },
            
            showStep: function(step) {
                jQuery('.lcd-optin-step').hide();
                jQuery('#lcd-optin-step-' + step).show();
                jQuery('#lcd-optin-loading').hide();
                this.currentStep = step;
            },
            
            showLoading: function() {
                jQuery('.lcd-optin-step').hide();
                jQuery('#lcd-optin-error').hide();
                jQuery('#lcd-optin-loading').show();
            },
            
            showSuccess: function(message) {
                jQuery('#lcd-success-message').text(message);
                this.showStep('success');
                
                // Auto-close modal after 3 seconds if in modal
                if (typeof LCDModal !== 'undefined' && jQuery('.lcd-optin-modal').length) {
                    setTimeout(function() {
                        LCDModal.close();
                    }, 3000);
                }
            },
            
            showError: function(message) {
                jQuery('.lcd-optin-step').hide();
                jQuery('#lcd-optin-loading').hide();
                jQuery('#lcd-optin-error .error-message').text(message);
                jQuery('#lcd-optin-error').show();
            },
            
            hideError: function() {
                jQuery('#lcd-optin-error').hide();
                this.showStep(this.currentStep);
            }
        };
        
        jQuery(document).ready(function() {
            lcdOptinForm.init();
        });
        ";
    }
} 