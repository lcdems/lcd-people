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
    }

    public function enqueue_admin_scripts($hook) {
        global $post;

        // Only enqueue on person edit screen
        if ($hook == 'post-new.php' || $hook == 'post.php') {
            if (isset($post) && $post->post_type === 'lcd_person') {
                // Enqueue Select2
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

                wp_enqueue_script(
                    'lcd-people-admin',
                    plugins_url('assets/js/admin.js', __FILE__),
                    array('jquery', 'select2'),
                    '1.0.0',
                    true
                );

                wp_localize_script('lcd-people-admin', 'lcdPeople', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('lcd_people_user_search'),
                ));
            }
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
        <style>
            .lcd-user-connection {
                margin-bottom: 10px;
            }
            .lcd-disconnect-user {
                color: #a00;
                text-decoration: none;
                margin-left: 10px;
            }
            .lcd-disconnect-user:hover {
                color: #dc3232;
            }
        </style>
        <?php
    }

    public function render_membership_meta_box($post) {
        $membership_status = get_post_meta($post->ID, '_lcd_person_membership_status', true);
        $start_date = get_post_meta($post->ID, '_lcd_person_start_date', true);
        $end_date = get_post_meta($post->ID, '_lcd_person_end_date', true);
        $membership_type = get_post_meta($post->ID, '_lcd_person_membership_type', true);
        $is_sustaining = get_post_meta($post->ID, '_lcd_person_is_sustaining', true);
        $dues_paid_via = get_post_meta($post->ID, '_lcd_person_dues_paid_via', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="lcd_person_membership_status"><?php _e('Membership Status', 'lcd-people'); ?></label></th>
                <td>
                    <select id="lcd_person_membership_status" name="lcd_person_membership_status">
                        <option value=""><?php _e('Not a Member', 'lcd-people'); ?></option>
                        <option value="active" <?php selected($membership_status, 'active'); ?>><?php _e('Active', 'lcd-people'); ?></option>
                        <option value="inactive" <?php selected($membership_status, 'inactive'); ?>><?php _e('Inactive', 'lcd-people'); ?></option>
                        <option value="grace" <?php selected($membership_status, 'grace'); ?>><?php _e('Grace Period', 'lcd-people'); ?></option>
                    </select>
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
                <th><label for="lcd_person_start_date"><?php _e('Start Date', 'lcd-people'); ?></label></th>
                <td>
                    <input type="date" id="lcd_person_start_date" name="lcd_person_start_date" value="<?php echo esc_attr($start_date); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="lcd_person_end_date"><?php _e('End Date', 'lcd-people'); ?></label></th>
                <td>
                    <input type="date" id="lcd_person_end_date" name="lcd_person_end_date" value="<?php echo esc_attr($end_date); ?>">
                </td>
            </tr>
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
                <th><label for="lcd_person_dues_paid_via"><?php _e('Dues paid via', 'lcd-people'); ?></label></th>
                <td>
                    <select id="lcd_person_dues_paid_via" name="lcd_person_dues_paid_via">
                        <option value=""><?php _e('None', 'lcd-people'); ?></option>
                        <option value="actblue" <?php selected($dues_paid_via, 'actblue'); ?>><?php _e('ActBlue', 'lcd-people'); ?></option>
                        <option value="cash" <?php selected($dues_paid_via, 'cash'); ?>><?php _e('Cash', 'lcd-people'); ?></option>
                        <option value="check" <?php selected($dues_paid_via, 'check'); ?>><?php _e('Check', 'lcd-people'); ?></option>
                        <option value="transfer" <?php selected($dues_paid_via, 'transfer'); ?>><?php _e('Transfer', 'lcd-people'); ?></option>
                        <option value="in-kind" <?php selected($dues_paid_via, 'in-kind'); ?>><?php _e('In-Kind', 'lcd-people'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
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
            'lcd_person_dues_paid_via'
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
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            __('ActBlue Integration Settings', 'lcd-people'),
            __('ActBlue Settings', 'lcd-people'),
            'manage_options',
            'lcd-people-actblue-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
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

        add_settings_section(
            'lcd_people_actblue_section',
            __('ActBlue Integration Settings', 'lcd-people'),
            array($this, 'render_settings_section'),
            'lcd-people-actblue-settings'
        );

        add_settings_field(
            'lcd_people_webhook_url',
            __('Webhook URL', 'lcd-people'),
            array($this, 'render_webhook_url_field'),
            'lcd-people-actblue-settings',
            'lcd_people_actblue_section'
        );

        add_settings_field(
            'lcd_people_actblue_username',
            __('Username', 'lcd-people'),
            array($this, 'render_username_field'),
            'lcd-people-actblue-settings',
            'lcd_people_actblue_section'
        );

        add_settings_field(
            'lcd_people_actblue_password',
            __('Password', 'lcd-people'),
            array($this, 'render_password_field'),
            'lcd-people-actblue-settings',
            'lcd_people_actblue_section'
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
            <form action="options.php" method="post">
                <?php
                settings_fields('lcd_people_actblue_settings');
                do_settings_sections('lcd-people-actblue-settings');
                submit_button();
                ?>
            </form>
        </div>
        <style>
            .password-toggle-wrapper {
                position: relative;
                display: inline-block;
            }
            .password-toggle-wrapper .dashicons {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                cursor: pointer;
                color: #666;
            }
            .password-toggle-wrapper input[type="password"],
            .password-toggle-wrapper input[type="text"] {
                padding-right: 30px;
            }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('.toggle-password').click(function() {
                var input = $(this).prev('input');
                var icon = $(this);
                
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    input.attr('type', 'password');
                    icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render the settings section description
     */
    public function render_settings_section($args) {
        ?>
        <p><?php _e('Configure the credentials for the ActBlue webhook integration. These credentials will be used to authenticate incoming webhook requests.', 'lcd-people'); ?></p>
        <?php
    }

    /**
     * Render the webhook URL field
     */
    public function render_webhook_url_field($args) {
        $webhook_url = rest_url('lcd-people/v1/actblue-webhook');
        ?>
        <input type="text" value="<?php echo esc_attr($webhook_url); ?>" class="regular-text" readonly onclick="this.select();">
        <p class="description"><?php _e('Configure this URL in ActBlue to receive webhook notifications. Click to select for copying.', 'lcd-people'); ?></p>
        <?php
    }

    /**
     * Render the username field
     */
    public function render_username_field($args) {
        $username = get_option('lcd_people_actblue_username');
        ?>
        <input type="text" name="lcd_people_actblue_username" value="<?php echo esc_attr($username); ?>" class="regular-text">
        <p class="description"><?php _e('Username for authenticating ActBlue webhook requests', 'lcd-people'); ?></p>
        <?php
    }

    /**
     * Render the password field
     */
    public function render_password_field($args) {
        $password = get_option('lcd_people_actblue_password');
        ?>
        <div class="password-toggle-wrapper">
            <input type="password" name="lcd_people_actblue_password" value="<?php echo esc_attr($password); ?>" class="regular-text">
            <span class="dashicons dashicons-visibility toggle-password"></span>
        </div>
        <p class="description"><?php _e('Password for authenticating ActBlue webhook requests', 'lcd-people'); ?></p>
        <?php
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
        
        // Log the incoming webhook data
        error_log('ActBlue Webhook received: ' . print_r($params, true));

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
            $response_message = 'Person updated successfully';
        } else {
            // Create new person
            $person_id = $this->create_person_from_actblue($donor, $contribution, $lineitems);
            if (is_wp_error($person_id)) {
                return $person_id;
            }
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
        // Update membership status to active
        update_post_meta($person_id, '_lcd_person_membership_status', 'active');
        
        // Set membership type to paid
        update_post_meta($person_id, '_lcd_person_membership_type', 'paid');
        
        // Set payment method to ActBlue
        update_post_meta($person_id, '_lcd_person_dues_paid_via', 'actblue');

        // Set sustaining member status based on recurring contribution
        $is_recurring = !empty($contribution['recurringPeriod']);
        update_post_meta($person_id, '_lcd_person_is_sustaining', $is_recurring ? '1' : '0');
        
        // Update start date if not already set
        $start_date = get_post_meta($person_id, '_lcd_person_start_date', true);
        if (empty($start_date)) {
            update_post_meta($person_id, '_lcd_person_start_date', date('Y-m-d'));
        }

        // Set end date to one year from now
        $end_date = date('Y-m-d', strtotime('+1 year'));
        update_post_meta($person_id, '_lcd_person_end_date', $end_date);

        // Store the ActBlue lineitem URL for reference
        if (!empty($lineitem['lineitemId'])) {
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
        // First create the WordPress user
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

        // Set meta data
        update_post_meta($person_id, '_lcd_person_first_name', $donor['firstname']);
        update_post_meta($person_id, '_lcd_person_last_name', $donor['lastname']);
        update_post_meta($person_id, '_lcd_person_email', $donor['email']);
        update_post_meta($person_id, '_lcd_person_membership_status', 'active');
        update_post_meta($person_id, '_lcd_person_membership_type', 'paid');
        update_post_meta($person_id, '_lcd_person_dues_paid_via', 'actblue');
        update_post_meta($person_id, '_lcd_person_start_date', date('Y-m-d'));
        update_post_meta($person_id, '_lcd_person_end_date', date('Y-m-d', strtotime('+1 year')));

        // Link the WordPress user to the person if user was created successfully
        if (!is_wp_error($user_id)) {
            update_post_meta($person_id, '_lcd_person_user_id', $user_id);
            update_user_meta($user_id, self::USER_META_KEY, $person_id);
        }

        // Set sustaining member status based on recurring contribution
        $is_recurring = !empty($contribution['recurringPeriod']);
        update_post_meta($person_id, '_lcd_person_is_sustaining', $is_recurring ? '1' : '0');

        // Store the ActBlue lineitem URL for reference
        if (!empty($lineitem['lineitemId'])) {
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
}

// Initialize the plugin
function lcd_people_init() {
    LCD_People::get_instance();
}
add_action('plugins_loaded', 'lcd_people_init'); 