<?php
if (!defined('ABSPATH')) exit;

/**
 * Analytics & Insights Admin Dashboard
 * Predictive analytics, impact metrics, retention analytics
 */
class FS_Admin_Analytics {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 20);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Analytics & Insights',
            'Analytics',
            'manage_friendshyft',
            'friendshyft-analytics',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'predictive';

        ?>
        <div class="wrap">
            <h1>📊 Analytics & Insights</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=friendshyft-analytics&tab=predictive" class="nav-tab <?php echo $active_tab === 'predictive' ? 'nav-tab-active' : ''; ?>">
                    🔮 Predictive Analytics
                </a>
                <a href="?page=friendshyft-analytics&tab=impact" class="nav-tab <?php echo $active_tab === 'impact' ? 'nav-tab-active' : ''; ?>">
                    💪 Impact Metrics
                </a>
                <a href="?page=friendshyft-analytics&tab=retention" class="nav-tab <?php echo $active_tab === 'retention' ? 'nav-tab-active' : ''; ?>">
                    ❤️ Volunteer Retention
                </a>
            </h2>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'predictive':
                        self::render_predictive_tab();
                        break;
                    case 'impact':
                        self::render_impact_tab();
                        break;
                    case 'retention':
                        self::render_retention_tab();
                        break;
                }
                ?>
            </div>
        </div>

        <style>
            .analytics-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .analytics-card {
                background: white;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            .analytics-card h3 {
                margin-top: 0;
                color: #0073aa;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
            .stat-big {
                font-size: 36px;
                font-weight: bold;
                color: #0073aa;
                margin: 10px 0;
            }
            .stat-label {
                font-size: 14px;
                color: #666;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .risk-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: bold;
            }
            .risk-high {
                background: #dc3545;
                color: white;
            }
            .risk-medium {
                background: #ffc107;
                color: #333;
            }
            .risk-low {
                background: #28a745;
                color: white;
            }
            .trend-up {
                color: #28a745;
            }
            .trend-down {
                color: #dc3545;
            }
            .trend-stable {
                color: #666;
            }
            .opportunities-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }
            .opportunities-table th,
            .opportunities-table td {
                padding: 10px;
                text-align: left;
                border-bottom: 1px solid #eee;
            }
            .opportunities-table th {
                background: #f5f5f5;
                font-weight: 600;
            }
            .opportunities-table tr:hover {
                background: #f9f9f9;
            }
            .confidence-bar {
                height: 8px;
                background: #e0e0e0;
                border-radius: 4px;
                overflow: hidden;
            }
            .confidence-fill {
                height: 100%;
                background: #0073aa;
                transition: width 0.3s;
            }
            .date-filter {
                background: white;
                padding: 15px;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin: 20px 0;
            }
            .date-filter label {
                margin-right: 10px;
                font-weight: 600;
            }
            .date-filter input,
            .date-filter select {
                margin-right: 15px;
                padding: 6px 10px;
            }
            .export-btn {
                background: #0073aa;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 600;
            }
            .export-btn:hover {
                background: #005177;
            }
            .message-box {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
                padding: 15px;
                border-radius: 6px;
                margin: 15px 0;
            }
        </style>
        <?php
    }

    /**
     * Predictive Analytics Tab
     */
    private static function render_predictive_tab() {
        global $wpdb;

        // Get upcoming opportunities that need predictions
        $upcoming = $wpdb->get_results(
            "SELECT o.*, p.name as program_name,
                    DATEDIFF(o.event_date, CURDATE()) as days_until
             FROM {$wpdb->prefix}fs_opportunities o
             LEFT JOIN {$wpdb->prefix}fs_programs p ON o.program_id = p.id
             WHERE o.event_date >= CURDATE()
             AND o.event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             AND (o.status = 'Open' OR o.status IS NULL)
             ORDER BY o.event_date ASC
             LIMIT 20"
        );

        ?>
        <div class="analytics-card">
            <h3>🔮 Volunteer Forecasting</h3>
            <p>Predictive analytics for upcoming volunteer opportunities based on historical data patterns.</p>

            <?php if (empty($upcoming)): ?>
                <p style="color: #666; font-style: italic;">No upcoming opportunities in the next 30 days.</p>
            <?php else: ?>
                <table class="opportunities-table">
                    <thead>
                        <tr>
                            <th>Opportunity</th>
                            <th>Date</th>
                            <th>Current / Total</th>
                            <th>Predicted Signups</th>
                            <th>Confidence</th>
                            <th>No-Show Risk</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upcoming as $opp): ?>
                            <?php
                            $forecast = FS_Predictive_Analytics::forecast_volunteer_needs($opp->days_until);
                            $prediction = null;
                            foreach ($forecast as $f) {
                                if ($f->opportunity_id == $opp->id) {
                                    $prediction = $f;
                                    break;
                                }
                            }

                            // Get high-risk volunteers for this opportunity
                            $high_risk = FS_Predictive_Analytics::get_high_risk_volunteers($opp->id, 0.3);
                            $risk_count = count($high_risk);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($opp->title); ?></strong><br>
                                    <small style="color: #666;"><?php echo esc_html($opp->program_name); ?></small>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($opp->event_date)); ?><br>
                                    <small style="color: #666;"><?php echo $opp->days_until; ?> days</small>
                                </td>
                                <td>
                                    <strong><?php echo $opp->spots_filled; ?> / <?php echo $opp->spots_available; ?></strong><br>
                                    <small style="color: #666;">
                                        <?php echo $opp->spots_available - $opp->spots_filled; ?> open
                                    </small>
                                </td>
                                <td>
                                    <?php if ($prediction): ?>
                                        <strong><?php echo $prediction->predicted_signups; ?></strong> signups<br>
                                        <small style="color: #666;">Fill rate: <?php echo round($prediction->predicted_fill_rate * 100); ?>%</small>
                                    <?php else: ?>
                                        <em style="color: #999;">Calculating...</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($prediction): ?>
                                        <div class="confidence-bar">
                                            <div class="confidence-fill" style="width: <?php echo ($prediction->confidence * 100); ?>%"></div>
                                        </div>
                                        <small><?php echo round($prediction->confidence * 100); ?>%</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($risk_count > 0): ?>
                                        <span class="risk-badge risk-high"><?php echo $risk_count; ?> high-risk</span>
                                    <?php else: ?>
                                        <span style="color: #28a745;">✓ Low risk</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="analytics-card" style="margin-top: 20px;">
            <h3>📈 Shift Optimization Insights</h3>
            <p>Analyze historical patterns to optimize shift scheduling.</p>

            <?php
            // Get all programs
            $programs = $wpdb->get_results(
                "SELECT id, name FROM {$wpdb->prefix}fs_programs ORDER BY name ASC"
            );

            if (!empty($programs)):
                $selected_program = isset($_GET['program_id']) ? intval($_GET['program_id']) : $programs[0]->id;
            ?>
                <form method="get" style="margin-bottom: 20px;">
                    <input type="hidden" name="page" value="friendshyft-analytics">
                    <input type="hidden" name="tab" value="predictive">
                    <label>Select Program:</label>
                    <select name="program_id" onchange="this.form.submit()">
                        <?php foreach ($programs as $prog): ?>
                            <option value="<?php echo $prog->id; ?>" <?php selected($selected_program, $prog->id); ?>>
                                <?php echo esc_html($prog->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php
                $optimization = FS_Predictive_Analytics::optimize_shift_scheduling($selected_program, 30);
                ?>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 6px;">
                        <div class="stat-label">Best Day of Week</div>
                        <div class="stat-big" style="font-size: 24px;"><?php echo $optimization['best_day_name']; ?></div>
                        <small style="color: #666;"><?php echo round($optimization['best_day_avg']); ?> avg signups</small>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 6px;">
                        <div class="stat-label">Avg Fill Rate</div>
                        <div class="stat-big" style="font-size: 24px;"><?php echo round($optimization['avg_fill_rate'] * 100); ?>%</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 6px;">
                        <div class="stat-label">Optimal Lead Time</div>
                        <div class="stat-big" style="font-size: 24px;"><?php echo round($optimization['optimal_lead_time']); ?></div>
                        <small style="color: #666;">days notice</small>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Impact Metrics Tab
     */
    private static function render_impact_tab() {
        // Date filter
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-01-01');
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');

        $impact = FS_Impact_Metrics::calculate_community_impact($start_date, $end_date);
        $by_program = FS_Impact_Metrics::get_hours_by_program($start_date, $end_date);

        ?>
        <div class="date-filter">
            <form method="get">
                <input type="hidden" name="page" value="friendshyft-analytics">
                <input type="hidden" name="tab" value="impact">
                <label>Start Date:</label>
                <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                <label>End Date:</label>
                <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                <button type="submit" class="button">Update Report</button>

                <a href="<?php echo admin_url('admin-post.php?action=fs_export_donor_report&start_date=' . $start_date . '&end_date=' . $end_date); ?>" class="export-btn" style="float: right; text-decoration: none;">
                    📥 Export CSV Report
                </a>
            </form>
        </div>

        <div class="analytics-grid">
            <div class="analytics-card">
                <div class="stat-label">Total Volunteer Hours</div>
                <div class="stat-big"><?php echo number_format($impact['total_hours'], 1); ?></div>
            </div>
            <div class="analytics-card">
                <div class="stat-label">Unique Volunteers</div>
                <div class="stat-big"><?php echo number_format($impact['unique_volunteers']); ?></div>
            </div>
            <div class="analytics-card">
                <div class="stat-label">Economic Value</div>
                <div class="stat-big">$<?php echo number_format($impact['economic_value'], 0); ?></div>
                <small style="color: #666;">@ $<?php echo $impact['volunteer_hour_value']; ?>/hour</small>
            </div>
            <div class="analytics-card">
                <div class="stat-label">Volunteer Retention</div>
                <div class="stat-big"><?php echo $impact['retention_rate']; ?>%</div>
                <small style="color: #666;"><?php echo number_format($impact['returning_volunteers']); ?> returning volunteers</small>
            </div>
            <div class="analytics-card">
                <div class="stat-label">Total Shifts</div>
                <div class="stat-big"><?php echo number_format($impact['total_shifts']); ?></div>
            </div>
            <div class="analytics-card">
                <div class="stat-label">Active Programs</div>
                <div class="stat-big"><?php echo $impact['active_programs']; ?></div>
            </div>
        </div>

        <div class="analytics-card" style="margin-top: 20px;">
            <h3>💪 Hours by Program</h3>
            <?php if (empty($by_program)): ?>
                <p style="color: #666; font-style: italic;">No program activity in this date range.</p>
            <?php else: ?>
                <table class="opportunities-table">
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Total Hours</th>
                            <th>Unique Volunteers</th>
                            <th>Total Shifts</th>
                            <th>Avg Hours/Shift</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($by_program as $prog): ?>
                            <tr>
                                <td><strong><?php echo esc_html($prog->name); ?></strong></td>
                                <td><?php echo number_format($prog->total_hours, 1); ?></td>
                                <td><?php echo number_format($prog->unique_volunteers); ?></td>
                                <td><?php echo number_format($prog->total_shifts); ?></td>
                                <td><?php echo number_format($prog->avg_hours_per_shift, 1); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="analytics-card" style="margin-top: 20px;">
            <h3>📢 Donor-Ready Key Messages</h3>
            <?php
            $messages = FS_Impact_Metrics::generate_donor_report($start_date, $end_date)['key_messages'];
            foreach ($messages as $message):
            ?>
                <div class="message-box">
                    <?php echo esc_html($message); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Retention Analytics Tab
     */
    private static function render_retention_tab() {
        $stats = FS_Volunteer_Retention::get_engagement_statistics();

        // Get at-risk volunteers
        $high_risk = FS_Volunteer_Retention::get_at_risk_volunteers('high', 25);
        $medium_risk = FS_Volunteer_Retention::get_at_risk_volunteers('medium', 25);

        ?>
        <div class="analytics-grid">
            <div class="analytics-card">
                <div class="stat-label">Average Engagement Score</div>
                <div class="stat-big"><?php echo round($stats->avg_score); ?></div>
                <small style="color: #666;">out of 100</small>
            </div>
            <div class="analytics-card">
                <div class="stat-label">High Risk Volunteers</div>
                <div class="stat-big" style="color: #dc3545;"><?php echo $stats->high_risk; ?></div>
            </div>
            <div class="analytics-card">
                <div class="stat-label">Medium Risk Volunteers</div>
                <div class="stat-big" style="color: #ffc107;"><?php echo $stats->medium_risk; ?></div>
            </div>
            <div class="analytics-card">
                <div class="stat-label">Engagement Trends</div>
                <div style="margin-top: 10px;">
                    <span class="trend-up">↑ <?php echo $stats->improving; ?> Improving</span><br>
                    <span class="trend-down">↓ <?php echo $stats->declining; ?> Declining</span><br>
                    <span class="trend-stable">→ <?php echo $stats->stable; ?> Stable</span>
                </div>
            </div>
        </div>

        <div class="analytics-card" style="margin-top: 20px;">
            <h3>⚠️ High-Risk Volunteers</h3>
            <p>Volunteers who need immediate attention and re-engagement efforts.</p>

            <?php if (empty($high_risk)): ?>
                <p style="color: #28a745; font-weight: bold;">✓ No high-risk volunteers at this time!</p>
            <?php else: ?>
                <table class="opportunities-table">
                    <thead>
                        <tr>
                            <th>Volunteer</th>
                            <th>Score</th>
                            <th>Trend</th>
                            <th>Last Activity</th>
                            <th>Days Inactive</th>
                            <th>Total Hours</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($high_risk as $vol): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($vol->name); ?></strong><br>
                                    <small style="color: #666;"><?php echo esc_html($vol->email); ?></small>
                                </td>
                                <td>
                                    <strong style="color: #dc3545;"><?php echo $vol->score; ?></strong>
                                </td>
                                <td>
                                    <span class="trend-<?php echo $vol->trend === 'improving' ? 'up' : ($vol->trend === 'declining' ? 'down' : 'stable'); ?>">
                                        <?php echo ucfirst($vol->trend); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($vol->last_activity_date): ?>
                                        <?php echo date('M j, Y', strtotime($vol->last_activity_date)); ?>
                                    <?php else: ?>
                                        <em style="color: #999;">Never</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $vol->days_inactive; ?> days</td>
                                <td><?php echo number_format($vol->total_hours, 1); ?></td>
                                <td>
                                    <button class="button button-small send-reengagement" data-volunteer-id="<?php echo $vol->id; ?>">
                                        📧 Send Re-engagement
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="analytics-card" style="margin-top: 20px;">
            <h3>⚡ Medium-Risk Volunteers</h3>
            <p>Volunteers showing early warning signs of disengagement.</p>

            <?php if (empty($medium_risk)): ?>
                <p style="color: #28a745; font-weight: bold;">✓ No medium-risk volunteers at this time!</p>
            <?php else: ?>
                <table class="opportunities-table">
                    <thead>
                        <tr>
                            <th>Volunteer</th>
                            <th>Score</th>
                            <th>Trend</th>
                            <th>Last 30 Days</th>
                            <th>Last 90 Days</th>
                            <th>Total Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medium_risk as $vol): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($vol->name); ?></strong><br>
                                    <small style="color: #666;"><?php echo esc_html($vol->email); ?></small>
                                </td>
                                <td>
                                    <strong style="color: #ffc107;"><?php echo $vol->score; ?></strong>
                                </td>
                                <td>
                                    <span class="trend-<?php echo $vol->trend === 'improving' ? 'up' : ($vol->trend === 'declining' ? 'down' : 'stable'); ?>">
                                        <?php echo ucfirst($vol->trend); ?>
                                    </span>
                                </td>
                                <td><?php echo $vol->signups_last_30_days; ?> signups</td>
                                <td><?php echo $vol->signups_last_90_days; ?> signups</td>
                                <td><?php echo number_format($vol->total_hours, 1); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.send-reengagement').on('click', function() {
                var $btn = $(this);
                var volunteerId = $btn.data('volunteer-id');

                if (!confirm('Send re-engagement email to this volunteer?')) {
                    return;
                }

                $btn.prop('disabled', true).text('Sending...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'fs_send_manual_reengagement',
                        nonce: '<?php echo wp_create_nonce('friendshyft_admin_nonce'); ?>',
                        volunteer_id: volunteerId
                    },
                    success: function(response) {
                        if (response.success) {
                            $btn.text('✓ Sent!').css('background', '#28a745');
                            alert('Re-engagement email sent successfully!');
                        } else {
                            alert('Error: ' + response.data);
                            $btn.prop('disabled', false).text('📧 Send Re-engagement');
                        }
                    },
                    error: function() {
                        alert('Connection error. Please try again.');
                        $btn.prop('disabled', false).text('📧 Send Re-engagement');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
