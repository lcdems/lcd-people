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
        
        return $template;
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only load on profile pages
        $current_post = get_post();
        if (!is_page_template('template-member-profile.php') && 
            (!$current_post || !has_shortcode($current_post->post_content, 'lcd_member_profile'))) {
            return;
        }
        
        wp_enqueue_style(
            'lcd-people-frontend',
            plugins_url('/assets/css/frontend.css', dirname(__FILE__)),
            array(),
            '1.0.0'
        );
        
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
        
        // Check if LCD Events plugin is active
        // Include the plugin.php file to access is_plugin_active() function
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $events_plugin_active = is_plugin_active('lcd-events/lcd-events.php') || function_exists('lcd_register_events_post_type');
        
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
                    <?php if ($events_plugin_active): ?>
                        <button class="lcd-tab-button" 
                                data-tab="volunteering"
                                role="tab"
                                aria-controls="volunteering-tab"
                                aria-selected="false"
                                id="volunteering-tab-button">
                            <?php _e('Volunteering Info', 'lcd-people'); ?>
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Membership Tab Content -->
                <div class="lcd-tab-content active" 
                     id="membership-tab"
                     role="tabpanel"
                     aria-labelledby="membership-tab-button">
                    <?php echo $this->render_membership_tab($membership_status, $membership_type, $is_sustaining, $start_date, $end_date, $first_name, $last_name, $email, $phone, $address, $roles, $precincts); ?>
                </div>
                
                <!-- Volunteering Tab Content -->
                <?php if ($events_plugin_active): ?>
                    <div class="lcd-tab-content" 
                         id="volunteering-tab"
                         role="tabpanel"
                         aria-labelledby="volunteering-tab-button">
                        <?php echo $this->render_volunteering_tab($person_id, $has_person_record); ?>
                    </div>
                <?php endif; ?>
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
     * Render the volunteering tab content
     */
    private function render_volunteering_tab($person_id, $has_person_record) {
        ob_start();
        
        // Get volunteer data
        $upcoming_shifts = array();
        $past_shifts = array();
        $volunteer_submission_data = null;
        
        if ($has_person_record) {
            $person_email = get_post_meta($person_id, '_lcd_person_email', true);
            $all_shifts = $this->get_person_volunteer_shifts($person_id, $person_email);
            
            // Separate upcoming and past shifts
            $today = current_time('Y-m-d');
            foreach ($all_shifts as $shift) {
                $shift_date = '';
                if (!empty($shift['shift_date'])) {
                    $shift_date = $shift['shift_date'];
                } elseif (!empty($shift['event_date'])) {
                    $shift_date = $shift['event_date'];
                }
                
                if (!empty($shift_date) && $shift_date >= $today) {
                    $upcoming_shifts[] = $shift;
                } else {
                    $past_shifts[] = $shift;
                }
            }
            
            // Get volunteer interest form submission data
            $latest_submission_id = get_post_meta($person_id, '_lcd_person_latest_volunteer_submission_id', true);
            if (!empty($latest_submission_id)) {
                $volunteer_submission_data = $this->get_volunteer_submission_data($latest_submission_id);
            }
        }
        ?>
        <div class="lcd-member-profile-section lcd-volunteer-info">
            <h3><?php _e('Volunteering Information', 'lcd-people'); ?></h3>
            <p>If you need assistance with your volunteer record, please contact us at <a href="mailto:volunteer@lewiscountydemocrats.org">volunteer@lewiscountydemocrats.org</a>.</p>
            <?php if (!$has_person_record): ?>
                <div class="lcd-volunteer-no-record">
                    <p><em><?php _e('No volunteer record found.', 'lcd-people'); ?> <a href="<?php echo get_bloginfo('url'); ?>/volunteer"><?php _e('Sign up here', 'lcd-people'); ?></a> <?php _e('to get involved!', 'lcd-people'); ?></em></p>
                </div>
            <?php else: ?>
                
                <!-- Volunteer Interest Form Data -->
                <div class="lcd-volunteer-interests">
                    <h4><?php _e('Volunteer Interests', 'lcd-people'); ?></h4>
                    <?php if ($volunteer_submission_data): ?>
                        <div class="lcd-volunteer-submission-info">
                            <p class="submission-date">
                                <strong><?php _e('Last updated:', 'lcd-people'); ?></strong>
                                <?php echo esc_html($volunteer_submission_data['date_created']); ?>
                            </p>
                            <?php if (!empty($volunteer_submission_data['form_name'])): ?>
                                <p class="form-name">
                                    <strong><?php _e('Form:', 'lcd-people'); ?></strong>
                                    <?php echo esc_html($volunteer_submission_data['form_name']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($volunteer_submission_data['fields'])): ?>
                            <div class="lcd-volunteer-interests-data">
                                <?php foreach ($volunteer_submission_data['fields'] as $field_name => $field_value): ?>
                                    <div class="volunteer-interest-item">
                                        <strong><?php echo esc_html($field_name); ?>:</strong>
                                        <span><?php echo esc_html($field_value); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="lcd-volunteer-no-interests">
                            <p><?php _e('No volunteer interests on file.', 'lcd-people'); ?></p>
                            <p>
                                <a href="<?php echo get_bloginfo('url'); ?>/volunteer" class="lcd-volunteer-signup-link">
                                    <?php _e('Fill out our volunteer interest form', 'lcd-people'); ?>
                                </a> 
                                <?php _e('to let us know how you\'d like to help!', 'lcd-people'); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Upcoming Volunteer Shifts -->
                <div class="lcd-volunteer-upcoming-shifts">
                    <h4><?php _e('Upcoming Shifts', 'lcd-people'); ?></h4>
                    <?php if (empty($upcoming_shifts)): ?>
                        <p class="lcd-volunteer-placeholder-text">
                            <em><?php _e('No upcoming volunteer shifts found.', 'lcd-people'); ?></em>
                        </p>
                    <?php else: ?>
                        <div class="lcd-volunteer-shifts-list">
                            <?php foreach ($upcoming_shifts as $shift): ?>
                                <div class="lcd-shift-item">
                                    <div class="lcd-shift-header">
                                        <h5><?php echo esc_html($shift['event_title']); ?></h5>
                                        <?php if (!empty($shift['formatted_event_date'])): ?>
                                            <span class="lcd-shift-date">
                                                <?php echo esc_html($shift['formatted_event_date']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lcd-shift-details">
                                        <div class="lcd-shift-info">
                                            <strong><?php _e('Shift:', 'lcd-people'); ?></strong> 
                                            <?php echo esc_html($shift['shift_title']); ?>
                                        </div>
                                        <?php if (!empty($shift['shift_date_time'])): ?>
                                            <div class="lcd-shift-time">
                                                <strong><?php _e('Time:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['shift_date_time']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($shift['event_location'])): ?>
                                            <div class="lcd-shift-location">
                                                <strong><?php _e('Location:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['event_location']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($shift['volunteer_notes'])): ?>
                                            <div class="lcd-shift-notes">
                                                <strong><?php _e('Notes:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['volunteer_notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="lcd-shift-status">
                                            <strong><?php _e('Status:', 'lcd-people'); ?></strong>
                                            <span class="status-<?php echo esc_attr($shift['status']); ?>">
                                                <?php echo esc_html(ucfirst($shift['status'])); ?>
                                            </span>
                                        </div>
                                        <div class="lcd-shift-signup-date">
                                            <strong><?php _e('Signed up:', 'lcd-people'); ?></strong>
                                            <?php echo esc_html($shift['formatted_signup_date']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Past Volunteer Activities -->
                <div class="lcd-volunteer-past-shifts">
                    <h4><?php _e('Past Volunteer Activities', 'lcd-people'); ?></h4>
                    <?php if (empty($past_shifts)): ?>
                        <p class="lcd-volunteer-placeholder-text">
                            <em><?php _e('No past volunteer activities found.', 'lcd-people'); ?></em>
                        </p>
                    <?php else: ?>
                        <div class="lcd-volunteer-shifts-list">
                            <?php foreach (array_slice($past_shifts, 0, 10) as $shift): // Show only last 10 ?>
                                <div class="lcd-shift-item past-shift">
                                    <div class="lcd-shift-header">
                                        <h5><?php echo esc_html($shift['event_title']); ?></h5>
                                        <?php if (!empty($shift['formatted_event_date'])): ?>
                                            <span class="lcd-shift-date">
                                                <?php echo esc_html($shift['formatted_event_date']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lcd-shift-details">
                                        <div class="lcd-shift-info">
                                            <strong><?php _e('Shift:', 'lcd-people'); ?></strong> 
                                            <?php echo esc_html($shift['shift_title']); ?>
                                        </div>
                                        <?php if (!empty($shift['shift_date_time'])): ?>
                                            <div class="lcd-shift-time">
                                                <strong><?php _e('Time:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['shift_date_time']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($shift['event_location'])): ?>
                                            <div class="lcd-shift-location">
                                                <strong><?php _e('Location:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['event_location']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="lcd-shift-status">
                                            <strong><?php _e('Status:', 'lcd-people'); ?></strong>
                                            <span class="status-<?php echo esc_attr($shift['status']); ?>">
                                                <?php echo esc_html(ucfirst($shift['status'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($past_shifts) > 10): ?>
                                <p class="lcd-volunteer-more-activities">
                                    <em><?php printf(__('Showing 10 of %d past activities.', 'lcd-people'), count($past_shifts)); ?></em>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get volunteer shifts for a person (both upcoming and past)
     */
    private function get_person_volunteer_shifts($person_id, $person_email = '') {
        if (!function_exists('lcd_get_volunteer_signups')) {
            return array();
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'lcd_volunteer_signups';

        // Get signups for this person by person_id or email
        $where_conditions = array();
        $where_values = array();

        if (!empty($person_id)) {
            $where_conditions[] = 'person_id = %d';
            $where_values[] = $person_id;
        }

        if (!empty($person_email)) {
            $where_conditions[] = 'volunteer_email = %s';
            $where_values[] = $person_email;
        }

        if (empty($where_conditions)) {
            return array();
        }

        $where_clause = '(' . implode(' OR ', $where_conditions) . ')';

        $query = "
            SELECT vs.*, p.post_title as event_title
            FROM {$table_name} vs
            LEFT JOIN {$wpdb->posts} p ON vs.event_id = p.ID
            WHERE {$where_clause}
            AND p.post_status = 'publish'
            ORDER BY vs.signup_date DESC
        ";

        $signups = $wpdb->get_results($wpdb->prepare($query, ...$where_values));

        if (empty($signups)) {
            return array();
        }

        $shifts = array();

        foreach ($signups as $signup) {
            // Get event meta data
            $event_date = get_post_meta($signup->event_id, '_event_date', true);
            $event_location = get_post_meta($signup->event_id, '_event_location', true);

            // Get volunteer shifts data to get shift details
            $volunteer_shifts = get_post_meta($signup->event_id, '_volunteer_shifts', true);
            $shift_details = array();
            
            if (is_array($volunteer_shifts) && isset($volunteer_shifts[$signup->shift_index])) {
                $shift_details = $volunteer_shifts[$signup->shift_index];
            }

            // Format dates and times
            $formatted_event_date = '';
            if (!empty($event_date)) {
                $formatted_event_date = date_i18n(get_option('date_format'), strtotime($event_date));
            }

            $shift_date_time = '';
            $shift_date = '';
            if (!empty($shift_details['date'])) {
                $shift_date = $shift_details['date'];
                $formatted_shift_date = date_i18n(get_option('date_format'), strtotime($shift_details['date']));
                $time_parts = array();
                
                if (!empty($shift_details['start_time'])) {
                    $time_parts[] = date_i18n(get_option('time_format'), strtotime($shift_details['date'] . ' ' . $shift_details['start_time']));
                }
                if (!empty($shift_details['end_time'])) {
                    $time_parts[] = date_i18n(get_option('time_format'), strtotime($shift_details['date'] . ' ' . $shift_details['end_time']));
                }
                
                $shift_date_time = $formatted_shift_date;
                if (!empty($time_parts)) {
                    $shift_date_time .= ' ' . implode(' - ', $time_parts);
                }
            }

            $formatted_signup_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($signup->signup_date));

            $shifts[] = array(
                'event_id' => $signup->event_id,
                'event_title' => $signup->event_title,
                'event_location' => $event_location,
                'event_date' => $event_date,
                'shift_title' => $signup->shift_title,
                'shift_date' => $shift_date,
                'shift_date_time' => $shift_date_time,
                'volunteer_notes' => $signup->volunteer_notes,
                'status' => $signup->status,
                'formatted_event_date' => $formatted_event_date,
                'formatted_signup_date' => $formatted_signup_date,
                'signup_date' => $signup->signup_date
            );
        }

        return $shifts;
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

            // Get field labels from the form definition
            $field_labels = array();
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
                            
                            if ($field_id && $field_label) {
                                $field_labels[$field_id] = $field_label;
                                
                                // Handle name field sub-components
                                if (isset($field_data['type']) && $field_data['type'] === 'name') {
                                    if (isset($field_data['fname']) && $field_data['fname']) {
                                        $field_labels[$field_id . '-first-name'] = isset($field_data['fname_label']) ? $field_data['fname_label'] : $field_label . ' (First Name)';
                                    }
                                    if (isset($field_data['lname']) && $field_data['lname']) {
                                        $field_labels[$field_id . '-last-name'] = isset($field_data['lname_label']) ? $field_data['lname_label'] : $field_label . ' (Last Name)';
                                    }
                                    if (isset($field_data['mname']) && $field_data['mname']) {
                                        $field_labels[$field_id . '-middle-name'] = isset($field_data['mname_label']) ? $field_data['mname_label'] : $field_label . ' (Middle Name)';
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
                    
                    // Get field label - first try from form definition, then fallback
                    $field_label = '';
                    if (isset($field_labels[$meta->meta_key])) {
                        $field_label = $field_labels[$meta->meta_key];
                    } else {
                        $field_label = $this->convert_forminator_field_key_to_label($meta->meta_key);
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