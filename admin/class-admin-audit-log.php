<?php
if (!defined('ABSPATH')) exit;

/**
 * Audit Log Viewer
 * Admin interface for viewing and filtering audit logs
 */
class FS_Admin_Audit_Log {

    public static function init() {
        add_action('admin_post_fs_export_audit_log', array(__CLASS__, 'export_audit_log'));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        // Get filter parameters
        $action_type = isset($_GET['action_type']) ? sanitize_text_field($_GET['action_type']) : '';
        $entity_type = isset($_GET['entity_type']) ? sanitize_text_field($_GET['entity_type']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-7 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;

        // Get logs
        $args = array(
            'action_type' => $action_type,
            'entity_type' => $entity_type,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'limit' => $per_page,
            'offset' => ($page_num - 1) * $per_page
        );

        $logs = FS_Audit_Log::get_logs($args);
        $total_logs = FS_Audit_Log::get_log_count($args);
        $total_pages = ceil($total_logs / $per_page);

        // Get unique action types and entity types for filters
        global $wpdb;
        $action_types = $wpdb->get_col("SELECT DISTINCT action_type FROM {$wpdb->prefix}fs_audit_log ORDER BY action_type");
        $entity_types = $wpdb->get_col("SELECT DISTINCT entity_type FROM {$wpdb->prefix}fs_audit_log ORDER BY entity_type");

        ?>
        <div class="wrap">
            <h1>Audit Log</h1>
            <p>View all system activity and changes for accountability and debugging.</p>

            <!-- Statistics -->
            <?php
            $today_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_audit_log WHERE DATE(created_at) = CURDATE()");
            $week_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $total_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_audit_log");
            ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
                <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($today_count); ?></div>
                    <div style="color: #666; margin-top: 5px;">Today's Actions</div>
                </div>
                <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($week_count); ?></div>
                    <div style="color: #666; margin-top: 5px;">This Week</div>
                </div>
                <div style="background: white; border-left: 4px solid #667eea; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #667eea;"><?php echo number_format($total_count); ?></div>
                    <div style="color: #666; margin-top: 5px;">Total Logged</div>
                </div>
            </div>

            <!-- Filters -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Filter Logs</h2>
                <form method="get" action="">
                    <input type="hidden" name="page" value="fs-audit-log">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <div>
                            <label for="action_type" style="display: block; margin-bottom: 5px;"><strong>Action Type:</strong></label>
                            <select name="action_type" id="action_type" style="width: 100%;">
                                <option value="">All Actions</option>
                                <?php foreach ($action_types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($action_type, $type); ?>>
                                        <?php echo esc_html($type); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="entity_type" style="display: block; margin-bottom: 5px;"><strong>Entity Type:</strong></label>
                            <select name="entity_type" id="entity_type" style="width: 100%;">
                                <option value="">All Entities</option>
                                <?php foreach ($entity_types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($entity_type, $type); ?>>
                                        <?php echo esc_html($type); ?>
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
                    <div style="margin-top: 15px;">
                        <button type="submit" class="button button-primary">Apply Filters</button>
                        <a href="<?php echo admin_url('admin.php?page=fs-audit-log'); ?>" class="button">Clear Filters</a>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; margin-left: 10px;">
                            <?php wp_nonce_field('fs_export_audit_log', '_wpnonce_export'); ?>
                            <input type="hidden" name="action" value="fs_export_audit_log">
                            <input type="hidden" name="action_type" value="<?php echo esc_attr($action_type); ?>">
                            <input type="hidden" name="entity_type" value="<?php echo esc_attr($entity_type); ?>">
                            <input type="hidden" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                            <input type="hidden" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                            <button type="submit" class="button">
                                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export to CSV
                            </button>
                        </form>
                    </div>
                </form>
            </div>

            <!-- Results -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Activity Log (<?php echo number_format($total_logs); ?> entries)</h2>

                <?php if (empty($logs)): ?>
                    <p style="text-align: center; color: #666; padding: 40px;">No log entries found for the selected filters.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 150px;">Date/Time</th>
                                <th>Action</th>
                                <th>User</th>
                                <th>Entity</th>
                                <th>Details</th>
                                <th style="width: 120px;">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $details = json_decode($log->details, true);
                                $action_color = self::get_action_color($log->action_type);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('M j, Y', strtotime($log->created_at)); ?></strong><br>
                                        <small><?php echo date('g:i A', strtotime($log->created_at)); ?></small>
                                    </td>
                                    <td>
                                        <span style="display: inline-block; padding: 4px 8px; background: <?php echo $action_color; ?>15; color: <?php echo $action_color; ?>; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                            <?php echo esc_html($log->action_type); ?>
                                        </span><br>
                                        <small style="color: #666;"><?php echo esc_html(FS_Audit_Log::get_action_description($log)); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($log->user_name); ?></strong><br>
                                        <small style="color: #666;"><?php echo esc_html($log->user_role); ?></small>
                                    </td>
                                    <td>
                                        <?php echo esc_html($log->entity_type); ?> #<?php echo $log->entity_id; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($details)): ?>
                                            <?php foreach ($details as $key => $value): ?>
                                                <strong><?php echo esc_html($key); ?>:</strong> <?php echo esc_html(is_array($value) ? json_encode($value) : $value); ?><br>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code style="font-size: 11px;"><?php echo esc_html($log->ip_address); ?></code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div style="margin-top: 20px; text-align: center;">
                            <?php
                            $base_url = add_query_arg(array(
                                'page' => 'fs-audit-log',
                                'action_type' => $action_type,
                                'entity_type' => $entity_type,
                                'date_from' => $date_from,
                                'date_to' => $date_to
                            ), admin_url('admin.php'));

                            if ($page_num > 1) {
                                echo '<a href="' . esc_url(add_query_arg('paged', $page_num - 1, $base_url)) . '" class="button">« Previous</a> ';
                            }

                            echo '<span style="margin: 0 10px;">Page ' . $page_num . ' of ' . $total_pages . '</span>';

                            if ($page_num < $total_pages) {
                                echo ' <a href="' . esc_url(add_query_arg('paged', $page_num + 1, $base_url)) . '" class="button">Next »</a>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get color for action type
     */
    private static function get_action_color($action_type) {
        if (strpos($action_type, 'created') !== false || strpos($action_type, 'signup') !== false) {
            return '#28a745'; // Green for creates/signups
        } elseif (strpos($action_type, 'deleted') !== false || strpos($action_type, 'cancelled') !== false) {
            return '#dc3545'; // Red for deletes/cancellations
        } elseif (strpos($action_type, 'updated') !== false || strpos($action_type, 'confirmed') !== false) {
            return '#ffc107'; // Yellow for updates/confirmations
        } else {
            return '#0073aa'; // Blue for other actions
        }
    }

    /**
     * Export audit log to CSV
     */
    public static function export_audit_log() {
        check_admin_referer('fs_export_audit_log', '_wpnonce_export');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
        $entity_type = isset($_POST['entity_type']) ? sanitize_text_field($_POST['entity_type']) : '';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';

        $args = array(
            'action_type' => $action_type,
            'entity_type' => $entity_type,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'limit' => 10000, // Max 10k records
            'offset' => 0
        );

        $logs = FS_Audit_Log::get_logs($args);

        // Set headers for CSV download
        $filename = 'audit-log-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Add header row
        fputcsv($output, array('Date/Time', 'Action Type', 'Description', 'User', 'Role', 'Entity Type', 'Entity ID', 'Details', 'IP Address'));

        // Add data rows
        foreach ($logs as $log) {
            fputcsv($output, array(
                date('Y-m-d H:i:s', strtotime($log->created_at)),
                $log->action_type,
                FS_Audit_Log::get_action_description($log),
                $log->user_name,
                $log->user_role,
                $log->entity_type,
                $log->entity_id,
                $log->details,
                $log->ip_address
            ));
        }

        fclose($output);
        exit;
    }
}
