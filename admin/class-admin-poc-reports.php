<?php
if (!defined('ABSPATH')) exit;

/**
 * POC Reports Dashboard
 * Provides Point of Contact users with reporting and analytics for their opportunities
 */
class FS_Admin_POC_Reports {

    public static function init() {
        add_action('admin_post_fs_export_volunteer_list', array(__CLASS__, 'export_volunteer_list'));
        add_action('admin_post_fs_export_attendance_summary', array(__CLASS__, 'export_attendance_summary'));
        add_action('admin_post_fs_send_bulk_email', array(__CLASS__, 'send_bulk_email'));
    }

    public static function render_page() {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        // Check if user is POC or admin
        if (!FS_POC_Role::current_user_is_poc()) {
            wp_die('You do not have permission to access this page.');
        }

        // Get selected opportunity if any
        $selected_opp_id = isset($_GET['opportunity_id']) ? intval($_GET['opportunity_id']) : 0;

        // Get POC opportunities
        $opportunities = FS_POC_Role::get_poc_opportunities($user_id);

        // Handle success messages
        if (isset($_GET['email_sent'])) {
            echo '<div class="notice notice-success"><p>Bulk email sent successfully!</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>Reports & Analytics</h1>
            <p>Comprehensive reports for your opportunities and volunteers.</p>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Select Opportunity</h2>
                <form method="get" action="">
                    <input type="hidden" name="page" value="fs-poc-reports">
                    <select name="opportunity_id" id="opportunity_select" style="min-width: 400px;">
                        <option value="">— Select an Opportunity —</option>
                        <?php foreach ($opportunities as $opp): ?>
                            <option value="<?php echo $opp->id; ?>" <?php selected($selected_opp_id, $opp->id); ?>>
                                <?php echo esc_html($opp->title); ?> - <?php echo date('M j, Y', strtotime($opp->event_date)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-primary">View Reports</button>
                </form>
            </div>

            <?php if ($selected_opp_id): ?>
                <?php
                global $wpdb;

                // Get opportunity details
                $opportunity = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
                    $selected_opp_id
                ));

                if (!$opportunity) {
                    echo '<div class="notice notice-error"><p>Opportunity not found.</p></div>';
                    return;
                }

                // Get signups
                $signups = $wpdb->get_results($wpdb->prepare(
                    "SELECT s.*, v.name, v.email, v.phone, v.volunteer_status,
                            sh.shift_start_time, sh.shift_end_time
                     FROM {$wpdb->prefix}fs_signups s
                     JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
                     LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
                     WHERE s.opportunity_id = %d
                     AND s.status = 'confirmed'
                     ORDER BY v.name ASC",
                    $selected_opp_id
                ));

                // Get time records for this opportunity
                $time_records = $wpdb->get_results($wpdb->prepare(
                    "SELECT tr.*, v.name
                     FROM {$wpdb->prefix}fs_time_records tr
                     JOIN {$wpdb->prefix}fs_volunteers v ON tr.volunteer_id = v.id
                     WHERE tr.opportunity_id = %d
                     ORDER BY tr.check_in DESC",
                    $selected_opp_id
                ));

                // Get team signups
                $team_signups = $wpdb->get_results($wpdb->prepare(
                    "SELECT ts.*, t.name as team_name
                     FROM {$wpdb->prefix}fs_team_signups ts
                     JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
                     WHERE ts.opportunity_id = %d
                     AND ts.status != 'cancelled'",
                    $selected_opp_id
                ));

                // Get team attendance records
                $team_attendance = $wpdb->get_results($wpdb->prepare(
                    "SELECT ta.*, t.name as team_name
                     FROM {$wpdb->prefix}fs_team_attendance ta
                     JOIN {$wpdb->prefix}fs_teams t ON ta.team_id = t.id
                     WHERE ta.opportunity_id = %d
                     ORDER BY ta.check_in_time DESC",
                    $selected_opp_id
                ));

                // Calculate statistics
                $total_signups = count($signups) + array_sum(array_map(function($ts) { return $ts->scheduled_size; }, $team_signups));
                $total_checked_in = count($time_records) + array_sum(array_map(function($ta) { return $ta->people_count; }, $team_attendance));
                $no_shows = $total_signups - $total_checked_in;
                $total_hours = array_sum(array_column($time_records, 'total_hours')) + array_sum(array_column($team_attendance, 'total_hours'));

                ?>

                <div class="opportunity-header" style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h2><?php echo esc_html($opportunity->title); ?></h2>
                    <p><strong>Date:</strong> <?php echo date('l, F j, Y', strtotime($opportunity->event_date)); ?></p>
                    <p><strong>Location:</strong> <?php echo esc_html($opportunity->location ?: '—'); ?></p>
                </div>

                <!-- Statistics Dashboard -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
                    <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo $total_signups; ?></div>
                        <div style="color: #666; margin-top: 5px;">Total Signups</div>
                    </div>
                    <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo $total_checked_in; ?></div>
                        <div style="color: #666; margin-top: 5px;">Checked In</div>
                    </div>
                    <div style="background: white; border-left: 4px solid #dc3545; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 32px; font-weight: bold; color: #dc3545;"><?php echo $no_shows; ?></div>
                        <div style="color: #666; margin-top: 5px;">No-Shows</div>
                    </div>
                    <div style="background: white; border-left: 4px solid #667eea; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="font-size: 32px; font-weight: bold; color: #667eea;"><?php echo number_format($total_hours, 2); ?></div>
                        <div style="color: #666; margin-top: 5px;">Total Hours</div>
                    </div>
                </div>

                <!-- Export Actions -->
                <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h3>Export Reports</h3>
                    <p>Download data for offline analysis or record keeping.</p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; margin-right: 10px;">
                        <?php wp_nonce_field('fs_export_volunteer_list', '_wpnonce_export'); ?>
                        <input type="hidden" name="action" value="fs_export_volunteer_list">
                        <input type="hidden" name="opportunity_id" value="<?php echo $selected_opp_id; ?>">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export Volunteer List (CSV)
                        </button>
                    </form>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                        <?php wp_nonce_field('fs_export_attendance_summary', '_wpnonce_attendance'); ?>
                        <input type="hidden" name="action" value="fs_export_attendance_summary">
                        <input type="hidden" name="opportunity_id" value="<?php echo $selected_opp_id; ?>">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export Attendance Summary (CSV)
                        </button>
                    </form>
                </div>

                <!-- Bulk Email to Signups -->
                <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h3>Send Bulk Email to Volunteers</h3>
                    <p>Send a message to all volunteers signed up for this opportunity.</p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('Send email to <?php echo $total_signups; ?> volunteer(s)?');">
                        <?php wp_nonce_field('fs_send_bulk_email', '_wpnonce_bulk'); ?>
                        <input type="hidden" name="action" value="fs_send_bulk_email">
                        <input type="hidden" name="opportunity_id" value="<?php echo $selected_opp_id; ?>">

                        <div style="margin-bottom: 15px;">
                            <label for="email_subject" style="display: block; margin-bottom: 5px;"><strong>Subject:</strong></label>
                            <input type="text" name="email_subject" id="email_subject" required style="width: 100%; max-width: 600px;" placeholder="Enter email subject">
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label for="email_template" style="display: block; margin-bottom: 5px;"><strong>Quick Templates:</strong></label>
                            <select id="email_template" style="max-width: 600px;" onchange="applyTemplate(this.value)">
                                <option value="">— Select a template —</option>
                                <option value="reminder">Pre-Event Reminder</option>
                                <option value="update">Last-Minute Update</option>
                                <option value="thank_you">Thank You Message</option>
                                <option value="cancellation">Event Cancellation</option>
                            </select>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label for="email_message" style="display: block; margin-bottom: 5px;"><strong>Message:</strong></label>
                            <textarea name="email_message" id="email_message" required rows="10" style="width: 100%; max-width: 600px;" placeholder="Enter your message to volunteers"></textarea>
                            <p class="description">Available placeholders: {volunteer_name}, {opportunity_title}, {event_date}, {location}</p>
                        </div>

                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-email" style="margin-top: 3px;"></span> Send Email to All Signups
                        </button>
                    </form>
                </div>

                <script>
                function applyTemplate(template) {
                    const subjectField = document.getElementById('email_subject');
                    const messageField = document.getElementById('email_message');
                    const oppTitle = <?php echo json_encode($opportunity->title); ?>;
                    const eventDate = <?php echo json_encode(date('l, F j, Y', strtotime($opportunity->event_date))); ?>;
                    const location = <?php echo json_encode($opportunity->location); ?>;

                    if (template === 'reminder') {
                        subjectField.value = 'Reminder: ' + oppTitle + ' on ' + eventDate;
                        messageField.value = `Hello {volunteer_name},

This is a friendly reminder about your upcoming volunteer shift:

**{opportunity_title}**
Date: {event_date}
Location: {location}

Please make sure to arrive 10 minutes early. If you need to cancel, please do so as soon as possible through your volunteer portal.

Thank you for volunteering!`;
                    } else if (template === 'update') {
                        subjectField.value = 'Important Update: ' + oppTitle;
                        messageField.value = `Hello {volunteer_name},

We have an important update regarding your volunteer shift:

**{opportunity_title}**
Date: {event_date}

[Add your update details here]

If you have any questions, please don't hesitate to reach out.

Thank you!`;
                    } else if (template === 'thank_you') {
                        subjectField.value = 'Thank You for Volunteering!';
                        messageField.value = `Dear {volunteer_name},

Thank you so much for volunteering at {opportunity_title} on {event_date}!

Your dedication and hard work made a real difference. We truly appreciate the time and energy you contributed.

We hope to see you at future volunteer opportunities!

With gratitude,
The Volunteer Team`;
                    } else if (template === 'cancellation') {
                        subjectField.value = 'Event Cancelled: ' + oppTitle;
                        messageField.value = `Hello {volunteer_name},

Unfortunately, we need to inform you that the following volunteer opportunity has been cancelled:

**{opportunity_title}**
Originally scheduled for: {event_date}

We apologize for any inconvenience this may cause. Please check your volunteer portal for other upcoming opportunities.

Thank you for your understanding.`;
                    }
                }
                </script>

                <!-- Volunteer List -->
                <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                    <h3>Volunteer Signups</h3>
                    <?php if (!empty($signups) || !empty($team_signups)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Checked In</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Show individual volunteers
                                foreach ($signups as $signup) {
                                    $checked_in = false;
                                    $hours = 0;
                                    foreach ($time_records as $tr) {
                                        if ($tr->volunteer_id == $signup->volunteer_id) {
                                            $checked_in = true;
                                            $hours = $tr->total_hours;
                                            break;
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($signup->name); ?></strong></td>
                                        <td><?php echo esc_html($signup->email); ?></td>
                                        <td><?php echo esc_html($signup->phone ?: '—'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($signup->volunteer_status); ?>">
                                                <?php echo esc_html($signup->volunteer_status); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($checked_in): ?>
                                                <span style="color: #28a745;">✓ Yes</span>
                                            <?php else: ?>
                                                <span style="color: #dc3545;">✗ No-Show</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $hours ? number_format($hours, 2) : '—'; ?></td>
                                    </tr>
                                <?php } ?>

                                <?php
                                // Show team signups
                                foreach ($team_signups as $team) {
                                    $checked_in = false;
                                    $hours = 0;
                                    $people_count = $team->scheduled_size;
                                    foreach ($team_attendance as $ta) {
                                        if ($ta->team_id == $team->team_id) {
                                            $checked_in = true;
                                            $hours = $ta->total_hours;
                                            $people_count = $ta->people_count;
                                            break;
                                        }
                                    }
                                    ?>
                                    <tr style="background: #f0f8ff;">
                                        <td><strong>👥 <?php echo esc_html($team->team_name); ?></strong><br><small>(Team - <?php echo $people_count; ?> people)</small></td>
                                        <td colspan="2">—</td>
                                        <td><span class="status-badge status-active">Team</span></td>
                                        <td>
                                            <?php if ($checked_in): ?>
                                                <span style="color: #28a745;">✓ Yes</span>
                                            <?php else: ?>
                                                <span style="color: #dc3545;">✗ No-Show</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $hours ? number_format($hours, 2) : '—'; ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color: #666; font-style: italic;">No signups yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Attendance Details -->
                <?php if (!empty($time_records) || !empty($team_attendance)): ?>
                    <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                        <h3>Attendance Details</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Volunteer/Team</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($time_records as $tr): ?>
                                    <tr>
                                        <td><?php echo esc_html($tr->name); ?></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($tr->check_in)); ?></td>
                                        <td><?php echo $tr->check_out ? date('M j, Y g:i A', strtotime($tr->check_out)) : '—'; ?></td>
                                        <td><?php echo $tr->total_hours ? number_format($tr->total_hours, 2) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ($team_attendance as $ta): ?>
                                    <tr style="background: #f0f8ff;">
                                        <td><strong>👥 <?php echo esc_html($ta->team_name); ?></strong> (<?php echo $ta->people_count; ?> people)</td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($ta->check_in_time)); ?></td>
                                        <td><?php echo $ta->check_out_time ? date('M j, Y g:i A', strtotime($ta->check_out_time)) : '—'; ?></td>
                                        <td><?php echo $ta->total_hours ? number_format($ta->total_hours, 2) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

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
            </style>
        </div>
        <?php
    }

    /**
     * Export volunteer list to CSV
     */
    public static function export_volunteer_list() {
        check_admin_referer('fs_export_volunteer_list', '_wpnonce_export');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_die('Unauthorized');
        }

        $opportunity_id = intval($_POST['opportunity_id']);

        global $wpdb;

        // Get opportunity
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            wp_die('Opportunity not found');
        }

        // Get signups
        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, v.name, v.email, v.phone, v.volunteer_status
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             WHERE s.opportunity_id = %d
             AND s.status = 'confirmed'
             ORDER BY v.name ASC",
            $opportunity_id
        ));

        // Get team signups
        $team_signups = $wpdb->get_results($wpdb->prepare(
            "SELECT ts.*, t.name as team_name
             FROM {$wpdb->prefix}fs_team_signups ts
             JOIN {$wpdb->prefix}fs_teams t ON ts.team_id = t.id
             WHERE ts.opportunity_id = %d
             AND ts.status != 'cancelled'",
            $opportunity_id
        ));

        // Set headers for CSV download
        $filename = 'volunteer-list-' . sanitize_title($opportunity->title) . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Add header row
        fputcsv($output, array('Name', 'Email', 'Phone', 'Type', 'Status', 'Signup Date'));

        // Add individual volunteers
        foreach ($signups as $signup) {
            fputcsv($output, array(
                $signup->name,
                $signup->email,
                $signup->phone,
                'Individual',
                $signup->volunteer_status,
                date('Y-m-d H:i:s', strtotime($signup->signup_date))
            ));
        }

        // Add teams
        foreach ($team_signups as $team) {
            fputcsv($output, array(
                $team->team_name . ' (Team)',
                '—',
                '—',
                'Team (' . $team->scheduled_size . ' people)',
                'Active',
                date('Y-m-d H:i:s', strtotime($team->signup_date))
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Export attendance summary to CSV
     */
    public static function export_attendance_summary() {
        check_admin_referer('fs_export_attendance_summary', '_wpnonce_attendance');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_die('Unauthorized');
        }

        $opportunity_id = intval($_POST['opportunity_id']);

        global $wpdb;

        // Get opportunity
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            wp_die('Opportunity not found');
        }

        // Get time records
        $time_records = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.*, v.name, v.email
             FROM {$wpdb->prefix}fs_time_records tr
             JOIN {$wpdb->prefix}fs_volunteers v ON tr.volunteer_id = v.id
             WHERE tr.opportunity_id = %d
             ORDER BY tr.check_in DESC",
            $opportunity_id
        ));

        // Get team attendance
        $team_attendance = $wpdb->get_results($wpdb->prepare(
            "SELECT ta.*, t.name as team_name
             FROM {$wpdb->prefix}fs_team_attendance ta
             JOIN {$wpdb->prefix}fs_teams t ON ta.team_id = t.id
             WHERE ta.opportunity_id = %d
             ORDER BY ta.check_in_time DESC",
            $opportunity_id
        ));

        // Set headers for CSV download
        $filename = 'attendance-summary-' . sanitize_title($opportunity->title) . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Add header row
        fputcsv($output, array('Name', 'Email', 'Type', 'People Count', 'Check In', 'Check Out', 'Hours'));

        // Add individual records
        foreach ($time_records as $tr) {
            fputcsv($output, array(
                $tr->name,
                $tr->email,
                'Individual',
                '1',
                date('Y-m-d H:i:s', strtotime($tr->check_in)),
                $tr->check_out ? date('Y-m-d H:i:s', strtotime($tr->check_out)) : '',
                $tr->total_hours ?: ''
            ));
        }

        // Add team records
        foreach ($team_attendance as $ta) {
            fputcsv($output, array(
                $ta->team_name . ' (Team)',
                '—',
                'Team',
                $ta->people_count,
                date('Y-m-d H:i:s', strtotime($ta->check_in_time)),
                $ta->check_out_time ? date('Y-m-d H:i:s', strtotime($ta->check_out_time)) : '',
                $ta->total_hours ?: ''
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * Send bulk email to all volunteers signed up for an opportunity
     */
    public static function send_bulk_email() {
        check_admin_referer('fs_send_bulk_email', '_wpnonce_bulk');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_die('Unauthorized');
        }

        $opportunity_id = intval($_POST['opportunity_id']);
        $subject = sanitize_text_field($_POST['email_subject']);
        $message = wp_kses_post($_POST['email_message']);

        global $wpdb;

        // Get opportunity
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            wp_die('Opportunity not found');
        }

        // Get all individual volunteers signed up
        $volunteers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT v.id, v.name, v.email, v.access_token
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
             WHERE s.opportunity_id = %d
             AND s.status = 'confirmed'",
            $opportunity_id
        ));

        // Get team members
        $team_members = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT v.id, v.name, v.email, v.access_token
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_team_members tm ON v.id = tm.volunteer_id
             JOIN {$wpdb->prefix}fs_team_signups ts ON tm.team_id = ts.team_id
             WHERE ts.opportunity_id = %d
             AND ts.status != 'cancelled'
             AND tm.status = 'active'",
            $opportunity_id
        ));

        // Merge and deduplicate volunteers
        $all_volunteers = array_merge($volunteers, $team_members);
        $seen = array();
        $unique_volunteers = array();
        foreach ($all_volunteers as $vol) {
            if (!in_array($vol->id, $seen)) {
                $seen[] = $vol->id;
                $unique_volunteers[] = $vol;
            }
        }

        // Send emails
        $sent_count = 0;
        foreach ($unique_volunteers as $volunteer) {
            // Replace placeholders
            $personalized_message = str_replace(
                array('{volunteer_name}', '{opportunity_title}', '{event_date}', '{location}'),
                array(
                    $volunteer->name,
                    $opportunity->title,
                    date('l, F j, Y', strtotime($opportunity->event_date)),
                    $opportunity->location
                ),
                $message
            );

            // Build portal link
            if (!empty($volunteer->access_token)) {
                $portal_url = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal'));
            } else {
                $portal_url = home_url('/volunteer-portal');
            }

            // Build email
            $email_body = "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto;'>
                    " . wpautop($personalized_message) . "

                    <p style='margin-top: 30px;'>
                        <a href='{$portal_url}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>View in Portal</a>
                    </p>

                    <p style='margin-top: 30px; color: #666; font-size: 14px;'>
                        This is an automated message from your volunteer coordinator.
                    </p>
                </div>
            </body>
            </html>
            ";

            $headers = array('Content-Type: text/html; charset=UTF-8');

            if (wp_mail($volunteer->email, $subject, $email_body, $headers)) {
                $sent_count++;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("FriendShyft: Bulk email sent to {$volunteer->email} for opportunity {$opportunity_id}");
            }
        }

        // Redirect back with success message
        wp_redirect(add_query_arg(
            array('page' => 'fs-poc-reports', 'opportunity_id' => $opportunity_id, 'email_sent' => $sent_count),
            admin_url('admin.php')
        ));
        exit;
    }
}
