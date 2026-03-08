<?php
/**
 * FriendShyft Team Time Tracking
 * 
 * Handles team check-in/out via kiosk
 */

class FS_Team_Time_Tracking {
    
    /**
     * Team check-in
     */
    public static function check_in($team_signup_id, $people_count, $notes = '') {
        global $wpdb;
        
        // Verify signup exists and is not already checked in
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_team_signups WHERE id = %d",
            $team_signup_id
        ));
        
        if (!$signup) {
            return new WP_Error('not_found', 'Team signup not found');
        }
        
        // Check if already checked in
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_team_attendance 
             WHERE team_signup_id = %d AND check_out_time IS NULL",
            $team_signup_id
        ));
        
        if ($existing) {
            return new WP_Error('already_checked_in', 'Team is already checked in');
        }
        
        // Create attendance record
        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_team_attendance',
            array(
                'team_signup_id' => $team_signup_id,
                'check_in_time' => current_time('mysql'),
                'people_count' => $people_count,
                'notes' => $notes
            ),
            array('%d', '%s', '%d', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to check in', $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Team check-out
     */
    public static function check_out($attendance_id, $notes = '') {
        global $wpdb;
        
        // Get attendance record
        $attendance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_team_attendance WHERE id = %d",
            $attendance_id
        ));
        
        if (!$attendance) {
            return new WP_Error('not_found', 'Attendance record not found');
        }
        
        if ($attendance->check_out_time) {
            return new WP_Error('already_checked_out', 'Already checked out');
        }
        
        // Calculate hours
        $check_in = new DateTime($attendance->check_in_time);
        $check_out = new DateTime(current_time('mysql'));
        $interval = $check_in->diff($check_out);
        $hours_per_person = round($interval->h + ($interval->i / 60), 2);
        $total_hours = round($hours_per_person * $attendance->people_count, 2);
        
        // Update attendance
        $result = $wpdb->update(
            $wpdb->prefix . 'fs_team_attendance',
            array(
                'check_out_time' => current_time('mysql'),
                'hours_per_person' => $hours_per_person,
                'total_hours' => $total_hours,
                'notes' => $notes ? $notes : $attendance->notes
            ),
            array('id' => $attendance_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to check out');
        }
        
        // Update team signup with actual attendance
        $wpdb->update(
            $wpdb->prefix . 'fs_team_signups',
            array('actual_attendance' => $attendance->people_count),
            array('id' => $attendance->team_signup_id)
        );
        
        return array(
            'hours_per_person' => $hours_per_person,
            'total_hours' => $total_hours
        );
    }
    
    /**
     * Get active check-in for team
     */
    public static function get_active_checkin($team_id, $opportunity_id = null) {
        global $wpdb;
        
        $where = array(
            "ts.team_id = %d",
            "ta.check_out_time IS NULL"
        );
        $params = array($team_id);
        
        if ($opportunity_id) {
            $where[] = "ts.opportunity_id = %d";
            $params[] = $opportunity_id;
        }
        
        $attendance = $wpdb->get_row($wpdb->prepare(
            "SELECT ta.*, ts.team_id, ts.opportunity_id, o.name as opportunity_name
             FROM {$wpdb->prefix}fs_team_attendance ta
             JOIN {$wpdb->prefix}fs_team_signups ts ON ta.team_signup_id = ts.id
             JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY ta.check_in_time DESC
             LIMIT 1",
            ...$params
        ));
        
        return $attendance;
    }
    
    /**
     * Get team attendance history
     */
    public static function get_team_attendance_history($team_id, $limit = 50) {
        global $wpdb;
        
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.*, 
                    ts.team_id,
                    ts.opportunity_id,
                    o.name as opportunity_name,
                    s.date as shift_date,
                    s.start_time,
                    s.end_time
             FROM {$wpdb->prefix}fs_team_attendance ta
             JOIN {$wpdb->prefix}fs_team_signups ts ON ta.team_signup_id = ts.id
             JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_shifts s ON ts.shift_id = s.id
             WHERE ts.team_id = %d
             ORDER BY ta.check_in_time DESC
             LIMIT %d",
            $team_id,
            $limit
        ));
        
        return $records;
    }
    
    /**
     * Get team's total volunteer hours
     */
    public static function get_team_total_hours($team_id, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $where = array("ts.team_id = %d");
        $params = array($team_id);
        
        if ($start_date) {
            $where[] = "ta.check_in_time >= %s";
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $where[] = "ta.check_in_time <= %s";
            $params[] = $end_date;
        }
        
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT ta.id) as session_count,
                SUM(ta.people_count) as total_people_served,
                SUM(ta.total_hours) as total_hours
             FROM {$wpdb->prefix}fs_team_attendance ta
             JOIN {$wpdb->prefix}fs_team_signups ts ON ta.team_signup_id = ts.id
             WHERE " . implode(' AND ', $where) . "
               AND ta.check_out_time IS NOT NULL",
            ...$params
        ));
        
        return $totals;
    }
    
    /**
     * Find today's scheduled signup for kiosk
     */
    public static function find_todays_signup($team_id, $opportunity_id = null) {
        global $wpdb;
        
        $where = array(
            "ts.team_id = %d",
            "s.date = CURDATE()",
            "ts.status = 'scheduled'"
        );
        $params = array($team_id);
        
        if ($opportunity_id) {
            $where[] = "ts.opportunity_id = %d";
            $params[] = $opportunity_id;
        }
        
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT ts.*, o.name as opportunity_name, s.start_time, s.end_time
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_shifts s ON ts.shift_id = s.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.start_time ASC
             LIMIT 1",
            ...$params
        ));
        
        return $signup;
    }
}
