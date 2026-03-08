<?php
if (!defined('ABSPATH')) exit;

class FS_Handoff_Notifications {
    
    public static function init() {
        // Schedule daily cron job
        add_action('fs_daily_handoff_check', array(__CLASS__, 'process_handoff_notifications'));
        
        if (!wp_next_scheduled('fs_daily_handoff_check')) {
            wp_schedule_event(time(), 'daily', 'fs_daily_handoff_check');
        }
    }
    
    /**
     * Process handoff notifications for all active templates
     * Runs daily via cron
     */
    public static function process_handoff_notifications() {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Processing handoff notifications');
        }
        
        // Get all active flexible selection templates with handoff notifications enabled
        $templates = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates 
            WHERE template_type = 'flexible_selection' 
            AND status = 'Active'
            AND handoff_notifications = 1"
        );
        
        if (empty($templates)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: No templates with handoff notifications enabled');
            }
            return;
        }
        
        foreach ($templates as $template) {
            self::process_template_notifications($template);
        }
    }
    
    /**
     * Process notifications for a specific template
     */
    private static function process_template_notifications($template) {
        global $wpdb;
        
        $pattern = json_decode($template->recurrence_pattern, true);
        $period = $pattern['flexible_period'] ?? 'quarterly';
        
        // Get current and next period dates
        $current_period = self::get_period_dates($period, 'current');
        $next_period = self::get_period_dates($period, 'next');
        
        $today = date('Y-m-d');
        $period_start = $current_period['start'];
        $period_end = $current_period['end'];
        
        // Calculate notification windows
        $start_window_end = date('Y-m-d', strtotime($period_start . ' +3 days'));
        $end_window_start = date('Y-m-d', strtotime($period_end . ' -7 days'));
        
        $should_send_start = ($today >= $period_start && $today <= $start_window_end);
        $should_send_end = ($today >= $end_window_start && $today <= $period_end);
        
        if (!$should_send_start && !$should_send_end) {
            return; // Not in a notification window
        }
        
        // Get all volunteers with commitments in current period
        $current_volunteers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT v.*, v.id as volunteer_id
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE o.template_id = %d
             AND o.event_date BETWEEN %s AND %s
             AND s.status = 'confirmed'
             ORDER BY v.name ASC",
            $template->id,
            $period_start,
            $period_end
        ));
        
        foreach ($current_volunteers as $current_volunteer) {
            if ($should_send_start) {
                self::send_handoff_notification($template, $current_volunteer, $current_period, $next_period, 'period_start');
            }
            
            if ($should_send_end) {
                self::send_handoff_notification($template, $current_volunteer, $current_period, $next_period, 'period_end');
            }
        }
    }
    
    /**
     * Send handoff notification to a volunteer
     */
    private static function send_handoff_notification($template, $current_volunteer, $current_period, $next_period, $notification_type) {
        global $wpdb;
        
        // Check if already sent
        $already_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_handoff_notifications
            WHERE template_id = %d
            AND period_start = %s
            AND volunteer_id = %d
            AND notification_type = %s",
            $template->id,
            $current_period['start'],
            $current_volunteer->volunteer_id,
            $notification_type
        ));
        
        if ($already_sent) {
            return; // Already sent this notification
        }
        
        // Find next volunteer(s)
        $next_volunteers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT v.*
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE o.template_id = %d
             AND o.event_date BETWEEN %s AND %s
             AND s.status = 'confirmed'
             ORDER BY o.event_date ASC, v.name ASC",
            $template->id,
            $next_period['start'],
            $next_period['end']
        ));
        
        if (empty($next_volunteers)) {
            // No one scheduled for next period yet
            self::send_no_successor_email($template, $current_volunteer, $current_period, $next_period, $notification_type);
            
            // Log that we attempted
            $wpdb->insert(
                $wpdb->prefix . 'fs_handoff_notifications',
                array(
                    'template_id' => $template->id,
                    'period_start' => $current_period['start'],
                    'period_end' => $current_period['end'],
                    'volunteer_id' => $current_volunteer->volunteer_id,
                    'next_volunteer_id' => null,
                    'notification_type' => $notification_type,
                    'sent_date' => current_time('mysql')
                )
            );
            return;
        }
        
        // Send email with next volunteer info
        self::send_successor_email($template, $current_volunteer, $next_volunteers, $current_period, $next_period, $notification_type);
        
        // Log notification
        foreach ($next_volunteers as $next_vol) {
            $wpdb->insert(
                $wpdb->prefix . 'fs_handoff_notifications',
                array(
                    'template_id' => $template->id,
                    'period_start' => $current_period['start'],
                    'period_end' => $current_period['end'],
                    'volunteer_id' => $current_volunteer->volunteer_id,
                    'next_volunteer_id' => $next_vol->id,
                    'notification_type' => $notification_type,
                    'sent_date' => current_time('mysql')
                )
            );
        }
    }
    
    /**
     * Send email when there IS a next volunteer
     */
    private static function send_successor_email($template, $current_volunteer, $next_volunteers, $current_period, $next_period, $notification_type) {
        $period_name = ucfirst(self::get_period_name($template));
        
        if ($notification_type === 'period_start') {
            $subject = "Your {$template->title} Schedule - Upcoming Handoff Info";
            $intro = "You're scheduled for {$template->title} this {$period_name}. Here's who will be taking over next:";
        } else {
            $subject = "Reminder: {$template->title} Handoff Coming Soon";
            $intro = "Your {$template->title} period is ending soon. Don't forget to coordinate with the next volunteer:";
        }
        
        $next_volunteers_list = '';
        foreach ($next_volunteers as $next_vol) {
            $next_volunteers_list .= "<li><strong>{$next_vol->name}</strong><br>";
            if (!empty($next_vol->email)) {
                $next_volunteers_list .= "Email: <a href='mailto:{$next_vol->email}'>{$next_vol->email}</a><br>";
            }
            if (!empty($next_vol->phone)) {
                $next_volunteers_list .= "Phone: {$next_vol->phone}<br>";
            }
            $next_volunteers_list .= "</li>";
        }
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2 style='color: #0073aa;'>{$template->title}</h2>
            <p>Hi {$current_volunteer->name},</p>
            <p>{$intro}</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='margin-top: 0;'>Your Current Period</h3>
                <p><strong>" . date('F j', strtotime($current_period['start'])) . " - " . date('F j, Y', strtotime($current_period['end'])) . "</strong></p>
            </div>
            
            <div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='margin-top: 0;'>Next Period Volunteers</h3>
                <p><strong>" . date('F j', strtotime($next_period['start'])) . " - " . date('F j, Y', strtotime($next_period['end'])) . "</strong></p>
                <ul style='list-style: none; padding-left: 0;'>
                    {$next_volunteers_list}
                </ul>
            </div>
            
            <p>Please coordinate with the next volunteer(s) to ensure a smooth handoff.</p>
            
            <p>Questions? Contact your volunteer coordinator.</p>
            
            <p>Thank you for your service!</p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($current_volunteer->email, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Sent {$notification_type} handoff notification to {$current_volunteer->name} for {$template->title}");
        }
    }
    
    /**
     * Send email when there is NO next volunteer yet
     */
    private static function send_no_successor_email($template, $current_volunteer, $current_period, $next_period, $notification_type) {
        $period_name = ucfirst(self::get_period_name($template));
        
        if ($notification_type === 'period_start') {
            $subject = "Your {$template->title} Schedule - No Next Volunteer Yet";
            $message_body = "You're scheduled for {$template->title} this {$period_name}. However, no one has signed up for the next period yet. Please check back or contact your coordinator.";
        } else {
            $subject = "URGENT: {$template->title} - No Next Volunteer Scheduled";
            $message_body = "<strong style='color: #dc3545;'>Your {$template->title} period is ending soon, but no one is scheduled for the next period.</strong> Please contact your volunteer coordinator immediately to ensure coverage.";
        }
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2 style='color: #0073aa;'>{$template->title}</h2>
            <p>Hi {$current_volunteer->name},</p>
            <p>{$message_body}</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='margin-top: 0;'>Your Current Period</h3>
                <p><strong>" . date('F j', strtotime($current_period['start'])) . " - " . date('F j, Y', strtotime($current_period['end'])) . "</strong></p>
            </div>
            
            <div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='margin-top: 0;'>Next Period (No Volunteers Yet)</h3>
                <p><strong>" . date('F j', strtotime($next_period['start'])) . " - " . date('F j, Y', strtotime($next_period['end'])) . "</strong></p>
                <p style='color: #856404;'>⚠️ Coverage needed for this period</p>
            </div>
            
            <p>Thank you for your service!</p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($current_volunteer->email, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Sent 'no successor' {$notification_type} notification to {$current_volunteer->name} for {$template->title}");
        }
    }
    
    /**
     * Get period dates (current or next)
     */
    private static function get_period_dates($period, $which = 'current') {
        $now = current_time('timestamp');
        $year = date('Y', $now);
        $month = date('n', $now);
        
        switch ($period) {
            case 'quarterly':
                $quarter = ceil($month / 3);
                if ($which === 'next') {
                    $quarter++;
                    if ($quarter > 4) {
                        $quarter = 1;
                        $year++;
                    }
                }
                $start_month = (($quarter - 1) * 3) + 1;
                $start = date('Y-m-d', mktime(0, 0, 0, $start_month, 1, $year));
                $end = date('Y-m-t', mktime(0, 0, 0, $start_month + 2, 1, $year));
                break;
                
            case 'biannually':
                if ($month <= 6) {
                    $start = $year . '-01-01';
                    $end = $year . '-06-30';
                    if ($which === 'next') {
                        $start = $year . '-07-01';
                        $end = $year . '-12-31';
                    }
                } else {
                    $start = $year . '-07-01';
                    $end = $year . '-12-31';
                    if ($which === 'next') {
                        $year++;
                        $start = $year . '-01-01';
                        $end = $year . '-06-30';
                    }
                }
                break;
                
            case 'annually':
            default:
                $start = $year . '-01-01';
                $end = $year . '-12-31';
                if ($which === 'next') {
                    $year++;
                    $start = $year . '-01-01';
                    $end = $year . '-12-31';
                }
                break;
        }
        
        return array('start' => $start, 'end' => $end);
    }
    
    /**
     * Get readable period name
     */
    private static function get_period_name($template) {
        $pattern = json_decode($template->recurrence_pattern, true);
        return $pattern['flexible_period'] ?? 'quarter';
    }
}

FS_Handoff_Notifications::init();