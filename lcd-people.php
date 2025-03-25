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

class LCD_People {
    private static $instance = null;
    const USER_META_KEY = '_lcd_person_id';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
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

        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));

        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'register_rest_endpoint'));

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

        // Check and update expired memberships
        add_action('lcd_check_expired_memberships', array($this, 'check_expired_memberships'));
        register_activation_hook(__FILE__, array($this, 'schedule_membership_check'));
        register_deactivation_hook(__FILE__, array($this, 'clear_membership_check_schedule'));

        // Remove default date filter and add custom filters
        add_filter('disable_months_dropdown', array($this, 'disable_months_dropdown'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'add_admin_filters'));
        add_filter('parse_query', array($this, 'handle_admin_filters'));
        
        // Add the "Copy Emails" button to the admin list page
        add_action('manage_posts_extra_tablenav', array($this, 'add_copy_emails_button'));
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

            // Enqueue our admin script with select2 as dependency
            wp_enqueue_script(
                'lcd-people-admin',
                plugins_url('assets/js/admin.js', __FILE__),
                array('jquery', 'jquery-ui-dialog', 'select2'),
                '1.0.0',
                true
            );

            wp_localize_script('lcd-people-admin', 'lcdPeople', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('lcd_people_user_search'),
                'strings' => array(
                    'retriggerSuccess' => __('Welcome automation re-trigger attempted successfully.', 'lcd-people'),
                    'retriggerError' => __('Failed to re-trigger welcome automation:', 'lcd-people'),
                    'confirmRetrigger' => __('Are you sure you want to re-trigger the welcome automation?', 'lcd-people'),
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
                'nonce' => wp_create_nonce('lcd_people_admin'),
                'strings' => array(
                    'copySuccess' => __('Emails copied to clipboard!', 'lcd-people'),
                    'copyError' => __('Failed to copy emails:', 'lcd-people'),
                    'noEmails' => __('No email addresses found.', 'lcd-people'),
                )
            ));
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
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'people'),
            'capability_type'    => 'post',
            'has_archive'        => true,
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

    private function add_sync_record($person_id, $service, $success, $message = '') {
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

    private function sync_person_to_sender($person_id, $trigger_automation = true) {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            $this->add_sync_record($person_id, 'Sender.net', false, 'No API token configured');
            return false;
        }

        $email = get_post_meta($person_id, '_lcd_person_email', true);
        if (empty($email)) {
            $this->add_sync_record($person_id, 'Sender.net', false, 'No email address found');
            return false;
        }

        // Get current and previous membership status
        $current_status = get_post_meta($person_id, '_lcd_person_membership_status', true);
        $previous_status = get_post_meta($person_id, '_lcd_person_previous_status', true);

        // Get end date and format it for display
        $end_date = get_post_meta($person_id, '_lcd_person_end_date', true);
        $end_date_display = $end_date ? date_i18n(get_option('date_format'), strtotime($end_date)) : '';
        
        // Calculate grace period end date (30 days after end date) and format it
        $grace_period_end_date = $end_date ? date('Y-m-d', strtotime($end_date . ' +30 days')) : '';
        $grace_period_end_date_display = $grace_period_end_date ? date_i18n(get_option('date_format'), strtotime($grace_period_end_date)) : '';

        // Determine if this is a new member activation
        $is_new_activation = ($previous_status === '' || $previous_status === false) && $current_status === 'active';
        
        // First, try to get existing subscriber
        $existing_subscriber = null;
        $response = wp_remote_get('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            $this->add_sync_record($person_id, 'Sender.net', false, 'Error checking existing subscriber: ' . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 200 && isset($body['data'])) {
            $existing_subscriber = $body['data'];
        }

        // Get groups to sync
        $groups = array();
        
        // If subscriber exists, preserve their existing groups
        if ($existing_subscriber && isset($existing_subscriber['subscriber_tags'])) {
            foreach ($existing_subscriber['subscriber_tags'] as $tag) {
                $groups[] = $tag['id'];
            }
        }

        // Add new member group if this is a new activation
        if ($is_new_activation) {
            $new_member_group = get_option('lcd_people_sender_new_member_group');
            if (!empty($new_member_group) && !in_array($new_member_group, $groups) && $trigger_automation) {
                $groups[] = $new_member_group;
            }
        }

        // Get sustaining member status
        $is_sustaining = get_post_meta($person_id, '_lcd_person_is_sustaining', true);

        // Format phone number if exists
        $phone = get_post_meta($person_id, '_lcd_person_phone', true);
        if (!empty($phone)) {
            // Remove any non-digit characters
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            // Add +1 country code for US numbers if not already present
            if (strlen($phone) === 10) {
                $phone = '+1' . $phone;
            } elseif (strlen($phone) === 11 && $phone[0] === '1') {
                $phone = '+' . $phone;
            }
        }

        $subscriber_data = array(
            'email' => $email,
            'firstname' => get_post_meta($person_id, '_lcd_person_first_name', true),
            'lastname' => get_post_meta($person_id, '_lcd_person_last_name', true),
            'groups' => $groups,
            'fields' => array(
                '{$membership_status}' => $current_status,
                '{$membership_end_date}' => $end_date,
                '{$membership_end_date_display}' => $end_date_display,
                '{$grace_period_end_date_display}' => $grace_period_end_date_display,
                '{$sustaining_member}' => $is_sustaining ? 'true' : ''
            ),
            'trigger_automation' => $trigger_automation,
            'trigger_groups' => true
        );

        // Only add phone if it's properly formatted
        if (!empty($phone)) {
            $subscriber_data['phone'] = $phone;
        }

        if ($existing_subscriber) {
            // For existing subscribers, only update the fields we want to change
            $update_data = array(
                'fields' => $subscriber_data['fields'],
                'trigger_automation' => $subscriber_data['trigger_automation'],
                'trigger_groups' => $subscriber_data['trigger_groups']
            );
            
            // Only update groups if we're adding the new member group
            if ($is_new_activation) {
                $update_data['groups'] = $subscriber_data['groups'];
            }
            
            // Include basic info updates
            $update_data['firstname'] = $subscriber_data['firstname'];
            $update_data['lastname'] = $subscriber_data['lastname'];
            if (isset($subscriber_data['phone'])) {
                $update_data['phone'] = $subscriber_data['phone'];
            }

            $response = wp_remote_request('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
                'method' => 'PATCH',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($update_data)
            ));
        } else {
            $response = wp_remote_post('https://api.sender.net/v2/subscribers', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($subscriber_data)
            ));
        }

        if (is_wp_error($response)) {
            $this->add_sync_record($person_id, 'Sender.net', false, 'Sync failed: ' . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 200 || $status === 201) {
            $this->add_sync_record($person_id, 'Sender.net', true);
            
            // Store the current status as previous for next time
            update_post_meta($person_id, '_lcd_person_previous_status', $current_status);
            
            return true;
        } else {
            $message = isset($body['message']) ? $body['message'] : 'Unknown error';
            $this->add_sync_record($person_id, 'Sender.net', false, 'Sync failed. Status: ' . $status . ', Message: ' . $message);
            return false;
        }
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
            'lcd_person_payment_note'
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

        // Existing ActBlue settings...

        // Sender.net settings
        register_setting('lcd_people_sender_settings', 'lcd_people_sender_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lcd_people_sender_settings', 'lcd_people_sender_new_member_group', array(
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
            'lcd_people_sender_new_member_group',
            __('New Member Group ID', 'lcd-people'),
            array($this, 'render_new_member_group_field'),
            'lcd-people-sender-settings',
            'lcd_people_sender_section'
        );
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
            if (isset($_POST['test_sender_connection']) && check_admin_referer('test_sender_connection', 'test_sender_nonce')) {
                $this->test_sender_connection($_POST['test_email'], $_POST['test_firstname'], $_POST['test_lastname']);
            }
            ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('lcd_people_actblue_settings');
                do_settings_sections('lcd-people-actblue-settings');
                submit_button();
                ?>
            </form>

            <hr>

            <h2><?php _e('Test Connection', 'lcd-people'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('test_sender_connection', 'test_sender_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="test_email"><?php _e('Test Email', 'lcd-people'); ?></label></th>
                        <td>
                            <input type="email" name="test_email" id="test_email" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_firstname"><?php _e('Test First Name', 'lcd-people'); ?></label></th>
                        <td>
                            <input type="text" name="test_firstname" id="test_firstname" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_lastname"><?php _e('Test Last Name', 'lcd-people'); ?></label></th>
                        <td>
                            <input type="text" name="test_lastname" id="test_lastname" class="regular-text" required>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Test Connection', 'lcd-people'), 'secondary', 'test_sender_connection'); ?>
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

    public function render_new_member_group_field() {
        $group_id = get_option('lcd_people_sender_new_member_group');
        ?>
        <input type="text" name="lcd_people_sender_new_member_group" value="<?php echo esc_attr($group_id); ?>" class="regular-text">
        <p class="description"><?php _e('The Sender.net group ID to add new members to (e.g. dw2kEJ)', 'lcd-people'); ?></p>
        <?php
    }

    public function render_sender_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
            if (isset($_POST['test_sender_connection']) && check_admin_referer('test_sender_connection', 'test_sender_nonce')) {
                $this->test_sender_connection(
                    $_POST['test_email'], 
                    $_POST['test_firstname'], 
                    $_POST['test_lastname'],
                    $_POST['test_previous_status'],
                    $_POST['test_new_status']
                );
            }
            settings_errors('lcd_people_sender_settings');
            ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('lcd_people_sender_settings');
                do_settings_sections('lcd-people-sender-settings');
                submit_button();
                ?>
            </form>

            <hr>

            <h2><?php _e('Test Connection', 'lcd-people'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('test_sender_connection', 'test_sender_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="test_email"><?php _e('Test Email', 'lcd-people'); ?></label></th>
                        <td>
                            <input type="email" name="test_email" id="test_email" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_firstname"><?php _e('Test First Name', 'lcd-people'); ?></label></th>
                        <td>
                            <input type="text" name="test_firstname" id="test_firstname" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_lastname"><?php _e('Test Last Name', 'lcd-people'); ?></label></th>
                        <td>
                            <input type="text" name="test_lastname" id="test_lastname" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_previous_status"><?php _e('Previous Status', 'lcd-people'); ?></label></th>
                        <td>
                            <select name="test_previous_status" id="test_previous_status">
                                <option value=""><?php _e('Not a Member', 'lcd-people'); ?></option>
                                <option value="active"><?php _e('Active', 'lcd-people'); ?></option>
                                <option value="inactive"><?php _e('Inactive', 'lcd-people'); ?></option>
                                <option value="grace"><?php _e('Grace Period', 'lcd-people'); ?></option>
                            </select>
                            <p class="description"><?php _e('Simulate previous membership status', 'lcd-people'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="test_new_status"><?php _e('New Status', 'lcd-people'); ?></label></th>
                        <td>
                            <select name="test_new_status" id="test_new_status">
                                <option value=""><?php _e('Not a Member', 'lcd-people'); ?></option>
                                <option value="active"><?php _e('Active', 'lcd-people'); ?></option>
                                <option value="inactive"><?php _e('Inactive', 'lcd-people'); ?></option>
                                <option value="grace"><?php _e('Grace Period', 'lcd-people'); ?></option>
                            </select>
                            <p class="description"><?php _e('Simulate new membership status', 'lcd-people'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Test Connection', 'lcd-people'), 'secondary', 'test_sender_connection'); ?>
            </form>
        </div>
        <?php
    }

    private function test_sender_connection($email, $firstname, $lastname, $previous_status = '', $new_status = '') {
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            add_settings_error(
                'lcd_people_sender_settings',
                'sender_test_failed',
                __('API Token is required.', 'lcd-people'),
                'error'
            );
            return;
        }

        $log = array();
        $log[] = __('Starting test connection...', 'lcd-people');
        $log[] = sprintf(__('Looking for existing subscriber with email: %s', 'lcd-people'), $email);

        // First, try to get existing subscriber
        $existing_subscriber = null;
        $response = wp_remote_get('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            $log[] = sprintf(__('Error checking for existing subscriber: %s', 'lcd-people'), $response->get_error_message());
            add_settings_error(
                'lcd_people_sender_settings',
                'sender_test_failed',
                implode('<br>', $log),
                'error'
            );
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 200 && isset($body['data'])) {
            $existing_subscriber = $body['data'];
            $log[] = __('Existing subscriber found', 'lcd-people');
            if (!empty($existing_subscriber['subscriber_tags'])) {
                $group_names = array_map(function($tag) {
                    return $tag['title'];
                }, $existing_subscriber['subscriber_tags']);
                $log[] = sprintf(__('Current groups: %s', 'lcd-people'), implode(', ', $group_names));
            }
        } else {
            $log[] = __('No existing subscriber found, will create new', 'lcd-people');
        }

        // Get groups to sync
        $groups = array();
        if ($existing_subscriber && isset($existing_subscriber['subscriber_tags'])) {
            foreach ($existing_subscriber['subscriber_tags'] as $tag) {
                $groups[] = $tag['id'];
            }
        }

        // Check if this is a new member activation
        $is_new_activation = ($previous_status === '' || $previous_status === false) && $new_status === 'active';
        if ($is_new_activation) {
            $log[] = __('Detected new member activation', 'lcd-people');
            
            // Add test group if configured
            $new_member_group = get_option('lcd_people_sender_new_member_group');
            if (!empty($new_member_group) && !in_array($new_member_group, $groups)) {
                $groups[] = $new_member_group;
                $log[] = sprintf(__('Adding new member group ID: %s', 'lcd-people'), $new_member_group);
            }
        } else {
            $log[] = sprintf(
                __('Status transition: %s → %s (not a new activation)', 'lcd-people'),
                $previous_status ? $previous_status : 'none',
                $new_status ? $new_status : 'none'
            );
        }

        $subscriber_data = array(
            'email' => sanitize_email($email),
            'firstname' => sanitize_text_field($firstname),
            'lastname' => sanitize_text_field($lastname),
            'groups' => $groups,
            'fields' => array(
                '{$membership_status}' => $new_status,
                '{$membership_end_date}' => date('Y-m-d', strtotime('+1 year')),
                '{$sustaining_member}' => 'true'
            )
        );

        $log[] = __('Preparing to sync test data...', 'lcd-people');

        if ($existing_subscriber) {
            $log[] = __('Updating existing subscriber...', 'lcd-people');
            $response = wp_remote_request('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
                'method' => 'PATCH',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($subscriber_data)
            ));
        } else {
            $log[] = __('Creating new subscriber...', 'lcd-people');
            $response = wp_remote_post('https://api.sender.net/v2/subscribers', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($subscriber_data)
            ));
        }

        if (is_wp_error($response)) {
            $log[] = sprintf(__('Error syncing subscriber: %s', 'lcd-people'), $response->get_error_message());
            add_settings_error(
                'lcd_people_sender_settings',
                'sender_test_failed',
                implode('<br>', $log),
                'error'
            );
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 200 || $status === 201) {
            $log[] = __('Sync completed successfully!', 'lcd-people');
            if (isset($body['data'])) {
                $log[] = sprintf(
                    __('Subscriber ID: %s', 'lcd-people'),
                    $body['data']['id']
                );
            }
            add_settings_error(
                'lcd_people_sender_settings',
                'sender_test_success',
                implode('<br>', $log),
                'success'
            );
        } else {
            $log[] = sprintf(
                __('Sync failed. Status: %d, Message: %s', 'lcd-people'),
                $status,
                isset($body['message']) ? $body['message'] : __('Unknown error', 'lcd-people')
            );
            add_settings_error(
                'lcd_people_sender_settings',
                'sender_test_failed',
                implode('<br>', $log),
                'error'
            );
        }
    }

    public function handle_person_sync($person_id) {
        $this->sync_person_to_sender($person_id);
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
        $this->save_meta_box_data($post_id);

        // Store the previous status for next time
        update_post_meta($post_id, '_lcd_person_previous_status', $previous_status);

        // Sync to Sender.net - disable automation triggers for admin updates
        $sync_result = $this->sync_person_to_sender($post_id, false);

        if (!$sync_result) {
            $this->add_sync_record($post_id, 'Save Handler', false, 'Failed to sync to Sender.net after save');
        }
    }

    /**
     * Register REST API endpoint
     */
    public function register_rest_endpoint() {
        register_rest_route('lcd-people/v1', '/actblue-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_actblue_webhook'),
            'permission_callback' => array($this, 'verify_webhook_auth')
        ));
    }

    /**
     * Verify webhook authentication
     */
    public function verify_webhook_auth($request) {
        $auth_header = $request->get_header('authorization');
        if (!$auth_header || strpos($auth_header, 'Basic ') !== 0) {
            return new WP_Error(
                'rest_forbidden',
                __('Missing or invalid authentication header.', 'lcd-people'),
                array('status' => 401)
            );
        }

        $auth = substr($auth_header, 6);
        $credentials = base64_decode($auth);
        list($username, $password) = explode(':', $credentials);

        $stored_username = get_option('lcd_people_actblue_username');
        $stored_password = get_option('lcd_people_actblue_password');

        if ($username !== $stored_username || $password !== $stored_password) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid credentials.', 'lcd-people'),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Handle ActBlue webhook
     */
    public function handle_actblue_webhook($request) {
        $params = $request->get_json_params();
        
        // Check if this is a dues payment
        $dues_form = get_option('lcd_people_actblue_dues_form', 'lcdcc-dues');
        if (empty($params['contribution']['contributionForm']) || 
            $params['contribution']['contributionForm'] !== $dues_form) {
            return array(
                'success' => true,
                'message' => 'Ignored - not a dues payment'
            );
        }

        // Validate required fields
        if (empty($params['donor']) || empty($params['donor']['email'])) {
            return new WP_Error(
                'missing_email',
                __('Donor email is required.', 'lcd-people'),
                array('status' => 400)
            );
        }

        $donor = $params['donor'];
        $contribution = $params['contribution'];
        $lineitems = $params['lineitems'][0]; // We'll use the first lineitem

        // Try to find existing person by email
        $person = $this->get_person_by_email($donor['email']);
        
        if ($person) {
            // Update existing person
            $this->update_person_from_actblue($person->ID, $donor, $contribution, $lineitems);
            $this->add_sync_record($person->ID, 'ActBlue', true, 'Person updated successfully');
            $response_message = 'Person updated successfully';
        } else {
            // Create new person
            $person_id = $this->create_person_from_actblue($donor, $contribution, $lineitems);
            if (is_wp_error($person_id)) {
                return $person_id;
            }
            $this->add_sync_record($person_id, 'ActBlue', true, 'New person created successfully');
            $response_message = 'New person created successfully';
        }

        return array(
            'success' => true,
            'message' => $response_message
        );
    }

    /**
     * Get person by email
     */
    private function get_person_by_email($email) {
        $args = array(
            'post_type' => 'lcd_person',
            'meta_key' => '_lcd_person_email',
            'meta_value' => $email,
            'posts_per_page' => 1
        );

        $query = new WP_Query($args);
        return $query->posts ? $query->posts[0] : null;
    }

    /**
     * Update person from ActBlue data
     */
    private function update_person_from_actblue($person_id, $donor, $contribution, $lineitem) {
        // Check if person has a linked WordPress user
        $user_id = get_post_meta($person_id, '_lcd_person_user_id', true);
        
        if (!$user_id) {
            // Check if a WordPress user with this email exists
            $existing_user = get_user_by('email', $donor['email']);
            
            if ($existing_user) {
                $user_id = $existing_user->ID;
            } else {
                // Create new WordPress user
                $user_data = array(
                    'user_login' => $donor['email'],
                    'user_email' => $donor['email'],
                    'user_pass'  => wp_generate_password(),
                    'first_name' => $donor['firstname'],
                    'last_name'  => $donor['lastname'],
                    'display_name' => $donor['firstname'] . ' ' . $donor['lastname'],
                    'role' => 'subscriber'
                );

                // Disable new user notification email
                add_filter('send_email_change_email', '__return_false');
                add_filter('send_password_change_email', '__return_false');
                remove_action('register_new_user', 'wp_send_new_user_notifications');
                remove_action('edit_user_created_user', 'wp_send_new_user_notifications');

                // Create the user
                $user_id = wp_insert_user($user_data);

                // Remove our filters
                remove_filter('send_email_change_email', '__return_false');
                remove_filter('send_password_change_email', '__return_false');
                add_action('register_new_user', 'wp_send_new_user_notifications');
                add_action('edit_user_created_user', 'wp_send_new_user_notifications');

                if (is_wp_error($user_id)) {
                    error_log('Failed to create WordPress user for existing ActBlue donor: ' . $user_id->get_error_message());
                    $user_id = null;
                }
            }

            // Link the WordPress user to the person if we have a valid user
            if ($user_id && !is_wp_error($user_id)) {
                update_post_meta($person_id, '_lcd_person_user_id', $user_id);
                update_user_meta($user_id, self::USER_META_KEY, $person_id);
            }
        }

        // Update membership status to active
        update_post_meta($person_id, '_lcd_person_membership_status', 'active');
        
        // Set membership type to paid
        update_post_meta($person_id, '_lcd_person_membership_type', 'paid');
        
        // Set payment method to ActBlue
        update_post_meta($person_id, '_lcd_person_dues_paid_via', 'actblue');
        
        // Get current date in site's timezone
        $current_date = current_time('Y-m-d');
        
        // Update start date if not already set
        $start_date = get_post_meta($person_id, '_lcd_person_start_date', true);
        if (empty($start_date)) {
            update_post_meta($person_id, '_lcd_person_start_date', $current_date);
        }

        // Set end date to one year from now
        $end_date = date('Y-m-d', strtotime($current_date . ' +1 year'));
        update_post_meta($person_id, '_lcd_person_end_date', $end_date);

        // Store the ActBlue lineitem URL for reference
        if (!empty($lineitem['lineitemId'])) {
            update_post_meta($person_id, '_lcd_person_actblue_lineitem_id', $lineitem['lineitemId']);
            update_post_meta($person_id, '_lcd_person_actblue_lineitem_url', 
                sprintf('https://secure.actblue.com/entities/155025/lineitems/%d', $lineitem['lineitemId'])
            );
        }

        // Update contact information if provided
        if (!empty($donor['phone'])) {
            update_post_meta($person_id, '_lcd_person_phone', $donor['phone']);
        }

        // Combine address fields
        if (!empty($donor['addr1'])) {
            $address = $donor['addr1'];
            if (!empty($donor['city'])) $address .= "\n" . $donor['city'];
            if (!empty($donor['state'])) $address .= ', ' . $donor['state'];
            if (!empty($donor['zip'])) $address .= ' ' . $donor['zip'];
            if (!empty($donor['country']) && $donor['country'] !== 'United States') $address .= "\n" . $donor['country'];
            
            update_post_meta($person_id, '_lcd_person_address', $address);
        }

        do_action('lcd_person_actblue_updated', $person_id, $donor, $contribution, $lineitem);
    }

    /**
     * Create person from ActBlue data
     */
    private function create_person_from_actblue($donor, $contribution, $lineitem) {
        // First check if a WordPress user with this email already exists
        $existing_user = get_user_by('email', $donor['email']);
        
        if ($existing_user) {
            $user_id = $existing_user->ID;
        } else {
            // Create new WordPress user
            $user_data = array(
                'user_login' => $donor['email'],
                'user_email' => $donor['email'],
                'user_pass'  => wp_generate_password(),
                'first_name' => $donor['firstname'],
                'last_name'  => $donor['lastname'],
                'display_name' => $donor['firstname'] . ' ' . $donor['lastname'],
                'role' => 'subscriber'
            );

            // Disable new user notification email
            add_filter('send_email_change_email', '__return_false');
            add_filter('send_password_change_email', '__return_false');
            remove_action('register_new_user', 'wp_send_new_user_notifications');
            remove_action('edit_user_created_user', 'wp_send_new_user_notifications');

            // Create the user
            $user_id = wp_insert_user($user_data);

            // Remove our filters
            remove_filter('send_email_change_email', '__return_false');
            remove_filter('send_password_change_email', '__return_false');
            add_action('register_new_user', 'wp_send_new_user_notifications');
            add_action('edit_user_created_user', 'wp_send_new_user_notifications');

            if (is_wp_error($user_id)) {
                error_log('Failed to create WordPress user for ActBlue donor: ' . $user_id->get_error_message());
                // Continue with person creation even if user creation fails
                $user_id = null;
            }
        }

        // Create post
        $post_data = array(
            'post_title'  => $donor['firstname'] . ' ' . $donor['lastname'],
            'post_type'   => 'lcd_person',
            'post_status' => 'publish'
        );

        $person_id = wp_insert_post($post_data);

        if (is_wp_error($person_id)) {
            return $person_id;
        }

        // Get current date in site's timezone
        $current_date = current_time('Y-m-d');
        
        // Set meta data
        update_post_meta($person_id, '_lcd_person_first_name', $donor['firstname']);
        update_post_meta($person_id, '_lcd_person_last_name', $donor['lastname']);
        update_post_meta($person_id, '_lcd_person_email', $donor['email']);
        update_post_meta($person_id, '_lcd_person_membership_status', 'active');
        update_post_meta($person_id, '_lcd_person_membership_type', 'paid');
        update_post_meta($person_id, '_lcd_person_dues_paid_via', 'actblue');
        update_post_meta($person_id, '_lcd_person_start_date', $current_date);
        update_post_meta($person_id, '_lcd_person_end_date', date('Y-m-d', strtotime($current_date . ' +1 year')));

        // Link the WordPress user to the person if we have a valid user
        if ($user_id && !is_wp_error($user_id)) {
            update_post_meta($person_id, '_lcd_person_user_id', $user_id);
            update_user_meta($user_id, self::USER_META_KEY, $person_id);
        }

        // Store the ActBlue lineitem URL for reference
        if (!empty($lineitem['lineitemId'])) {
            update_post_meta($person_id, '_lcd_person_actblue_lineitem_id', $lineitem['lineitemId']);
            update_post_meta($person_id, '_lcd_person_actblue_lineitem_url', 
                sprintf('https://secure.actblue.com/entities/155025/lineitems/%d', $lineitem['lineitemId'])
            );
        }

        if (!empty($donor['phone'])) {
            update_post_meta($person_id, '_lcd_person_phone', $donor['phone']);
        }

        // Combine address fields
        if (!empty($donor['addr1'])) {
            $address = $donor['addr1'];
            if (!empty($donor['city'])) $address .= "\n" . $donor['city'];
            if (!empty($donor['state'])) $address .= ', ' . $donor['state'];
            if (!empty($donor['zip'])) $address .= ' ' . $donor['zip'];
            if (!empty($donor['country']) && $donor['country'] !== 'United States') $address .= "\n" . $donor['country'];
            
            update_post_meta($person_id, '_lcd_person_address', $address);
        }

        do_action('lcd_person_actblue_created', $person_id, $donor, $contribution, $lineitem);

        return $person_id;
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
        $sync_result = $this->sync_person_to_sender($person_id);

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

        // Get the new member group ID
        $new_member_group = get_option('lcd_people_sender_new_member_group');
        if (empty($new_member_group)) {
            wp_send_json_error(array('message' => __('New member group ID not configured.', 'lcd-people')));
        }

        // Force remove and re-add the new member group
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            wp_send_json_error(array('message' => __('Sender.net API token not configured.', 'lcd-people')));
        }

        $email = get_post_meta($person_id, '_lcd_person_email', true);
        if (empty($email)) {
            wp_send_json_error(array('message' => __('No email address found for this person.', 'lcd-people')));
        }

        // First, get current subscriber data
        $response = wp_remote_get('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => __('Failed to get subscriber data:', 'lcd-people') . ' ' . $response->get_error_message()));
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || !isset($body['data'])) {
            wp_send_json_error(array('message' => __('Subscriber not found in Sender.net', 'lcd-people')));
        }

        $subscriber = $body['data'];
        $groups = array();
        
        // Get current groups, excluding the new member group
        if (isset($subscriber['subscriber_tags'])) {
            foreach ($subscriber['subscriber_tags'] as $tag) {
                if ($tag['id'] !== $new_member_group) {
                    $groups[] = $tag['id'];
                }
            }
        }

        // Add the new member group back
        $groups[] = $new_member_group;

        // Update subscriber with modified groups
        $subscriber_data = array(
            'groups' => $groups,
            'trigger_automation' => true,
            'trigger_groups' => true
        );

        $response = wp_remote_request('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($subscriber_data)
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => __('Failed to update subscriber:', 'lcd-people') . ' ' . $response->get_error_message()));
        }

        $status = wp_remote_retrieve_response_code($response);
        
        if ($status === 200) {
            $this->add_sync_record($person_id, 'Sender.net', true, 'Welcome automation re-trigger attempted');
            wp_send_json_success(array('message' => __('Welcome automation re-trigger attempted successfully.', 'lcd-people')));
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $message = isset($body['message']) ? $body['message'] : __('Unknown error', 'lcd-people');
            $this->add_sync_record($person_id, 'Sender.net', false, 'Failed to re-trigger welcome automation: ' . $message);
            wp_send_json_error(array('message' => __('Failed to update subscriber. Status:', 'lcd-people') . ' ' . $status . ', ' . __('Message:', 'lcd-people') . ' ' . $message));
        }
    }

    /**
     * Check and update expired memberships
     */
    public function check_expired_memberships() {
        // Get all active members
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_lcd_person_membership_status',
                    'value' => 'active',
                    'compare' => '='
                )
            )
        );

        $members = get_posts($args);

        foreach ($members as $member) {
            $end_date = get_post_meta($member->ID, '_lcd_person_end_date', true);
            
            // Skip if no end date
            if (empty($end_date)) {
                continue;
            }

            // Check if membership has expired
            $today = date('Y-m-d');
            if ($end_date < $today) {
                // Update membership status to expired
                update_post_meta($member->ID, '_lcd_person_membership_status', 'expired');
                
                // Store previous status for sync
                update_post_meta($member->ID, '_lcd_person_previous_status', 'active');
                
                // Sync to Sender.net
                $this->sync_person_to_sender($member->ID);
                
                // Add sync record
                $this->add_sync_record($member->ID, 'Membership Expiration', true, 'Membership expired and status updated');
            }
        }
    }

    /**
     * Schedule the daily membership check
     */
    public function schedule_membership_check() {
        if (!wp_next_scheduled('lcd_check_expired_memberships')) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'lcd_check_expired_memberships');
        }
    }

    /**
     * Clear the scheduled membership check
     */
    public function clear_membership_check_schedule() {
        wp_clear_scheduled_hook('lcd_check_expired_memberships');
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

    public function add_copy_emails_button($which) {
        global $typenow;
        
        // Only add button on our custom post type and at the top of the list
        if ($typenow !== 'lcd_person' || $which !== 'top') {
            return;
        }
        
        echo '<div class="alignleft actions">';
        echo '<button type="button" id="lcd-copy-emails-button" class="button">' . __('Copy Emails', 'lcd-people') . '</button>';
        echo '</div>';
    }
}

// Initialize the plugin
function lcd_people_init() {
    LCD_People::get_instance();
}
add_action('plugins_loaded', 'lcd_people_init');

// Register deactivation hook
register_deactivation_hook(__FILE__, array('LCD_People', 'deactivate')); 