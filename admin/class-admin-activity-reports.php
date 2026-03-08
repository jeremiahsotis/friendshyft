<?php
if (!defined('ABSPATH')) exit;

/**
 * Volunteer Activity Reports
 * Comprehensive analytics for volunteer participation, hours, and achievements
 */
class FS_Admin_Activity_Reports {

    public static function init() {
        add_action('admin_post_fs_export_activity_report', array(__CLASS__, 'export_activity_report'));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        global $wpdb;

        // Get filter parameters
        $program_id = isset($_GET['program_id']) ? intval($_GET['program_id']) : 0;
        $role_id = isset($_GET['role_id']) ? intval($_GET['role_id']) : 0;
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');

        // Get all programs for filter
        $programs = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}fs_programs ORDER BY name ASC"
        );

        // Get all roles for filter
        $roles = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}fs_roles ORDER BY name ASC"
        );

        ?>
        <div class="wrap">
            <h1>Volunteer Activity Reports</h1>
            <p>Analyze volunteer participation, hours, and trends across your programs.</p>

            <!-- Filters -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Filters</h2>
                <form method="get" action="">
                    <input type="hidden" name="page" value="fs-activity-reports">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <label for="program_id" style="display: block; margin-bottom: 5px;"><strong>Program:</strong></label>
                            <select name="program_id" id="program_id" style="width: 100%;">
                                <option value="0">All Programs</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program->id; ?>" <?php selected($program_id, $program->id); ?>>
                                        <?php echo esc_html($program->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="role_id" style="display: block; margin-bottom: 5px;"><strong>Role:</strong></label>
                            <select name="role_id" id="role_id" style="width: 100%;">
                                <option value="0">All Roles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role->id; ?>" <?php selected($role_id, $role->id); ?>>
                                        <?php echo esc_html($role->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="date_from" style="display: block; margin-bottom: 5px;"><strong>From:</strong></label>
                            <input type="date" name="date_from" id="date_from" value="<?php echo esc_attr($date_from); ?>" style="width: 100%;">
                        </div>
                        <div>
                            <label for="date_to" style="display: block; margin-bottom: 5px;"><strong>To:</strong></label>
                            <input type="date" name="date_to" id="date_to" value="<?php echo esc_attr($date_to); ?>" style="width: 100%;">
                        </div>
                    </div>
                    <button type="submit" class="button button-primary" style="margin-top: 15px;">Apply Filters</button>
                    <a href="<?php echo admin_url('admin.php?page=fs-activity-reports'); ?>" class="button" style="margin-top: 15px;">Clear Filters</a>
                </form>
            </div>

            <?php
            // Build WHERE clauses for queries
            $where_conditions = array("o.event_date BETWEEN %s AND %s");
            $where_params = array($date_from, $date_to);

            if ($program_id > 0) {
                $where_conditions[] = "p.id = %d";
                $where_params[] = $program_id;
            }

            if ($role_id > 0) {
                // This will filter volunteers by role
                $where_conditions[] = "vr.role_id = %d";
                $where_params[] = $role_id;
            }

            $where_clause = implode(' AND ', $where_conditions);

            // Get overall statistics
            $total_volunteers = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT v.id)
                 FROM {$wpdb->prefix}fs_volunteers v
                 JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 LEFT JOIN {$wpdb->prefix}fs_programs p ON o.conference = p.name
                 LEFT JOIN {$wpdb->prefix}fs_volunteer_roles vr ON v.id = vr.volunteer_id
                 WHERE $where_clause
                 AND s.status = 'confirmed'",
                $where_params
            ));

            $total_hours = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(tr.total_hours)
                 FROM {$wpdb->prefix}fs_time_records tr
                 JOIN {$wpdb->prefix}fs_opportunities o ON tr.opportunity_id = o.id
                 LEFT JOIN {$wpdb->prefix}fs_programs p ON o.conference = p.name
                 LEFT JOIN {$wpdb->prefix}fs_volunteer_roles vr ON tr.volunteer_id = vr.volunteer_id
                 WHERE $where_clause",
                $where_params
            )) ?: 0;

            // Add team hours
            $team_hours = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(ta.total_hours)
                 FROM {$wpdb->prefix}fs_team_attendance ta
                 JOIN {$wpdb->prefix}fs_opportunities o ON ta.opportunity_id = o.id
                 LEFT JOIN {$wpdb->prefix}fs_programs p ON o.conference = p.name
                 WHERE o.event_date BETWEEN %s AND %s" . ($program_id > 0 ? " AND p.id = %d" : ""),
                $program_id > 0 ? array($date_from, $date_to, $program_id) : array($date_from, $date_to)
            )) ?: 0;

            $total_hours += $team_hours;

            $total_opportunities = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT o.id)
                 FROM {$wpdb->prefix}fs_opportunities o
                 LEFT JOIN {$wpdb->prefix}fs_programs p ON o.conference = p.name
                 WHERE o.event_date BETWEEN %s AND %s" . ($program_id > 0 ? " AND p.id = %d" : ""),
                $program_id > 0 ? array($date_from, $date_to, $program_id) : array($date_from, $date_to)
            ));

            $avg_hours_per_volunteer = $total_volunteers > 0 ? $total_hours / $total_volunteers : 0;
            ?>

            <!-- Overall Statistics -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
                <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_volunteers); ?></div>
                    <div style="color: #666; margin-top: 5px;">Active Volunteers</div>
                </div>
                <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($total_hours, 2); ?></div>
                    <div style="color: #666; margin-top: 5px;">Total Hours</div>
                </div>
                <div style="background: white; border-left: 4px solid #667eea; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #667eea;"><?php echo number_format($avg_hours_per_volunteer, 2); ?></div>
                    <div style="color: #666; margin-top: 5px;">Avg Hours/Volunteer</div>
                </div>
                <div style="background: white; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?php echo number_format($total_opportunities); ?></div>
                    <div style="color: #666; margin-top: 5px;">Opportunities</div>
                </div>
            </div>

            <!-- Export Button -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('fs_export_activity_report', '_wpnonce_export'); ?>
                    <input type="hidden" name="action" value="fs_export_activity_report">
                    <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">
                    <input type="hidden" name="role_id" value="<?php echo $role_id; ?>">
                    <input type="hidden" name="date_from" value="<?php echo $date_from; ?>">
                    <input type="hidden" name="date_to" value="<?php echo $date_to; ?>">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export Full Report (CSV)
                    </button>
                </form>
            </div>

            <!-- Hours by Program -->
            <?php
            $hours_by_program = $wpdb->get_results($wpdb->prepare(
                "SELECT p.name as program_name,
                        SUM(tr.total_hours) as total_hours,
                        COUNT(DISTINCT tr.volunteer_id) as volunteer_count
                 FROM {$wpdb->prefix}fs_time_records tr
                 JOIN {$wpdb->prefix}fs_opportunities o ON tr.opportunity_id = o.id
                 LEFT JOIN {$wpdb->prefix}fs_programs p ON o.conference = p.name
                 WHERE o.event_date BETWEEN %s AND %s
                 GROUP BY p.name
                 ORDER BY total_hours DESC",
                $date_from,
                $date_to
            ));

            // Add team hours by program
            $team_hours_by_program = $wpdb->get_results($wpdb->prepare(
                "SELECT p.name as program_name,
                        SUM(ta.total_hours) as total_hours
                 FROM {$wpdb->prefix}fs_team_attendance ta
                 JOIN {$wpdb->prefix}fs_opportunities o ON ta.opportunity_id = o.id
                 LEFT JOIN {$wpdb->prefix}fs_programs p ON o.conference = p.name
                 WHERE o.event_date BETWEEN %s AND %s
                 GROUP BY p.name",
                $date_from,
                $date_to
            ));

            // Merge team hours into program hours
            foreach ($team_hours_by_program as $th) {
                $found = false;
                foreach ($hours_by_program as &$ph) {
                    if ($ph->program_name === $th->program_name) {
                        $ph->total_hours += $th->total_hours;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $hours_by_program[] = (object)array(
                        'program_name' => $th->program_name,
                        'total_hours' => $th->total_hours,
                        'volunteer_count' => 0
                    );
                }
            }

            // Sort by total hours descending
            usort($hours_by_program, function($a, $b) {
                return $b->total_hours - $a->total_hours;
            });
            ?>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Hours by Program</h3>
                <?php if (!empty($hours_by_program)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Total Hours</th>
                                <th>Volunteers</th>
                                <th>Avg Hours/Volunteer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hours_by_program as $row): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row->program_name ?: 'Unassigned'); ?></strong></td>
                                    <td><?php echo number_format($row->total_hours, 2); ?></td>
                                    <td><?php echo number_format($row->volunteer_count); ?></td>
                                    <td><?php echo $row->volunteer_count > 0 ? number_format($row->total_hours / $row->volunteer_count, 2) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #666; font-style: italic;">No data available for the selected period.</p>
                <?php endif; ?>
            </div>

            <!-- Hours by Role -->
            <?php
            $hours_by_role = $wpdb->get_results($wpdb->prepare(
                "SELECT r.name as role_name,
                        SUM(tr.total_hours) as total_hours,
                        COUNT(DISTINCT v.id) as volunteer_count
                 FROM {$wpdb->prefix}fs_volunteers v
                 JOIN {$wpdb->prefix}fs_volunteer_roles vr ON v.id = vr.volunteer_id
                 JOIN {$wpdb->prefix}fs_roles r ON vr.role_id = r.id
                 JOIN {$wpdb->prefix}fs_time_records tr ON v.id = tr.volunteer_id
                 JOIN {$wpdb->prefix}fs_opportunities o ON tr.opportunity_id = o.id
                 WHERE o.event_date BETWEEN %s AND %s
                 GROUP BY r.id
                 ORDER BY total_hours DESC",
                $date_from,
                $date_to
            ));
            ?>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Hours by Role</h3>
                <?php if (!empty($hours_by_role)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Total Hours</th>
                                <th>Volunteers</th>
                                <th>Avg Hours/Volunteer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hours_by_role as $row): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row->role_name); ?></strong></td>
                                    <td><?php echo number_format($row->total_hours, 2); ?></td>
                                    <td><?php echo number_format($row->volunteer_count); ?></td>
                                    <td><?php echo number_format($row->total_hours / $row->volunteer_count, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #666; font-style: italic;">No data available for the selected period.</p>
                <?php endif; ?>
            </div>

            <!-- Top Volunteers -->
            <?php
            $top_volunteers = $wpdb->get_results($wpdb->prepare(
                "SELECT v.id, v.name, v.email,
                        SUM(tr.total_hours) as total_hours,
                        COUNT(DISTINCT tr.opportunity_id) as opportunities_count
                 FROM {$wpdb->prefix}fs_volunteers v
                 JOIN {$wpdb->prefix}fs_time_records tr ON v.id = tr.volunteer_id
                 JOIN {$wpdb->prefix}fs_opportunities o ON tr.opportunity_id = o.id
                 WHERE o.event_date BETWEEN %s AND %s
                 GROUP BY v.id
                 ORDER BY total_hours DESC
                 LIMIT 20",
                $date_from,
                $date_to
            ));
            ?>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Top 20 Volunteers by Hours</h3>
                <?php if (!empty($top_volunteers)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Total Hours</th>
                                <th>Opportunities</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($top_volunteers as $vol): ?>
                                <tr>
                                    <td><strong><?php echo $rank++; ?></strong></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $vol->id); ?>">
                                            <?php echo esc_html($vol->name); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($vol->email); ?></td>
                                    <td><strong><?php echo number_format($vol->total_hours, 2); ?></strong></td>
                                    <td><?php echo number_format($vol->opportunities_count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #666; font-style: italic;">No volunteer activity recorded for the selected period.</p>
                <?php endif; ?>
            </div>

            <!-- Participation Trends (Monthly) -->
            <?php
            $monthly_trends = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE_FORMAT(o.event_date, '%%Y-%%m') as month,
                        COUNT(DISTINCT s.volunteer_id) as volunteer_count,
                        COUNT(DISTINCT o.id) as opportunity_count,
                        SUM(CASE WHEN tr.id IS NOT NULL THEN 1 ELSE 0 END) as attended_count
                 FROM {$wpdb->prefix}fs_opportunities o
                 LEFT JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id AND s.status = 'confirmed'
                 LEFT JOIN {$wpdb->prefix}fs_time_records tr ON s.volunteer_id = tr.volunteer_id AND s.opportunity_id = tr.opportunity_id
                 WHERE o.event_date BETWEEN %s AND %s
                 GROUP BY month
                 ORDER BY month ASC",
                $date_from,
                $date_to
            ));
            ?>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Participation Trends (Monthly)</h3>
                <?php if (!empty($monthly_trends)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Opportunities</th>
                                <th>Unique Volunteers</th>
                                <th>Attended</th>
                                <th>Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_trends as $row): ?>
                                <?php
                                $signups = $row->volunteer_count * $row->opportunity_count;
                                $attendance_rate = $signups > 0 ? ($row->attended_count / $signups) * 100 : 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo date('F Y', strtotime($row->month . '-01')); ?></strong></td>
                                    <td><?php echo number_format($row->opportunity_count); ?></td>
                                    <td><?php echo number_format($row->volunteer_count); ?></td>
                                    <td><?php echo number_format($row->attended_count); ?></td>
                                    <td><?php echo number_format($attendance_rate, 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #666; font-style: italic;">No participation data for the selected period.</p>
                <?php endif; ?>
            </div>

            <!-- Badge Achievements -->
            <?php
            // Get badge counts
            $badge_stats = $wpdb->get_results(
                "SELECT badge_name, COUNT(*) as count
                 FROM {$wpdb->prefix}fs_volunteer_badges
                 GROUP BY badge_name
                 ORDER BY count DESC"
            );
            ?>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Badge Achievements</h3>
                <?php if (!empty($badge_stats)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Badge</th>
                                <th>Volunteers Earned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($badge_stats as $badge): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($badge->badge_name); ?></strong></td>
                                    <td><?php echo number_format($badge->count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #666; font-style: italic;">No badges have been earned yet.</p>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    /**
     * Export comprehensive activity report to CSV
     */
    public static function export_activity_report() {
        check_admin_referer('fs_export_activity_report', '_wpnonce_export');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $program_id = intval($_POST['program_id']);
        $role_id = intval($_POST['role_id']);
        $date_from = sanitize_text_field($_POST['date_from']);
        $date_to = sanitize_text_field($_POST['date_to']);

        global $wpdb;

        // Build WHERE clause
        $where_conditions = array("o.event_date BETWEEN %s AND %s");
        $where_params = array($date_from, $date_to);

        if ($program_id > 0) {
            $where_conditions[] = "p.id = %d";
            $where_params[] = $program_id;
        }

        if ($role_id > 0) {
            $where_conditions[] = "vr.role_id = %d";
            $where_params[] = $role_id;
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Get volunteer activity data
        $activity_data = $wpdb->get_results($wpdb->prepare(
            "SELECT v.name, v.email, v.volunteer_status,
                    r.name as role_name,
                    p.name as program_name,
                    SUM(tr.total_hours) as total_hours,
                    COUNT(DISTINCT tr.opportunity_id) as opportunities_count,
                    COUNT(DISTINCT s.id) as total_signups
             FROM {$wpdb->prefix}fs_volunteers v
             LEFT JOIN {$wpdb->prefix}fs_volunteer_roles vr ON v.id = vr.volunteer_id
             LEFT JOIN {$wpdb->prefix}fs_roles r ON vr.role_id = r.id
             LEFT JOIN {$wpdb->prefix}fs_time_records tr ON v.id = tr.volunteer_id
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON tr.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_programs p ON o.conference = p.name
             LEFT JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
             WHERE $where_clause
             GROUP BY v.id, r.id
             ORDER BY v.name ASC",
            $where_params
        ));

        // Set headers for CSV download
        $filename = 'activity-report-' . $date_from . '-to-' . $date_to . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Add header row
        fputcsv($output, array('Volunteer Name', 'Email', 'Status', 'Role', 'Program', 'Total Hours', 'Opportunities Attended', 'Total Signups'));

        // Add data rows
        foreach ($activity_data as $row) {
            fputcsv($output, array(
                $row->name,
                $row->email,
                $row->volunteer_status,
                $row->role_name ?: '—',
                $row->program_name ?: '—',
                $row->total_hours ?: 0,
                $row->opportunities_count ?: 0,
                $row->total_signups ?: 0
            ));
        }

        fclose($output);
        exit;
    }
}
