<?php
/**
 * Team Kiosk Interface
 * 
 * Extends volunteer kiosk to support team check-in/out
 * Teams check in using team leader's PIN or a team-specific PIN
 */

class FS_Team_Kiosk {
    
    public static function init() {
        // Add AJAX handlers for team kiosk
        add_action('wp_ajax_nopriv_fs_team_kiosk_checkin', array(__CLASS__, 'ajax_checkin'));
        add_action('wp_ajax_nopriv_fs_team_kiosk_checkout', array(__CLASS__, 'ajax_checkout'));
        add_action('wp_ajax_nopriv_fs_team_kiosk_lookup', array(__CLASS__, 'ajax_lookup_team'));
    }
    
    /**
     * Lookup team by PIN (team leader's PIN)
     */
    public static function ajax_lookup_team() {
        $pin = sanitize_text_field($_POST['pin']);
        $opportunity_id = !empty($_POST['opportunity_id']) ? (int)$_POST['opportunity_id'] : null;
        
        global $wpdb;
        
        // Find volunteer by PIN
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}fs_volunteers WHERE pin = %s",
            $pin
        ));
        
        if (!$volunteer) {
            wp_send_json_error('Invalid PIN');
        }
        
        // Find teams led by this volunteer
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, default_size, type 
             FROM {$wpdb->prefix}fs_teams 
             WHERE team_leader_volunteer_id = %d AND status = 'active'",
            $volunteer->id
        ));
        
        if (empty($teams)) {
            wp_send_json_error('No active teams found for this volunteer');
        }
        
        // For each team, check if they have a signup today
        $teams_with_signups = array();
        foreach ($teams as $team) {
            $signup = FS_Team_Time_Tracking::find_todays_signup($team->id, $opportunity_id);
            $active_checkin = FS_Team_Time_Tracking::get_active_checkin($team->id, $opportunity_id);
            
            $team->has_signup = (bool)$signup;
            $team->signup = $signup;
            $team->is_checked_in = (bool)$active_checkin;
            $team->active_checkin = $active_checkin;
            
            if ($signup || $active_checkin) {
                $teams_with_signups[] = $team;
            }
        }
        
        if (empty($teams_with_signups)) {
            wp_send_json_error('No teams scheduled for today at this location');
        }
        
        wp_send_json_success(array(
            'volunteer_name' => $volunteer->name,
            'teams' => $teams_with_signups
        ));
    }
    
    /**
     * Team check-in
     */
    public static function ajax_checkin() {
        $team_signup_id = (int)$_POST['team_signup_id'];
        $people_count = (int)$_POST['people_count'];
        $notes = sanitize_textarea_field($_POST['notes']);
        
        $result = FS_Team_Time_Tracking::check_in($team_signup_id, $people_count, $notes);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'attendance_id' => $result,
            'check_in_time' => current_time('mysql')
        ));
    }
    
    /**
     * Team check-out
     */
    public static function ajax_checkout() {
        $attendance_id = (int)$_POST['attendance_id'];
        $notes = sanitize_textarea_field($_POST['notes']);
        
        $result = FS_Team_Time_Tracking::check_out($attendance_id, $notes);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Render team selection interface for kiosk
     * 
     * This gets injected into the kiosk page after PIN entry
     */
    public static function render_team_selector($teams_data) {
        ?>
        <div class="team-selector" style="max-width: 600px; margin: 0 auto;">
            <h2>Select Team</h2>
            <p>Hi <?php echo esc_html($teams_data['volunteer_name']); ?>! Select which team you're checking in:</p>
            
            <div class="team-list" style="margin-top: 20px;">
                <?php foreach ($teams_data['teams'] as $team): ?>
                    <div class="team-card" style="border: 2px solid #ddd; padding: 20px; margin-bottom: 15px; border-radius: 8px; cursor: pointer;"
                         onclick="selectTeam(<?php echo $team->id; ?>, <?php echo esc_js(json_encode($team)); ?>)">
                        
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h3 style="margin: 0 0 5px 0;"><?php echo esc_html($team->name); ?></h3>
                                <p style="margin: 0; color: #666;">
                                    <?php echo $team->default_size; ?> people
                                </p>
                            </div>
                            
                            <div>
                                <?php if ($team->is_checked_in): ?>
                                    <span style="background: #46b450; color: white; padding: 8px 15px; border-radius: 4px; font-weight: bold;">
                                        ✓ Checked In
                                    </span>
                                <?php elseif ($team->has_signup): ?>
                                    <span style="background: #2271b1; color: white; padding: 8px 15px; border-radius: 4px;">
                                        Scheduled Today
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($team->signup): ?>
                            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;">
                                <small style="color: #666;">
                                    <?php echo esc_html($team->signup->opportunity_name); ?>
                                    <?php if ($team->signup->start_time): ?>
                                        @ <?php echo date('g:i A', strtotime($team->signup->start_time)); ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <script>
        function selectTeam(teamId, teamData) {
            if (teamData.is_checked_in) {
                // Show check-out interface
                showTeamCheckout(teamData);
            } else {
                // Show check-in interface
                showTeamCheckin(teamData);
            }
        }
        
        function showTeamCheckin(team) {
            // Replace interface with check-in form
            const html = `
                <div style="max-width: 500px; margin: 0 auto; text-align: center;">
                    <h2>Check In: ${team.name}</h2>
                    <p style="color: #666; margin-bottom: 30px;">${team.signup.opportunity_name}</p>
                    
                    <div style="margin: 30px 0;">
                        <label style="display: block; margin-bottom: 10px; font-size: 18px;">
                            How many people are here today?
                        </label>
                        <input type="number" id="people_count" 
                               value="${team.default_size}" 
                               min="1" 
                               style="font-size: 48px; width: 150px; padding: 20px; text-align: center; border: 2px solid #2271b1; border-radius: 8px;">
                    </div>
                    
                    <div style="margin: 30px 0;">
                        <button onclick="submitTeamCheckin(${team.signup.id})" 
                                style="background: #46b450; color: white; border: none; padding: 20px 40px; font-size: 24px; border-radius: 8px; cursor: pointer;">
                            Check In
                        </button>
                    </div>
                    
                    <div style="margin-top: 30px;">
                        <button onclick="location.reload()" 
                                style="background: #ddd; color: #666; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            `;
            
            document.querySelector('.team-selector').innerHTML = html;
        }
        
        function showTeamCheckout(team) {
            const checkinTime = new Date(team.active_checkin.check_in_time);
            const now = new Date();
            const hours = ((now - checkinTime) / 1000 / 60 / 60).toFixed(2);
            
            const html = `
                <div style="max-width: 500px; margin: 0 auto; text-align: center;">
                    <h2>Check Out: ${team.name}</h2>
                    <p style="color: #666;">${team.active_checkin.opportunity_name}</p>
                    
                    <div style="background: #f0f6fc; padding: 20px; border-radius: 8px; margin: 30px 0;">
                        <div style="font-size: 18px; color: #666;">Checked in at</div>
                        <div style="font-size: 24px; font-weight: bold; margin: 10px 0;">
                            ${checkinTime.toLocaleTimeString()}
                        </div>
                        <div style="font-size: 18px; color: #666;">
                            ${team.active_checkin.people_count} people • ~${hours} hours
                        </div>
                    </div>
                    
                    <div style="margin: 30px 0;">
                        <button onclick="submitTeamCheckout(${team.active_checkin.id})" 
                                style="background: #dc3232; color: white; border: none; padding: 20px 40px; font-size: 24px; border-radius: 8px; cursor: pointer;">
                            Check Out
                        </button>
                    </div>
                    
                    <div style="margin-top: 30px;">
                        <button onclick="location.reload()" 
                                style="background: #ddd; color: #666; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            `;
            
            document.querySelector('.team-selector').innerHTML = html;
        }
        
        function submitTeamCheckin(signupId) {
            const peopleCount = document.getElementById('people_count').value;
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=fs_team_kiosk_checkin&team_signup_id=${signupId}&people_count=${peopleCount}&notes=`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Team checked in successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.data);
                }
            });
        }
        
        function submitTeamCheckout(attendanceId) {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=fs_team_kiosk_checkout&attendance_id=${attendanceId}&notes=`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(`Team checked out! Total hours: ${data.data.total_hours}`);
                    location.reload();
                } else {
                    alert('Error: ' + data.data);
                }
            });
        }
        </script>
        <?php
    }
}
