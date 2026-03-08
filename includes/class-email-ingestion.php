<?php
/**
 * FriendShyft Email Ingestion
 * 
 * Handles receiving emails via API endpoint or IMAP polling
 */

class FS_Email_Ingestion {

    /**
     * Maximum email size in bytes (10MB)
     * Can be filtered via 'fs_email_max_size' hook
     */
    const MAX_EMAIL_SIZE = 10485760; // 10MB

    /**
     * Initialize hooks and endpoints
     */
    public static function init() {
        // Register REST API endpoint for webhook
        add_action('rest_api_init', array(__CLASS__, 'register_api_endpoint'));
    }
    
    /**
     * Register REST API endpoint for incoming emails
     */
    public static function register_api_endpoint() {
        register_rest_route('friendshyft/v1', '/email/ingest', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => array(__CLASS__, 'verify_token'),
        ));
    }
    
    /**
     * Verify security token
     */
    public static function verify_token($request) {
        $token = $request->get_header('X-FriendShyft-Token');
        $stored_token = get_option('fs_email_ingest_token');
        
        if (empty($stored_token)) {
            return new WP_Error('no_token', 'Email ingestion not configured', array('status' => 503));
        }
        
        return hash_equals($stored_token, $token);
    }
    
    /**
     * Handle incoming webhook
     */
    public static function handle_webhook($request) {
        $params = $request->get_json_params();

        if (empty($params['raw_email'])) {
            return new WP_Error('missing_email', 'No email body provided', array('status' => 400));
        }

        // Extract from and subject if provided
        $from = $params['from'] ?? 'webhook@community-volunteer-hub.org';
        $subject = $params['subject'] ?? 'A New Response To Your Need';
        $body = $params['raw_email'];

        // Validate email size
        $size_check = self::validate_email_size($body);
        if (is_wp_error($size_check)) {
            return $size_check;
        }

        $email_data = array(
            'from' => $from,
            'subject' => $subject,
            'body' => $body
        );

        $result = FS_Email_Processor::process_email($email_data);

        return rest_ensure_response(array(
            'success' => $result['success'],
            'status' => $result['status'],
            'message' => $result['message'],
            'volunteer_id' => $result['volunteer_id']
        ));
    }
    
    /**
     * Get API endpoint URL
     */
    public static function get_endpoint_url() {
        return rest_url('friendshyft/v1/email/ingest');
    }
    
    /**
     * Generate secure token
     */
    public static function generate_token() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Check inbox via IMAP (if configured)
     * 
     * @return array|WP_Error Summary of emails processed
     */
    public static function check_imap_inbox() {
        // Get IMAP settings
        $imap_server = get_option('fs_email_imap_server');
        $imap_port = get_option('fs_email_imap_port', 993);
        $imap_ssl = get_option('fs_email_imap_ssl', true);
        $email_address = get_option('fs_email_inbox_address');
        $email_password = get_option('fs_email_inbox_password');
        
        if (empty($imap_server) || empty($email_address) || empty($email_password)) {
            return new WP_Error('config_missing', 'IMAP not configured');
        }
        
        // Build mailbox string
        $ssl_flag = $imap_ssl ? '/ssl' : '';
        $mailbox = "{{$imap_server}:{$imap_port}/imap{$ssl_flag}}INBOX";
        
        // Connect to mailbox
        $inbox = @imap_open($mailbox, $email_address, $email_password);
        
        if (!$inbox) {
            return new WP_Error('connection_failed', 'Failed to connect: ' . imap_last_error());
        }
        
        $summary = array(
            'checked' => 0,
            'processed' => 0,
            'success' => 0,
            'duplicate' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        // Get unseen emails
        $emails = imap_search($inbox, 'UNSEEN');
        
        if (!$emails) {
            imap_close($inbox);
            return $summary; // No new emails
        }
        
        $summary['checked'] = count($emails);
        
        foreach ($emails as $email_number) {
            try {
                // Get header info
                $header = imap_headerinfo($inbox, $email_number);
                $from = isset($header->from[0]) ? $header->from[0]->mailbox . '@' . $header->from[0]->host : 'unknown';
                $subject = isset($header->subject) ? $header->subject : '(no subject)';
                
                // Get body
                $body = imap_fetchbody($inbox, $email_number, 1);

                // Decode if needed
                $encoding = imap_fetchstructure($inbox, $email_number);
                if (isset($encoding->parts[0]->encoding)) {
                    switch ($encoding->parts[0]->encoding) {
                        case 3: // BASE64
                            $body = base64_decode($body);
                            break;
                        case 4: // QUOTED-PRINTABLE
                            $body = quoted_printable_decode($body);
                            break;
                    }
                }

                // Validate email size
                $size_check = self::validate_email_size($body);
                if (is_wp_error($size_check)) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Email too large: ' . $size_check->get_error_message();
                    imap_setflag_full($inbox, $email_number, "\\Seen");
                    continue;
                }

                $email_data = array(
                    'from' => $from,
                    'subject' => $subject,
                    'body' => $body
                );

                // Process
                $result = FS_Email_Processor::process_email($email_data);
                
                $summary['processed']++;
                
                if ($result['status'] === 'success' || $result['status'] === 'success_no_email') {
                    $summary['success']++;
                    imap_setflag_full($inbox, $email_number, "\\Seen");
                } elseif ($result['status'] === 'duplicate') {
                    $summary['duplicate']++;
                    imap_setflag_full($inbox, $email_number, "\\Seen");
                } else {
                    $summary['failed']++;
                    $summary['errors'][] = $result['message'];
                }
                
            } catch (Exception $e) {
                $summary['failed']++;
                $summary['errors'][] = $e->getMessage();
            }
        }
        
        imap_close($inbox);
        
        return $summary;
    }
    
    /**
     * Test IMAP connection
     */
    public static function test_imap_connection() {
        $imap_server = get_option('fs_email_imap_server');
        $imap_port = get_option('fs_email_imap_port', 993);
        $imap_ssl = get_option('fs_email_imap_ssl', true);
        $email_address = get_option('fs_email_inbox_address');
        $email_password = get_option('fs_email_inbox_password');
        
        if (empty($imap_server) || empty($email_address) || empty($email_password)) {
            return new WP_Error('config_missing', 'IMAP settings incomplete');
        }
        
        $ssl_flag = $imap_ssl ? '/ssl' : '';
        $mailbox = "{{$imap_server}:{$imap_port}/imap{$ssl_flag}}INBOX";
        
        $inbox = @imap_open($mailbox, $email_address, $email_password);
        
        if (!$inbox) {
            return new WP_Error('connection_failed', imap_last_error());
        }
        
        $status = imap_status($inbox, $mailbox, SA_ALL);
        imap_close($inbox);
        
        return array(
            'success' => true,
            'message' => sprintf('Connected successfully. %d unread messages.', $status->unseen)
        );
    }
    
    /**
     * Schedule IMAP cron check
     */
    public static function schedule_imap_check() {
        if (!wp_next_scheduled('fs_check_imap_inbox')) {
            wp_schedule_event(time(), 'hourly', 'fs_check_imap_inbox');
        }
    }
    
    /**
     * Unschedule IMAP cron check
     */
    public static function unschedule_imap_check() {
        $timestamp = wp_next_scheduled('fs_check_imap_inbox');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fs_check_imap_inbox');
        }
    }

    /**
     * Validate email size to prevent memory exhaustion
     *
     * @param string $email_body The email body to validate
     * @return true|WP_Error True if valid, WP_Error if too large
     */
    public static function validate_email_size($email_body) {
        $max_size = apply_filters('fs_email_max_size', self::MAX_EMAIL_SIZE);
        $email_size = strlen($email_body);

        if ($email_size > $max_size) {
            $max_mb = number_format($max_size / 1048576, 1);
            $actual_mb = number_format($email_size / 1048576, 1);

            return new WP_Error(
                'email_too_large',
                sprintf(
                    'Email size (%s MB) exceeds maximum allowed size (%s MB)',
                    $actual_mb,
                    $max_mb
                ),
                array('status' => 413) // 413 Payload Too Large
            );
        }

        return true;
    }
}

// Hook IMAP cron
add_action('fs_check_imap_inbox', function() {
    if (get_option('fs_email_imap_enabled')) {
        $result = FS_Email_Ingestion::check_imap_inbox();
        
        if (!is_wp_error($result) && $result['processed'] > 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'FriendShyft IMAP Check: %d processed (%d success, %d duplicate, %d failed)',
                    $result['processed'],
                    $result['success'],
                    $result['duplicate'],
                    $result['failed']
                ));
            }
        }
    }
});
