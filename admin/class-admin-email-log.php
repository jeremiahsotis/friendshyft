<?php
if (!defined('ABSPATH')) exit;

/**
 * Email Parse Log Admin Page
 */
class FS_Admin_Email_Log {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 26);
        add_action('admin_post_fs_reprocess_email', array(__CLASS__, 'reprocess_email'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Email Parse Log',
            'Email Log',
            'manage_options',
            'fs-email-log',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function render_page() {
        global $wpdb;
        
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        
        // Build query
        $where = "1=1";
        if ($status_filter !== 'all') {
            $where .= $wpdb->prepare(" AND status = %s", $status_filter);
        }
        
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_email_log 
             WHERE $where 
             ORDER BY received_date DESC 
             LIMIT 100"
        );
        
        // Parse JSON data for display
        foreach ($logs as $log) {
            $parsed = json_decode($log->parsed_data, true);
            $log->parsed_name = $parsed['name'] ?? null;
            $log->parsed_email = $parsed['email'] ?? null;
            $log->parsed_phone = $parsed['phone'] ?? null;
            $log->parsed_interest = $parsed['interest'] ?? null;
        }
        
        // Get status counts
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$wpdb->prefix}fs_email_log 
             GROUP BY status",
            OBJECT_K
        );
        
        ?>
        <div class="wrap">
            <h1>Email Parse Log</h1>
            
            <?php if (isset($_GET['reprocessed'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Email reprocessed successfully!</p>
                </div>
            <?php endif; ?>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select onchange="window.location.href='<?php echo admin_url('admin.php?page=fs-email-log&status='); ?>' + this.value">
                        <option value="all" <?php selected($status_filter, 'all'); ?>>
                            All (<?php echo array_sum(array_map(function($s) { return $s->count; }, $status_counts)); ?>)
                        </option>
                        <option value="success" <?php selected($status_filter, 'success'); ?>>
                            Success (<?php echo $status_counts['success']->count ?? 0; ?>)
                        </option>
                        <option value="duplicate" <?php selected($status_filter, 'duplicate'); ?>>
                            Duplicate (<?php echo $status_counts['duplicate']->count ?? 0; ?>)
                        </option>
                        <option value="failed" <?php selected($status_filter, 'failed'); ?>>
                            Failed (<?php echo $status_counts['failed']->count ?? 0; ?>)
                        </option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>>
                            Pending (<?php echo $status_counts['pending']->count ?? 0; ?>)
                        </option>
                    </select>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Interest</th>
                        <th>Volunteer</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 20px;">
                                No emails logged yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(date('M j, Y g:ia', strtotime($log->received_date))); ?></td>
                                <td>
                                    <?php
                                    $status_classes = array(
                                        'success' => 'success',
                                        'success_no_email' => 'warning',
                                        'duplicate' => 'warning',
                                        'failed' => 'error',
                                        'pending' => 'info'
                                    );
                                    $class = $status_classes[$log->status] ?? 'info';
                                    ?>
                                    <span class="dashicons dashicons-<?php 
                                        echo $log->status === 'success' || $log->status === 'success_no_email' ? 'yes' : 
                                             ($log->status === 'duplicate' ? 'warning' : 'no'); 
                                    ?>"></span>
                                    <span style="color: <?php 
                                        echo $log->status === 'success' || $log->status === 'success_no_email' ? '#46b450' : 
                                             ($log->status === 'duplicate' ? '#ffb900' : '#dc3232'); 
                                    ?>;">
                                        <?php echo esc_html(ucwords(str_replace('_', ' ', $log->status))); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->parsed_name ?: '—'); ?></td>
                                <td><?php echo esc_html($log->parsed_email ?: '—'); ?></td>
                                <td><?php echo esc_html($log->parsed_phone ?: '—'); ?></td>
                                <td><?php echo esc_html($log->parsed_interest ?: '—'); ?></td>
                                <td>
                                    <?php if ($log->volunteer_id): ?>
                                        <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $log->volunteer_id); ?>">
                                            #<?php echo $log->volunteer_id; ?>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small" 
                                            onclick="viewDetails(<?php echo $log->id; ?>)">
                                        View
                                    </button>
                                    
                                    <?php if ($log->status !== 'success' && $log->status !== 'success_no_email'): ?>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="fs_reprocess_email">
                                            <input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                                            <?php wp_nonce_field('fs_reprocess_email_' . $log->id); ?>
                                            <button type="submit" class="button button-small">Reprocess</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Details Modal -->
        <div id="email-details-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                                              background: rgba(0,0,0,0.7); z-index: 100000; overflow: auto;">
            <div style="background: white; max-width: 800px; margin: 50px auto; padding: 20px; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
                    <h2>Email Details</h2>
                    <button type="button" class="button" onclick="closeDetails()">Close</button>
                </div>
                <div id="email-details-content"></div>
            </div>
        </div>
        
        <script>
        const emailDetails = <?php echo json_encode(array_map(function($log) {
            return array(
                'id' => $log->id,
                'received_date' => $log->received_date,
                'status' => $log->status,
                'parsed_name' => $log->parsed_name,
                'parsed_email' => $log->parsed_email,
                'parsed_phone' => $log->parsed_phone,
                'parsed_interest' => $log->parsed_interest,
                'error_message' => $log->error_message,
                'volunteer_id' => $log->volunteer_id,
                'raw_body' => $log->raw_body,
                'from_address' => $log->from_address,
                'subject' => $log->subject
            );
        }, $logs)); ?>;
        
        function viewDetails(logId) {
            const log = emailDetails.find(l => l.id === logId);
            if (!log) return;
            
            let html = '<table class="form-table">';
            html += '<tr><th>Received:</th><td>' + log.received_date + '</td></tr>';
            html += '<tr><th>From:</th><td>' + log.from_address + '</td></tr>';
            html += '<tr><th>Subject:</th><td>' + log.subject + '</td></tr>';
            html += '<tr><th>Status:</th><td>' + log.status + '</td></tr>';
            
            if (log.error_message) {
                html += '<tr><th>Error:</th><td style="color: #dc3232;">' + log.error_message + '</td></tr>';
            }
            
            html += '<tr><th>Parsed Name:</th><td>' + (log.parsed_name || '—') + '</td></tr>';
            html += '<tr><th>Parsed Email:</th><td>' + (log.parsed_email || '—') + '</td></tr>';
            html += '<tr><th>Parsed Phone:</th><td>' + (log.parsed_phone || '—') + '</td></tr>';
            html += '<tr><th>Parsed Interest:</th><td>' + (log.parsed_interest || '—') + '</td></tr>';
            
            if (log.volunteer_id) {
                html += '<tr><th>Volunteer:</th><td><a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id='); ?>' + 
                        log.volunteer_id + '">#' + log.volunteer_id + '</a></td></tr>';
            }
            
            html += '<tr><th>Raw Email:</th><td><pre style="background: #f5f5f5; padding: 10px; overflow: auto; max-height: 400px;">' + 
                    log.raw_body + '</pre></td></tr>';
            html += '</table>';
            
            document.getElementById('email-details-content').innerHTML = html;
            document.getElementById('email-details-modal').style.display = 'block';
        }
        
        function closeDetails() {
            document.getElementById('email-details-modal').style.display = 'none';
        }
        
        // Close modal on background click
        document.getElementById('email-details-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetails();
            }
        });
        </script>
        <?php
    }
    
    public static function reprocess_email() {
        $log_id = intval($_POST['log_id'] ?? 0);
        check_admin_referer('fs_reprocess_email_' . $log_id);
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_email_log WHERE id = %d",
            $log_id
        ));
        
        if (!$log) {
            wp_die('Email log not found');
        }
        
        // Reprocess the email
        $email_data = array(
            'from' => $log->from_address,
            'subject' => $log->subject,
            'body' => $log->raw_body
        );
        
        FS_Email_Processor::process_email($email_data);
        
        wp_redirect(admin_url('admin.php?page=fs-email-log&reprocessed=1'));
        exit;
    }
}
