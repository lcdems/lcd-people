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
        if (!is_page_template('template-member-profile.php') && !has_shortcode(get_post()->post_content, 'lcd_member_profile')) {
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
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize the frontend class
function LCD_People_Frontend_Init() {
    return LCD_People_Frontend::get_instance();
} 