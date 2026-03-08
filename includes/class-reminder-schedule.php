<?php
if (!defined('ABSPATH')) exit;

class FS_Reminder_Scheduler {
    
    public static function init() {
        // Register cron schedule
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
        
        // Schedule daily reminder check
        if (!wp_next_scheduled('fs_send_reminders')) {
            wp_schedule_event(strtotime('06:00:00'), 'daily', 'fs_send_reminders');
        }
        
        add_action('fs_send_reminders', array(__CLASS__, 'send_daily_reminders'));
    }
    
    public static function add_cron_schedule($schedules) {
        $schedules['daily'] = array(
            'interval' => 86400, // 24 hours
            'display'  => __('Once Daily')
        );
        return $schedules;
    }
    
    /**
     * Send reminders for opportunities happening tomorrow
     */
    public static function send_daily_reminders() {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Running daily reminder job');
        }
        
        // Get tomorrow's date
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // Get all confirmed signups for tomorrow
        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, v.*, o.*, sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
             WHERE s.status = 'confirmed'
             AND o.event_date = %s
             AND o.status = 'Open'
             ORDER BY v.id, sh.shift_start_time",
            $tomorrow
        ));
        
        if (empty($signups)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: No reminders to send for tomorrow');
            }
            return;
        }
        
        $sent_count = 0;
        
        foreach ($signups as $signup) {
            // Create volunteer object
            $volunteer = (object) array(
                'id' => $signup->volunteer_id,
                'name' => $signup->name,
                'email' => $signup->email,
                'access_token' => $signup->access_token
            );
            
            // Create opportunity object
            $opportunity = (object) array(
                'id' => $signup->opportunity_id,
                'title' => $signup->title,
                'event_date' => $signup->event_date,
                'location' => $signup->location,
                'description' => $signup->description,
                'requirements' => $signup->requirements
            );
            
            // Create shift object if applicable
            $shift = null;
            if ($signup->shift_id) {
                $shift = (object) array(
                    'id' => $signup->shift_id,
                    'shift_start_time' => $signup->shift_start_time,
                    'shift_end_time' => $signup->shift_end_time
                );
            }
            
            // Send reminder
            if (class_exists('FS_Notifications')) {
                FS_Notifications::send_opportunity_reminder($volunteer, $opportunity, $shift);
                $sent_count++;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Sent {$sent_count} reminder emails for tomorrow ({$tomorrow})");
        }
    }
}

FS_Reminder_Scheduler::init();
