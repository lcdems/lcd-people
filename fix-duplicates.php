<?php
/**
 * Utility script to fix duplicate primary status issues in People plugin
 * Run this from wp-admin or via WP-CLI to clean up existing duplicates
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fix all duplicate primary status issues
 * This addresses the existing problems that caused renewals to create new records
 */
function lcd_people_fix_all_duplicates() {
    global $wpdb;
    
    $results = array(
        'processed_emails' => 0,
        'fixed_duplicates' => 0,
        'errors' => array(),
        'details' => array()
    );
    
    // Get all unique emails that have multiple person records
    $emails_with_duplicates = $wpdb->get_results("
        SELECT pm.meta_value as email, COUNT(*) as count
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = '_lcd_person_email' 
        AND p.post_type = 'lcd_person' 
        AND p.post_status = 'publish'
        AND pm.meta_value != ''
        GROUP BY pm.meta_value
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC
    ");
    
    if (!empty($emails_with_duplicates)) {
        // Get the ActBlue handler instance
        $lcd_people = LCD_People::get_instance();
        if (!$lcd_people) {
            $results['errors'][] = 'Could not access People plugin instance';
            return $results;
        }
        
        // Try to get ActBlue handler, or create it if missing
        $actblue_handler = null;
        if (isset($lcd_people->actblue_handler) && $lcd_people->actblue_handler) {
            $actblue_handler = $lcd_people->actblue_handler;
        } else {
            // The handler should exist but may not be properly initialized
            // Try to ensure the ActBlue handler class is loaded
            $actblue_handler_file = plugin_dir_path(__FILE__) . 'includes/class-lcd-people-actblue-handler.php';
            if (file_exists($actblue_handler_file)) {
                require_once $actblue_handler_file;
                if (class_exists('LCD_People_ActBlue_Handler')) {
                    $actblue_handler = new LCD_People_ActBlue_Handler($lcd_people);
                }
            }
        }
        
        if (!$actblue_handler) {
            $results['errors'][] = 'Could not access or create ActBlue handler. Plugin may not be fully initialized.';
            return $results;
        }
        
        foreach ($emails_with_duplicates as $email_data) {
            $email = $email_data->email;
            $count = $email_data->count;
            
            $results['processed_emails']++;
            
            // Get details before fixing
            $people_before = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID, p.post_title, pm_primary.meta_value as is_primary, pm_status.meta_value as status
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_lcd_person_email'
                LEFT JOIN {$wpdb->postmeta} pm_primary ON p.ID = pm_primary.post_id AND pm_primary.meta_key = '_lcd_person_is_primary'
                LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_lcd_person_membership_status'
                WHERE pm_email.meta_value = %s AND p.post_type = 'lcd_person' AND p.post_status = 'publish'
                ORDER BY p.ID
            ", $email));
            
            $primary_count_before = 0;
            foreach ($people_before as $person) {
                if ($person->is_primary === '1') {
                    $primary_count_before++;
                }
            }
            
            // Fix the duplicates using the ActBlue handler if available, or directly if not
            $fix_result = false;
            if ($actblue_handler && method_exists($actblue_handler, 'fix_duplicate_primary_status')) {
                $fix_result = $actblue_handler->fix_duplicate_primary_status($email);
            } else {
                // Fallback: implement the fix directly
                $fix_result = lcd_people_fix_duplicate_primary_status_direct($email);
            }
            
            if ($fix_result) {
                // Get details after fixing
                $people_after = $wpdb->get_results($wpdb->prepare("
                    SELECT p.ID, p.post_title, pm_primary.meta_value as is_primary, pm_status.meta_value as status
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_lcd_person_email'
                    LEFT JOIN {$wpdb->postmeta} pm_primary ON p.ID = pm_primary.post_id AND pm_primary.meta_key = '_lcd_person_is_primary'
                    LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_lcd_person_membership_status'
                    WHERE pm_email.meta_value = %s AND p.post_type = 'lcd_person' AND p.post_status = 'publish'
                    ORDER BY p.ID
                ", $email));
                
                $primary_count_after = 0;
                $primary_person = null;
                foreach ($people_after as $person) {
                    if ($person->is_primary === '1') {
                        $primary_count_after++;
                        $primary_person = $person;
                    }
                }
                
                if ($primary_count_before !== 1 && $primary_count_after === 1) {
                    $results['fixed_duplicates']++;
                }
                
                $results['details'][] = array(
                    'email' => $email,
                    'total_records' => $count,
                    'primaries_before' => $primary_count_before,
                    'primaries_after' => $primary_count_after,
                    'new_primary' => $primary_person ? $primary_person->post_title . ' (ID: ' . $primary_person->ID . ')' : 'None',
                    'status' => ($primary_count_after === 1) ? 'Fixed' : 'Needs manual review'
                );
            } else {
                $results['errors'][] = 'Failed to fix duplicates for: ' . $email;
            }
        }
    }
    
    return $results;
}

/**
 * Direct implementation of fixing duplicate primary status
 * Used as fallback when ActBlue handler is not available
 */
function lcd_people_fix_duplicate_primary_status_direct($email) {
    global $wpdb;
    
    if (empty($email)) {
        return false;
    }

    // Get all people with this email
    $people = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, pm_status.meta_value as membership_status, pm_primary.meta_value as is_primary
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_lcd_person_email'
        LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_lcd_person_membership_status'
        LEFT JOIN {$wpdb->postmeta} pm_primary ON p.ID = pm_primary.post_id AND pm_primary.meta_key = '_lcd_person_is_primary'
        WHERE pm_email.meta_value = %s AND p.post_type = 'lcd_person' AND p.post_status = 'publish'
        ORDER BY p.ID
    ", $email));

    if (count($people) <= 1) {
        return true; // Nothing to fix
    }

    // Count current primaries
    $current_primaries = 0;
    $primary_person = null;
    foreach ($people as $person) {
        if ($person->is_primary === '1') {
            $current_primaries++;
            $primary_person = $person;
        }
    }

    // If we already have exactly one primary, nothing to do
    if ($current_primaries === 1) {
        return true;
    }

    // First, remove primary status from all people for this email
    foreach ($people as $person) {
        update_post_meta($person->ID, '_lcd_person_is_primary', '0');
    }

    // Now determine who should be primary
    $new_primary = null;

    // Priority 1: Active/Paid member
    foreach ($people as $person) {
        if (in_array(strtolower($person->membership_status), ['active', 'paid'])) {
            $new_primary = $person;
            break;
        }
    }

    // Priority 2: Grace period member
    if (!$new_primary) {
        foreach ($people as $person) {
            if (strtolower($person->membership_status) === 'grace') {
                $new_primary = $person;
                break;
            }
        }
    }

    // Priority 3: Any other member
    if (!$new_primary) {
        foreach ($people as $person) {
            if (!empty($person->membership_status) && strtolower($person->membership_status) !== 'none') {
                $new_primary = $person;
                break;
            }
        }
    }

    // Priority 4: Oldest record
    if (!$new_primary) {
        $new_primary = $people[0]; // Already sorted by ID (oldest first)
    }

    // Set the new primary
    if ($new_primary) {
        update_post_meta($new_primary->ID, '_lcd_person_is_primary', '1');
        return true;
    }

    return false;
}

/**
 * Display results in admin or CLI
 */
function lcd_people_display_fix_results($results) {
    if (is_admin()) {
        echo '<div class="notice notice-info"><p><strong>Duplicate Fix Results:</strong></p>';
        echo '<p>Processed ' . $results['processed_emails'] . ' emails with duplicates</p>';
        echo '<p>Fixed ' . $results['fixed_duplicates'] . ' duplicate issues</p>';
        
        if (!empty($results['errors'])) {
            echo '<p style="color: red;">Errors: ' . implode(', ', $results['errors']) . '</p>';
        }
        
        if (!empty($results['details'])) {
            echo '<h4>Details:</h4>';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Email</th><th>Records</th><th>Primaries Before</th><th>Primaries After</th><th>New Primary</th><th>Status</th></tr></thead>';
            echo '<tbody>';
            foreach ($results['details'] as $detail) {
                echo '<tr>';
                echo '<td>' . esc_html($detail['email']) . '</td>';
                echo '<td>' . $detail['total_records'] . '</td>';
                echo '<td>' . $detail['primaries_before'] . '</td>';
                echo '<td>' . $detail['primaries_after'] . '</td>';
                echo '<td>' . esc_html($detail['new_primary']) . '</td>';
                echo '<td>' . ($detail['status'] === 'Fixed' ? '<span style="color: green;">Fixed</span>' : '<span style="color: orange;">Needs Review</span>') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    } else {
        // CLI output
        echo "Duplicate Fix Results:\n";
        echo "Processed {$results['processed_emails']} emails with duplicates\n";
        echo "Fixed {$results['fixed_duplicates']} duplicate issues\n";
        
        if (!empty($results['errors'])) {
            echo "Errors: " . implode(', ', $results['errors']) . "\n";
        }
    }
}

// If running from admin, add admin page
if (is_admin()) {
    add_action('admin_menu', function() {
        add_submenu_page(
            'edit.php?post_type=lcd_person',
            'Fix Duplicates',
            'Fix Duplicates',
            'manage_options',
            'lcd-people-fix-duplicates',
            function() {
                echo '<div class="wrap">';
                echo '<h1>Fix People Duplicate Issues</h1>';
                echo '<p>This utility fixes duplicate primary status issues that can cause renewals to create new records instead of updating existing ones.</p>';
                
                if (isset($_POST['fix_duplicates']) && wp_verify_nonce($_POST['_wpnonce'], 'fix_duplicates')) {
                    $results = lcd_people_fix_all_duplicates();
                    lcd_people_display_fix_results($results);
                } else {
                    echo '<form method="post">';
                    wp_nonce_field('fix_duplicates');
                    echo '<p><input type="submit" name="fix_duplicates" class="button-primary" value="Fix All Duplicate Issues" /></p>';
                    echo '<p><em>This will ensure each email address has exactly one primary person record.</em></p>';
                    echo '</form>';
                }
                
                echo '</div>';
            }
        );
    });
}

// If running via WP-CLI, add the command
if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
    $wp_cli = 'WP_CLI';
    $wp_cli::add_command('lcd-people fix-duplicates', function() {
        $results = lcd_people_fix_all_duplicates();
        lcd_people_display_fix_results($results);
    });
} 