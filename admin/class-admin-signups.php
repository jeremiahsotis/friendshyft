<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Signups {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);
        add_action('admin_post_fs_manual_signup', array(__CLASS__, 'handle_manual_signup'));
        add_action('admin_post_fs_remove_signup', array(__CLASS__, 'handle_remove_signup'));
        add_action('admin_post_fs_remove_team_signup', array(__CLASS__, 'handle_remove_team_signup'));
        add_action('admin_post_fs_add_to_waitlist', array(__CLASS__, 'handle_add_to_waitlist'));
        add_action('admin_post_fs_promote_from_waitlist', array(__CLASS__, 'handle_promote_from_waitlist'));
        add_action('admin_post_fs_remove_from_waitlist', array(__CLASS__, 'handle_remove_from_waitlist'));
        add_action('admin_post_fs_change_signup_status', array(__CLASS__, 'handle_change_signup_status'));

        // Debug: Log all admin_init requests
        add_action('admin_init', array(__CLASS__, 'debug_admin_init'), 1);
    }

    public static function debug_admin_init() {
        if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'fs_manual_signup') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DEBUG admin_init: fs_manual_signup request detected');
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('POST data: ' . print_r($_POST, true));
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Has volunteer_id: ' . (isset($_POST['volunteer_id']) ? 'YES' : 'NO'));
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Has team_id: ' . (isset($_POST['team_id']) ? 'YES' : 'NO'));
            }

            // Check if the hook is registered
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Hook has_action admin_post_fs_manual_signup: ' . (has_action('admin_post_fs_manual_signup') ? 'YES' : 'NO'));
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Current user can manage_options: ' . (current_user_can('manage_options') ? 'YES' : 'NO'));
            }
        }
    }
    
    public static function add_menu_pages() {
        // All signups (visible in menu)
        add_submenu_page(
            'friendshyft',
            'All Signups',
            'Signups',
            'manage_options',
            'fs-signups',
            array(__CLASS__, 'all_signups_page')
        );

        // View signups for an opportunity (hidden from menu)
        add_submenu_page(
            null, // Hidden from menu
            'Manage Signups',
            'Manage Signups',
            'manage_options',
            'fs-manage-signups',
            array(__CLASS__, 'manage_signups_page')
        );
    }

    public static function all_signups_page() {
        global $wpdb;

        // Handle messages
        if (isset($_GET['status_changed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Signup status changed successfully!</p></div>';
        }
        if (isset($_GET['error_message'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode($_GET['error_message'])) . '</p></div>';
        }

        // Get filter values
        $opportunity_filter = isset($_GET['opportunity']) ? intval($_GET['opportunity']) : 0;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Build query for individual signups
        $where = array('1=1');
        $params = array();

        if ($opportunity_filter) {
            $where[] = 's.opportunity_id = %d';
            $params[] = $opportunity_filter;
        }

        if ($status_filter) {
            $where[] = 's.status = %s';
            $params[] = $status_filter;
        }

        if ($search) {
            $where[] = '(v.name LIKE %s OR v.email LIKE %s OR o.title LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $where_clause = implode(' AND ', $where);

        // Get individual signups
        if (!empty($params)) {
            $signups = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, v.name as volunteer_name, v.email as volunteer_email,
                        o.title as opportunity_title, o.datetime_start, o.datetime_end
                 FROM {$wpdb->prefix}fs_signups s
                 LEFT JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
                 LEFT JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 WHERE $where_clause
                 ORDER BY o.datetime_start DESC, v.name ASC",
                ...$params
            ));
        } else {
            $signups = $wpdb->get_results(
                "SELECT s.*, v.name as volunteer_name, v.email as volunteer_email,
                        o.title as opportunity_title, o.datetime_start, o.datetime_end
                 FROM {$wpdb->prefix}fs_signups s
                 LEFT JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
                 LEFT JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 WHERE $where_clause
                 ORDER BY o.datetime_start DESC, v.name ASC"
            );
        }

        // Get team signups
        $team_signups = $wpdb->get_results(
            "SELECT ts.*, t.name as team_name, o.title as opportunity_title,
                    o.datetime_start, o.datetime_end
             FROM {$wpdb->prefix}fs_team_signups ts
             LEFT JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             ORDER BY o.datetime_start DESC, t.name ASC"
        );

        // Get opportunities for filter
        $opportunities = $wpdb->get_results(
            "SELECT id, title, datetime_start FROM {$wpdb->prefix}fs_opportunities
             ORDER BY datetime_start DESC LIMIT 100"
        );

        // Get unique statuses
        $statuses = $wpdb->get_col(
            "SELECT DISTINCT status FROM {$wpdb->prefix}fs_signups
             WHERE status IS NOT NULL ORDER BY status"
        );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">All Signups</h1>
            <hr class="wp-header-end">

            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px;">
                    <input type="hidden" name="page" value="fs-signups">

                    <select name="opportunity" onchange="this.form.submit()">
                        <option value="">All Opportunities</option>
                        <?php foreach ($opportunities as $opp): ?>
                            <option value="<?php echo $opp->id; ?>" <?php selected($opportunity_filter, $opp->id); ?>>
                                <?php echo esc_html($opp->title . ' - ' . date('M j, Y', strtotime($opp->datetime_start))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($status_filter, $status); ?>>
                                <?php echo esc_html($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search...">

                    <input type="submit" class="button" value="Search">

                    <?php if ($opportunity_filter || $status_filter || $search): ?>
                        <a href="<?php echo admin_url('admin.php?page=fs-signups'); ?>" class="button">Clear Filters</a>
                    <?php endif; ?>
                </form>
            </div>

            <h2>Individual Signups (<?php echo count($signups); ?>)</h2>
            <?php if (empty($signups)): ?>
                <p>No individual signups found.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Volunteer</th>
                            <th>Email</th>
                            <th>Opportunity</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($signups as $signup): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $signup->volunteer_id); ?>">
                                        <?php echo esc_html($signup->volunteer_name); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($signup->volunteer_email); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-opportunity&id=' . $signup->opportunity_id); ?>">
                                        <?php echo esc_html($signup->opportunity_title); ?>
                                    </a>
                                </td>
                                <td><?php echo date('M j, Y g:i A', strtotime($signup->datetime_start)); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr(strtolower($signup->status)); ?>">
                                        <?php echo esc_html($signup->status); ?>
                                    </span>
                                    <br>
                                    <small style="white-space: nowrap;">
                                        <?php
                                        $current_url = urlencode(remove_query_arg('status_changed', $_SERVER['REQUEST_URI']));
                                        if ($signup->status !== 'confirmed'):
                                        ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=confirmed&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as Confirmed">
                                                ✓ Confirm
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($signup->status !== 'no_show' && $signup->status !== 'confirmed'): ?> | <?php endif; ?>
                                        <?php if ($signup->status !== 'no_show'): ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=no_show&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as No Show" style="color: #d63638;">
                                                ✗ No Show
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($signup->status !== 'cancelled' && $signup->status !== 'no_show'): ?> | <?php endif; ?>
                                        <?php if ($signup->status !== 'cancelled'): ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=cancelled&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as Cancelled" style="color: #999;">
                                                − Cancel
                                            </a>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-manage-signups&opportunity_id=' . $signup->opportunity_id); ?>" class="button button-small">
                                        Manage
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top: 40px;">Team Signups (<?php echo count($team_signups); ?>)</h2>
            <?php if (empty($team_signups)): ?>
                <p>No team signups found.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Opportunity</th>
                            <th>Date</th>
                            <th>People Count</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_signups as $signup): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-team&id=' . $signup->team_id); ?>">
                                        <?php echo esc_html($signup->team_name); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-opportunity&id=' . $signup->opportunity_id); ?>">
                                        <?php echo esc_html($signup->opportunity_title); ?>
                                    </a>
                                </td>
                                <td><?php echo date('M j, Y g:i A', strtotime($signup->datetime_start)); ?></td>
                                <td><?php echo intval($signup->people_count); ?> people</td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-manage-signups&opportunity_id=' . $signup->opportunity_id); ?>" class="button button-small">
                                        Manage
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function manage_signups_page() {
        global $wpdb;

        $opportunity_id = isset($_GET['opportunity_id']) ? intval($_GET['opportunity_id']) : 0;
        
        if (!$opportunity_id) {
            wp_die('Invalid opportunity');
        }
        
        // Handle messages
        if (isset($_GET['status_changed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Signup status changed successfully!</p></div>';
        }
        if (isset($_GET['signup_added'])) {
            echo '<div class="notice notice-success"><p>Volunteer added successfully!</p></div>';
        }
        if (isset($_GET['team_added'])) {
            $message = 'Team added successfully!';
            if (isset($_GET['merged'])) {
                $merged_names = urldecode($_GET['merged']);
                $message .= ' (Merged individual signups for: ' . esc_html($merged_names) . ')';
            }
            echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
        }
        if (isset($_GET['signup_removed'])) {
            echo '<div class="notice notice-success"><p>Signup removed successfully!</p></div>';
        }
        if (isset($_GET['waitlist_added'])) {
            echo '<div class="notice notice-success"><p>Volunteer added to waitlist successfully!</p></div>';
        }
        if (isset($_GET['waitlist_promoted'])) {
            echo '<div class="notice notice-success"><p>Volunteer promoted from waitlist and added to opportunity!</p></div>';
        }
        if (isset($_GET['waitlist_removed'])) {
            echo '<div class="notice notice-success"><p>Volunteer removed from waitlist!</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'duplicate_team') {
            echo '<div class="notice notice-error"><p>This team is already signed up for this shift/opportunity.</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'member_conflicts') {
            $message = isset($_GET['conflict_message']) ? urldecode($_GET['conflict_message']) : 'Team has member conflicts.';
            echo '<div class="notice notice-warning"><p>' . wp_kses_post($message) . '</p>';

            // Show confirmation buttons if we have conflict data
            if (isset($_GET['team_id']) && isset($_GET['available_size'])) {
                $team_id = intval($_GET['team_id']);
                $shift_id = isset($_GET['shift_id']) ? intval($_GET['shift_id']) : null;
                $available_size = intval($_GET['available_size']);

                echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="display: inline-block; margin-right: 10px;">';
                wp_nonce_field('fs_manual_signup', '_wpnonce_team');
                echo '<input type="hidden" name="action" value="fs_manual_signup">';
                echo '<input type="hidden" name="opportunity_id" value="' . $opportunity_id . '">';
                echo '<input type="hidden" name="team_id" value="' . $team_id . '">';
                if ($shift_id) {
                    echo '<input type="hidden" name="shift_id" value="' . $shift_id . '">';
                }
                echo '<input type="hidden" name="team_size" value="' . $available_size . '">';
                echo '<input type="hidden" name="force_signup" value="1">';
                echo '<button type="submit" class="button button-primary">Yes, Sign Up with ' . $available_size . ' Member(s)</button>';
                echo '</form>';

                echo '<a href="' . admin_url('admin.php?page=fs-manage-signups&opportunity_id=' . $opportunity_id) . '" class="button">Cancel</a>';
            }

            echo '</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'team_failed') {
            $message = isset($_GET['error_message']) ? urldecode($_GET['error_message']) : 'Failed to add team. The opportunity may not accept teams or may be at capacity.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'failed') {
            $message = isset($_GET['error_message']) ? urldecode($_GET['error_message']) : 'Failed to add volunteer.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'already_waitlisted') {
            echo '<div class="notice notice-error"><p>This volunteer is already on the waitlist for this opportunity.</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'already_signed_up') {
            echo '<div class="notice notice-error"><p>This volunteer is already signed up for this opportunity.</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'waitlist_failed') {
            echo '<div class="notice notice-error"><p>Failed to add volunteer to waitlist.</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'promote_failed') {
            $message = isset($_GET['error_message']) ? urldecode($_GET['error_message']) : 'Failed to promote volunteer from waitlist.';
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'remove_failed') {
            echo '<div class="notice notice-error"><p>Failed to remove volunteer from waitlist.</p></div>';
        }
        if (isset($_GET['error_message']) && !isset($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(urldecode($_GET['error_message'])) . '</p></div>';
        }
        
        // Get opportunity details
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, t.title as template_title
             FROM {$wpdb->prefix}fs_opportunities o
             LEFT JOIN {$wpdb->prefix}fs_opportunity_templates t ON o.template_id = t.id
             WHERE o.id = %d",
            $opportunity_id
        ));
        
        if (!$opportunity) {
            wp_die('Opportunity not found');
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Opportunity $opportunity_id: spots_filled = {$opportunity->spots_filled}, spots_available = {$opportunity->spots_available}");
        }

        // Get shifts if any
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts 
             WHERE opportunity_id = %d 
             ORDER BY display_order ASC",
            $opportunity_id
        ));
        
        // Get current individual signups
        $individual_signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, v.name as volunteer_name, v.email, v.phone, v.volunteer_status,
                    sh.shift_start_time, sh.shift_end_time,
                    'individual' as signup_type
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
             WHERE s.opportunity_id = %d
             AND s.status IN ('confirmed', 'pending')
             ORDER BY sh.display_order ASC, v.name ASC",
            $opportunity_id
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Individual signups query returned: " . count($individual_signups) . " rows");
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Individual signups: " . print_r($individual_signups, true));
        }

        // Debug: Check ALL signups regardless of status
        $all_statuses = $wpdb->get_results($wpdb->prepare(
            "SELECT id, volunteer_id, opportunity_id, shift_id, status FROM {$wpdb->prefix}fs_signups WHERE opportunity_id = %d",
            $opportunity_id
        ));
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("ALL signups (any status) for opportunity $opportunity_id: " . print_r($all_statuses, true));
        }

        // Get current team signups
        $team_signups = $wpdb->get_results($wpdb->prepare(
            "SELECT ts.*, t.name as team_name, ts.scheduled_size,
                    sh.shift_start_time, sh.shift_end_time,
                    'team' as signup_type, ts.id as signup_id
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON ts.shift_id = sh.id
             WHERE ts.opportunity_id = %d
             AND ts.status != 'cancelled'
             ORDER BY sh.display_order ASC, t.name ASC",
            $opportunity_id
        ));

        // Merge signups
        $signups = array_merge($individual_signups, $team_signups);
        
        // Group signups by shift
        $signups_by_shift = array();
        foreach ($signups as $signup) {
            $shift_key = $signup->shift_id ?: 0;
            if (!isset($signups_by_shift[$shift_key])) {
                $signups_by_shift[$shift_key] = array();
            }
            $signups_by_shift[$shift_key][] = $signup;
        }
        
        // Get ALL active volunteers (will filter per-shift or per-opportunity later)
        $all_volunteers = $wpdb->get_results(
            "SELECT id, name, email, volunteer_status
             FROM {$wpdb->prefix}fs_volunteers
             WHERE volunteer_status = 'Active'
             ORDER BY name ASC"
        );

        // Get ALL active teams (will filter per-shift or per-opportunity later)
        // Calculate actual team size: 1 (leader) + number of members
        $all_teams = $wpdb->get_results(
            "SELECT t.id, t.name,
                    (1 + COALESCE((SELECT COUNT(*) FROM {$wpdb->prefix}fs_team_members m WHERE m.team_id = t.id), 0)) as team_size
             FROM {$wpdb->prefix}fs_teams t
             WHERE t.status = 'active'
             ORDER BY t.name ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>
                Manage Signups
                <a href="<?php echo admin_url('admin.php?page=fs-edit-opportunity&id=' . $opportunity_id); ?>" class="page-title-action">Edit Opportunity</a>
            </h1>
            
            <div class="opportunity-header" style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2><?php echo esc_html($opportunity->title); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                    <div>
                        <strong>Date:</strong><br>
                        <?php echo date('l, F j, Y', strtotime($opportunity->event_date)); ?>
                    </div>
                    <div>
                        <strong>Location:</strong><br>
                        <?php echo esc_html($opportunity->location ?: '—'); ?>
                    </div>
                    <div>
                        <strong>Capacity:</strong><br>
                        <?php echo $opportunity->spots_filled; ?> / <?php echo $opportunity->spots_available; ?> filled
                    </div>
                    <div>
                        <strong>Status:</strong><br>
                        <span class="status-badge status-<?php echo strtolower($opportunity->status); ?>">
                            <?php echo esc_html($opportunity->status); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($shifts)): ?>
                <!-- Shift-based signups -->
                <?php foreach ($shifts as $shift): ?>
                    <?php
                    $shift_signups = $signups_by_shift[$shift->id] ?? array();
                    $shift_available = $shift->spots_available - $shift->spots_filled;
                    ?>
                    <div class="shift-section" style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                        <h3>
                            Shift: <?php echo date('g:i A', strtotime($shift->shift_start_time)); ?> - <?php echo date('g:i A', strtotime($shift->shift_end_time)); ?>
                            <span style="font-weight: normal; font-size: 14px; color: #666;">
                                (<?php echo $shift->spots_filled; ?> / <?php echo $shift->spots_available; ?> filled)
                            </span>
                        </h3>
                        
                        <?php if (!empty($shift_signups)): ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th>Volunteer</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shift_signups as $signup): ?>
                                        <tr>
                                            <td>
                                                <?php if ($signup->signup_type === 'team'): ?>
                                                    <strong>👥 <?php echo esc_html($signup->team_name); ?></strong>
                                                    <br><small>(Team - <?php echo (int)$signup->scheduled_size; ?> people)</small>
                                                <?php else: ?>
                                                    <strong><?php echo esc_html($signup->volunteer_name); ?></strong>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($signup->signup_type === 'team'): ?>
                                                    —
                                                <?php else: ?>
                                                    <a href="mailto:<?php echo esc_attr($signup->email); ?>">
                                                        <?php echo esc_html($signup->email); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($signup->signup_type === 'team'): ?>
                                                    —
                                                <?php else: ?>
                                                    <?php echo esc_html($signup->phone ?: '—'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($signup->signup_type === 'team'): ?>
                                                    <span class="status-badge status-active">Team</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-<?php echo strtolower($signup->status); ?>">
                                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $signup->status))); ?>
                                                    </span>
                                                    <br>
                                                    <small style="white-space: nowrap;">
                                                        <?php
                                                        $current_url = urlencode(remove_query_arg('status_changed', $_SERVER['REQUEST_URI']));
                                                        if ($signup->status !== 'confirmed'):
                                                        ?>
                                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=confirmed&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as Confirmed">
                                                                ✓ Confirm
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($signup->status !== 'no_show' && $signup->status !== 'confirmed'): ?> | <?php endif; ?>
                                                        <?php if ($signup->status !== 'no_show'): ?>
                                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=no_show&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as No Show" style="color: #d63638;">
                                                                ✗ No Show
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($signup->status !== 'cancelled' && $signup->status !== 'no_show'): ?> | <?php endif; ?>
                                                        <?php if ($signup->status !== 'cancelled'): ?>
                                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=cancelled&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as Cancelled" style="color: #999;">
                                                                − Cancel
                                                            </a>
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($signup->signup_type === 'team'): ?>
                                                    <a href="<?php echo admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $signup->team_id); ?>">View Team</a> |
                                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_remove_team_signup&signup_id=' . $signup->signup_id . '&opportunity_id=' . $opportunity_id), 'fs_remove_team_signup'); ?>"
                                                       onclick="return confirm('Remove this team from the opportunity?');"
                                                       style="color: #b32d2e;">Remove</a>
                                                <?php else: ?>
                                                    <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $signup->volunteer_id); ?>">View</a> |
                                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_remove_signup&signup_id=' . $signup->id . '&opportunity_id=' . $opportunity_id), 'fs_remove_signup'); ?>"
                                                       onclick="return confirm('Remove this volunteer from the opportunity?');"
                                                       style="color: #b32d2e;">Remove</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color: #666; font-style: italic;">No signups yet for this shift</p>
                        <?php endif; ?>
                        
                        <?php if ($shift_available > 0): ?>
                            <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                                <h4 style="margin: 0 0 10px 0;">Add to This Shift</h4>

                                <!-- Add Individual Volunteer -->
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: end; margin-bottom: 15px;">
                                    <input type="hidden" name="_wpnonce_shift_<?php echo $shift->id; ?>" value="<?php echo wp_create_nonce('fs_manual_signup_' . $shift->id); ?>">
                                    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'])); ?>">
                                    <input type="hidden" name="action" value="fs_manual_signup">
                                    <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">
                                    <input type="hidden" name="shift_id" value="<?php echo $shift->id; ?>">
                                    <div>
                                        <label for="volunteer_id_<?php echo $shift->id; ?>" style="display: block; margin-bottom: 5px;"><strong>Individual Volunteer:</strong></label>
                                        <select name="volunteer_id" id="volunteer_id_<?php echo $shift->id; ?>" required style="min-width: 300px;">
                                            <option value="">— Choose Volunteer —</option>
                                            <?php
                                            // Get volunteers already signed up for THIS shift
                                            $shift_volunteer_ids = array_map(function($s) {
                                                return $s->signup_type === 'individual' ? $s->volunteer_id : null;
                                            }, $shift_signups);
                                            $shift_volunteer_ids = array_filter($shift_volunteer_ids);

                                            // Show only volunteers NOT signed up for this shift
                                            foreach ($all_volunteers as $vol):
                                                if (!in_array($vol->id, $shift_volunteer_ids)):
                                            ?>
                                                <option value="<?php echo $vol->id; ?>">
                                                    <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                                </option>
                                            <?php
                                                endif;
                                            endforeach;
                                            ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="button button-primary">Add Volunteer</button>
                                </form>

                                <!-- Add Team -->
                                <?php if ($opportunity->allow_team_signups && !empty($all_teams)): ?>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: end;">
                                        <input type="hidden" name="_wpnonce_shift_team_<?php echo $shift->id; ?>" value="<?php echo wp_create_nonce('fs_manual_signup_' . $shift->id); ?>">
                                        <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'])); ?>">
                                        <input type="hidden" name="action" value="fs_manual_signup">
                                        <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">
                                        <input type="hidden" name="shift_id" value="<?php echo $shift->id; ?>">
                                        <div>
                                            <label for="team_id_<?php echo $shift->id; ?>" style="display: block; margin-bottom: 5px;"><strong>Team:</strong></label>
                                            <select name="team_id" id="team_id_<?php echo $shift->id; ?>" required style="min-width: 250px;" onchange="document.getElementById('team_size_<?php echo $shift->id; ?>').value = this.options[this.selectedIndex].dataset.size || '';">
                                                <option value="">— Choose Team —</option>
                                                <?php
                                                // Get teams already signed up for THIS shift
                                                $shift_team_ids = array_map(function($s) {
                                                    return $s->signup_type === 'team' ? $s->team_id : null;
                                                }, $shift_signups);
                                                $shift_team_ids = array_filter($shift_team_ids);

                                                // Show only teams NOT signed up for this shift
                                                foreach ($all_teams as $team):
                                                    if (!in_array($team->id, $shift_team_ids)):
                                                ?>
                                                    <option value="<?php echo $team->id; ?>" data-size="<?php echo $team->team_size; ?>">
                                                        <?php echo esc_html($team->name); ?> (<?php echo $team->team_size; ?> people)
                                                    </option>
                                                <?php
                                                    endif;
                                                endforeach;
                                                ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="team_size_<?php echo $shift->id; ?>" style="display: block; margin-bottom: 5px;"><strong>Team Size:</strong></label>
                                            <input type="number" name="team_size" id="team_size_<?php echo $shift->id; ?>" min="1" max="<?php echo $shift_available; ?>" required style="width: 80px;">
                                        </div>
                                        <button type="submit" class="button button-secondary">Add Team</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                                <p style="color: #d9534f; font-weight: bold; margin: 0;">This shift is at capacity (<?php echo $shift->spots_filled; ?>/<?php echo $shift->spots_available; ?>)</p>
                            </div>
                        <?php endif; ?>

                        <!-- Waitlist Section for Shift -->
                        <?php
                        $shift_waitlist_entries = FS_Waitlist_Manager::get_waitlist($opportunity_id, $shift->id);
                        $shift_is_full = $shift->spots_filled >= $shift->spots_available;
                        ?>

                        <div style="margin-top: 30px; padding: 15px; background: #fff9e6; border-left: 4px solid #f0ad4e; border-radius: 4px;">
                            <h4 style="margin: 0 0 10px 0; color: #856404;">
                                📋 Waitlist
                                <?php if ($shift_is_full): ?>
                                    <span style="color: #d9534f; font-weight: normal; font-size: 14px;">(Shift is at capacity)</span>
                                <?php endif; ?>
                            </h4>

                            <?php if (!empty($shift_waitlist_entries)): ?>
                                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px;">Position</th>
                                            <th>Volunteer Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th style="width: 100px;">Rank Score</th>
                                            <th style="width: 150px;">Joined</th>
                                            <th style="width: 150px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $position = 1; foreach ($shift_waitlist_entries as $entry): ?>
                                            <tr>
                                                <td><strong>#<?php echo $position++; ?></strong></td>
                                                <td><?php echo esc_html($entry->name); ?></td>
                                                <td><a href="mailto:<?php echo esc_attr($entry->email); ?>"><?php echo esc_html($entry->email); ?></a></td>
                                                <td><?php echo esc_html($entry->phone ?: '—'); ?></td>
                                                <td><?php echo esc_html($entry->rank_score); ?></td>
                                                <td><?php echo date('M j, Y g:i A', strtotime($entry->joined_at)); ?></td>
                                                <td>
                                                    <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $entry->volunteer_id); ?>">View</a> |
                                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_promote_from_waitlist&waitlist_id=' . $entry->id . '&opportunity_id=' . $opportunity_id), 'fs_promote_waitlist'); ?>"
                                                       style="color: #28a745; font-weight: bold;">Promote</a> |
                                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_remove_from_waitlist&waitlist_id=' . $entry->id . '&opportunity_id=' . $opportunity_id), 'fs_remove_waitlist'); ?>"
                                                       onclick="return confirm('Remove from waitlist?');"
                                                       style="color: #b32d2e;">Remove</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p style="color: #666; font-style: italic; margin-top: 10px;">No volunteers on waitlist for this shift</p>
                            <?php endif; ?>

                            <!-- Add to Waitlist Form for Shift -->
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                                <h5 style="margin: 0 0 10px 0;">Add to Waitlist</h5>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: end;">
                                    <input type="hidden" name="_wpnonce_waitlist" value="<?php echo wp_create_nonce('fs_add_to_waitlist'); ?>">
                                    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'])); ?>">
                                    <input type="hidden" name="action" value="fs_add_to_waitlist">
                                    <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">
                                    <input type="hidden" name="shift_id" value="<?php echo $shift->id; ?>">
                                    <div>
                                        <label for="waitlist_volunteer_id_shift_<?php echo $shift->id; ?>" style="display: block; margin-bottom: 5px;"><strong>Volunteer:</strong></label>
                                        <select name="volunteer_id" id="waitlist_volunteer_id_shift_<?php echo $shift->id; ?>" required style="min-width: 300px;">
                                            <option value="">— Choose Volunteer —</option>
                                            <?php
                                            // Get volunteers already signed up OR on waitlist for this shift
                                            $shift_volunteer_ids = array_map(function($s) {
                                                return $s->signup_type === 'individual' ? $s->volunteer_id : null;
                                            }, $shift_signups);
                                            $shift_volunteer_ids = array_filter($shift_volunteer_ids);
                                            $shift_waitlist_volunteer_ids = array_map(function($w) {
                                                return $w->volunteer_id;
                                            }, $shift_waitlist_entries);
                                            $excluded_shift_ids = array_merge($shift_volunteer_ids, $shift_waitlist_volunteer_ids);

                                            // Show only volunteers NOT already signed up or on waitlist
                                            foreach ($all_volunteers as $vol):
                                                if (!in_array($vol->id, $excluded_shift_ids)):
                                            ?>
                                                <option value="<?php echo $vol->id; ?>">
                                                    <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                                </option>
                                            <?php
                                                endif;
                                            endforeach;
                                            ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="button button-secondary">Add to Waitlist</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
            <?php else: ?>
                <!-- No shifts - single opportunity -->
                <div class="signups-section" style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h3>Volunteer Signups</h3>
                    
                    <?php if (!empty($signups)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Volunteer</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Signup Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($signups as $signup): ?>
                                    <tr>
                                        <td>
                                            <?php if ($signup->signup_type === 'team'): ?>
                                                <strong>👥 <?php echo esc_html($signup->team_name); ?></strong>
                                                <br><small>(Team - <?php echo (int)$signup->scheduled_size; ?> people)</small>
                                            <?php else: ?>
                                                <strong><?php echo esc_html($signup->volunteer_name); ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($signup->signup_type === 'team'): ?>
                                                —
                                            <?php else: ?>
                                                <a href="mailto:<?php echo esc_attr($signup->email); ?>">
                                                    <?php echo esc_html($signup->email); ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($signup->signup_type === 'team'): ?>
                                                —
                                            <?php else: ?>
                                                <?php echo esc_html($signup->phone ?: '—'); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($signup->signup_type === 'team'): ?>
                                                <span class="status-badge status-active">Team</span>
                                            <?php else: ?>
                                                <span class="status-badge status-<?php echo strtolower($signup->status); ?>">
                                                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $signup->status))); ?>
                                                </span>
                                                <br>
                                                <small style="white-space: nowrap;">
                                                    <?php
                                                    $current_url = urlencode(remove_query_arg('status_changed', $_SERVER['REQUEST_URI']));
                                                    if ($signup->status !== 'confirmed'):
                                                    ?>
                                                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=confirmed&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as Confirmed">
                                                            ✓ Confirm
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($signup->status !== 'no_show' && $signup->status !== 'confirmed'): ?> | <?php endif; ?>
                                                    <?php if ($signup->status !== 'no_show'): ?>
                                                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=no_show&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as No Show" style="color: #d63638;">
                                                            ✗ No Show
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($signup->status !== 'cancelled' && $signup->status !== 'no_show'): ?> | <?php endif; ?>
                                                    <?php if ($signup->status !== 'cancelled'): ?>
                                                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_change_signup_status&signup_id=' . $signup->id . '&new_status=cancelled&redirect_to=' . $current_url), 'fs_change_signup_status_' . $signup->id); ?>" title="Mark as Cancelled" style="color: #999;">
                                                            − Cancel
                                                        </a>
                                                    <?php endif; ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($signup->signup_date)); ?></td>
                                        <td>
                                            <?php if ($signup->signup_type === 'team'): ?>
                                                <a href="<?php echo admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $signup->team_id); ?>">View Team</a> |
                                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_remove_team_signup&signup_id=' . $signup->signup_id . '&opportunity_id=' . $opportunity_id), 'fs_remove_team_signup'); ?>"
                                                   onclick="return confirm('Remove this team from the opportunity?');"
                                                   style="color: #b32d2e;">Remove</a>
                                            <?php else: ?>
                                                <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $signup->volunteer_id); ?>">View</a> |
                                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_remove_signup&signup_id=' . $signup->id . '&opportunity_id=' . $opportunity_id), 'fs_remove_signup'); ?>"
                                                   onclick="return confirm('Remove this volunteer from the opportunity?');"
                                                   style="color: #b32d2e;">Remove</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color: #666; font-style: italic;">No signups yet</p>
                    <?php endif; ?>
                    
                    <?php if ($opportunity->spots_filled < $opportunity->spots_available): ?>
                        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                            <h4 style="margin: 0 0 10px 0;">Add to Opportunity</h4>

                            <!-- Add Individual Volunteer -->
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: end; margin-bottom: 15px;">
                                <input type="hidden" name="_wpnonce" value="<?php echo wp_create_nonce('fs_manual_signup'); ?>">
                                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'])); ?>">
                                <input type="hidden" name="action" value="fs_manual_signup">
                                <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">
                                <div>
                                    <label for="volunteer_id" style="display: block; margin-bottom: 5px;"><strong>Individual Volunteer:</strong></label>
                                    <select name="volunteer_id" id="volunteer_id" required style="min-width: 300px;">
                                        <option value="">— Choose Volunteer —</option>
                                        <?php
                                        // Get volunteers already signed up for this opportunity
                                        $opp_volunteer_ids = array_map(function($s) {
                                            return $s->volunteer_id;
                                        }, $individual_signups);

                                        // Show only volunteers NOT already signed up
                                        foreach ($all_volunteers as $vol):
                                            if (!in_array($vol->id, $opp_volunteer_ids)):
                                        ?>
                                            <option value="<?php echo $vol->id; ?>">
                                                <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                            </option>
                                        <?php
                                            endif;
                                        endforeach;
                                        ?>
                                    </select>
                                </div>
                                <button type="submit" class="button button-primary">Add Volunteer</button>
                            </form>

                            <!-- Add Team -->
                            <?php if ($opportunity->allow_team_signups && !empty($all_teams)): ?>
                                <?php $available = $opportunity->spots_available - $opportunity->spots_filled; ?>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: end;">
                                    <input type="hidden" name="_wpnonce_team" value="<?php echo wp_create_nonce('fs_manual_signup'); ?>">
                                    <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'])); ?>">
                                    <input type="hidden" name="action" value="fs_manual_signup">
                                    <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">
                                    <div>
                                        <label for="team_id" style="display: block; margin-bottom: 5px;"><strong>Team:</strong></label>
                                        <select name="team_id" id="team_id" required style="min-width: 250px;" onchange="document.getElementById('team_size').value = this.options[this.selectedIndex].dataset.size || '';">
                                            <option value="">— Choose Team —</option>
                                            <?php
                                            // Get teams already signed up for this opportunity
                                            $opp_team_ids = array_map(function($s) {
                                                return $s->team_id;
                                            }, $team_signups);

                                            // Show only teams NOT already signed up
                                            foreach ($all_teams as $team):
                                                if (!in_array($team->id, $opp_team_ids)):
                                            ?>
                                                <option value="<?php echo $team->id; ?>" data-size="<?php echo $team->team_size; ?>">
                                                    <?php echo esc_html($team->name); ?> (<?php echo $team->team_size; ?> people)
                                                </option>
                                            <?php
                                                endif;
                                            endforeach;
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="team_size" style="display: block; margin-bottom: 5px;"><strong>Team Size:</strong></label>
                                        <input type="number" name="team_size" id="team_size" min="1" max="<?php echo $available; ?>" required style="width: 80px;">
                                    </div>
                                    <button type="submit" class="button button-secondary">Add Team</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                            <p style="color: #d9534f; font-weight: bold; margin: 0;">This opportunity is at capacity (<?php echo $opportunity->spots_filled; ?>/<?php echo $opportunity->spots_available; ?>)</p>
                        </div>
                    <?php endif; ?>

                    <!-- Waitlist Section -->
                    <?php
                    $waitlist_entries = FS_Waitlist_Manager::get_waitlist($opportunity_id);
                    $is_full = $opportunity->spots_filled >= $opportunity->spots_available;
                    ?>

                    <div style="margin-top: 30px; padding: 15px; background: #fff9e6; border-left: 4px solid #f0ad4e; border-radius: 4px;">
                        <h4 style="margin: 0 0 10px 0; color: #856404;">
                            📋 Waitlist
                            <?php if ($is_full): ?>
                                <span style="color: #d9534f; font-weight: normal; font-size: 14px;">(Opportunity is at capacity)</span>
                            <?php endif; ?>
                        </h4>

                        <?php if (!empty($waitlist_entries)): ?>
                            <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">Position</th>
                                        <th>Volunteer Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th style="width: 100px;">Rank Score</th>
                                        <th style="width: 150px;">Joined</th>
                                        <th style="width: 150px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $position = 1; foreach ($waitlist_entries as $entry): ?>
                                        <tr>
                                            <td><strong>#<?php echo $position++; ?></strong></td>
                                            <td><?php echo esc_html($entry->name); ?></td>
                                            <td><a href="mailto:<?php echo esc_attr($entry->email); ?>"><?php echo esc_html($entry->email); ?></a></td>
                                            <td><?php echo esc_html($entry->phone ?: '—'); ?></td>
                                            <td><?php echo esc_html($entry->rank_score); ?></td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($entry->joined_at)); ?></td>
                                            <td>
                                                <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $entry->volunteer_id); ?>">View</a> |
                                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_promote_from_waitlist&waitlist_id=' . $entry->id . '&opportunity_id=' . $opportunity_id), 'fs_promote_waitlist'); ?>"
                                                   style="color: #28a745; font-weight: bold;">Promote</a> |
                                                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_remove_from_waitlist&waitlist_id=' . $entry->id . '&opportunity_id=' . $opportunity_id), 'fs_remove_waitlist'); ?>"
                                                   onclick="return confirm('Remove from waitlist?');"
                                                   style="color: #b32d2e;">Remove</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color: #666; font-style: italic; margin-top: 10px;">No volunteers on waitlist</p>
                        <?php endif; ?>

                        <!-- Add to Waitlist Form -->
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                            <h5 style="margin: 0 0 10px 0;">Add to Waitlist</h5>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: end;">
                                <input type="hidden" name="_wpnonce_waitlist" value="<?php echo wp_create_nonce('fs_add_to_waitlist'); ?>">
                                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr(wp_unslash($_SERVER['REQUEST_URI'])); ?>">
                                <input type="hidden" name="action" value="fs_add_to_waitlist">
                                <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">
                                <div>
                                    <label for="waitlist_volunteer_id" style="display: block; margin-bottom: 5px;"><strong>Volunteer:</strong></label>
                                    <select name="volunteer_id" id="waitlist_volunteer_id" required style="min-width: 300px;">
                                        <option value="">— Choose Volunteer —</option>
                                        <?php
                                        // Get volunteers already signed up OR on waitlist
                                        $opp_volunteer_ids = array_map(function($s) {
                                            return $s->volunteer_id;
                                        }, $individual_signups);
                                        $waitlist_volunteer_ids = array_map(function($w) {
                                            return $w->volunteer_id;
                                        }, $waitlist_entries);
                                        $excluded_ids = array_merge($opp_volunteer_ids, $waitlist_volunteer_ids);

                                        // Show only volunteers NOT already signed up or on waitlist
                                        foreach ($all_volunteers as $vol):
                                            if (!in_array($vol->id, $excluded_ids)):
                                        ?>
                                            <option value="<?php echo $vol->id; ?>">
                                                <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                            </option>
                                        <?php
                                            endif;
                                        endforeach;
                                        ?>
                                    </select>
                                </div>
                                <button type="submit" class="button button-secondary">Add to Waitlist</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <style>
                .status-badge {
                    padding: 4px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 600;
                }
                .status-active { background: #d4edda; color: #155724; }
                .status-inactive { background: #f8d7da; color: #721c24; }
                .status-pending { background: #fff3cd; color: #856404; }
                .status-open { background: #d4edda; color: #155724; }
                .status-closed { background: #f8d7da; color: #721c24; }
                .status-full { background: #fff3cd; color: #856404; }
                .status-confirmed { background: #d4edda; color: #155724; }
                .status-no_show { background: #f8d7da; color: #721c24; }
                .status-cancelled { background: #e2e3e5; color: #383d41; }
            </style>
        </div>
        <?php
    }
    
    public static function handle_manual_signup() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('DEBUG handle_manual_signup: HANDLER REACHED!');
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('POST at handler: ' . print_r($_POST, true));
        }

        // Verify permissions first
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Get shift_id to determine which nonce to verify (safe to access before nonce check)
        $shift_id = isset($_POST['shift_id']) ? intval($_POST['shift_id']) : null;

        // Verify the appropriate nonce - try all possible nonce formats
        $nonce_verified = false;

        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Shift ID: ' . ($shift_id ?: 'NULL'));
        }

        if ($shift_id) {
            // Check shift-specific volunteer nonce
            $nonce_name = '_wpnonce_shift_' . $shift_id;
            $nonce_action = 'fs_manual_signup_' . $shift_id;
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Checking nonce: $nonce_name with action: $nonce_action");
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Nonce value from POST: " . (isset($_POST[$nonce_name]) ? $_POST[$nonce_name] : 'NOT SET'));
            }
            if (isset($_POST[$nonce_name]) && wp_verify_nonce($_POST[$nonce_name], $nonce_action)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("VOLUNTEER NONCE VERIFIED!");
                }
                $nonce_verified = true;
            }

            // Check shift-specific team nonce
            if (!$nonce_verified) {
                $team_nonce_name = '_wpnonce_shift_team_' . $shift_id;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Checking team nonce: $team_nonce_name with action: $nonce_action");
                }
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Team nonce value from POST: " . (isset($_POST[$team_nonce_name]) ? $_POST[$team_nonce_name] : 'NOT SET'));
                }
                if (isset($_POST[$team_nonce_name]) && wp_verify_nonce($_POST[$team_nonce_name], $nonce_action)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("TEAM NONCE VERIFIED!");
                    }
                    $nonce_verified = true;
                }
            }
        }

        // Check standard volunteer nonce
        if (!$nonce_verified && isset($_POST['_wpnonce'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Checking standard volunteer nonce");
            }
            if (wp_verify_nonce($_POST['_wpnonce'], 'fs_manual_signup')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("STANDARD VOLUNTEER NONCE VERIFIED!");
                }
                $nonce_verified = true;
            }
        }

        // Check team nonce
        if (!$nonce_verified && isset($_POST['_wpnonce_team'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Checking standard team nonce");
            }
            if (wp_verify_nonce($_POST['_wpnonce_team'], 'fs_manual_signup')) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("STANDARD TEAM NONCE VERIFIED!");
                }
                $nonce_verified = true;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Final nonce_verified status: " . ($nonce_verified ? 'TRUE' : 'FALSE'));
        }

        if (!$nonce_verified) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("NONCE VERIFICATION FAILED - wp_die will be called");
            }
            wp_die(
                'Security check failed. The page may have expired. Please refresh the page and try again.',
                'Security Check Failed',
                array('response' => 403, 'back_link' => true)
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("After nonce check - continuing with signup processing");
        }

        // Now safely access all POST data after nonce verification
        $opportunity_id = intval($_POST['opportunity_id']);
        $is_team = isset($_POST['team_id']) && !empty($_POST['team_id']);

        // Debug logging - write to plugin directory
        file_put_contents(
            FRIENDSHYFT_PLUGIN_DIR . 'debug.log',
            date('Y-m-d H:i:s') . " Manual signup debug:\n" .
            "is_team: " . ($is_team ? 'YES' : 'NO') . "\n" .
            "POST data: " . print_r($_POST, true) . "\n\n",
            FILE_APPEND
        );

        // Handle team signup
        if ($is_team) {
            $team_id = intval($_POST['team_id']);
            $team_size = intval($_POST['team_size']);

            global $wpdb;

            // Check if team already signed up
            $existing_where = array(
                $wpdb->prepare('team_id = %d', $team_id),
                $wpdb->prepare('opportunity_id = %d', $opportunity_id),
                "status != 'cancelled'"
            );

            if ($shift_id) {
                $existing_where[] = $wpdb->prepare('shift_id = %d', $shift_id);
            }

            $existing = $wpdb->get_var(
                "SELECT id FROM {$wpdb->prefix}fs_team_signups
                 WHERE " . implode(' AND ', $existing_where)
            );

            if ($existing) {
                wp_redirect(add_query_arg(
                    array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'duplicate_team'),
                    admin_url('admin.php')
                ));
                exit;
            }

            // Check for member conflicts BEFORE creating signup
            $conflicts = FS_Team_Signup::check_team_member_conflicts($team_id, $opportunity_id, $shift_id);

            // If there are unavailable members and no force flag, show warning
            if (!empty($conflicts['unavailable']) && !isset($_POST['force_signup'])) {
                // Build conflict message
                $conflict_msg = 'The following team members are unavailable:<br><ul>';
                foreach ($conflicts['unavailable'] as $u) {
                    $conflict_msg .= '<li>' . esc_html($u['name']) . ' - ' . esc_html($u['reason']) . '</li>';
                }
                $conflict_msg .= '</ul>';

                if (!empty($conflicts['merged'])) {
                    $merged_names = array_map(function($m) { return $m['name']; }, $conflicts['merged']);
                    $conflict_msg .= '<br><strong>Will merge individual signups for:</strong> ' . implode(', ', $merged_names);
                }

                $available_count = $team_size - count($conflicts['unavailable']);
                $conflict_msg .= '<br><br><strong>Do you want to sign up with ' . $available_count . ' member(s) instead of ' . $team_size . '?</strong>';

                wp_redirect(add_query_arg(
                    array(
                        'page' => 'fs-manage-signups',
                        'opportunity_id' => $opportunity_id,
                        'error' => 'member_conflicts',
                        'conflict_message' => urlencode($conflict_msg),
                        'team_id' => $team_id,
                        'shift_id' => $shift_id,
                        'original_size' => $team_size,
                        'available_size' => $available_count
                    ),
                    admin_url('admin.php')
                ));
                exit;
            }

            // Adjust team size if force_signup and there are unavailable members
            if (isset($_POST['force_signup']) && !empty($conflicts['unavailable'])) {
                $team_size = $team_size - count($conflicts['unavailable']);
            }

            // Create team signup
            $signup_data = array(
                'team_id' => $team_id,
                'opportunity_id' => $opportunity_id,
                'shift_id' => $shift_id,
                'scheduled_size' => $team_size,
                'signup_date' => current_time('mysql'),
                'status' => 'scheduled'
            );

            $result = FS_Team_Signup::create_signup($signup_data);

            if (is_wp_error($result)) {
                // Check if error is due to member conflicts
                if ($result->get_error_code() === 'member_conflicts') {
                    $conflict_data = $result->get_error_data();

                    // Build conflict message
                    $conflict_msg = 'Team signup has conflicts:<br>';

                    if (!empty($conflict_data['merged'])) {
                        $merged_names = array_map(function($m) { return $m['name']; }, $conflict_data['merged']);
                        $conflict_msg .= '<strong>Will merge individual signups:</strong> ' . implode(', ', $merged_names) . '<br>';
                    }

                    if (!empty($conflict_data['unavailable'])) {
                        $unavailable_list = array();
                        foreach ($conflict_data['unavailable'] as $u) {
                            $unavailable_list[] = $u['name'] . ' (' . $u['reason'] . ')';
                        }
                        $conflict_msg .= '<strong>Unavailable members:</strong> ' . implode(', ', $unavailable_list);
                    }

                    wp_redirect(add_query_arg(
                        array(
                            'page' => 'fs-manage-signups',
                            'opportunity_id' => $opportunity_id,
                            'error' => 'member_conflicts',
                            'conflict_message' => urlencode($conflict_msg),
                            'conflict_data' => urlencode(json_encode($conflict_data))
                        ),
                        admin_url('admin.php')
                    ));
                    exit;
                }

                // Other error
                wp_redirect(add_query_arg(
                    array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'team_failed', 'error_message' => urlencode($result->get_error_message())),
                    admin_url('admin.php')
                ));
                exit;
            }

            // Success - handle merging and update counts
            $conflicts = FS_Team_Signup::check_team_member_conflicts($team_id, $opportunity_id, $shift_id);

            // Get team and opportunity details for audit log
            $team = $wpdb->get_row($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}fs_teams WHERE id = %d",
                $team_id
            ));
            $opportunity = $wpdb->get_row($wpdb->prepare(
                "SELECT title, event_date FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
                $opportunity_id
            ));

            // Log team signup creation
            if ($team && $opportunity) {
                FS_Audit_Log::log('team_signup_created', 'team_signup', $result, array(
                    'team_name' => $team->name,
                    'opportunity_title' => $opportunity->title,
                    'event_date' => $opportunity->event_date,
                    'scheduled_size' => $team_size,
                    'shift_id' => $shift_id
                ));
            }

            // Merge individual signups if any
            if (!empty($conflicts['merged'])) {
                $merged_ids = array_map(function($m) { return $m['signup_id']; }, $conflicts['merged']);
                FS_Team_Signup::merge_individual_signups($result, $merged_ids);

                // Decrement spots_filled for each merged individual (since they're now part of team count)
                $merge_count = count($merged_ids);
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}fs_opportunities
                     SET spots_filled = spots_filled - %d
                     WHERE id = %d",
                    $merge_count,
                    $opportunity_id
                ));

                if ($shift_id) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->prefix}fs_opportunity_shifts
                         SET spots_filled = spots_filled - %d
                         WHERE id = %d",
                        $merge_count,
                        $shift_id
                    ));
                }
            }

            // Update opportunity spots
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fs_opportunities
                 SET spots_filled = spots_filled + %d
                 WHERE id = %d",
                $team_size,
                $opportunity_id
            ));

            // Update shift spots if applicable
            if ($shift_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}fs_opportunity_shifts
                     SET spots_filled = spots_filled + %d
                     WHERE id = %d",
                    $team_size,
                    $shift_id
                ));
            }

            $redirect_args = array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'team_added' => '1');

            if (!empty($conflicts['merged'])) {
                $merged_names = array_map(function($m) { return $m['name']; }, $conflicts['merged']);
                $redirect_args['merged'] = urlencode(implode(', ', $merged_names));
            }

            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }

        // Handle individual volunteer signup
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("=== VOLUNTEER SIGNUP SECTION ===");
        }
        $volunteer_id = intval($_POST['volunteer_id']);
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Volunteer ID: $volunteer_id");
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Opportunity ID: $opportunity_id");
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Shift ID: " . ($shift_id ?: 'NULL'));
        }

        global $wpdb;

        $registration_id = null;
        $signup_status = 'confirmed';
        if (class_exists('FS_Event_Registrations')) {
            $registration = FS_Event_Registrations::find_active_registration_for_opportunity($volunteer_id, $opportunity_id);
            if ($registration) {
                $registration_id = (int) $registration->id;
                FS_Event_Registrations::reconcile_registration_entries($registration_id);
                if (!in_array($registration->permission_status, array(FS_Event_Registrations::PERMISSION_SIGNED, FS_Event_Registrations::PERMISSION_NOT_REQUIRED), true)) {
                    $signup_status = 'pending';
                }
            }
        }

        // Check if already signed up
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND opportunity_id = %d AND status IN ('confirmed', 'pending')",
            $volunteer_id,
            $opportunity_id
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Existing signup check: " . ($existing ? "FOUND (ID: $existing)" : "NONE"));
        }

        if ($existing) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("DUPLICATE SIGNUP - redirecting with error");
            }
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'duplicate'),
                admin_url('admin.php')
            ));
            exit;
        }

        // Create signup
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Calling FS_Signup::create()");
        }
        $result = FS_Signup::create($volunteer_id, $opportunity_id, $shift_id, $signup_status, $registration_id);
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("FS_Signup::create() result: " . print_r($result, true));
        }

        if ($result['success']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("SUCCESS - redirecting with signup_added=1");
            }
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'signup_added' => '1'),
                admin_url('admin.php')
            ));
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FAILED - redirecting with error=failed. Message: " . $result['message']);
            }
            wp_redirect(add_query_arg(
                array(
                    'page' => 'fs-manage-signups',
                    'opportunity_id' => $opportunity_id,
                    'error' => 'failed',
                    'error_message' => urlencode($result['message'])
                ),
                admin_url('admin.php')
            ));
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("About to exit");
        }
        exit;
    }
    
    public static function handle_remove_signup() {
        check_admin_referer('fs_remove_signup');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $signup_id = intval($_GET['signup_id']);
        $opportunity_id = intval($_GET['opportunity_id']);

        global $wpdb;

        // Get signup details
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));

        if ($signup) {
            // Cancel the signup
            FS_Signup::cancel($signup_id, $signup->volunteer_id);
        }

        wp_redirect(add_query_arg(
            array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'signup_removed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_remove_team_signup() {
        check_admin_referer('fs_remove_team_signup');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $signup_id = intval($_GET['signup_id']);
        $opportunity_id = intval($_GET['opportunity_id']);

        global $wpdb;

        // Get team signup details with team and opportunity info for audit log
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT ts.*, t.name as team_name, o.title as opportunity_title, o.event_date
             FROM {$wpdb->prefix}fs_team_signups ts
             LEFT JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             WHERE ts.id = %d",
            $signup_id
        ));

        if ($signup) {
            // Cancel the team signup
            $result = FS_Team_Signup::cancel_signup($signup_id);

            if (!is_wp_error($result)) {
                // Log team signup removal
                FS_Audit_Log::log('team_signup_cancelled', 'team_signup', $signup_id, array(
                    'team_name' => $signup->team_name,
                    'opportunity_title' => $signup->opportunity_title,
                    'event_date' => $signup->event_date,
                    'scheduled_size' => $signup->scheduled_size,
                    'shift_id' => $signup->shift_id
                ));

                // Update opportunity spots if needed
                $team_size = intval($signup->scheduled_size);

                // Update the opportunity spots_filled count
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}fs_opportunities
                     SET spots_filled = GREATEST(0, spots_filled - %d)
                     WHERE id = %d",
                    $team_size,
                    $opportunity_id
                ));

                // Update shift spots_filled if applicable
                if ($signup->shift_id) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->prefix}fs_opportunity_shifts
                         SET spots_filled = GREATEST(0, spots_filled - %d)
                         WHERE id = %d",
                        $team_size,
                        $signup->shift_id
                    ));
                }
            }
        }

        wp_redirect(add_query_arg(
            array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'signup_removed' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_add_to_waitlist() {
        check_admin_referer('fs_add_to_waitlist', '_wpnonce_waitlist');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $volunteer_id = isset($_POST['volunteer_id']) ? intval($_POST['volunteer_id']) : 0;
        $opportunity_id = isset($_POST['opportunity_id']) ? intval($_POST['opportunity_id']) : 0;
        $shift_id = isset($_POST['shift_id']) ? intval($_POST['shift_id']) : null;

        if (!$volunteer_id || !$opportunity_id) {
            wp_die('Missing required parameters');
        }

        global $wpdb;

        $registration_id = null;
        if (class_exists('FS_Event_Registrations')) {
            $registration = FS_Event_Registrations::find_active_registration_for_opportunity($volunteer_id, $opportunity_id);
            if ($registration) {
                $registration_id = (int) $registration->id;
                FS_Event_Registrations::reconcile_registration_entries($registration_id);
            }
        }

        // Get volunteer details
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            wp_die('Volunteer not found');
        }

        // Check if already on waitlist
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_waitlist
             WHERE volunteer_id = %d AND opportunity_id = %d AND status = 'waiting'",
            $volunteer_id,
            $opportunity_id
        ));

        if ($existing) {
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'already_waitlisted'),
                admin_url('admin.php')
            ));
            exit;
        }

        // Check if already signed up
        $existing_signup = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND opportunity_id = %d AND status IN ('confirmed', 'pending')",
            $volunteer_id,
            $opportunity_id
        ));

        if ($existing_signup) {
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'already_signed_up'),
                admin_url('admin.php')
            ));
            exit;
        }

        // Calculate rank score
        $rank_score = self::calculate_waitlist_rank_score($volunteer_id);

        // Add to waitlist
        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_waitlist",
            array(
                'volunteer_id' => $volunteer_id,
                'opportunity_id' => $opportunity_id,
                'shift_id' => $shift_id,
                'registration_id' => $registration_id,
                'rank_score' => $rank_score,
                'priority_level' => 'normal',
                'joined_at' => current_time('mysql'),
                'status' => 'waiting'
            )
        );

        if ($result) {
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'waitlist_added' => '1'),
                admin_url('admin.php')
            ));
        } else {
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'waitlist_failed'),
                admin_url('admin.php')
            ));
        }
        exit;
    }

    public static function handle_promote_from_waitlist() {
        check_admin_referer('fs_promote_waitlist');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $waitlist_id = isset($_GET['waitlist_id']) ? intval($_GET['waitlist_id']) : 0;
        $opportunity_id = isset($_GET['opportunity_id']) ? intval($_GET['opportunity_id']) : 0;

        if (!$waitlist_id || !$opportunity_id) {
            wp_die('Missing required parameters');
        }

        global $wpdb;

        // Get waitlist entry
        $waitlist_entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_waitlist WHERE id = %d",
            $waitlist_id
        ));

        if (!$waitlist_entry) {
            wp_die('Waitlist entry not found');
        }

        if (empty($waitlist_entry->registration_id) && class_exists('FS_Event_Registrations')) {
            $registration = FS_Event_Registrations::find_active_registration_for_opportunity((int) $waitlist_entry->volunteer_id, $opportunity_id);
            if ($registration) {
                FS_Event_Registrations::reconcile_registration_entries((int) $registration->id);
                $wpdb->update(
                    "{$wpdb->prefix}fs_waitlist",
                    array('registration_id' => (int) $registration->id),
                    array('id' => $waitlist_id),
                    array('%d'),
                    array('%d')
                );
                $waitlist_entry->registration_id = (int) $registration->id;
            }
        }

        if (!empty($waitlist_entry->registration_id) && class_exists('FS_Event_Registrations')) {
            $promote_result = FS_Event_Registrations::promote_waitlist_entry($waitlist_id);
            if (is_wp_error($promote_result)) {
                wp_redirect(add_query_arg(
                    array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'promote_failed', 'error_message' => urlencode($promote_result->get_error_message())),
                    admin_url('admin.php')
                ));
                exit;
            }

            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'waitlist_promoted' => '1'),
                admin_url('admin.php')
            ));
            exit;
        }

        // Legacy path for non-registration waitlist entries.
        $result = FS_Signup::create($waitlist_entry->volunteer_id, $opportunity_id, $waitlist_entry->shift_id);

        if ($result['success']) {
            // Mark as promoted
            $wpdb->update(
                "{$wpdb->prefix}fs_waitlist",
                array(
                    'status' => 'promoted',
                    'promoted_at' => current_time('mysql')
                ),
                array('id' => $waitlist_id)
            );

            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'waitlist_promoted' => '1'),
                admin_url('admin.php')
            ));
        } else {
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'promote_failed', 'error_message' => urlencode($result['message'])),
                admin_url('admin.php')
            ));
        }
        exit;
    }

    public static function handle_remove_from_waitlist() {
        check_admin_referer('fs_remove_waitlist');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $waitlist_id = isset($_GET['waitlist_id']) ? intval($_GET['waitlist_id']) : 0;
        $opportunity_id = isset($_GET['opportunity_id']) ? intval($_GET['opportunity_id']) : 0;

        if (!$waitlist_id || !$opportunity_id) {
            wp_die('Missing required parameters');
        }

        global $wpdb;

        $result = $wpdb->update(
            "{$wpdb->prefix}fs_waitlist",
            array('status' => 'removed'),
            array('id' => $waitlist_id)
        );

        if ($result !== false) {
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'waitlist_removed' => '1'),
                admin_url('admin.php')
            ));
        } else {
            wp_redirect(add_query_arg(
                array('page' => 'fs-manage-signups', 'opportunity_id' => $opportunity_id, 'error' => 'remove_failed'),
                admin_url('admin.php')
            ));
        }
        exit;
    }

    private static function calculate_waitlist_rank_score($volunteer_id) {
        global $wpdb;

        $score = 0;

        // Factor 1: Number of completed signups (max 100 points)
        $completed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND status = 'confirmed'",
            $volunteer_id
        ));
        $score += min($completed_count * 5, 100);

        // Factor 2: Hours volunteered (max 100 points)
        $total_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records
             WHERE volunteer_id = %d",
            $volunteer_id
        ));
        $score += min($total_hours * 2, 100);

        // Factor 3: No-show rate (subtract up to 50 points)
        $no_shows = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND status = 'no_show'",
            $volunteer_id
        ));
        $score -= min($no_shows * 10, 50);

        // Factor 4: Badge count (5 points per badge, max 50)
        $badge_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_badges
             WHERE volunteer_id = %d",
            $volunteer_id
        ));
        $score += min($badge_count * 5, 50);

        return max($score, 0); // Don't go negative
    }

    public static function handle_change_signup_status() {
        if (!isset($_GET['signup_id']) || !isset($_GET['new_status'])) {
            wp_die('Missing required parameters');
        }

        $signup_id = intval($_GET['signup_id']);
        $new_status = sanitize_text_field($_GET['new_status']);

        check_admin_referer('fs_change_signup_status_' . $signup_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Validate status
        $valid_statuses = array('confirmed', 'no_show', 'cancelled');
        if (!in_array($new_status, $valid_statuses)) {
            wp_die('Invalid status');
        }

        global $wpdb;

        // Get signup details before updating
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, v.name as volunteer_name, v.birthdate as birthdate, o.title as opportunity_title
             FROM {$wpdb->prefix}fs_signups s
             LEFT JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.id = %d",
            $signup_id
        ));

        if (!$signup) {
            wp_die('Signup not found');
        }

        $old_status = $signup->status;

        if ($new_status === 'confirmed' && class_exists('FS_Event_Registrations')) {
            $gate = FS_Event_Registrations::should_block_confirmation($signup);
            if (!empty($gate['blocked'])) {
                $redirect_url = isset($_GET['redirect_to']) ? urldecode($_GET['redirect_to']) : admin_url('admin.php?page=fs-signups');
                $redirect_url = add_query_arg('error_message', urlencode($gate['message']), $redirect_url);
                wp_redirect($redirect_url);
                exit;
            }
        }

        // Update status
        $wpdb->update(
            "{$wpdb->prefix}fs_signups",
            array('status' => $new_status),
            array('id' => $signup_id),
            array('%s'),
            array('%d')
        );

        // Log the status change
        FS_Audit_Log::log('signup_status_changed', 'signup', $signup_id, array(
            'volunteer_name' => $signup->volunteer_name,
            'opportunity_title' => $signup->opportunity_title,
            'old_status' => $old_status,
            'new_status' => $new_status
        ));

        // Redirect back
        $redirect_url = isset($_GET['redirect_to']) ? urldecode($_GET['redirect_to']) : admin_url('admin.php?page=fs-signups');
        $redirect_url = add_query_arg('status_changed', '1', $redirect_url);

        wp_redirect($redirect_url);
        exit;
    }
}

// Initialization is handled in main plugin file (friendshyft.php)
// to ensure proper hook registration timing
