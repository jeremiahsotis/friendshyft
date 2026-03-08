<?php
/**
 * FriendShyft Team Management
 * 
 * Core class for team operations
 */

class FS_Team_Manager {
    
    /**
     * Create a new team
     */
    public static function create_team($data) {
        global $wpdb;
        
        $defaults = array(
            'name' => '',
            'type' => 'recurring',
            'team_leader_volunteer_id' => null,
            'default_size' => 1,
            'description' => '',
            'status' => 'active',
            'created_date' => current_time('mysql')
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (empty($data['name'])) {
            return new WP_Error('missing_name', 'Team name is required');
        }
        
        if ($data['default_size'] < 1) {
            return new WP_Error('invalid_size', 'Team size must be at least 1');
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_teams',
            $data,
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to create team', $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update team
     */
    public static function update_team($team_id, $data) {
        global $wpdb;
        
        $allowed_fields = array(
            'name', 'type', 'team_leader_volunteer_id', 
            'default_size', 'description', 'status'
        );
        
        $update_data = array();
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        if (empty($update_data)) {
            return new WP_Error('no_data', 'No data to update');
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'fs_teams',
            $update_data,
            array('id' => $team_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update team', $wpdb->last_error);
        }
        
        return true;
    }
    
    /**
     * Get team by ID
     */
    public static function get_team($team_id) {
        global $wpdb;
        
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_teams WHERE id = %d",
            $team_id
        ));
        
        if (!$team) {
            return null;
        }
        
        // Add team leader info
        if ($team->team_leader_volunteer_id) {
            $team->leader = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, email FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                $team->team_leader_volunteer_id
            ));
        } else {
            $team->leader = null;
        }
        
        // Add member count
        $team->member_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_team_members WHERE team_id = %d",
            $team_id
        ));
        
        return $team;
    }
    
    /**
     * Get all teams
     */
    public static function get_teams($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'status' => 'all',
            'type' => 'all',
            'orderby' => 'name',
            'order' => 'ASC',
            'limit' => null,
            'offset' => 0
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = "1=1";
        
        if ($args['status'] !== 'all') {
            $where .= $wpdb->prepare(" AND status = %s", $args['status']);
        }
        
        if ($args['type'] !== 'all') {
            $where .= $wpdb->prepare(" AND type = %s", $args['type']);
        }
        
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        
        $limit_clause = '';
        if ($args['limit']) {
            $limit_clause = $wpdb->prepare(" LIMIT %d OFFSET %d", $args['limit'], $args['offset']);
        }
        
        $teams = $wpdb->get_results(
            "SELECT t.*,
                    COUNT(DISTINCT tm.id) as member_count,
                    (1 + COUNT(DISTINCT tm.id)) as team_size,
                    v.name as leader_name
             FROM {$wpdb->prefix}fs_teams t
             LEFT JOIN {$wpdb->prefix}fs_team_members tm ON t.id = tm.team_id
             LEFT JOIN {$wpdb->prefix}fs_volunteers v ON t.team_leader_volunteer_id = v.id
             WHERE $where
             GROUP BY t.id
             ORDER BY $orderby
             $limit_clause"
        );
        
        return $teams;
    }
    
    /**
     * Delete team
     */
    public static function delete_team($team_id) {
        global $wpdb;
        
        // Check for existing signups
        $signup_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_team_signups WHERE team_id = %d",
            $team_id
        ));
        
        if ($signup_count > 0) {
            return new WP_Error('has_signups', 'Cannot delete team with existing signups. Set status to inactive instead.');
        }
        
        // Delete team members first
        $wpdb->delete($wpdb->prefix . 'fs_team_members', array('team_id' => $team_id));
        
        // Delete team
        $result = $wpdb->delete($wpdb->prefix . 'fs_teams', array('id' => $team_id));
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to delete team');
        }
        
        return true;
    }
    
    /**
     * Add team member
     */
    public static function add_member($team_id, $data) {
        global $wpdb;
        
        $defaults = array(
            'team_id' => $team_id,
            'volunteer_id' => null,
            'name' => null,
            'role' => 'member',
            'notes' => '',
            'added_date' => current_time('mysql')
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Must have either volunteer_id or name
        if (empty($data['volunteer_id']) && empty($data['name'])) {
            return new WP_Error('missing_identifier', 'Either volunteer_id or name is required');
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_team_members',
            $data,
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to add member', $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get team members
     */
    public static function get_members($team_id) {
        global $wpdb;
        
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT tm.*, v.name as volunteer_name, v.email
             FROM {$wpdb->prefix}fs_team_members tm
             LEFT JOIN {$wpdb->prefix}fs_volunteers v ON tm.volunteer_id = v.id
             WHERE tm.team_id = %d
             ORDER BY tm.role DESC, tm.added_date ASC",
            $team_id
        ));
        
        return $members;
    }
    
    /**
     * Remove team member
     */
    public static function remove_member($member_id) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $wpdb->prefix . 'fs_team_members',
            array('id' => $member_id)
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Failed to remove member');
        }
        
        return true;
    }
    
    /**
     * Get team's upcoming signups
     */
    public static function get_team_signups($team_id, $status = 'all') {
        global $wpdb;
        
        $where = $wpdb->prepare("ts.team_id = %d", $team_id);
        
        if ($status !== 'all') {
            $where .= $wpdb->prepare(" AND ts.status = %s", $status);
        }
        
        $signups = $wpdb->get_results(
            "SELECT ts.*, o.name as opportunity_name,
                    s.date as shift_date, s.start_time, s.end_time
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_shifts s ON ts.shift_id = s.id
             WHERE $where
             ORDER BY ts.signup_date DESC
             LIMIT 50"
        );
        
        return $signups;
    }

    /**
     * Get teams for a volunteer (as leader or member)
     */
    public static function get_volunteer_teams($volunteer_id) {
        global $wpdb;

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.*,
                    CASE
                        WHEN t.team_leader_volunteer_id = %d THEN 'leader'
                        ELSE 'member'
                    END as volunteer_role
             FROM {$wpdb->prefix}fs_teams t
             LEFT JOIN {$wpdb->prefix}fs_team_members tm ON t.id = tm.team_id
             WHERE t.status = 'active'
               AND (t.team_leader_volunteer_id = %d OR tm.volunteer_id = %d)
             ORDER BY t.name ASC",
            $volunteer_id,
            $volunteer_id,
            $volunteer_id
        ));

        return $teams;
    }

    /**
     * Generate a unique PIN for a team
     */
    public static function generate_team_pin($team_id) {
        global $wpdb;

        // Generate a unique 4-digit PIN (matches individual volunteer PINs)
        $max_attempts = 100;
        $attempt = 0;

        do {
            $pin = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

            // Check if PIN is unique across all teams AND volunteers
            $team_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_teams WHERE pin = %s AND id != %d",
                $pin,
                $team_id
            ));

            $volunteer_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE pin = %s",
                $pin
            ));

            $exists = $team_exists || $volunteer_exists;
            $attempt++;
        } while ($exists && $attempt < $max_attempts);

        if ($exists) {
            return new WP_Error('pin_generation_failed', 'Could not generate unique PIN');
        }

        // Update team with new PIN
        $wpdb->update(
            $wpdb->prefix . 'fs_teams',
            array('pin' => $pin),
            array('id' => $team_id),
            array('%s'),
            array('%d')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Generated PIN {$pin} for team {$team_id}");
        }

        return $pin;
    }

    /**
     * Generate a unique QR code for a team
     */
    public static function generate_team_qr_code($team_id) {
        global $wpdb;

        // Generate a unique QR code (similar to volunteer QR codes)
        $max_attempts = 100;
        $attempt = 0;

        do {
            $qr_code = 'TEAM-' . wp_generate_password(12, false, false);

            // Check if QR code is unique across all teams
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_teams WHERE qr_code = %s AND id != %d",
                $qr_code,
                $team_id
            ));

            $attempt++;
        } while ($exists && $attempt < $max_attempts);

        if ($exists) {
            return new WP_Error('qr_generation_failed', 'Could not generate unique QR code');
        }

        // Update team with new QR code
        $wpdb->update(
            $wpdb->prefix . 'fs_teams',
            array('qr_code' => $qr_code),
            array('id' => $team_id),
            array('%s'),
            array('%d')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Generated QR code {$qr_code} for team {$team_id}");
        }

        return $qr_code;
    }
}
