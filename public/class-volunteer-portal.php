<?php
if (!defined('ABSPATH')) exit;

class FS_Volunteer_Portal {
    
    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_shortcode('volunteer_dashboard', array(__CLASS__, 'dashboard_shortcode'));
        add_shortcode('volunteer_portal', array(__CLASS__, 'dashboard_shortcode'));
        add_shortcode('volunteer_opportunities', array(__CLASS__, 'opportunities_shortcode'));

        // Logged-in handlers
        add_action('wp_ajax_fs_signup_opportunity', array(__CLASS__, 'handle_signup'));
        add_action('wp_ajax_fs_cancel_signup', array(__CLASS__, 'handle_cancel'));
        add_action('wp_ajax_fs_complete_step', array(__CLASS__, 'handle_complete_step'));

        // Token-based (non-logged-in) handlers
        add_action('wp_ajax_nopriv_fs_signup_opportunity', array(__CLASS__, 'handle_signup'));
        add_action('wp_ajax_nopriv_fs_cancel_signup', array(__CLASS__, 'handle_cancel'));
        add_action('wp_ajax_nopriv_fs_complete_step', array(__CLASS__, 'handle_complete_step'));

        // Blocked time management (availability) - both logged-in and token-based
        add_action('wp_ajax_fs_portal_add_blocked_time', array(__CLASS__, 'ajax_portal_add_blocked_time'));
        add_action('wp_ajax_nopriv_fs_portal_add_blocked_time', array(__CLASS__, 'ajax_portal_add_blocked_time'));
        add_action('wp_ajax_fs_portal_delete_blocked_time', array(__CLASS__, 'ajax_portal_delete_blocked_time'));
        add_action('wp_ajax_nopriv_fs_portal_delete_blocked_time', array(__CLASS__, 'ajax_portal_delete_blocked_time'));

        add_shortcode('fs_flexible_selection', array(__CLASS__, 'flexible_selection_shortcode'));
        add_action('wp_ajax_fs_claim_week', array(__CLASS__, 'ajax_claim_week'));
        add_action('wp_ajax_fs_unclaim_week', array(__CLASS__, 'ajax_unclaim_week'));

        add_shortcode('fs_flexible_calendar', array(__CLASS__, 'flexible_calendar_shortcode'));

        // Register REST API routes
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
    }

    /**
     * Register REST API routes
     */
    public static function register_rest_routes() {
        register_rest_route('friendshyft/v1', '/complete-step', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_complete_step'),
            'permission_callback' => '__return_true' // We handle auth in the callback
        ));

        register_rest_route('friendshyft/v1', '/signup-opportunity', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_signup'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('friendshyft/v1', '/cancel-signup', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_cancel'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * REST API handler for completing steps
     */
    public static function rest_complete_step($request) {
        // Copy POST data for handle_complete_step
        $_POST = $request->get_params();
        return self::handle_complete_step();
    }

    /**
     * REST API handler for signup
     */
    public static function rest_signup($request) {
        $_POST = $request->get_params();
        return self::handle_signup();
    }

    /**
     * REST API handler for cancel
     */
    public static function rest_cancel($request) {
        $_POST = $request->get_params();
        return self::handle_cancel();
    }
    
    public static function enqueue_scripts() {
        if (is_user_logged_in()) {
            wp_enqueue_script('jquery');
        }
    }

    /**
     * Get volunteer record for currently logged in user
     * Works with or without Monday.com
     * 
     * @return object|null Volunteer record or null if not found
     */
    private static function get_current_volunteer() {
        if (!is_user_logged_in()) {
            return null;
        }
    
        global $wpdb;
        $user_id = get_current_user_id();
    
        // Try Monday ID first
        $monday_id = get_user_meta($user_id, 'monday_contact_id', true);
        if (!empty($monday_id)) {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE monday_id = %s",
                $monday_id
            ));
            if ($volunteer) {
                return $volunteer;
            }
        }
    
        // Try local volunteer ID
        $local_volunteer_id = get_user_meta($user_id, 'fs_volunteer_id', true);
        if (!empty($local_volunteer_id)) {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                $local_volunteer_id
            ));
            if ($volunteer) {
                return $volunteer;
            }
        }
    
        // Last resort: find by WP user ID
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE wp_user_id = %d",
            $user_id
        ));
    
        return $volunteer;
    }

    /**
     * Check for token-based authentication
     * Returns volunteer if valid token, null otherwise
     */
    private static function check_token_auth() {
        if (!isset($_GET['token'])) {
            return null;
        }
    
        $token = sanitize_text_field($_GET['token']);
    
        if (empty($token)) {
            return null;
        }
    
        global $wpdb;
    
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $token
        ));
    
        return $volunteer;
    }

    /**
 * Get logged in volunteer - checks token auth first, then WordPress login
 * This is the primary method to use for volunteer portal authentication
 */
private static function get_logged_in_volunteer() {
    // Check for token authentication first
    $volunteer = self::check_token_auth();
    
    if ($volunteer) {
        return $volunteer;
    }
    
    // Fall back to WordPress login
    if (!is_user_logged_in()) {
        return null;
    }
    
    return self::get_current_volunteer();
}

/**
 * Simple login form for volunteers without tokens
 */
private static function login_form() {
    ob_start();
    ?>
    <div class="friendshyft-login-prompt">
        <div class="login-card">
            <h2>Volunteer Portal Login</h2>
            <p>Please log in to access your volunteer dashboard.</p>
            <a href="<?php echo wp_login_url(get_permalink()); ?>" class="btn-login">
                Log In
            </a>
        </div>
    </div>
    
    <style>
        .friendshyft-login-prompt {
            max-width: 500px;
            margin: 60px auto;
            padding: 20px;
        }
        .login-card {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            text-align: center;
        }
        .login-card h2 {
            margin: 0 0 15px 0;
            color: #0073aa;
        }
        .login-card p {
            color: #666;
            margin-bottom: 25px;
        }
        .btn-login {
            display: inline-block;
            background: #0073aa;
            color: white;
            padding: 15px 40px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: background 0.2s;
        }
        .btn-login:hover {
            background: #005177;
            color: white;
        }
    </style>
    <?php
    return ob_get_clean();
}
    
    public static function dashboard_shortcode() {
    // Check if volunteer is logged in
    $volunteer = self::get_logged_in_volunteer();
    if (!$volunteer) {
        return self::login_form();
    }
    
    global $wpdb;
    
    // Get token if present and build portal base URL with it
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $portal_url = home_url('/volunteer-portal/');
    if ($token) {
        $portal_url = add_query_arg('token', $token, $portal_url);
    }
    
    // Check which view is requested
    $view = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'dashboard';
    
    // Route to appropriate view
    switch ($view) {
        case 'browse':
            return self::opportunities_shortcode();

        case 'profile':
            return self::profile_view($volunteer, $portal_url);

        case 'schedule':
            return self::schedule_view($volunteer, $portal_url);

        case 'history':
            return self::history_view($volunteer, $portal_url);

        case 'teams':
            return self::teams_view($volunteer, $portal_url);

        case 'create-team':
            return self::create_team_view($volunteer, $portal_url);

        case 'availability':
            // Redirect old availability view to new recurring-schedule view
            return self::recurring_schedule_view($volunteer, $portal_url);

        case 'feedback':
            return FS_Portal_Feedback::render_feedback_view($volunteer, $portal_url);

        case 'substitutes':
            return self::substitutes_view($volunteer, $portal_url);

        case 'recurring-schedule':
            return self::recurring_schedule_view($volunteer, $portal_url);

        case 'dashboard':
        default:
            // Continue to dashboard rendering below
            break;
    }
    
    // Get volunteer's upcoming signups (individual)
$upcoming_signups = $wpdb->get_results($wpdb->prepare(
    "SELECT s.*, o.title, o.event_date, o.location,
            sh.shift_start_time, sh.shift_end_time,
            'individual' as signup_type
     FROM {$wpdb->prefix}fs_signups s
     JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
     LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
     WHERE s.volunteer_id = %d
     AND s.status = 'confirmed'
     AND o.event_date >= CURDATE()
     ORDER BY o.event_date, sh.shift_start_time
     LIMIT 10",
    $volunteer->id
));

// Get volunteer's upcoming team signups
$team_signups = $wpdb->get_results($wpdb->prepare(
    "SELECT ts.*, o.title, o.event_date, o.location,
            sh.shift_start_time, sh.shift_end_time,
            t.name as team_name, ts.scheduled_size,
            'team' as signup_type
     FROM {$wpdb->prefix}fs_team_signups ts
     JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
     JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
     LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON ts.shift_id = sh.id
     WHERE ts.status != 'cancelled'
     AND o.event_date >= CURDATE()
     AND (t.team_leader_volunteer_id = %d
          OR EXISTS (
              SELECT 1 FROM {$wpdb->prefix}fs_team_members tm
              WHERE tm.team_id = t.id AND tm.volunteer_id = %d
          ))
     ORDER BY o.event_date, sh.shift_start_time
     LIMIT 10",
    $volunteer->id,
    $volunteer->id
));

// Merge and sort all signups
$all_signups = array_merge($upcoming_signups, $team_signups);
usort($all_signups, function($a, $b) {
    $date_cmp = strcmp($a->event_date, $b->event_date);
    if ($date_cmp !== 0) return $date_cmp;
    return strcmp($a->shift_start_time ?? '', $b->shift_start_time ?? '');
});
$upcoming_signups = array_slice($all_signups, 0, 10);
    
    // Get volunteer's total hours
    $total_hours = FS_Time_Tracking::get_volunteer_hours($volunteer->id);
    
    // Get volunteer's total signups
    $total_signups = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups 
         WHERE volunteer_id = %d AND status = 'confirmed'",
        $volunteer->id
    ));
    
    // Check onboarding status
    $onboarding_incomplete = empty($volunteer->emergency_contact_name) || 
                             empty($volunteer->emergency_contact_phone);
    
    // Get volunteer badges
    $badges = FS_Badges::get_volunteer_badges($volunteer->id);
    $badge_progress = FS_Badges::get_badge_progress($volunteer->id);

    // Get progress records (workflows)
    $progress_records = $wpdb->get_results($wpdb->prepare(
        "SELECT p.*, w.name as workflow_name, w.steps as workflow_steps
        FROM {$wpdb->prefix}fs_progress p
        JOIN {$wpdb->prefix}fs_workflows w ON p.workflow_id = w.id
        WHERE p.volunteer_id = %d
        ORDER BY p.last_sync DESC",
        $volunteer->id
    ));

    ob_start();
    ?>
    <div class="friendshyft-portal dashboard-view">
        <div class="portal-header">
            <div class="header-content">
                <h1>Welcome back, <?php echo esc_html($volunteer->name); ?>! 👋</h1>
                <div class="header-actions">
                    <a href="<?php echo esc_url(add_query_arg('view', 'profile', $portal_url)); ?>" class="btn-secondary">
                        ⚙️ My Profile
                    </a>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/volunteer-portal/'))); ?>" class="btn-logout">
                        Logout
                    </a>
                </div>
            </div>
        </div>
        
        <div class="portal-content">
            
            <?php if ($onboarding_incomplete): ?>
            <div class="dashboard-section onboarding-alert">
                <h2>⚠️ Complete Your Profile</h2>
                <p>Please complete your emergency contact information to finish setting up your account.</p>
                <a href="<?php echo esc_url(add_query_arg('view', 'profile', $portal_url)); ?>" class="btn-primary">
                    Complete Profile Now
                </a>
            </div>
            <?php endif; ?>

            <?php if (!empty($progress_records)): ?>
            <div class="dashboard-section workflows-section">
                <h2>📋 Your Workflows</h2>
                <?php foreach ($progress_records as $progress):
                    $workflow_steps = json_decode($progress->workflow_steps, true);
                    $step_completions = json_decode($progress->step_completions, true);

                    // Calculate progress
                    $total_steps = count($step_completions);
                    $completed_steps = 0;
                    foreach ($step_completions as $step) {
                        if ($step['completed']) {
                            $completed_steps++;
                        }
                    }
                    $progress_percentage = $total_steps > 0 ? round(($completed_steps / $total_steps) * 100) : 0;
                ?>
                <div class="workflow-card">
                    <div class="workflow-header">
                        <h3><?php echo esc_html($progress->workflow_name); ?></h3>
                        <span class="workflow-status"><?php echo esc_html($progress->overall_status); ?></span>
                    </div>
                    <div class="workflow-progress">
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
                        </div>
                        <span class="progress-text"><?php echo $completed_steps; ?> of <?php echo $total_steps; ?> steps completed (<?php echo $progress_percentage; ?>%)</span>
                    </div>
                    <div class="workflow-steps">
                        <?php foreach ($step_completions as $index => $step):
                            $is_completed = $step['completed'];
                            $step_class = $is_completed ? 'step-complete' : 'step-incomplete';
                            $step_details = null;
                            foreach ($workflow_steps as $ws) {
                                if ($ws['name'] === $step['name']) {
                                    $step_details = $ws;
                                    break;
                                }
                            }
                        ?>
                        <div class="workflow-step <?php echo $step_class; ?>">
                            <div class="step-indicator">
                                <?php if ($is_completed): ?>
                                    <span class="step-checkmark">✓</span>
                                <?php else: ?>
                                    <span class="step-number"><?php echo $index + 1; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="step-info">
                                <div class="step-name"><?php echo esc_html($step['name']); ?></div>
                                <div class="step-meta">
                                    <span class="step-type type-<?php echo esc_attr(strtolower($step['type'])); ?>"><?php echo esc_html($step['type']); ?></span>
                                    <?php if ($step['required']): ?>
                                        <span class="step-required">Required</span>
                                    <?php endif; ?>
                                    <?php if ($is_completed && $step['completed_date']): ?>
                                        <span class="step-completed-date">Completed <?php echo date('M j, Y', strtotime($step['completed_date'])); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($step_details && $step_details['description']): ?>
                                    <p class="step-description"><?php echo nl2br(esc_html($step_details['description'])); ?></p>
                                <?php endif; ?>
                                <?php if (!$is_completed && $step_details && $step_details['content_url'] && $step['type'] === 'Automated'): ?>
                                    <a href="<?php echo esc_url($step_details['content_url']); ?>" class="step-action-btn" target="_blank">
                                        View Content →
                                    </a>
                                <?php endif; ?>
                                <?php if (!$is_completed && $step['type'] === 'Automated'): ?>
                                    <button class="btn-complete-step" data-progress-id="<?php echo $progress->id; ?>" data-step-name="<?php echo esc_attr($step['name']); ?>">
                                        Mark as Complete
                                    </button>
                                <?php elseif (!$is_completed && in_array($step['type'], array('Manual', 'In-Person'))): ?>
                                    <p class="step-pending-text">⏳ Staff will complete this step with you</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            // Get volunteer badges
            $badges = FS_Badges::get_volunteer_badges($volunteer->id);
            $badge_progress = FS_Badges::get_badge_progress($volunteer->id);

            if (!empty($badges) || !empty($badge_progress)):
            ?>
            <div class="dashboard-section badges-section">
                <h2>🏆 Your Achievements</h2>
                
                <?php if (!empty($badges)): ?>
                <div class="badges-earned">
                    <h3>Badges Earned</h3>
                    <div class="badges-grid">
                        <?php foreach ($badges as $badge): ?>
                            <div class="badge-card" title="Earned <?php echo date('M j, Y', strtotime($badge['earned_date'])); ?>">
                                <div class="badge-icon"><?php echo $badge['icon']; ?></div>
                                <div class="badge-name"><?php echo esc_html($badge['name']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($badge_progress)): ?>
                <div class="badges-progress">
                    <h3>Next Milestones</h3>
                    <?php foreach ($badge_progress as $prog): ?>
                        <div class="progress-item">
                            <div class="progress-header">
                                <span class="progress-icon"><?php echo $prog['icon']; ?></span>
                                <span class="progress-name"><?php echo esc_html($prog['name']); ?></span>
                                <span class="progress-stats"><?php echo $prog['current']; ?> / <?php echo $prog['target']; ?></span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $prog['percentage']; ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="dashboard-section stats-section">
                <h2>📊 Your Impact</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">⏰</div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo number_format($total_hours, 1); ?></div>
                            <div class="stat-label">Total Hours</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">✓</div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo number_format($total_signups); ?></div>
                            <div class="stat-label">Opportunities Completed</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🏆</div>
                        <div class="stat-details">
                            <div class="stat-value"><?php echo count($badges); ?></div>
                            <div class="stat-label">Badges Earned</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-section">
                <div class="upcoming-signups">
                    <h3>Your Upcoming Opportunities</h3>
                    <?php if (empty($upcoming_signups)): ?>
                        <p class="no-signups">No upcoming opportunities scheduled.</p>
                        <p><a href="<?php echo esc_url(add_query_arg('view', 'browse', $portal_url)); ?>" class="browse-link">Browse Available Opportunities →</a></p>
                    <?php else: ?>
                        <div class="signups-list">
                            <?php foreach ($upcoming_signups as $signup):
                                $event_datetime = strtotime($signup->event_date . ' ' . ($signup->shift_start_time ?: '00:00:00'));
                                $is_soon = ($event_datetime - time()) < (48 * 3600); // Within 48 hours
                                $is_team = $signup->signup_type === 'team';
                                $attendance_confirmed = isset($signup->attendance_confirmed) ? $signup->attendance_confirmed : false;
                                $needs_confirmation = !$attendance_confirmed && $is_soon && $signup->signup_type === 'individual';
                            ?>
                                <div class="signup-card <?php echo $needs_confirmation ? 'needs-confirmation' : ''; ?> <?php echo $is_team ? 'team-signup' : ''; ?>">
                                    <div class="signup-header">
                                        <div class="signup-date">
                                            <div class="date-day"><?php echo date('j', strtotime($signup->event_date)); ?></div>
                                            <div class="date-month"><?php echo date('M', strtotime($signup->event_date)); ?></div>
                                        </div>
                                        <div class="signup-details">
                                            <h4>
                                                <?php echo esc_html($signup->title); ?>
                                                <?php if ($is_team): ?>
                                                    <span class="team-indicator">👥 Team: <?php echo esc_html($signup->team_name); ?></span>
                                                <?php endif; ?>
                                            </h4>
                                            <p class="signup-time">
                                                <?php echo date('l, g:i A', strtotime($signup->event_date . ' ' . $signup->shift_start_time)); ?>
                                                <?php if ($signup->shift_end_time): ?>
                                                    - <?php echo date('g:i A', strtotime($signup->event_date . ' ' . $signup->shift_end_time)); ?>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($is_team): ?>
                                                <p class="team-size">Team Size: <?php echo (int)$signup->scheduled_size; ?> people</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($needs_confirmation): ?>
                                        <div class="confirmation-prompt">
                                            <p class="confirmation-text">⏰ Can you still make it?</p>
                                            <div class="confirmation-buttons">
                                                <button class="btn-confirm" data-signup-id="<?php echo $signup->id; ?>">
                                                    ✓ Yes, I'll Be There
                                                </button>
                                                <button class="btn-cancel-attendance" data-signup-id="<?php echo $signup->id; ?>">
                                                    Cancel This Opportunity
                                                </button>
                                            </div>
                                        </div>
                                    <?php elseif ($attendance_confirmed): ?>
                                        <div class="confirmation-status confirmed">
                                            ✓ Confirmed <?php echo isset($signup->confirmation_date) ? date('M j', strtotime($signup->confirmation_date)) : ''; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($signup->location): ?>
                                        <div class="signup-location">
                                            📍 <?php echo esc_html($signup->location); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="dashboard-section quick-actions">
                <h2>Quick Actions</h2>
                <div class="actions-grid">
                    <a href="<?php echo esc_url(add_query_arg('view', 'browse', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">🔍</div>
                        <div class="action-label">Browse Opportunities</div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'schedule', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">📅</div>
                        <div class="action-label">My Schedule</div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'history', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">📊</div>
                        <div class="action-label">My History</div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'teams', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">👥</div>
                        <div class="action-label">My Teams</div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'recurring-schedule', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">🔁</div>
                        <div class="action-label">Availability</div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'feedback', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">💬</div>
                        <div class="action-label">Give Feedback</div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'substitutes', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">🔄</div>
                        <div class="action-label">Find Substitutes</div>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'profile', $portal_url)); ?>" class="action-card">
                        <div class="action-icon">⚙️</div>
                        <div class="action-label">Profile Settings</div>
                    </a>
                </div>
            </div>
            
        </div>
    </div>
    
    <style>
    .friendshyft-portal {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    .portal-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 8px;
        margin-bottom: 30px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    .portal-header h1 {
        margin: 0;
        font-size: 28px;
        color: white;
    }
    .header-actions {
        display: flex;
        gap: 10px;
    }
    .btn-secondary, .btn-logout {
        padding: 10px 20px;
        border-radius: 4px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s;
    }
    .btn-secondary {
        background: rgba(255,255,255,0.2);
        color: white;
    }
    .btn-secondary:hover {
        background: rgba(255,255,255,0.3);
    }
    .btn-logout {
        background: rgba(0,0,0,0.2);
        color: white;
    }
    .btn-logout:hover {
        background: rgba(0,0,0,0.3);
    }
    .dashboard-section {
        background: white;
        padding: 25px;
        border-radius: 8px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .dashboard-section h2 {
        margin: 0 0 20px 0;
        font-size: 22px;
        color: #333;
    }
    .dashboard-section h3 {
        margin: 0 0 15px 0;
        font-size: 18px;
        color: #333;
    }
    .onboarding-alert {
        background: #fff9e6;
        border-left: 4px solid #f39c12;
    }
    .onboarding-alert h2 {
        color: #f39c12;
    }
    .btn-primary {
        display: inline-block;
        background: #0073aa;
        color: white;
        padding: 12px 24px;
        border-radius: 4px;
        text-decoration: none;
        font-weight: 600;
        margin-top: 10px;
    }
    .btn-primary:hover {
        background: #005a87;
    }

    /* Workflows Section */
    .workflows-section {
        background: #f8f9fa;
        border-left: 4px solid #0073aa;
    }
    .workflows-section h2 {
        color: #0073aa;
    }
    .workflow-card {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .workflow-card:last-child {
        margin-bottom: 0;
    }
    .workflow-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 2px solid #e9ecef;
    }
    .workflow-header h3 {
        margin: 0;
        font-size: 20px;
        color: #333;
    }
    .workflow-status {
        background: #e9ecef;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        color: #495057;
    }
    .workflow-progress {
        margin-bottom: 20px;
    }
    .progress-bar-container {
        height: 12px;
        background: #e9ecef;
        border-radius: 6px;
        overflow: hidden;
        margin-bottom: 8px;
    }
    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        transition: width 0.3s ease;
    }
    .progress-text {
        font-size: 14px;
        color: #666;
    }
    .workflow-steps {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .workflow-step {
        display: flex;
        gap: 15px;
        padding: 15px;
        border-radius: 8px;
        background: #f8f9fa;
        transition: all 0.2s;
    }
    .workflow-step.step-complete {
        background: #d4edda;
        border-left: 4px solid #28a745;
    }
    .workflow-step.step-incomplete {
        background: #fff;
        border: 2px solid #e9ecef;
    }
    .step-indicator {
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-weight: 700;
    }
    .step-complete .step-indicator {
        background: #28a745;
        color: white;
    }
    .step-incomplete .step-indicator {
        background: #e9ecef;
        color: #6c757d;
    }
    .step-checkmark {
        font-size: 24px;
    }
    .step-number {
        font-size: 18px;
    }
    .step-info {
        flex: 1;
    }
    .step-name {
        font-size: 16px;
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
    }
    .step-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 8px;
    }
    .step-type {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }
    .step-type.type-automated {
        background: #d1ecf1;
        color: #0c5460;
    }
    .step-type.type-manual {
        background: #fff3cd;
        color: #856404;
    }
    .step-type.type-in-person {
        background: #f8d7da;
        color: #721c24;
    }
    .step-required {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        background: #dc3545;
        color: white;
    }
    .step-completed-date {
        padding: 4px 8px;
        font-size: 12px;
        color: #666;
    }
    .step-description {
        font-size: 14px;
        color: #666;
        margin: 8px 0;
        line-height: 1.5;
    }
    .step-action-btn {
        display: inline-block;
        margin-top: 8px;
        padding: 8px 16px;
        background: #0073aa;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
    }
    .step-action-btn:hover {
        background: #005a87;
    }
    .btn-complete-step {
        margin-top: 8px;
        padding: 8px 16px;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-complete-step:hover {
        background: #218838;
    }
    .step-pending-text {
        margin-top: 8px;
        font-size: 14px;
        color: #856404;
        font-style: italic;
    }

    /* Badges Section */
    .badges-section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-color: #667eea;
        color: white;
    }
    .badges-section h2,
    .badges-section h3 {
        color: white;
    }
    .badges-earned {
        margin-bottom: 30px;
    }
    .badges-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }
    .badge-card {
        background: rgba(255, 255, 255, 0.2);
        padding: 20px;
        border-radius: 8px;
        text-align: center;
        transition: transform 0.2s;
        cursor: pointer;
    }
    .badge-card:hover {
        transform: scale(1.05);
        background: rgba(255, 255, 255, 0.3);
    }
    .badge-icon {
        font-size: 48px;
        margin-bottom: 10px;
    }
    .badge-name {
        font-weight: 600;
        font-size: 14px;
    }
    .badges-progress {
        background: rgba(255, 255, 255, 0.1);
        padding: 20px;
        border-radius: 8px;
    }
    .progress-item {
        margin-bottom: 20px;
    }
    .progress-item:last-child {
        margin-bottom: 0;
    }
    .progress-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }
    .progress-icon {
        font-size: 24px;
    }
    .progress-name {
        flex: 1;
        font-weight: 600;
    }
    .progress-stats {
        font-size: 14px;
        opacity: 0.9;
    }
    .progress-bar {
        height: 8px;
        background: rgba(255, 255, 255, 0.3);
        border-radius: 4px;
        overflow: hidden;
    }
    .progress-fill {
        height: 100%;
        background: white;
        transition: width 0.3s;
    }
    
    /* Stats Section */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }
    .stat-card {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 8px;
    }
    .stat-icon {
        font-size: 40px;
    }
    .stat-value {
        font-size: 32px;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 5px;
    }
    .stat-label {
        font-size: 14px;
        opacity: 0.9;
    }
    
    /* Signups */
    .no-signups {
        text-align: center;
        color: #999;
        padding: 20px;
        font-style: italic;
    }
    .browse-link {
        color: #0073aa;
        text-decoration: none;
        font-weight: 600;
    }
    .browse-link:hover {
        text-decoration: underline;
    }
    .signups-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .signup-card {
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 20px;
        background: white;
        transition: all 0.2s;
    }
    .signup-card:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .signup-card.needs-confirmation {
        border-left: 4px solid #f39c12;
        background: #fff9e6;
    }
    .signup-card.team-signup {
        border-left: 4px solid #667eea;
        background: #f0f6fc;
    }
    .team-indicator {
        display: inline-block;
        font-size: 13px;
        color: #667eea;
        font-weight: 600;
        margin-left: 8px;
        padding: 2px 8px;
        background: rgba(102, 126, 234, 0.1);
        border-radius: 4px;
    }
    .team-size {
        font-size: 13px;
        color: #666;
        margin: 5px 0 0 0;
        font-style: italic;
    }
    .signup-header {
        display: flex;
        gap: 20px;
        align-items: flex-start;
    }
    .signup-date {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 10px;
        border-radius: 8px;
        text-align: center;
        min-width: 60px;
    }
    .date-day {
        font-size: 28px;
        font-weight: 700;
        line-height: 1;
    }
    .date-month {
        font-size: 12px;
        text-transform: uppercase;
        margin-top: 2px;
    }
    .signup-details {
        flex: 1;
    }
    .signup-details h4 {
        margin: 0 0 5px 0;
        color: #333;
        font-size: 18px;
    }
    .signup-program {
        color: #666;
        margin: 0 0 5px 0;
        font-size: 14px;
    }
    .signup-time {
        color: #999;
        margin: 0;
        font-size: 14px;
    }
    .signup-location {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #e0e0e0;
        color: #666;
        font-size: 14px;
    }
    .confirmation-prompt {
        background: #f39c12;
        color: white;
        padding: 15px;
        margin-top: 15px;
        border-radius: 4px;
        text-align: center;
    }
    .confirmation-text {
        margin: 0 0 10px 0;
        font-weight: 600;
    }
    .confirmation-buttons {
        display: flex;
        gap: 10px;
        justify-content: center;
        flex-wrap: wrap;
    }
    .btn-confirm,
    .btn-cancel-attendance {
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 14px;
    }
    .btn-confirm {
        background: white;
        color: #27ae60;
    }
    .btn-confirm:hover {
        background: #27ae60;
        color: white;
    }
    .btn-cancel-attendance {
        background: rgba(255, 255, 255, 0.3);
        color: white;
    }
    .btn-cancel-attendance:hover {
        background: rgba(255, 255, 255, 0.5);
    }
    .confirmation-status {
        padding: 10px;
        margin-top: 15px;
        border-radius: 4px;
        text-align: center;
        font-weight: 600;
    }
    .confirmation-status.confirmed {
        background: #d4edda;
        color: #155724;
    }
    
    /* Quick Actions */
    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
    }
    .action-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        transition: all 0.2s;
    }
    .action-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    .action-icon {
        font-size: 40px;
        margin-bottom: 10px;
    }
    .action-label {
        font-weight: 600;
        font-size: 14px;
        text-align: center;
    }
    
    /* Mobile Responsive */
    @media (max-width: 768px) {
        .friendshyft-portal {
            padding: 10px;
        }
        .portal-header {
            padding: 20px;
        }
        .portal-header h1 {
            font-size: 22px;
        }
        .header-content {
            flex-direction: column;
            align-items: flex-start;
        }
        .dashboard-section {
            padding: 15px;
        }
        .stats-grid {
            grid-template-columns: 1fr;
        }
        .badges-grid {
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        }
        .signup-header {
            flex-direction: column;
        }
        .signup-date {
            align-self: flex-start;
        }
        .confirmation-buttons {
            flex-direction: column;
        }
        .btn-confirm,
        .btn-cancel-attendance {
            width: 100%;
        }
        .actions-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        function showNotice(type, message) {
            const notice = $('<div class="portal-notice ' + type + '">' + message + '</div>');
            $('.portal-content').prepend(notice);
            setTimeout(function() {
                notice.fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        }
        
        // Confirm attendance
        $('.btn-confirm').on('click', function() {
            const button = $(this);
            const signupId = button.data('signup-id');
            const card = button.closest('.signup-card');
            const token = '<?php echo isset($_GET['token']) ? esc_js(sanitize_text_field($_GET['token'])) : ''; ?>';

            if (confirm('Confirm your attendance for this opportunity?')) {
                button.prop('disabled', true).text('Confirming...');

                var ajaxUrl, ajaxData;
                if (token) {
                    ajaxUrl = '<?php echo esc_url(rest_url('friendshyft/v1/confirm-attendance')); ?>';
                    ajaxData = {
                        signup_id: signupId,
                        volunteer_id: <?php echo $volunteer->id; ?>,
                        token: token
                    };
                } else {
                    ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
                    ajaxData = {
                        action: 'fs_confirm_attendance',
                        signup_id: signupId,
                        volunteer_id: <?php echo $volunteer->id; ?>,
                        nonce: '<?php echo wp_create_nonce('friendshyft_portal'); ?>'
                    };
                }

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: token ? JSON.stringify(ajaxData) : ajaxData,
                    contentType: token ? 'application/json' : 'application/x-www-form-urlencoded; charset=UTF-8',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            card.removeClass('needs-confirmation');
                            card.find('.confirmation-prompt').replaceWith(
                                '<div class="confirmation-status confirmed">✓ Confirmed just now</div>'
                            );
                            showNotice('success', 'Attendance confirmed! Thank you.');
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false).text('✓ Yes, I\'ll Be There');
                        }
                    },
                    error: function() {
                        alert('Failed to confirm attendance. Please try again.');
                        button.prop('disabled', false).text('✓ Yes, I\'ll Be There');
                    }
                });
            }
        });
        
        // Cancel attendance
        $('.btn-cancel-attendance').on('click', function() {
            const button = $(this);
            const signupId = button.data('signup-id');
            const card = button.closest('.signup-card');
            const token = '<?php echo isset($_GET['token']) ? esc_js(sanitize_text_field($_GET['token'])) : ''; ?>';

            if (confirm('Are you sure you want to cancel this opportunity? This will notify the team so they can find a replacement.')) {
                button.prop('disabled', true).text('Cancelling...');

                var ajaxUrl, ajaxData;
                if (token) {
                    ajaxUrl = '<?php echo esc_url(rest_url('friendshyft/v1/cancel-attendance')); ?>';
                    ajaxData = {
                        signup_id: signupId,
                        volunteer_id: <?php echo $volunteer->id; ?>,
                        token: token
                    };
                } else {
                    ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
                    ajaxData = {
                        action: 'fs_cancel_attendance',
                        signup_id: signupId,
                        volunteer_id: <?php echo $volunteer->id; ?>,
                        nonce: '<?php echo wp_create_nonce('friendshyft_portal'); ?>'
                    };
                }

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: token ? JSON.stringify(ajaxData) : ajaxData,
                    contentType: token ? 'application/json' : 'application/x-www-form-urlencoded; charset=UTF-8',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            card.fadeOut(300, function() {
                                $(this).remove();
                                // Check if any signups left
                                if ($('.signups-list .signup-card').length === 0) {
                                    $('.signups-list').html('<p class="no-signups">No upcoming opportunities scheduled.</p>');
                                }
                            });
                            showNotice('success', 'Opportunity cancelled. Thank you for letting us know.');
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false).text('Cancel This Opportunity');
                        }
                    },
                    error: function() {
                        alert('Failed to cancel. Please try again or contact us directly.');
                        button.prop('disabled', false).text('Cancel This Opportunity');
                    }
                });
            }
        });

        // Complete workflow step
        $('.btn-complete-step').on('click', function(e) {
            e.preventDefault();
            console.log('FriendShyft: Button clicked');

            const button = $(this);
            const progressId = button.data('progress-id');
            const stepName = button.data('step-name');
            const stepContainer = button.closest('.workflow-step');
            const token = '<?php echo isset($_GET['token']) ? esc_js(sanitize_text_field($_GET['token'])) : ''; ?>';

            console.log('FriendShyft: Progress ID:', progressId, 'Step:', stepName, 'Token:', token ? 'exists' : 'none');

            if (confirm('Mark "' + stepName + '" as complete?')) {
                button.prop('disabled', true).text('Completing...');

                // Build data object
                var ajaxData = {
                    progress_id: progressId,
                    step_name: stepName,
                    volunteer_id: <?php echo $volunteer->id; ?>
                };

                // Add token if using token auth
                if (token) {
                    ajaxData.token = token;
                }

                console.log('FriendShyft: Sending request to REST API with data:', ajaxData);

                $.ajax({
                    url: '<?php echo esc_url(rest_url('friendshyft/v1/complete-step')); ?>',
                    type: 'POST',
                    data: JSON.stringify(ajaxData),
                    contentType: 'application/json',
                    dataType: 'json',
                    success: function(response) {
                        console.log('FriendShyft: AJAX success:', response);
                        if (response.success) {
                            // Update the step UI
                            stepContainer.removeClass('step-incomplete').addClass('step-complete');
                            stepContainer.find('.step-indicator').html('<span class="step-checkmark">✓</span>');
                            stepContainer.find('.step-indicator').css({
                                'background': '#28a745',
                                'color': 'white'
                            });
                            stepContainer.css({
                                'background': '#d4edda',
                                'border-left': '4px solid #28a745',
                                'border': 'none'
                            });
                            button.remove();

                            // Add completion date
                            const today = new Date();
                            const dateStr = today.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                            stepContainer.find('.step-meta').append('<span class="step-completed-date">Completed ' + dateStr + '</span>');

                            showNotice('success', 'Step completed! Great work.');

                            // Reload page after a short delay to update progress bar
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            alert('Error: ' + (response.data || 'Unable to complete step'));
                            button.prop('disabled', false).text('Mark as Complete');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('FriendShyft: AJAX error:', {xhr: xhr, status: status, error: error});
                        console.error('FriendShyft: Response text:', xhr.responseText);
                        alert('Failed to complete step. Please try again. (Status: ' + xhr.status + ')');
                        button.prop('disabled', false).text('Mark as Complete');
                    }
                });
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

    /**
 * Profile view
 */
private static function profile_view($volunteer, $portal_url) {
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-volunteer-profile.php';
    return FS_Volunteer_Profile::render($volunteer, $portal_url);
}

/**
 * Schedule view - Calendar of volunteer's commitments
 */
private static function schedule_view($volunteer, $portal_url) {
    global $wpdb;

    // Get volunteer's upcoming signups for next 3 months
    $three_months_out = date('Y-m-d', strtotime('+3 months'));

    // Get individual signups
    $individual_signups = $wpdb->get_results($wpdb->prepare(
    "SELECT s.*, o.title, o.event_date, o.location, o.template_id,
            t.title as template_title, t.template_type,
            sh.shift_start_time, sh.shift_end_time,
            'individual' as signup_type
     FROM {$wpdb->prefix}fs_signups s
     JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
     LEFT JOIN {$wpdb->prefix}fs_opportunity_templates t ON o.template_id = t.id
     LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
     WHERE s.volunteer_id = %d
     AND s.status = 'confirmed'
     AND o.event_date >= CURDATE()
     AND o.event_date <= %s
     ORDER BY o.event_date, sh.shift_start_time",
    $volunteer->id,
    $three_months_out
));

    // Get team signups where volunteer is a member OR team leader
    $team_signups = $wpdb->get_results($wpdb->prepare(
        "SELECT ts.id as signup_id, ts.opportunity_id, ts.shift_id, ts.status,
                o.title, o.event_date, o.location, o.template_id,
                t.title as template_title, t.template_type,
                sh.shift_start_time, sh.shift_end_time,
                tm.name as team_name, ts.scheduled_size,
                'team' as signup_type
         FROM {$wpdb->prefix}fs_team_signups ts
         JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
         JOIN {$wpdb->prefix}fs_teams tm ON ts.team_id = tm.id
         LEFT JOIN {$wpdb->prefix}fs_opportunity_templates t ON o.template_id = t.id
         LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON ts.shift_id = sh.id
         WHERE ts.status != 'cancelled'
         AND o.event_date >= CURDATE()
         AND o.event_date <= %s
         AND (tm.team_leader_volunteer_id = %d
              OR EXISTS (
                  SELECT 1 FROM {$wpdb->prefix}fs_team_members tmem
                  WHERE tmem.team_id = tm.id AND tmem.volunteer_id = %d
              ))
         ORDER BY o.event_date, sh.shift_start_time",
        $three_months_out,
        $volunteer->id,
        $volunteer->id
    ));

    // Merge both types of signups
    $upcoming_signups = array_merge($individual_signups, $team_signups);

    // Sort combined array by date and time
    usort($upcoming_signups, function($a, $b) {
        $date_cmp = strcmp($a->event_date, $b->event_date);
        if ($date_cmp !== 0) return $date_cmp;
        return strcmp($a->shift_start_time ?? '', $b->shift_start_time ?? '');
    });

    // Group signups by month
    $signups_by_month = array();
    foreach ($upcoming_signups as $signup) {
        $month_key = date('Y-m', strtotime($signup->event_date));
        if (!isset($signups_by_month[$month_key])) {
            $signups_by_month[$month_key] = array();
        }
        $signups_by_month[$month_key][] = $signup;
    }
    
    // Get total hours committed
    $total_hours = 0;
    foreach ($upcoming_signups as $signup) {
        if ($signup->shift_start_time && $signup->shift_end_time) {
            $start = strtotime($signup->event_date . ' ' . $signup->shift_start_time);
            $end = strtotime($signup->event_date . ' ' . $signup->shift_end_time);
            $total_hours += ($end - $start) / 3600;
        } else {
            // Default to 3 hours for flexible weeks without specific times
            $total_hours += 3;
        }
    }
    
    ob_start();
    ?>
    <div class="friendshyft-portal schedule-view">
        <div class="portal-header">
            <div class="header-content">
                <h1>📅 My Schedule</h1>
                <div class="header-actions">
                    <a href="<?php echo esc_url($portal_url); ?>" class="btn-secondary">← Back to Dashboard</a>
                </div>
            </div>
        </div>
        
        <div class="portal-content">
            
            <div class="schedule-summary">
                <div class="summary-card">
                    <div class="summary-icon">📋</div>
                    <div class="summary-details">
                        <div class="summary-value"><?php echo count($upcoming_signups); ?></div>
                        <div class="summary-label">Upcoming Commitments</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">⏰</div>
                    <div class="summary-details">
                        <div class="summary-value"><?php echo number_format($total_hours, 1); ?></div>
                        <div class="summary-label">Hours Scheduled</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">📆</div>
                    <div class="summary-details">
                        <div class="summary-value"><?php echo count($signups_by_month); ?></div>
                        <div class="summary-label">Active Months</div>
                    </div>
                </div>
            </div>
            
            <?php if (empty($upcoming_signups)): ?>
                <div class="no-schedule">
                    <div class="no-schedule-icon">📅</div>
                    <h3>No Upcoming Commitments</h3>
                    <p>You don't have any volunteer opportunities scheduled yet.</p>
                    <a href="<?php echo esc_url(add_query_arg('view', 'browse', $portal_url)); ?>" class="btn-primary">
                        Browse Available Opportunities
                    </a>
                </div>
            <?php else: ?>
                
                <?php foreach ($signups_by_month as $month_key => $month_signups): ?>
                    <div class="month-section">
                        <h2 class="month-header">
                            <?php echo date('F Y', strtotime($month_key . '-01')); ?>
                            <span class="month-count"><?php echo count($month_signups); ?> commitment<?php echo count($month_signups) != 1 ? 's' : ''; ?></span>
                        </h2>
                        
                        <div class="schedule-grid">
                            <?php foreach ($month_signups as $signup): ?>
                                <?php
                                $is_flexible = ($signup->template_type === 'flexible_selection');
                                $event_datetime = strtotime($signup->event_date . ' ' . ($signup->shift_start_time ?: '00:00:00'));
                                $is_soon = ($event_datetime - time()) < (7 * 24 * 3600); // Within 7 days
                                $is_today = (date('Y-m-d', $event_datetime) === date('Y-m-d'));
                                ?>
                                
                                <div class="schedule-card <?php echo $is_flexible ? 'flexible-week' : 'single-shift'; ?> <?php echo $is_soon ? 'upcoming-soon' : ''; ?> <?php echo $is_today ? 'today' : ''; ?>">
                                    <?php if ($is_today): ?>
                                        <div class="today-badge">TODAY</div>
                                    <?php elseif ($is_soon): ?>
                                        <div class="soon-badge">SOON</div>
                                    <?php endif; ?>
                                    
                                    <div class="schedule-date-block">
                                        <div class="date-day"><?php echo date('j', strtotime($signup->event_date)); ?></div>
                                        <div class="date-month"><?php echo date('M', strtotime($signup->event_date)); ?></div>
                                        <div class="date-weekday"><?php echo date('D', strtotime($signup->event_date)); ?></div>
                                    </div>
                                    
                                    <div class="schedule-details">
                                        <h3 class="schedule-title">
                                            <?php if ($is_flexible && $signup->template_title): ?>
                                                <?php echo esc_html($signup->template_title); ?>
                                            <?php else: ?>
                                                <?php echo esc_html($signup->title); ?>
                                            <?php endif; ?>
                                            <?php if (isset($signup->signup_type) && $signup->signup_type === 'team'): ?>
                                                <span class="team-badge">👥 Team: <?php echo esc_html($signup->team_name); ?></span>
                                            <?php endif; ?>
                                        </h3>



                                        <?php if ($is_flexible): ?>
                                            <p class="schedule-time">
                                                🔄 <strong>Full Week Commitment</strong>
                                                <?php 
                                                $week_end = date('M j', strtotime($signup->event_date . ' +4 days'));
                                                echo '<br><span class="time-range">Mon-Fri: ' . date('M j', strtotime($signup->event_date)) . ' - ' . $week_end . '</span>';
                                                ?>
                                            </p>
                                        <?php else: ?>
                                            <?php if ($signup->shift_start_time): ?>
                                                <p class="schedule-time">
                                                    🕐 <strong><?php echo date('g:i A', strtotime($signup->shift_start_time)); ?>
                                                    <?php if ($signup->shift_end_time): ?>
                                                        - <?php echo date('g:i A', strtotime($signup->shift_end_time)); ?>
                                                        <?php
                                                        $start = strtotime($signup->shift_start_time);
                                                        $end = strtotime($signup->shift_end_time);
                                                        $hours = ($end - $start) / 3600;
                                                        echo '<span class="duration">(' . number_format($hours, 1) . ' hrs)</span>';
                                                        ?>
                                                    <?php endif; ?>
                                                    </strong>
                                                </p>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($signup->location): ?>
                                            <p class="schedule-location">
                                                📍 <?php echo esc_html($signup->location); ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($signup->attendance_confirmed): ?>
                                            <div class="confirmation-badge confirmed">
                                                ✓ Attendance Confirmed
                                            </div>
                                        <?php elseif ($is_soon): ?>
                                            <div class="confirmation-badge pending">
                                                ⏰ Confirmation Needed
                                            </div>
                                        <?php endif; ?>

                                        <!-- Cancel Signup Button -->
                                        <?php if ($event_datetime > time()): // Only show for future events ?>
                                            <div class="schedule-actions">
                                                <button class="btn-cancel-schedule"
                                                        data-signup-id="<?php echo isset($signup->signup_id) ? $signup->signup_id : $signup->id; ?>"
                                                        data-signup-type="<?php echo isset($signup->signup_type) ? $signup->signup_type : 'individual'; ?>"
                                                        data-title="<?php echo esc_attr($signup->title); ?>">
                                                    ❌ Cancel
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
            <?php endif; ?>
            
        </div>
    </div>
    
    <style>
    .schedule-view .portal-content {
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .schedule-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .summary-card {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .summary-icon {
        font-size: 40px;
    }
    .summary-value {
        font-size: 32px;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 5px;
    }
    .summary-label {
        font-size: 14px;
        opacity: 0.9;
    }
    
    .no-schedule {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .no-schedule-icon {
        font-size: 80px;
        margin-bottom: 20px;
    }
    .no-schedule h3 {
        margin: 0 0 10px 0;
        color: #333;
    }
    .no-schedule p {
        color: #666;
        margin: 0 0 20px 0;
    }
    
    .month-section {
        background: white;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 30px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .month-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 0 0 20px 0;
        padding-bottom: 15px;
        border-bottom: 3px solid #667eea;
        color: #333;
        font-size: 24px;
    }
    .month-count {
        font-size: 14px;
        font-weight: 600;
        background: #667eea;
        color: white;
        padding: 5px 12px;
        border-radius: 20px;
    }
    
    .schedule-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
    }
    
    .schedule-card {
        display: flex;
        gap: 15px;
        padding: 20px;
        background: #f8f9fa;
        border: 2px solid #dee2e6;
        border-radius: 8px;
        position: relative;
        transition: all 0.2s;
    }
    .schedule-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    .schedule-card.flexible-week {
        border-color: #667eea;
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
    }
    .schedule-card.upcoming-soon {
        border-color: #f39c12;
        background: #fff9e6;
    }
    .schedule-card.today {
        border-color: #28a745;
        background: #d4edda;
        border-width: 3px;
    }
    
    .today-badge,
    .soon-badge {
        position: absolute;
        top: -10px;
        right: 15px;
        padding: 4px 12px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.5px;
    }
    .today-badge {
        background: #28a745;
        color: white;
    }
    .soon-badge {
        background: #f39c12;
        color: white;
    }
    
    .schedule-date-block {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px;
        border-radius: 8px;
        text-align: center;
        min-width: 80px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .date-day {
        font-size: 32px;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 2px;
    }
    .date-month {
        font-size: 14px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 1px;
        margin-bottom: 5px;
    }
    .date-weekday {
        font-size: 12px;
        opacity: 0.9;
        text-transform: uppercase;
    }
    
    .schedule-details {
        flex: 1;
    }
    .schedule-title {
        margin: 0 0 8px 0;
        color: #333;
        font-size: 18px;
        font-weight: 700;
    }
    .team-badge {
        display: inline-block;
        background: #28a745;
        color: white;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 8px;
        vertical-align: middle;
    }
    .schedule-program {
        color: #666;
        margin: 0 0 8px 0;
        font-size: 14px;
        font-weight: 600;
    }
    .schedule-time {
        margin: 8px 0;
        font-size: 14px;
        color: #333;
    }
    .schedule-time strong {
        color: #0073aa;
    }
    .time-range {
        color: #666;
        font-size: 13px;
    }
    .duration {
        color: #666;
        font-size: 12px;
        margin-left: 5px;
    }
    .schedule-location {
        margin: 8px 0 0 0;
        font-size: 14px;
        color: #666;
    }
    
    .confirmation-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        margin-top: 10px;
    }
    .confirmation-badge.confirmed {
        background: #d4edda;
        color: #155724;
    }
    .confirmation-badge.pending {
        background: #fff3cd;
        color: #856404;
    }

    .schedule-actions {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #e0e0e0;
    }
    .btn-cancel-schedule {
        padding: 8px 16px;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-cancel-schedule:hover {
        background: #c82333;
    }
    .btn-cancel-schedule:disabled {
        background: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
    }

    @media (max-width: 768px) {
        .schedule-summary {
            grid-template-columns: 1fr;
        }
        .schedule-grid {
            grid-template-columns: 1fr;
        }
        .schedule-card {
            flex-direction: column;
        }
        .schedule-date-block {
            align-self: flex-start;
        }
        .month-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Handle cancel signup from schedule view
        $('.btn-cancel-schedule').on('click', function() {
            const $btn = $(this);
            const signupId = $btn.data('signup-id');
            const signupType = $btn.data('signup-type');
            const title = $btn.data('title');

            if (!confirm('Are you sure you want to cancel your signup for "' + title + '"? This cannot be undone.')) {
                return;
            }

            $btn.prop('disabled', true).text('Cancelling...');

            // Get token from URL if present
            const urlParams = new URLSearchParams(window.location.search);
            const token = urlParams.get('token');

            var ajaxUrl, ajaxData;
            if (token) {
                ajaxUrl = '<?php echo esc_url(rest_url('friendshyft/v1/cancel-signup')); ?>';
                ajaxData = {
                    signup_id: signupId,
                    token: token
                };
            } else {
                ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
                ajaxData = {
                    action: 'fs_cancel_signup',
                    signup_id: signupId,
                    nonce: '<?php echo wp_create_nonce('friendshyft_portal'); ?>'
                };
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: token ? JSON.stringify(ajaxData) : ajaxData,
                contentType: token ? 'application/json' : 'application/x-www-form-urlencoded; charset=UTF-8',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Remove the card from the view
                        $btn.closest('.schedule-card').fadeOut(400, function() {
                            $(this).remove();

                            // Check if month section is now empty
                            const $monthSection = $('.month-section');
                            $monthSection.each(function() {
                                if ($(this).find('.schedule-card').length === 0) {
                                    $(this).remove();
                                }
                            });

                            // Show empty state if no signups left
                            if ($('.schedule-card').length === 0) {
                                location.reload();
                            }
                        });
                        alert('Your signup has been cancelled successfully.');
                    } else {
                        alert('Error: ' + (response.data ? response.data.message : 'Unable to cancel signup'));
                        $btn.prop('disabled', false).text('❌ Cancel');
                    }
                },
                error: function(xhr, status, error) {
                    alert('Something went wrong. Please try again.');
                    $btn.prop('disabled', false).text('❌ Cancel');
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * History view
 */
private static function history_view($volunteer, $portal_url) {
    global $wpdb;

    // Get past signups (completed or cancelled, before today)
    $past_signups = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, o.title, o.location, o.event_date, o.description,
                os.shift_start_time, os.shift_end_time
         FROM {$wpdb->prefix}fs_signups s
         JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
         LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts os ON s.shift_id = os.id
         WHERE s.volunteer_id = %d
         AND o.event_date < CURDATE()
         ORDER BY o.event_date DESC, os.shift_start_time DESC
         LIMIT 50",
        $volunteer->id
    ));

    // Get time records
    $time_records = $wpdb->get_results($wpdb->prepare(
        "SELECT tr.*, o.title, o.event_date, o.location
         FROM {$wpdb->prefix}fs_time_records tr
         JOIN {$wpdb->prefix}fs_opportunities o ON tr.opportunity_id = o.id
         WHERE tr.volunteer_id = %d
         ORDER BY tr.check_in DESC
         LIMIT 50",
        $volunteer->id
    ));

    // Calculate total hours
    $total_hours = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(total_hours) FROM {$wpdb->prefix}fs_time_records WHERE volunteer_id = %d",
        $volunteer->id
    ));

    // Get earned badges
    $badges = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fs_volunteer_badges
         WHERE volunteer_id = %d
         ORDER BY earned_date DESC",
        $volunteer->id
    ));

    // Get badge definitions
    $badge_definitions = FS_Badges::get_badge_definitions();

    // Get workflow completions
    $workflows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.*, w.name as workflow_name, w.description
         FROM {$wpdb->prefix}fs_progress p
         JOIN {$wpdb->prefix}fs_workflows w ON p.workflow_id = w.id
         WHERE p.volunteer_id = %d
         ORDER BY p.completed DESC, w.name ASC",
        $volunteer->id
    ));

    // Count stats
    $total_signups = count($past_signups);
    $completed_signups = count(array_filter($past_signups, function($s) {
        return $s->status === 'confirmed' || $s->status === 'completed';
    }));
    $cancelled_signups = count(array_filter($past_signups, function($s) {
        return $s->status === 'cancelled';
    }));

    ob_start();
    ?>
    <div class="friendshyft-portal history-view">
        <div class="portal-header">
            <h1>My History</h1>
            <a href="<?php echo esc_url($portal_url); ?>" class="btn-secondary">← Back to Dashboard</a>
        </div>

        <div class="portal-content">
            <!-- Summary Cards -->
            <div class="history-summary">
                <div class="summary-card">
                    <div class="summary-icon">📋</div>
                    <div class="summary-info">
                        <div class="summary-value"><?php echo $total_signups; ?></div>
                        <div class="summary-label">Total Signups</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">✅</div>
                    <div class="summary-info">
                        <div class="summary-value"><?php echo $completed_signups; ?></div>
                        <div class="summary-label">Completed</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">⏰</div>
                    <div class="summary-info">
                        <div class="summary-value"><?php echo number_format($total_hours ?? 0, 1); ?></div>
                        <div class="summary-label">Total Hours</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">🏆</div>
                    <div class="summary-info">
                        <div class="summary-value"><?php echo count($badges); ?></div>
                        <div class="summary-label">Badges Earned</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="history-tabs">
                <button class="history-tab active" data-tab="signups">Past Signups</button>
                <button class="history-tab" data-tab="time">Time Records</button>
                <button class="history-tab" data-tab="badges">Badges</button>
                <button class="history-tab" data-tab="workflows">Training Progress</button>
            </div>

            <!-- Search and Filter Controls -->
            <div class="history-controls">
                <div class="search-box">
                    <input type="text" id="history-search" placeholder="🔍 Search by title or location..." />
                </div>
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="date-from">From:</label>
                        <input type="date" id="date-from" />
                    </div>
                    <div class="filter-group">
                        <label for="date-to">To:</label>
                        <input type="date" id="date-to" />
                    </div>
                    <div class="filter-group">
                        <label for="status-filter">Status:</label>
                        <select id="status-filter">
                            <option value="all">All</option>
                            <option value="confirmed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <button id="reset-history-filters" class="btn-reset">↻ Reset</button>
                </div>
                <div class="results-count">
                    Showing <span id="history-visible-count"><?php echo $total_signups; ?></span> of <span id="history-total-count"><?php echo $total_signups; ?></span> signups
                </div>
            </div>

            <!-- Tab Content: Past Signups -->
            <div class="history-tab-content active" id="tab-signups">
                <?php if (!empty($past_signups)): ?>
                    <div class="history-list">
                        <?php foreach ($past_signups as $signup): ?>
                            <div class="history-item signup-item status-<?php echo esc_attr($signup->status); ?>"
                                 data-title="<?php echo esc_attr(strtolower($signup->title ?? '')); ?>"
                                 data-location="<?php echo esc_attr(strtolower($signup->location ?? '')); ?>"
                                 data-date="<?php echo esc_attr($signup->event_date); ?>"
                                 data-status="<?php echo esc_attr($signup->status); ?>">
                                <div class="history-item-header">
                                    <h3><?php echo esc_html($signup->title); ?></h3>
                                    <span class="status-badge <?php echo esc_attr($signup->status); ?>">
                                        <?php echo ucfirst($signup->status); ?>
                                    </span>
                                </div>
                                <div class="history-item-details">
                                    <div class="detail-row">
                                        <span class="icon">📅</span>
                                        <strong><?php echo date('l, F j, Y', strtotime($signup->event_date)); ?></strong>
                                    </div>
                                    <?php if ($signup->shift_start_time): ?>
                                        <div class="detail-row">
                                            <span class="icon">🕐</span>
                                            <?php echo date('g:i A', strtotime($signup->shift_start_time)); ?>
                                            <?php if ($signup->shift_end_time): ?>
                                                - <?php echo date('g:i A', strtotime($signup->shift_end_time)); ?>
                                                <?php
                                                $start = strtotime($signup->shift_start_time);
                                                $end = strtotime($signup->shift_end_time);
                                                $hours = ($end - $start) / 3600;
                                                echo '<span class="duration">(' . number_format($hours, 1) . ' hrs)</span>';
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($signup->location): ?>
                                        <div class="detail-row">
                                            <span class="icon">📍</span>
                                            <?php echo esc_html($signup->location); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($signup->attendance_confirmed): ?>
                                        <div class="detail-row">
                                            <span class="icon">✓</span>
                                            <span class="confirmed-text">Attendance Confirmed</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <p>No past signups yet. Start volunteering to build your history!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab Content: Time Records -->
            <div class="history-tab-content" id="tab-time">
                <?php if (!empty($time_records)): ?>
                    <div class="history-list">
                        <?php foreach ($time_records as $record): ?>
                            <div class="history-item time-item">
                                <div class="history-item-header">
                                    <h3><?php echo esc_html($record->title); ?></h3>
                                    <span class="hours-badge"><?php echo number_format($record->total_hours ?? 0, 2); ?> hrs</span>
                                </div>
                                <div class="history-item-details">
                                    <div class="detail-row">
                                        <span class="icon">📅</span>
                                        <strong><?php echo date('l, F j, Y', strtotime($record->event_date)); ?></strong>
                                    </div>
                                    <div class="detail-row">
                                        <span class="icon">⏱️</span>
                                        Check-in: <?php echo date('g:i A', strtotime($record->check_in)); ?>
                                        <?php if ($record->check_out): ?>
                                            | Check-out: <?php echo date('g:i A', strtotime($record->check_out)); ?>
                                        <?php else: ?>
                                            <span class="pending-text">(Still checked in)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($record->location): ?>
                                        <div class="detail-row">
                                            <span class="icon">📍</span>
                                            <?php echo esc_html($record->location); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($record->notes): ?>
                                        <div class="detail-row">
                                            <span class="icon">📝</span>
                                            <?php echo esc_html($record->notes); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">⏱️</div>
                        <p>No time records yet. Check in at your next volunteer opportunity!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab Content: Badges -->
            <div class="history-tab-content" id="tab-badges">
                <?php if (!empty($badges)): ?>
                    <div class="badges-grid">
                        <?php foreach ($badges as $badge): ?>
                            <?php
                            $badge_def = $badge_definitions[$badge->badge_type] ?? null;
                            if ($badge_def):
                                $level_info = $badge_def['levels'][$badge->badge_level] ?? null;
                            ?>
                            <div class="badge-card">
                                <div class="badge-icon"><?php echo $badge_def['icon'] ?? '🏆'; ?></div>
                                <h3><?php echo esc_html($badge_def['name'] ?? ucfirst($badge->badge_type)); ?></h3>
                                <div class="badge-level"><?php echo esc_html($level_info['name'] ?? $badge->badge_level); ?></div>
                                <div class="badge-date">Earned: <?php echo date('M j, Y', strtotime($badge->earned_date)); ?></div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">🏆</div>
                        <p>No badges earned yet. Keep volunteering to unlock achievements!</p>
                        <div class="badge-preview">
                            <h4>Available Badges:</h4>
                            <div class="badges-grid">
                                <?php foreach ($badge_definitions as $type => $def): ?>
                                    <div class="badge-card locked">
                                        <div class="badge-icon"><?php echo $def['icon']; ?></div>
                                        <h3><?php echo esc_html($def['name']); ?></h3>
                                        <div class="badge-level">Locked</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab Content: Workflows -->
            <div class="history-tab-content" id="tab-workflows">
                <?php if (!empty($workflows)): ?>
                    <div class="history-list">
                        <?php foreach ($workflows as $workflow): ?>
                            <?php
                            $step_completions = json_decode($workflow->step_completions ?? '{}', true);
                            $completed_steps = is_array($step_completions) ? count(array_filter($step_completions)) : 0;
                            $total_steps = is_array($step_completions) ? count($step_completions) : 0;
                            $progress = $total_steps > 0 ? ($completed_steps / $total_steps) * 100 : 0;
                            ?>
                            <div class="history-item workflow-item <?php echo $workflow->completed ? 'completed' : ''; ?>">
                                <div class="history-item-header">
                                    <h3><?php echo esc_html($workflow->workflow_name); ?></h3>
                                    <?php if ($workflow->completed): ?>
                                        <span class="status-badge completed">✓ Completed</span>
                                    <?php else: ?>
                                        <span class="progress-badge"><?php echo round($progress); ?>%</span>
                                    <?php endif; ?>
                                </div>
                                <div class="history-item-details">
                                    <?php if ($workflow->description): ?>
                                        <div class="detail-row">
                                            <span class="icon">📝</span>
                                            <?php echo esc_html($workflow->description); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="detail-row">
                                        <span class="icon">📊</span>
                                        <?php echo $completed_steps; ?> of <?php echo $total_steps; ?> steps completed
                                    </div>
                                    <?php if (!$workflow->completed && $total_steps > 0): ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📚</div>
                        <p>No training workflows assigned yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
    .history-view .portal-content {
        max-width: 1200px;
        margin: 0 auto;
    }

    .history-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .summary-card {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .summary-icon {
        font-size: 40px;
    }
    .summary-value {
        font-size: 32px;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 5px;
    }
    .summary-label {
        font-size: 14px;
        opacity: 0.9;
    }

    /* History Controls */
    .history-controls {
        background: white;
        border: 2px solid #667eea;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .history-controls .search-box {
        margin-bottom: 15px;
    }
    .history-controls #history-search {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #ddd;
        border-radius: 6px;
        font-size: 16px;
    }
    .history-controls #history-search:focus {
        outline: none;
        border-color: #667eea;
    }
    .filter-row {
        display: flex;
        gap: 15px;
        align-items: flex-end;
        flex-wrap: wrap;
        margin-bottom: 15px;
    }
    .filter-row .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
        flex: 1;
        min-width: 150px;
    }
    .filter-row label {
        font-weight: 600;
        font-size: 14px;
        color: #333;
    }
    .filter-row input,
    .filter-row select {
        padding: 10px;
        border: 2px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
    }
    .filter-row input:focus,
    .filter-row select:focus {
        outline: none;
        border-color: #667eea;
    }
    .filter-row .btn-reset {
        padding: 10px 20px;
        background: #6c757d;
        color: white;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
    }
    .filter-row .btn-reset:hover {
        background: #5a6268;
    }
    .results-count {
        text-align: center;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        color: #666;
    }
    .results-count span {
        color: #667eea;
        font-weight: 700;
    }

    .history-tabs {
        display: flex;
        gap: 10px;
        border-bottom: 2px solid #e0e0e0;
        margin-bottom: 30px;
        flex-wrap: wrap;
    }
    .history-tab {
        padding: 12px 24px;
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        cursor: pointer;
        font-size: 16px;
        font-weight: 500;
        color: #666;
        transition: all 0.3s ease;
    }
    .history-tab:hover {
        color: #667eea;
    }
    .history-tab.active {
        color: #667eea;
        border-bottom-color: #667eea;
    }

    .history-tab-content {
        display: none;
    }
    .history-tab-content.active {
        display: block;
    }

    .history-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .history-item {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        padding: 20px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .history-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .history-item-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
        gap: 15px;
    }
    .history-item-header h3 {
        margin: 0;
        font-size: 18px;
        color: #333;
        flex: 1;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .status-badge.confirmed,
    .status-badge.completed {
        background: #d4edda;
        color: #155724;
    }
    .status-badge.cancelled {
        background: #f8d7da;
        color: #721c24;
    }
    .status-badge.merged_to_team {
        background: #d1ecf1;
        color: #0c5460;
    }

    .hours-badge {
        padding: 4px 12px;
        background: #667eea;
        color: white;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
    }

    .progress-badge {
        padding: 4px 12px;
        background: #ffc107;
        color: #000;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
    }

    .history-item-details {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .detail-row {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #666;
        font-size: 14px;
    }
    .detail-row .icon {
        font-size: 16px;
    }
    .detail-row .duration {
        color: #999;
        margin-left: 5px;
    }
    .confirmed-text {
        color: #28a745;
        font-weight: 600;
    }
    .pending-text {
        color: #ffc107;
        font-style: italic;
    }

    .badges-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 20px;
    }
    .badge-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        padding: 30px 20px;
        text-align: center;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .badge-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.15);
    }
    .badge-card.locked {
        opacity: 0.5;
        background: #f5f5f5;
    }
    .badge-icon {
        font-size: 60px;
        margin-bottom: 15px;
    }
    .badge-card h3 {
        margin: 0 0 10px 0;
        font-size: 18px;
        color: #333;
    }
    .badge-level {
        font-size: 14px;
        font-weight: 600;
        color: #667eea;
        margin-bottom: 5px;
    }
    .badge-date {
        font-size: 12px;
        color: #999;
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: #e0e0e0;
        border-radius: 4px;
        overflow: hidden;
        margin-top: 8px;
    }
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        transition: width 0.3s ease;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }
    .empty-icon {
        font-size: 80px;
        margin-bottom: 20px;
    }
    .empty-state p {
        font-size: 18px;
        margin: 0;
    }
    .badge-preview {
        margin-top: 40px;
    }
    .badge-preview h4 {
        color: #666;
        margin-bottom: 20px;
    }

    @media (max-width: 768px) {
        .history-summary {
            grid-template-columns: 1fr 1fr;
        }
        .history-tabs {
            overflow-x: auto;
        }
        .history-tab {
            padding: 10px 16px;
            font-size: 14px;
        }
        .badges-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>

    <?php
    // JavaScript functionality is in js/portal-script.js to avoid WordPress wptexturize
    // corrupting && operators in inline scripts
    return ob_get_clean();
}

/**
 * Availability view - Manage blocked times
 */
private static function availability_view($volunteer, $portal_url) {
    global $wpdb;

    // Get blocked times (availability)
    $blocked_times = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fs_blocked_times
         WHERE volunteer_id = %d
         ORDER BY start_time ASC",
        $volunteer->id
    ));

    ob_start();
    ?>
    <div class="friendshyft-portal availability-view">
        <div class="portal-header">
            <h1>My Availability</h1>
            <a href="<?php echo esc_url($portal_url); ?>" class="btn-secondary">← Back to Dashboard</a>
        </div>

        <div class="portal-content">
            <div class="availability-intro">
                <p><strong>Block out times when you are NOT available to volunteer.</strong> This helps us avoid scheduling you during those periods.</p>
                <p>You can add blocked times manually below, or sync your Google Calendar to automatically block busy times.</p>
            </div>

            <!-- Add Blocked Time Form -->
            <div class="blocked-time-form-section">
                <h2>Add Blocked Time</h2>
                <form id="portal-add-blocked-time-form" class="blocked-time-form">
                    <div class="form-row">
                        <div class="form-field">
                            <label for="portal_blocked_start_time"><strong>Start Date & Time *</strong></label>
                            <input type="datetime-local" id="portal_blocked_start_time" required>
                        </div>
                        <div class="form-field">
                            <label for="portal_blocked_end_time"><strong>End Date & Time *</strong></label>
                            <input type="datetime-local" id="portal_blocked_end_time" required>
                        </div>
                    </div>
                    <div class="form-field">
                        <label for="portal_blocked_title"><strong>Title (optional)</strong></label>
                        <input type="text" id="portal_blocked_title" placeholder="e.g., Vacation, Medical Appointment, etc.">
                    </div>
                    <button type="submit" class="btn-primary">Add Blocked Time</button>
                </form>
            </div>

            <!-- Blocked Times List -->
            <div class="blocked-times-list-section">
                <h2>My Blocked Times</h2>
                <?php if (!empty($blocked_times)): ?>
                    <div class="blocked-times-grid" id="portal-blocked-times-container">
                        <?php foreach ($blocked_times as $bt): ?>
                            <div class="blocked-time-card" data-id="<?php echo $bt->id; ?>">
                                <div class="blocked-time-header">
                                    <h3><?php echo $bt->title ? esc_html($bt->title) : 'Blocked Time'; ?></h3>
                                    <span class="source-badge <?php echo esc_attr($bt->source); ?>">
                                        <?php echo $bt->source === 'google_calendar' ? '📅 Calendar' : '✏️ Manual'; ?>
                                    </span>
                                </div>
                                <div class="blocked-time-details">
                                    <div class="detail-row">
                                        <span class="icon">🗓️</span>
                                        <div>
                                            <strong>Start:</strong><br>
                                            <?php echo date('l, F j, Y', strtotime($bt->start_time)); ?><br>
                                            <?php echo date('g:i A', strtotime($bt->start_time)); ?>
                                        </div>
                                    </div>
                                    <div class="detail-row">
                                        <span class="icon">🗓️</span>
                                        <div>
                                            <strong>End:</strong><br>
                                            <?php echo date('l, F j, Y', strtotime($bt->end_time)); ?><br>
                                            <?php echo date('g:i A', strtotime($bt->end_time)); ?>
                                        </div>
                                    </div>
                                    <?php if ($bt->source !== 'google_calendar'): ?>
                                        <div class="detail-actions">
                                            <button class="btn-delete-blocked-time" data-id="<?php echo $bt->id; ?>">
                                                🗑️ Delete
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="google-sync-note">
                                            <em>📅 Synced from Google Calendar (manage in Google Calendar)</em>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" id="portal-blocked-times-empty">
                        <div class="empty-icon">📅</div>
                        <p>No blocked times set. Add times when you are NOT available to volunteer.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
    .availability-view .portal-content {
        max-width: 900px;
        margin: 0 auto;
    }

    .availability-intro {
        background: #f0f7ff;
        border-left: 4px solid #667eea;
        padding: 20px;
        margin-bottom: 30px;
        border-radius: 4px;
    }
    .availability-intro p {
        margin: 0 0 10px 0;
        color: #333;
    }
    .availability-intro p:last-child {
        margin-bottom: 0;
    }

    .blocked-time-form-section {
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 40px;
    }
    .blocked-time-form-section h2 {
        margin-top: 0;
        color: #333;
    }

    .blocked-time-form .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    .blocked-time-form .form-field {
        margin-bottom: 15px;
    }
    .blocked-time-form .form-field label {
        display: block;
        margin-bottom: 8px;
        color: #333;
    }
    .blocked-time-form input[type="text"],
    .blocked-time-form input[type="datetime-local"] {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    .blocked-time-form input:focus {
        border-color: #667eea;
        outline: none;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 6px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }

    .blocked-times-list-section {
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .blocked-times-list-section h2 {
        margin-top: 0;
        color: #333;
    }

    .blocked-times-grid {
        display: grid;
        gap: 20px;
    }

    .blocked-time-card {
        background: #fafafa;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 20px;
        transition: box-shadow 0.2s ease;
    }
    .blocked-time-card:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .blocked-time-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e0e0e0;
    }
    .blocked-time-header h3 {
        margin: 0;
        font-size: 18px;
        color: #333;
    }

    .source-badge {
        font-size: 12px;
        padding: 4px 12px;
        border-radius: 12px;
        background: #e0e0e0;
        color: #666;
        white-space: nowrap;
    }
    .source-badge.google_calendar {
        background: #e3f2fd;
        color: #1976d2;
    }
    .source-badge.manual {
        background: #fff3e0;
        color: #f57c00;
    }

    .blocked-time-details {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .detail-row {
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .detail-row .icon {
        font-size: 20px;
        margin-top: 2px;
    }
    .detail-row div {
        color: #555;
        line-height: 1.5;
    }

    .detail-actions {
        margin-top: 10px;
        padding-top: 15px;
        border-top: 1px solid #e0e0e0;
    }
    .btn-delete-blocked-time {
        background: #dc3545;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
        transition: background 0.2s ease;
    }
    .btn-delete-blocked-time:hover {
        background: #c82333;
    }

    .google-sync-note {
        margin-top: 10px;
        padding-top: 15px;
        border-top: 1px solid #e0e0e0;
        color: #666;
        font-size: 13px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }
    .empty-icon {
        font-size: 80px;
        margin-bottom: 20px;
    }
    .empty-state p {
        font-size: 18px;
        margin: 0;
    }

    @media (max-width: 768px) {
        .blocked-time-form .form-row {
            grid-template-columns: 1fr;
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add Blocked Time Form Handler
        const addBlockedTimeForm = document.getElementById('portal-add-blocked-time-form');
        if (addBlockedTimeForm) {
            addBlockedTimeForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const startTime = document.getElementById('portal_blocked_start_time').value;
                const endTime = document.getElementById('portal_blocked_end_time').value;
                const title = document.getElementById('portal_blocked_title').value;

                if (!startTime || !endTime) {
                    alert('Please enter both start and end times.');
                    return;
                }

                if (new Date(startTime) >= new Date(endTime)) {
                    alert('End time must be after start time.');
                    return;
                }

                // Get token from URL if present
                const urlParams = new URLSearchParams(window.location.search);
                const token = urlParams.get('token');

                const data = {
                    action: 'fs_portal_add_blocked_time',
                    start_time: startTime,
                    end_time: endTime,
                    title: title
                };

                if (token) {
                    data.token = token;
                }

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(data)
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Blocked time added successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (result.data.message || 'Failed to add blocked time'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            });
        }

        // Delete Blocked Time Handler
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-delete-blocked-time')) {
                const blockedTimeId = e.target.getAttribute('data-id');

                if (!confirm('Are you sure you want to delete this blocked time?')) {
                    return;
                }

                const urlParams = new URLSearchParams(window.location.search);
                const token = urlParams.get('token');

                const data = {
                    action: 'fs_portal_delete_blocked_time',
                    blocked_time_id: blockedTimeId
                };

                if (token) {
                    data.token = token;
                } else {
                    // For logged-in users, include nonce
                    data.nonce = '<?php echo wp_create_nonce('friendshyft_portal'); ?>';
                }

                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(data)
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Blocked time deleted successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + (result.data.message || 'Failed to delete blocked time'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

    public static function opportunities_shortcode() {
    global $wpdb;

    // Check for token auth FIRST
    $volunteer = self::check_token_auth();

    // Fall back to login check
    if (!$volunteer) {
        if (!is_user_logged_in()) {
            return '<p>Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view opportunities.</p>';
        }

        $volunteer = self::get_current_volunteer();

        if (!$volunteer) {
            return '<p>Your account is not yet linked. Please contact an administrator.</p>';
        }
    }

    // Get the token for passing back to dashboard
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

    // Build dashboard URL with token if present
    $dash_url = home_url('/volunteer-portal/');
    if ($token) {
        $dash_url .= '?token=' . $token;
    }

    // Get volunteer's teams
    $volunteer_teams = FS_Team_Manager::get_volunteer_teams($volunteer->id);
    
    // Get volunteer's role IDs
    $volunteer_role_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT role_id FROM {$wpdb->prefix}fs_volunteer_roles WHERE volunteer_id = %d",
        $volunteer->id
    ));

    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("FriendShyft Opportunity Filter DEBUG:");
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Volunteer ID: " . $volunteer->id);
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Volunteer Role IDs: " . print_r($volunteer_role_ids, true));
    }

    // Build the SQL to filter by roles
    // Show opportunities where:
    // 1. required_roles is NULL or empty (open to all), OR
    // 2. Volunteer has at least one of the required roles

    if (empty($volunteer_role_ids)) {
        // Volunteer has no roles, so only show opportunities with no role requirements
        $role_filter = "AND (o.required_roles IS NULL OR o.required_roles = '' OR o.required_roles = '[]')";
    } else {
        // Build a condition to check if volunteer has any required role
        $role_ids_string = implode(',', array_map('intval', $volunteer_role_ids));

        // Build LIKE conditions that match both string and integer formats in JSON
        // For role_id 5, this matches: "5" OR ,5, OR ,5] OR [5, OR [5]
        $like_conditions = array();
        foreach ($volunteer_role_ids as $role_id) {
            $role_id = intval($role_id);
            $like_conditions[] = "opp.required_roles LIKE '%\"" . $role_id . "\"%'";  // String format: "5"
            $like_conditions[] = "opp.required_roles LIKE '%," . $role_id . ",%'";     // Middle: ,5,
            $like_conditions[] = "opp.required_roles LIKE '%," . $role_id . "]%'";     // End: ,5]
            $like_conditions[] = "opp.required_roles LIKE '%[" . $role_id . ",%'";     // Start: [5,
            $like_conditions[] = "opp.required_roles LIKE '%[" . $role_id . "]%'";     // Only: [5]
        }

        $role_filter = "AND (
            o.required_roles IS NULL
            OR o.required_roles = ''
            OR o.required_roles = '[]'
            OR o.id IN (
                SELECT opp.id
                FROM {$wpdb->prefix}fs_opportunities opp
                WHERE opp.required_roles IS NOT NULL
                AND opp.required_roles != ''
                AND opp.required_roles != '[]'
                AND (
                    " . implode(' OR ', $like_conditions) . "
                )
            )
        )";
    }

    // Get upcoming opportunities with role filtering applied
    // Include opportunities that are either:
    // 1. Regular individual opportunities (not team-only)
    // 2. Team opportunities (if volunteer has teams)
    $team_filter = '';
    if (!empty($volunteer_teams)) {
        // Show all opportunities (individual and team)
        $team_filter = '';
    } else {
        // Only show individual opportunities (exclude team-only ones or include if allow_team_signups = 0)
        $team_filter = 'AND (o.allow_team_signups = 0 OR o.allow_team_signups IS NULL)';
    }

    $full_query = "SELECT o.*,
            o.allow_team_signups,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE opportunity_id = o.id AND volunteer_id = {$volunteer->id} AND status = 'confirmed') as is_signed_up,
            (SELECT COUNT(*) FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             WHERE ts.opportunity_id = o.id
             AND ts.status != 'cancelled'
             AND (t.team_leader_volunteer_id = {$volunteer->id}
                  OR EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}fs_team_members tm
                      WHERE tm.team_id = t.id AND tm.volunteer_id = {$volunteer->id}
                  ))
            ) as is_signed_up_via_team
     FROM {$wpdb->prefix}fs_opportunities o
     WHERE o.event_date >= CURDATE()
     AND o.status = 'Open'
     {$role_filter}
     {$team_filter}
     ORDER BY o.event_date ASC, o.title ASC
     LIMIT 50";

    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Full SQL Query: " . $full_query);
    }

    $opportunities = $wpdb->get_results($full_query);

    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Opportunities found: " . count($opportunities));
    }
    if (!empty($opportunities)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("First 3 opportunities:");
        }
        foreach (array_slice($opportunities, 0, 3) as $opp) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("  - " . $opp->title . " (ID: " . $opp->id . ", required_roles: " . $opp->required_roles . ")");
            }
        }
    }
    
    // Group opportunities by date and title
    $grouped = array();
    $grouping_iteration = 0;
    foreach ($opportunities as $opp) {
        $grouping_iteration++;
        $key = $opp->event_date . '|' . $opp->title;
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("GROUPING DEBUG #" . $grouping_iteration . " - Opp ID: " . $opp->id . ", event_date: " . $opp->event_date . ", title: " . $opp->title . ", key: '" . $key . "'");
        }
        if (!isset($grouped[$key])) {
            $grouped[$key] = array(
                'opportunity' => $opp,
                'shifts' => array()
            );
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("  -> KEY ALREADY EXISTS! Skipping duplicate.");
            }
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Total opportunities from query: " . count($opportunities));
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Grouped opportunities count: " . count($grouped));
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("Grouped keys: " . print_r(array_keys($grouped), true));
    }
    
    // Get shifts for each opportunity
    foreach ($grouped as $key => &$group) {
        $opp = $group['opportunity'];
        $group['shifts'] = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
                     WHERE shift_id = s.id AND volunteer_id = %d AND status = 'confirmed') as user_signed_up
             FROM {$wpdb->prefix}fs_opportunity_shifts s
             WHERE s.opportunity_id = %d
             ORDER BY s.display_order ASC",
            $volunteer->id,
            $opp->id
        ));
    }
    unset($group); // Break reference to prevent corruption

    ob_start();
    ?>
    <div class="fs-opportunities-page">
        <div class="page-header">
            <h1>Available Volunteer Opportunities</h1>
            <a href="<?php echo esc_url($dash_url); ?>" class="button-secondary">← Back to Dashboard</a>
        </div>

        <?php if (isset($_GET['success']) && $_GET['success']): ?>
            <div class="portal-message success-message">
                ✓ <?php echo isset($_GET['message']) ? esc_html(urldecode($_GET['message'])) : 'Successfully signed up!'; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="portal-message error-message">
                ✗ <?php echo esc_html(urldecode($_GET['error'])); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($grouped)): ?>
            <!-- Search, Filter, and Sort Controls -->
            <div class="opportunities-controls">
                <div class="search-box">
                    <input type="text" id="opportunity-search" placeholder="🔍 Search opportunities by title, description, or location..." />
                </div>
                <div class="controls-row">
                    <div class="filter-group">
                        <label for="date-filter">📅 Date:</label>
                        <select id="date-filter">
                            <option value="all">All Dates</option>
                            <option value="this-week">This Week</option>
                            <option value="next-week">Next Week</option>
                            <option value="this-month">This Month</option>
                            <option value="next-month">Next Month</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="availability-filter">📊 Availability:</label>
                        <select id="availability-filter">
                            <option value="all">All Opportunities</option>
                            <option value="available">Has Open Spots</option>
                            <option value="full">Full (Waitlist)</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="sort-by">⬇️ Sort By:</label>
                        <select id="sort-by">
                            <option value="date-asc">Date (Earliest First)</option>
                            <option value="date-desc">Date (Latest First)</option>
                            <option value="title-asc">Title (A-Z)</option>
                            <option value="title-desc">Title (Z-A)</option>
                            <option value="spots">Most Spots Available</option>
                        </select>
                    </div>
                    <button id="reset-filters" class="button-reset">↻ Reset</button>
                </div>
                <div class="results-summary">
                    Showing <span id="visible-count"><?php echo count($grouped); ?></span> of <span id="total-count"><?php echo count($grouped); ?></span> opportunities
                </div>
            </div>
            <div class="opportunities-grid">
                <?php
                $render_count = 0;
                foreach ($grouped as $grouping_key => $group):
                    $render_count++;
                    $opp = $group['opportunity'];
                    $shifts = $group['shifts'];
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("RENDER DEBUG: Card #" . $render_count . " - Grouping Key: '" . $grouping_key . "', Opp ID: " . $opp->id . ", Date: " . $opp->event_date . ", Title: " . $opp->title);
                    }
                    $eligibility = FS_Eligibility_Checker::check($volunteer, $opp);
                    $is_eligible = $eligibility['eligible'];
                    $reason = $eligibility['reason'];

                    // Calculate total spots and filled for filtering
                    $total_spots_available = 0;
                    $total_spots_filled = 0;
                    foreach ($shifts as $shift) {
                        $total_spots_available += $shift->spots_available;
                        $total_spots_filled += $shift->spots_filled;
                    }
                    $spots_remaining = $total_spots_available - $total_spots_filled;
                    $is_full = $spots_remaining <= 0;
                ?>
                    <div class="opportunity-card"
                         data-title="<?php echo esc_attr(strtolower($opp->title ?? '')); ?>"
                         data-description="<?php echo esc_attr(strtolower(strip_tags($opp->description ?? ''))); ?>"
                         data-location="<?php echo esc_attr(strtolower($opp->location ?? '')); ?>"
                         data-date="<?php echo esc_attr($opp->event_date); ?>"
                         data-is-full="<?php echo $is_full ? 'true' : 'false'; ?>"
                         data-spots-remaining="<?php echo $spots_remaining; ?>">
                        <div class="opp-header">
                            <h3><?php echo esc_html($opp->title); ?></h3>
                            <div class="opp-date">
                                📅 <?php echo date('l, F j, Y', strtotime($opp->event_date)); ?>
                            </div>
                            <?php if ($opp->allow_team_signups && !empty($volunteer_teams)): ?>
                                <span class="team-badge">👥 Team Opportunity</span>
                            <?php endif; ?>
                        </div>

                        <div class="opp-body">
                            <?php if ($opp->description): ?>
                                <div class="detail-item">
                                    <?php echo wpautop(wp_strip_all_tags($opp->description)); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($opp->location): ?>
                                <div class="detail-item">
                                    <strong>📍 Location:</strong> <?php echo esc_html($opp->location); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($opp->requirements): ?>
                                <div class="detail-item">
                                    <strong>Requirements:</strong> <?php echo wpautop(wp_strip_all_tags($opp->requirements)); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($opp->allow_team_signups && !empty($volunteer_teams)): ?>
                                <div class="signup-type-selector">
                                    <strong>How would you like to sign up?</strong>
                                    <div class="signup-options">
                                        <label class="signup-option-radio">
                                            <input type="radio" name="signup_type_<?php echo $opp->id; ?>" value="individual" checked>
                                            <span>🧑 Sign up as Individual</span>
                                        </label>
                                        <label class="signup-option-radio">
                                            <input type="radio" name="signup_type_<?php echo $opp->id; ?>" value="team">
                                            <span>👥 Sign up as Team</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="team-selector" id="team_selector_<?php echo $opp->id; ?>" style="display: none;">
                                    <strong>Select Team:</strong>
                                    <select class="team-select" id="team_select_<?php echo $opp->id; ?>">
                                        <option value="">-- Select a team --</option>
                                        <?php foreach ($volunteer_teams as $team): ?>
                                            <option value="<?php echo $team->id; ?>" data-size="<?php echo $team->default_size; ?>">
                                                <?php echo esc_html($team->name); ?>
                                                (<?php echo $team->default_size; ?> people)
                                                <?php if ($team->volunteer_role === 'leader'): ?>
                                                    - Leader
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="shifts-section">
                                <strong>Available Shifts:</strong>
                                <div class="shifts-list">
                                    <?php foreach ($shifts as $shift): ?>
                                        <?php
                                        $shift_full = $shift->spots_filled >= $shift->spots_available;
                                        $user_has_shift = $shift->user_signed_up > 0;
                                        $user_has_via_team = $opp->is_signed_up_via_team > 0;
                                        ?>
                                        <div class="shift-option <?php echo ($user_has_shift || $user_has_via_team) ? 'user-signed-up' : ''; ?>">
                                            <div class="shift-time">
                                                🕐 <?php echo date('g:i A', strtotime($shift->shift_start_time)); ?> -
                                                <?php echo date('g:i A', strtotime($shift->shift_end_time)); ?>
                                            </div>
                                            <div class="shift-capacity">
                                                <?php echo $shift->spots_filled; ?> / <?php echo $shift->spots_available; ?> filled
                                                <?php if ($shift_full): ?>
                                                    <span class="full-badge">FULL</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="shift-action">
                                                <?php if ($user_has_shift): ?>
                                                    <span class="signed-up-badge">✓ You're signed up!</span>
                                                <?php elseif ($user_has_via_team): ?>
                                                    <span class="signed-up-badge">👥 Signed up via team</span>
                                                <?php elseif (!$is_eligible): ?>
                                                    <button class="button-signup" disabled>
                                                        🔒 Not Eligible
                                                    </button>
                                                <?php elseif ($shift_full): ?>
                                                    <button class="button-signup" disabled>Full</button>
                                                <?php else: ?>
                                                    <button class="button-signup"
                                                            data-opp-id="<?php echo $opp->id; ?>"
                                                            data-shift-id="<?php echo $shift->id; ?>"
                                                            onclick="signupForShift(<?php echo $opp->id; ?>, <?php echo $shift->id; ?>, '<?php echo esc_js($opp->title); ?>', '<?php echo date('g:i A', strtotime($shift->shift_start_time)); ?>')">
                                                        Sign Up
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <?php if (!$is_eligible): ?>
                                <div class="ineligible-message">
                                    🔒 <?php echo esc_html($reason); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>No opportunities are currently available for your assigned roles. Please contact an administrator if you'd like to volunteer in additional areas.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <style>
        .fs-opportunities-page { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #0073aa;
        }
        .page-header h1 { margin: 0; }
        .portal-message {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 15px;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #dc3545;
        }
        .button-secondary {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
        }
        .button-secondary:hover {
            background: #5a6268;
            color: white;
        }

        /* Search, Filter, Sort Controls */
        .opportunities-controls {
            background: white;
            border: 2px solid #0073aa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .search-box {
            margin-bottom: 15px;
        }
        #opportunity-search {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        #opportunity-search:focus {
            outline: none;
            border-color: #0073aa;
        }
        .controls-row {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
            min-width: 180px;
        }
        .filter-group label {
            font-weight: 600;
            font-size: 14px;
            color: #333;
        }
        .filter-group select {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .filter-group select:focus {
            outline: none;
            border-color: #0073aa;
        }
        .button-reset {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            white-space: nowrap;
        }
        .button-reset:hover {
            background: #5a6268;
        }
        .results-summary {
            text-align: center;
            font-size: 15px;
            color: #666;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
            font-weight: 600;
        }
        .results-summary span {
            color: #0073aa;
            font-weight: 700;
        }

        .opportunities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }
        .opportunity-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .opp-header {
            background: #0073aa;
            color: white !important;
            padding: 20px;
        }
        .opp-header h3 {
            margin: 0 0 10px 0;
            font-size: 22px;
            font-weight: 700;
            color: white !important;
        }
        .opp-date {
            font-size: 14px;
            opacity: 0.9;
        }
        .team-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.3);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 8px;
        }
        .opp-body {
            padding: 20px;
        }
        .signup-type-selector {
            margin: 20px 0;
            padding: 15px;
            background: #f0f6fc;
            border-radius: 6px;
            border: 2px solid #0073aa;
        }
        .signup-type-selector > strong {
            display: block;
            margin-bottom: 12px;
            color: #0073aa;
        }
        .signup-options {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .signup-option-radio {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: white;
            border: 2px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .signup-option-radio:hover {
            border-color: #0073aa;
            background: #f8fbff;
        }
        .signup-option-radio input[type="radio"] {
            margin: 0;
            cursor: pointer;
        }
        .signup-option-radio input[type="radio"]:checked + span {
            font-weight: 600;
            color: #0073aa;
        }
        .signup-option-radio span {
            font-size: 14px;
            user-select: none;
        }
        .team-selector {
            margin: 15px 0;
            padding: 15px;
            background: #fff9e6;
            border-radius: 6px;
            border: 2px solid #f39c12;
        }
        .team-selector > strong {
            display: block;
            margin-bottom: 10px;
            color: #856404;
        }
        .team-select {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            background: white;
        }
        .team-select:focus {
            outline: none;
            border-color: #f39c12;
        }
        .detail-item {
            margin-bottom: 15px;
            font-size: 14px;
            line-height: 1.6;
        }
        .shifts-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .shifts-section > strong {
            display: block;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .shifts-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .shift-option {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 10px;
            align-items: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 2px solid transparent;
        }
        .shift-option.user-signed-up {
            background: #d4edda;
            border-color: #28a745;
        }
        .shift-time {
            font-weight: 600;
            font-size: 14px;
        }
        .shift-capacity {
            font-size: 13px;
            color: #666;
        }
        .full-badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        .signed-up-badge {
            color: #28a745;
            font-weight: 600;
            font-size: 14px;
        }
        .shift-action {
            text-align: right;
        }
        .button-signup {
            background: #28a745;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .button-signup:hover:not(:disabled) {
            background: #218838;
        }
        .button-signup:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .ineligible-message {
            margin-top: 15px;
            padding: 12px;
            background: #f8d7da;
            color: #721c24;
            border-radius: 4px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            .opportunities-controls {
                padding: 15px;
            }
            .controls-row {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-group {
                min-width: 100%;
            }
            .button-reset {
                width: 100%;
            }
            .opportunities-grid {
                grid-template-columns: 1fr;
            }
            .shift-option {
                grid-template-columns: 1fr;
                text-align: left;
                gap: 12px;
            }
            .shift-action {
                text-align: left;
            }
            .shift-action .button-signup {
                width: 100%;
            }
            .button-signup {
                min-height: 44px;
                padding: 12px 20px;
                font-size: 16px;
            }
        }
    </style>

    <?php
    // JavaScript functionality is in js/portal-script.js to avoid WordPress wptexturize
    // corrupting && operators in inline scripts
    return ob_get_clean();
}
    
    public static function handle_signup() {
    // Check for token auth first - token can come from POST (AJAX) or GET (URL)
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : 
             (isset($_GET['token']) ? sanitize_text_field($_GET['token']) : null);
    
    $volunteer = null;
    if ($token) {
        global $wpdb;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $token
        ));
    }
    
    // Fall back to login check
    if (!$volunteer) {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in'));
        }
        
        $volunteer = self::get_current_volunteer();
    }
    
    if (!$volunteer) {
        wp_send_json_error(array('message' => 'Volunteer account not found'));
    }
    
    $opportunity_id = intval($_POST['opportunity_id'] ?? 0);
    $shift_id = isset($_POST['shift_id']) ? intval($_POST['shift_id']) : null;
    
    if (!$opportunity_id) {
        wp_send_json_error(array('message' => 'Missing opportunity ID'));
    }
    
    $result = FS_Signup::create($volunteer->id, $opportunity_id, $shift_id);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

public static function handle_cancel() {
    // Check for token auth first
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : 
             (isset($_GET['token']) ? sanitize_text_field($_GET['token']) : null);
    
    $volunteer = null;
    if ($token) {
        global $wpdb;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $token
        ));
    }
    
    // Fall back to login check
    if (!$volunteer) {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Must be logged in'));
        }
        
        $volunteer = self::get_current_volunteer();
    }
    
    if (!$volunteer) {
        wp_send_json_error(array('message' => 'Volunteer profile not found'));
    }
    
    $signup_id = intval($_POST['signup_id'] ?? 0);
    
    $result = FS_Signup::cancel($signup_id, $volunteer->id);
    
    if ($result['success']) {
        wp_send_json_success();
    } else {
        wp_send_json_error($result);
    }
}

public static function handle_complete_step() {
    // Log that we received the request
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: handle_complete_step called');
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: POST data: ' . print_r($_POST, true));
    }

    // Check for token auth first
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) :
             (isset($_GET['token']) ? sanitize_text_field($_GET['token']) : null);

    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: Token: ' . ($token ? 'present' : 'not present'));
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: User logged in: ' . (is_user_logged_in() ? 'yes' : 'no'));
    }

    $volunteer = null;
    if ($token) {
        global $wpdb;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $token
        ));
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: Volunteer found by token: ' . ($volunteer ? 'yes (ID: ' . $volunteer->id . ')' : 'no'));
        }

        // Token auth successful - skip nonce check
        if ($volunteer) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Token auth successful, skipping nonce check');
            }
        }
    }

    // Fall back to login check
    if (!$volunteer) {
        if (!is_user_logged_in()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Not logged in and no valid token');
            }
            wp_send_json_error(array('message' => 'Must be logged in or provide valid token'));
            return;
        }

        // Only verify nonce for logged-in users (not for REST API with token)
        // REST API requests don't have nonces, they use token authentication
        if (isset($_POST['_ajax_nonce'])) {
            check_ajax_referer('fs_complete_step', '_ajax_nonce');
        }

        $volunteer = self::get_current_volunteer();
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: Volunteer found by login: ' . ($volunteer ? 'yes (ID: ' . $volunteer->id . ')' : 'no'));
        }
    }

    if (!$volunteer) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: No volunteer found at all');
        }
        wp_send_json_error(array('message' => 'Volunteer profile not found'));
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: Processing step completion for volunteer ID: ' . $volunteer->id);
    }
    
    $step_name = sanitize_text_field($_POST['step_name'] ?? '');
    $progress_id = intval($_POST['progress_id'] ?? 0);
    
    global $wpdb;
    
    // Verify this progress belongs to this volunteer
    $progress = $wpdb->get_row($wpdb->prepare(
        "SELECT p.*, v.monday_id as volunteer_monday_id, v.name as volunteer_name, v.id as volunteer_id
        FROM {$wpdb->prefix}fs_progress p
        JOIN {$wpdb->prefix}fs_volunteers v ON p.volunteer_id = v.id
        WHERE p.id = %d AND v.id = %d",
        $progress_id,
        $volunteer->id
    ));
    
    if (!$progress) {
        wp_send_json_error(array('message' => 'Progress record not found'));
    }
    
    // Find the step in progress subitems
    $step_completions = json_decode($progress->step_completions, true);
    $step_monday_id = null;
    $step_index = null;
    
    foreach ($step_completions as $index => $completion) {
        if ($completion['name'] === $step_name) {
            $step_monday_id = $completion['monday_id'];
            $step_index = $index;
            break;
        }
    }
    
    if ($step_index === null) {
        wp_send_json_error(array('message' => 'Step not found'));
    }
    
    // Check if already completed
    if ($step_completions[$step_index]['completed']) {
        wp_send_json_error(array('message' => 'Step already completed'));
    }
    
    // Update Monday.com subitem ONLY if configured and Monday ID exists
    if (FS_Monday_API::is_configured() && !empty($step_monday_id)) {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (!empty($board_ids['progress'])) {
            $today = date('Y-m-d');
            
            $column_values = array(
                'boolean_mkxs3zj3' => array('checked' => true),
                'date_mkxsxg0a' => array('date' => $today),
                'text_mkxsqhb1' => $progress->volunteer_name
            );
            
            $column_values_json = json_encode($column_values);
            $column_values_escaped = addslashes($column_values_json);
            
            $query = 'query {
                items(ids: [' . $step_monday_id . ']) {
                    board {
                        id
                    }
                }
            }';
            
            $board_result = $api->query_raw($query);
            $subitem_board_id = $board_result['items'][0]['board']['id'] ?? null;
            
            if ($subitem_board_id) {
                $mutation = 'mutation {
                    change_multiple_column_values(
                        item_id: ' . $step_monday_id . ',
                        board_id: ' . $subitem_board_id . ',
                        column_values: "' . $column_values_escaped . '"
                    ) {
                        id
                    }
                }';
                
                $result = $api->query_raw($mutation);
                
                if (!$result || !isset($result['change_multiple_column_values']['id'])) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('FriendShyft: Failed to update Monday.com step, continuing with local update');
                    }
                }
            }
        }
    }
    
    // ALWAYS update local database
    $step_completions[$step_index]['completed'] = true;
    $step_completions[$step_index]['completed_date'] = date('Y-m-d');
    $step_completions[$step_index]['completed_by'] = $progress->volunteer_name;
    
    $wpdb->update(
        $wpdb->prefix . 'fs_progress',
        array('step_completions' => json_encode(array_values($step_completions))),
        array('id' => $progress_id)
    );
    
    // Get workflow name
    $workflow = $wpdb->get_row($wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}fs_workflows WHERE id = %d",
        $progress->workflow_id
    ));
    
    // Trigger step completion notification
    $volunteer_obj = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
        $progress->volunteer_id
    ));
    
    do_action('fs_step_completed', $volunteer_obj, $workflow->name, $step_name);
    
    wp_send_json_success();
}

    public static function flexible_selection_shortcode() {
    global $wpdb;

    if (!is_user_logged_in()) {
        return '<p>Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view available weeks.</p>';
    }
    
    $volunteer = self::get_current_volunteer();
    
    if (!$volunteer) {
        return '<p>Your account is not yet linked. Please contact an administrator.</p>';
    }
    
    // Get all active flexible selection templates
    $templates = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates 
        WHERE template_type = 'flexible_selection' 
        AND status = 'Active'
        ORDER BY title ASC"
    );
    
    if (empty($templates)) {
        return '<p>No flexible selection opportunities are currently available.</p>';
    }
    
    ob_start();
    ?>
    <div class="fs-flexible-selection">
        <h1>Pick Your Weeks</h1>
        <p class="description">Select the weeks you'd like to volunteer. Each opportunity has different requirements and limits.</p>
        
        <?php foreach ($templates as $template): ?>
            <?php
            $pattern = json_decode($template->recurrence_pattern, true);
            $slots_per_week = $pattern['flexible_slots_per_week'] ?? 1;
            $min_claims = $pattern['flexible_min_claims'] ?? 1;
            $max_claims = $pattern['flexible_max_claims'] ?? 4;
            $period = $pattern['flexible_period'] ?? 'quarterly';
            $week_pattern = $pattern['flexible_week_pattern'] ?? 'monday_friday';
            
            // Get current AND next period dates
            $current_period = self::get_current_period_dates($period);
            $next_period = self::get_next_period_dates($period);
            
            // Get volunteer's CURRENT period commitments (read-only)
            $current_commitments = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, o.event_date, o.title
                 FROM {$wpdb->prefix}fs_signups s
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 WHERE s.volunteer_id = %d
                 AND o.template_id = %d
                 AND o.event_date BETWEEN %s AND %s
                 AND s.status = 'confirmed'
                 ORDER BY o.event_date ASC",
                $volunteer->id,
                $template->id,
                $current_period['start'],
                $current_period['end']
            ));
            
            // Get volunteer's NEXT period selections (editable)
            $my_claims = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, o.event_date, o.title
                 FROM {$wpdb->prefix}fs_signups s
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 WHERE s.volunteer_id = %d
                 AND o.template_id = %d
                 AND o.event_date BETWEEN %s AND %s
                 AND s.status = 'confirmed'
                 ORDER BY o.event_date ASC",
                $volunteer->id,
                $template->id,
                $next_period['start'],
                $next_period['end']
            ));
            
            // Get available weeks for NEXT period only
            $available_weeks = $wpdb->get_results($wpdb->prepare(
                "SELECT o.*, 
                        (SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups s WHERE s.opportunity_id = o.id AND s.status = 'confirmed') as claimed_count
                 FROM {$wpdb->prefix}fs_opportunities o
                 WHERE o.template_id = %d 
                 AND o.event_date BETWEEN %s AND %s
                 AND o.status = 'Open'
                 ORDER BY o.event_date ASC",
                $template->id,
                $next_period['start'],
                $next_period['end']
            ));
            
            $my_claim_count = count($my_claims);
            $my_claimed_ids = array_column($my_claims, 'opportunity_id');
            
            // Calculate progress
            $progress_pct = $max_claims > 0 ? round(($my_claim_count / $max_claims) * 100) : 0;
            $can_claim_more = $my_claim_count < $max_claims;
            $needs_more = $my_claim_count < $min_claims;
            ?>
            
            <div class="flexible-template-section">
                <div class="template-header">
                    <div class="template-info">
                        <h2><?php echo esc_html($template->title); ?></h2>
                        <?php if ($template->description): ?>
                            <p><?php echo nl2br(esc_html($template->description)); ?></p>
                        <?php endif; ?>
                        <?php if ($template->location): ?>
                            <p class="template-location">📍 <?php echo esc_html($template->location); ?></p>
                        <?php endif; ?>
                        <?php if ($template->requirements): ?>
                            <div class="template-requirements"><strong>Requirements:</strong> <?php echo wpautop(wp_kses_post($template->requirements)); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="template-stats">
                        <div class="stat-card">
                            <div class="stat-label">Next <?php echo ucfirst($period); ?> Selections</div>
                            <div class="stat-value"><?php echo $my_claim_count; ?> / <?php echo $max_claims; ?> weeks</div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progress_pct; ?>%;"></div>
                            </div>
                            <?php if ($needs_more): ?>
                                <div class="stat-note warning">⚠️ Need <?php echo $min_claims - $my_claim_count; ?> more week(s)</div>
                            <?php elseif (!$can_claim_more): ?>
                                <div class="stat-note success">✓ Maximum reached</div>
                            <?php else: ?>
                                <div class="stat-note">Can select <?php echo $max_claims - $my_claim_count; ?> more</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Selection Period</div>
                            <div class="stat-value">
                                <?php echo date('M j', strtotime($next_period['start'])); ?> - 
                                <?php echo date('M j, Y', strtotime($next_period['end'])); ?>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Week Format</div>
                            <div class="stat-value">
                                <?php echo $week_pattern === 'monday_friday' ? 'Mon-Fri' : 'Full Week'; ?>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Slots Per Week</div>
                            <div class="stat-value"><?php echo $slots_per_week; ?></div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($current_commitments)): ?>
                <div class="current-commitments-section">
                    <h3>Your Current Commitments (<?php echo date('M j', strtotime($current_period['start'])); ?> - <?php echo date('M j, Y', strtotime($current_period['end'])); ?>)</h3>
                    <div class="current-commitments-list">
                        <?php foreach ($current_commitments as $commitment): ?>
                            <div class="commitment-card">
                                <div class="week-info">
                                    <strong><?php echo date('M j', strtotime($commitment->event_date)); ?> - 
                                    <?php 
                                    $end_date = $week_pattern === 'monday_friday' 
                                        ? date('M j, Y', strtotime($commitment->event_date . ' +4 days'))
                                        : date('M j, Y', strtotime($commitment->event_date . ' +6 days'));
                                    echo $end_date;
                                    ?></strong>
                                </div>
                                <div class="commitment-badge">✓ Active</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($my_claims)): ?>
                <div class="my-claims-section">
                    <h3>Your Selections for Next <?php echo ucfirst($period); ?></h3>
                    <div class="claimed-weeks-list">
                        <?php foreach ($my_claims as $claim): ?>
                            <div class="claimed-week-card">
                                <div class="week-info">
                                    <strong><?php echo date('M j', strtotime($claim->event_date)); ?> - 
                                    <?php 
                                    $end_date = $week_pattern === 'monday_friday' 
                                        ? date('M j, Y', strtotime($claim->event_date . ' +4 days'))
                                        : date('M j, Y', strtotime($claim->event_date . ' +6 days'));
                                    echo $end_date;
                                    ?></strong>
                                </div>
                                <button class="button-unclaim" 
                                        onclick="unclaimWeek(<?php echo $claim->opportunity_id; ?>, <?php echo $claim->id; ?>)">
                                    Release Week
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="available-weeks-section">
                    <h3>Available Weeks for Next <?php echo ucfirst($period); ?></h3>
                    
                    <?php if (empty($available_weeks)): ?>
                        <p>No weeks currently available to claim for the next <?php echo $period; ?>.</p>
                    <?php else: ?>
                        <div class="weeks-grid">
                            <?php foreach ($available_weeks as $week): ?>
                                <?php
                                $is_claimed_by_me = in_array($week->id, $my_claimed_ids);
                                $is_full = $week->claimed_count >= $slots_per_week;
                                $can_claim_this = $can_claim_more && !$is_claimed_by_me && !$is_full;
                                
                                $end_date = $week_pattern === 'monday_friday' 
                                    ? date('M j, Y', strtotime($week->event_date . ' +4 days'))
                                    : date('M j, Y', strtotime($week->event_date . ' +6 days'));
                                ?>
                                
                                <div class="week-card <?php echo $is_claimed_by_me ? 'claimed-by-me' : ''; ?> <?php echo $is_full ? 'full' : ''; ?>">
                                    <div class="week-dates">
                                        <strong><?php echo date('M j', strtotime($week->event_date)); ?> - <?php echo $end_date; ?></strong>
                                    </div>
                                    
                                    <div class="week-availability">
                                        <?php echo ($slots_per_week - $week->claimed_count); ?> of <?php echo $slots_per_week; ?> available
                                    </div>
                                    
                                    <?php if ($is_claimed_by_me): ?>
                                        <div class="week-status claimed">✓ You claimed this week</div>
                                    <?php elseif ($is_full): ?>
                                        <div class="week-status full">Full</div>
                                    <?php elseif (!$can_claim_more): ?>
                                        <div class="week-status limit-reached">Limit Reached</div>
                                    <?php else: ?>
                                        <button class="button-claim" 
                                                onclick="claimWeek(<?php echo $week->id; ?>, <?php echo $template->id; ?>)">
                                            Claim This Week
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <style>
        .fs-flexible-selection {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .fs-flexible-selection h1 {
            margin-bottom: 10px;
        }
        .fs-flexible-selection > .description {
            color: #666;
            margin-bottom: 30px;
        }
        
        .flexible-template-section {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .template-header {
            display: flex;
            gap: 30px;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 2px solid #e5e5e5;
        }
        .template-info {
            flex: 1;
        }
        .template-info h2 {
            margin: 0 0 15px 0;
            color: #0073aa;
        }
        .template-info p {
            margin: 10px 0;
        }
        .template-location {
            color: #666;
            font-size: 14px;
        }
        .template-requirements {
            background: #fff3cd;
            padding: 10px;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .template-stats {
            display: flex;
            gap: 15px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            min-width: 150px;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #0073aa;
            margin-bottom: 10px;
        }
        .progress-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .progress-fill {
            height: 100%;
            background: #28a745;
            transition: width 0.3s;
        }
        .stat-note {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 3px;
            text-align: center;
        }
        .stat-note.warning {
            background: #fff3cd;
            color: #856404;
        }
        .stat-note.success {
            background: #d4edda;
            color: #155724;
        }
        
        .current-commitments-section {
            background: #e9ecef;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 30px;
        }
        .current-commitments-section h3 {
            margin: 0 0 15px 0;
            color: #495057;
        }
        .current-commitments-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .commitment-card {
            background: white;
            padding: 15px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 2px solid #6c757d;
        }
        .commitment-badge {
            color: #495057;
            font-weight: 600;
            font-size: 14px;
        }
        
        .my-claims-section {
            background: #e7f3ff;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 30px;
        }
        .my-claims-section h3 {
            margin: 0 0 15px 0;
            color: #0073aa;
        }
        .claimed-weeks-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .claimed-week-card {
            background: white;
            padding: 15px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 2px solid #0073aa;
        }
        .week-info {
            font-size: 16px;
        }
        .button-unclaim {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }
        .button-unclaim:hover {
            background: #c82333;
        }
        
        .available-weeks-section h3 {
            margin: 0 0 20px 0;
        }
        .weeks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        .week-card {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
        }
        .week-card.claimed-by-me {
            background: #d4edda;
            border-color: #28a745;
        }
        .week-card.full {
            background: #f8d7da;
            border-color: #dc3545;
            opacity: 0.7;
        }
        .week-dates {
            margin-bottom: 10px;
            font-size: 16px;
        }
        .week-availability {
            font-size: 13px;
            color: #666;
            margin-bottom: 15px;
        }
        .week-status {
            padding: 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 13px;
        }
        .week-status.claimed {
            background: #28a745;
            color: white;
        }
        .week-status.full {
            background: #dc3545;
            color: white;
        }
        .week-status.limit-reached {
            background: #ffc107;
            color: #333;
        }
        .button-claim {
            width: 100%;
            background: #0073aa;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .button-claim:hover {
            background: #005177;
        }
        
        @media (max-width: 768px) {
            .template-header {
                flex-direction: column;
            }
            .template-stats {
                flex-direction: column;
                width: 100%;
            }
            .stat-card {
                width: 100%;
            }
            .weeks-grid {
                grid-template-columns: 1fr;
            }
            .button-claim,
            .button-unclaim {
                min-height: 44px;
                padding: 12px 20px;
                font-size: 16px;
            }
        }
    </style>
    
    <script>
function claimWeek(opportunityId, templateId) {
    if (!confirm('Claim this week? This counts toward your commitment.')) {
        return;
    }
    
    // Get token from URL if present
    const urlParams = new URLSearchParams(window.location.search);
    const token = urlParams.get('token');
    
    var ajaxData = {
        action: 'fs_claim_week',
        opportunity_id: opportunityId,
        template_id: templateId,
        _ajax_nonce: '<?php echo wp_create_nonce('fs_claim_week'); ?>'
    };
    
    if (token) {
        ajaxData.token = token;
    }
    
    jQuery.ajax({
        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
        type: 'POST',
        data: ajaxData,
        success: function(response) {
            if (response.success) {
                alert('Week claimed successfully!');
                location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        },
        error: function() {
            alert('Something went wrong. Please try again.');
        }
    });
}

function unclaimWeek(opportunityId, signupId) {
    if (!confirm('Release this week? You can claim it again later if spots are available.')) {
        return;
    }
    
    // Get token from URL if present
    const urlParams = new URLSearchParams(window.location.search);
    const token = urlParams.get('token');
    
    var ajaxData = {
        action: 'fs_unclaim_week',
        signup_id: signupId,
        _ajax_nonce: '<?php echo wp_create_nonce('fs_unclaim_week'); ?>'
    };
    
    if (token) {
        ajaxData.token = token;
    }
    
    jQuery.ajax({
        url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
        type: 'POST',
        data: ajaxData,
        success: function(response) {
            if (response.success) {
                alert('Week released successfully!');
                location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        },
        error: function() {
            alert('Something went wrong. Please try again.');
        }
    });
}
</script>
    
    <?php
    return ob_get_clean();
}

private static function get_current_period_dates($period) {
    $now = current_time('timestamp');
    $year = date('Y', $now);
    $month = date('n', $now);
    
    switch ($period) {
        case 'quarterly':
            // Determine current quarter
            $quarter = ceil($month / 3);
            $start_month = (($quarter - 1) * 3) + 1;
            $start = date('Y-m-d', mktime(0, 0, 0, $start_month, 1, $year));
            $end = date('Y-m-t', mktime(0, 0, 0, $start_month + 2, 1, $year));
            break;
            
        case 'biannually':
            // First half (Jan-Jun) or second half (Jul-Dec)
            if ($month <= 6) {
                $start = $year . '-01-01';
                $end = $year . '-06-30';
            } else {
                $start = $year . '-07-01';
                $end = $year . '-12-31';
            }
            break;
            
        case 'annually':
        default:
            $start = $year . '-01-01';
            $end = $year . '-12-31';
            break;
    }
    
    return array('start' => $start, 'end' => $end);
}

    private static function get_next_period_dates($period) {
    $now = current_time('timestamp');
    $year = date('Y', $now);
    $month = date('n', $now);
    
    switch ($period) {
        case 'quarterly':
            $quarter = ceil($month / 3);
            $next_quarter = $quarter + 1;
            if ($next_quarter > 4) {
                $next_quarter = 1;
                $year++;
            }
            $start_month = (($next_quarter - 1) * 3) + 1;
            $start = date('Y-m-d', mktime(0, 0, 0, $start_month, 1, $year));
            $end = date('Y-m-t', mktime(0, 0, 0, $start_month + 2, 1, $year));
            break;
            
        case 'biannually':
            if ($month <= 6) {
                $start = $year . '-07-01';
                $end = $year . '-12-31';
            } else {
                $year++;
                $start = $year . '-01-01';
                $end = $year . '-06-30';
            }
            break;
            
        case 'annually':
        default:
            $year++;
            $start = $year . '-01-01';
            $end = $year . '-12-31';
            break;
    }
    
    return array('start' => $start, 'end' => $end);
}

    public static function ajax_claim_week() {
    check_ajax_referer('fs_claim_week', '_ajax_nonce');

    // Check for token auth first
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : null;
    
    $volunteer = null;
    if ($token) {
        global $wpdb;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $token
        ));
    }
    
    // Fall back to login check
    if (!$volunteer) {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in'));
        }
        
        $volunteer = self::get_current_volunteer();
    }
    
    if (!$volunteer) {
        wp_send_json_error(array('message' => 'Volunteer account not found'));
    }

    $opportunity_id = intval($_POST['opportunity_id'] ?? 0);
    $template_id = intval($_POST['template_id'] ?? 0);

    global $wpdb;
    
        // Get opportunity
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));
    
        if (!$opportunity) {
            wp_send_json_error(array('message' => 'Opportunity not found'));
        }
    
        // Get template
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates WHERE id = %d",
            $template_id
        ));
    
        $pattern = json_decode($template->recurrence_pattern, true);
        $max_claims = $pattern['flexible_max_claims'] ?? 4;
        $period = $pattern['flexible_period'] ?? 'quarterly';
    
        // Get NEXT period dates
        $next_period = self::get_next_period_dates($period);
    
        // Verify opportunity is in NEXT period
        if ($opportunity->event_date < $next_period['start'] || $opportunity->event_date > $next_period['end']) {
            wp_send_json_error(array('message' => 'You can only select weeks in the next ' . $period . ' period'));
        }
    
        // Check if already claimed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_signups 
            WHERE volunteer_id = %d AND opportunity_id = %d AND status = 'confirmed'",
            $volunteer->id,
            $opportunity_id
        ));
    
        if ($existing) {
            wp_send_json_error(array('message' => 'You have already claimed this week'));
        }
    
        // Check if week is full
        $claimed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups 
            WHERE opportunity_id = %d AND status = 'confirmed'",
            $opportunity_id
        ));
    
        if ($claimed_count >= $opportunity->spots_available) {
            wp_send_json_error(array('message' => 'This week is already full'));
        }
    
        // Check volunteer's limits for NEXT period
        $current_claims = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups s
            JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
            WHERE s.volunteer_id = %d 
            AND o.template_id = %d
            AND o.event_date BETWEEN %s AND %s
            AND s.status = 'confirmed'",
            $volunteer->id,
            $template_id,
            $next_period['start'],
            $next_period['end']
        ));
    
        if ($current_claims >= $max_claims) {
            wp_send_json_error(array('message' => 'You have reached your maximum selections for the next ' . $period));
        }
    
        // Create signup
        $wpdb->insert(
            $wpdb->prefix . 'fs_signups',
            array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id,
                'shift_id' => null,
                'signup_date' => current_time('mysql'),
                'status' => 'confirmed'
            )
        );
    
        // Update spots filled
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities 
            SET spots_filled = spots_filled + 1 
            WHERE id = %d",
            $opportunity_id
        ));
    
        wp_send_json_success(array('message' => 'Week claimed successfully!'));
    }
    
    public static function ajax_unclaim_week() {
    check_ajax_referer('fs_unclaim_week', '_ajax_nonce');
    
    // Check for token auth first
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : null;
    
    $volunteer = null;
    if ($token) {
        global $wpdb;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $token
        ));
    }
    
    // Fall back to login check
    if (!$volunteer) {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in'));
        }
        
        $volunteer = self::get_current_volunteer();
    }
    
    if (!$volunteer) {
        wp_send_json_error(array('message' => 'Volunteer account not found'));
    }

    $signup_id = intval($_POST['signup_id'] ?? 0);

    global $wpdb;
        
        // Get signup
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));
        
        if (!$signup) {
            wp_send_json_error(array('message' => 'Signup not found'));
        }
        
        // Cancel signup
        $wpdb->update(
            $wpdb->prefix . 'fs_signups',
            array('status' => 'cancelled'),
            array('id' => $signup_id)
        );
        
        // Update spots filled
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities 
            SET spots_filled = spots_filled - 1 
            WHERE id = %d",
            $signup->opportunity_id
        ));
        
        wp_send_json_success(array('message' => 'Week released successfully!'));
    }

    public static function flexible_calendar_shortcode($atts) {
    global $wpdb;

    if (!is_user_logged_in()) {
        return '<p>Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view the calendar.</p>';
    }
    
    $volunteer = self::get_current_volunteer();
    
    if (!$volunteer) {
        return '<p>Your account is not yet linked. Please contact an administrator.</p>';
    }
    
    // Get template ID from shortcode attributes
    $atts = shortcode_atts(array(
        'template_id' => ''
    ), $atts);
    
    $template_id = intval($atts['template_id']);
    
    // If no template specified, get all active flexible templates
    if ($template_id) {
        $templates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates 
            WHERE id = %d AND template_type = 'flexible_selection' AND status = 'Active'",
            $template_id
        ));
    } else {
        $templates = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates 
            WHERE template_type = 'flexible_selection' AND status = 'Active'
            ORDER BY title ASC"
        );
    }
    
    if (empty($templates)) {
        return '<p>No flexible selection calendars available.</p>';
    }
    
    ob_start();
    ?>
    <div class="fs-flexible-calendar">
        <h1>📅 Volunteer Schedule Calendar</h1>
        <p class="calendar-description">See who's scheduled and coordinate handoffs with other volunteers.</p>
        
        <?php foreach ($templates as $template): ?>
            <?php
            $pattern = json_decode($template->recurrence_pattern, true);
            $period = $pattern['flexible_period'] ?? 'quarterly';
            $week_pattern = $pattern['flexible_week_pattern'] ?? 'monday_friday';
            $slots_per_week = $pattern['flexible_slots_per_week'] ?? 1;
            
            $current_period = self::get_current_period_dates($period);
            $next_period = self::get_next_period_dates($period);
            
            // Get all signups for both periods
            $all_signups = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, o.event_date, v.name as volunteer_name, v.email, v.phone
                 FROM {$wpdb->prefix}fs_signups s
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
                 WHERE o.template_id = %d
                 AND o.event_date BETWEEN %s AND %s
                 AND s.status = 'confirmed'
                 ORDER BY o.event_date ASC",
                $template->id,
                $current_period['start'],
                $next_period['end']
            ));
            
            // Group signups by week
            $signups_by_week = array();
            foreach ($all_signups as $signup) {
                $week_key = $signup->event_date;
                if (!isset($signups_by_week[$week_key])) {
                    $signups_by_week[$week_key] = array();
                }
                $signups_by_week[$week_key][] = $signup;
            }
            
            // Generate calendar weeks for both periods
            $calendar_data = array(
                'current' => self::generate_calendar_weeks($current_period, $week_pattern, $signups_by_week, $slots_per_week),
                'next' => self::generate_calendar_weeks($next_period, $week_pattern, $signups_by_week, $slots_per_week)
            );
            ?>
            
            <div class="calendar-template-section">
                <div class="calendar-header">
                    <h2><?php echo esc_html($template->title); ?></h2>
                    <?php if ($template->description): ?>
                        <p class="template-desc"><?php echo esc_html($template->description); ?></p>
                    <?php endif; ?>
                    <?php if ($template->location): ?>
                        <p class="template-location">📍 <?php echo esc_html($template->location); ?></p>
                    <?php endif; ?>
                </div>
                
                <!-- Current Period Calendar -->
                <div class="period-section current-period">
                    <h3>Current <?php echo ucfirst($period); ?> (<?php echo date('M j', strtotime($current_period['start'])); ?> - <?php echo date('M j, Y', strtotime($current_period['end'])); ?>)</h3>
                    
                    <?php if (empty($calendar_data['current'])): ?>
                        <p class="no-weeks">No weeks in current period.</p>
                    <?php else: ?>
                        <div class="calendar-grid">
                            <?php foreach ($calendar_data['current'] as $week_data): ?>
                                <div class="calendar-week <?php echo $week_data['has_signups'] ? 'has-volunteers' : 'no-volunteers'; ?>">
                                    <div class="week-header">
                                        <div class="week-dates">
                                            <?php echo date('M j', strtotime($week_data['start'])); ?> - 
                                            <?php echo date('M j', strtotime($week_data['end'])); ?>
                                        </div>
                                        <div class="week-status">
                                            <?php echo count($week_data['volunteers']); ?> / <?php echo $slots_per_week; ?> filled
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($week_data['volunteers'])): ?>
                                        <div class="volunteers-list">
                                            <?php foreach ($week_data['volunteers'] as $vol): ?>
                                                <div class="volunteer-card <?php echo $vol['is_me'] ? 'is-me' : ''; ?>">
                                                    <div class="vol-name">
                                                        <?php if ($vol['is_me']): ?>
                                                            <span class="me-badge">YOU</span>
                                                        <?php endif; ?>
                                                        <?php echo esc_html($vol['name']); ?>
                                                    </div>
                                                    <div class="vol-contact">
                                                        <?php if (!empty($vol['email'])): ?>
                                                            <a href="mailto:<?php echo esc_attr($vol['email']); ?>" class="contact-link">
                                                                ✉️ <?php echo esc_html($vol['email']); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (!empty($vol['phone'])): ?>
                                                            <a href="tel:<?php echo esc_attr($vol['phone']); ?>" class="contact-link">
                                                                📞 <?php echo esc_html($vol['phone']); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="no-volunteers-msg">
                                            <span>⚠️ No volunteers scheduled</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Next Period Calendar -->
                <div class="period-section next-period">
                    <h3>Next <?php echo ucfirst($period); ?> (<?php echo date('M j', strtotime($next_period['start'])); ?> - <?php echo date('M j, Y', strtotime($next_period['end'])); ?>)</h3>
                    
                    <?php if (empty($calendar_data['next'])): ?>
                        <p class="no-weeks">No weeks generated yet for next period.</p>
                    <?php else: ?>
                        <div class="calendar-grid">
                            <?php foreach ($calendar_data['next'] as $week_data): ?>
                                <div class="calendar-week <?php echo $week_data['has_signups'] ? 'has-volunteers' : 'no-volunteers'; ?>">
                                    <div class="week-header">
                                        <div class="week-dates">
                                            <?php echo date('M j', strtotime($week_data['start'])); ?> - 
                                            <?php echo date('M j', strtotime($week_data['end'])); ?>
                                        </div>
                                        <div class="week-status">
                                            <?php echo count($week_data['volunteers']); ?> / <?php echo $slots_per_week; ?> filled
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($week_data['volunteers'])): ?>
                                        <div class="volunteers-list">
                                            <?php foreach ($week_data['volunteers'] as $vol): ?>
                                                <div class="volunteer-card <?php echo $vol['is_me'] ? 'is-me' : ''; ?>">
                                                    <div class="vol-name">
                                                        <?php if ($vol['is_me']): ?>
                                                            <span class="me-badge">YOU</span>
                                                        <?php endif; ?>
                                                        <?php echo esc_html($vol['name']); ?>
                                                    </div>
                                                    <div class="vol-contact">
                                                        <?php if (!empty($vol['email'])): ?>
                                                            <a href="mailto:<?php echo esc_attr($vol['email']); ?>" class="contact-link">
                                                                ✉️ <?php echo esc_html($vol['email']); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (!empty($vol['phone'])): ?>
                                                            <a href="tel:<?php echo esc_attr($vol['phone']); ?>" class="contact-link">
                                                                📞 <?php echo esc_html($vol['phone']); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="no-volunteers-msg">
                                            <span>⚠️ No volunteers scheduled</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Handoff Notice -->
                <?php 
                $end_of_current = date('Y-m-d', strtotime($current_period['end']));
                $days_until_handoff = (strtotime($end_of_current) - strtotime(date('Y-m-d'))) / (60 * 60 * 24);
                if ($days_until_handoff > 0 && $days_until_handoff <= 14): 
                ?>
                    <div class="handoff-notice">
                        <h4>⏰ Handoff Reminder</h4>
                        <p>
                            The current period ends in <strong><?php echo ceil($days_until_handoff); ?> days</strong> 
                            (<?php echo date('F j, Y', strtotime($end_of_current)); ?>). 
                            If you're scheduled this period, please coordinate with the next volunteer(s) above.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <style>
        .fs-flexible-calendar {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        .fs-flexible-calendar h1 {
            margin-bottom: 10px;
            color: #0073aa;
        }
        .calendar-description {
            color: #666;
            margin-bottom: 30px;
        }
        
        .calendar-template-section {
            background: white;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .calendar-header {
            border-bottom: 3px solid #0073aa;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .calendar-header h2 {
            margin: 0 0 10px 0;
            color: #0073aa;
        }
        .template-desc {
            margin: 10px 0;
            color: #333;
        }
        .template-location {
            color: #666;
            font-size: 14px;
        }
        
        .period-section {
            margin-bottom: 40px;
        }
        .period-section h3 {
            background: #0073aa;
            color: white;
            padding: 15px 20px;
            margin: 0 0 20px 0;
            border-radius: 6px;
        }
        .next-period h3 {
            background: #667eea;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .calendar-week {
            border: 2px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            transition: box-shadow 0.2s;
        }
        .calendar-week:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .calendar-week.no-volunteers {
            background: #fff3cd;
            border-color: #ffc107;
        }
        
        .week-header {
            background: #f8f9fa;
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }
        .week-dates {
            font-weight: 600;
            font-size: 16px;
            color: #333;
            margin-bottom: 5px;
        }
        .week-status {
            font-size: 13px;
            color: #666;
        }
        
        .volunteers-list {
            padding: 15px;
        }
        .volunteer-card {
            background: #e7f3ff;
            border: 1px solid #0073aa;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .volunteer-card:last-child {
            margin-bottom: 0;
        }
        .volunteer-card.is-me {
            background: #d4edda;
            border-color: #28a745;
            border-width: 2px;
        }
        
        .vol-name {
            font-weight: 600;
            font-size: 15px;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .me-badge {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }
        
        .vol-contact {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .contact-link {
            font-size: 13px;
            color: #0073aa;
            text-decoration: none;
            word-break: break-all;
        }
        .contact-link:hover {
            text-decoration: underline;
        }
        
        .no-volunteers-msg {
            padding: 20px;
            text-align: center;
            color: #856404;
            font-weight: 600;
        }
        
        .no-weeks {
            padding: 40px;
            text-align: center;
            background: #f8f9fa;
            border-radius: 8px;
            color: #666;
        }
        
        .handoff-notice {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin-top: 30px;
        }
        .handoff-notice h4 {
            margin: 0 0 10px 0;
            color: #856404;
        }
        .handoff-notice p {
            margin: 0;
            color: #333;
        }
        
        @media (max-width: 768px) {
            .calendar-grid {
                grid-template-columns: 1fr;
            }
            .contact-link {
                font-size: 14px;
                word-break: break-word;
            }
            .vol-contact a {
                padding: 8px 0;
                display: block;
            }
        }
    </style>
    
    <?php
    return ob_get_clean();
}

/**
 * Generate calendar week data for a period
 */
private static function generate_calendar_weeks($period, $week_pattern, $signups_by_week, $slots_per_week) {
    global $wpdb;
    $current_volunteer = self::get_current_volunteer();
    
    $weeks = array();
    $start_date = new DateTime($period['start']);
    $end_date = new DateTime($period['end']);
    
    // Find all Mondays in the period
    while ($start_date <= $end_date) {
        if ($start_date->format('N') == 1) { // Monday
            $week_start = $start_date->format('Y-m-d');
            
            // Calculate week end based on pattern
            $days_to_add = ($week_pattern === 'monday_friday') ? 4 : 6;
            $week_end_date = clone $start_date;
            $week_end_date->modify("+{$days_to_add} days");
            $week_end = $week_end_date->format('Y-m-d');
            
            // Get volunteers for this week
            $volunteers = array();
            if (isset($signups_by_week[$week_start])) {
                foreach ($signups_by_week[$week_start] as $signup) {
                    $volunteers[] = array(
                        'name' => $signup->volunteer_name,
                        'email' => $signup->email,
                        'phone' => $signup->phone,
                        'is_me' => ($signup->volunteer_id == $current_volunteer->id)
                    );
                }
            }
            
            $weeks[] = array(
                'start' => $week_start,
                'end' => $week_end,
                'volunteers' => $volunteers,
                'has_signups' => !empty($volunteers)
            );
        }
        
        $start_date->modify('+1 day');
    }
    
    return $weeks;
}

    /**
     * Teams view - show volunteer's teams
     */
    public static function teams_view($volunteer, $portal_url) {
        global $wpdb;

        // Handle team creation submission
        if (isset($_POST['create_team']) && wp_verify_nonce($_POST['_wpnonce'], 'create_team')) {
            $result = FS_Team_Manager::create_team(array(
                'name' => sanitize_text_field($_POST['team_name']),
                'type' => sanitize_text_field($_POST['team_type']),
                'team_leader_volunteer_id' => $volunteer->id,
                'default_size' => intval($_POST['default_size']),
                'description' => sanitize_textarea_field($_POST['description']),
                'status' => 'active'
            ));

            if (!is_wp_error($result)) {
                $success_message = 'Team created successfully!';
            } else {
                $error_message = $result->get_error_message();
            }
        }

        // Get volunteer's teams
        $teams = FS_Team_Manager::get_volunteer_teams($volunteer->id);

        ob_start();
        ?>
        <div class="friendshyft-portal teams-view">
            <div class="portal-header">
                <h1>My Teams</h1>
                <div class="header-actions">
                    <a href="<?php echo esc_url($portal_url); ?>" class="button-secondary">← Back to Dashboard</a>
                    <a href="<?php echo esc_url(add_query_arg('view', 'create-team', $portal_url)); ?>" class="button-primary">+ Create New Team</a>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="portal-message success-message">✓ <?php echo esc_html($success_message); ?></div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="portal-message error-message">✗ <?php echo esc_html($error_message); ?></div>
            <?php endif; ?>

            <?php if (!empty($teams)): ?>
                <div class="teams-grid">
                    <?php foreach ($teams as $team): ?>
                        <div class="team-card">
                            <div class="team-header">
                                <h3>👥 <?php echo esc_html($team->name); ?></h3>
                                <?php if ($team->volunteer_role === 'leader'): ?>
                                    <span class="leader-badge">Team Leader</span>
                                <?php else: ?>
                                    <span class="member-badge">Team Member</span>
                                <?php endif; ?>
                            </div>

                            <div class="team-details">
                                <?php if ($team->description): ?>
                                    <p><?php echo esc_html($team->description); ?></p>
                                <?php endif; ?>

                                <div class="team-meta">
                                    <div class="meta-item">
                                        <strong>Team Size:</strong> <?php echo (int)$team->default_size; ?> people
                                    </div>
                                    <div class="meta-item">
                                        <strong>Type:</strong> <?php echo ucfirst($team->type); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>You're not part of any teams yet.</p>
                    <a href="<?php echo esc_url(add_query_arg('view', 'create-team', $portal_url)); ?>" class="button-primary">Create Your First Team</a>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .teams-view { max-width: 1200px; margin: 0 auto; padding: 20px; }
            .portal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
            .header-actions { display: flex; gap: 10px; }
            .button-primary {
                background: #0073aa;
                color: white;
                padding: 10px 20px;
                border-radius: 4px;
                text-decoration: none;
                font-weight: 600;
            }
            .button-primary:hover { background: #005177; color: white; }
            .teams-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: 20px;
            }
            .team-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .team-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 2px solid #f0f0f0;
            }
            .team-header h3 { margin: 0; }
            .leader-badge, .member-badge {
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .leader-badge { background: #d4edda; color: #155724; }
            .member-badge { background: #d1ecf1; color: #0c5460; }
            .team-meta { margin-top: 15px; }
            .meta-item { margin-bottom: 8px; font-size: 14px; }
            .empty-state {
                text-align: center;
                padding: 60px 20px;
                background: #f8f9fa;
                border-radius: 8px;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Create team view
     */
    public static function create_team_view($volunteer, $portal_url) {
        ob_start();
        ?>
        <div class="friendshyft-portal create-team-view">
            <div class="portal-header">
                <h1>Create New Team</h1>
                <a href="<?php echo esc_url(add_query_arg('view', 'teams', $portal_url)); ?>" class="button-secondary">← Back to My Teams</a>
            </div>

            <div class="create-team-form-container">
                <form method="POST" action="<?php echo esc_url(add_query_arg('view', 'teams', $portal_url)); ?>" class="create-team-form">
                    <?php wp_nonce_field('create_team'); ?>

                    <div class="form-group">
                        <label for="team_name">Team Name *</label>
                        <input type="text" id="team_name" name="team_name" required maxlength="255"
                               placeholder="e.g., Johnson Family, Youth Group, Friday Night Crew">
                    </div>

                    <div class="form-group">
                        <label for="team_type">Team Type *</label>
                        <select id="team_type" name="team_type" required>
                            <option value="recurring">Recurring Team (Regular volunteers)</option>
                            <option value="one-time">One-Time Team (Special event)</option>
                        </select>
                        <small>Recurring teams volunteer regularly; one-time teams are for specific events.</small>
                    </div>

                    <div class="form-group">
                        <label for="default_size">Team Size *</label>
                        <input type="number" id="default_size" name="default_size" required min="1" max="100" value="5">
                        <small>How many people are typically on your team?</small>
                    </div>

                    <div class="form-group">
                        <label for="description">Team Description (Optional)</label>
                        <textarea id="description" name="description" rows="4"
                                  placeholder="Tell us about your team..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="create_team" class="button-primary">Create Team</button>
                        <a href="<?php echo esc_url(add_query_arg('view', 'teams', $portal_url)); ?>" class="button-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <style>
            .create-team-view { max-width: 800px; margin: 0 auto; padding: 20px; }
            .create-team-form-container {
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .form-group {
                margin-bottom: 25px;
            }
            .form-group label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #333;
            }
            .form-group input[type="text"],
            .form-group input[type="number"],
            .form-group select,
            .form-group textarea {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            .form-group small {
                display: block;
                margin-top: 5px;
                color: #666;
                font-size: 13px;
            }
            .form-actions {
                display: flex;
                gap: 10px;
                margin-top: 30px;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for volunteers to add their own blocked times (availability)
     */
    public static function ajax_portal_add_blocked_time() {
        global $wpdb;

        // Check for token authentication first
        $volunteer = self::check_token_auth();

        // Fall back to logged-in user
        if (!$volunteer && is_user_logged_in()) {
            $volunteer = self::get_current_volunteer();
        }

        if (!$volunteer) {
            wp_send_json_error(array('message' => 'Authentication required'));
            return;
        }

        // Validate inputs
        if (!isset($_POST['start_time']) || !isset($_POST['end_time'])) {
            wp_send_json_error(array('message' => 'Start time and end time are required'));
            return;
        }

        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = sanitize_text_field($_POST['end_time']);
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';

        // Validate datetime format
        $start_dt = strtotime($start_time);
        $end_dt = strtotime($end_time);

        if (!$start_dt || !$end_dt) {
            wp_send_json_error(array('message' => 'Invalid date/time format'));
            return;
        }

        if ($end_dt <= $start_dt) {
            wp_send_json_error(array('message' => 'End time must be after start time'));
            return;
        }

        // Convert to MySQL datetime format
        $start_mysql = date('Y-m-d H:i:s', $start_dt);
        $end_mysql = date('Y-m-d H:i:s', $end_dt);

        // Insert blocked time
        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_blocked_times",
            array(
                'volunteer_id' => $volunteer->id,
                'start_time' => $start_mysql,
                'end_time' => $end_mysql,
                'source' => 'manual',
                'title' => $title,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Database error: Failed to add blocked time'));
            return;
        }

        $blocked_time_id = $wpdb->insert_id;

        // Audit log
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('blocked_time_added', 'volunteer', $volunteer->id, array(
                'blocked_time_id' => $blocked_time_id,
                'start_time' => $start_mysql,
                'end_time' => $end_mysql,
                'title' => $title,
                'source' => 'volunteer_portal'
            ));
        }

        wp_send_json_success(array(
            'message' => 'Blocked time added successfully',
            'blocked_time_id' => $blocked_time_id
        ));
    }

    /**
     * AJAX handler for volunteers to delete their own blocked times
     */
    public static function ajax_portal_delete_blocked_time() {
        global $wpdb;

        // Check for token authentication first
        $volunteer = self::check_token_auth();

        // Fall back to logged-in user with nonce verification
        if (!$volunteer) {
            // For logged-in users, verify nonce
            if (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'friendshyft_portal')) {
                if (is_user_logged_in()) {
                    $volunteer = self::get_current_volunteer();
                }
            }
        }

        if (!$volunteer) {
            wp_send_json_error(array('message' => 'Authentication required'));
            return;
        }

        // Validate input
        if (!isset($_POST['blocked_time_id'])) {
            wp_send_json_error(array('message' => 'Blocked time ID is required'));
            return;
        }

        $blocked_time_id = intval($_POST['blocked_time_id']);

        // Get the blocked time to verify ownership and source
        $blocked_time = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_blocked_times WHERE id = %d",
            $blocked_time_id
        ));

        if (!$blocked_time) {
            wp_send_json_error(array('message' => 'Blocked time not found'));
            return;
        }

        // Verify ownership
        if ($blocked_time->volunteer_id != $volunteer->id) {
            wp_send_json_error(array('message' => 'You can only delete your own blocked times'));
            return;
        }

        // Prevent deletion of Google Calendar synced times
        if ($blocked_time->source === 'google_calendar') {
            wp_send_json_error(array('message' => 'Cannot delete Google Calendar synced times. Please manage them in Google Calendar.'));
            return;
        }

        // Delete the blocked time
        $result = $wpdb->delete(
            "{$wpdb->prefix}fs_blocked_times",
            array('id' => $blocked_time_id),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Database error: Failed to delete blocked time'));
            return;
        }

        // Audit log
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('blocked_time_deleted', 'volunteer', $volunteer->id, array(
                'blocked_time_id' => $blocked_time_id,
                'start_time' => $blocked_time->start_time,
                'end_time' => $blocked_time->end_time,
                'title' => $blocked_time->title,
                'source' => 'volunteer_portal'
            ));
        }

        wp_send_json_success(array('message' => 'Blocked time deleted successfully'));
    }

    /**
     * Substitutes view - find substitutes or accept substitute requests
     */
    private static function substitutes_view($volunteer, $portal_url) {
        global $wpdb;

        // Get volunteer's confirmed upcoming signups
        $my_signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.title, o.event_date, o.location, o.description,
                    sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
             WHERE s.volunteer_id = %d
             AND s.status = 'confirmed'
             AND o.event_date >= CURDATE()
             ORDER BY o.event_date, sh.shift_start_time",
            $volunteer->id
        ));

        // Get volunteer's active substitute requests
        $my_requests = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, o.title, o.event_date, o.location,
                    sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_substitute_requests r
             JOIN {$wpdb->prefix}fs_opportunities o ON r.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_signups s ON r.signup_id = s.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
             WHERE r.original_volunteer_id = %d
             AND r.status = 'pending'
             ORDER BY o.event_date",
            $volunteer->id
        ));

        // Get available substitute requests (others need help, volunteer is qualified)
        $available_requests = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, o.title, o.event_date, o.location, o.description,
                    v.name as original_volunteer_name,
                    sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_substitute_requests r
             JOIN {$wpdb->prefix}fs_opportunities o ON r.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_volunteers v ON r.original_volunteer_id = v.id
             JOIN {$wpdb->prefix}fs_signups s ON r.signup_id = s.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
             WHERE r.status = 'pending'
             AND r.original_volunteer_id != %d
             AND o.event_date >= CURDATE()
             AND EXISTS (
                 SELECT 1 FROM {$wpdb->prefix}fs_opportunity_roles orole
                 JOIN {$wpdb->prefix}fs_volunteer_roles vr ON orole.role_id = vr.role_id
                 WHERE orole.opportunity_id = o.id AND vr.volunteer_id = %d
             )
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->prefix}fs_signups existing
                 WHERE existing.volunteer_id = %d
                 AND existing.opportunity_id = o.id
                 AND existing.status = 'confirmed'
             )
             ORDER BY o.event_date",
            $volunteer->id,
            $volunteer->id,
            $volunteer->id
        ));

        ob_start();
        ?>
        <div class="friendshyft-portal substitutes-view">
            <div class="portal-header">
                <h1>🔄 Find Substitutes</h1>
                <a href="<?php echo esc_url($portal_url); ?>" class="btn-secondary">← Back to Dashboard</a>
            </div>

            <div class="portal-content">
                <!-- My Substitute Requests -->
                <div class="section-card">
                    <h2>📤 My Substitute Requests</h2>

                    <?php if (empty($my_requests)): ?>
                        <div class="empty-state">
                            <p>You don't have any pending substitute requests.</p>
                            <p class="help-text">Request a substitute from "My Schedule" when you can't make a shift.</p>
                        </div>
                    <?php else: ?>
                        <div class="requests-list">
                            <?php foreach ($my_requests as $request): ?>
                                <div class="request-card my-request">
                                    <div class="request-header">
                                        <h3><?php echo esc_html($request->title); ?></h3>
                                        <span class="status-badge status-pending">Looking for substitute...</span>
                                    </div>
                                    <div class="request-details">
                                        <p><strong>📅 Date:</strong> <?php echo date('F j, Y', strtotime($request->event_date)); ?></p>
                                        <?php if ($request->shift_start_time): ?>
                                            <p><strong>🕐 Time:</strong> <?php echo date('g:i A', strtotime($request->shift_start_time)); ?> - <?php echo date('g:i A', strtotime($request->shift_end_time)); ?></p>
                                        <?php endif; ?>
                                        <p><strong>📍 Location:</strong> <?php echo esc_html($request->location); ?></p>
                                        <?php if ($request->reason): ?>
                                            <p><strong>Reason:</strong> <?php echo esc_html($request->reason); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn-cancel-request" data-request-id="<?php echo $request->id; ?>">Cancel Request</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Available Substitute Opportunities -->
                <div class="section-card">
                    <h2>📥 Help Others - Available Substitute Opportunities</h2>

                    <?php if (empty($available_requests)): ?>
                        <div class="empty-state">
                            <p>No substitute opportunities available right now.</p>
                            <p class="help-text">We'll notify you when volunteers need substitutes for shifts you're qualified for.</p>
                        </div>
                    <?php else: ?>
                        <div class="requests-list">
                            <?php foreach ($available_requests as $request): ?>
                                <div class="request-card available-request">
                                    <div class="request-header">
                                        <h3><?php echo esc_html($request->title); ?></h3>
                                        <span class="status-badge status-available">Available</span>
                                    </div>
                                    <div class="request-details">
                                        <p><strong>📅 Date:</strong> <?php echo date('F j, Y', strtotime($request->event_date)); ?></p>
                                        <?php if ($request->shift_start_time): ?>
                                            <p><strong>🕐 Time:</strong> <?php echo date('g:i A', strtotime($request->shift_start_time)); ?> - <?php echo date('g:i A', strtotime($request->shift_end_time)); ?></p>
                                        <?php endif; ?>
                                        <p><strong>📍 Location:</strong> <?php echo esc_html($request->location); ?></p>
                                        <?php if ($request->description): ?>
                                            <div class="opportunity-description">
                                                <?php echo wpautop(wp_strip_all_tags($request->description)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn-accept-substitute" data-request-id="<?php echo $request->id; ?>">✓ Accept This Shift</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Request Substitute from My Shifts -->
                <div class="section-card">
                    <h2>📤 Request Substitute for My Shifts</h2>

                    <?php if (empty($my_signups)): ?>
                        <div class="empty-state">
                            <p>You don't have any upcoming shifts.</p>
                        </div>
                    <?php else: ?>
                        <div class="signups-list">
                            <?php foreach ($my_signups as $signup): ?>
                                <?php
                                // Check if already has pending request
                                $has_request = $wpdb->get_var($wpdb->prepare(
                                    "SELECT id FROM {$wpdb->prefix}fs_substitute_requests
                                     WHERE signup_id = %d AND status = 'pending'",
                                    $signup->id
                                ));
                                ?>
                                <div class="signup-card">
                                    <div class="signup-header">
                                        <h3><?php echo esc_html($signup->title); ?></h3>
                                    </div>
                                    <div class="signup-details">
                                        <p><strong>📅 Date:</strong> <?php echo date('F j, Y', strtotime($signup->event_date)); ?></p>
                                        <?php if ($signup->shift_start_time): ?>
                                            <p><strong>🕐 Time:</strong> <?php echo date('g:i A', strtotime($signup->shift_start_time)); ?> - <?php echo date('g:i A', strtotime($signup->shift_end_time)); ?></p>
                                        <?php endif; ?>
                                        <p><strong>📍 Location:</strong> <?php echo esc_html($signup->location); ?></p>
                                    </div>
                                    <?php if ($has_request): ?>
                                        <span class="status-badge status-pending">Substitute requested</span>
                                    <?php else: ?>
                                        <button class="btn-request-substitute" data-signup-id="<?php echo $signup->id; ?>">Request Substitute</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var token = '<?php echo isset($_GET['token']) ? esc_js(sanitize_text_field($_GET['token'])) : ''; ?>';

            // Request substitute
            $('.btn-request-substitute').on('click', function() {
                var signupId = $(this).data('signup-id');
                var reason = prompt('Why do you need a substitute? (optional)');

                if (reason === null) return; // User cancelled

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_request_substitute',
                        token: token,
                        signup_id: signupId,
                        reason: reason
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });

            // Cancel request
            $('.btn-cancel-request').on('click', function() {
                if (!confirm('Cancel this substitute request?')) return;

                var requestId = $(this).data('request-id');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_cancel_substitute_request',
                        token: token,
                        request_id: requestId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Request cancelled successfully.');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });

            // Accept substitute
            $('.btn-accept-substitute').on('click', function() {
                if (!confirm('Accept this substitute shift? You will be signed up for this opportunity.')) return;

                var requestId = $(this).data('request-id');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_accept_substitute',
                        token: token,
                        request_id: requestId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });
        });
        </script>

        <style>
            .substitutes-view .section-card {
                background: white;
                padding: 30px;
                margin-bottom: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .substitutes-view .section-card h2 {
                margin: 0 0 20px 0;
                color: #0073aa;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
            .substitutes-view .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #666;
            }
            .substitutes-view .help-text {
                font-size: 14px;
                color: #999;
            }
            .substitutes-view .requests-list,
            .substitutes-view .signups-list {
                display: grid;
                gap: 20px;
            }
            .substitutes-view .request-card,
            .substitutes-view .signup-card {
                border: 2px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                transition: border-color 0.2s;
            }
            .substitutes-view .request-card:hover,
            .substitutes-view .signup-card:hover {
                border-color: #0073aa;
            }
            .substitutes-view .request-header,
            .substitutes-view .signup-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 15px;
            }
            .substitutes-view .request-header h3,
            .substitutes-view .signup-header h3 {
                margin: 0;
                color: #333;
                font-size: 18px;
            }
            .substitutes-view .status-badge {
                padding: 5px 12px;
                border-radius: 20px;
                font-size: 13px;
                font-weight: 600;
            }
            .substitutes-view .status-pending {
                background: #fff3cd;
                color: #856404;
            }
            .substitutes-view .status-available {
                background: #d4edda;
                color: #155724;
            }
            .substitutes-view .request-details p,
            .substitutes-view .signup-details p {
                margin: 8px 0;
                color: #666;
            }
            .substitutes-view .opportunity-description {
                margin-top: 15px;
                padding: 15px;
                background: #f9f9f9;
                border-left: 4px solid #0073aa;
                font-size: 14px;
            }
            .substitutes-view .btn-request-substitute,
            .substitutes-view .btn-accept-substitute {
                background: #0073aa;
                color: white;
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 600;
                margin-top: 15px;
            }
            .substitutes-view .btn-request-substitute:hover,
            .substitutes-view .btn-accept-substitute:hover {
                background: #005177;
            }
            .substitutes-view .btn-cancel-request {
                background: #dc3545;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                margin-top: 15px;
            }
            .substitutes-view .btn-cancel-request:hover {
                background: #c82333;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Recurring schedule view - auto-signup and blackout dates
     */
    private static function recurring_schedule_view($volunteer, $portal_url) {
        global $wpdb;

        // Get volunteer's availability
        $availability = FS_Recurring_Schedules::get_availability($volunteer->id);

        // Get volunteer's blackout dates
        $blackout_dates = FS_Recurring_Schedules::get_blackout_dates($volunteer->id);

        // Get recent auto-signup log
        $auto_signup_log = FS_Recurring_Schedules::get_auto_signup_log($volunteer->id, 20);

        // Get all programs for dropdown
        $programs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fs_programs ORDER BY name");

        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        $time_slots = array(
            'morning' => 'Morning (before noon)',
            'afternoon' => 'Afternoon (noon - 5pm)',
            'evening' => 'Evening (after 5pm)',
            'all_day' => 'All Day'
        );

        ob_start();
        ?>
        <div class="friendshyft-portal recurring-schedule-view">
            <div class="portal-header">
                <h1>🔁 Auto-Signup & Availability</h1>
                <a href="<?php echo esc_url($portal_url); ?>" class="btn-secondary">← Back to Dashboard</a>
            </div>

            <div class="portal-content">
                <!-- Explanation -->
                <div class="info-card">
                    <h3>How Auto-Signup Works</h3>
                    <p>Set your recurring availability and enable auto-signup to be automatically signed up for matching opportunities. You'll receive email notifications when you're auto-signed up.</p>
                    <ul>
                        <li><strong>Recurring Availability:</strong> Tell us when you're generally available each week</li>
                        <li><strong>Auto-Signup:</strong> Automatically sign up for opportunities that match your availability</li>
                        <li><strong>Blackout Dates:</strong> Block specific dates when you're unavailable (vacation, etc.)</li>
                    </ul>
                </div>

                <!-- My Recurring Availability -->
                <div class="section-card">
                    <h2>📆 My Recurring Availability</h2>

                    <div class="availability-form">
                        <h3>Add New Availability</h3>
                        <form id="add-availability-form">
                            <div class="form-row">
                                <div class="form-field">
                                    <label>Day of Week</label>
                                    <select name="day_of_week" required>
                                        <option value="">Select day...</option>
                                        <?php foreach ($days as $day): ?>
                                            <option value="<?php echo $day; ?>"><?php echo ucfirst($day); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label>Time Slot</label>
                                    <select name="time_slot" required>
                                        <option value="">Select time...</option>
                                        <?php foreach ($time_slots as $value => $label): ?>
                                            <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label>Program (optional)</label>
                                    <select name="program_id">
                                        <option value="">Any program</option>
                                        <?php foreach ($programs as $program): ?>
                                            <option value="<?php echo $program->id; ?>"><?php echo esc_html($program->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <label>
                                    <input type="checkbox" name="auto_signup" value="1">
                                    Enable auto-signup for this time slot
                                </label>
                            </div>
                            <button type="submit" class="btn-primary">Add Availability</button>
                        </form>
                    </div>

                    <?php if (empty($availability)): ?>
                        <div class="empty-state">
                            <p>You haven't set any recurring availability yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="availability-list">
                            <table class="availability-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Time Slot</th>
                                        <th>Program</th>
                                        <th>Auto-Signup</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($availability as $avail): ?>
                                        <tr>
                                            <td><?php echo ucfirst($avail->day_of_week); ?></td>
                                            <td><?php echo $time_slots[$avail->time_slot]; ?></td>
                                            <td><?php echo $avail->program_name ? esc_html($avail->program_name) : 'Any'; ?></td>
                                            <td>
                                                <label class="toggle-switch">
                                                    <input type="checkbox" class="toggle-auto-signup" data-availability-id="<?php echo $avail->id; ?>" <?php echo $avail->auto_signup_enabled ? 'checked' : ''; ?>>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                            </td>
                                            <td>
                                                <button class="btn-remove-availability" data-availability-id="<?php echo $avail->id; ?>">Remove</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Blackout Dates -->
                <div class="section-card">
                    <h2>🚫 Blackout Dates</h2>

                    <div class="blackout-form">
                        <h3>Add Blackout Period</h3>
                        <form id="add-blackout-form">
                            <div class="form-row">
                                <div class="form-field">
                                    <label>Start Date</label>
                                    <input type="date" name="start_date" required min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-field">
                                    <label>End Date</label>
                                    <input type="date" name="end_date" required min="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="form-field">
                                <label>Reason (optional)</label>
                                <input type="text" name="reason" placeholder="e.g., Vacation, out of town">
                            </div>
                            <button type="submit" class="btn-primary">Add Blackout Period</button>
                        </form>
                    </div>

                    <?php if (empty($blackout_dates)): ?>
                        <div class="empty-state">
                            <p>No blackout dates set.</p>
                        </div>
                    <?php else: ?>
                        <div class="blackout-list">
                            <?php foreach ($blackout_dates as $blackout): ?>
                                <div class="blackout-card">
                                    <div class="blackout-info">
                                        <strong><?php echo date('M j, Y', strtotime($blackout->start_date)); ?> - <?php echo date('M j, Y', strtotime($blackout->end_date)); ?></strong>
                                        <?php if ($blackout->reason): ?>
                                            <p><?php echo esc_html($blackout->reason); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn-remove-blackout" data-blackout-id="<?php echo $blackout->id; ?>">Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Auto-Signup Log -->
                <div class="section-card">
                    <h2>📋 Auto-Signup History</h2>

                    <?php if (empty($auto_signup_log)): ?>
                        <div class="empty-state">
                            <p>No auto-signup attempts yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="log-list">
                            <table class="log-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Opportunity</th>
                                        <th>Status</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($auto_signup_log as $log): ?>
                                        <tr class="<?php echo $log->success ? 'log-success' : 'log-failed'; ?>">
                                            <td><?php echo date('M j, Y', strtotime($log->processed_at)); ?></td>
                                            <td><?php echo esc_html($log->title); ?> (<?php echo date('M j', strtotime($log->event_date)); ?>)</td>
                                            <td><?php echo $log->success ? '✓ Signed up' : '✗ Skipped'; ?></td>
                                            <td><?php echo esc_html($log->reason); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var token = '<?php echo isset($_GET['token']) ? esc_js(sanitize_text_field($_GET['token'])) : ''; ?>';

            // Add availability
            $('#add-availability-form').on('submit', function(e) {
                e.preventDefault();

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_save_availability',
                        token: token,
                        day_of_week: $('[name="day_of_week"]').val(),
                        time_slot: $('[name="time_slot"]').val(),
                        program_id: $('[name="program_id"]').val() || null,
                        auto_signup: $('[name="auto_signup"]').is(':checked')
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Availability saved successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });

            // Toggle auto-signup
            $('.toggle-auto-signup').on('change', function() {
                var availabilityId = $(this).data('availability-id');
                var enabled = $(this).is(':checked');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_toggle_auto_signup',
                        token: token,
                        availability_id: availabilityId,
                        enabled: enabled
                    },
                    success: function(response) {
                        if (!response.success) {
                            alert('Error: ' + response.data);
                            location.reload();
                        }
                    }
                });
            });

            // Remove availability
            $('.btn-remove-availability').on('click', function() {
                if (!confirm('Remove this availability setting?')) return;

                const availabilityId = $(this).data('availability-id');
                const $row = $(this).closest('tr');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_remove_availability',
                        token: token,
                        availability_id: availabilityId
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                // Check if table is now empty
                                if ($('#availability-list tbody tr').length === 0) {
                                    $('#availability-list tbody').html(
                                        '<tr><td colspan="5" style="text-align: center; padding: 40px; color: #999;">' +
                                        'No recurring availability set. Use the form above to add your weekly schedule.</td></tr>'
                                    );
                                }
                            });
                            showAlert(response.data, 'success');
                        } else {
                            showAlert(response.data || 'Failed to remove availability', 'error');
                        }
                    },
                    error: function() {
                        showAlert('Error removing availability', 'error');
                    }
                });
            });

            // Add blackout
            $('#add-blackout-form').on('submit', function(e) {
                e.preventDefault();

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_add_blackout_date',
                        token: token,
                        start_date: $('[name="start_date"]').val(),
                        end_date: $('[name="end_date"]').val(),
                        reason: $('[name="reason"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Blackout period added successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });

            // Remove blackout
            $('.btn-remove-blackout').on('click', function() {
                if (!confirm('Remove this blackout period?')) return;

                var blackoutId = $(this).data('blackout-id');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'fs_remove_blackout_date',
                        token: token,
                        blackout_id: blackoutId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Blackout period removed successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });
        });
        </script>

        <style>
            .recurring-schedule-view .info-card {
                background: #e7f3ff;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 30px;
                border-left: 4px solid #0073aa;
            }
            .recurring-schedule-view .info-card h3 {
                margin: 0 0 10px 0;
                color: #0073aa;
            }
            .recurring-schedule-view .info-card ul {
                margin: 15px 0 0 20px;
            }
            .recurring-schedule-view .section-card {
                background: white;
                padding: 30px;
                margin-bottom: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .recurring-schedule-view .section-card h2 {
                margin: 0 0 20px 0;
                color: #0073aa;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
            .recurring-schedule-view .section-card h3 {
                margin: 0 0 15px 0;
                font-size: 16px;
            }
            .recurring-schedule-view .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #666;
            }
            .recurring-schedule-view .availability-form,
            .recurring-schedule-view .blackout-form {
                background: #f9f9f9;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 30px;
            }
            .recurring-schedule-view .form-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-bottom: 15px;
            }
            .recurring-schedule-view .form-field {
                display: flex;
                flex-direction: column;
            }
            .recurring-schedule-view .form-field label {
                margin-bottom: 5px;
                font-weight: 600;
                color: #333;
            }
            .recurring-schedule-view .form-field input,
            .recurring-schedule-view .form-field select {
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            .recurring-schedule-view .availability-table,
            .recurring-schedule-view .log-table {
                width: 100%;
                border-collapse: collapse;
            }
            .recurring-schedule-view .availability-table th,
            .recurring-schedule-view .log-table th {
                background: #f5f5f5;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                border-bottom: 2px solid #ddd;
            }
            .recurring-schedule-view .availability-table td,
            .recurring-schedule-view .log-table td {
                padding: 12px;
                border-bottom: 1px solid #eee;
            }
            .recurring-schedule-view .toggle-switch {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 24px;
            }
            .recurring-schedule-view .toggle-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .recurring-schedule-view .toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: 0.4s;
                border-radius: 24px;
            }
            .recurring-schedule-view .toggle-slider:before {
                position: absolute;
                content: "";
                height: 16px;
                width: 16px;
                left: 4px;
                bottom: 4px;
                background-color: white;
                transition: 0.4s;
                border-radius: 50%;
            }
            .recurring-schedule-view input:checked + .toggle-slider {
                background-color: #0073aa;
            }
            .recurring-schedule-view input:checked + .toggle-slider:before {
                transform: translateX(26px);
            }
            .recurring-schedule-view .btn-remove-availability,
            .recurring-schedule-view .btn-remove-blackout {
                background: #dc3545;
                color: white;
                padding: 6px 12px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
            }
            .recurring-schedule-view .btn-remove-availability:hover,
            .recurring-schedule-view .btn-remove-blackout:hover {
                background: #c82333;
            }
            .recurring-schedule-view .blackout-list {
                display: grid;
                gap: 15px;
            }
            .recurring-schedule-view .blackout-card {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 15px;
                border: 2px solid #ddd;
                border-radius: 8px;
            }
            .recurring-schedule-view .blackout-card p {
                margin: 5px 0 0 0;
                color: #666;
                font-size: 14px;
            }
            .recurring-schedule-view .log-success {
                background: #d4edda;
            }
            .recurring-schedule-view .log-failed {
                background: #f8d7da;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

FS_Volunteer_Portal::init();
