<?php
/**
 * FriendShyft Team Signup Handler
 * 
 * Handles team signup logic for claiming shifts
 */

class FS_Team_Signup {
    
    /**
     * Create team signup for a shift
     */
    public static function create_signup($data) {
        global $wpdb;

        $defaults = array(
            'team_id' => 0,
            'opportunity_id' => 0,
            'shift_id' => null,
            'period_id' => null,
            'scheduled_size' => 0,
            'signup_date' => current_time('mysql'),
            'status' => 'scheduled',
            'notes' => ''
        );

        $data = wp_parse_args($data, $defaults);

        // Validate required fields
        if (empty($data['team_id']) || empty($data['opportunity_id'])) {
            return new WP_Error('missing_fields', 'Team ID and Opportunity ID are required');
        }

        if ($data['scheduled_size'] < 1) {
            return new WP_Error('invalid_size', 'Team size must be at least 1');
        }

        // Check if opportunity allows team signups
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT allow_team_signups FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $data['opportunity_id']
        ));

        if (!$opportunity || !$opportunity->allow_team_signups) {
            return new WP_Error('not_allowed', 'This opportunity does not accept team signups');
        }

        // Check team member conflicts (we'll handle this in the admin handler)
        // Just continue with creating the signup
        
        // Check for duplicate signup
        $where_clauses = array(
            $wpdb->prepare('team_id = %d', $data['team_id']),
            $wpdb->prepare('opportunity_id = %d', $data['opportunity_id']),
            "status != 'cancelled'"
        );
        
        if ($data['shift_id']) {
            $where_clauses[] = $wpdb->prepare('shift_id = %d', $data['shift_id']);
        }
        
        if ($data['period_id']) {
            $where_clauses[] = $wpdb->prepare('period_id = %d', $data['period_id']);
        }
        
        $existing = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}fs_team_signups 
             WHERE " . implode(' AND ', $where_clauses)
        );
        
        if ($existing) {
            return new WP_Error('duplicate', 'Team already signed up for this shift');
        }
        
        // Check capacity
        $capacity_check = self::check_capacity($data['opportunity_id'], $data['shift_id'], $data['period_id'], $data['scheduled_size']);
        if (is_wp_error($capacity_check)) {
            return $capacity_check;
        }
        
        // Create signup
        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_team_signups',
            $data,
            array('%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to create signup', $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Check if there's capacity for team signup
     */
    private static function check_capacity($opportunity_id, $shift_id, $period_id, $team_size) {
        global $wpdb;

        // Get opportunity/role capacity
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT spots_available FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            return new WP_Error('not_found', 'Opportunity not found');
        }

        // If unlimited, no check needed
        if ($opportunity->spots_available === null || $opportunity->spots_available === 0) {
            return true;
        }
        
        // Count existing signups (both individual and team)
        $where = array(
            $wpdb->prepare("opportunity_id = %d", $opportunity_id),
            "status != 'cancelled'"
        );
        
        if ($shift_id) {
            $where[] = $wpdb->prepare("shift_id = %d", $shift_id);
        }
        
        if ($period_id) {
            $where[] = $wpdb->prepare("period_id = %d", $period_id);
        }
        
        // Count individual volunteer signups
        $individual_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_signups 
             WHERE " . implode(' AND ', $where)
        );
        
        // Count team signups (sum of scheduled_size)
        $team_count = $wpdb->get_var(
            "SELECT COALESCE(SUM(scheduled_size), 0) FROM {$wpdb->prefix}fs_team_signups 
             WHERE " . implode(' AND ', $where)
        );
        
        $total_claimed = $individual_count + $team_count;
        $available = $opportunity->spots_available - $total_claimed;

        if ($team_size > $available) {
            return new WP_Error('no_capacity', sprintf(
                'Not enough capacity. Available: %d, Requested: %d',
                $available,
                $team_size
            ));
        }

        return true;
    }
    
    /**
     * Cancel team signup
     */
    public static function cancel_signup($signup_id) {
        global $wpdb;
        
        $result = $wpdb->update(
            $wpdb->prefix . 'fs_team_signups',
            array('status' => 'cancelled'),
            array('id' => $signup_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to cancel signup');
        }
        
        return true;
    }
    
    /**
     * Get team's signups
     */
    public static function get_team_signups($team_id, $filters = array()) {
        global $wpdb;
        
        $defaults = array(
            'status' => 'all',
            'upcoming_only' => false,
            'limit' => 50
        );
        
        $filters = wp_parse_args($filters, $defaults);
        
        $where = array($wpdb->prepare("ts.team_id = %d", $team_id));
        
        if ($filters['status'] !== 'all') {
            $where[] = $wpdb->prepare("ts.status = %s", $filters['status']);
        }
        
        if ($filters['upcoming_only']) {
            $where[] = "(s.date >= CURDATE() OR s.date IS NULL)";
        }
        
        $signups = $wpdb->get_results(
            "SELECT ts.*, 
                    o.name as opportunity_name,
                    o.description as opportunity_description,
                    s.date as shift_date,
                    s.start_time,
                    s.end_time,
                    p.start_date as period_start,
                    p.end_date as period_end
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_shifts s ON ts.shift_id = s.id
             LEFT JOIN {$wpdb->prefix}fs_periods p ON ts.period_id = p.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(s.date, p.start_date, ts.signup_date) DESC
             LIMIT " . (int)$filters['limit']
        );
        
        return $signups;
    }
    
    /**
     * Get available opportunities for teams
     */
    public static function get_available_opportunities() {
        global $wpdb;
        
        $opportunities = $wpdb->get_results(
            "SELECT o.*, 
                    COUNT(DISTINCT s.id) as shift_count,
                    MIN(s.date) as next_shift_date
             FROM {$wpdb->prefix}fs_opportunities o
             LEFT JOIN {$wpdb->prefix}fs_shifts s ON o.id = s.opportunity_id 
                AND s.date >= CURDATE()
             WHERE o.allow_team_signups = 1
               AND o.status = 'active'
             GROUP BY o.id
             ORDER BY o.name ASC"
        );
        
        return $opportunities;
    }
    
    /**
     * Get available shifts for team signup
     */
    public static function get_available_shifts($opportunity_id, $team_size = 1) {
        global $wpdb;
        
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d AND allow_team_signups = 1",
            $opportunity_id
        ));
        
        if (!$opportunity) {
            return array();
        }
        
        // Get shifts
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_signups 
                     WHERE shift_id = s.id AND status != 'cancelled') as individual_count,
                    (SELECT COALESCE(SUM(scheduled_size), 0) FROM {$wpdb->prefix}fs_team_signups 
                     WHERE shift_id = s.id AND status != 'cancelled') as team_count
             FROM {$wpdb->prefix}fs_shifts s
             WHERE s.opportunity_id = %d
               AND s.date >= CURDATE()
             ORDER BY s.date ASC, s.start_time ASC
             LIMIT 100",
            $opportunity_id
        ));
        
        // Calculate availability
        foreach ($shifts as $shift) {
            $total_claimed = $shift->individual_count + $shift->team_count;
            $shift->available_spots = $opportunity->spots_available ? $opportunity->spots_available - $total_claimed : 999;
            $shift->can_accommodate_team = $shift->available_spots >= $team_size;
        }

        return $shifts;
    }

    /**
     * Check team member conflicts and handle merging
     * Returns array with 'merged' and 'unavailable' members
     */
    public static function check_team_member_conflicts($team_id, $opportunity_id, $shift_id = null) {
        global $wpdb;

        $result = array(
            'merged' => array(),
            'unavailable' => array()
        );

        // Get team leader and members
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_teams WHERE id = %d",
            $team_id
        ));

        if (!$team) {
            return $result;
        }

        $member_ids = array();

        // Add team leader
        if ($team->team_leader_volunteer_id) {
            $member_ids[] = $team->team_leader_volunteer_id;
        }

        // Add team members
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT volunteer_id FROM {$wpdb->prefix}fs_team_members WHERE team_id = %d",
            $team_id
        ));

        foreach ($members as $member) {
            if ($member->volunteer_id) {
                $member_ids[] = $member->volunteer_id;
            }
        }

        if (empty($member_ids)) {
            return $result;
        }

        // Get opportunity time details
        $opp_time = $wpdb->get_row($wpdb->prepare(
            "SELECT o.event_date, sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_opportunities o
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON sh.id = %d
             WHERE o.id = %d",
            $shift_id ? $shift_id : 0,
            $opportunity_id
        ));

        if (!$opp_time) {
            return $result;
        }

        // If no shift ID or times, skip time-based conflict checking
        if (!$shift_id || !$opp_time->shift_start_time || !$opp_time->shift_end_time) {
            // Can only check for same-opportunity conflicts
            $target_start = null;
            $target_end = null;
        } else {
            $target_start = $opp_time->event_date . ' ' . $opp_time->shift_start_time;
            $target_end = $opp_time->event_date . ' ' . $opp_time->shift_end_time;
        }

        // Check each member
        foreach ($member_ids as $volunteer_id) {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, email FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                $volunteer_id
            ));

            if (!$volunteer) {
                continue;
            }

            // Check if already signed up individually for THIS opportunity
            $individual_signup = $wpdb->get_row($wpdb->prepare(
                "SELECT s.id FROM {$wpdb->prefix}fs_signups s
                 WHERE s.volunteer_id = %d
                 AND s.opportunity_id = %d
                 AND s.status = 'confirmed'
                 " . ($shift_id ? "AND s.shift_id = " . intval($shift_id) : ""),
                $volunteer_id,
                $opportunity_id
            ));

            if ($individual_signup) {
                // Will merge this individual signup
                $result['merged'][] = array(
                    'volunteer_id' => $volunteer_id,
                    'name' => $volunteer->name,
                    'signup_id' => $individual_signup->id
                );
                continue;
            }

            // Check for time conflicts with OTHER opportunities (only if we have shift times)
            if ($target_start && $target_end) {
                $conflict = $wpdb->get_row($wpdb->prepare(
                    "SELECT s.id, o.title as opportunity_title, o.event_date,
                            sh.shift_start_time, sh.shift_end_time
                     FROM {$wpdb->prefix}fs_signups s
                     JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                     LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
                     WHERE s.volunteer_id = %d
                     AND s.opportunity_id != %d
                     AND s.status = 'confirmed'
                     AND s.shift_id IS NOT NULL",
                    $volunteer_id,
                    $opportunity_id
                ));

                if ($conflict && $conflict->shift_start_time && $conflict->shift_end_time) {
                    $conflict_start = $conflict->event_date . ' ' . $conflict->shift_start_time;
                    $conflict_end = $conflict->event_date . ' ' . $conflict->shift_end_time;

                    // Check if times overlap
                    if (self::times_overlap($target_start, $target_end, $conflict_start, $conflict_end)) {
                        $result['unavailable'][] = array(
                            'volunteer_id' => $volunteer_id,
                            'name' => $volunteer->name,
                            'reason' => 'Already signed up for "' . $conflict->opportunity_title . '" at the same time'
                        );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Merge individual signups into team signup
     */
    public static function merge_individual_signups($team_signup_id, $individual_signup_ids) {
        global $wpdb;

        foreach ($individual_signup_ids as $signup_id) {
            // Cancel the individual signup
            $wpdb->update(
                $wpdb->prefix . 'fs_signups',
                array(
                    'status' => 'merged_to_team',
                    'notes' => 'Merged into team signup #' . $team_signup_id
                ),
                array('id' => $signup_id)
            );

            // Get signup details for notifications
            $signup = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*, v.name, v.email, v.access_token, o.title, o.event_date, o.location, o.description
                 FROM {$wpdb->prefix}fs_signups s
                 JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 WHERE s.id = %d",
                $signup_id
            ));

            // Send notification to volunteer that they've been merged into team signup
            if ($signup) {
                // Create volunteer object for notification
                $volunteer = (object) array(
                    'name' => $signup->name,
                    'email' => $signup->email,
                    'access_token' => $signup->access_token
                );

                // Create opportunity object for notification
                $opportunity = (object) array(
                    'title' => $signup->title,
                    'event_date' => $signup->event_date,
                    'location' => $signup->location,
                    'description' => $signup->description
                );

                FS_Notifications::send_team_merge_notification($volunteer, $opportunity, $team_signup_id);
            }
        }
    }

    /**
     * Check if two time ranges overlap
     */
    private static function times_overlap($start1, $end1, $start2, $end2) {
        $start1_ts = strtotime($start1);
        $end1_ts = strtotime($end1);
        $start2_ts = strtotime($start2);
        $end2_ts = strtotime($end2);

        return ($start1_ts < $end2_ts && $end1_ts > $start2_ts);
    }
}
