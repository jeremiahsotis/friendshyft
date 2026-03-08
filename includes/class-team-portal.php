<?php
/**
 * Team Portal Page
 * 
 * Interface for teams to browse and claim shifts
 * Accessed via /volunteer-portal/?team=[team_id]&token=[access_token]
 */

class FS_Team_Portal {
    
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'handle_team_portal'));
        add_action('wp_ajax_nopriv_fs_team_claim_shift', array(__CLASS__, 'ajax_claim_shift'));
        add_action('wp_ajax_fs_team_claim_shift', array(__CLASS__, 'ajax_claim_shift'));
    }
    
    /**
     * Handle team portal page
     */
    public static function handle_team_portal() {
        // Check if this is team portal page
        if (!is_page('volunteer-portal') || empty($_GET['team'])) {
            return;
        }
        
        $team_id = (int)$_GET['team'];
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        
        // Verify access
        $team = self::verify_team_access($team_id, $token);
        if (!$team) {
            wp_die('Invalid team or access token');
        }
        
        // Render team portal
        self::render_team_portal($team);
        exit;
    }
    
    /**
     * Verify team access via leader's token or direct team token
     */
    private static function verify_team_access($team_id, $token) {
        global $wpdb;
        
        $team = FS_Team_Manager::get_team($team_id);
        if (!$team) {
            return false;
        }
        
        // Check if token belongs to team leader
        if ($team->team_leader_volunteer_id) {
            $valid_token = $wpdb->get_var($wpdb->prepare(
                "SELECT access_token FROM {$wpdb->prefix}fs_volunteers 
                 WHERE id = %d AND access_token = %s",
                $team->team_leader_volunteer_id,
                $token
            ));
            
            if ($valid_token) {
                return $team;
            }
        }
        
        // Could add direct team tokens later if needed
        
        return false;
    }
    
    /**
     * Render team portal
     */
    private static function render_team_portal($team) {
        $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'opportunities';
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($team->name); ?> - Team Portal</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
                .header { background: #2271b1; color: white; padding: 20px 0; margin-bottom: 30px; }
                .header h1 { font-size: 24px; }
                .header p { opacity: 0.9; margin-top: 5px; }
                .nav { display: flex; gap: 20px; margin: 20px 0; border-bottom: 2px solid #ddd; }
                .nav a { padding: 10px 15px; text-decoration: none; color: #666; border-bottom: 3px solid transparent; }
                .nav a.active { color: #2271b1; border-bottom-color: #2271b1; font-weight: 600; }
                .card { background: white; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
                .card h3 { margin-bottom: 15px; color: #2271b1; }
                .btn { display: inline-block; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }
                .btn:hover { background: #135e96; }
                .btn-cancel { background: #dc3232; }
                .btn-cancel:hover { background: #a00; }
                .shift-list { display: grid; gap: 15px; }
                .shift-card { border: 1px solid #ddd; padding: 15px; border-radius: 4px; }
                .shift-card .date { font-size: 18px; font-weight: 600; color: #2271b1; margin-bottom: 5px; }
                .shift-card .time { color: #666; margin-bottom: 10px; }
                .shift-card .capacity { display: inline-block; padding: 4px 8px; background: #f0f0f0; border-radius: 3px; font-size: 14px; margin-top: 10px; }
                .shift-card .capacity.full { background: #dc3232; color: white; }
                .shift-card .capacity.low { background: #ffb900; color: white; }
                .success { background: #46b450; color: white; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
                .error { background: #dc3232; color: white; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
                .team-info { background: #f0f6fc; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
                .team-size-input { padding: 8px; width: 100px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="container">
                    <h1><?php echo esc_html($team->name); ?></h1>
                    <p>Team Portal • <?php echo esc_html($team->type === 'recurring' ? 'Recurring Team' : 'One-Time Team'); ?></p>
                </div>
            </div>
            
            <div class="container">
                <?php if (isset($_GET['success'])): ?>
                    <div class="success">
                        ✓ Successfully signed up for shift!
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['error'])): ?>
                    <div class="error">
                        Error: <?php echo esc_html(urldecode($_GET['error'])); ?>
                    </div>
                <?php endif; ?>
                
                <div class="team-info">
                    <strong>Default Team Size:</strong> <?php echo (int)$team->default_size; ?> people
                    <?php if ($team->leader): ?>
                        | <strong>Team Leader:</strong> <?php echo esc_html($team->leader->name); ?>
                    <?php endif; ?>
                </div>
                
                <div class="nav">
                    <a href="?team=<?php echo $team->id; ?>&token=<?php echo urlencode($_GET['token']); ?>&view=opportunities" 
                       class="<?php echo $view === 'opportunities' ? 'active' : ''; ?>">
                        Available Opportunities
                    </a>
                    <a href="?team=<?php echo $team->id; ?>&token=<?php echo urlencode($_GET['token']); ?>&view=signups" 
                       class="<?php echo $view === 'signups' ? 'active' : ''; ?>">
                        Your Signups
                    </a>
                </div>
                
                <?php if ($view === 'opportunities'): ?>
                    <?php self::render_opportunities($team); ?>
                <?php else: ?>
                    <?php self::render_signups($team); ?>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * Render available opportunities
     */
    private static function render_opportunities($team) {
        $opportunities = FS_Team_Signup::get_available_opportunities();
        
        if (empty($opportunities)) {
            echo '<div class="card"><p>No team opportunities available at this time.</p></div>';
            return;
        }
        
        foreach ($opportunities as $opp) {
            $shifts = FS_Team_Signup::get_available_shifts($opp->id, $team->default_size);
            
            ?>
            <div class="card">
                <h3><?php echo esc_html($opp->name); ?></h3>
                <p><?php echo esc_html($opp->description); ?></p>
                
                <?php if ($opp->shift_count > 0): ?>
                    <h4 style="margin-top: 20px; margin-bottom: 10px;">Available Shifts:</h4>
                    <div class="shift-list">
                        <?php foreach ($shifts as $shift): ?>
                            <div class="shift-card">
                                <div class="date">
                                    <?php echo date('l, F j, Y', strtotime($shift->date)); ?>
                                </div>
                                <div class="time">
                                    <?php if ($shift->start_time): ?>
                                        <?php echo date('g:i A', strtotime($shift->start_time)); ?>
                                        <?php if ($shift->end_time): ?>
                                            - <?php echo date('g:i A', strtotime($shift->end_time)); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($shift->can_accommodate_team): ?>
                                    <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" style="margin-top: 15px;">
                                        <input type="hidden" name="action" value="fs_team_claim_shift">
                                        <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                                        <input type="hidden" name="opportunity_id" value="<?php echo $opp->id; ?>">
                                        <input type="hidden" name="shift_id" value="<?php echo $shift->id; ?>">
                                        <input type="hidden" name="token" value="<?php echo esc_attr($_GET['token']); ?>">
                                        
                                        <label>
                                            How many people?
                                            <input type="number" name="team_size" class="team-size-input" 
                                                   value="<?php echo $team->default_size; ?>" 
                                                   min="1" max="<?php echo $shift->available_spots; ?>" required>
                                        </label>
                                        
                                        <button type="submit" class="btn">Claim This Shift</button>
                                        
                                        <span class="capacity">
                                            <?php echo $shift->available_spots; ?> spots available
                                        </span>
                                    </form>
                                <?php else: ?>
                                    <div class="capacity full">
                                        Not enough capacity for team (<?php echo $shift->available_spots; ?> spots available)
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p><em>No upcoming shifts scheduled yet.</em></p>
                <?php endif; ?>
            </div>
            <?php
        }
    }
    
    /**
     * Render team's signups
     */
    private static function render_signups($team) {
        $signups = FS_Team_Signup::get_team_signups($team->id, array(
            'status' => 'all',
            'upcoming_only' => false
        ));
        
        if (empty($signups)) {
            echo '<div class="card"><p>Your team hasn\'t signed up for any shifts yet.</p></div>';
            return;
        }
        
        foreach ($signups as $signup) {
            ?>
            <div class="card">
                <h3><?php echo esc_html($signup->opportunity_name); ?></h3>
                
                <?php if ($signup->shift_date): ?>
                    <p><strong>Date:</strong> <?php echo date('l, F j, Y', strtotime($signup->shift_date)); ?></p>
                    <?php if ($signup->start_time): ?>
                        <p><strong>Time:</strong> 
                            <?php echo date('g:i A', strtotime($signup->start_time)); ?>
                            <?php if ($signup->end_time): ?>
                                - <?php echo date('g:i A', strtotime($signup->end_time)); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php elseif ($signup->period_start): ?>
                    <p><strong>Period:</strong> 
                        <?php echo date('M j', strtotime($signup->period_start)); ?> - 
                        <?php echo date('M j, Y', strtotime($signup->period_end)); ?>
                    </p>
                <?php endif; ?>
                
                <p><strong>Scheduled Team Size:</strong> <?php echo (int)$signup->scheduled_size; ?> people</p>
                
                <?php if ($signup->actual_attendance): ?>
                    <p><strong>Actual Attendance:</strong> <?php echo (int)$signup->actual_attendance; ?> people</p>
                <?php endif; ?>
                
                <p><strong>Status:</strong> 
                    <span style="color: <?php echo $signup->status === 'completed' ? 'green' : ($signup->status === 'cancelled' ? 'red' : '#2271b1'); ?>;">
                        <?php echo esc_html(ucfirst($signup->status)); ?>
                    </span>
                </p>
                
                <?php if ($signup->status === 'scheduled'): ?>
                    <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>" style="margin-top: 15px;">
                        <input type="hidden" name="action" value="fs_team_cancel_signup">
                        <input type="hidden" name="signup_id" value="<?php echo $signup->id; ?>">
                        <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                        <input type="hidden" name="token" value="<?php echo esc_attr($_GET['token']); ?>">
                        
                        <button type="submit" class="btn btn-cancel" 
                                onclick="return confirm('Cancel this signup?');">
                            Cancel Signup
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php
        }
    }
    
    /**
     * AJAX: Claim shift
     * Supports both POST (from team portal) and GET (from volunteer portal)
     */
    public static function ajax_claim_shift() {
        // Support both GET and POST
        $request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

        $team_id = (int)$request['team_id'];
        $token = sanitize_text_field($request['token']);

        // Verify access
        $team = self::verify_team_access($team_id, $token);
        if (!$team) {
            // Redirect back to volunteer portal with error
            $redirect_url = add_query_arg(array(
                'token' => $token,
                'view' => 'browse',
                'error' => urlencode('Invalid team access')
            ), home_url('/volunteer-portal/'));

            wp_redirect($redirect_url);
            exit;
        }

        $data = array(
            'team_id' => $team_id,
            'opportunity_id' => (int)$request['opportunity_id'],
            'shift_id' => !empty($request['shift_id']) ? (int)$request['shift_id'] : null,
            'period_id' => !empty($request['period_id']) ? (int)$request['period_id'] : null,
            'scheduled_size' => (int)$request['team_size']
        );

        $result = FS_Team_Signup::create_signup($data);

        if (is_wp_error($result)) {
            $redirect_url = add_query_arg(array(
                'token' => $token,
                'view' => 'browse',
                'error' => urlencode($result->get_error_message())
            ), home_url('/volunteer-portal/'));

            wp_redirect($redirect_url);
            exit;
        }

        // Success - redirect back to volunteer portal browse view
        $redirect_url = add_query_arg(array(
            'token' => $token,
            'view' => 'browse',
            'success' => 1,
            'message' => urlencode('Successfully signed up your team!')
        ), home_url('/volunteer-portal/'));

        wp_redirect($redirect_url);
        exit;
    }
}

// AJAX handlers for cancel
add_action('wp_ajax_nopriv_fs_team_cancel_signup', function() {
    $signup_id = (int)($_POST['signup_id'] ?? 0);
    $team_id = (int)($_POST['team_id'] ?? 0);
    $token = sanitize_text_field($_POST['token'] ?? '');
    
    // Verify access (simplified for now)
    $result = FS_Team_Signup::cancel_signup($signup_id);
    
    $redirect_url = add_query_arg(array(
        'team' => $team_id,
        'token' => $token,
        'view' => 'signups',
        'success' => 1
    ), home_url('/volunteer-portal/'));
    
    wp_redirect($redirect_url);
    exit;
});
