<?php
if (!defined('ABSPATH')) exit;

class FS_Attendance_Confirmation {
    
    public static function init() {
        // Schedule reminder checks
        add_action('fs_send_attendance_reminders', array(__CLASS__, 'send_reminders'));
        
        // AJAX handlers
        add_action('wp_ajax_fs_confirm_attendance', array(__CLASS__, 'ajax_confirm_attendance'));
        add_action('wp_ajax_nopriv_fs_confirm_attendance', array(__CLASS__, 'ajax_confirm_attendance'));
        
        add_action('wp_ajax_fs_cancel_attendance', array(__CLASS__, 'ajax_cancel_attendance'));
        add_action('wp_ajax_nopriv_fs_cancel_attendance', array(__CLASS__, 'ajax_cancel_attendance'));
    }
    
    /**
     * Send attendance reminder emails
     * Should be run daily via cron
     */
    public static function send_reminders() {
        global $wpdb;
        
        // Get signups for opportunities happening in the next 24-48 hours
        // that haven't been confirmed yet
        $reminder_window_start = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $reminder_window_end = date('Y-m-d H:i:s', strtotime('+48 hours'));
        
        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.title as opportunity_title, o.event_date, o.datetime_start, o.datetime_end,
                    r.name as role_name, p.name as program_name,
                    v.name as volunteer_name, v.email as volunteer_email, v.access_token
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_roles r ON o.role_id = r.id
             JOIN {$wpdb->prefix}fs_programs p ON r.program_id = p.id
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             WHERE s.status = 'confirmed'
             AND s.attendance_confirmed = 0
             AND s.reminder_sent = 0
             AND o.datetime_start BETWEEN %s AND %s
             ORDER BY o.event_date, o.datetime_start",
            $reminder_window_start,
            $reminder_window_end
        ));
        
        foreach ($signups as $signup) {
            self::send_reminder_email($signup);
            
            // Mark reminder as sent
            $wpdb->update(
                $wpdb->prefix . 'fs_signups',
                array('reminder_sent' => 1),
                array('id' => $signup->id)
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Sent " . count($signups) . " attendance reminder emails");
        }
    }
    
    /**
     * Send individual reminder email
     */
    private static function send_reminder_email($signup) {
        $event_datetime = date('l, F j, Y \a\t g:i A', strtotime($signup->datetime_start));
        
        // Build confirmation link
        $confirm_url = add_query_arg(array(
            'action' => 'confirm',
            'signup_id' => $signup->id,
            'token' => $signup->access_token
        ), home_url('/volunteer-portal/attendance'));
        
        // Build portal link
        $portal_url = add_query_arg('token', $signup->access_token, home_url('/volunteer-portal'));
        
        $subject = "Reminder: {$signup->role_name} on " . date('M j', strtotime($signup->event_date));
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>Hi {$signup->volunteer_name},</h2>
            <p>This is a friendly reminder about your upcoming volunteer opportunity:</p>
            
            <div style='background: #f5f5f5; padding: 20px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                <h3 style='margin: 0 0 10px 0; color: #333;'>{$signup->role_name}</h3>
                <p style='margin: 5px 0;'><strong>Program:</strong> {$signup->program_name}</p>
                <p style='margin: 5px 0;'><strong>When:</strong> {$event_datetime}</p>
            </div>
            
            <p><strong>Can you still make it?</strong></p>
            
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$confirm_url}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 30px; text-decoration: none; border-radius: 4px; font-weight: 600; margin: 0 10px;'>✓ Yes, I'll Be There</a>
                <a href='{$portal_url}' style='display: inline-block; background: #666; color: white; padding: 12px 30px; text-decoration: none; border-radius: 4px; font-weight: 600; margin: 0 10px;'>View My Schedule</a>
            </p>
            
            <p style='font-size: 14px; color: #666;'>If you can't make it, please cancel through your <a href='{$portal_url}'>volunteer portal</a> so we can find a replacement.</p>
            
            <p style='margin-top: 30px;'>
                Looking forward to seeing you!<br>
                The Volunteer Team
            </p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($signup->volunteer_email, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log("FriendShyft: Attendance reminder sent to {$signup->volunteer_email} for signup {$signup->id}");
            } else {
                error_log("FriendShyft: Failed to send attendance reminder to {$signup->volunteer_email}");
            }
        }
    }
    
    /**
     * AJAX: Confirm attendance
     */
    public static function ajax_confirm_attendance() {
        check_ajax_referer('friendshyft_portal', 'nonce');

        $signup_id = intval($_POST['signup_id'] ?? 0);
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
        
        global $wpdb;
        
        // Verify this signup belongs to this volunteer
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE id = %d AND volunteer_id = %d",
            $signup_id,
            $volunteer_id
        ));
        
        if (!$signup) {
            wp_send_json_error('Invalid signup');
        }
        
        // Update confirmation
        $updated = $wpdb->update(
            $wpdb->prefix . 'fs_signups',
            array(
                'attendance_confirmed' => 1,
                'confirmation_date' => current_time('mysql')
            ),
            array('id' => $signup_id)
        );
        
        if ($updated) {
            wp_send_json_success('Attendance confirmed!');
        } else {
            wp_send_json_error('Failed to confirm attendance');
        }
    }
    
    /**
     * AJAX: Cancel attendance (different from canceling signup)
     */
    public static function ajax_cancel_attendance() {
        check_ajax_referer('friendshyft_portal', 'nonce');

        $signup_id = intval($_POST['signup_id'] ?? 0);
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
        
        global $wpdb;
        
        // Verify this signup belongs to this volunteer
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, o.event_date, o.start_time, r.name as role_name, v.email
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_roles r ON o.role_id = r.id
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             WHERE s.id = %d AND s.volunteer_id = %d",
            $signup_id,
            $volunteer_id
        ));
        
        if (!$signup) {
            wp_send_json_error('Invalid signup');
        }
        
        // Check if it's within the cancellation window
        $event_datetime = strtotime($signup->event_date . ' ' . $signup->start_time);
        $hours_until = ($event_datetime - time()) / 3600;
        
        if ($hours_until < 24) {
            wp_send_json_error('Cannot cancel within 24 hours of the opportunity. Please contact us directly.');
        }
        
        // Cancel the signup
        $updated = $wpdb->update(
            $wpdb->prefix . 'fs_signups',
            array(
                'status' => 'cancelled',
                'cancelled_date' => current_time('mysql')
            ),
            array('id' => $signup_id)
        );
        
        if ($updated) {
            // Notify admins
            if (class_exists('FS_Notifications')) {
                FS_Notifications::send_cancellation_notification($signup);
            }
            
            wp_send_json_success('Signup cancelled successfully');
        } else {
            wp_send_json_error('Failed to cancel signup');
        }
    }
    
    /**
     * Get confirmation status for a signup
     */
    public static function get_confirmation_status($signup_id) {
        global $wpdb;
        
        $status = $wpdb->get_row($wpdb->prepare(
            "SELECT attendance_confirmed, confirmation_date, reminder_sent 
             FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));
        
        return $status;
    }
    
    /**
     * Get confirmation stats for an opportunity
     */
    public static function get_opportunity_confirmation_stats($opportunity_id) {
        global $wpdb;
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_signups,
                SUM(CASE WHEN attendance_confirmed = 1 THEN 1 ELSE 0 END) as confirmed_count,
                SUM(CASE WHEN attendance_confirmed = 0 THEN 1 ELSE 0 END) as unconfirmed_count
             FROM {$wpdb->prefix}fs_signups
             WHERE opportunity_id = %d AND status = 'confirmed'",
            $opportunity_id
        ));
        
        return $stats;
    }
}

FS_Attendance_Confirmation::init();
