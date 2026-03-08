<?php
if (!defined('ABSPATH')) exit;

/**
 * Advanced Scheduling Admin Dashboard
 * Manage waitlists, substitutes, and recurring schedules
 */
class FS_Admin_Advanced_Scheduling {

    public static function init() {
        add_action('admin_post_fs_clear_waitlist', array(__CLASS__, 'clear_waitlist'));
        add_action('admin_post_fs_export_scheduling_data', array(__CLASS__, 'export_data'));
        add_action('admin_init', array(__CLASS__, 'handle_availability_actions'));
    }

    /**
     * Handle availability deletion actions
     */
    public static function handle_availability_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== 'fs-advanced-scheduling') {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'delete_availability') {
            $availability_id = isset($_GET['availability_id']) ? intval($_GET['availability_id']) : 0;
            $volunteer_id = isset($_GET['volunteer_id']) ? intval($_GET['volunteer_id']) : 0;

            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fs_delete_availability_' . $availability_id)) {
                wp_die('Security check failed');
            }

            global $wpdb;
            $result = $wpdb->delete(
                "{$wpdb->prefix}fs_availability",
                array('id' => $availability_id)
            );

            if ($result) {
                FS_Audit_Log::log('availability_removed_admin', 'availability', $availability_id, array(
                    'admin_user_id' => get_current_user_id(),
                    'volunteer_id' => $volunteer_id
                ));

                wp_redirect(add_query_arg(array(
                    'page' => 'fs-advanced-scheduling',
                    'tab' => 'availability',
                    'volunteer_id' => $volunteer_id,
                    'message' => 'availability_deleted'
                ), admin_url('admin.php')));
                exit;
            } else {
                wp_die('Failed to delete availability slot');
            }
        }

        if ($action === 'delete_blackout') {
            $blackout_id = isset($_GET['blackout_id']) ? intval($_GET['blackout_id']) : 0;
            $volunteer_id = isset($_GET['volunteer_id']) ? intval($_GET['volunteer_id']) : 0;

            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fs_delete_blackout_' . $blackout_id)) {
                wp_die('Security check failed');
            }

            global $wpdb;
            $result = $wpdb->delete(
                "{$wpdb->prefix}fs_blackout_dates",
                array('id' => $blackout_id)
            );

            if ($result) {
                FS_Audit_Log::log('blackout_removed_admin', 'blackout_date', $blackout_id, array(
                    'admin_user_id' => get_current_user_id(),
                    'volunteer_id' => $volunteer_id
                ));

                wp_redirect(add_query_arg(array(
                    'page' => 'fs-advanced-scheduling',
                    'tab' => 'availability',
                    'volunteer_id' => $volunteer_id,
                    'message' => 'blackout_deleted'
                ), admin_url('admin.php')));
                exit;
            } else {
                wp_die('Failed to delete blackout date');
            }
        }
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        // Show success message if redirected after deletion
        if (isset($_GET['message'])) {
            $message = sanitize_text_field($_GET['message']);
            if ($message === 'availability_deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>Availability slot deleted successfully.</p></div>';
            } elseif ($message === 'blackout_deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>Blackout date deleted successfully.</p></div>';
            }
        }

        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'waitlists';

        ?>
        <div class="wrap">
            <h1>Advanced Scheduling</h1>
            <p>Manage waitlists, substitute requests, and recurring volunteer schedules.</p>

            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=fs-advanced-scheduling&tab=waitlists" class="nav-tab <?php echo $tab === 'waitlists' ? 'nav-tab-active' : ''; ?>">
                    Waitlists
                </a>
                <a href="?page=fs-advanced-scheduling&tab=substitutes" class="nav-tab <?php echo $tab === 'substitutes' ? 'nav-tab-active' : ''; ?>">
                    Substitute Requests
                </a>
                <a href="?page=fs-advanced-scheduling&tab=availability" class="nav-tab <?php echo $tab === 'availability' ? 'nav-tab-active' : ''; ?>">
                    Recurring Availability
                </a>
                <a href="?page=fs-advanced-scheduling&tab=auto-signups" class="nav-tab <?php echo $tab === 'auto-signups' ? 'nav-tab-active' : ''; ?>">
                    Auto-Signup Log
                </a>
            </h2>

            <?php
            switch ($tab) {
                case 'waitlists':
                    self::render_waitlists_tab();
                    break;
                case 'substitutes':
                    self::render_substitutes_tab();
                    break;
                case 'availability':
                    self::render_availability_tab();
                    break;
                case 'auto-signups':
                    self::render_auto_signups_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render waitlists tab
     */
    private static function render_waitlists_tab() {
        global $wpdb;

        $opportunity_id = isset($_GET['opportunity_id']) ? intval($_GET['opportunity_id']) : 0;

        // Get opportunities with waitlists
        $opportunities_with_waitlists = $wpdb->get_results(
            "SELECT o.id, o.title, o.event_date, COUNT(w.id) as waitlist_count
             FROM {$wpdb->prefix}fs_opportunities o
             JOIN {$wpdb->prefix}fs_waitlist w ON o.id = w.opportunity_id
             WHERE w.status = 'waiting'
             GROUP BY o.id
             ORDER BY o.event_date ASC"
        );

        // Statistics
        $total_waiting = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_waitlist WHERE status = 'waiting'");
        $total_promoted = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_waitlist WHERE status = 'promoted'");

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: white; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?php echo number_format($total_waiting); ?></div>
                <div style="color: #666; margin-top: 5px;">Currently Waiting</div>
            </div>
            <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($total_promoted); ?></div>
                <div style="color: #666; margin-top: 5px;">Successfully Promoted</div>
            </div>
            <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo count($opportunities_with_waitlists); ?></div>
                <div style="color: #666; margin-top: 5px;">Opportunities with Waitlists</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <h2>Waitlists by Opportunity</h2>

            <?php if (empty($opportunities_with_waitlists)): ?>
                <p style="text-align: center; color: #666; padding: 40px;">No active waitlists.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Opportunity</th>
                            <th>Date</th>
                            <th style="width: 100px;">Waiting</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($opportunities_with_waitlists as $opp): ?>
                            <tr>
                                <td><strong><?php echo esc_html($opp->title); ?></strong></td>
                                <td><?php echo date('M j, Y', strtotime($opp->event_date)); ?></td>
                                <td><span class="badge"><?php echo $opp->waitlist_count; ?></span></td>
                                <td>
                                    <a href="?page=fs-advanced-scheduling&tab=waitlists&opportunity_id=<?php echo $opp->id; ?>#waitlist-details" class="button button-small">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if ($opportunity_id): ?>
            <?php self::render_waitlist_details($opportunity_id); ?>
        <?php endif; ?>

        <style>
            .badge {
                background: #ffc107;
                color: white;
                padding: 4px 10px;
                border-radius: 12px;
                font-weight: 600;
                font-size: 14px;
            }
        </style>
        <?php
    }

    /**
     * Render waitlist details for specific opportunity
     */
    private static function render_waitlist_details($opportunity_id) {
        global $wpdb;

        // Get opportunity first so we can show details even if no waitlist
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            ?>
            <div class="notice notice-error">
                <p><strong>Error:</strong> Opportunity not found.</p>
            </div>
            <?php
            return;
        }

        $waitlist = FS_Waitlist_Manager::get_waitlist($opportunity_id);

        ?>
        <div id="waitlist-details" style="background: #f0f9ff; padding: 20px; margin: 20px 0; border: 2px solid #0073aa; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,115,170,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <div>
                    <h2 style="margin: 0; color: #0073aa;">📋 Waitlist Details</h2>
                    <h3 style="margin: 5px 0 0 0; font-weight: 600;"><?php echo esc_html($opportunity->title); ?></h3>
                    <p style="color: #666; margin: 5px 0 0 0;"><?php echo date('F j, Y', strtotime($opportunity->event_date)); ?> • <?php echo count($waitlist); ?> people waiting</p>
                </div>
                <a href="?page=fs-advanced-scheduling&tab=waitlists" class="button">← Back to List</a>
            </div>

            <?php if (empty($waitlist)): ?>
                <div style="background: #fff; padding: 40px; text-align: center; border: 2px dashed #ccc; border-radius: 4px;">
                    <p style="font-size: 16px; color: #666; margin: 0;">
                        <span style="font-size: 48px; display: block; margin-bottom: 10px;">📭</span>
                        <strong>No one is on the waitlist for this opportunity yet.</strong>
                    </p>
                    <p style="color: #999; margin-top: 10px;">
                        Volunteers can join the waitlist when this opportunity is full.
                    </p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Volunteer</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th style="width: 100px;">Rank Score</th>
                            <th style="width: 150px;">Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $position = 1; ?>
                        <?php foreach ($waitlist as $entry): ?>
                            <tr>
                                <td><strong><?php echo $position++; ?></strong></td>
                                <td><?php echo esc_html($entry->name); ?></td>
                                <td><?php echo esc_html($entry->email); ?></td>
                                <td><?php echo esc_html($entry->phone ?: '—'); ?></td>
                                <td><?php echo $entry->rank_score; ?></td>
                                <td><?php echo date('M j, g:i A', strtotime($entry->joined_at)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 20px; color: #666; font-size: 13px;">
                    Rank scores are calculated based on completed signups, hours volunteered, attendance record, and badges earned.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render substitutes tab
     */
    private static function render_substitutes_tab() {
        $active_requests = FS_Substitute_Finder::get_active_requests();

        global $wpdb;
        $total_pending = count($active_requests);
        $total_fulfilled = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_substitute_requests WHERE status = 'fulfilled'");
        $total_swaps = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_swap_history");

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: white; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?php echo number_format($total_pending); ?></div>
                <div style="color: #666; margin-top: 5px;">Pending Requests</div>
            </div>
            <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($total_fulfilled); ?></div>
                <div style="color: #666; margin-top: 5px;">Fulfilled</div>
            </div>
            <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_swaps); ?></div>
                <div style="color: #666; margin-top: 5px;">Total Swaps</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <h2>Active Substitute Requests (<?php echo count($active_requests); ?>)</h2>

            <?php if (empty($active_requests)): ?>
                <p style="text-align: center; color: #666; padding: 40px;">No active substitute requests.</p>
            <?php else: ?>
                <?php foreach ($active_requests as $request): ?>
                    <div style="background: #f9f9f9; padding: 20px; margin-bottom: 15px; border-radius: 4px; border-left: 4px solid #ffc107;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <div>
                                <strong style="font-size: 16px;"><?php echo esc_html($request->title); ?></strong><br>
                                <span style="color: #666;">Requested by: <?php echo esc_html($request->original_volunteer_name); ?></span>
                            </div>
                            <div style="text-align: right;">
                                <strong><?php echo date('M j, Y', strtotime($request->event_date)); ?></strong><br>
                                <span style="color: #666; font-size: 13px;">
                                    <?php echo human_time_diff(strtotime($request->requested_at), current_time('timestamp')); ?> ago
                                </span>
                            </div>
                        </div>

                        <?php if ($request->location): ?>
                            <p><strong>Location:</strong> <?php echo esc_html($request->location); ?></p>
                        <?php endif; ?>

                        <?php if ($request->reason): ?>
                            <p><strong>Reason:</strong> <?php echo nl2br(esc_html($request->reason)); ?></p>
                        <?php endif; ?>

                        <p style="margin: 0; color: #666; font-size: 13px;">
                            Status: Waiting for qualified volunteers to accept
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render availability tab
     */
    private static function render_availability_tab() {
        global $wpdb;

        $volunteer_id = isset($_GET['volunteer_id']) ? intval($_GET['volunteer_id']) : 0;

        // Get volunteers with availability set
        $volunteers_with_availability = $wpdb->get_results(
            "SELECT v.id, v.name, v.email, COUNT(a.id) as slot_count,
             SUM(a.auto_signup_enabled) as auto_enabled_count
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_availability a ON v.id = a.volunteer_id
             GROUP BY v.id
             ORDER BY auto_enabled_count DESC, slot_count DESC"
        );

        $total_volunteers = count($volunteers_with_availability);
        $total_with_auto = $wpdb->get_var(
            "SELECT COUNT(DISTINCT volunteer_id) FROM {$wpdb->prefix}fs_availability WHERE auto_signup_enabled = 1"
        );
        $total_blackouts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_blackout_dates");

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_volunteers); ?></div>
                <div style="color: #666; margin-top: 5px;">With Availability Set</div>
            </div>
            <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($total_with_auto); ?></div>
                <div style="color: #666; margin-top: 5px;">Auto-Signup Enabled</div>
            </div>
            <div style="background: white; border-left: 4px solid #dc3545; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #dc3545;"><?php echo number_format($total_blackouts); ?></div>
                <div style="color: #666; margin-top: 5px;">Active Blackout Dates</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <h2>Volunteers with Recurring Availability</h2>

            <?php if (empty($volunteers_with_availability)): ?>
                <p style="text-align: center; color: #666; padding: 40px;">No volunteers have set their availability yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Volunteer</th>
                            <th>Email</th>
                            <th style="width: 150px;">Availability Slots</th>
                            <th style="width: 150px;">Auto-Signup Slots</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($volunteers_with_availability as $vol): ?>
                            <tr>
                                <td><strong><?php echo esc_html($vol->name); ?></strong></td>
                                <td><?php echo esc_html($vol->email); ?></td>
                                <td><?php echo $vol->slot_count; ?></td>
                                <td>
                                    <?php if ($vol->auto_enabled_count > 0): ?>
                                        <span style="color: #28a745; font-weight: 600;">✓ <?php echo $vol->auto_enabled_count; ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?page=fs-advanced-scheduling&tab=availability&volunteer_id=<?php echo $vol->id; ?>#availability-details" class="button button-small">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if ($volunteer_id): ?>
            <?php self::render_availability_details($volunteer_id); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Render availability details for specific volunteer
     */
    private static function render_availability_details($volunteer_id) {
        global $wpdb;

        // Get volunteer info
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            ?>
            <div class="notice notice-error">
                <p><strong>Error:</strong> Volunteer not found.</p>
            </div>
            <?php
            return;
        }

        // Get availability slots
        $availability = FS_Recurring_Schedules::get_availability($volunteer_id);

        // Get blackout dates
        $blackouts = FS_Recurring_Schedules::get_blackout_dates($volunteer_id);

        ?>
        <div id="availability-details" style="background: #f0f9ff; padding: 20px; margin: 20px 0; border: 2px solid #0073aa; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,115,170,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <div>
                    <h2 style="margin: 0; color: #0073aa;">📅 Availability Details</h2>
                    <h3 style="margin: 5px 0 0 0; font-weight: 600;"><?php echo esc_html($volunteer->name); ?></h3>
                    <p style="color: #666; margin: 5px 0 0 0;"><?php echo esc_html($volunteer->email); ?></p>
                </div>
                <a href="?page=fs-advanced-scheduling&tab=availability" class="button">← Back to List</a>
            </div>

            <!-- Recurring Availability -->
            <div style="background: white; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
                <h3 style="margin-top: 0;">Recurring Availability</h3>

                <?php if (empty($availability)): ?>
                    <p style="color: #666; padding: 20px; text-align: center;">No recurring availability set for this volunteer.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Time Slot</th>
                                <th>Program</th>
                                <th style="width: 120px;">Auto-Signup</th>
                                <th style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($availability as $avail): ?>
                                <tr>
                                    <td><strong><?php echo ucfirst($avail->day_of_week); ?></strong></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $avail->time_slot)); ?></td>
                                    <td><?php echo $avail->program_name ? esc_html($avail->program_name) : '<span style="color: #999;">Any Program</span>'; ?></td>
                                    <td>
                                        <?php if ($avail->auto_signup_enabled): ?>
                                            <span style="color: #28a745; font-weight: 600;">✓ Enabled</span>
                                        <?php else: ?>
                                            <span style="color: #999;">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo wp_nonce_url(
                                            admin_url('admin.php?page=fs-advanced-scheduling&tab=availability&action=delete_availability&availability_id=' . $avail->id . '&volunteer_id=' . $volunteer_id),
                                            'fs_delete_availability_' . $avail->id
                                        ); ?>"
                                        onclick="return confirm('Remove this availability slot?');"
                                        class="button button-small"
                                        style="background: #dc3545; color: white; border-color: #dc3545;">
                                            Remove
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Blackout Dates -->
            <div style="background: white; padding: 20px; border-radius: 4px;">
                <h3 style="margin-top: 0;">Blackout Dates</h3>

                <?php if (empty($blackouts)): ?>
                    <p style="color: #666; padding: 20px; text-align: center;">No blackout dates set for this volunteer.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Reason</th>
                                <th style="width: 100px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blackouts as $blackout): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($blackout->start_date)); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($blackout->end_date)); ?></td>
                                    <td><?php echo $blackout->reason ? esc_html($blackout->reason) : '<span style="color: #999;">—</span>'; ?></td>
                                    <td>
                                        <a href="<?php echo wp_nonce_url(
                                            admin_url('admin.php?page=fs-advanced-scheduling&tab=availability&action=delete_blackout&blackout_id=' . $blackout->id . '&volunteer_id=' . $volunteer_id),
                                            'fs_delete_blackout_' . $blackout->id
                                        ); ?>"
                                        onclick="return confirm('Remove this blackout date?');"
                                        class="button button-small"
                                        style="background: #dc3545; color: white; border-color: #dc3545;">
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
        <?php
    }

    /**
     * Render auto-signups tab
     */
    private static function render_auto_signups_tab() {
        global $wpdb;

        $limit = 100;
        $log_entries = $wpdb->get_results(
            "SELECT l.*, o.title, o.event_date, v.name as volunteer_name
             FROM {$wpdb->prefix}fs_auto_signup_log l
             JOIN {$wpdb->prefix}fs_opportunities o ON l.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_volunteers v ON l.volunteer_id = v.id
             ORDER BY l.processed_at DESC
             LIMIT $limit"
        );

        $success_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_auto_signup_log WHERE success = 1");
        $failure_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_auto_signup_log WHERE success = 0");
        $success_rate = ($success_count + $failure_count) > 0
            ? round(($success_count / ($success_count + $failure_count)) * 100)
            : 0;

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($success_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Successful Auto-Signups</div>
            </div>
            <div style="background: white; border-left: 4px solid #dc3545; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #dc3545;"><?php echo number_format($failure_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Failed Attempts</div>
            </div>
            <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo $success_rate; ?>%</div>
                <div style="color: #666; margin-top: 5px;">Success Rate</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <h2>Recent Auto-Signup Attempts (Last <?php echo count($log_entries); ?>)</h2>

            <?php if (empty($log_entries)): ?>
                <p style="text-align: center; color: #666; padding: 40px;">No auto-signup attempts logged yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;">Date/Time</th>
                            <th>Volunteer</th>
                            <th>Opportunity</th>
                            <th style="width: 100px;">Result</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log_entries as $entry): ?>
                            <tr>
                                <td>
                                    <?php echo date('M j, Y', strtotime($entry->processed_at)); ?><br>
                                    <small style="color: #666;"><?php echo date('g:i A', strtotime($entry->processed_at)); ?></small>
                                </td>
                                <td><?php echo esc_html($entry->volunteer_name); ?></td>
                                <td>
                                    <strong><?php echo esc_html($entry->title); ?></strong><br>
                                    <small style="color: #666;"><?php echo date('M j, Y', strtotime($entry->event_date)); ?></small>
                                </td>
                                <td>
                                    <?php if ($entry->success): ?>
                                        <span style="color: #28a745; font-weight: 600;">✓ Success</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545; font-weight: 600;">✗ Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($entry->reason); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Export scheduling data to CSV
     */
    public static function export_data() {
        check_admin_referer('fs_export_scheduling', '_wpnonce_export');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'waitlists';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=advanced-scheduling-' . $type . '-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        global $wpdb;

        if ($type === 'waitlists') {
            fputcsv($output, array('Volunteer', 'Email', 'Opportunity', 'Event Date', 'Rank Score', 'Joined', 'Status'));

            $waitlists = $wpdb->get_results(
                "SELECT w.*, v.name, v.email, o.title, o.event_date
                 FROM {$wpdb->prefix}fs_waitlist w
                 JOIN {$wpdb->prefix}fs_volunteers v ON w.volunteer_id = v.id
                 JOIN {$wpdb->prefix}fs_opportunities o ON w.opportunity_id = o.id
                 ORDER BY w.joined_at DESC"
            );

            foreach ($waitlists as $entry) {
                fputcsv($output, array(
                    $entry->name,
                    $entry->email,
                    $entry->title,
                    $entry->event_date,
                    $entry->rank_score,
                    date('Y-m-d H:i:s', strtotime($entry->joined_at)),
                    $entry->status
                ));
            }
        }

        fclose($output);
        exit;
    }
}
