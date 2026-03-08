<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Achievements_Dashboard {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 25);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_action('admin_post_fs_award_badge', array(__CLASS__, 'handle_award_badge'));
        add_action('admin_post_fs_remove_badge', array(__CLASS__, 'handle_remove_badge'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Achievements Dashboard',
            'Achievements',
            'manage_options',
            'friendshyft-achievements',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function enqueue_scripts($hook) {
        if ($hook !== 'friendshyft_page_friendshyft-achievements') {
            return;
        }
        
        wp_enqueue_style('friendshyft-achievements-admin', 
            plugins_url('../css/admin-achievements.css', __FILE__), 
            array(), 
            '1.0.0'
        );
    }
    
    public static function render_page() {
        global $wpdb;
        
        // Get filter parameters
        $time_period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30days';
        $badge_type = isset($_GET['badge_type']) ? sanitize_text_field($_GET['badge_type']) : 'all';
        
        // Calculate date range
        $date_filter = '';
        switch ($time_period) {
            case '7days':
                $date_filter = "AND earned_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case '30days':
                $date_filter = "AND earned_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case '90days':
                $date_filter = "AND earned_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
                break;
            case 'year':
                $date_filter = "AND earned_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                break;
            case 'all':
            default:
                $date_filter = '';
                break;
        }
        
        // Get overall stats
        $stats = self::get_overall_stats($date_filter, $badge_type);
        
        // Get badge leaderboard
        $leaderboard = self::get_badge_leaderboard($date_filter, $badge_type, 10);
        
        // Get recent badge awards
        $recent_badges = self::get_recent_badges(20);
        
        // Get attendance confirmation stats
        $confirmation_stats = self::get_confirmation_stats();
        
        // Get upcoming opportunities needing attention
        $needs_attention = self::get_opportunities_needing_attention();
        
        ?>
        <div class="wrap friendshyft-achievements-dashboard">
            <h1>
                🏆 Achievements Dashboard
                <span class="page-subtitle">Volunteer Engagement & Recognition</span>
            </h1>

            <!-- Messages -->
            <?php if (isset($_GET['badge_awarded'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Badge awarded successfully!</p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['badge_removed'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Badge removed successfully!</p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['badge_exists'])): ?>
                <div class="notice notice-warning is-dismissible">
                    <p>Volunteer already has this badge.</p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p>An error occurred. Please try again.</p>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="dashboard-filters">
                <form method="get" action="">
                    <input type="hidden" name="page" value="friendshyft-achievements">
                    
                    <select name="period" onchange="this.form.submit()">
                        <option value="7days" <?php selected($time_period, '7days'); ?>>Last 7 Days</option>
                        <option value="30days" <?php selected($time_period, '30days'); ?>>Last 30 Days</option>
                        <option value="90days" <?php selected($time_period, '90days'); ?>>Last 90 Days</option>
                        <option value="year" <?php selected($time_period, 'year'); ?>>Last Year</option>
                        <option value="all" <?php selected($time_period, 'all'); ?>>All Time</option>
                    </select>
                    
                    <select name="badge_type" onchange="this.form.submit()">
                        <option value="all" <?php selected($badge_type, 'all'); ?>>All Badge Types</option>
                        <option value="hours" <?php selected($badge_type, 'hours'); ?>>Time Champion</option>
                        <option value="signups" <?php selected($badge_type, 'signups'); ?>>Commitment Star</option>
                        <option value="streak" <?php selected($badge_type, 'streak'); ?>>Consistency Champion</option>
                        <option value="anniversary" <?php selected($badge_type, 'anniversary'); ?>>Anniversary</option>
                        <option value="mentor" <?php selected($badge_type, 'mentor'); ?>>Mentor</option>
                        <option value="early_bird" <?php selected($badge_type, 'early_bird'); ?>>Early Bird</option>
                    </select>
                </form>
            </div>
            
            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">🏆</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats->total_badges); ?></div>
                        <div class="stat-label">Total Badges Awarded</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats->volunteers_with_badges); ?></div>
                        <div class="stat-label">Volunteers with Badges</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo number_format($stats->avg_badges, 1); ?></div>
                        <div class="stat-label">Avg Badges per Volunteer</div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">✓</div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $confirmation_stats->confirmation_rate; ?>%</div>
                        <div class="stat-label">Attendance Confirmation Rate</div>
                    </div>
                </div>
            </div>

            <!-- Badge Management Section -->
            <div class="dashboard-section" style="margin-bottom: 20px; background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                <h2>🎖️ Manual Badge Management</h2>

                <!-- Award Badge Form -->
                <div style="margin-bottom: 30px;">
                    <h3>Award Badge to Volunteer</h3>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
                        <?php wp_nonce_field('fs_award_badge'); ?>
                        <input type="hidden" name="action" value="fs_award_badge">

                        <div>
                            <label for="volunteer_id" style="display: block; margin-bottom: 5px;"><strong>Volunteer:</strong></label>
                            <select name="volunteer_id" id="volunteer_id" required style="min-width: 250px;">
                                <option value="">— Select Volunteer —</option>
                                <?php
                                $volunteers = $wpdb->get_results(
                                    "SELECT id, name, email FROM {$wpdb->prefix}fs_volunteers
                                     WHERE volunteer_status = 'Active'
                                     ORDER BY name ASC"
                                );
                                foreach ($volunteers as $v):
                                ?>
                                    <option value="<?php echo $v->id; ?>">
                                        <?php echo esc_html($v->name); ?> (<?php echo esc_html($v->email); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="badge_type" style="display: block; margin-bottom: 5px;"><strong>Badge Type:</strong></label>
                            <select name="badge_type" id="badge_type_select" required style="min-width: 200px;">
                                <option value="">— Select Badge Type —</option>
                                <?php
                                $badge_definitions = FS_Badges::get_badge_definitions();
                                foreach ($badge_definitions as $type => $def):
                                ?>
                                    <option value="<?php echo esc_attr($type); ?>" data-levels='<?php echo json_encode($def['levels']); ?>'>
                                        <?php echo esc_html($def['icon'] . ' ' . $def['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="badge_level" style="display: block; margin-bottom: 5px;"><strong>Badge Level:</strong></label>
                            <select name="badge_level" id="badge_level_select" required style="min-width: 200px;">
                                <option value="">— Select Badge Type First —</option>
                            </select>
                        </div>

                        <div>
                            <button type="submit" class="button button-primary">Award Badge</button>
                        </div>
                    </form>

                    <script>
                    jQuery(document).ready(function($) {
                        $('#badge_type_select').on('change', function() {
                            var levels = $(this).find('option:selected').data('levels');
                            var levelSelect = $('#badge_level_select');
                            levelSelect.empty();

                            if (levels) {
                                levelSelect.append('<option value="">— Select Level —</option>');
                                $.each(levels, function(key, level) {
                                    levelSelect.append(
                                        $('<option></option>')
                                            .val(key)
                                            .text(level.name)
                                    );
                                });
                            } else {
                                levelSelect.append('<option value="">— Select Badge Type First —</option>');
                            }
                        });
                    });
                    </script>
                </div>

                <!-- All Awarded Badges Table -->
                <div>
                    <h3>All Awarded Badges</h3>
                    <?php
                    $all_badges = $wpdb->get_results(
                        "SELECT b.*, v.name as volunteer_name, v.email as volunteer_email
                         FROM {$wpdb->prefix}fs_volunteer_badges b
                         LEFT JOIN {$wpdb->prefix}fs_volunteers v ON b.volunteer_id = v.id
                         ORDER BY b.earned_date DESC
                         LIMIT 100"
                    );
                    ?>

                    <?php if (empty($all_badges)): ?>
                        <p>No badges have been awarded yet.</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Volunteer</th>
                                    <th>Badge</th>
                                    <th>Level</th>
                                    <th>Earned Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_badges as $badge):
                                    $badge_definitions = FS_Badges::get_badge_definitions();
                                    $def = $badge_definitions[$badge->badge_type] ?? null;
                                    $level_def = $def['levels'][$badge->badge_level] ?? null;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($badge->volunteer_name); ?></strong>
                                            <br><small><?php echo esc_html($badge->volunteer_email); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($def): ?>
                                                <span style="font-size: 20px;"><?php echo $def['icon']; ?></span>
                                                <?php echo esc_html($def['name']); ?>
                                            <?php else: ?>
                                                <?php echo esc_html($badge->badge_type); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($level_def): ?>
                                                <strong><?php echo esc_html($level_def['name']); ?></strong>
                                            <?php else: ?>
                                                <?php echo esc_html($badge->badge_level); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($badge->earned_date)); ?></td>
                                        <td>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_remove_badge&badge_id=' . $badge->id), 'fs_remove_badge_' . $badge->id); ?>"
                                               onclick="return confirm('Are you sure you want to remove this badge from <?php echo esc_js($badge->volunteer_name); ?>?');"
                                               class="button button-small"
                                               style="color: #d63638;">
                                                Remove
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Two Column Layout -->
            <div class="dashboard-columns">
                
                <!-- Left Column -->
                <div class="dashboard-column">
                    
                    <!-- Badge Leaderboard -->
                    <div class="dashboard-section">
                        <h2>🎖️ Badge Leaderboard</h2>
                        <?php if (empty($leaderboard)): ?>
                            <p class="no-data">No badges awarded yet in this time period.</p>
                        <?php else: ?>
                            <table class="leaderboard-table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Volunteer</th>
                                        <th>Badges</th>
                                        <th>Recent Achievements</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rank = 1;
                                    foreach ($leaderboard as $entry): 
                                        $volunteer_badges = FS_Badges::get_volunteer_badges($entry->volunteer_id);
                                        $recent_three = array_slice($volunteer_badges, 0, 3);
                                    ?>
                                        <tr>
                                            <td class="rank-cell">
                                                <?php if ($rank === 1): ?>
                                                    <span class="rank-badge gold">🥇</span>
                                                <?php elseif ($rank === 2): ?>
                                                    <span class="rank-badge silver">🥈</span>
                                                <?php elseif ($rank === 3): ?>
                                                    <span class="rank-badge bronze">🥉</span>
                                                <?php else: ?>
                                                    <span class="rank-number"><?php echo $rank; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo admin_url('admin.php?page=friendshyft-volunteers&action=edit&id=' . $entry->volunteer_id); ?>">
                                                    <?php echo esc_html($entry->volunteer_name); ?>
                                                </a>
                                            </td>
                                            <td class="badge-count"><?php echo $entry->badge_count; ?></td>
                                            <td class="recent-badges">
                                                <?php foreach ($recent_three as $badge): ?>
                                                    <span class="mini-badge" title="<?php echo esc_attr($badge['name']); ?>">
                                                        <?php echo $badge['icon']; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php 
                                        $rank++;
                                    endforeach; 
                                    ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Badge Awards -->
                    <div class="dashboard-section">
                        <h2>🎉 Recent Badge Awards</h2>
                        <?php if (empty($recent_badges)): ?>
                            <p class="no-data">No recent badge awards.</p>
                        <?php else: ?>
                            <div class="recent-badges-list">
                                <?php foreach ($recent_badges as $badge): 
                                    $badge_definitions = FS_Badges::get_badge_definitions();
                                    $def = $badge_definitions[$badge->badge_type] ?? null;
                                    if (!$def) continue;
                                    $level_def = $def['levels'][$badge->badge_level] ?? null;
                                    if (!$level_def) continue;
                                ?>
                                    <div class="badge-award-item">
                                        <div class="badge-icon-large"><?php echo $def['icon']; ?></div>
                                        <div class="badge-award-details">
                                            <div class="badge-award-name"><?php echo esc_html($level_def['name']); ?></div>
                                            <div class="badge-award-volunteer">
                                                <a href="<?php echo admin_url('admin.php?page=friendshyft-volunteers&action=edit&id=' . $badge->volunteer_id); ?>">
                                                    <?php echo esc_html($badge->volunteer_name); ?>
                                                </a>
                                            </div>
                                            <div class="badge-award-date">
                                                <?php echo human_time_diff(strtotime($badge->earned_date), current_time('timestamp')); ?> ago
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
                
                <!-- Right Column -->
                <div class="dashboard-column">
                    
                    <!-- Attendance Confirmation Overview -->
                    <div class="dashboard-section">
                        <h2>✓ Attendance Confirmations</h2>
                        
                        <div class="confirmation-stats">
                            <div class="conf-stat">
                                <div class="conf-stat-value"><?php echo $confirmation_stats->upcoming_total; ?></div>
                                <div class="conf-stat-label">Upcoming Opportunities</div>
                            </div>
                            <div class="conf-stat">
                                <div class="conf-stat-value confirmed"><?php echo $confirmation_stats->confirmed; ?></div>
                                <div class="conf-stat-label">Confirmed</div>
                            </div>
                            <div class="conf-stat">
                                <div class="conf-stat-value pending"><?php echo $confirmation_stats->pending; ?></div>
                                <div class="conf-stat-label">Pending</div>
                            </div>
                        </div>
                        
                        <div class="confirmation-bar">
                            <div class="confirmation-fill" style="width: <?php echo $confirmation_stats->confirmation_rate; ?>%;">
                                <?php echo $confirmation_stats->confirmation_rate; ?>% Confirmed
                            </div>
                        </div>
                    </div>
                    
                    <!-- Opportunities Needing Attention -->
                    <div class="dashboard-section attention-section">
                        <h2>⚠️ Needs Attention</h2>
                        <?php if (empty($needs_attention)): ?>
                            <p class="no-data success">All upcoming opportunities are fully confirmed! 🎉</p>
                        <?php else: ?>
                            <div class="attention-list">
                                <?php foreach ($needs_attention as $opp): ?>
                                    <div class="attention-item">
                                        <div class="attention-header">
                                            <strong><?php echo esc_html($opp->role_name); ?></strong>
                                            <span class="attention-date">
                                                <?php echo date('M j, g:i A', strtotime($opp->event_date . ' ' . $opp->start_time)); ?>
                                            </span>
                                        </div>
                                        <div class="attention-details">
                                            <span class="attention-program"><?php echo esc_html($opp->program_name); ?></span>
                                            <span class="attention-status">
                                                <?php echo $opp->confirmed_count; ?>/<?php echo $opp->total_signups; ?> confirmed
                                            </span>
                                        </div>
                                        <div class="attention-volunteers">
                                            <?php 
                                            $unconfirmed = self::get_unconfirmed_volunteers($opp->id);
                                            if (!empty($unconfirmed)):
                                            ?>
                                                <strong>Pending:</strong>
                                                <?php 
                                                $names = array_map(function($v) {
                                                    return '<a href="' . admin_url('admin.php?page=friendshyft-volunteers&action=edit&id=' . $v->volunteer_id) . '">' . esc_html($v->volunteer_name) . '</a>';
                                                }, $unconfirmed);
                                                echo implode(', ', $names);
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Badge Type Breakdown -->
                    <div class="dashboard-section">
                        <h2>📊 Badge Distribution</h2>
                        <?php 
                        $badge_breakdown = self::get_badge_breakdown($date_filter);
                        if (empty($badge_breakdown)): 
                        ?>
                            <p class="no-data">No badges awarded yet.</p>
                        <?php else: ?>
                            <div class="badge-breakdown">
                                <?php 
                                $badge_definitions = FS_Badges::get_badge_definitions();
                                foreach ($badge_breakdown as $row): 
                                    $def = $badge_definitions[$row->badge_type] ?? null;
                                    if (!$def) continue;
                                ?>
                                    <div class="breakdown-item">
                                        <div class="breakdown-icon"><?php echo $def['icon']; ?></div>
                                        <div class="breakdown-details">
                                            <div class="breakdown-name"><?php echo esc_html($def['name']); ?></div>
                                            <div class="breakdown-count"><?php echo $row->count; ?> awarded</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
                
            </div>
            
        </div>
        <?php
    }
    
    /**
     * Get overall statistics
     */
    private static function get_overall_stats($date_filter, $badge_type) {
        global $wpdb;
        
        $badge_type_filter = ($badge_type !== 'all') ? $wpdb->prepare("AND badge_type = %s", $badge_type) : '';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_badges,
                COUNT(DISTINCT volunteer_id) as volunteers_with_badges,
                COUNT(*) / COUNT(DISTINCT volunteer_id) as avg_badges
            FROM {$wpdb->prefix}fs_volunteer_badges
            WHERE 1=1
            {$date_filter}
            {$badge_type_filter}
        ");
        
        return $stats;
    }
    
    /**
     * Get badge leaderboard
     */
    private static function get_badge_leaderboard($date_filter, $badge_type, $limit = 10) {
        global $wpdb;
        
        $badge_type_filter = ($badge_type !== 'all') ? $wpdb->prepare("AND b.badge_type = %s", $badge_type) : '';
        
        $leaderboard = $wpdb->get_results($wpdb->prepare("
            SELECT 
                v.id as volunteer_id,
                v.name as volunteer_name,
                COUNT(*) as badge_count
            FROM {$wpdb->prefix}fs_volunteer_badges b
            JOIN {$wpdb->prefix}fs_volunteers v ON b.volunteer_id = v.id
            WHERE 1=1
            {$date_filter}
            {$badge_type_filter}
            GROUP BY v.id, v.name
            ORDER BY badge_count DESC, v.name ASC
            LIMIT %d
        ", $limit));
        
        return $leaderboard;
    }
    
    /**
     * Get recent badge awards
     */
    private static function get_recent_badges($limit = 20) {
        global $wpdb;
        
        $badges = $wpdb->get_results($wpdb->prepare("
            SELECT b.*, v.name as volunteer_name
            FROM {$wpdb->prefix}fs_volunteer_badges b
            JOIN {$wpdb->prefix}fs_volunteers v ON b.volunteer_id = v.id
            ORDER BY b.earned_date DESC
            LIMIT %d
        ", $limit));
        
        return $badges;
    }
    
    /**
     * Get confirmation statistics
     */
    private static function get_confirmation_stats() {
        global $wpdb;
        
        // Get upcoming opportunities (next 7 days)
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as upcoming_total,
                SUM(CASE WHEN s.attendance_confirmed = 1 THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN s.attendance_confirmed = 0 THEN 1 ELSE 0 END) as pending
            FROM {$wpdb->prefix}fs_signups s
            JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
            WHERE s.status = 'confirmed'
            AND o.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        
        if ($stats->upcoming_total > 0) {
            $stats->confirmation_rate = round(($stats->confirmed / $stats->upcoming_total) * 100);
        } else {
            $stats->confirmation_rate = 100;
        }
        
        return $stats;
    }
    
    /**
     * Get opportunities needing attention
     */
    private static function get_opportunities_needing_attention() {
        global $wpdb;
        
        $opportunities = $wpdb->get_results("
            SELECT 
                o.id,
                o.event_date,
                o.start_time,
                r.name as role_name,
                p.name as program_name,
                COUNT(*) as total_signups,
                SUM(CASE WHEN s.attendance_confirmed = 1 THEN 1 ELSE 0 END) as confirmed_count
            FROM {$wpdb->prefix}fs_opportunities o
            JOIN {$wpdb->prefix}fs_roles r ON o.role_id = r.id
            JOIN {$wpdb->prefix}fs_programs p ON r.program_id = p.id
            JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id
            WHERE s.status = 'confirmed'
            AND o.event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            GROUP BY o.id, o.event_date, o.start_time, r.name, p.name
            HAVING confirmed_count < total_signups
            ORDER BY o.event_date, o.start_time
            LIMIT 10
        ");
        
        return $opportunities;
    }
    
    /**
     * Get unconfirmed volunteers for an opportunity
     */
    private static function get_unconfirmed_volunteers($opportunity_id) {
        global $wpdb;
        
        $volunteers = $wpdb->get_results($wpdb->prepare("
            SELECT v.id as volunteer_id, v.name as volunteer_name
            FROM {$wpdb->prefix}fs_signups s
            JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
            WHERE s.opportunity_id = %d
            AND s.status = 'confirmed'
            AND s.attendance_confirmed = 0
        ", $opportunity_id));
        
        return $volunteers;
    }
    
    /**
     * Get badge breakdown by type
     */
    private static function get_badge_breakdown($date_filter) {
        global $wpdb;
        
        $breakdown = $wpdb->get_results("
            SELECT 
                badge_type,
                COUNT(*) as count
            FROM {$wpdb->prefix}fs_volunteer_badges
            WHERE 1=1
            {$date_filter}
            GROUP BY badge_type
            ORDER BY count DESC
        ");
        
        return $breakdown;
    }

    /**
     * Handle manual badge award
     */
    public static function handle_award_badge() {
        check_admin_referer('fs_award_badge');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $volunteer_id = isset($_POST['volunteer_id']) ? intval($_POST['volunteer_id']) : 0;
        $badge_type = isset($_POST['badge_type']) ? sanitize_text_field($_POST['badge_type']) : '';
        $badge_level = isset($_POST['badge_level']) ? sanitize_text_field($_POST['badge_level']) : '';

        if (!$volunteer_id || !$badge_type || !$badge_level) {
            wp_die('Missing required parameters');
        }

        global $wpdb;

        // Get volunteer details
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            wp_die('Volunteer not found');
        }

        // Award the badge
        $awarded = FS_Badges::manual_award_badge($volunteer_id, $badge_type, $badge_level);

        if ($awarded) {
            // Get badge details for audit log
            $definitions = FS_Badges::get_badge_definitions();
            $badge_name = $definitions[$badge_type]['levels'][$badge_level]['name'] ?? 'Unknown Badge';

            // Log the manual badge award
            FS_Audit_Log::log('badge_awarded', 'volunteer', $volunteer_id, array(
                'volunteer_name' => $volunteer->name,
                'badge_type' => $badge_type,
                'badge_level' => $badge_level,
                'badge_name' => $badge_name,
                'source' => 'manual'
            ));

            wp_redirect(add_query_arg('badge_awarded', '1', admin_url('admin.php?page=friendshyft-achievements')));
        } else {
            wp_redirect(add_query_arg('badge_exists', '1', admin_url('admin.php?page=friendshyft-achievements')));
        }

        exit;
    }

    /**
     * Handle badge removal
     */
    public static function handle_remove_badge() {
        if (!isset($_GET['badge_id'])) {
            wp_die('Missing badge ID');
        }

        $badge_id = intval($_GET['badge_id']);

        check_admin_referer('fs_remove_badge_' . $badge_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        // Get full badge details before removal
        $badge_details = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, v.name as volunteer_name
             FROM {$wpdb->prefix}fs_volunteer_badges b
             LEFT JOIN {$wpdb->prefix}fs_volunteers v ON b.volunteer_id = v.id
             WHERE b.id = %d",
            $badge_id
        ));

        // Remove the badge
        $removed_badge = FS_Badges::remove_badge($badge_id);

        if ($removed_badge && $badge_details) {
            // Get badge name for audit log
            $definitions = FS_Badges::get_badge_definitions();
            $badge_name = $definitions[$removed_badge->badge_type]['levels'][$removed_badge->badge_level]['name'] ?? 'Unknown Badge';

            // Log the badge removal
            FS_Audit_Log::log('badge_removed', 'volunteer', $removed_badge->volunteer_id, array(
                'volunteer_name' => $badge_details->volunteer_name,
                'badge_type' => $removed_badge->badge_type,
                'badge_level' => $removed_badge->badge_level,
                'badge_name' => $badge_name,
                'earned_date' => $removed_badge->earned_date
            ));

            wp_redirect(add_query_arg('badge_removed', '1', admin_url('admin.php?page=friendshyft-achievements')));
        } else {
            wp_redirect(add_query_arg('error', '1', admin_url('admin.php?page=friendshyft-achievements')));
        }

        exit;
    }
}

FS_Admin_Achievements_Dashboard::init();
