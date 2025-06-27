<?php
/**
 * ActBlue Webhook Handler for LCD People Plugin
 * 
 * Handles ActBlue webhook processing and person record creation/updates
 * 
 * @package LCD_People
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_People_ActBlue_Handler {
    
    /**
     * Main plugin instance
     * @var LCD_People
     */
    private $main_plugin;
    
    /**
     * User meta key constant
     * @var string
     */
    const USER_META_KEY = '_lcd_person_id';
    
    /**
     * Constructor
     * 
     * @param LCD_People $main_plugin Main plugin instance
     */
    public function __construct($main_plugin) {
        $this->main_plugin = $main_plugin;
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

        // Try to find existing person using a better strategy:
        // 1. First try exact name + email match
        $person = $this->get_person_by_email($donor['email'], $donor['firstname'], $donor['lastname']);
        
        // 2. If no exact match, try to find the primary person for this email
        if (!$person) {
            $person = $this->get_primary_person_by_email($donor['email']);
        }
        
        // 3. If still no match, look for any person with this email (for shared emails)
        if (!$person) {
            $person = $this->get_person_by_email($donor['email']);
        }
        
        if ($person) {
            // Update existing person
            $this->update_person_from_actblue($person->ID, $donor, $contribution, $lineitems);
            $this->add_sync_record($person->ID, 'ActBlue', true, 'Person updated successfully');
            $response_message = 'Person updated successfully (ID: ' . $person->ID . ')';
            
            // Fix any duplicate primary status issues for this email
            $this->fix_duplicate_primary_status($donor['email']);
        } else {
            // Create new person only if absolutely no record exists for this email
            $person_id = $this->create_person_from_actblue($donor, $contribution, $lineitems);
            if (is_wp_error($person_id)) {
                return $person_id;
            }
            $this->add_sync_record($person_id, 'ActBlue', true, 'New person created successfully');
            $response_message = 'New person created successfully (ID: ' . $person_id . ')';
            
            // Fix any duplicate primary status issues for this email (shouldn't be needed for new, but just in case)
            $this->fix_duplicate_primary_status($donor['email']);
        }

        return array(
            'success' => true,
            'message' => $response_message
        );
    }
    
    /**
     * Get person by email (and optionally first/last name for disambiguation)
     */
    public function get_person_by_email($email, $first_name = null, $last_name = null) {
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
     * Update person from ActBlue data
     */
    public function update_person_from_actblue($person_id, $donor, $contribution, $lineitem) {
        // Check if person has a linked WordPress user
        $user_id = get_post_meta($person_id, '_lcd_person_user_id', true);
        
        if (!$user_id) {
            // Check if a WordPress user with this email exists
            $existing_user = get_user_by('email', $donor['email']);
            
            if ($existing_user) {
                $user_id = $existing_user->ID;
                
                // Check if this user is already linked to another person
                $existing_person_id = get_user_meta($user_id, self::USER_META_KEY, true);
                
                if (empty($existing_person_id)) {
                    // This user isn't linked to any person yet, so link it to this person
                    update_post_meta($person_id, '_lcd_person_user_id', $user_id);
                    update_user_meta($user_id, self::USER_META_KEY, $person_id);
                }
                // If user is already linked to another person, we don't link this person to avoid conflicts
            } else {
                // Temporarily disable the user_register hook to prevent duplicate person creation
                remove_action('user_register', array($this->main_plugin, 'handle_user_registration'));
                
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

                // Re-enable the user_register hook
                add_action('user_register', array($this->main_plugin, 'handle_user_registration'));

                if (is_wp_error($user_id)) {
                    error_log('Failed to create WordPress user for existing ActBlue donor: ' . $user_id->get_error_message());
                    $user_id = null;
                } else {
                    // Link the new WordPress user to this person
                    update_post_meta($person_id, '_lcd_person_user_id', $user_id);
                    update_user_meta($user_id, self::USER_META_KEY, $person_id);
                }
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

        // Ensure this person is set as primary if no other primary exists for this email
        $is_primary = get_post_meta($person_id, '_lcd_person_is_primary', true);
        if ($is_primary !== '1') {
            // Check if there's already a primary person for this email
            $existing_primary = $this->get_primary_person_by_email($donor['email']);
            if (!$existing_primary) {
                // No primary exists, make this person primary
                update_post_meta($person_id, '_lcd_person_is_primary', '1');
                delete_post_meta($person_id, '_lcd_person_actual_primary_id');
                $this->add_sync_record($person_id, 'ActBlue', true, 'Person updated and set as primary (no existing primary found)');
            }
        }

        do_action('lcd_person_actblue_updated', $person_id, $donor, $contribution, $lineitem);
    }
    
    /**
     * Create person from ActBlue data
     */
    public function create_person_from_actblue($donor, $contribution, $lineitem) {
        // Check if a WordPress user with this email already exists
        $existing_user = get_user_by('email', $donor['email']);
        
        if ($existing_user) {
            $user_id = $existing_user->ID;
        } else {
            // Temporarily disable the user_register hook to prevent duplicate person creation
            remove_action('user_register', array($this->main_plugin, 'handle_user_registration'));
            
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

            // Re-enable the user_register hook
            add_action('user_register', array($this->main_plugin, 'handle_user_registration'));

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
        // Note: For shared emails, only the first person will be linked to the WordPress user
        if ($user_id && !is_wp_error($user_id)) {
            // Check if this user is already linked to another person
            $existing_person_id = get_user_meta($user_id, self::USER_META_KEY, true);
            
            if (empty($existing_person_id)) {
                // This user isn't linked to any person yet, so link it to this person
                update_post_meta($person_id, '_lcd_person_user_id', $user_id);
                update_user_meta($user_id, self::USER_META_KEY, $person_id);
            }
            // If user is already linked to another person, we don't link this person to avoid conflicts
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

        // Check and potentially update Primary Status (Automatic Assignment)
        $email = $donor['email'];
        // Find if *another* primary person exists with this email
         $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_status' => 'publish',
            'post__not_in' => array($person_id), // Exclude self
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
            // Another primary exists - this new person becomes secondary
             update_post_meta($person_id, '_lcd_person_is_primary', '0');
             update_post_meta($person_id, '_lcd_person_actual_primary_id', $existing_primary_id);
             $this->add_sync_record($person_id, 'ActBlue Webhook', true, 'New person created as secondary (primary already exists).');
        } else {
            // No other primary - this new person becomes primary
            update_post_meta($person_id, '_lcd_person_is_primary', '1');
            // No need to set _lcd_person_actual_primary_id
             $this->add_sync_record($person_id, 'ActBlue Webhook', true, 'New person created as primary.');
        }

        do_action('lcd_person_actblue_created', $person_id, $donor, $contribution, $lineitem);

        return $person_id;
    }
    
    /**
     * Fix duplicate primary status for an email - ensure only one primary exists
     * This is helpful for cleaning up existing duplicate situations
     */
    public function fix_duplicate_primary_status($email) {
        if (empty($email)) {
            return false;
        }

        // Get all people with this email
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_lcd_person_email',
                    'value' => $email,
                    'compare' => '='
                )
            )
        );

        $people = get_posts($args);
        
        if (count($people) <= 1) {
            // Only one or no person with this email, ensure the one person is primary
            if (count($people) === 1) {
                update_post_meta($people[0]->ID, '_lcd_person_is_primary', '1');
                delete_post_meta($people[0]->ID, '_lcd_person_actual_primary_id');
            }
            return true;
        }

        // Multiple people with same email - determine who should be primary
        $current_primaries = array();
        $all_person_ids = array();
        
        foreach ($people as $person) {
            $all_person_ids[] = $person->ID;
            $is_primary = get_post_meta($person->ID, '_lcd_person_is_primary', true);
            if ($is_primary === '1') {
                $current_primaries[] = $person->ID;
            }
        }

        if (count($current_primaries) === 1) {
            // Exactly one primary exists - set others as secondary
            $primary_id = $current_primaries[0];
            foreach ($all_person_ids as $person_id) {
                if ($person_id !== $primary_id) {
                    update_post_meta($person_id, '_lcd_person_is_primary', '0');
                    update_post_meta($person_id, '_lcd_person_actual_primary_id', $primary_id);
                }
            }
        } elseif (count($current_primaries) === 0) {
            // No primary - make the most recent active member primary
            $best_candidate = null;
            foreach ($people as $person) {
                $status = get_post_meta($person->ID, '_lcd_person_membership_status', true);
                $user_id = get_post_meta($person->ID, '_lcd_person_user_id', true);
                
                // Prefer active members with WordPress accounts
                if ($status === 'active' && !empty($user_id)) {
                    $best_candidate = $person->ID;
                    break;
                }
                // Then prefer active members without accounts
                if ($status === 'active' && !$best_candidate) {
                    $best_candidate = $person->ID;
                }
                // Finally, just pick the first one if no active members
                if (!$best_candidate) {
                    $best_candidate = $person->ID;
                }
            }
            
            // Set the best candidate as primary
            if ($best_candidate) {
                update_post_meta($best_candidate, '_lcd_person_is_primary', '1');
                delete_post_meta($best_candidate, '_lcd_person_actual_primary_id');
                
                // Set others as secondary
                foreach ($all_person_ids as $person_id) {
                    if ($person_id !== $best_candidate) {
                        update_post_meta($person_id, '_lcd_person_is_primary', '0');
                        update_post_meta($person_id, '_lcd_person_actual_primary_id', $best_candidate);
                    }
                }
            }
        } else {
            // Multiple primaries - keep the first one, make others secondary
            $keep_primary = $current_primaries[0];
            foreach ($current_primaries as $i => $primary_id) {
                if ($i > 0) {
                    update_post_meta($primary_id, '_lcd_person_is_primary', '0');
                    update_post_meta($primary_id, '_lcd_person_actual_primary_id', $keep_primary);
                }
            }
        }

        return true;
    }

    /**
     * Add sync record (delegate to main plugin)
     */
    private function add_sync_record($person_id, $service, $success, $message = '') {
        // For now, call the main plugin's method
        // This could be refactored into a separate sync handler later
        if (method_exists($this->main_plugin, 'add_sync_record')) {
            return $this->main_plugin->add_sync_record($person_id, $service, $success, $message);
        }
        
        // Fallback implementation
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
} 