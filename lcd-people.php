<?php
/**
 * Plugin Name: LCD People Manager
 * Plugin URI: 
 * Description: Manages people and their roles in the LCD organization
 * Version: 1.0.0
 * Author: LCD
 * License: GPL v2 or later
 * Text Domain: lcd-people
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/class-lcd-people-frontend.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-lcd-people-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-lcd-people-actblue-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-lcd-people-sender-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-lcd-people-optin-handler.php';
require_once plugin_dir_path(__FILE__) . 'admin/class-people-email-admin.php';

class LCD_People {
    private static $instance = null;
    private $settings;
    private $actblue_handler;
    private $sender_handler;
    private $optin_handler;
    private $email_admin;
    const USER_META_KEY = '_lcd_person_id';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize settings
        $this->settings = new LCD_People_Settings($this);
        
        // Initialize ActBlue handler
        $this->actblue_handler = new LCD_People_ActBlue_Handler($this);
        
        // Initialize Sender.net handler
        $this->sender_handler = new LCD_People_Sender_Handler($this);
        
        // Initialize Opt-in handler
        $this->optin_handler = new LCD_People_Optin_Handler($this);
        
        // Initialize Email Admin (only in admin)
        if (is_admin()) {
            $this->email_admin = LCD_People_Email_Admin::get_instance($this);
        }
        
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_taxonomies'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_box_data'));
        add_filter('enter_title_here', array($this, 'change_title_placeholder'));
        add_action('edit_form_after_title', array($this, 'add_name_notice'));
        add_action('wp_insert_post_data', array($this, 'modify_post_title'), 10, 2);
        
        // Add columns to admin list
        add_filter('manage_lcd_person_posts_columns', array($this, 'set_custom_columns'));
        add_action('manage_lcd_person_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
        add_filter('manage_edit-lcd_person_sortable_columns', array($this, 'set_sortable_columns'));

        // Add AJAX handlers for user search
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_lcd_search_users', array($this, 'ajax_search_users'));

        // Add AJAX handler for checking duplicate connections
        add_action('wp_ajax_lcd_check_user_connection', array($this, 'ajax_check_user_connection'));
        
        // Add AJAX handler for copying emails
        add_action('wp_ajax_lcd_get_filtered_emails', array($this, 'ajax_get_filtered_emails'));
        
        // Handle user deletion
        add_action('delete_user', array($this, 'handle_user_deletion'));
        
        // Handle post deletion
        add_action('before_delete_post', array($this, 'handle_post_deletion'));

        // Add user table column
        add_filter('manage_users_columns', array($this, 'add_user_column'));
        add_filter('manage_users_custom_column', array($this, 'render_user_column'), 10, 3);

        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'register_rest_endpoint'), 10);
        

        // Register cron job for membership status updates
        add_action('lcd_check_membership_statuses', array($this, 'check_membership_statuses'));
        
        // Schedule cron job if not already scheduled
        if (!wp_next_scheduled('lcd_check_membership_statuses')) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'lcd_check_membership_statuses');
        }

        // Add Sender.net sync hooks
        add_action('lcd_person_actblue_created', array($this, 'handle_person_sync'), 10, 1);
        add_action('lcd_person_actblue_updated', array($this, 'handle_person_sync'), 10, 1);
        add_action('lcd_member_status_changed', array($this, 'handle_person_sync'), 10, 1);
        add_action('save_post_lcd_person', array($this, 'handle_person_save'), 10, 3);

        

        // Add cancel membership AJAX handler
        add_action('wp_ajax_lcd_cancel_membership', array($this, 'ajax_cancel_membership'));
        
        // Add re-trigger welcome automation AJAX handler
        add_action('wp_ajax_lcd_retrigger_welcome', array($this, 'ajax_retrigger_welcome'));

        // Add switch primary member AJAX handler
        add_action('wp_ajax_lcd_switch_primary_member', array($this, 'ajax_switch_primary_member'));

        // Add Sync All to Sender AJAX handler
        add_action('wp_ajax_lcd_sync_all_to_sender', array($this, 'ajax_sync_all_to_sender'));

        // Remove default date filter and add custom filters
        add_filter('disable_months_dropdown', array($this, 'disable_months_dropdown'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'add_admin_filters'));
        add_filter('parse_query', array($this, 'handle_admin_filters'));
        
        // Add the "Copy Emails" button to the admin list page
        add_action('manage_posts_extra_tablenav', array($this, 'add_copy_emails_button'));

        // Add class to admin rows for duplicate primary highlighting
        add_filter('post_class', array($this, 'add_person_row_class'), 10, 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles')); // Ensure styles are enqueued

        // Handle user deletion
        add_action('delete_user', array($this, 'handle_user_deletion'));
        
        // Handle post deletion
        add_action('before_delete_post', array($this, 'handle_post_deletion'));
        
        // Add registration hooks
        add_action('user_register', array($this, 'handle_user_registration'));
        add_action('wp_login', array($this, 'handle_user_login_check'), 10, 2);
        
        // Add claim account AJAX handlers (both logged-in and non-logged-in users)
        add_action('wp_ajax_lcd_send_claim_verification_email', array($this, 'ajax_send_claim_verification_email'));
        add_action('wp_ajax_nopriv_lcd_send_claim_verification_email', array($this, 'ajax_send_claim_verification_email'));
        add_action('wp_ajax_lcd_verify_claim_token', array($this, 'ajax_verify_claim_token'));
        add_action('wp_ajax_nopriv_lcd_verify_claim_token', array($this, 'ajax_verify_claim_token'));
        add_action('wp_ajax_lcd_claim_create_account_with_token', array($this, 'ajax_claim_create_account_with_token'));
        add_action('wp_ajax_nopriv_lcd_claim_create_account_with_token', array($this, 'ajax_claim_create_account_with_token'));
        
        // Include fix duplicates utility
        if (file_exists(plugin_dir_path(__FILE__) . 'fix-duplicates.php')) {
            include_once plugin_dir_path(__FILE__) . 'fix-duplicates.php';
        }
    }

    public function enqueue_admin_scripts($hook) {
        global $post;
        global $typenow;

        // Enqueue on person edit screen AND settings pages
        if (($hook == 'post-new.php' || $hook == 'post.php') && isset($post) && $post->post_type === 'lcd_person' ||
            $hook == 'lcd_person_page_lcd-people-actblue-settings' ||
            $hook == 'lcd_person_page_lcd-people-sender-settings') {
            
            // Enqueue jQuery UI
            wp_enqueue_style('wp-jquery-ui-dialog');
            wp_enqueue_script('jquery-ui-dialog');

            // Enqueue Dashicons
            wp_enqueue_style('dashicons');

            // Enqueue our admin styles
            wp_enqueue_style(
                'lcd-people-admin',
                plugins_url('assets/css/admin.css', __FILE__),
                array(),
                '1.0.0'
            );

            // Enqueue volunteering meta box styles (only on person edit screens)
            if (($hook == 'post-new.php' || $hook == 'post.php') && isset($post) && $post->post_type === 'lcd_person') {
                wp_enqueue_style(
                    'lcd-people-volunteering-meta-box',
                    plugins_url('assets/css/volunteering-meta-box.css', __FILE__),
                    array(),
                    '1.0.0'
                );
            }

            // Enqueue Select2 for all pages (needed for settings pages too)
            wp_enqueue_style(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                array(),
                '4.1.0-rc.0'
            );
            
            wp_enqueue_script(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
                array('jquery'),
                '4.1.0-rc.0',
                true
            );

            // Enqueue our admin script with select2 and modal system as dependencies
            wp_enqueue_script(
                'lcd-people-admin',
                plugins_url('assets/js/admin.js', __FILE__),
                array('jquery', 'jquery-ui-dialog', 'select2', 'lcd-modal-system-admin'),
                '1.0.0',
                true
            );

            // Enqueue volunteering meta box script (only on person edit screens)
            if (($hook == 'post-new.php' || $hook == 'post.php') && isset($post) && $post->post_type === 'lcd_person') {
                wp_enqueue_script(
                    'lcd-people-volunteering-meta-box',
                    plugins_url('assets/js/volunteering-meta-box.js', __FILE__),
                    array('jquery'),
                    '1.0.0',
                    true
                );

                wp_localize_script('lcd-people-volunteering-meta-box', 'lcdPeopleVolunteering', array(
                    'viewText' => __('View Submission Data', 'lcd-people'),
                    'hideText' => __('Hide Submission Data', 'lcd-people')
                ));
            }

            wp_localize_script('lcd-people-admin', 'lcdPeople', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('lcd_people_user_search'),
                'strings' => array(
                    'retriggerSuccess' => __('Welcome automation re-trigger attempted successfully.', 'lcd-people'),
                    'retriggerError' => __('Failed to re-trigger welcome automation:', 'lcd-people'),
                    'confirmRetrigger' => __('Are you sure you want to re-trigger the welcome automation?', 'lcd-people'),
                    'confirmMakePrimary' => __('Are you sure you want to make this person primary? This will demote the current primary member.', 'lcd-people'),
                    'switchPrimarySuccess' => __('Primary member switched successfully. Reloading page...', 'lcd-people'),
                    'switchPrimaryError' => __('Failed to switch primary member:', 'lcd-people'),
                    'makePrimary' => __('Make this person primary', 'lcd-people'),
                    'switchingPrimary' => __('Switching', 'lcd-people'),
                    'ajaxRequestFailed' => __('An error occurred while processing the request.', 'lcd-people'),
                )
            ));
        }
        
        // Enqueue on the lcd_person list page
        if ($hook === 'edit.php' && $typenow === 'lcd_person') {
            wp_enqueue_script(
                'lcd-people-list-admin',
                plugins_url('assets/js/admin-list.js', __FILE__),
                array('jquery'),
                '1.0.0',
                true
            );
            
            wp_localize_script('lcd-people-list-admin', 'lcdPeopleAdmin', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('lcd_people_admin'), // Nonce for admin list actions
                'strings' => array(
                    'copySuccess' => __('Emails copied to clipboard!', 'lcd-people'),
                    'copyError' => __('Failed to copy emails:', 'lcd-people'),
                    'noEmails' => __('No email addresses found for the current filters.', 'lcd-people'),
                    'confirmSyncAll' => __('Are you sure you want to attempt syncing ALL people to Sender.net? This may take a while and will only sync primary members with email addresses.', 'lcd-people'),
                    'syncingAll' => __('Syncing all people... (including backfill primary check)... please wait.', 'lcd-people'), // Updated processing text
                    'syncAllError' => __('An error occurred during the sync process:', 'lcd-people'),
                    'syncErrors' => __('Sync Errors:', 'lcd-people'),
                    'ajaxRequestFailed' => __('AJAX request failed.', 'lcd-people'),
                )
            ));
        }
    }

    // Enqueue specific styles for admin list table
    public function enqueue_admin_styles($hook) {
         // Enqueue on the lcd_person list page
        if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'lcd_person') {
            wp_enqueue_style(
                'lcd-people-admin-list-styles', // Use a different handle than the JS
                plugins_url('assets/css/admin-list.css', __FILE__),
                array(),
                '1.0.1' // Incremented version
            );
        }
    }

    public function ajax_search_users() {
        check_ajax_referer('lcd_people_user_search', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(-1);
        }

        $search = sanitize_text_field($_GET['q']);

        $users = get_users(array(
            'search' => "*{$search}*",
            'search_columns' => array('user_login', 'user_email', 'user_nicename', 'display_name'),
            'number' => 10,
        ));

        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'id' => $user->ID,
                'text' => sprintf(
                    '%s (%s)',
                    $user->display_name,
                    $user->user_email
                )
            );
        }

        wp_send_json($results);
    }

    public function register_taxonomies() {
        // Register Role taxonomy
        register_taxonomy('lcd_role', 'lcd_person', array(
            'labels' => array(
                'name' => __('Roles', 'lcd-people'),
                'singular_name' => __('Role', 'lcd-people'),
                'search_items' => __('Search Roles', 'lcd-people'),
                'all_items' => __('All Roles', 'lcd-people'),
                'edit_item' => __('Edit Role', 'lcd-people'),
                'update_item' => __('Update Role', 'lcd-people'),
                'add_new_item' => __('Add New Role', 'lcd-people'),
                'new_item_name' => __('New Role Name', 'lcd-people'),
                'menu_name' => __('Roles', 'lcd-people'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'role'),
        ));

        // Register Precinct taxonomy
        register_taxonomy('lcd_precinct', 'lcd_person', array(
            'labels' => array(
                'name' => __('Precincts', 'lcd-people'),
                'singular_name' => __('Precinct', 'lcd-people'),
                'search_items' => __('Search Precincts', 'lcd-people'),
                'all_items' => __('All Precincts', 'lcd-people'),
                'edit_item' => __('Edit Precinct', 'lcd-people'),
                'update_item' => __('Update Precinct', 'lcd-people'),
                'add_new_item' => __('Add New Precinct', 'lcd-people'),
                'new_item_name' => __('New Precinct Name', 'lcd-people'),
                'menu_name' => __('Precincts', 'lcd-people'),
            ),
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'precinct'),
        ));
    }

    public function register_post_type() {
        $labels = array(
            'name'               => __('People', 'lcd-people'),
            'singular_name'      => __('Person', 'lcd-people'),
            'menu_name'         => __('People', 'lcd-people'),
            'add_new'           => __('Add New', 'lcd-people'),
            'add_new_item'      => __('Add New Person', 'lcd-people'),
            'edit_item'         => __('Edit Person', 'lcd-people'),
            'new_item'          => __('New Person', 'lcd-people'),
            'view_item'         => __('View Person', 'lcd-people'),
            'search_items'      => __('Search People', 'lcd-people'),
            'not_found'         => __('No people found', 'lcd-people'),
            'not_found_in_trash'=> __('No people found in Trash', 'lcd-people'),
        );

        $args = array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'people'),
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_position'      => null,
            'supports'           => array('thumbnail'),
            'menu_icon'          => 'dashicons-groups'
        );

        register_post_type('lcd_person', $args);
    }

    public function set_custom_columns($columns) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Name', 'lcd-people');
        $new_columns['connected_user'] = __('WordPress User', 'lcd-people');
        $new_columns['email'] = __('Email', 'lcd-people');
        $new_columns['phone'] = __('Phone', 'lcd-people');
        $new_columns['membership_status'] = __('Status', 'lcd-people');
        $new_columns['membership_type'] = __('Type', 'lcd-people');
        $new_columns['sustaining'] = __('Sustaining', 'lcd-people');
        $new_columns['start_date'] = __('Start Date', 'lcd-people');
        $new_columns['end_date'] = __('End Date', 'lcd-people');
        $new_columns['taxonomy-lcd_role'] = __('Roles', 'lcd-people');
        $new_columns['taxonomy-lcd_precinct'] = __('Precinct', 'lcd-people');
        
        return $new_columns;
    }

    public function render_custom_columns($column, $post_id) {
        switch ($column) {
            case 'connected_user':
                $user_id = get_post_meta($post_id, '_lcd_person_user_id', true);
                if ($user_id) {
                    $user = get_user_by('id', $user_id);
                    if ($user) {
                        echo sprintf('<a href="%s">%s</a>',
                            esc_url(get_edit_user_link($user_id)),
                            esc_html($user->display_name)
                        );
                    }
                }
                break;
            case 'email':
                echo esc_html(get_post_meta($post_id, '_lcd_person_email', true));
                break;
            case 'phone':
                echo esc_html(get_post_meta($post_id, '_lcd_person_phone', true));
                break;
            case 'membership_status':
                $status = get_post_meta($post_id, '_lcd_person_membership_status', true);
                echo esc_html(ucfirst($status));
                break;
            case 'membership_type':
                $type = get_post_meta($post_id, '_lcd_person_membership_type', true);
                echo esc_html(ucfirst($type));
                break;
            case 'sustaining':
                $is_sustaining = get_post_meta($post_id, '_lcd_person_is_sustaining', true);
                echo $is_sustaining ? '✓' : '—';
                break;
            case 'start_date':
                $date = get_post_meta($post_id, '_lcd_person_start_date', true);
                echo $date ? date('Y-m-d', strtotime($date)) : '';
                break;
            case 'end_date':
                $date = get_post_meta($post_id, '_lcd_person_end_date', true);
                echo $date ? date('Y-m-d', strtotime($date)) : '';
                break;
        }
    }

    public function set_sortable_columns($columns) {
        $columns['email'] = 'email';
        $columns['membership_status'] = 'membership_status';
        $columns['membership_type'] = 'membership_type';
        $columns['sustaining'] = 'sustaining';
        $columns['start_date'] = 'start_date';
        $columns['end_date'] = 'end_date';
        return $columns;
    }

    public function add_meta_boxes() {
        // Contact Information meta box
        add_meta_box(
            'lcd_person_contact',
            __('Contact Information', 'lcd-people'),
            array($this, 'render_contact_meta_box'),
            'lcd_person',
            'normal',
            'high'
        );

        // Membership Details meta box
        add_meta_box(
            'lcd_person_details',
            __('Membership Details', 'lcd-people'),
            array($this, 'render_membership_meta_box'),
            'lcd_person',
            'normal',
            'high'
        );

        // Sync Records meta box
        add_meta_box(
            'lcd_person_sync_records',
            __('Sync Records', 'lcd-people'),
            array($this, 'render_sync_records_meta_box'),
            'lcd_person',
            'side',
            'default'
        );

        // Shared Email Management meta box
        add_meta_box(
            'lcd_person_shared_email',
            __('Shared Email Management', 'lcd-people'),
            array($this, 'render_shared_email_meta_box'),
            'lcd_person',
            'side', // Place it on the side
            'low'  // Place it lower down
        );

        // Volunteering Information meta box (conditional on LCD Events plugin)
        if (function_exists('lcd_get_volunteer_signups')) {
            add_meta_box(
                'lcd_person_volunteering',
                __('Volunteering Information', 'lcd-people'),
                array($this, 'render_volunteering_meta_box'),
                'lcd_person',
                'normal',
                'default'
            );
        }
    }

    public function render_contact_meta_box($post) {
        wp_nonce_field('lcd_person_meta_box', 'lcd_person_meta_box_nonce');

        $first_name = get_post_meta($post->ID, '_lcd_person_first_name', true);
        $last_name = get_post_meta($post->ID, '_lcd_person_last_name', true);
        $email = get_post_meta($post->ID, '_lcd_person_email', true);
        $phone = get_post_meta($post->ID, '_lcd_person_phone', true);
        $address = get_post_meta($post->ID, '_lcd_person_address', true);
        $user_id = get_post_meta($post->ID, '_lcd_person_user_id', true);
        
        // Get connected user info if exists
        $connected_user = null;
        if ($user_id) {
            $connected_user = get_user_by('id', $user_id);
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="lcd_person_user_id"><?php _e('Connected WordPress User', 'lcd-people'); ?></label></th>
                <td>
                    <div class="lcd-user-connection">
                        <?php if ($connected_user): ?>
                            <div class="lcd-connected-user">
                                <p>
                                    <?php echo sprintf(
                                        __('Connected to: <strong>%s</strong> (%s)', 'lcd-people'),
                                        esc_html($connected_user->display_name),
                                        esc_html($connected_user->user_email)
                                    ); ?>
                                    <a href="#" class="lcd-disconnect-user" data-confirm="<?php esc_attr_e('Are you sure you want to disconnect this user?', 'lcd-people'); ?>">
                                        <?php _e('Disconnect', 'lcd-people'); ?>
                                    </a>
                                </p>
                            </div>
                        <?php else: ?>
                            <select id="lcd_person_user_search" style="width: 100%;" placeholder="<?php esc_attr_e('Search for a user...', 'lcd-people'); ?>">
                                <option value=""><?php _e('Select a user...', 'lcd-people'); ?></option>
                            </select>
                        <?php endif; ?>
                        <input type="hidden" name="lcd_person_user_id" id="lcd_person_user_id" value="<?php echo esc_attr($user_id); ?>">
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_first_name"><?php _e('First Name', 'lcd-people'); ?></label></th>
                <td>
                    <input type="text" id="lcd_person_first_name" name="lcd_person_first_name" value="<?php echo esc_attr($first_name); ?>" class="regular-text" required>
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_last_name"><?php _e('Last Name', 'lcd-people'); ?></label></th>
                <td>
                    <input type="text" id="lcd_person_last_name" name="lcd_person_last_name" value="<?php echo esc_attr($last_name); ?>" class="regular-text" required>
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_email"><?php _e('Email', 'lcd-people'); ?></label></th>
                <td>
                    <input type="email" id="lcd_person_email" name="lcd_person_email" value="<?php echo esc_attr($email); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_phone"><?php _e('Phone', 'lcd-people'); ?></label></th>
                <td>
                    <input type="tel" id="lcd_person_phone" name="lcd_person_phone" value="<?php echo esc_attr($phone); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_address"><?php _e('Address', 'lcd-people'); ?></label></th>
                <td>
                    <textarea id="lcd_person_address" name="lcd_person_address" rows="3" class="regular-text"><?php echo esc_textarea($address); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_membership_meta_box($post) {
        $membership_status = get_post_meta($post->ID, '_lcd_person_membership_status', true);
        $start_date = get_post_meta($post->ID, '_lcd_person_start_date', true);
        $end_date = get_post_meta($post->ID, '_lcd_person_end_date', true);
        $membership_type = get_post_meta($post->ID, '_lcd_person_membership_type', true);
        $is_sustaining = get_post_meta($post->ID, '_lcd_person_is_sustaining', true);
        $dues_paid_via = get_post_meta($post->ID, '_lcd_person_dues_paid_via', true);
        $actblue_lineitem_id = get_post_meta($post->ID, '_lcd_person_actblue_lineitem_id', true);
        $is_primary = get_post_meta($post->ID, '_lcd_person_is_primary', true);
        $email = get_post_meta($post->ID, '_lcd_person_email', true); // Needed for conditional display

        // Default to checked if meta value is empty (for new posts) or doesn't exist yet
        if ($is_primary === '') {
            $is_primary = '1';
        }
        ?>
        <table class="form-table">
            <tr>
                <th><label for="lcd_person_membership_type"><?php _e('Membership Type', 'lcd-people'); ?></label></th>
                <td>
                    <select id="lcd_person_membership_type" name="lcd_person_membership_type">
                        <option value=""><?php _e('None', 'lcd-people'); ?></option>
                        <option value="paid" <?php selected($membership_type, 'paid'); ?>><?php _e('Paid', 'lcd-people'); ?></option>
                        <option value="compulsary" <?php selected($membership_type, 'compulsary'); ?>><?php _e('Compulsary', 'lcd-people'); ?></option>
                        <option value="gratis" <?php selected($membership_type, 'gratis'); ?>><?php _e('Gratis', 'lcd-people'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_membership_status"><?php _e('Status', 'lcd-people'); ?></label></th>
                <td>
                    <select id="lcd_person_membership_status" name="lcd_person_membership_status">
                        <option value=""><?php _e('Not a Member', 'lcd-people'); ?></option>
                        <option value="active" <?php selected($membership_status, 'active'); ?>><?php _e('Active', 'lcd-people'); ?></option>
                        <option value="inactive" <?php selected($membership_status, 'inactive'); ?>><?php _e('Inactive', 'lcd-people'); ?></option>
                        <option value="grace" <?php selected($membership_status, 'grace'); ?>><?php _e('Grace Period', 'lcd-people'); ?></option>
                        <option value="expired" <?php selected($membership_status, 'expired'); ?>><?php _e('Expired', 'lcd-people'); ?></option>
                    </select>
                    <?php if ($membership_status === 'active'): ?>
                        <button type="button" class="button button-secondary" id="lcd-cancel-membership">
                            <?php _e('Cancel Membership', 'lcd-people'); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="lcd-retrigger-welcome">
                            <?php _e('Re-trigger Welcome', 'lcd-people'); ?>
                        </button>
                        <div id="lcd-retrigger-welcome-dialog" title="<?php esc_attr_e('Re-trigger Welcome Automation?', 'lcd-people'); ?>" style="display: none;">
                            <p><?php _e('This will attempt to re-trigger the welcome automation for this member.', 'lcd-people'); ?></p>
                            <p><strong><?php _e('Note:', 'lcd-people'); ?></strong> <?php _e('The automation will only run if the member has not been through it before.', 'lcd-people'); ?></p>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_is_sustaining"><?php _e('Sustaining Member', 'lcd-people'); ?></label></th>
                <td>
                    <input type="checkbox" id="lcd_person_is_sustaining" name="lcd_person_is_sustaining" value="1" <?php checked($is_sustaining, '1'); ?>>
                    <label for="lcd_person_is_sustaining"><?php _e('Is a sustaining member', 'lcd-people'); ?></label>
                </td>
            </tr>
            <tr>
                <th><label><?php _e('Membership Period', 'lcd-people'); ?></label></th>
                <td>
                    <label for="lcd_person_start_date"><?php _e('Start:', 'lcd-people'); ?></label>
                    <input type="date" id="lcd_person_start_date" name="lcd_person_start_date" value="<?php echo esc_attr($start_date); ?>" style="width: auto;">
                    &nbsp;&nbsp;
                    <label for="lcd_person_end_date"><?php _e('End:', 'lcd-people'); ?></label>
                    <input type="date" id="lcd_person_end_date" name="lcd_person_end_date" value="<?php echo esc_attr($end_date); ?>" style="width: auto;">
                </td>
            </tr>
            <tr>
                <th><label><?php _e('Payment Details', 'lcd-people'); ?></label></th>
                <td>
                    <select id="lcd_person_dues_paid_via" name="lcd_person_dues_paid_via" style="width: auto;">
                        <option value=""><?php _e('None', 'lcd-people'); ?></option>
                        <option value="actblue" <?php selected($dues_paid_via, 'actblue'); ?>><?php _e('ActBlue', 'lcd-people'); ?></option>
                        <option value="cash" <?php selected($dues_paid_via, 'cash'); ?>><?php _e('Cash', 'lcd-people'); ?></option>
                        <option value="check" <?php selected($dues_paid_via, 'check'); ?>><?php _e('Check', 'lcd-people'); ?></option>
                        <option value="transfer" <?php selected($dues_paid_via, 'transfer'); ?>><?php _e('Transfer', 'lcd-people'); ?></option>
                        <option value="in-kind" <?php selected($dues_paid_via, 'in-kind'); ?>><?php _e('In-Kind', 'lcd-people'); ?></option>
                    </select>
                    
                    <div id="actblue-payment-details" style="margin-top: 10px; <?php echo $dues_paid_via !== 'actblue' ? 'display: none;' : ''; ?>">
                        <input type="hidden" id="lcd_person_actblue_lineitem_id" name="lcd_person_actblue_lineitem_id" value="<?php echo esc_attr($actblue_lineitem_id); ?>">
                        <?php if ($actblue_lineitem_id): ?>
                            <a href="https://secure.actblue.com/entities/155025/lineitems/<?php echo esc_attr($actblue_lineitem_id); ?>" target="_blank" class="button button-primary">
                                <span class="dashicons dashicons-external"></span>
                                <?php _e('View Payment on ActBlue', 'lcd-people'); ?>
                            </a>
                            <a href="#" class="edit-actblue-payment">
                                <span class="dashicons dashicons-edit"></span>
                            </a>
                        <?php else: ?>
                            <button type="button" class="button button-secondary add-actblue-payment">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <?php _e('Add ActBlue Payment', 'lcd-people'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_payment_note"><?php _e('Payment Note', 'lcd-people'); ?></label></th>
                <td>
                    <textarea id="lcd_person_payment_note" name="lcd_person_payment_note" rows="3" class="widefat"><?php echo esc_textarea(get_post_meta($post->ID, '_lcd_person_payment_note', true)); ?></textarea>
                    <p class="description"><?php _e('Add any notes about the payment here (e.g., check number, payment plan details, etc.)', 'lcd-people'); ?></p>
                </td>
            </tr>
        </table>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Function to toggle visibility of the primary member row
                function togglePrimaryRow() {
                    if ($('#lcd_person_email').val()) {
                        $('.primary-member-row').show();
                    } else {
                        $('.primary-member-row').hide();
                        // Uncheck primary if email is removed
                        $('#lcd_person_is_primary').prop('checked', false);
                    }
                }

                // Check visibility when email field changes
                $('#lcd_person_email').on('input change keyup', togglePrimaryRow);

                // Also check visibility when contact metabox is loaded (in case email comes from user connect)
                 // We need to check periodically as user connection might populate email async
                var checkEmailInterval = setInterval(function() {
                    if ($('#lcd_person_email').val()) {
                         togglePrimaryRow();
                         // Optionally clear interval once email is found, or keep running
                         // clearInterval(checkEmailInterval);
                    }
                }, 500); // Check every 500ms

                // Initial check on page load
                togglePrimaryRow();

                // Handle "Make Primary" click
                $(document).on('click', '.lcd-make-primary', function(e) {
                    e.preventDefault();
                    var $link = $(this);
                    var personId = $link.data('person-id');
                    var currentPrimaryId = $link.data('current-primary-id');
                    var nonce = $link.data('nonce');

                    if (!confirm(lcdPeople.strings.confirmMakePrimary)) {
                        return;
                    }

                    $link.text(lcdPeople.strings.switchingPrimary + '...');
                    $link.css('pointer-events', 'none'); // Disable link during processing

                    $.post(lcdPeople.ajaxurl, {
                        action: 'lcd_switch_primary_member',
                        person_id: personId,
                        current_primary_id: currentPrimaryId,
                        _ajax_nonce: nonce
                    }, function(response) {
                        if (response.success) {
                            alert(lcdPeople.strings.switchPrimarySuccess);
                            location.reload(); // Reload the page to see changes
                        } else {
                            alert(lcdPeople.strings.switchPrimaryError + ' ' + response.data.message);
                            $link.text(lcdPeople.strings.makePrimary); // Restore link text
                             $link.css('pointer-events', ''); // Re-enable link
                        }
                    }).fail(function() {
                         alert(lcdPeople.strings.switchPrimaryError + ' ' + lcdPeople.strings.ajaxRequestFailed);
                         $link.text(lcdPeople.strings.makePrimary); // Restore link text
                         $link.css('pointer-events', ''); // Re-enable link
                    });
                });
            });
        </script>
        <?php
    }

    public function render_sync_records_meta_box($post) {
        $sync_records = get_post_meta($post->ID, '_lcd_person_sync_records', true);
        if (!is_array($sync_records)) {
            $sync_records = array();
        }
        ?>
        <div class="sync-records-wrapper">
            <?php if (empty($sync_records)) : ?>
                <p><?php _e('No sync records found.', 'lcd-people'); ?></p>
            <?php else : ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'lcd-people'); ?></th>
                            <th><?php _e('Service', 'lcd-people'); ?></th>
                            <th><?php _e('Status', 'lcd-people'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Sort records by timestamp, newest first
                        usort($sync_records, function($a, $b) {
                            return $b['timestamp'] - $a['timestamp'];
                        });
                        
                        // Show last 10 records
                        $records_to_show = array_slice($sync_records, 0, 10);
                        
                        foreach ($records_to_show as $record) : ?>
                            <tr>
                                <td><?php echo date_i18n('Y-m-d H:i:s', $record['timestamp']); ?></td>
                                <td><?php echo esc_html($record['service']); ?></td>
                                <td>
                                    <?php if ($record['success']) : ?>
                                        <span class="success">✓</span>
                                    <?php else : ?>
                                        <span class="error" title="<?php echo esc_attr($record['message']); ?>">✗</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php _e('Showing last 10 sync records', 'lcd-people'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function add_sync_record($person_id, $service, $success, $message = '') {
        $sync_records = get_post_meta($person_id, '_lcd_person_sync_records', true);
        if (!is_array($sync_records)) {
            $sync_records = array();
        }

        // Add new record
        $sync_records[] = array(
            'timestamp' => current_time('timestamp'),
            'service' => $service,
            'success' => $success,
            'message' => $message
        );

        // Keep only last 50 records
        if (count($sync_records) > 50) {
            $sync_records = array_slice($sync_records, -50);
        }

        update_post_meta($person_id, '_lcd_person_sync_records', $sync_records);
    }

    public function handle_person_sync($person_id) {
        $this->sender_handler->sync_person_to_sender($person_id);
    }

    public function handle_person_save($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ($post->post_type !== 'lcd_person') {
            return;
        }

        // Don't sync if this is a revision
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Store previous status before any changes
        $previous_status = get_post_meta($post_id, '_lcd_person_membership_status', true);
        
        // Let the normal save process happen
        // Note: save_meta_box_data is called automatically via the 'save_post' hook action added in the constructor
        // We don't need to call it explicitly here.

        // Update the previous status meta after potential changes in save_meta_box_data
        $current_status = get_post_meta($post_id, '_lcd_person_membership_status', true);
        update_post_meta($post_id, '_lcd_person_previous_status', $previous_status);

        // Sync to Sender.net - disable automation triggers for admin updates
        // This sync will check for primary status internally
        $this->sender_handler->sync_person_to_sender($post_id, false); 

        // Sync record logging is handled within sync_person_to_sender
    }

    /**
     * Register REST API endpoint
     */
    public function register_rest_endpoint() {
        // Register ActBlue webhook endpoint
        register_rest_route('lcd-people/v1', '/actblue-webhook', array(
            'methods' => 'POST',
            'callback' => array($this->actblue_handler, 'handle_actblue_webhook'),
            'permission_callback' => array($this->actblue_handler, 'verify_webhook_auth')
        ));

        // Register a separate namespace for the configured Forminator form
        $configured_form_id = get_option('lcd_people_forminator_volunteer_form');
        
        if (!empty($configured_form_id)) {
            // Create a unique namespace for this specific form
            $form_namespace = 'volunteer-form-' . $configured_form_id;
            
            register_rest_route($form_namespace . '/v1', '/submit', array(
                'methods' => 'POST,GET',  // Allow both POST and GET for testing
                'callback' => array($this, 'handle_forminator_webhook'),
                'permission_callback' => '__return_true'
            ));
        }
    }

    

    /**
     * Get person by email (and optionally first/last name for disambiguation)
     */
    private function get_person_by_email($email, $first_name = null, $last_name = null) {
        if (empty($email)) {
            return null;
        }

        $meta_query = array(
            'relation' => 'AND',
            array(
                'key' => '_lcd_person_email',
                'value' => $email,
                'compare' => '='
            ),
        );

        // If first and last name are provided, add them to the query
        if (!empty($first_name) && !empty($last_name)) {
             $meta_query[] = array(
                'key' => '_lcd_person_first_name',
                'value' => $first_name,
                'compare' => '=' // Note: Case-sensitivity depends on DB collation
            );
             $meta_query[] = array(
                'key' => '_lcd_person_last_name',
                'value' => $last_name,
                'compare' => '=' // Note: Case-sensitivity depends on DB collation
            );
        }
        
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => 1, // We only expect one match with email+name
            'meta_query' => $meta_query
        );

        $query = new WP_Query($args);
        return $query->posts ? $query->posts[0] : null;
    }

    /**
     * Get the primary person record for an email address
     * Only returns a person if they are marked as primary for the email
     */
    private function get_primary_person_by_email($email) {
        if (empty($email)) {
            return null;
        }

        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => 1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_lcd_person_email',
                    'value' => $email,
                    'compare' => '='
                ),
                array(
                    'key' => '_lcd_person_is_primary',
                    'value' => '1',
                    'compare' => '='
                )
            )
        );

        $query = new WP_Query($args);
        return $query->posts ? $query->posts[0] : null;
    }

   

    /**
     * Handle Forminator webhook
     */
    public function handle_forminator_webhook($request) {
        // Get data from the request
        $json_params = $request->get_json_params();
        $post_params = $request->get_params();
        
        // Get the form data, trying both JSON and POST params
        $form_data = !empty($json_params) ? $json_params : $post_params;
        
        // Store debug info
        $debug_info = array(
            'timestamp' => current_time('mysql'),
            'data_received' => array(
                'json_params' => $json_params,
                'post_params' => $post_params
            ),
            'steps' => array()
        );
        
        if (empty($form_data)) {
            $debug_info['steps'][] = array(
                'step' => 'check_data',
                'status' => 'error',
                'message' => 'No form data received'
            );
            set_transient('lcd_people_last_webhook_debug', $debug_info, WEEK_IN_SECONDS);
            return new WP_Error(
                'no_data',
                __('No form data received', 'lcd-people'),
                array('status' => 400)
            );
        }

        // Get field mappings
        $mappings = get_option('lcd_people_forminator_volunteer_mappings', array());
        if (empty($mappings)) {
            $debug_info['steps'][] = array(
                'step' => 'check_mappings',
                'status' => 'error',
                'message' => 'No field mappings configured'
            );
            set_transient('lcd_people_last_webhook_debug', $debug_info, WEEK_IN_SECONDS);
            return new WP_Error(
                'no_mappings',
                __('No field mappings configured', 'lcd-people'),
                array('status' => 400)
            );
        }

        // Debug log the mappings
        $debug_info['steps'][] = array(
            'step' => 'mappings_config',
            'status' => 'info',
            'message' => 'Field mappings: ' . print_r($mappings, true)
        );

        // Debug log the raw form data received
        $debug_info['steps'][] = array(
            'step' => 'raw_form_data',
            'status' => 'info',
            'message' => 'Raw form data received: ' . print_r($form_data, true)
        );

        // Map form data to person data
        $person_data = array();
        foreach ($mappings as $form_field => $person_field) {
            // Convert underscores to hyphens for field lookup
            $form_field_underscore = str_replace('-', '_', $form_field);
            
            // Debug log each field mapping attempt
            $debug_info['steps'][] = array(
                'step' => 'field_mapping',
                'status' => 'info',
                'message' => sprintf(
                    'Mapping form field "%s" to person field "%s". Form value: %s',
                    $form_field,
                    $person_field,
                    isset($form_data[$form_field_underscore]) ? print_r($form_data[$form_field_underscore], true) : 'not set'
                )
            );
            
            // Handle name field components (e.g., name-1_first_name, name-1_last_name)
            if (strpos($form_field, '_first_name') !== false || strpos($form_field, '_last_name') !== false || strpos($form_field, '_middle_name') !== false) {
                // First, try to get the value directly from the form data
                // Forminator might send individual components directly
                if (isset($form_data[$form_field_underscore])) {
                    $person_data[$person_field] = $form_data[$form_field_underscore];
                    $debug_info['steps'][] = array(
                        'step' => 'name_field_direct',
                        'status' => 'success',
                        'message' => sprintf(
                            'Found name field "%s" directly in form data: %s',
                            $form_field_underscore,
                            $form_data[$form_field_underscore]
                        )
                    );
                } else {
                    // Fallback: try to extract from base name field array
                    // Extract the base name field (e.g., "name-1" from "name-1_first_name")
                    $base_field = str_replace(array('_first_name', '_last_name', '_middle_name'), '', $form_field);
                    $base_field_underscore = str_replace('-', '_', $base_field);
                    
                    $debug_info['steps'][] = array(
                        'step' => 'name_field_fallback',
                        'status' => 'info',
                        'message' => sprintf(
                            'Trying fallback for name field "%s" using base field "%s"',
                            $form_field,
                            $base_field_underscore
                        )
                    );
                    
                    // Check if the base name field exists in form data
                    if (isset($form_data[$base_field_underscore]) && is_array($form_data[$base_field_underscore])) {
                        $name_data = $form_data[$base_field_underscore];
                        
                        // Extract the specific name component
                        if (strpos($form_field, '_first_name') !== false && isset($name_data['first-name'])) {
                            $person_data[$person_field] = $name_data['first-name'];
                            $debug_info['steps'][] = array(
                                'step' => 'name_field_extracted',
                                'status' => 'success',
                                'message' => sprintf(
                                    'Extracted first name from base field "%s": %s',
                                    $base_field_underscore,
                                    $name_data['first-name']
                                )
                            );
                        } elseif (strpos($form_field, '_last_name') !== false && isset($name_data['last-name'])) {
                            $person_data[$person_field] = $name_data['last-name'];
                            $debug_info['steps'][] = array(
                                'step' => 'name_field_extracted',
                                'status' => 'success',
                                'message' => sprintf(
                                    'Extracted last name from base field "%s": %s',
                                    $base_field_underscore,
                                    $name_data['last-name']
                                )
                            );
                        } elseif (strpos($form_field, '_middle_name') !== false && isset($name_data['middle-name'])) {
                            $person_data[$person_field] = $name_data['middle-name'];
                            $debug_info['steps'][] = array(
                                'step' => 'name_field_extracted',
                                'status' => 'success',
                                'message' => sprintf(
                                    'Extracted middle name from base field "%s": %s',
                                    $base_field_underscore,
                                    $name_data['middle-name']
                                )
                            );
                        } else {
                            $debug_info['steps'][] = array(
                                'step' => 'name_field_fallback_failed',
                                'status' => 'error',
                                'message' => sprintf(
                                    'Could not extract name component from base field "%s". Available keys: %s',
                                    $base_field_underscore,
                                    implode(', ', array_keys($name_data))
                                )
                            );
                        }
                    } else {
                        $debug_info['steps'][] = array(
                            'step' => 'name_field_fallback_failed',
                            'status' => 'error',
                            'message' => sprintf(
                                'Base name field "%s" not found in form data or is not an array',
                                $base_field_underscore
                            )
                        );
                    }
                }
            } else {
                // Handle regular fields
                if (!empty($form_data[$form_field_underscore])) {
                    $person_data[$person_field] = $form_data[$form_field_underscore];
                }
            }
        }
        
        $debug_info['steps'][] = array(
            'step' => 'map_fields',
            'status' => 'success',
            'message' => 'Final mapped fields: ' . print_r($person_data, true)
        );

        if (empty($person_data['email'])) {
            $debug_info['steps'][] = array(
                'step' => 'check_email',
                'status' => 'error',
                'message' => 'Email field is required'
            );
            set_transient('lcd_people_last_webhook_debug', $debug_info, WEEK_IN_SECONDS);
            return new WP_Error(
                'no_email',
                __('Email field is required', 'lcd-people'),
                array('status' => 400)
            );
        }

        // Try to find existing person by email
        $person = $this->get_person_by_email($person_data['email']);

        // Check for submission ID (entry_id) in the form data
        $submission_id = null;
        if (isset($form_data['entry_id'])) {
            $submission_id = intval($form_data['entry_id']);
            $debug_info['steps'][] = array(
                'step' => 'submission_id',
                'status' => 'info',
                'message' => 'Found submission ID: ' . $submission_id
            );
        }
        
        // Also check if submission ID was mapped through field mappings
        if (empty($submission_id) && isset($person_data['latest_volunteer_submission_id'])) {
            $submission_id = intval($person_data['latest_volunteer_submission_id']);
            // Remove it from person_data since it's not a regular person field
            unset($person_data['latest_volunteer_submission_id']);
            $debug_info['steps'][] = array(
                'step' => 'submission_id_mapped',
                'status' => 'info',
                'message' => 'Found submission ID from field mapping: ' . $submission_id
            );
        }

        if ($person) {
            // Update existing person
            $this->update_person_from_forminator($person->ID, $person_data, '', '', $submission_id);
            $this->add_sync_record($person->ID, 'Forminator', true, 'Person updated from volunteer form');
            $response_message = 'Person updated successfully';
            $debug_info['steps'][] = array(
                'step' => 'update_person',
                'status' => 'success',
                'message' => 'Updated person ID: ' . $person->ID
            );
        } else {
            // Create new person
            $person_id = $this->create_person_from_forminator($person_data, '', '', $submission_id);
            if (is_wp_error($person_id)) {
                $debug_info['steps'][] = array(
                    'step' => 'create_person',
                    'status' => 'error',
                    'message' => $person_id->get_error_message()
                );
                set_transient('lcd_people_last_webhook_debug', $debug_info, WEEK_IN_SECONDS);
                return $person_id;
            }
            $this->add_sync_record($person_id, 'Forminator', true, 'New person created from volunteer form');
            $response_message = 'New person created successfully';
            $debug_info['steps'][] = array(
                'step' => 'create_person',
                'status' => 'success',
                'message' => 'Created person ID: ' . $person_id
            );
        }

        $debug_info['steps'][] = array(
            'step' => 'complete',
            'status' => 'success',
            'message' => $response_message
        );
        set_transient('lcd_people_last_webhook_debug', $debug_info, WEEK_IN_SECONDS);

        return array(
            'success' => true,
            'message' => $response_message
        );
    }

    /**
     * Update person from Forminator data (selective updates for existing people)
     */
    private function update_person_from_forminator($person_id, $person_data, $role_data = '', $precinct_data = '', $submission_id = null) {
        // For existing people, only update fields that are empty or if the new data is more complete
        $fields_to_update = array();
        
        foreach ($person_data as $field => $value) {
            if (!empty($value)) {
                $current_value = get_post_meta($person_id, '_lcd_person_' . $field, true);
                
                // Update if current field is empty, or if it's contact info that might have changed
                // Also update name fields from volunteer forms as they might provide more accurate info
                if (empty($current_value) || in_array($field, array('phone', 'address', 'first_name', 'last_name'))) {
                    $fields_to_update[$field] = $value;
                }
            }
        }
        
        // Apply the selective updates
        foreach ($fields_to_update as $field => $value) {
            update_post_meta($person_id, '_lcd_person_' . $field, $value);
        }

        // Add volunteer role if not already present (this method now checks for duplicates)
        $this->add_volunteer_role($person_id, $role_data);
        
        // Add precinct if provided (only if they don't already have one)
        if (!empty($precinct_data)) {
            $current_precincts = wp_get_post_terms($person_id, 'lcd_precinct', array('fields' => 'slugs'));
            if (is_wp_error($current_precincts)) {
                $current_precincts = array();
            }
            
            if (empty($current_precincts)) {
                wp_set_post_terms($person_id, array($precinct_data), 'lcd_precinct', false);
            }
        }

        // Update post title only if name fields were actually updated
        if (isset($fields_to_update['first_name']) || isset($fields_to_update['last_name'])) {
            $first_name = get_post_meta($person_id, '_lcd_person_first_name', true);
            $last_name = get_post_meta($person_id, '_lcd_person_last_name', true);
            
            if (!empty($first_name) && !empty($last_name)) {
                wp_update_post(array(
                    'ID' => $person_id,
                    'post_title' => $first_name . ' ' . $last_name
                ));
            }
        }

        // Handle Primary Status (Automatic Assignment) - only if email was updated
        if (isset($fields_to_update['email'])) {
            $this->update_primary_status($person_id, $fields_to_update['email']);
        }

        // Store submission ID if provided
        if (!empty($submission_id)) {
            update_post_meta($person_id, '_lcd_person_latest_volunteer_submission_id', $submission_id);
        }

        // Sync to Sender.net with volunteer group (this will add volunteer group if not already present)
        $this->sender_handler->sync_volunteer_to_sender($person_id);
    }

    /**
     * Create person from Forminator data
     */
    private function create_person_from_forminator($person_data, $role_data = '', $precinct_data = '', $submission_id = null) {
        // Create post
        $post_data = array(
            'post_title'  => $person_data['first_name'] . ' ' . $person_data['last_name'],
            'post_type'   => 'lcd_person',
            'post_status' => 'publish'
        );

        $person_id = wp_insert_post($post_data);

        if (is_wp_error($person_id)) {
            return $person_id;
        }

        // Set meta data
        foreach ($person_data as $field => $value) {
            if (!empty($value)) {
                update_post_meta($person_id, '_lcd_person_' . $field, $value);
            }
        }

        // Add volunteer role
        $this->add_volunteer_role($person_id, $role_data);
        
        // Add precinct if provided
        if (!empty($precinct_data)) {
            wp_set_post_terms($person_id, array($precinct_data), 'lcd_precinct', false);
        }

        // Handle Primary Status (Automatic Assignment)
        if (!empty($person_data['email'])) {
            $this->update_primary_status($person_id, $person_data['email']);
        }

        // Store submission ID if provided
        if (!empty($submission_id)) {
            update_post_meta($person_id, '_lcd_person_latest_volunteer_submission_id', $submission_id);
        }

        // Sync to Sender.net with volunteer group
        $this->sender_handler->sync_volunteer_to_sender($person_id);

        return $person_id;
    }

    /**
     * Add volunteer role to person (only if not already present)
     */
    private function add_volunteer_role($person_id, $additional_role = '') {
        // Get current roles
        $current_roles = wp_get_post_terms($person_id, 'lcd_role', array('fields' => 'slugs'));
        if (is_wp_error($current_roles)) {
            $current_roles = array();
        }

        $roles_to_add = array();
        
        // Add volunteer role if not already present
        if (!in_array('Volunteer', $current_roles)) {
            $roles_to_add[] = 'Volunteer';
        }
        
        // Add additional role if provided and not already present
        if (!empty($additional_role) && !in_array($additional_role, $current_roles)) {
            $roles_to_add[] = $additional_role;
        }
        
        // Only add roles if there are new ones to add
        if (!empty($roles_to_add)) {
            wp_set_post_terms($person_id, $roles_to_add, 'lcd_role', true); // true = append to existing
        }
    }

    /**
     * Update primary status for a person with given email
     */
    private function update_primary_status($person_id, $email) {
        // Find if another primary person exists with this email
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_status' => 'publish',
            'post__not_in' => array($person_id),
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_lcd_person_email',
                    'value' => $email,
                    'compare' => '='
                ),
                array(
                    'key' => '_lcd_person_is_primary',
                    'value' => '1',
                    'compare' => '='
                )
            )
        );
        $existing_primary_query = new WP_Query($args);
        $existing_primary_id = $existing_primary_query->posts ? $existing_primary_query->posts[0] : false;

        if ($existing_primary_id) {
            // Another primary exists - this person becomes secondary
            update_post_meta($person_id, '_lcd_person_is_primary', '0');
            update_post_meta($person_id, '_lcd_person_actual_primary_id', $existing_primary_id);
        } else {
            // No other primary - this person becomes primary
            update_post_meta($person_id, '_lcd_person_is_primary', '1');
            delete_post_meta($person_id, '_lcd_person_actual_primary_id');
        }
    }

    /**
     * Check and update membership statuses
     */
    public function check_membership_statuses() {
        $current_date = current_time('Y-m-d');
        
        // Get active members whose end date has passed
        $expired_members = get_posts(array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_lcd_person_membership_status',
                    'value' => 'active',
                ),
                array(
                    'key' => '_lcd_person_end_date',
                    'value' => $current_date,
                    'compare' => '<',
                    'type' => 'DATE'
                )
            )
        ));

        // Update expired members to grace period
        foreach ($expired_members as $member) {
            update_post_meta($member->ID, '_lcd_person_membership_status', 'grace');
            do_action('lcd_member_status_changed', $member->ID, 'active', 'grace');
        }

        // Get grace period members who expired more than 30 days ago
        $grace_cutoff_date = date('Y-m-d', strtotime($current_date . ' -30 days'));
        
        $inactive_members = get_posts(array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_lcd_person_membership_status',
                    'value' => 'grace',
                ),
                array(
                    'key' => '_lcd_person_end_date',
                    'value' => $grace_cutoff_date,
                    'compare' => '<',
                    'type' => 'DATE'
                )
            )
        ));

        // Update to inactive
        foreach ($inactive_members as $member) {
            update_post_meta($member->ID, '_lcd_person_membership_status', 'inactive');
            do_action('lcd_member_status_changed', $member->ID, 'grace', 'inactive');
        }
    }

    /**
     * Clean up scheduled hooks on plugin deactivation
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('lcd_check_membership_statuses');
    }

    public function ajax_cancel_membership() {
        check_ajax_referer('lcd_cancel_membership', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')));
        }

        $person_id = intval($_POST['person_id']);
        if (!$person_id) {
            wp_send_json_error(array('message' => __('Invalid person ID.', 'lcd-people')));
        }

        // Get current date in site's timezone
        $current_date = current_time('Y-m-d');

        // Store the current status before changing it
        $previous_status = get_post_meta($person_id, '_lcd_person_membership_status', true);
        update_post_meta($person_id, '_lcd_person_previous_status', $previous_status);

        // Update person meta
        update_post_meta($person_id, '_lcd_person_membership_status', 'inactive');
        update_post_meta($person_id, '_lcd_person_is_sustaining', '0');
        update_post_meta($person_id, '_lcd_person_end_date', $current_date);
        update_post_meta($person_id, '_lcd_person_dues_paid_via', '');

        // Sync to Sender.net
        $sync_result = $this->sender_handler->sync_person_to_sender($person_id);

        if (!$sync_result) {
            error_log('Failed to sync cancelled membership to Sender.net for person ' . $person_id);
        }

        wp_send_json_success(array(
            'current_date' => $current_date,
            'message' => __('Membership cancelled successfully.', 'lcd-people')
        ));
    }

    public function ajax_retrigger_welcome() {
        check_ajax_referer('lcd_people_user_search', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')));
        }

        $person_id = intval($_POST['person_id']);
        if (!$person_id) {
            wp_send_json_error(array('message' => __('Invalid person ID.', 'lcd-people')));
        }

        // Use the sender handler to retrigger welcome automation
        $result = $this->sender_handler->retrigger_welcome_automation($person_id);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    /**
     * Disable the default months dropdown for our post type
     */
    public function disable_months_dropdown($disable, $post_type) {
        if ($post_type === 'lcd_person') {
            return true;
        }
        return $disable;
    }

    /**
     * Add custom filter dropdowns to the admin list
     */
    public function add_admin_filters() {
        global $typenow;
        
        if ($typenow !== 'lcd_person') {
            return;
        }

        // Get current values from URL
        $current_status = isset($_GET['membership_status']) ? sanitize_text_field($_GET['membership_status']) : '';
        $current_type = isset($_GET['membership_type']) ? sanitize_text_field($_GET['membership_type']) : '';
        $current_sustaining = isset($_GET['is_sustaining']) ? sanitize_text_field($_GET['is_sustaining']) : '';
        $current_role = isset($_GET['lcd_role']) ? sanitize_text_field($_GET['lcd_role']) : '';

        // Membership Status dropdown
        $statuses = array(
            '' => __('All Statuses', 'lcd-people'),
            'active' => __('Active', 'lcd-people'),
            'inactive' => __('Inactive', 'lcd-people'),
            'grace' => __('Grace Period', 'lcd-people'),
            'expired' => __('Expired', 'lcd-people')
        );
        echo '<select name="membership_status">';
        foreach ($statuses as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_status, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';

        // Membership Type dropdown
        $types = array(
            '' => __('All Types', 'lcd-people'),
            'paid' => __('Paid', 'lcd-people'),
            'compulsary' => __('Compulsary', 'lcd-people'),
            'gratis' => __('Gratis', 'lcd-people')
        );
        echo '<select name="membership_type">';
        foreach ($types as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_type, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';

        // Sustaining Member dropdown
        $sustaining_options = array(
            '' => __('All Members', 'lcd-people'),
            '1' => __('Sustaining Only', 'lcd-people'),
            '0' => __('Non-Sustaining Only', 'lcd-people')
        );
        echo '<select name="is_sustaining">';
        foreach ($sustaining_options as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_sustaining, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';

        // Roles dropdown
        $roles = get_terms(array(
            'taxonomy' => 'lcd_role',
            'hide_empty' => false,
        ));

        if (!empty($roles) && !is_wp_error($roles)) {
            echo '<select name="lcd_role">';
            echo '<option value="">' . __('All Roles', 'lcd-people') . '</option>';
            foreach ($roles as $role) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($role->slug),
                    selected($current_role, $role->slug, false),
                    esc_html($role->name)
                );
            }
            echo '</select>';
        }
    }

    /**
     * Handle the custom filter logic
     */
    public function handle_admin_filters($query) {
        global $pagenow;
        
        // Only run in admin post list
        if (!is_admin() || $pagenow !== 'edit.php' || !isset($query->query['post_type']) || $query->query['post_type'] !== 'lcd_person') {
            return $query;
        }

        $meta_query = array();

        // Handle membership status filter
        if (!empty($_GET['membership_status'])) {
            $meta_query[] = array(
                'key' => '_lcd_person_membership_status',
                'value' => sanitize_text_field($_GET['membership_status']),
                'compare' => '='
            );
        }

        // Handle membership type filter
        if (!empty($_GET['membership_type'])) {
            $meta_query[] = array(
                'key' => '_lcd_person_membership_type',
                'value' => sanitize_text_field($_GET['membership_type']),
                'compare' => '='
            );
        }

        // Handle sustaining member filter
        if (isset($_GET['is_sustaining']) && $_GET['is_sustaining'] !== '') {
            $meta_query[] = array(
                'key' => '_lcd_person_is_sustaining',
                'value' => sanitize_text_field($_GET['is_sustaining']),
                'compare' => '='
            );
        }

        // Add meta query if we have any conditions
        if (!empty($meta_query)) {
            $meta_query['relation'] = 'AND';
            $query->set('meta_query', $meta_query);
        }

        // Handle roles filter
        if (!empty($_GET['lcd_role'])) {
            $tax_query = array(
                array(
                    'taxonomy' => 'lcd_role',
                    'field' => 'slug',
                    'terms' => sanitize_text_field($_GET['lcd_role'])
                )
            );
            $query->set('tax_query', $tax_query);
        }
        
        // Handle sorting by start_date and end_date
        $orderby = $query->get('orderby');
        
        if ($orderby === 'start_date') {
            $query->set('meta_key', '_lcd_person_start_date');
            $query->set('orderby', 'meta_value');
            $query->set('meta_type', 'DATE');
        } elseif ($orderby === 'end_date') {
            $query->set('meta_key', '_lcd_person_end_date');
            $query->set('orderby', 'meta_value');
            $query->set('meta_type', 'DATE');
        }

        return $query;
    }

    /**
     * AJAX handler to get all emails from the current filtered list
     */
    public function ajax_get_filtered_emails() {
        check_ajax_referer('lcd_people_admin', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(-1);
        }

        // Get the current admin filter arguments
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1, // Get all posts
            'fields' => 'ids', // Only get post IDs for efficiency
        );

        // Add meta query if filters are set
        $meta_query = array();

        // Handle membership status filter
        if (!empty($_GET['membership_status'])) {
            $meta_query[] = array(
                'key' => '_lcd_person_membership_status',
                'value' => sanitize_text_field($_GET['membership_status']),
                'compare' => '='
            );
        }

        // Handle membership type filter
        if (!empty($_GET['membership_type'])) {
            $meta_query[] = array(
                'key' => '_lcd_person_membership_type',
                'value' => sanitize_text_field($_GET['membership_type']),
                'compare' => '='
            );
        }

        // Handle sustaining member filter
        if (isset($_GET['is_sustaining']) && $_GET['is_sustaining'] !== '') {
            $meta_query[] = array(
                'key' => '_lcd_person_is_sustaining',
                'value' => sanitize_text_field($_GET['is_sustaining']),
                'compare' => '='
            );
        }

        // Apply meta query if we have conditions
        if (!empty($meta_query)) {
            if (count($meta_query) > 1) {
                $meta_query['relation'] = 'AND';
            }
            $args['meta_query'] = $meta_query;
        }

        // Handle role filter
        if (!empty($_GET['lcd_role'])) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'lcd_role',
                    'field' => 'slug',
                    'terms' => sanitize_text_field($_GET['lcd_role'])
                )
            );
        }

        // Handle search
        if (!empty($_GET['s'])) {
            $args['s'] = sanitize_text_field($_GET['s']);
        }

        // Run the query
        $query = new WP_Query($args);
        $emails = array();

        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                $email = get_post_meta($post_id, '_lcd_person_email', true);
                if (!empty($email)) {
                    $emails[] = $email;
                }
            }
        }

        wp_send_json_success(array(
            'emails' => $emails,
            'total' => count($emails)
        ));
    }

    /**
     * Add custom buttons to the admin list table navigation area.
     */
    public function add_copy_emails_button($which) {
        global $typenow;
        
        // Only add buttons on our custom post type and at the top of the list
        if ($typenow !== 'lcd_person' || $which !== 'top') {
            return;
        }
        
        echo '<div class="alignleft actions lcd-people-actions">'; // Added a class for easier selection
        
        // Copy Emails Button
        echo '<button type="button" id="lcd-copy-emails-button" class="button">' . esc_html__('Copy Emails', 'lcd-people') . '</button>';
        
        // Sync All to Sender Button
        if (current_user_can('manage_options')) { // Only show sync button to admins
            echo '<button type="button" id="lcd-sync-all-sender-button" class="button button-secondary">' 
                 . '<span class="dashicons dashicons-update" style="margin-top: 4px;"></span> '
                 . esc_html__('Sync All to Sender.net', 'lcd-people') 
                 . '</button>';
             echo '<span class="spinner" style="float: none; vertical-align: middle; margin-left: 5px;"></span>'; // Add a spinner
        }

        echo '</div>';
    }

    /**
     * Add CSS class to table row if this person is a duplicate primary
     */
    public function add_person_row_class($classes, $class, $post_id) {
        if (get_post_type($post_id) !== 'lcd_person') {
            return $classes;
        }

        // Check only on the admin edit screen for lcd_person post type
        if (!is_admin() || !function_exists('get_current_screen')) {
             return $classes;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-lcd_person') {
             return $classes;
        }

        $is_primary = get_post_meta($post_id, '_lcd_person_is_primary', true);
        $email = get_post_meta($post_id, '_lcd_person_email', true);

        if ($is_primary === '1' && !empty($email)) {
            // Check if another primary person exists with the same email
            $args = array(
                'post_type' => 'lcd_person',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'post_status' => 'publish', // Only check published posts
                'post__not_in' => array($post_id),
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => '_lcd_person_email',
                        'value' => $email,
                        'compare' => '='
                    ),
                    array(
                        'key' => '_lcd_person_is_primary',
                        'value' => '1',
                        'compare' => '='
                    )
                )
            );
            $duplicate_query = new WP_Query($args);

            if ($duplicate_query->have_posts()) {
                $classes[] = 'lcd-duplicate-primary';
            }
        }

        return $classes;
    }

    /**
     * AJAX handler to switch the primary member status between two people with the same email.
     */
    public function ajax_switch_primary_member() {
        $person_id_to_promote = isset($_POST['person_id']) ? intval($_POST['person_id']) : 0;
        $current_primary_id_to_demote = isset($_POST['current_primary_id']) ? intval($_POST['current_primary_id']) : 0;
        $nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field($_POST['_ajax_nonce']) : '';

        // Security Checks
        if (!wp_verify_nonce($nonce, 'lcd_switch_primary_' . $person_id_to_promote)) {
            wp_send_json_error(array('message' => __('Invalid security token.', 'lcd-people')), 403);
        }

        if (!current_user_can('edit_post', $person_id_to_promote) || !current_user_can('edit_post', $current_primary_id_to_demote)) {
            wp_send_json_error(array('message' => __('You do not have permission to edit these records.', 'lcd-people')), 403);
        }

        // Validate IDs
        if (!$person_id_to_promote || !$current_primary_id_to_demote) {
            wp_send_json_error(array('message' => __('Invalid person IDs provided.', 'lcd-people')), 400);
        }

        $person_to_promote = get_post($person_id_to_promote);
        $person_to_demote = get_post($current_primary_id_to_demote);

        if (!$person_to_promote || $person_to_promote->post_type !== 'lcd_person' || !$person_to_demote || $person_to_demote->post_type !== 'lcd_person') {
            wp_send_json_error(array('message' => __('Invalid person records found.', 'lcd-people')), 400);
        }

        // Validate Emails
        $email_promote = get_post_meta($person_id_to_promote, '_lcd_person_email', true);
        $email_demote = get_post_meta($current_primary_id_to_demote, '_lcd_person_email', true);

        if (empty($email_promote) || $email_promote !== $email_demote) {
            wp_send_json_error(array('message' => __('Persons do not share the same email address.', 'lcd-people')), 400);
        }
        
        // Validate current primary status
        $is_demote_primary = get_post_meta($current_primary_id_to_demote, '_lcd_person_is_primary', true);
        if ($is_demote_primary !== '1') {
             wp_send_json_error(array('message' => __('The person to demote is not currently the primary member.', 'lcd-people')), 400);
        }
        $is_promote_primary = get_post_meta($person_id_to_promote, '_lcd_person_is_primary', true);
         if ($is_promote_primary === '1') {
             wp_send_json_error(array('message' => __('The person to promote is already the primary member.', 'lcd-people')), 400);
        }

        // Perform the switch
        update_post_meta($current_primary_id_to_demote, '_lcd_person_is_primary', '0');
        delete_post_meta($current_primary_id_to_demote, '_lcd_person_actual_primary_id'); // Clear any reference it might have had
        update_post_meta($person_id_to_promote, '_lcd_person_is_primary', '1');
        delete_post_meta($person_id_to_promote, '_lcd_person_actual_primary_id'); // This person is now primary, clear reference

        // Add sync records for auditing
        $this->add_sync_record($current_primary_id_to_demote, 'Primary Status', true, 'Demoted from primary via switch.');
        $this->add_sync_record($person_id_to_promote, 'Primary Status', true, 'Promoted to primary via switch.');

        // Note: We don't explicitly trigger Sender sync here, as just changing primary status doesn't
        // change the *data* that would need syncing (like membership status).
        // The correct primary person's data will sync on the next relevant event (save, status change etc.)

        wp_send_json_success(array('message' => __('Primary member switched successfully.', 'lcd-people')));
    }

    /**
     * AJAX handler to sync all people to Sender.net
     */
    public function ajax_sync_all_to_sender() {
        // Security Checks
        check_ajax_referer('lcd_people_admin', 'nonce'); // Use the existing admin nonce

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'lcd-people')), 403);
        }

        // Use the sender handler to sync all people
        $results = $this->sender_handler->sync_all_to_sender();

        $message = sprintf(
            __('Sync complete. Total People: %d. Backfilled Primary Status: %d. Attempted Sync (Primary with Email): %d. Synced Successfully: %d. Skipped (Non-Primary): %d. Skipped (No Email): %d. Failed: %d.', 'lcd-people'),
            $results['total_found'],
            $results['backfilled_primary'],
            $results['attempted'],
            $results['synced'],
            $results['skipped_non_primary'],
            $results['skipped_no_email'],
            $results['failed']
        );

        if ($results['failed'] > 0) {
            $message .= ' ' . __('See details below or check sync records on individual person pages.', 'lcd-people');
        }

        wp_send_json_success(array(
            'message' => $message,
            'results' => $results
        ));
    }

    /**
     * Render the Shared Email Management meta box content.
     */
    public function render_shared_email_meta_box($post) {
        $person_id = $post->ID;
        $person_name = $post->post_title; // Get the current person's name for clarity
        $email = get_post_meta($person_id, '_lcd_person_email', true);

        echo '<p><em>' . esc_html__('Only the primary member for an email address syncs with services like Sender.net.', 'lcd-people') . '</em></p>';

        if (empty($email)) {
            echo '<p>' . esc_html__('Email address required for shared email management.', 'lcd-people') . '</p>';
            return;
        }

        // Find other people with the same email
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1, // Get all matches
            'post_status' => 'publish',
            'post__not_in' => array($person_id),
            'meta_query' => array(
                array(
                    'key' => '_lcd_person_email',
                    'value' => $email,
                    'compare' => '='
                ),
            ),
            'fields' => 'ids', // Get only IDs initially
        );
        $linked_people_query = new WP_Query($args);
        $linked_people_ids = $linked_people_query->posts;

        if (empty($linked_people_ids)) {
            echo '<p>' . esc_html__('No other members share this email address.', 'lcd-people') . '</p>';
            return;
        }

        echo '<p><strong>' . esc_html__('Members sharing this email:', 'lcd-people') . '</strong></p>';
        echo '<ul>';

        $actual_primary_id = 0;
        $duplicate_primary_ids = [];
        
        // Check primary status of linked people
        foreach ($linked_people_ids as $linked_id) {
             $linked_person = get_post($linked_id);
             echo '<li><a href="' . esc_url(get_edit_post_link($linked_id)) . '" title="Edit ' . esc_attr($linked_person->post_title) . '">' . esc_html($linked_person->post_title) . '</a></li>';

            $is_linked_primary = get_post_meta($linked_id, '_lcd_person_is_primary', true);
            if ($is_linked_primary === '1') {
                if ($actual_primary_id === 0) {
                    $actual_primary_id = $linked_id; // Found the first primary among others
                } else {
                    $duplicate_primary_ids[] = $linked_id; // Found more than one primary among others
                }
            }
        }
        echo '</ul><hr>';

        // Now determine the status message based on the current person and the findings
        $current_person_is_primary = get_post_meta($person_id, '_lcd_person_is_primary', true) === '1';

        if ($current_person_is_primary) {
            echo '<p><strong>' . esc_html__('Status:', 'lcd-people') . '</strong> ';
            printf(esc_html__('%s (this record) is the primary contact.', 'lcd-people'), '<strong>' . esc_html($person_name) . '</strong>');
            echo '</p>';

            // Check if there was a conflict among the *other* linked members
            if ($actual_primary_id !== 0) {
                 $conflict_id = $actual_primary_id; // The first one found becomes the reference for the warning
                 $conflict_person = get_post($conflict_id);
                 printf(
                    '<p style="color: #d63638;"><strong>%s:</strong> %s <a href="%s">%s</a> %s</p>',
                    __('Warning', 'lcd-people'),
                    __('Another person,', 'lcd-people'),
                    esc_url(get_edit_post_link($conflict_id)),
                    esc_html($conflict_person->post_title),
                    __('is also marked as primary! Please resolve this conflict.', 'lcd-people')
                );
            }
            // Include duplicates beyond the first conflict if any
             foreach($duplicate_primary_ids as $dup_id) {
                 $dup_person = get_post($dup_id);
                 printf(
                    '<p style="color: #d63638;"><strong>%s:</strong> %s <a href="%s">%s</a> %s</p>',
                    __('Warning', 'lcd-people'),
                    __('And,', 'lcd-people'),
                    esc_url(get_edit_post_link($dup_id)),
                    esc_html($dup_person->post_title),
                    __('is also marked as primary!', 'lcd-people')
                );
             }
        } else {
            // Current person is secondary
            if ($actual_primary_id !== 0) {
                // Found a primary among the others
                $primary_person = get_post($actual_primary_id);
                echo '<p><strong>' . esc_html__('Status:', 'lcd-people') . '</strong> ';
                 printf(
                    '<a href="%s">%s</a> %s', 
                    esc_url(get_edit_post_link($actual_primary_id)), 
                    esc_html($primary_person->post_title), 
                    esc_html__('is the primary contact for this email.', 'lcd-people') // Clarified
                 );
                 echo '</p>';

                // Add the "Make Primary" link - referring to the person being edited
                printf(
                    '<p><a href="#" class="button button-secondary lcd-make-primary" data-person-id="%d" data-current-primary-id="%d" data-nonce="%s">%s %s</a></p>',
                    esc_attr($person_id),
                    esc_attr($actual_primary_id),
                    wp_create_nonce('lcd_switch_primary_' . $person_id),
                    esc_html__('Make', 'lcd-people'),
                    '<strong>' . esc_html($person_name) . '</strong> ' . esc_html__('Primary', 'lcd-people')
                );
                 // Also warn if there were duplicates found *among the others*
                 foreach($duplicate_primary_ids as $dup_id) {
                     $dup_person = get_post($dup_id);
                     printf(
                        '<p style="color: #d63638;"><strong>%s:</strong> %s <a href="%s">%s</a> %s</p>',
                        __('Warning', 'lcd-people'),
                        __('Conflict:', 'lcd-people'),
                        esc_url(get_edit_post_link($dup_id)),
                        esc_html($dup_person->post_title),
                        __('is also marked as primary!', 'lcd-people')
                    );
                 }
            } else {
                 // This person is secondary, AND no primary was found among others either!
                echo '<p style="color: #d63638;"><strong>' . esc_html__('Warning:', 'lcd-people') . '</strong> ' . esc_html__('No primary member is set for this email address among the linked members. Please save one of the members to automatically assign primary status.', 'lcd-people') . '</p>';
            }
        }
    }

    /**
     * Render Volunteering Information Meta Box
     */
    public function render_volunteering_meta_box($post) {
        $latest_submission_id = get_post_meta($post->ID, '_lcd_person_latest_volunteer_submission_id', true);
        $person_email = get_post_meta($post->ID, '_lcd_person_email', true);
        
        ?>
        <div class="lcd-volunteering-info">
            <?php if (!function_exists('lcd_get_volunteer_signups')): ?>
                <p class="description">
                    <?php _e('LCD Events plugin is not active. Volunteer shift information is not available.', 'lcd-people'); ?>
                </p>
            <?php else: ?>
                
                <!-- Latest Volunteer Submission ID -->
                <div class="volunteer-submission-section">
                    <h4><?php _e('Latest Volunteer Form Submission', 'lcd-people'); ?></h4>
                    <table class="form-table">
                        <tr>
                            <th><label for="lcd_person_latest_volunteer_submission_id"><?php _e('Submission ID:', 'lcd-people'); ?></label></th>
                            <td>
                                <input type="text" 
                                       id="lcd_person_latest_volunteer_submission_id" 
                                       name="lcd_person_latest_volunteer_submission_id" 
                                       value="<?php echo esc_attr($latest_submission_id); ?>" 
                                       class="regular-text">
                                <p class="description">
                                    <?php _e('This field can be automatically populated from form submissions via the Forminator integration mapping.', 'lcd-people'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php if (!empty($latest_submission_id)): ?>
                        <?php $submission_data = $this->get_forminator_submission_data($latest_submission_id); ?>
                        <?php if ($submission_data): ?>
                                                         <div class="submission-data-section">
                                 <button type="button" class="button button-secondary submission-toggle" data-target="submission-details">
                                     <span class="dashicons dashicons-arrow-down-alt2"></span>
                                     <span class="button-text"><?php _e('View Submission Data', 'lcd-people'); ?></span>
                                 </button>
                                <div id="submission-details" class="submission-details" style="display: none;">
                                    <div class="submission-meta">
                                        <p><strong><?php _e('Submitted:', 'lcd-people'); ?></strong> <?php echo esc_html($submission_data['date_created']); ?></p>
                                        <?php if (!empty($submission_data['form_name'])): ?>
                                            <p><strong><?php _e('Form:', 'lcd-people'); ?></strong> <?php echo esc_html($submission_data['form_name']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="submission-fields">
                                        <h5><?php _e('Submitted Data:', 'lcd-people'); ?></h5>
                                        <table class="widefat striped">
                                            <thead>
                                                <tr>
                                                    <th><?php _e('Field', 'lcd-people'); ?></th>
                                                    <th><?php _e('Value', 'lcd-people'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($submission_data['fields'] as $field_name => $field_value): ?>
                                                    <tr>
                                                        <td><strong><?php echo esc_html($field_name); ?></strong></td>
                                                        <td><?php echo esc_html($field_value); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="submission-error">
                                <p class="description" style="color: #d63638;">
                                    <?php _e('Submission data could not be retrieved. The submission may not exist or Forminator plugin may not be active.', 'lcd-people'); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Upcoming Volunteer Shifts -->
                <div class="upcoming-shifts-section">
                    <h4><?php _e('Upcoming Volunteer Shifts', 'lcd-people'); ?></h4>
                    <?php
                    $upcoming_shifts = $this->get_person_upcoming_volunteer_shifts($post->ID, $person_email);
                    
                    if (empty($upcoming_shifts)): ?>
                        <p><?php _e('No upcoming volunteer shifts found.', 'lcd-people'); ?></p>
                    <?php else: ?>
                        <div class="volunteer-shifts-list">
                            <?php foreach ($upcoming_shifts as $shift): ?>
                                <div class="shift-item">
                                    <div class="shift-header">
                                        <h5>
                                            <a href="<?php echo get_edit_post_link($shift['event_id']); ?>" target="_blank">
                                                <?php echo esc_html($shift['event_title']); ?>
                                            </a>
                                        </h5>
                                        <span class="shift-date">
                                            <?php echo esc_html($shift['formatted_event_date']); ?>
                                        </span>
                                    </div>
                                    <div class="shift-details">
                                        <div class="shift-info">
                                            <strong><?php _e('Shift:', 'lcd-people'); ?></strong> 
                                            <?php echo esc_html($shift['shift_title']); ?>
                                        </div>
                                        <?php if (!empty($shift['shift_date_time'])): ?>
                                            <div class="shift-time">
                                                <strong><?php _e('Time:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['shift_date_time']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($shift['event_location'])): ?>
                                            <div class="shift-location">
                                                <strong><?php _e('Location:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['event_location']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($shift['volunteer_notes'])): ?>
                                            <div class="shift-notes">
                                                <strong><?php _e('Notes:', 'lcd-people'); ?></strong>
                                                <?php echo esc_html($shift['volunteer_notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="shift-status">
                                            <strong><?php _e('Status:', 'lcd-people'); ?></strong>
                                            <span class="status-<?php echo esc_attr($shift['status']); ?>">
                                                <?php echo esc_html(ucfirst($shift['status'])); ?>
                                            </span>
                                        </div>
                                        <div class="shift-signup-date">
                                            <strong><?php _e('Signed up:', 'lcd-people'); ?></strong>
                                            <?php echo esc_html($shift['formatted_signup_date']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get upcoming volunteer shifts for a person
     */
    private function get_person_upcoming_volunteer_shifts($person_id, $person_email = '') {
        if (!function_exists('lcd_get_volunteer_signups')) {
            return array();
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'lcd_volunteer_signups';
        $today = current_time('Y-m-d');

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

        $upcoming_shifts = array();

        foreach ($signups as $signup) {
            // Get event meta data
            $event_date = get_post_meta($signup->event_id, '_event_date', true);
            $event_location = get_post_meta($signup->event_id, '_event_location', true);
            
            // Skip past events
            if (!empty($event_date) && $event_date < $today) {
                continue;
            }

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
            if (!empty($shift_details['date'])) {
                $shift_date = date_i18n(get_option('date_format'), strtotime($shift_details['date']));
                $time_parts = array();
                
                if (!empty($shift_details['start_time'])) {
                    $time_parts[] = date_i18n(get_option('time_format'), strtotime($shift_details['date'] . ' ' . $shift_details['start_time']));
                }
                if (!empty($shift_details['end_time'])) {
                    $time_parts[] = date_i18n(get_option('time_format'), strtotime($shift_details['date'] . ' ' . $shift_details['end_time']));
                }
                
                $shift_date_time = $shift_date;
                if (!empty($time_parts)) {
                    $shift_date_time .= ' ' . implode(' - ', $time_parts);
                }
            }

            $formatted_signup_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($signup->signup_date));

            $upcoming_shifts[] = array(
                'event_id' => $signup->event_id,
                'event_title' => $signup->event_title,
                'event_location' => $event_location,
                'shift_title' => $signup->shift_title,
                'shift_date_time' => $shift_date_time,
                'volunteer_notes' => $signup->volunteer_notes,
                'status' => $signup->status,
                'formatted_event_date' => $formatted_event_date,
                'formatted_signup_date' => $formatted_signup_date,
                'signup_date' => $signup->signup_date
            );
        }

        // Sort by event date if available, otherwise by signup date
        usort($upcoming_shifts, function($a, $b) {
            $date_a = !empty($a['shift_date_time']) ? strtotime($a['shift_date_time']) : strtotime($a['signup_date']);
            $date_b = !empty($b['shift_date_time']) ? strtotime($b['shift_date_time']) : strtotime($b['signup_date']);
            return $date_a - $date_b;
        });

        return $upcoming_shifts;
    }

    /**
     * Get Forminator submission data by submission ID
     */
    private function get_forminator_submission_data($submission_id) {
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
            error_log('LCD People: Error fetching Forminator submission data: ' . $e->getMessage());
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
        } elseif (preg_match('/^url-\d+$/', $field_key)) {
            return 'URL Field';
        } elseif (preg_match('/^upload-\d+$/', $field_key)) {
            return 'File Upload';
        }
        
        // Fallback: clean up field name for display
        $field_label = str_replace(array('_', '-'), ' ', $field_key);
        $field_label = ucwords($field_label);
        
        // Handle common patterns
        $field_label = str_replace(array(
            'Name 1', 'Email 1', 'Phone 1', 'Address 1',
            'Text 1', 'Textarea 1', 'Select 1', 'Radio 1', 'Checkbox 1'
        ), array(
            'Name', 'Email Address', 'Phone Number', 'Address',
            'Text Field', 'Text Area', 'Select Field', 'Radio Field', 'Checkbox Field'
        ), $field_label);
        
        return $field_label;
    }

    /**
     * User Registration Integration Methods
     * 
     * These methods handle connecting WordPress user registrations to People records:
     * 1. When a user registers, try to find an existing Person record by email (and name if available)
     * 2. If found, connect the existing Person to the new user account
     * 3. If not found, create a new Person record and connect it
     * 4. On login, check if user needs to be connected to a Person record (fallback)
     */

    /**
     * Handle new user registration
     * Triggered by 'user_register' hook
     */
    public function handle_user_registration($user_id) {
        // Get the newly registered user
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return;
        }

        // Check if user is already connected to a person record
        $existing_person_id = get_user_meta($user_id, self::USER_META_KEY, true);
        if ($existing_person_id && get_post($existing_person_id)) {
            // User is already connected to a person record, no need to create or connect another
            return;
        }

        // Try to find existing person by email first
        $person = $this->get_person_by_email($user->user_email, $user->first_name, $user->last_name);
        
        if (!$person) {
            // If no exact match, try by email only
            $person = $this->get_person_by_email($user->user_email);
        }

        if ($person) {
            // Connect existing person to user
            $person_id = $person->ID;
            update_post_meta($person_id, '_lcd_person_user_id', $user_id);
            update_user_meta($user_id, self::USER_META_KEY, $person_id);
            
            // Update person data with user info if not already set
            $this->update_person_from_user($person_id, $user);
        } else {
            // Create new person record
            $person_id = $this->create_person_from_user($user_id);
            if ($person_id && !is_wp_error($person_id)) {
                update_user_meta($user_id, self::USER_META_KEY, $person_id);
            }
        }
    }

    /**
     * Handle user login check
     * Triggered by 'wp_login' hook as a fallback to ensure users are connected to Person records
     */
    public function handle_user_login_check($user_login, $user) {
        // Check if user already has a connected person record
        $person_id = get_user_meta($user->ID, self::USER_META_KEY, true);
        if ($person_id && get_post($person_id)) {
            return; // Already connected
        }

        // Try to find existing person by email first
        $person = $this->get_person_by_email($user->user_email, $user->first_name, $user->last_name);
        
        if (!$person) {
            // If no exact match, try by email only
            $person = $this->get_person_by_email($user->user_email);
        }

        if ($person) {
            // Connect existing person to user
            $person_id = $person->ID;
            update_post_meta($person_id, '_lcd_person_user_id', $user->ID);
            update_user_meta($user->ID, self::USER_META_KEY, $person_id);
            
            // Update person data with user info if not already set
            $this->update_person_from_user($person_id, $user);
        } else {
            // Create new person record
            $person_id = $this->create_person_from_user($user->ID);
            if ($person_id && !is_wp_error($person_id)) {
                update_user_meta($user->ID, self::USER_META_KEY, $person_id);
            }
        }
    }

    /**
     * Create a person record from WordPress user data
     */
    private function create_person_from_user($user_id) {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return false;
        }

        // Create post title from user data
        $post_title = '';
        if (!empty($user->first_name) && !empty($user->last_name)) {
            $post_title = $user->first_name . ' ' . $user->last_name;
        } elseif (!empty($user->display_name) && $user->display_name !== $user->user_login) {
            $post_title = $user->display_name;
        } else {
            $post_title = $user->user_login;
        }

        // Create person post
        $post_data = array(
            'post_title'  => $post_title,
            'post_type'   => 'lcd_person',
            'post_status' => 'publish'
        );

        $person_id = wp_insert_post($post_data);

        if (is_wp_error($person_id)) {
            error_log('Failed to create person record for user ' . $user_id . ': ' . $person_id->get_error_message());
            return false;
        }

        // Set person meta data
        update_post_meta($person_id, '_lcd_person_user_id', $user_id);
        update_post_meta($person_id, '_lcd_person_email', $user->user_email);
        
        if (!empty($user->first_name)) {
            update_post_meta($person_id, '_lcd_person_first_name', $user->first_name);
        }
        
        if (!empty($user->last_name)) {
            update_post_meta($person_id, '_lcd_person_last_name', $user->last_name);
        }

        // Set default membership status for new registrations
        update_post_meta($person_id, '_lcd_person_membership_status', 'inactive');
        
        // Set registration date
        update_post_meta($person_id, '_lcd_person_registration_date', current_time('Y-m-d'));

        // Fire action for other plugins/themes to hook into
        do_action('lcd_person_created_from_registration', $person_id, $user_id);

        return $person_id;
    }

    /**
     * Update person record with user data (for existing people)
     */
    private function update_person_from_user($person_id, $user) {
        // Only update fields that are empty in the person record
        $existing_first_name = get_post_meta($person_id, '_lcd_person_first_name', true);
        $existing_last_name = get_post_meta($person_id, '_lcd_person_last_name', true);
        
        if (empty($existing_first_name) && !empty($user->first_name)) {
            update_post_meta($person_id, '_lcd_person_first_name', $user->first_name);
        }
        
        if (empty($existing_last_name) && !empty($user->last_name)) {
            update_post_meta($person_id, '_lcd_person_last_name', $user->last_name);
        }

        // Update the post title if it needs to be better
        $person_post = get_post($person_id);
        if ($person_post && !empty($user->first_name) && !empty($user->last_name)) {
            $new_title = $user->first_name . ' ' . $user->last_name;
            if ($person_post->post_title !== $new_title) {
                wp_update_post(array(
                    'ID' => $person_id,
                    'post_title' => $new_title
                ));
            }
        }

        // Set registration date if not already set
        $registration_date = get_post_meta($person_id, '_lcd_person_registration_date', true);
        if (empty($registration_date)) {
            update_post_meta($person_id, '_lcd_person_registration_date', current_time('Y-m-d'));
        }

        // Fire action for other plugins/themes to hook into
        do_action('lcd_person_connected_to_user', $person_id, $user->ID);
    }

    /**
     * AJAX handler for sending claim verification email
     */
    public function ajax_send_claim_verification_email() {
        // Check if we have POST data
        if (empty($_POST)) {
            wp_send_json_error(array('message' => __('No data received.', 'lcd-people')));
        }
        
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'lcd_claim_account')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lcd-people')));
        }

        $email = sanitize_email($_POST['email']);
        if (empty($email)) {
            wp_send_json_error(array('message' => __('Please provide a valid email address.', 'lcd-people')));
        }

        // Always send success response for security (don't reveal if email exists)
        $this->send_claim_verification_email($email);
        
        wp_send_json_success(array(
            'message' => __('Verification email sent successfully.', 'lcd-people')
        ));
    }

    /**
     * AJAX handler for verifying claim token
     */
    public function ajax_verify_claim_token() {
        // Check if we have POST data
        if (empty($_POST)) {
            wp_send_json_error(array('message' => __('No data received.', 'lcd-people')));
        }
        
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'lcd_claim_account')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lcd-people')));
        }

        $token = sanitize_text_field($_POST['token']);
        if (empty($token)) {
            wp_send_json_error(array('message' => __('Invalid token.', 'lcd-people')));
        }

        $person_data = $this->verify_claim_token($token);
        if (!$person_data) {
            wp_send_json_error(array('message' => __('Invalid or expired token. Please request a new verification email.', 'lcd-people')));
        }

        wp_send_json_success(array(
            'person' => $person_data
        ));
    }

    /**
     * AJAX handler for creating account with token
     */
    public function ajax_claim_create_account_with_token() {
        // Check if we have POST data
        if (empty($_POST)) {
            wp_send_json_error(array('message' => __('No data received.', 'lcd-people')));
        }
        
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'lcd_claim_account')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'lcd-people')));
        }

        $token = sanitize_text_field($_POST['token']);
        $password = $_POST['password']; // Don't sanitize password

        // Validate inputs
        if (empty($token) || empty($password)) {
            wp_send_json_error(array('message' => __('Missing required information.', 'lcd-people')));
        }

        // Verify token and get person data
        $person_data = $this->verify_claim_token($token);
        if (!$person_data) {
            wp_send_json_error(array('message' => __('Invalid or expired token. Please request a new verification email.', 'lcd-people')));
        }

        $person_id = $person_data['id'];
        $email = $person_data['email'];

        // Check if user already exists (final validation)
        $existing_user = get_user_by('email', $email);
        if ($existing_user) {
            wp_send_json_error(array('message' => __('A user account already exists for this email address.', 'lcd-people')));
        }

        // Create user account
        $user_data = array(
            'user_login' => $email,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $person_data['first_name'],
            'last_name' => $person_data['last_name'],
            'display_name' => $person_data['name'],
            'role' => 'subscriber'
        );

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => __('Failed to create user account: ', 'lcd-people') . $user_id->get_error_message()));
        }

        // Connect user to person record
        update_post_meta($person_id, '_lcd_person_user_id', $user_id);
        update_user_meta($user_id, self::USER_META_KEY, $person_id);

        // Set registration date if not already set
        $registration_date = get_post_meta($person_id, '_lcd_person_registration_date', true);
        if (empty($registration_date)) {
            update_post_meta($person_id, '_lcd_person_registration_date', current_time('Y-m-d'));
        }

        // Delete the used token
        $this->delete_claim_token($token);

        // Log the user in
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        // Fire action for other plugins/themes to hook into
        do_action('lcd_person_claimed_account', $person_id, $user_id);

        wp_send_json_success(array(
            'message' => __('Account created successfully! You are now logged in.', 'lcd-people'),
            'user_id' => $user_id,
            'person_id' => $person_id
        ));
    }

    /**
     * Manually connect a user to a person record
     * Useful for admin functions or bulk operations
     */
    public function connect_user_to_person($user_id, $person_id) {
        // Validate inputs
        $user = get_user_by('ID', $user_id);
        $person = get_post($person_id);
        
        if (!$user || !$person || $person->post_type !== 'lcd_person') {
            return false;
        }

        // Check if user is already connected to another person
        $existing_person_id = get_user_meta($user_id, self::USER_META_KEY, true);
        if ($existing_person_id && $existing_person_id != $person_id) {
            // Disconnect from previous person
            delete_post_meta($existing_person_id, '_lcd_person_user_id');
        }

        // Check if person is already connected to another user
        $existing_user_id = get_post_meta($person_id, '_lcd_person_user_id', true);
        if ($existing_user_id && $existing_user_id != $user_id) {
            // Disconnect from previous user
            delete_user_meta($existing_user_id, self::USER_META_KEY);
        }

        // Make the connection
        update_post_meta($person_id, '_lcd_person_user_id', $user_id);
        update_user_meta($user_id, self::USER_META_KEY, $person_id);
        
        // Update person data from user if needed
        $this->update_person_from_user($person_id, $user);

        return true;
    }

    /**
     * Find existing person records by email only (without name matching)
     * Useful for finding potential matches during registration
     */
    public function find_people_by_email($email) {
        if (empty($email)) {
            return array();
        }

        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_lcd_person_email',
                    'value' => $email,
                    'compare' => '='
                )
            )
        );

        return get_posts($args);
    }

    /**
     * Send claim verification email with appropriate content based on user/person status
     */
    private function send_claim_verification_email($email) {
        // Check what exists for this email
        $user = get_user_by('email', $email);
        $primary_person = $this->get_primary_person_by_email($email);
        
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $contact_email = get_option('admin_email');
        
        // Prepare common template variables
        $template_vars = array(
            'site_name' => $site_name,
            'site_url' => $site_url,
            'contact_email' => $contact_email,
            'login_url' => wp_login_url(),
            'reset_password_url' => wp_lostpassword_url(),
            'membership_url' => home_url('/membership'),
            'volunteer_url' => home_url('/volunteer'),
            'events_url' => home_url('/events'),
            'profile_url' => home_url('/profile')
        );
        
        if ($user) {
            // User account exists - send login instructions
            return $this->get_email_admin()->send_people_email('claim_existing_user', $email, $template_vars);
            
        } elseif ($primary_person) {
            // Primary person exists, no user account - send account creation link
            $token = $this->generate_claim_token($primary_person->ID);
            $create_account_url = add_query_arg('token', $token, get_permalink(get_page_by_path('claim-account')));
            
            $first_name = get_post_meta($primary_person->ID, '_lcd_person_first_name', true);
            $last_name = get_post_meta($primary_person->ID, '_lcd_person_last_name', true);
            $membership_status = get_post_meta($primary_person->ID, '_lcd_person_membership_status', true);
            
            // Add person-specific template variables
            $expiry_hours = 24; // default
            if ($this->get_email_admin()) {
                $expiry_hours = $this->get_email_admin()->get_token_expiry_hours();
            }
            
            $template_vars = array_merge($template_vars, array(
                'first_name' => $first_name,
                'last_name' => $last_name,
                'name' => trim($first_name . ' ' . $last_name),
                'email' => $email,
                'membership_status' => $membership_status ? ucfirst($membership_status) : 'Member',
                'create_account_url' => $create_account_url,
                'member_id' => $primary_person->ID,
                'token_expiry_hours' => $expiry_hours
            ));
            
            return $this->get_email_admin()->send_people_email('claim_create_account', $email, $template_vars);
            
        } else {
            // No records found - send instructions to join
            return $this->get_email_admin()->send_people_email('claim_no_records', $email, $template_vars);
        }
    }

    /**
     * Get email admin instance
     */
    public function get_email_admin() {
        if (!$this->email_admin && is_admin()) {
            $this->email_admin = LCD_People_Email_Admin::get_instance($this);
        }
        return $this->email_admin;
    }
    
    /**
     * Get opt-in handler instance
     */
    public function get_optin_handler() {
        return $this->optin_handler;
    }

    /**
     * Generate a secure token for account creation
     */
    private function generate_claim_token($person_id) {
        $token = wp_generate_password(32, false);
        
        // Get token expiry hours from email admin settings
        $expiry_hours = 24; // default
        if ($this->get_email_admin()) {
            $expiry_hours = $this->get_email_admin()->get_token_expiry_hours();
        }
        
        $expires = time() + ($expiry_hours * 60 * 60);
        
        // Store token in transient
        set_transient('lcd_claim_token_' . $token, array(
            'person_id' => $person_id,
            'expires' => $expires,
            'created' => time()
        ), $expiry_hours * 60 * 60);
        
        return $token;
    }

    /**
     * Verify claim token and return person data
     */
    private function verify_claim_token($token) {
        $token_data = get_transient('lcd_claim_token_' . $token);
        
        if (!$token_data || !isset($token_data['person_id'])) {
            return false;
        }
        
        // Check if token has expired
        if (time() > $token_data['expires']) {
            delete_transient('lcd_claim_token_' . $token);
            return false;
        }
        
        $person_id = $token_data['person_id'];
        $person = get_post($person_id);
        
        if (!$person || $person->post_type !== 'lcd_person') {
            delete_transient('lcd_claim_token_' . $token);
            return false;
        }
        
        // Verify person is still primary
        $is_primary = get_post_meta($person_id, '_lcd_person_is_primary', true);
        if ($is_primary !== '1') {
            delete_transient('lcd_claim_token_' . $token);
            return false;
        }
        
        // Return person data
        $first_name = get_post_meta($person_id, '_lcd_person_first_name', true);
        $last_name = get_post_meta($person_id, '_lcd_person_last_name', true);
        $email = get_post_meta($person_id, '_lcd_person_email', true);
        $phone = get_post_meta($person_id, '_lcd_person_phone', true);
        $membership_status = get_post_meta($person_id, '_lcd_person_membership_status', true);
        
        return array(
            'id' => $person_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'name' => trim($first_name . ' ' . $last_name),
            'email' => $email,
            'phone' => $phone,
            'membership_status' => $membership_status ? ucfirst($membership_status) : ''
        );
    }

    /**
     * Delete a used claim token
     */
    private function delete_claim_token($token) {
        delete_transient('lcd_claim_token_' . $token);
    }



    public function change_title_placeholder($title) {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'lcd_person') {
            return __('Name will be auto-generated from First and Last Name', 'lcd-people');
        }
        return $title;
    }

    public function add_name_notice() {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'lcd_person') {
            $post = get_post();
            // If a title is not yet set, show a notice
            if (empty($post->post_title)) {
                echo '<div class="notice notice-info inline"><p>' . __('The title will be automatically generated from the First and Last Name fields.', 'lcd-people') . '</p></div>';
            } else {
                // Display the title in header text
                echo '<h1>' . esc_html($post->post_title) . '</h1>';
            }
        }
    }

    public function modify_post_title($data, $postarr) {
        if ($data['post_type'] === 'lcd_person' && isset($_POST['lcd_person_first_name']) && isset($_POST['lcd_person_last_name'])) {
            $first_name = sanitize_text_field($_POST['lcd_person_first_name']);
            $last_name = sanitize_text_field($_POST['lcd_person_last_name']);
            $data['post_title'] = $first_name . ' ' . $last_name;
        }
        return $data;
    }

    public function save_meta_box_data($post_id) {
        if (!isset($_POST['lcd_person_meta_box_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['lcd_person_meta_box_nonce'], 'lcd_person_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Handle user connection/disconnection
        $old_user_id = get_post_meta($post_id, '_lcd_person_user_id', true);
        $new_user_id = isset($_POST['lcd_person_user_id']) ? sanitize_text_field($_POST['lcd_person_user_id']) : '';

        if ($old_user_id !== $new_user_id) {
            // Remove old connection if exists
            if ($old_user_id) {
                delete_user_meta($old_user_id, self::USER_META_KEY);
            }

            // Add new connection if exists
            if ($new_user_id) {
                // Check for existing connection
                $existing_person_id = $this->get_person_by_user_id($new_user_id);
                if ($existing_person_id && $existing_person_id !== $post_id) {
                    // Don't save the new user_id if there's a duplicate connection
                    return;
                }
                update_user_meta($new_user_id, self::USER_META_KEY, $post_id);
            }
        }

        $fields = array(
            'lcd_person_first_name',
            'lcd_person_last_name',
            'lcd_person_email',
            'lcd_person_phone',
            'lcd_person_address',
            'lcd_person_membership_status',
            'lcd_person_start_date',
            'lcd_person_end_date',
            'lcd_person_membership_type',
            'lcd_person_user_id',
            'lcd_person_dues_paid_via',
            'lcd_person_actblue_lineitem_id',
            'lcd_person_payment_note',
            'lcd_person_latest_volunteer_submission_id'
        );

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta(
                    $post_id,
                    '_' . $field,
                    sanitize_text_field($_POST[$field])
                );
            }
        }

        // Handle the checkbox field separately since it won't be in $_POST if unchecked
        $is_sustaining = isset($_POST['lcd_person_is_sustaining']) ? '1' : '0';
        update_post_meta($post_id, '_lcd_person_is_sustaining', $is_sustaining);

        // Handle Primary Member (Automatic Assignment)
        $email = isset($_POST['lcd_person_email']) ? sanitize_email($_POST['lcd_person_email']) : '';
        // $submitted_is_primary = isset($_POST['lcd_person_is_primary']) ? '1' : '0'; // Removed - no longer submitted
        $is_primary_final = '0'; // Default to not primary

        if (!empty($email)) {
            // Find if another primary person exists with this email
            $args = array(
                'post_type' => 'lcd_person',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'post__not_in' => array($post_id), // Exclude current post
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => '_lcd_person_email',
                        'value' => $email,
                        'compare' => '='
                    ),
                    array(
                        'key' => '_lcd_person_is_primary',
                        'value' => '1',
                        'compare' => '='
                    )
                )
            );
            $existing_primary_query = new WP_Query($args);
            $existing_primary_id = $existing_primary_query->posts ? $existing_primary_query->posts[0] : false;

            if ($existing_primary_id) {
                // Another primary exists, current post *must* be secondary
                $is_primary_final = '0';
                // Store the ID of the actual primary for display in the meta box notice
                update_post_meta($post_id, '_lcd_person_actual_primary_id', $existing_primary_id);
            } else {
                // No other primary exists for this email, this one *must* be primary
                $is_primary_final = '1';
                delete_post_meta($post_id, '_lcd_person_actual_primary_id');
            }
           
        } else {
            // No email, cannot be primary
            $is_primary_final = '0';
            delete_post_meta($post_id, '_lcd_person_actual_primary_id');
        }
        update_post_meta($post_id, '_lcd_person_is_primary', $is_primary_final);

        // If ActBlue line item ID is set, update the line item URL
        if (isset($_POST['lcd_person_actblue_lineitem_id']) && !empty($_POST['lcd_person_actblue_lineitem_id'])) {
            $lineitem_id = absint($_POST['lcd_person_actblue_lineitem_id']);
            update_post_meta($post_id, '_lcd_person_actblue_lineitem_url', 
                sprintf('https://secure.actblue.com/entities/155025/lineitems/%d', $lineitem_id)
            );
        } else {
            delete_post_meta($post_id, '_lcd_person_actblue_lineitem_url');
        }
    }

    public function ajax_check_user_connection() {
        check_ajax_referer('lcd_people_user_search', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(-1);
        }

        $user_id = intval($_GET['user_id']);
        $existing_person_id = $this->get_person_by_user_id($user_id);

        if ($existing_person_id) {
            $person = get_post($existing_person_id);
            wp_send_json(array(
                'connected' => true,
                'message' => sprintf(
                    __('This user is already connected to another person: %s', 'lcd-people'),
                    $person->post_title
                )
            ));
        } else {
            wp_send_json(array('connected' => false));
        }
    }

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

    public function handle_user_deletion($user_id) {
        // Find and update any connected person
        $person_id = $this->get_person_by_user_id($user_id);
        if ($person_id) {
            delete_post_meta($person_id, '_lcd_person_user_id');
        }
    }

    public function handle_post_deletion($post_id) {
        if (get_post_type($post_id) === 'lcd_person') {
            // Remove connection from user if exists
            $user_id = get_post_meta($post_id, '_lcd_person_user_id', true);
            if ($user_id) {
                delete_user_meta($user_id, self::USER_META_KEY);
            }
        }
    }

    public function add_user_column($columns) {
        $columns['lcd_person'] = __('Person Record', 'lcd-people');
        return $columns;
    }

    public function render_user_column($output, $column_name, $user_id) {
        if ($column_name === 'lcd_person') {
            $person_id = get_user_meta($user_id, self::USER_META_KEY, true);
            if ($person_id) {
                $person = get_post($person_id);
                if ($person) {
                    return sprintf(
                        '<a href="%s">%s</a>',
                        get_edit_post_link($person_id),
                        esc_html($person->post_title)
                    );
                }
            }
            return '—'; // Em dash for no connection
        }
        return $output;
    }
}

// Initialize the plugin
function lcd_people_init() {
    $plugin = LCD_People::get_instance();
    
    // Initialize frontend functionality
    LCD_People_Frontend_Init();
}

// Hook into WordPress early
add_action('init', 'lcd_people_init', 0);

// Register deactivation hook
register_deactivation_hook(__FILE__, array('LCD_People', 'deactivate')); 