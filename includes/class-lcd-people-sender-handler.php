<?php
/**
 * Sender.net Integration Handler for LCD People Plugin
 * 
 * Handles Sender.net API integration and subscriber synchronization
 * 
 * @package LCD_People
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_People_Sender_Handler {
    
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
    }
    
    /**
     * Sync person to Sender.net
     * 
     * @param int $person_id Person post ID
     * @param bool $trigger_automation Whether to trigger automation
     * @return bool Success status
     */
    public function sync_person_to_sender($person_id, $trigger_automation = true) {
        // Check if primary member
        $is_primary = get_post_meta($person_id, '_lcd_person_is_primary', true);
        $email = get_post_meta($person_id, '_lcd_person_email', true); // Need email for logging

        if ($is_primary !== '1') {
            $this->add_sync_record($person_id, 'Sender.net', false, 'Sync skipped: Not the primary member for this email (' . ($email ?: 'No Email') . ').');
            return false; // Indicate sync was skipped/failed
        }
        
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
            $group_assignments = get_option('lcd_people_sender_group_assignments', array());
            $new_member_group = $group_assignments['new_member'] ?? '';
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

    /**
     * Sync volunteer to Sender.net with volunteer group
     * 
     * @param int $person_id Person post ID
     * @return bool Success status
     */
    public function sync_volunteer_to_sender($person_id) {
        // Check if primary member
        $is_primary = get_post_meta($person_id, '_lcd_person_is_primary', true);
        if ($is_primary !== '1') {
            $this->add_sync_record($person_id, 'Sender.net', false, 'Sync skipped: Not the primary member for this email.');
            return false;
        }

        $token = get_option('lcd_people_sender_token');
        $group_assignments = get_option('lcd_people_sender_group_assignments', array());
        $volunteer_group_id = $group_assignments['new_volunteer'] ?? '';
        
        if (empty($token) || empty($volunteer_group_id)) {
            $this->add_sync_record($person_id, 'Sender.net', false, 'Volunteer sync skipped: Missing API token or volunteer group ID');
            return false;
        }

        $email = get_post_meta($person_id, '_lcd_person_email', true);
        if (empty($email)) {
            $this->add_sync_record($person_id, 'Sender.net', false, 'No email address found');
            return false;
        }

        // Get existing subscriber to preserve their groups
        $existing_groups = array();
        $response = wp_remote_get('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            )
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['data']['subscriber_tags'])) {
                foreach ($body['data']['subscriber_tags'] as $tag) {
                    $existing_groups[] = $tag['id'];
                }
            }
        }

        // Add volunteer group if not already present
        if (!in_array($volunteer_group_id, $existing_groups)) {
            $existing_groups[] = $volunteer_group_id;
        }

        $subscriber_data = array(
            'email' => $email,
            'firstname' => get_post_meta($person_id, '_lcd_person_first_name', true),
            'lastname' => get_post_meta($person_id, '_lcd_person_last_name', true),
            'groups' => $existing_groups,
            'trigger_automation' => true,
            'trigger_groups' => true
        );

        // Add phone if available
        $phone = get_post_meta($person_id, '_lcd_person_phone', true);
        if (!empty($phone)) {
            // Format phone number
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) === 10) {
                $phone = '+1' . $phone;
            } elseif (strlen($phone) === 11 && $phone[0] === '1') {
                $phone = '+' . $phone;
            }
            $subscriber_data['phone'] = $phone;
        }

        // Update or create subscriber
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
            $this->add_sync_record($person_id, 'Sender.net', false, 'Volunteer sync failed: ' . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status === 200 || $status === 201) {
            $this->add_sync_record($person_id, 'Sender.net', true, 'Volunteer synced successfully');
            return true;
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $message = isset($body['message']) ? $body['message'] : 'Unknown error';
            $this->add_sync_record($person_id, 'Sender.net', false, 'Volunteer sync failed. Status: ' . $status . ', Message: ' . $message);
            return false;
        }
    }

    /**
     * Re-trigger welcome automation for a person
     * 
     * @param int $person_id Person post ID
     * @return array Response array with success status and message
     */
    public function retrigger_welcome_automation($person_id) {
        // Get the new member group ID
        $group_assignments = get_option('lcd_people_sender_group_assignments', array());
        $new_member_group = $group_assignments['new_member'] ?? '';
        if (empty($new_member_group)) {
            return array(
                'success' => false,
                'message' => __('New member group ID not configured.', 'lcd-people')
            );
        }

        // Force remove and re-add the new member group
        $token = get_option('lcd_people_sender_token');
        if (empty($token)) {
            return array(
                'success' => false,
                'message' => __('Sender.net API token not configured.', 'lcd-people')
            );
        }

        $email = get_post_meta($person_id, '_lcd_person_email', true);
        if (empty($email)) {
            return array(
                'success' => false,
                'message' => __('No email address found for this person.', 'lcd-people')
            );
        }

        // First, get current subscriber data
        $response = wp_remote_get('https://api.sender.net/v2/subscribers/' . urlencode($email), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Failed to get subscriber data:', 'lcd-people') . ' ' . $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || !isset($body['data'])) {
            return array(
                'success' => false,
                'message' => __('Subscriber not found in Sender.net', 'lcd-people')
            );
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
            return array(
                'success' => false,
                'message' => __('Failed to update subscriber:', 'lcd-people') . ' ' . $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        
        if ($status === 200) {
            $this->add_sync_record($person_id, 'Sender.net', true, 'Welcome automation re-trigger attempted');
            return array(
                'success' => true,
                'message' => __('Welcome automation re-trigger attempted successfully.', 'lcd-people')
            );
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $message = isset($body['message']) ? $body['message'] : __('Unknown error', 'lcd-people');
            $this->add_sync_record($person_id, 'Sender.net', false, 'Failed to re-trigger welcome automation: ' . $message);
            return array(
                'success' => false,
                'message' => __('Failed to update subscriber. Status:', 'lcd-people') . ' ' . $status . ', ' . __('Message:', 'lcd-people') . ' ' . $message
            );
        }
    }

    /**
     * Sync all people to Sender.net
     * 
     * @return array Results array with sync statistics
     */
    public function sync_all_to_sender() {
        // Query all published people
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids', // Only need IDs
        );
        $all_people_query = new WP_Query($args);
        $all_people_ids = $all_people_query->posts;

        $results = array(
            'total_found' => count($all_people_ids),
            'attempted' => 0,
            'synced' => 0,
            'skipped_non_primary' => 0,
            'skipped_no_email' => 0,
            'failed' => 0,
            'error_messages' => [],
            'backfilled_primary' => 0
        );

        if (empty($all_people_ids)) {
            return $results;
        }

        // Group by email
        $email_groups = [];
        $email_counts = [];
        foreach ($all_people_ids as $person_id) {
            $email = get_post_meta($person_id, '_lcd_person_email', true);
            if (!empty($email)) {
                $email = strtolower(trim($email)); // Normalize email
                if (!isset($email_groups[$email])) {
                    $email_groups[$email] = [];
                    $email_counts[$email] = 0;
                }
                $email_groups[$email][] = $person_id;
                $email_counts[$email]++;
            }
        }

        // Backfill primary status for single-email users
        foreach ($email_counts as $email => $count) {
            if ($count === 1) {
                $person_id = $email_groups[$email][0]; // Get the single ID for this email
                $is_primary = get_post_meta($person_id, '_lcd_person_is_primary', true);
                if ($is_primary !== '1') {
                    // This person has a unique email but isn't primary - fix it.
                    update_post_meta($person_id, '_lcd_person_is_primary', '1');
                    delete_post_meta($person_id, '_lcd_person_actual_primary_id'); // Clean up just in case
                    $results['backfilled_primary']++;
                }
            }
            // If count > 1, the existing save/switch logic handles primary status.
        }

        foreach ($all_people_ids as $person_id) {
            $email = get_post_meta($person_id, '_lcd_person_email', true);
            if (empty($email)) {
                $results['skipped_no_email']++;
                continue; // Skip if no email
            }

            $is_primary = get_post_meta($person_id, '_lcd_person_is_primary', true);
            if ($is_primary !== '1') {
                $results['skipped_non_primary']++;
                continue; // Skip if not primary
            }

            // If primary and has email, attempt sync
            $results['attempted']++;
            
            // Call the sync function (trigger_automation = false)
            $sync_success = $this->sync_person_to_sender($person_id, false); 
            
            if ($sync_success) {
                 $results['synced']++;
            } else {
                 $results['failed']++;
                 // Try to grab the last error message for this person from sync_records if possible
                 $sync_records = get_post_meta($person_id, '_lcd_person_sync_records', true);
                 if (!empty($sync_records) && is_array($sync_records)) {
                     $last_record = end($sync_records);
                     if (isset($last_record['service']) && $last_record['service'] === 'Sender.net' && !$last_record['success']) {
                         $results['error_messages'][$person_id] = get_the_title($person_id) . ': ' . $last_record['message'];
                     }
                 }
            }
        }

        return $results;
    }

    /**
     * Add sync record to person
     * 
     * @param int $person_id Person post ID
     * @param string $service Service name
     * @param bool $success Success status
     * @param string $message Optional message
     */
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
} 