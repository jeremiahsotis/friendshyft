<?php
/**
 * FriendShyft Email Parser
 * 
 * Parses emails from Community Volunteer Hub and extracts volunteer information
 */

class FS_Email_Parser {
    
    /**
     * Parse volunteer hub email and extract structured data
     * 
     * @param string $raw_email Raw email body
     * @return array|WP_Error Parsed data or error
     */
    public static function parse_volunteer_hub_email($raw_email) {
        $data = array(
            'name' => null,
            'email' => null,
            'phone' => null,
            'phone_cell' => null,
            'interest' => null,
            'notes' => null
        );
        
        // Extract Volunteer Opportunity
        if (preg_match('/Volunteer Opportunity:\s*(.+?)(?:\n|$)/i', $raw_email, $matches)) {
            $data['interest'] = trim($matches[1]);
        }
        
        // Extract Submitter (name)
        if (preg_match('/Submitter:\s*(.+?)(?:\n|$)/i', $raw_email, $matches)) {
            $data['name'] = trim($matches[1]);
        }
        
        // Extract Email
        if (preg_match('/Email:\s*(.+?)(?:\n|$)/i', $raw_email, $matches)) {
            $email = trim($matches[1]);
            if (is_email($email)) {
                $data['email'] = $email;
            }
        }
        
        // Extract Phone
        if (preg_match('/Phone:\s*(.+?)(?:\n|$)/i', $raw_email, $matches)) {
            $data['phone'] = self::clean_phone($matches[1]);
        }
        
        // Extract Cell
        if (preg_match('/Cell:\s*(.+?)(?:\n|$)/i', $raw_email, $matches)) {
            $data['phone_cell'] = self::clean_phone($matches[1]);
        }
        
        // Extract Additional Notes
        if (preg_match('/Additional Notes:\s*(.+?)(?:Thank you!|$)/is', $raw_email, $matches)) {
            $notes = trim($matches[1]);
            if (!empty($notes)) {
                $data['notes'] = $notes;
            }
        }
        
        // Validate required fields
        $errors = array();
        if (empty($data['name'])) {
            $errors[] = 'Missing name';
        }
        if (empty($data['email'])) {
            $errors[] = 'Missing or invalid email';
        }
        if (empty($data['interest'])) {
            $errors[] = 'Missing volunteer opportunity interest';
        }
        
        if (!empty($errors)) {
            return new WP_Error('parse_failed', 'Failed to parse email: ' . implode(', ', $errors), $data);
        }
        
        return $data;
    }
    
    /**
     * Clean phone number to consistent format
     * 
     * @param string $phone Raw phone number
     * @return string Cleaned phone
     */
    private static function clean_phone($phone) {
        $phone = trim($phone);
        // Remove everything except digits and x (for extensions)
        $phone = preg_replace('/[^0-9x]/i', '', $phone);
        return $phone;
    }
    
    /**
     * Log raw email for processing
     * 
     * @param array $email_data Email metadata and body
     * @return int Email log ID
     */
    public static function log_email($email_data) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'fs_email_log',
            array(
                'received_date' => current_time('mysql'),
                'from_address' => $email_data['from'],
                'subject' => $email_data['subject'],
                'raw_body' => $email_data['body'],
                'status' => 'pending'
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update email log after processing
     * 
     * @param int $log_id Email log ID
     * @param string $status Status (success, failed, duplicate)
     * @param array $parsed_data Parsed data (optional)
     * @param int $volunteer_id Created volunteer ID (optional)
     * @param string $error_message Error message (optional)
     */
    public static function update_log($log_id, $status, $parsed_data = null, $volunteer_id = null, $error_message = null) {
        global $wpdb;
        
        $update_data = array(
            'status' => $status,
            'processed_date' => current_time('mysql')
        );
        
        if ($parsed_data) {
            $update_data['parsed_data'] = json_encode($parsed_data);
        }
        
        if ($volunteer_id) {
            $update_data['volunteer_id'] = $volunteer_id;
        }
        
        if ($error_message) {
            $update_data['error_message'] = $error_message;
        }
        
        $wpdb->update(
            $wpdb->prefix . 'fs_email_log',
            $update_data,
            array('id' => $log_id)
        );
    }
    
    /**
     * Check if volunteer already exists by email
     * 
     * @param string $email Email address
     * @return int|false Volunteer ID if exists, false otherwise
     */
    public static function check_duplicate($email) {
        global $wpdb;
        
        $volunteer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE email = %s",
            $email
        ));
        
        return $volunteer_id ? (int)$volunteer_id : false;
    }
    
    /**
     * Create volunteer from parsed email data
     * 
     * @param array $parsed_data Parsed volunteer data
     * @return int|WP_Error Volunteer ID or error
     */
    public static function create_volunteer_from_email($parsed_data) {
        global $wpdb;
        
        // Check for duplicate
        $existing_id = self::check_duplicate($parsed_data['email']);
        if ($existing_id) {
            return new WP_Error('duplicate', 'Volunteer already exists', array('volunteer_id' => $existing_id));
        }
        
        // Generate access token
        $access_token = wp_generate_password(24, false);
        
        // Insert volunteer
        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_volunteers',
            array(
                'name' => $parsed_data['name'],
                'email' => $parsed_data['email'],
                'phone' => $parsed_data['phone'],
                'phone_cell' => $parsed_data['phone_cell'],
                'access_token' => $access_token,
                'source' => 'email_hub',
                'created_date' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to create volunteer', $wpdb->last_error);
        }
        
        $volunteer_id = $wpdb->insert_id;
        
        // Record interest
        if (!empty($parsed_data['interest'])) {
            $wpdb->insert(
                $wpdb->prefix . 'fs_volunteer_interests',
                array(
                    'volunteer_id' => $volunteer_id,
                    'interest' => $parsed_data['interest'],
                    'notes' => $parsed_data['notes'],
                    'source' => 'email_hub',
                    'created_date' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s')
            );
        }
        
        return $volunteer_id;
    }
    
    /**
     * Send welcome email to new volunteer
     * 
     * @param int $volunteer_id Volunteer ID
     * @return bool Success
     */
    public static function send_welcome_email($volunteer_id) {
        global $wpdb;
        
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));
        
        if (!$volunteer) {
            return false;
        }
        
        // Get portal URL with magic link
        $portal_url = add_query_arg(
            array('token' => $volunteer->access_token),
            home_url('/volunteer-portal/')
        );
        
        $subject = 'Welcome to ' . get_bloginfo('name') . ' Volunteer Portal';
        
        $message = "Hi {$volunteer->name},\n\n";
        $message .= "Thank you for your interest in volunteering with us!\n\n";
        $message .= "We've created your volunteer account. You can access the volunteer portal using this link:\n\n";
        $message .= $portal_url . "\n\n";
        $message .= "In the portal, you can:\n";
        $message .= "- Browse available volunteer opportunities\n";
        $message .= "- Sign up for shifts\n";
        $message .= "- Track your volunteer hours\n";
        $message .= "- Update your profile\n\n";
        $message .= "We look forward to seeing you!\n\n";
        $message .= "Best regards,\n";
        $message .= get_bloginfo('name');
        
        return wp_mail($volunteer->email, $subject, $message);
    }
    
    /**
     * Notify admin of processing error
     * 
     * @param string $error Error message
     * @param string $raw_email Raw email body
     * @param int $log_id Email log ID
     */
    public static function notify_admin_on_error($error, $raw_email, $log_id) {
        $admin_email = get_option('fs_email_admin_notification', get_option('admin_email'));
        
        $subject = 'FriendShyft: Email Processing Error';
        
        $message = "An error occurred while processing a volunteer interest email.\n\n";
        $message .= "Error: {$error}\n\n";
        $message .= "Email Log ID: {$log_id}\n\n";
        $message .= "View in admin: " . admin_url("admin.php?page=friendshyft-email-log&log_id={$log_id}") . "\n\n";
        $message .= "--- RAW EMAIL ---\n\n";
        $message .= $raw_email;
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Simple parse wrapper for admin UI (just returns parsed data without creating volunteer)
     * 
     * @param string $email_body Email body to parse
     * @return array Result with success and parsed data
     */
    public static function parse_email($email_body) {
        $parsed = self::parse_volunteer_hub_email($email_body);
        
        if (is_wp_error($parsed)) {
            return array(
                'success' => false,
                'error' => $parsed->get_error_message()
            );
        }
        
        return array_merge(
            array('success' => true),
            $parsed
        );
    }
    
    /**
     * Parse and process email (creates volunteer) - wrapper for admin UI
     * 
     * @param string $email_body Email body
     * @return array Result with success, volunteer_id, etc
     */
    public static function parse_and_process($email_body) {
        $email_data = array(
            'from' => 'manual@admin',
            'subject' => 'Manual Processing',
            'body' => $email_body
        );
        
        $result = FS_Email_Processor::process_email($email_data);
        
        // Format for admin UI
        return array(
            'success' => $result['success'],
            'volunteer_id' => $result['volunteer_id'],
            'error' => $result['success'] ? null : $result['message'],
            'status' => $result['status']
        );
    }
}
