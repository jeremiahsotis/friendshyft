<?php
if (!defined('ABSPATH')) exit;

class FS_Signup {
    
    public static function create($volunteer_id, $opportunity_id, $shift_id = null, $status = 'confirmed', $registration_id = null) {
        global $wpdb;

        $status = sanitize_text_field($status);
        if (!in_array($status, array('confirmed', 'pending', 'cancelled', 'no_show', 'expired'), true)) {
            $status = 'confirmed';
        }
        
        // Get volunteer
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));
        
        if (!$volunteer) {
            return array('success' => false, 'message' => 'We couldn\'t find your volunteer profile. Please contact us if you need assistance.');
        }

        // Locked safety policy: minors/unknown age cannot be auto-confirmed without permission workflow.
        if ($status === 'confirmed' && empty($registration_id) && class_exists('FS_Event_Registrations')) {
            if (FS_Event_Registrations::should_skip_auto_promotion_for_volunteer($volunteer_id)) {
                return array(
                    'success' => false,
                    'message' => 'Guardian permission is required before this volunteer can be confirmed.'
                );
            }
        }

        // Get opportunity
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            return array('success' => false, 'message' => 'This opportunity is no longer available. Please browse other opportunities.');
        }

        // Get shift if provided
        $shift = null;
        if ($shift_id) {
            $shift = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts WHERE id = %d AND opportunity_id = %d",
                $shift_id,
                $opportunity_id
            ));

            if (!$shift) {
                return array('success' => false, 'message' => 'This time slot is no longer available. Please try a different time.');
            }

            // Check if shift is full
            if ($shift->spots_filled >= $shift->spots_available) {
                return array('success' => false, 'message' => 'Sorry, this shift is now full. Please try another time slot or check back later for openings.');
            }
        }

        // Check if already signed up for this opportunity (individual)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_signups
            WHERE volunteer_id = %d AND opportunity_id = %d
            AND status NOT IN ('cancelled', 'expired')",
            $volunteer_id,
            $opportunity_id
        ));

        if ($existing) {
            return array('success' => false, 'message' => 'You\'re already signed up for this opportunity. Check your dashboard to view your upcoming shifts.');
        }

        // Check if already signed up via team for THIS opportunity
        $team_signup = $wpdb->get_row($wpdb->prepare(
            "SELECT ts.*, t.name as team_name
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             WHERE ts.opportunity_id = %d
             AND ts.status != 'cancelled'
             AND (t.team_leader_volunteer_id = %d
                  OR EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}fs_team_members tm
                      WHERE tm.team_id = t.id AND tm.volunteer_id = %d
                  ))
             " . ($shift_id ? "AND ts.shift_id = " . intval($shift_id) : "") . "
             LIMIT 1",
            $opportunity_id,
            $volunteer_id,
            $volunteer_id
        ));

        if ($team_signup) {
            return array('success' => false, 'message' => 'You are already signed up for this opportunity as part of team "' . $team_signup->team_name . '"');
        }

        // Check for time conflicts with team signups for OTHER opportunities
        $time_conflict = self::check_team_time_conflict($volunteer_id, $opportunity_id, $shift_id);
        if ($time_conflict) {
            return array('success' => false, 'message' => $time_conflict);
        }
        
        // Check eligibility
        $eligibility = FS_Eligibility_Checker::check($volunteer, $opportunity);
        
        if (!$eligibility['eligible']) {
            return array('success' => false, 'message' => $eligibility['reason']);
        }
        
        // Create local signup record
        $signup_data = array(
            'volunteer_id' => $volunteer_id,
            'opportunity_id' => $opportunity_id,
            'shift_id' => $shift_id,
            'registration_id' => $registration_id,
            'status' => $status,
            'signup_date' => current_time('mysql')
        );

        $result = $wpdb->insert($wpdb->prefix . 'fs_signups', $signup_data);
        $signup_id = $wpdb->insert_id;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("INSERT result: " . ($result ? 'SUCCESS' : 'FAILED'));
            error_log("INSERT ID: " . $signup_id);
            error_log("WPDB last_error: " . $wpdb->last_error);
            error_log("Signup data: " . print_r($signup_data, true));
        }
        
        // Update shift spots_filled
        if ($shift_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fs_opportunity_shifts 
                SET spots_filled = spots_filled + 1 
                WHERE id = %d",
                $shift_id
            ));
        }
        
        // Update opportunity spots_filled (total across all shifts)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities 
            SET spots_filled = spots_filled + 1 
            WHERE id = %d",
            $opportunity_id
        ));
        
        // Try to sync to Monday.com if configured
        $monday_id = null;
        if (FS_Monday_API::is_configured() && !empty($volunteer->monday_id) && !empty($opportunity->monday_id)) {
            $api = new FS_Monday_API();
            $board_ids = $api->get_board_ids();
            
            if (!empty($board_ids['signups'])) {
                $shift_time = '';
                if ($shift) {
                    $shift_time = date('g:i A', strtotime($shift->shift_start_time)) . ' - ' . 
                                  date('g:i A', strtotime($shift->shift_end_time));
                }
                
                $item_name = $volunteer->name . ' - ' . $opportunity->title . 
                            ($shift_time ? ' (' . $shift_time . ')' : '');
                
                $monday_id = $api->create_item(
                    $board_ids['signups'],
                    $item_name,
                    array(
                        'person' => array('personsAndTeams' => array(array('id' => $volunteer->monday_id, 'kind' => 'person'))),
                        'opportunity' => array('linkedPulseIds' => array(array('linkedPulseId' => $opportunity->monday_id)))
                    )
                );
                
                if ($monday_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'fs_signups',
                        array('monday_id' => $monday_id),
                        array('id' => $signup_id)
                    );
                }
            }
        }
        
        // Send notification only for confirmed signups.
        if ($status === 'confirmed' && class_exists('FS_Notifications')) {
            FS_Notifications::send_signup_confirmation($volunteer, $opportunity, $shift);
            // Notify Point of Contact
            FS_Notifications::send_poc_signup_notification($volunteer, $opportunity, $shift);
        }

        return array(
            'success' => true,
            'message' => $status === 'pending'
                ? 'Spot held pending approval.'
                : 'Successfully signed up!',
            'signup_id' => $signup_id
        );
    }
    
    public static function cancel($signup_id, $volunteer_id = null, $silent = false, $trigger_waitlist = true) {
    global $wpdb;
    
    // Get signup details BEFORE cancelling
    $signup = $wpdb->get_row($wpdb->prepare(
        "SELECT s.*, v.*, o.*, sh.shift_start_time, sh.shift_end_time
         FROM {$wpdb->prefix}fs_signups s
         JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
         JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
         LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
         WHERE s.id = %d",
        $signup_id
    ));
    
    if (!$signup) {
        return array('success' => false, 'message' => 'We couldn\'t find this signup. It may have already been cancelled.');
    }
    
    // Update status
    $wpdb->update(
        $wpdb->prefix . 'fs_signups',
        array('status' => 'cancelled'),
        array('id' => $signup_id)
    );
    
    // Decrease shift spots_filled
    if ($signup->shift_id) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunity_shifts 
            SET spots_filled = spots_filled - 1 
            WHERE id = %d AND spots_filled > 0",
            $signup->shift_id
        ));
    }
    
    // Decrease opportunity spots_filled
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}fs_opportunities 
        SET spots_filled = spots_filled - 1 
        WHERE id = %d AND spots_filled > 0",
        $signup->opportunity_id
    ));
    
    // Send cancellation confirmation
    if (!$silent && class_exists('FS_Notifications')) {
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
            'description' => $signup->description
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
        
        FS_Notifications::send_cancellation_confirmation($volunteer, $opportunity, $shift);
    }

    // Fire action hook for waitlist auto-promotion
    if ($trigger_waitlist) {
        do_action('fs_signup_cancelled', $signup->volunteer_id, $signup->opportunity_id, $signup->shift_id);
    }

    return array('success' => true, 'message' => 'Signup cancelled');
}

    /**
     * Check if volunteer has time conflict with their team's signups
     */
    private static function check_team_time_conflict($volunteer_id, $opportunity_id, $shift_id = null) {
        global $wpdb;

        // Get the time details for the opportunity we're trying to sign up for
        $target_opp = $wpdb->get_row($wpdb->prepare(
            "SELECT o.event_date, sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_opportunities o
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON sh.id = %d
             WHERE o.id = %d",
            $shift_id ? $shift_id : 0,
            $opportunity_id
        ));

        if (!$target_opp) {
            return false;
        }

        // If no shift times, we can't check overlap
        if (!$shift_id || !$target_opp->shift_start_time || !$target_opp->shift_end_time) {
            return false;
        }

        // Determine the time range for the target opportunity
        $target_start = $target_opp->event_date . ' ' . $target_opp->shift_start_time;
        $target_end = $target_opp->event_date . ' ' . $target_opp->shift_end_time;

        // Find team signups for OTHER opportunities that this volunteer is part of
        $conflicts = $wpdb->get_results($wpdb->prepare(
            "SELECT ts.*, t.name as team_name, o.title as opportunity_title, o.event_date,
                    sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON ts.shift_id = sh.id
             WHERE ts.opportunity_id != %d
             AND ts.status != 'cancelled'
             AND ts.shift_id IS NOT NULL
             AND (t.team_leader_volunteer_id = %d
                  OR EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}fs_team_members tm
                      WHERE tm.team_id = t.id AND tm.volunteer_id = %d
                  ))",
            $opportunity_id,
            $volunteer_id,
            $volunteer_id
        ));

        foreach ($conflicts as $conflict) {
            if (!$conflict->shift_start_time || !$conflict->shift_end_time) {
                continue;
            }

            $conflict_start = $conflict->event_date . ' ' . $conflict->shift_start_time;
            $conflict_end = $conflict->event_date . ' ' . $conflict->shift_end_time;

            // Check if times overlap
            if (self::times_overlap($target_start, $target_end, $conflict_start, $conflict_end)) {
                return 'You cannot sign up for this opportunity because your team "' . $conflict->team_name . '" is already signed up for "' . $conflict->opportunity_title . '" at the same time.';
            }
        }

        return false;
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
