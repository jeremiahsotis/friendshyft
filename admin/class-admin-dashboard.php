<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Dashboard {
    
    public static function render() {
        global $wpdb;
        
        // Get current stats
        $total_volunteers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteers WHERE volunteer_status = 'Active'"
        );
        
        $pending_volunteers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteers WHERE volunteer_status = 'Pending'"
        );
        
        $total_opportunities = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_opportunities 
             WHERE event_date >= CURDATE() AND status = 'Open'"
        );
        
        $total_signups = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups 
             WHERE status = 'confirmed'"
        );
        
        $pending_onboarding = $wpdb->get_var(
            "SELECT COUNT(DISTINCT volunteer_id) FROM {$wpdb->prefix}fs_progress WHERE completed = 0"
        );
        
        // Fill rate for upcoming opportunities (next 30 days)
        $fill_rate_data = $wpdb->get_row(
            "SELECT 
                SUM(spots_available) as total_spots,
                SUM(spots_filled) as filled_spots
             FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date >= CURDATE()
             AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             AND status = 'Open'"
        );
        
        $fill_rate = $fill_rate_data->total_spots > 0 
            ? round(($fill_rate_data->filled_spots / $fill_rate_data->total_spots) * 100) 
            : 0;
        
        // Overall fill rate (all upcoming)
        $overall_fill_data = $wpdb->get_row(
            "SELECT 
                SUM(spots_available) as total_spots,
                SUM(spots_filled) as filled_spots
             FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date >= CURDATE()
             AND status = 'Open'"
        );
        
        $overall_fill_rate = $overall_fill_data->total_spots > 0 
            ? round(($overall_fill_data->filled_spots / $overall_fill_data->total_spots) * 100) 
            : 0;
        
        // Coverage gaps - opportunities with low fill rate in next 14 days
        $coverage_gaps = $wpdb->get_results(
            "SELECT id, title, event_date, spots_available, spots_filled,
                    ROUND((spots_filled / spots_available) * 100) as fill_percentage
             FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date >= CURDATE()
             AND event_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
             AND status = 'Open'
             AND spots_available > 0
             AND (spots_filled / spots_available) < 0.5
             ORDER BY event_date ASC, fill_percentage ASC
             LIMIT 10"
        );
        
        // Upcoming fully staffed opportunities
        $fully_staffed = $wpdb->get_results(
            "SELECT id, title, event_date, spots_available, spots_filled
             FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date >= CURDATE()
             AND event_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
             AND status = 'Open'
             AND spots_filled >= spots_available
             ORDER BY event_date ASC
             LIMIT 5"
        );
        
        // Recent signups
        // Get recent individual signups
        $individual_signups = $wpdb->get_results(
            "SELECT s.signup_date, v.name as volunteer_name, o.title as opportunity_title, o.event_date,
                    'individual' as signup_type
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.status = 'confirmed'
             ORDER BY s.signup_date DESC
             LIMIT 10"
        );

        // Get recent team signups
        $team_signups = $wpdb->get_results(
            "SELECT ts.signup_date, t.name as team_name, o.title as opportunity_title, o.event_date,
                    ts.scheduled_size, 'team' as signup_type
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             JOIN {$wpdb->prefix}fs_opportunities o ON ts.opportunity_id = o.id
             WHERE ts.status != 'cancelled'
             ORDER BY ts.signup_date DESC
             LIMIT 10"
        );

        // Merge and sort by signup_date
        $recent_signups = array_merge($individual_signups, $team_signups);
        usort($recent_signups, function($a, $b) {
            return strcmp($b->signup_date, $a->signup_date);
        });
        $recent_signups = array_slice($recent_signups, 0, 10);
        
        // Top volunteers by signup count (last 90 days)
        $top_volunteers = $wpdb->get_results(
            "SELECT v.id, v.name, v.email, COUNT(s.id) as signup_count
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.status = 'confirmed'
             AND o.event_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
             GROUP BY v.id
             ORDER BY signup_count DESC
             LIMIT 5"
        );
        
        // Fill rate by program
        $fill_by_program = $wpdb->get_results(
            "SELECT 
                p.name as program_name,
                COUNT(DISTINCT o.id) as opportunity_count,
                SUM(o.spots_available) as total_spots,
                SUM(o.spots_filled) as filled_spots,
                CASE 
                    WHEN SUM(o.spots_available) > 0 THEN ROUND((SUM(o.spots_filled) / SUM(o.spots_available)) * 100)
                    ELSE 0
                END as fill_percentage
             FROM {$wpdb->prefix}fs_opportunities o
             JOIN {$wpdb->prefix}fs_opportunity_templates t ON o.template_id = t.id
             JOIN {$wpdb->prefix}fs_roles r ON JSON_CONTAINS(o.required_roles, CAST(r.id AS CHAR), '$')
             JOIN {$wpdb->prefix}fs_programs p ON r.program_id = p.id
             WHERE o.event_date >= CURDATE()
             AND o.event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             AND o.status = 'Open'
             GROUP BY p.id, p.name
             ORDER BY fill_percentage ASC, opportunity_count DESC
             LIMIT 10"
        );
        
        // Volunteer engagement trends (signups per week for last 8 weeks)
        $engagement_trends = $wpdb->get_results(
            "SELECT 
                YEARWEEK(s.signup_date, 1) as year_week,
                DATE_SUB(s.signup_date, INTERVAL WEEKDAY(s.signup_date) DAY) as week_start,
                COUNT(*) as signup_count
             FROM {$wpdb->prefix}fs_signups s
             WHERE s.signup_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
             AND s.status = 'confirmed'
             GROUP BY year_week, week_start
             ORDER BY year_week ASC"
        );
        
        // Template performance (recurring opportunities)
        $template_performance = $wpdb->get_results(
            "SELECT 
                t.title as template_name,
                t.template_type,
                COUNT(o.id) as opportunity_count,
                SUM(o.spots_available) as total_spots,
                SUM(o.spots_filled) as filled_spots,
                CASE 
                    WHEN SUM(o.spots_available) > 0 THEN ROUND((SUM(o.spots_filled) / SUM(o.spots_available)) * 100)
                    ELSE 0
                END as fill_percentage
             FROM {$wpdb->prefix}fs_opportunity_templates t
             JOIN {$wpdb->prefix}fs_opportunities o ON t.id = o.template_id
             WHERE o.event_date >= CURDATE()
             AND o.status = 'Open'
             AND t.status = 'Active'
             GROUP BY t.id, t.title, t.template_type
             ORDER BY opportunity_count DESC, fill_percentage ASC
             LIMIT 8"
        );
        
        ?>
        <div class="wrap">
            <h1>
                📊 FriendShyft Dashboard
                <span style="font-size: 14px; font-weight: normal; color: #666; margin-left: 15px;">
                    Last updated: <?php echo date('F j, Y g:i A'); ?>
                </span>
            </h1>
            
            <!-- Stats Cards -->
            <div class="fs-dashboard-stats">
                <div class="fs-stat-card">
                    <div class="fs-stat-number"><?php echo number_format($total_volunteers); ?></div>
                    <div class="fs-stat-label">Active Volunteers</div>
                    <?php if ($pending_volunteers > 0): ?>
                        <div class="fs-stat-detail">
                            <a href="<?php echo admin_url('admin.php?page=fs-volunteers&status_filter=Pending'); ?>">
                                +<?php echo $pending_volunteers; ?> pending
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="fs-stat-card">
                    <div class="fs-stat-number"><?php echo number_format($total_opportunities); ?></div>
                    <div class="fs-stat-label">Upcoming Opportunities</div>
                    <div class="fs-stat-detail">
                        <a href="<?php echo admin_url('admin.php?page=fs-opportunities'); ?>">View all</a>
                    </div>
                </div>
                
                <div class="fs-stat-card">
                    <div class="fs-stat-number" style="color: <?php echo $fill_rate >= 70 ? '#28a745' : ($fill_rate >= 50 ? '#ffc107' : '#dc3545'); ?>">
                        <?php echo $fill_rate; ?>%
                    </div>
                    <div class="fs-stat-label">Fill Rate (Next 30 Days)</div>
                    <div class="fs-stat-detail">Overall: <?php echo $overall_fill_rate; ?>%</div>
                </div>
                
                <div class="fs-stat-card">
                    <div class="fs-stat-number"><?php echo number_format($total_signups); ?></div>
                    <div class="fs-stat-label">Total Confirmed Signups</div>
                    <?php if ($pending_onboarding > 0): ?>
                        <div class="fs-stat-detail">
                            <a href="<?php echo admin_url('admin.php?page=fs-workflows'); ?>">
                                <?php echo $pending_onboarding; ?> in onboarding
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
                <!-- Coverage Gaps -->
                <div class="fs-dashboard-card">
                    <h2>⚠️ Coverage Gaps (Next 14 Days)</h2>
                    <p class="fs-card-description">Opportunities below 50% capacity that need attention</p>
                    <?php if (!empty($coverage_gaps)): ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>Opportunity</th>
                                    <th>Date</th>
                                    <th style="text-align: center;">Fill Rate</th>
                                    <th style="text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coverage_gaps as $gap): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($gap->title); ?></strong>
                                        </td>
                                        <td><?php echo date('D, M j', strtotime($gap->event_date)); ?></td>
                                        <td style="text-align: center;">
                                            <span style="color: #dc3545; font-weight: 600;">
                                                <?php echo $gap->spots_filled; ?>/<?php echo $gap->spots_available; ?>
                                                (<?php echo $gap->fill_percentage; ?>%)
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <a href="<?php echo admin_url('admin.php?page=fs-manage-signups&opportunity_id=' . $gap->id); ?>" class="button button-small">
                                                Manage
                                            </a>
                                            <button class="button button-small send-opportunity-reminder" data-opportunity-id="<?php echo $gap->id; ?>" style="margin-left: 5px;">
                                                📧 Remind
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="fs-empty-state">
                            <span style="font-size: 48px;">✓</span>
                            <p>All opportunities are well-staffed!</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Fully Staffed -->
                <?php if (!empty($fully_staffed)): ?>
                <div class="fs-dashboard-card">
                    <h2>✓ Fully Staffed</h2>
                    <p class="fs-card-description">Ready to go!</p>
                    <div class="fs-list-compact">
                        <?php foreach ($fully_staffed as $opp): ?>
                            <div class="fs-list-item">
                                <div class="fs-list-title"><?php echo esc_html($opp->title); ?></div>
                                <div class="fs-list-meta">
                                    <?php echo date('M j', strtotime($opp->event_date)); ?> • 
                                    <span style="color: #28a745; font-weight: 600;">
                                        <?php echo $opp->spots_filled; ?>/<?php echo $opp->spots_available; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <!-- Recent Signups -->
                <div class="fs-dashboard-card">
                    <h2>📋 Recent Signups</h2>
                    <?php if (!empty($recent_signups)): ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>Volunteer</th>
                                    <th>Opportunity</th>
                                    <th>Event Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_signups as $signup): ?>
                                    <tr>
                                        <td>
                                            <?php if ($signup->signup_type === 'team'): ?>
                                                <strong>👥 <?php echo esc_html($signup->team_name); ?></strong>
                                                <br><small>(<?php echo (int)$signup->scheduled_size; ?> people)</small>
                                            <?php else: ?>
                                                <?php echo esc_html($signup->volunteer_name); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($signup->opportunity_title); ?></td>
                                        <td><?php echo date('M j', strtotime($signup->event_date)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No recent signups</p>
                    <?php endif; ?>
                </div>
                
                <!-- Top Volunteers -->
                <?php if (!empty($top_volunteers)): ?>
                <div class="fs-dashboard-card">
                    <h2>⭐ Most Active Volunteers (Last 90 Days)</h2>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th>Volunteer</th>
                                <th style="text-align: center;">Signups</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_volunteers as $vol): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $vol->id); ?>">
                                            <?php echo esc_html($vol->name); ?>
                                        </a>
                                    </td>
                                    <td style="text-align: center;">
                                        <strong style="color: #0073aa; font-size: 18px;">
                                            <?php echo $vol->signup_count; ?>
                                        </strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($fill_by_program)): ?>
            <div class="fs-dashboard-card" style="margin-top: 20px;">
                <h2>📊 Fill Rate by Program (Next 30 Days)</h2>
                <p class="fs-card-description">Which programs are attracting volunteers?</p>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th style="text-align: center;">Opportunities</th>
                            <th style="text-align: center;">Total Spots</th>
                            <th style="text-align: center;">Filled</th>
                            <th style="text-align: center;">Fill Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fill_by_program as $program): ?>
                            <tr>
                                <td><strong><?php echo esc_html($program->program_name); ?></strong></td>
                                <td style="text-align: center;"><?php echo $program->opportunity_count; ?></td>
                                <td style="text-align: center;"><?php echo $program->total_spots; ?></td>
                                <td style="text-align: center;"><?php echo $program->filled_spots; ?></td>
                                <td style="text-align: center;">
                                    <span style="color: <?php echo $program->fill_percentage >= 70 ? '#28a745' : ($program->fill_percentage >= 50 ? '#ffc107' : '#dc3545'); ?>; font-weight: 600;">
                                        <?php echo $program->fill_percentage; ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($template_performance)): ?>
            <div class="fs-dashboard-card" style="margin-top: 20px;">
                <h2>🔁 Recurring Opportunity Performance</h2>
                <p class="fs-card-description">How are your templates doing?</p>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Template</th>
                            <th>Type</th>
                            <th style="text-align: center;">Active Opps</th>
                            <th style="text-align: center;">Total Spots</th>
                            <th style="text-align: center;">Fill Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($template_performance as $template): ?>
                            <tr>
                                <td><strong><?php echo esc_html($template->template_name); ?></strong></td>
                                <td><?php echo esc_html($template->template_type); ?></td>
                                <td style="text-align: center;"><?php echo $template->opportunity_count; ?></td>
                                <td style="text-align: center;"><?php echo $template->total_spots; ?></td>
                                <td style="text-align: center;">
                                    <span style="color: <?php echo $template->fill_percentage >= 70 ? '#28a745' : ($template->fill_percentage >= 50 ? '#ffc107' : '#dc3545'); ?>; font-weight: 600;">
                                        <?php echo $template->fill_percentage; ?>%
                                    </span>
                                    <small style="display: block; color: #666;">
                                        <?php echo $template->filled_spots; ?>/<?php echo $template->total_spots; ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($engagement_trends)): ?>
            <div class="fs-dashboard-card" style="margin-top: 20px;">
                <h2>📈 Volunteer Engagement Trend (Last 8 Weeks)</h2>
                <div class="fs-trend-chart">
                    <?php 
                    $max_signups = max(array_column($engagement_trends, 'signup_count'));
                    foreach ($engagement_trends as $week): 
                        $height = $max_signups > 0 ? ($week->signup_count / $max_signups) * 100 : 0;
                    ?>
                        <div class="fs-trend-bar">
                            <div class="fs-trend-value"><?php echo $week->signup_count; ?></div>
                            <div class="fs-trend-fill" style="height: <?php echo $height; ?>%;"></div>
                            <div class="fs-trend-label"><?php echo date('M j', strtotime($week->week_start)); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <style>
            .fs-dashboard-stats {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin: 20px 0 30px 0;
            }
            .fs-stat-card {
                background: white;
                border: 1px solid #ccd0d4;
                padding: 20px;
                text-align: center;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .fs-stat-number {
                font-size: 36px;
                font-weight: bold;
                color: #2271b1;
                margin-bottom: 5px;
            }
            .fs-stat-label {
                color: #666;
                font-size: 14px;
                font-weight: 600;
            }
            .fs-stat-detail {
                margin-top: 8px;
                font-size: 13px;
                color: #666;
            }
            .fs-stat-detail a {
                text-decoration: none;
            }
            .fs-dashboard-card {
                background: white;
                border: 1px solid #ccd0d4;
                padding: 20px;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .fs-dashboard-card h2 {
                margin: 0 0 5px 0;
                font-size: 18px;
            }
            .fs-card-description {
                margin: 0 0 15px 0;
                color: #666;
                font-size: 13px;
            }
            .fs-empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #28a745;
            }
            .fs-empty-state p {
                margin: 10px 0 0 0;
                font-size: 16px;
                font-weight: 600;
            }
            .fs-list-compact {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .fs-list-item {
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
                border-left: 3px solid #28a745;
            }
            .fs-list-title {
                font-weight: 600;
                margin-bottom: 4px;
            }
            .fs-list-meta {
                font-size: 13px;
                color: #666;
            }
            .fs-trend-chart {
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                height: 200px;
                padding: 20px 0;
                border-bottom: 2px solid #ddd;
            }
            .fs-trend-bar {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                margin: 0 5px;
            }
            .fs-trend-value {
                font-weight: 600;
                color: #0073aa;
                margin-bottom: 5px;
                font-size: 14px;
            }
            .fs-trend-fill {
                width: 100%;
                background: linear-gradient(to top, #0073aa, #2196f3);
                border-radius: 4px 4px 0 0;
                min-height: 20px;
                transition: height 0.3s ease;
            }
            .fs-trend-label {
                font-size: 11px;
                color: #666;
                margin-top: 8px;
                transform: rotate(-45deg);
                white-space: nowrap;
            }
            @media (max-width: 1200px) {
                .fs-dashboard-stats {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('.send-opportunity-reminder').on('click', function() {
                var $btn = $(this);
                var opportunityId = $btn.data('opportunity-id');

                if (!confirm('Send reminder emails to all volunteers signed up for this opportunity?')) {
                    return;
                }

                $btn.prop('disabled', true).text('Sending...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'fs_send_opportunity_reminder',
                        nonce: '<?php echo wp_create_nonce('friendshyft_admin_nonce'); ?>',
                        opportunity_id: opportunityId
                    },
                    success: function(response) {
                        if (response.success) {
                            $btn.text('✓ Sent!').css('background', '#28a745');
                            alert(response.data.message);
                        } else {
                            alert('Error: ' + response.data);
                            $btn.prop('disabled', false).text('📧 Remind');
                        }
                    },
                    error: function() {
                        alert('Connection error. Please try again.');
                        $btn.prop('disabled', false).text('📧 Remind');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public static function init() {
        add_action('wp_ajax_fs_send_opportunity_reminder', array(__CLASS__, 'send_opportunity_reminder'));
    }

    /**
     * AJAX handler to send reminders to volunteers for a specific opportunity
     */
    public static function send_opportunity_reminder() {
        check_ajax_referer('friendshyft_admin_nonce', 'nonce');

        if (!current_user_can('manage_friendshyft')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $opportunity_id = isset($_POST['opportunity_id']) ? intval($_POST['opportunity_id']) : 0;

        if (!$opportunity_id) {
            wp_send_json_error('Invalid opportunity ID');
            return;
        }

        global $wpdb;

        // Get opportunity details
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            wp_send_json_error('Opportunity not found');
            return;
        }

        // Get all confirmed signups for this opportunity
        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, v.name, v.email, v.access_token, sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
             WHERE s.opportunity_id = %d
             AND s.status = 'confirmed'",
            $opportunity_id
        ));

        if (empty($signups)) {
            wp_send_json_error('No confirmed signups for this opportunity');
            return;
        }

        $sent_count = 0;
        foreach ($signups as $signup) {
            $volunteer = (object) array(
                'id' => $signup->volunteer_id,
                'name' => $signup->name,
                'email' => $signup->email,
                'access_token' => $signup->access_token
            );

            $shift = null;
            if ($signup->shift_id && $signup->shift_start_time) {
                $shift = (object) array(
                    'shift_start_time' => $signup->shift_start_time,
                    'shift_end_time' => $signup->shift_end_time
                );
            }

            FS_Notifications::send_opportunity_reminder($volunteer, $opportunity, $shift);
            $sent_count++;
        }

        wp_send_json_success(array(
            'message' => "Reminder emails sent to {$sent_count} volunteer(s)!"
        ));
    }
}
