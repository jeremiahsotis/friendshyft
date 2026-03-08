<?php
/**
 * FriendShyft Email Processor
 * 
 * Orchestrates the full email processing workflow
 */

class FS_Email_Processor {
    
    /**
     * Process incoming volunteer interest email
     * 
     * @param array $email_data Raw email data (from, subject, body)
     * @return array Processing result
     */
    public static function process_email($email_data) {
        $result = array(
            'success' => false,
            'log_id' => null,
            'volunteer_id' => null,
            'status' => null,
            'message' => null
        );
        
        // Step 1: Log the email
        $log_id = FS_Email_Parser::log_email($email_data);
        $result['log_id'] = $log_id;
        
        // Step 2: Parse the email
        $parsed_data = FS_Email_Parser::parse_volunteer_hub_email($email_data['body']);
        
        if (is_wp_error($parsed_data)) {
            // Parsing failed
            $error_message = $parsed_data->get_error_message();
            FS_Email_Parser::update_log($log_id, 'failed', null, null, $error_message);
            FS_Email_Parser::notify_admin_on_error($error_message, $email_data['body'], $log_id);
            
            $result['status'] = 'failed';
            $result['message'] = $error_message;
            return $result;
        }
        
        // Step 3: Check for duplicate
        $existing_volunteer = FS_Email_Parser::check_duplicate($parsed_data['email']);
        if ($existing_volunteer) {
            // Volunteer already exists - log as duplicate and notify admin
            FS_Email_Parser::update_log($log_id, 'duplicate', $parsed_data, $existing_volunteer, 'Volunteer already exists');
            
            // Still record the new interest
            self::add_interest_to_existing_volunteer($existing_volunteer, $parsed_data);
            
            // Notify admin about duplicate
            self::notify_admin_duplicate($existing_volunteer, $parsed_data, $log_id);
            
            $result['success'] = true;
            $result['volunteer_id'] = $existing_volunteer;
            $result['status'] = 'duplicate';
            $result['message'] = 'Volunteer already exists - interest logged';
            return $result;
        }
        
        // Step 4: Create volunteer
        $volunteer_id = FS_Email_Parser::create_volunteer_from_email($parsed_data);
        
        if (is_wp_error($volunteer_id)) {
            // Creation failed
            $error_message = $volunteer_id->get_error_message();
            FS_Email_Parser::update_log($log_id, 'failed', $parsed_data, null, $error_message);
            FS_Email_Parser::notify_admin_on_error($error_message, $email_data['body'], $log_id);
            
            $result['status'] = 'failed';
            $result['message'] = $error_message;
            return $result;
        }
        
        // Step 5: Send welcome email
        $email_sent = FS_Email_Parser::send_welcome_email($volunteer_id);
        
        if (!$email_sent) {
            // Welcome email failed, but volunteer was created
            FS_Email_Parser::update_log($log_id, 'success_no_email', $parsed_data, $volunteer_id, 'Welcome email failed to send');
            
            $result['success'] = true;
            $result['volunteer_id'] = $volunteer_id;
            $result['status'] = 'success_no_email';
            $result['message'] = 'Volunteer created but welcome email failed';
            return $result;
        }
        
        // Step 6: Mark as success
        FS_Email_Parser::update_log($log_id, 'success', $parsed_data, $volunteer_id);
        
        $result['success'] = true;
        $result['volunteer_id'] = $volunteer_id;
        $result['status'] = 'success';
        $result['message'] = 'Volunteer created and welcome email sent';
        
        return $result;
    }
    
    /**
     * Add new interest to existing volunteer
     * 
     * @param int $volunteer_id Volunteer ID
     * @param array $parsed_data Parsed email data
     */
    private static function add_interest_to_existing_volunteer($volunteer_id, $parsed_data) {
        global $wpdb;
        
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
    }
    
    /**
     * Notify admin about duplicate volunteer submission
     * 
     * @param int $volunteer_id Existing volunteer ID
     * @param array $parsed_data New submission data
     * @param int $log_id Email log ID
     */
    private static function notify_admin_duplicate($volunteer_id, $parsed_data, $log_id) {
        global $wpdb;
        
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));
        
        $admin_email = get_option('fs_email_admin_notification', get_option('admin_email'));
        
        $subject = 'FriendShyft: Duplicate Volunteer Interest';
        
        $message = "A volunteer interest email was received for an existing volunteer.\n\n";
        $message .= "Existing Volunteer:\n";
        $message .= "  Name: {$volunteer->name}\n";
        $message .= "  Email: {$volunteer->email}\n";
        $message .= "  ID: {$volunteer_id}\n\n";
        $message .= "New Interest Expressed:\n";
        $message .= "  Opportunity: {$parsed_data['interest']}\n";
        if (!empty($parsed_data['notes'])) {
            $message .= "  Notes: {$parsed_data['notes']}\n";
        }
        $message .= "\n";
        $message .= "The new interest has been logged to their profile.\n\n";
        $message .= "View volunteer: " . admin_url("admin.php?page=friendshyft-volunteers&action=edit&volunteer_id={$volunteer_id}") . "\n";
        $message .= "View email log: " . admin_url("admin.php?page=friendshyft-email-log&log_id={$log_id}");
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Process all pending emails (for cron job)
     * 
     * @return array Summary of processing results
     */
    public static function process_pending_emails() {
        global $wpdb;
        
        $pending = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_email_log 
             WHERE status = 'pending' 
             ORDER BY received_date ASC 
             LIMIT 50"
        );
        
        $summary = array(
            'processed' => 0,
            'success' => 0,
            'duplicate' => 0,
            'failed' => 0
        );
        
        foreach ($pending as $email_log) {
            $email_data = array(
                'from' => $email_log->from_address,
                'subject' => $email_log->subject,
                'body' => $email_log->raw_body
            );
            
            // Process using existing log ID
            $parsed_data = FS_Email_Parser::parse_volunteer_hub_email($email_log->raw_body);
            
            if (is_wp_error($parsed_data)) {
                FS_Email_Parser::update_log($email_log->id, 'failed', null, null, $parsed_data->get_error_message());
                $summary['failed']++;
            } else {
                $existing = FS_Email_Parser::check_duplicate($parsed_data['email']);
                
                if ($existing) {
                    self::add_interest_to_existing_volunteer($existing, $parsed_data);
                    FS_Email_Parser::update_log($email_log->id, 'duplicate', $parsed_data, $existing);
                    $summary['duplicate']++;
                } else {
                    $volunteer_id = FS_Email_Parser::create_volunteer_from_email($parsed_data);
                    
                    if (is_wp_error($volunteer_id)) {
                        FS_Email_Parser::update_log($email_log->id, 'failed', $parsed_data, null, $volunteer_id->get_error_message());
                        $summary['failed']++;
                    } else {
                        FS_Email_Parser::send_welcome_email($volunteer_id);
                        FS_Email_Parser::update_log($email_log->id, 'success', $parsed_data, $volunteer_id);
                        $summary['success']++;
                    }
                }
            }
            
            $summary['processed']++;
        }
        
        return $summary;
    }
}
