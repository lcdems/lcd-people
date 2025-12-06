<?php
/**
 * CallHub Integration Handler for LCD People Plugin
 * 
 * Handles CallHub API integration for SMS contact management and DNC lists
 * 
 * @package LCD_People
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_People_CallHub_Handler {
    
    /**
     * Main plugin instance
     * @var LCD_People
     */
    private $main_plugin;
    
    /**
     * CallHub API base URL (set dynamically from settings)
     * @var string
     */
    private $api_base_url;
    
    /**
     * Constructor
     * 
     * @param LCD_People $main_plugin Main plugin instance
     */
    public function __construct($main_plugin) {
        $this->main_plugin = $main_plugin;
        $this->api_base_url = $this->build_api_base_url();
        $this->init_hooks();
    }
    
    /**
     * Build the API base URL from settings
     * 
     * @return string
     */
    private function build_api_base_url() {
        $api_domain = get_option('lcd_people_callhub_api_domain', '');
        
        // If no custom domain set, use default
        if (empty($api_domain)) {
            return 'https://api.callhub.io/v1/';
        }
        
        // Clean up the domain - remove protocol if present
        $api_domain = preg_replace('#^https?://#', '', $api_domain);
        $api_domain = rtrim($api_domain, '/');
        
        return 'https://' . $api_domain . '/v1/';
    }
    
    /**
     * Get API domain from settings
     * 
     * @return string
     */
    private function get_api_domain() {
        return get_option('lcd_people_callhub_api_domain', '');
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register REST API endpoint for CallHub webhooks
        add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
    }
    
    /**
     * Register webhook endpoint for CallHub STOP messages
     */
    public function register_webhook_endpoint() {
        register_rest_route('lcd-people/v1', '/callhub-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true' // CallHub needs to access this without auth
        ));
    }
    
    /**
     * Get API key from settings
     * 
     * @return string|null
     */
    private function get_api_key() {
        return get_option('lcd_people_callhub_api_key', '');
    }
    
    /**
     * Get DNC list name from settings
     * 
     * @return string
     */
    private function get_dnc_list_name() {
        return get_option('lcd_people_callhub_dnc_list_name', '');
    }
    
    /**
     * Get all DNC lists from CallHub API
     * 
     * @return array Array of DNC lists with 'url' and 'name' keys
     */
    public function get_dnc_lists() {
        // Check for cached lists
        $cached_lists = get_transient('lcd_people_callhub_dnc_lists');
        if ($cached_lists !== false) {
            return $cached_lists;
        }
        
        $response = $this->api_request('dnc_lists/');
        
        if (is_wp_error($response)) {
            error_log('LCD People: Failed to fetch DNC lists - ' . $response->get_error_message());
            return array();
        }
        
        if ($response['status_code'] !== 200) {
            error_log('LCD People: DNC lists API returned status ' . $response['status_code']);
            return array();
        }
        
        $lists = array();
        $results = isset($response['body']['results']) ? $response['body']['results'] : 
                  (is_array($response['body']) ? $response['body'] : array());
        
        foreach ($results as $list) {
            if (isset($list['url']) && isset($list['name'])) {
                $lists[] = array(
                    'url' => $list['url'],
                    'name' => $list['name']
                );
            }
        }
        
        // Cache for 1 hour
        set_transient('lcd_people_callhub_dnc_lists', $lists, HOUR_IN_SECONDS);
        
        return $lists;
    }
    
    /**
     * Get DNC list URL by name
     * 
     * @param string $name DNC list name to find
     * @return string|null DNC list URL or null if not found
     */
    public function get_dnc_list_url_by_name($name) {
        if (empty($name)) {
            return null;
        }
        
        $lists = $this->get_dnc_lists();
        
        foreach ($lists as $list) {
            if (strcasecmp($list['name'], $name) === 0) {
                return $list['url'];
            }
        }
        
        // Log available lists for debugging
        $available_names = array_map(function($l) { return $l['name']; }, $lists);
        error_log('LCD People: DNC list "' . $name . '" not found. Available lists: ' . implode(', ', $available_names));
        
        return null;
    }
    
    /**
     * Make API request to CallHub
     * 
     * @param string $endpoint API endpoint (relative to base URL)
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array $data Request body data
     * @return array|WP_Error Response array or WP_Error
     */
    private function api_request($endpoint, $method = 'GET', $data = null) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('CallHub API key not configured.', 'lcd-people'));
        }
        
        $url = $this->api_base_url . ltrim($endpoint, '/');
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Token ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($data !== null && in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('LCD People CallHub API Error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
        
        // Log API response for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('LCD People CallHub API Response - Status: ' . $status_code . ', Endpoint: ' . $endpoint);
        }
        
        return array(
            'status_code' => $status_code,
            'body' => $decoded_body,
            'raw_body' => $body
        );
    }
    
    /**
     * Make API request to CallHub v2 API
     * 
     * @param string $endpoint API endpoint (relative to /v2/)
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param array $data Request body data
     * @return array|WP_Error Response array or WP_Error
     */
    private function api_request_v2($endpoint, $method = 'GET', $data = null) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('CallHub API key not configured.', 'lcd-people'));
        }
        
        // Build v2 API URL
        $api_domain = $this->get_api_domain();
        if (empty($api_domain)) {
            $api_domain = 'api.callhub.io';
        }
        $api_domain = preg_replace('#^https?://#', '', $api_domain);
        $api_domain = rtrim($api_domain, '/');
        $url = 'https://' . $api_domain . '/v2/' . ltrim($endpoint, '/');
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Token ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        );
        
        if ($data !== null && in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'))) {
            $args['body'] = json_encode($data);
        }
        
        error_log('LCD People CallHub v2 API Request - URL: ' . $url . ', Method: ' . $method);
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('LCD People CallHub v2 API Error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);
        
        error_log('LCD People CallHub v2 API Response - Status: ' . $status_code . ', Endpoint: ' . $endpoint);
        
        return array(
            'status_code' => $status_code,
            'body' => $decoded_body,
            'raw_body' => $body
        );
    }
    
    /**
     * Format phone number for CallHub (E.164 format)
     * 
     * @param string $phone Phone number
     * @return string Formatted phone number
     */
    public function format_phone_number($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Add +1 country code for US numbers if not already present
        if (strlen($phone) === 10) {
            $phone = '+1' . $phone;
        } elseif (strlen($phone) === 11 && $phone[0] === '1') {
            $phone = '+' . $phone;
        } elseif (strlen($phone) > 0 && $phone[0] !== '+') {
            // If it doesn't start with +, assume it needs it
            if (strlen($phone) === 11) {
                $phone = '+' . $phone;
            }
        }
        
        return $phone;
    }
    
    /**
     * Get configured SMS opt-in tag IDs
     * 
     * @return array Array of tag IDs (strings)
     */
    private function get_sms_optin_tag_ids() {
        $tags_string = get_option('lcd_people_callhub_sms_tags', '');
        
        if (empty($tags_string)) {
            return array();
        }
        
        // Parse comma-separated tag IDs as strings
        $tags = array_map('trim', explode(',', $tags_string));
        
        // Remove empty values, keep as strings
        $tags = array_filter($tags, function($tag) {
            return !empty($tag) && is_numeric($tag);
        });
        
        return array_values($tags);
    }
    
    /**
     * Create or update a contact in CallHub
     * 
     * @param string $phone Phone number
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param string $email Email address
     * @param array $tag_ids Optional array of tag IDs to assign
     * @return array Result with success status and message
     */
    public function create_or_update_contact($phone, $first_name = '', $last_name = '', $email = '', $tag_ids = array()) {
        $phone = $this->format_phone_number($phone);
        
        if (empty($phone)) {
            return array(
                'success' => false,
                'message' => __('Invalid phone number.', 'lcd-people')
            );
        }
        
        // Check if contact already exists by phone number first
        $existing_contact = $this->get_contact_by_phone($phone);
        
        // If not found by phone, try by email to prevent duplicates
        if (!$existing_contact && !empty($email)) {
            $existing_contact = $this->get_contact_by_email($email);
            if ($existing_contact) {
                error_log('LCD People: Found existing CallHub contact by email: ' . $email . ' (ID: ' . ($existing_contact['id'] ?? 'unknown') . ')');
            }
        }
        
        $contact_data = array(
            'contact' => $phone,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email
        );
        
        $contact_id = null;
        $is_existing = false;
        
        if ($existing_contact && isset($existing_contact['id'])) {
            // Update existing contact
            $is_existing = true;
            $contact_id = $existing_contact['id'];
            error_log('LCD People: Updating existing CallHub contact ID: ' . $contact_id);
            $response = $this->api_request('contacts/' . $contact_id . '/', 'PUT', $contact_data);
        } else {
            // Create new contact
            error_log('LCD People: Creating new CallHub contact for phone: ' . $phone);
            $response = $this->api_request('contacts/', 'POST', $contact_data);
            $contact_id = isset($response['body']['id']) ? $response['body']['id'] : null;
        }
        
        if (is_wp_error($response)) {
            // Even if update failed, try to add tags to existing contact
            if ($is_existing && !empty($contact_id) && !empty($tag_ids)) {
                error_log('LCD People: Contact update failed but trying to add tags to existing contact ' . $contact_id);
                $tag_result = $this->add_tags_to_contact($contact_id, $tag_ids);
                if ($tag_result['success']) {
                    return array(
                        'success' => true,
                        'message' => __('Tags added to existing contact.', 'lcd-people'),
                        'contact_id' => $contact_id,
                        'tags_added' => true
                    );
                }
            }
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        if (in_array($response['status_code'], array(200, 201))) {
            $result = array(
                'success' => true,
                'message' => __('Contact synced to CallHub successfully.', 'lcd-people'),
                'contact_id' => $contact_id
            );
            
            // Add tags via separate API call if provided
            if (!empty($tag_ids) && !empty($contact_id)) {
                error_log('LCD People: Adding tags to contact ' . $contact_id . ', tag IDs: ' . implode(', ', $tag_ids));
                $tag_result = $this->add_tags_to_contact($contact_id, $tag_ids);
                $result['tags_added'] = $tag_result['success'];
                error_log('LCD People: Tag result - success: ' . ($tag_result['success'] ? 'yes' : 'no') . ', message: ' . $tag_result['message']);
                if (!$tag_result['success']) {
                    $result['message'] .= ' ' . sprintf(__('(Tag error: %s)', 'lcd-people'), $tag_result['message']);
                }
            }
            
            return $result;
        }
        
        // Contact create/update failed, but if we have an existing contact, still try to add tags
        if ($is_existing && !empty($contact_id) && !empty($tag_ids)) {
            error_log('LCD People: Contact update returned non-200 status but trying to add tags to existing contact ' . $contact_id);
            $tag_result = $this->add_tags_to_contact($contact_id, $tag_ids);
            if ($tag_result['success']) {
                return array(
                    'success' => true,
                    'message' => __('Tags added to existing contact.', 'lcd-people'),
                    'contact_id' => $contact_id,
                    'tags_added' => true
                );
            }
        }
        
        $error_message = isset($response['body']['detail']) ? $response['body']['detail'] : 
                        (isset($response['body']['message']) ? $response['body']['message'] : 
                        __('Unknown error', 'lcd-people'));
        
        return array(
            'success' => false,
            'message' => sprintf(__('CallHub API error: %s', 'lcd-people'), $error_message)
        );
    }
    
    /**
     * Add tags to a contact using tag IDs
     * 
     * @param string $contact_id Contact ID
     * @param array $tag_ids Array of tag IDs to add
     * @return array Result with success status and message
     */
    public function add_tags_to_contact($contact_id, $tag_ids) {
        if (empty($contact_id) || empty($tag_ids)) {
            return array(
                'success' => false,
                'message' => __('Contact ID and tag IDs are required.', 'lcd-people')
            );
        }
        
        // Convert tag IDs to strings as required by CallHub API
        $tag_ids = array_map('strval', $tag_ids);
        $tag_ids = array_filter($tag_ids, function($id) { return !empty($id) && $id !== '0'; });
        
        if (empty($tag_ids)) {
            return array(
                'success' => false,
                'message' => __('No valid tag IDs provided.', 'lcd-people')
            );
        }
        
        // CallHub API v2: PATCH /v2/contacts/{id}/taggings/ with {"tags": ["123", "456"]}
        // See: https://developer.callhub.io/reference/contactsidtaggings
        $tag_body = array('tags' => array_values($tag_ids));
        error_log('LCD People: Adding tags to contact ' . $contact_id . ' - Request body: ' . json_encode($tag_body));
        
        $response = $this->api_request_v2('contacts/' . $contact_id . '/taggings/', 'PATCH', $tag_body);
        
        error_log('LCD People: Tag response - Status: ' . ($response['status_code'] ?? 'error') . ', Body: ' . json_encode($response['body'] ?? 'null'));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        if (in_array($response['status_code'], array(200, 201, 204))) {
            return array(
                'success' => true,
                'message' => sprintf(__('%d tag(s) added to contact.', 'lcd-people'), count($tag_ids)),
                'added' => count($tag_ids)
            );
        }
        
        $error_message = isset($response['body']['detail']) ? $response['body']['detail'] : 
                        (isset($response['body']['message']) ? $response['body']['message'] : 
                        'Status ' . $response['status_code']);
        
        error_log('LCD People: Failed to add tags: ' . $error_message);
        
        return array(
            'success' => false,
            'message' => sprintf(__('Failed to add tags: %s', 'lcd-people'), $error_message)
        );
    }
    
    /**
     * Get contact by phone number
     * 
     * @param string $phone Phone number
     * @return array|null Contact data or null if not found
     */
    public function get_contact_by_phone($phone) {
        $phone = $this->format_phone_number($phone);
        
        // Strip + for search since CallHub may store without it
        $phone_digits = ltrim($phone, '+');
        
        error_log('LCD People: Searching for contact with phone: ' . $phone . ' (digits: ' . $phone_digits . ')');
        
        $response = $this->api_request('contacts/?search=' . urlencode($phone_digits));
        
        if (is_wp_error($response)) {
            error_log('LCD People: Contact search error: ' . $response->get_error_message());
            return null;
        }
        
        error_log('LCD People: Contact search response - Status: ' . $response['status_code'] . ', Results count: ' . (isset($response['body']['results']) ? count($response['body']['results']) : 0));
        
        if ($response['status_code'] === 200 && isset($response['body']['results'])) {
            // Search for exact match in results (comparing digits only)
            foreach ($response['body']['results'] as $contact) {
                $contact_phone_digits = preg_replace('/[^0-9]/', '', $contact['contact'] ?? '');
                error_log('LCD People: Comparing contact phone ' . $contact_phone_digits . ' with ' . $phone_digits);
                
                if ($contact_phone_digits === $phone_digits) {
                    error_log('LCD People: Found matching contact with ID: ' . ($contact['id'] ?? 'unknown'));
                    return $contact;
                }
            }
        }
        
        error_log('LCD People: No matching contact found for phone: ' . $phone);
        return null;
    }
    
    /**
     * Get ALL contacts by phone number (for removing duplicates)
     * 
     * @param string $phone Phone number
     * @return array Array of matching contacts (empty if none found)
     */
    public function get_all_contacts_by_phone($phone) {
        $phone = $this->format_phone_number($phone);
        $phone_digits = ltrim($phone, '+');
        $matches = array();
        
        $response = $this->api_request('contacts/?search=' . urlencode($phone_digits));
        
        if (is_wp_error($response)) {
            return $matches;
        }
        
        if ($response['status_code'] === 200 && isset($response['body']['results'])) {
            foreach ($response['body']['results'] as $contact) {
                $contact_phone_digits = preg_replace('/[^0-9]/', '', $contact['contact'] ?? '');
                if ($contact_phone_digits === $phone_digits && isset($contact['id'])) {
                    $matches[] = $contact;
                }
            }
        }
        
        error_log('LCD People: Found ' . count($matches) . ' contact(s) matching phone: ' . $phone);
        return $matches;
    }
    
    /**
     * Get contact by email address
     * 
     * @param string $email Email address
     * @return array|null Contact data or null if not found
     */
    public function get_contact_by_email($email) {
        if (empty($email)) {
            return null;
        }
        
        $response = $this->api_request('contacts/?search=' . urlencode($email));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        if ($response['status_code'] === 200 && isset($response['body']['results'])) {
            // Search for exact match in results
            foreach ($response['body']['results'] as $contact) {
                if (isset($contact['email']) && strcasecmp($contact['email'], $email) === 0) {
                    return $contact;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Add phone number to DNC (Do Not Call) list
     * 
     * @param string $phone Phone number
     * @return array Result with success status and message
     */
    public function add_to_dnc($phone) {
        $phone = $this->format_phone_number($phone);
        
        if (empty($phone)) {
            return array(
                'success' => false,
                'message' => __('Invalid phone number.', 'lcd-people')
            );
        }
        
        // Get DNC list name from settings
        $dnc_list_name = $this->get_dnc_list_name();
        
        if (empty($dnc_list_name)) {
            return array(
                'success' => false,
                'message' => __('CallHub DNC list name not configured.', 'lcd-people')
            );
        }
        
        // Get the DNC list URL by name
        $dnc_list_url = $this->get_dnc_list_url_by_name($dnc_list_name);
        
        if (empty($dnc_list_url)) {
            return array(
                'success' => false,
                'message' => sprintf(__('CallHub DNC list "%s" not found. Please check the list name in settings.', 'lcd-people'), $dnc_list_name)
            );
        }
        
        // Check if already on DNC
        $existing = $this->get_dnc_entry($phone);
        if ($existing) {
            return array(
                'success' => true,
                'message' => __('Phone already on DNC list.', 'lcd-people')
            );
        }
        
        $dnc_data = array(
            'dnc' => $dnc_list_url,
            'phone_number' => $phone,
            'category' => 2  // Category 2 = Texting only (not calling)
        );
        
        // Log the request for debugging
        error_log('LCD People: Adding to DNC - Phone: ' . $phone . ', DNC List: ' . $dnc_list_name . ', Category: 2 (texting only)');
        
        $response = $this->api_request('dnc_contacts/', 'POST', $dnc_data);
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        // Log the response for debugging
        error_log('LCD People: DNC API Response - Status: ' . $response['status_code'] . ', Body: ' . json_encode($response['body']));
        
        if (in_array($response['status_code'], array(200, 201))) {
            // Clear the DNC lists cache so it reflects the new entry
            delete_transient('lcd_people_callhub_dnc_lists');
            
            return array(
                'success' => true,
                'message' => __('Phone added to DNC list.', 'lcd-people')
            );
        }
        
        // Handle case where phone is already on DNC (might return 400)
        if ($response['status_code'] === 400) {
            $error_detail = isset($response['body']['phone_number']) ? 
                           implode(' ', (array)$response['body']['phone_number']) : '';
            if (stripos($error_detail, 'already exists') !== false || stripos($error_detail, 'unique') !== false) {
                return array(
                    'success' => true,
                    'message' => __('Phone already on DNC list.', 'lcd-people')
                );
            }
            
            // Also check for dnc field errors
            $dnc_error = isset($response['body']['dnc']) ? 
                        implode(' ', (array)$response['body']['dnc']) : '';
            if (!empty($dnc_error)) {
                return array(
                    'success' => false,
                    'message' => sprintf(__('DNC List error: %s', 'lcd-people'), $dnc_error)
                );
            }
        }
        
        $error_message = isset($response['body']['detail']) ? $response['body']['detail'] : 
                        (isset($response['body']['message']) ? $response['body']['message'] : 
                        json_encode($response['body']));
        
        return array(
            'success' => false,
            'message' => sprintf(__('Failed to add to DNC: %s', 'lcd-people'), $error_message)
        );
    }
    
    /**
     * Get DNC entry by phone number
     * 
     * @param string $phone Phone number
     * @return array|null DNC entry or null if not found
     */
    public function get_dnc_entry($phone) {
        $phone = $this->format_phone_number($phone);
        
        // Strip the + for comparison since CallHub API returns phone without +
        $phone_digits = ltrim($phone, '+');
        
        error_log('LCD People: Searching for DNC entry with phone: ' . $phone . ' (digits: ' . $phone_digits . ')');
        
        $response = $this->api_request('dnc_contacts/?search=' . urlencode($phone_digits));
        
        if (is_wp_error($response)) {
            error_log('LCD People: DNC search error: ' . $response->get_error_message());
            return null;
        }
        
        error_log('LCD People: DNC search response - Status: ' . $response['status_code'] . ', Results count: ' . (isset($response['body']['results']) ? count($response['body']['results']) : 0));
        
        if ($response['status_code'] === 200 && isset($response['body']['results'])) {
            foreach ($response['body']['results'] as $entry) {
                // CallHub returns phone_number without + prefix
                $entry_phone_digits = ltrim($entry['phone_number'] ?? '', '+');
                error_log('LCD People: Comparing entry phone ' . $entry_phone_digits . ' with ' . $phone_digits);
                
                if ($entry_phone_digits === $phone_digits) {
                    // Extract ID from URL since CallHub doesn't return id field directly
                    // URL format: https://api.callhub.io/v1/dnc_contacts/{id}/
                    $id = null;
                    if (isset($entry['url']) && preg_match('/dnc_contacts\/(\d+)\//', $entry['url'], $matches)) {
                        $id = $matches[1];
                    }
                    $entry['id'] = $id;
                    
                    error_log('LCD People: Found matching DNC entry with ID: ' . ($id ?? 'unknown') . ' from URL: ' . ($entry['url'] ?? 'none'));
                    return $entry;
                }
            }
        }
        
        error_log('LCD People: No matching DNC entry found for phone: ' . $phone);
        return null;
    }
    
    /**
     * Remove phone number from DNC (Do Not Call) list
     * 
     * @param string $phone Phone number
     * @return array Result with success status and message
     */
    public function remove_from_dnc($phone) {
        $phone = $this->format_phone_number($phone);
        
        if (empty($phone)) {
            return array(
                'success' => false,
                'message' => __('Invalid phone number.', 'lcd-people')
            );
        }
        
        error_log('LCD People: Attempting to remove from DNC - Phone: ' . $phone);
        
        // Find the DNC entry
        $dnc_entry = $this->get_dnc_entry($phone);
        
        if (!$dnc_entry || !isset($dnc_entry['id'])) {
            error_log('LCD People: Phone not found on DNC list: ' . $phone);
            return array(
                'success' => true,
                'message' => __('Phone not on DNC list (nothing to remove).', 'lcd-people')
            );
        }
        
        error_log('LCD People: Found DNC entry ID ' . $dnc_entry['id'] . ' for phone ' . $phone . ', deleting...');
        
        $response = $this->api_request('dnc_contacts/' . $dnc_entry['id'] . '/', 'DELETE');
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        if (in_array($response['status_code'], array(200, 204))) {
            error_log('LCD People: Successfully removed phone from DNC: ' . $phone);
            return array(
                'success' => true,
                'message' => __('Phone removed from DNC list.', 'lcd-people')
            );
        }
        
        $error_message = isset($response['body']['detail']) ? $response['body']['detail'] : 
                        __('Unknown error', 'lcd-people');
        
        error_log('LCD People: Failed to remove from DNC - Status: ' . $response['status_code'] . ', Error: ' . $error_message);
        
        return array(
            'success' => false,
            'message' => sprintf(__('Failed to remove from DNC: %s', 'lcd-people'), $error_message)
        );
    }
    
    /**
     * High-level method to sync SMS status to CallHub
     * 
     * When SMS opted in: Create/update contact and remove from DNC
     * When SMS opted out: Add to DNC
     * 
     * @param string $phone Phone number
     * @param string $first_name First name
     * @param string $last_name Last name
     * @param string $email Email address
     * @param bool $sms_opted_in Whether user has opted in to SMS
     * @return array Result with success status and message
     */
    public function sync_sms_status($phone, $first_name, $last_name, $email, $sms_opted_in) {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => __('CallHub API key not configured.', 'lcd-people')
            );
        }
        
        $phone = $this->format_phone_number($phone);
        
        if (empty($phone)) {
            return array(
                'success' => false,
                'message' => __('No phone number provided.', 'lcd-people')
            );
        }
        
        $results = array();
        
        if ($sms_opted_in) {
            // User has opted IN to SMS
            error_log('LCD People: Processing SMS OPT-IN for phone: ' . $phone);
            
            // Get SMS opt-in tag IDs to assign
            $sms_tag_ids = $this->get_sms_optin_tag_ids();
            error_log('LCD People: Tag IDs to assign: ' . implode(', ', $sms_tag_ids));
            
            // 1. Create/update contact in CallHub with tag IDs
            $contact_result = $this->create_or_update_contact($phone, $first_name, $last_name, $email, $sms_tag_ids);
            $results['contact'] = $contact_result;
            error_log('LCD People: Contact create/update result: ' . json_encode($contact_result));
            
            // 2. Remove from DNC list (if present)
            $dnc_result = $this->remove_from_dnc($phone);
            $results['dnc'] = $dnc_result;
            error_log('LCD People: DNC removal result: ' . json_encode($dnc_result));
            
            // Success if contact was created/updated (DNC removal is optional)
            $success = $contact_result['success'];
            $message = $contact_result['success'] ? 
                      __('Contact synced to CallHub (SMS opted in).', 'lcd-people') :
                      $contact_result['message'];
            
            if ($contact_result['success'] && !empty($sms_tag_ids)) {
                $message .= sprintf(' ' . __('(%d tag(s) assigned)', 'lcd-people'), count($sms_tag_ids));
            }
            
        } else {
            // User has opted OUT of SMS
            // Add to DNC list
            $dnc_result = $this->add_to_dnc($phone);
            $results['dnc'] = $dnc_result;
            
            $success = $dnc_result['success'];
            $message = $dnc_result['success'] ? 
                      __('Phone added to CallHub DNC list (SMS opted out).', 'lcd-people') :
                      $dnc_result['message'];
        }
        
        // Log the sync result
        error_log('LCD People CallHub Sync - Phone: ' . $phone . ', SMS Opted In: ' . ($sms_opted_in ? 'Yes' : 'No') . ', Success: ' . ($success ? 'Yes' : 'No'));
        
        return array(
            'success' => $success,
            'message' => $message,
            'details' => $results
        );
    }
    
    /**
     * Handle incoming webhook from CallHub (STOP messages)
     * 
     * CallHub webhook format for sb.reply and p2p.reply events:
     * {
     *   "hook": {},
     *   "data": {
     *     "content": "STOP",
     *     "from_name": "John Doe",
     *     "from_number": "16502293681",
     *     "campaign": "Campaign Name"
     *   }
     * }
     * 
     * See: https://developer.callhub.io/reference/create-new-webhook
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook($request) {
        $payload = $request->get_json_params();
        
        // Log webhook receipt
        error_log('LCD People CallHub Webhook Received: ' . json_encode($payload));
        
        // Validate webhook payload
        if (empty($payload)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Empty payload'
            ), 400);
        }
        
        // CallHub sends data in a nested 'data' object
        $data = isset($payload['data']) ? $payload['data'] : $payload;
        
        // Extract phone number - CallHub uses 'from_number' for SMS replies
        $phone = isset($data['from_number']) ? $data['from_number'] : 
                (isset($data['phone_number']) ? $data['phone_number'] : 
                (isset($data['contact']) ? $data['contact'] : 
                (isset($data['mobile']) ? $data['mobile'] : null)));
        
        // Extract message content - CallHub uses 'content' for SMS replies
        $message_text = isset($data['content']) ? strtoupper(trim($data['content'])) : '';
        
        // Extract campaign name for logging
        $campaign = isset($data['campaign']) ? $data['campaign'] : 'unknown';
        $from_name = isset($data['from_name']) ? $data['from_name'] : '';
        
        error_log('LCD People: Webhook parsed - Phone: ' . ($phone ?? 'none') . ', Content: "' . $message_text . '", Campaign: ' . $campaign . ', From: ' . $from_name);
        
        // Check if this is a STOP/opt-out message
        // Standard SMS opt-out keywords
        $opt_out_keywords = array('STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT');
        $is_stop = in_array($message_text, $opt_out_keywords);
        
        error_log('LCD People: Is opt-out message: ' . ($is_stop ? 'YES' : 'NO'));
        
        if (empty($phone)) {
            error_log('LCD People: Webhook rejected - no phone number found in payload');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No phone number in payload'
            ), 400);
        }
        
        $phone = $this->format_phone_number($phone);
        
        if ($is_stop) {
            // Process STOP/opt-out
            error_log('LCD People: Processing STOP request for phone: ' . $phone);
            $result = $this->process_stop_webhook($phone, $payload);
            
            return new WP_REST_Response(array(
                'success' => $result['success'],
                'message' => $result['message']
            ), $result['success'] ? 200 : 500);
        }
        
        // For other webhook events (non-STOP messages), just acknowledge receipt
        error_log('LCD People: Non-opt-out message received, acknowledging');
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Webhook received'
        ), 200);
    }
    
    /**
     * Process STOP webhook - update local DB and remove from SMS groups
     * 
     * Note: We do NOT remove the phone from Sender.net - we only remove from SMS opt-in groups.
     * The phone number is retained across all systems; we're just syncing the opt-in status.
     * 
     * @param string $phone Phone number
     * @param array $payload Full webhook payload
     * @return array Result with success status and message
     */
    private function process_stop_webhook($phone, $payload) {
        $errors = array();
        $email = null;
        
        // 1. Find person by phone number in WordPress
        $person_id = $this->find_person_by_phone($phone);
        
        if ($person_id) {
            // Update person's SMS consent status
            update_post_meta($person_id, '_lcd_person_sms_opted_in', '0');
            update_post_meta($person_id, '_lcd_person_sms_opt_out_date', current_time('mysql'));
            update_post_meta($person_id, '_lcd_person_sms_opt_out_source', 'callhub_stop');
            
            // Get email for Sender.net group removal
            $email = get_post_meta($person_id, '_lcd_person_email', true);
            
            error_log('LCD People: Updated person ' . $person_id . ' SMS opt-out via CallHub STOP (email: ' . ($email ?: 'none') . ')');
        } else {
            error_log('LCD People: No person found for phone ' . $phone . ' (CallHub STOP webhook)');
        }
        
        // 2. Remove from SMS opt-in groups in Sender.net (keep phone number, just remove groups)
        if (!empty($email)) {
            $sender_result = $this->remove_from_sms_groups($email);
            if (!$sender_result['success']) {
                $errors[] = $sender_result['message'];
            }
        }
        
        // 3. Make sure phone is on CallHub DNC
        $dnc_result = $this->add_to_dnc($phone);
        if (!$dnc_result['success']) {
            $errors[] = $dnc_result['message'];
        }
        
        if (empty($errors)) {
            return array(
                'success' => true,
                'message' => __('STOP processed successfully.', 'lcd-people')
            );
        }
        
        return array(
            'success' => false,
            'message' => implode('; ', $errors)
        );
    }
    
    /**
     * Remove subscriber from SMS opt-in groups in Sender.net
     * 
     * This removes the user from SMS groups but keeps their phone number in the system.
     * 
     * @param string $email Email address
     * @return array Result with success status and message
     */
    private function remove_from_sms_groups($email) {
        $token = get_option('lcd_people_sender_token');
        
        if (empty($token)) {
            return array(
                'success' => true, // Not an error if not configured
                'message' => __('Sender.net not configured.', 'lcd-people')
            );
        }
        
        // Get SMS opt-in groups from settings
        $group_assignments = get_option('lcd_people_sender_group_assignments', array());
        $sms_groups = $group_assignments['sms_optin'] ?? array();
        
        if (empty($sms_groups)) {
            return array(
                'success' => true,
                'message' => __('No SMS groups configured.', 'lcd-people')
            );
        }
        
        $removed_count = 0;
        $errors = array();
        
        foreach ($sms_groups as $group_id) {
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
                $errors[] = 'Group ' . $group_id . ': ' . $response->get_error_message();
                continue;
            }
            
            $status = wp_remote_retrieve_response_code($response);
            if (in_array($status, array(200, 204))) {
                $removed_count++;
                error_log('LCD People: Removed ' . $email . ' from SMS group ' . $group_id);
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $error_msg = $body['message'] ?? "Status $status";
                $errors[] = 'Group ' . $group_id . ': ' . $error_msg;
            }
        }
        
        if ($removed_count > 0 || empty($errors)) {
            return array(
                'success' => true,
                'message' => sprintf(__('Removed from %d SMS group(s).', 'lcd-people'), $removed_count)
            );
        }
        
        return array(
            'success' => false,
            'message' => implode('; ', $errors)
        );
    }
    
    /**
     * Find person post ID by phone number
     * 
     * @param string $phone Phone number
     * @return int|null Person post ID or null
     */
    private function find_person_by_phone($phone) {
        $phone = $this->format_phone_number($phone);
        
        // Also try variations without formatting
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);
        
        $args = array(
            'post_type' => 'lcd_person',
            'posts_per_page' => 1,
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_lcd_person_phone',
                    'value' => $phone,
                    'compare' => '='
                ),
                array(
                    'key' => '_lcd_person_phone',
                    'value' => $phone_clean,
                    'compare' => 'LIKE'
                )
            )
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0]->ID;
        }
        
        return null;
    }
    
    /**
     * Remove phone from Sender.net subscriber
     * 
     * @param string $phone Phone number
     * @return array Result with success status and message
     */
    private function remove_phone_from_sender($phone) {
        $token = get_option('lcd_people_sender_token');
        
        if (empty($token)) {
            return array(
                'success' => false,
                'message' => __('Sender.net API token not configured.', 'lcd-people')
            );
        }
        
        $phone = $this->format_phone_number($phone);
        
        // Find subscriber by phone number
        // First, search for subscriber with this phone
        $response = wp_remote_get('https://api.sender.net/v2/subscribers?phone=' . urlencode($phone), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Failed to search Sender.net subscribers.', 'lcd-people')
            );
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status !== 200 || empty($body['data'])) {
            // No subscriber found with this phone - that's okay
            return array(
                'success' => true,
                'message' => __('No Sender.net subscriber found with this phone.', 'lcd-people')
            );
        }
        
        // Remove phone from found subscribers
        $updated = 0;
        foreach ($body['data'] as $subscriber) {
            if (isset($subscriber['id'])) {
                // Use the remove_phone endpoint
                $remove_response = wp_remote_request('https://api.sender.net/v2/subscribers/' . $subscriber['id'] . '/remove_phone', array(
                    'method' => 'DELETE',
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ),
                    'body' => json_encode(array()),
                    'timeout' => 15
                ));
                
                if (!is_wp_error($remove_response)) {
                    $remove_status = wp_remote_retrieve_response_code($remove_response);
                    if (in_array($remove_status, array(200, 204))) {
                        $updated++;
                    }
                }
            }
        }
        
        return array(
            'success' => true,
            'message' => sprintf(__('Phone removed from %d Sender.net subscriber(s).', 'lcd-people'), $updated)
        );
    }
    
    /**
     * Test API connection
     * 
     * @return array Result with success status and message
     */
    public function test_connection() {
        $api_key = $this->get_api_key();
        
        if (empty($api_key)) {
            return array(
                'success' => false,
                'message' => __('API key not configured.', 'lcd-people')
            );
        }
        
        // Try to fetch user info or contacts list to verify connection
        $response = $this->api_request('contacts/?limit=1');
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        if ($response['status_code'] === 200) {
            return array(
                'success' => true,
                'message' => __('Successfully connected to CallHub API.', 'lcd-people')
            );
        }
        
        if ($response['status_code'] === 401) {
            return array(
                'success' => false,
                'message' => __('Invalid API key.', 'lcd-people')
            );
        }
        
        return array(
            'success' => false,
            'message' => sprintf(__('API returned status %d', 'lcd-people'), $response['status_code'])
        );
    }
    
    /**
     * Get list of registered webhooks
     * 
     * @return array Result with webhooks list
     */
    public function get_webhooks() {
        $response = $this->api_request('webhooks/');
        
        if (is_wp_error($response)) {
            error_log('LCD People: get_webhooks error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
                'webhooks' => array()
            );
        }
        
        error_log('LCD People: get_webhooks raw response: ' . print_r($response['body'], true));
        
        if ($response['status_code'] === 200) {
            $webhooks = isset($response['body']['results']) ? $response['body']['results'] : 
                       (is_array($response['body']) ? $response['body'] : array());
            error_log('LCD People: get_webhooks parsed ' . count($webhooks) . ' webhooks');
            return array(
                'success' => true,
                'webhooks' => $webhooks
            );
        }
        
        return array(
            'success' => false,
            'message' => sprintf(__('Failed to fetch webhooks (status %d)', 'lcd-people'), $response['status_code']),
            'webhooks' => array()
        );
    }
    
    /**
     * Create a webhook in CallHub
     * 
     * CallHub API: POST /webhooks/
     * Required parameters: target (URL), event
     * See: https://developer.callhub.io/reference/webhookspost
     * 
     * @param string $url Webhook URL to receive notifications
     * @param string $event Event type to subscribe to
     * @return array Result with success status and webhook data
     */
    public function create_webhook($url, $event) {
        // CallHub API expects 'target' and 'event' parameters
        $data = array(
            'target' => $url,
            'event' => $event
        );
        
        error_log('LCD People: Creating CallHub webhook - URL: ' . $url . ', Event: ' . $event);
        error_log('LCD People: Webhook request body: ' . json_encode($data));
        
        $response = $this->api_request('webhooks/', 'POST', $data);
        
        if (is_wp_error($response)) {
            error_log('LCD People: Webhook creation WP_Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        error_log('LCD People: Webhook creation response - Status: ' . $response['status_code'] . ', Body: ' . json_encode($response['body']));
        
        if (in_array($response['status_code'], array(200, 201))) {
            return array(
                'success' => true,
                'message' => __('Webhook created successfully.', 'lcd-people'),
                'webhook' => $response['body']
            );
        }
        
        // Build error message from various possible response formats
        $error_message = '';
        if (isset($response['body']['detail'])) {
            $error_message = $response['body']['detail'];
        } elseif (isset($response['body']['message'])) {
            $error_message = $response['body']['message'];
        } elseif (isset($response['body']['target']) && is_array($response['body']['target'])) {
            $error_message = 'Target URL error: ' . implode(' ', $response['body']['target']);
        } elseif (isset($response['body']['url']) && is_array($response['body']['url'])) {
            $error_message = 'URL error: ' . implode(' ', $response['body']['url']);
        } elseif (isset($response['body']['event']) && is_array($response['body']['event'])) {
            $error_message = 'Event error: ' . implode(' ', $response['body']['event']);
        } elseif (isset($response['body']['non_field_errors']) && is_array($response['body']['non_field_errors'])) {
            $error_message = implode(' ', $response['body']['non_field_errors']);
        } else {
            $error_message = 'Status ' . $response['status_code'] . ': ' . json_encode($response['body']);
        }
        
        error_log('LCD People: Webhook creation failed: ' . $error_message);
        
        return array(
            'success' => false,
            'message' => sprintf(__('Failed to create webhook: %s', 'lcd-people'), $error_message)
        );
    }
    
    /**
     * Delete a webhook from CallHub
     * 
     * @param int|string $webhook_id Webhook ID to delete
     * @return array Result with success status
     */
    public function delete_webhook($webhook_id) {
        $response = $this->api_request('webhooks/' . $webhook_id . '/', 'DELETE');
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        if (in_array($response['status_code'], array(200, 204))) {
            return array(
                'success' => true,
                'message' => __('Webhook deleted successfully.', 'lcd-people')
            );
        }
        
        return array(
            'success' => false,
            'message' => sprintf(__('Failed to delete webhook (status %d)', 'lcd-people'), $response['status_code'])
        );
    }
    
    /**
     * Register webhook for SMS opt-out events
     * Creates webhooks for relevant SMS events
     * 
     * CallHub webhook events (see https://developer.callhub.io/reference/webhookspost):
     * - sb.reply: SMS Broadcast reply
     * - p2p.reply: Peer-to-peer texting reply
     * - vb.transfer: Voice broadcast transfer
     * - cc.transfer: Call center transfer
     * 
     * @return array Result with success status and details
     */
    public function register_sms_webhook() {
        $webhook_url = rest_url('lcd-people/v1/callhub-webhook');
        
        error_log('LCD People: Registering SMS webhook to URL: ' . $webhook_url);
        
        // SMS-related events in CallHub
        // sb.reply = SMS Broadcast reply (for STOP messages)
        // p2p.reply = Peer-to-peer texting reply (for STOP messages)
        $events_to_register = array('sb.reply', 'p2p.reply');
        
        $results = array();
        $success_count = 0;
        
        // First, check existing webhooks to avoid duplicates
        $existing = $this->get_webhooks();
        $existing_urls = array();
        
        error_log('LCD People: Existing webhooks response: ' . json_encode($existing));
        
        if ($existing['success'] && !empty($existing['webhooks'])) {
            foreach ($existing['webhooks'] as $webhook) {
                // CallHub returns 'target' for the destination URL we registered
                $webhook_url_value = $webhook['target'] ?? $webhook['target_url'] ?? $webhook['url'] ?? '';
                $webhook_event = $webhook['event'] ?? '';
                $existing_urls[$webhook_event] = $webhook_url_value;
                error_log('LCD People: Found existing webhook - Event: ' . $webhook_event . ', Target: ' . $webhook_url_value);
            }
        }
        
        foreach ($events_to_register as $event) {
            // Skip if webhook already exists for this event and URL
            if (isset($existing_urls[$event]) && $existing_urls[$event] === $webhook_url) {
                $results[$event] = array(
                    'success' => true,
                    'message' => __('Webhook already registered.', 'lcd-people'),
                    'skipped' => true
                );
                $success_count++;
                error_log('LCD People: Skipping webhook for event ' . $event . ' - already registered');
                continue;
            }
            
            $result = $this->create_webhook($webhook_url, $event);
            $results[$event] = $result;
            
            if ($result['success']) {
                $success_count++;
            }
        }
        
        error_log('LCD People: Webhook registration complete - ' . $success_count . ' of ' . count($events_to_register) . ' succeeded');
        
        return array(
            'success' => $success_count > 0,
            'message' => sprintf(__('%d of %d webhooks registered successfully.', 'lcd-people'), $success_count, count($events_to_register)),
            'details' => $results
        );
    }
    
    /**
     * Get webhook status for display in admin
     * 
     * @return array Status information
     */
    public function get_webhook_status() {
        $webhook_url = rest_url('lcd-people/v1/callhub-webhook');
        error_log('LCD People: get_webhook_status - Looking for URL: ' . $webhook_url);
        
        $existing = $this->get_webhooks();
        
        if (!$existing['success']) {
            error_log('LCD People: get_webhook_status - Failed to get webhooks: ' . ($existing['message'] ?? 'unknown error'));
            return array(
                'registered' => false,
                'message' => $existing['message'] ?? __('Could not check webhook status.', 'lcd-people'),
                'webhooks' => array()
            );
        }
        
        error_log('LCD People: get_webhook_status - Found ' . count($existing['webhooks']) . ' total webhooks');
        
        $our_webhooks = array();
        foreach ($existing['webhooks'] as $webhook) {
            // Log all webhook fields to find the right one
            error_log('LCD People: get_webhook_status - Full webhook data: ' . json_encode($webhook));
            
            // CallHub returns 'target' for the destination URL we registered
            $webhook_url_value = $webhook['target'] ?? $webhook['target_url'] ?? $webhook['url'] ?? '';
            error_log('LCD People: get_webhook_status - Checking webhook target: ' . $webhook_url_value . ' (event: ' . ($webhook['event'] ?? 'unknown') . ')');
            if ($webhook_url_value === $webhook_url) {
                $our_webhooks[] = $webhook;
            }
        }
        
        error_log('LCD People: get_webhook_status - Matched ' . count($our_webhooks) . ' webhooks for this site');
        
        return array(
            'registered' => !empty($our_webhooks),
            'message' => !empty($our_webhooks) ? 
                        sprintf(__('%d webhook(s) registered for this site.', 'lcd-people'), count($our_webhooks)) :
                        __('No webhooks registered for this site.', 'lcd-people'),
            'webhooks' => $our_webhooks
        );
    }
}

