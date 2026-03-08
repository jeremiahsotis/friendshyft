<?php
if (!defined('ABSPATH')) exit;

/**
 * Database Sync Admin Page
 * Manually trigger table creation and cron job scheduling
 */
class FS_Admin_Database_Sync {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 100);
        add_action('admin_post_fs_sync_database', array(__CLASS__, 'handle_sync'));
    }

    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Database Sync',
            'Database Sync',
            'manage_options',
            'friendshyft-database-sync',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        global $wpdb;

        // Check which tables exist
        $all_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}fs_%'");

        $expected_tables = array(
            // Core tables
            'fs_volunteers',
            'fs_roles',
            'fs_programs',
            'fs_opportunities',
            'fs_signups',
            'fs_time_records',
            'fs_volunteer_interests',
            'fs_volunteer_roles',
            'fs_opportunity_templates',
            'fs_holidays',
            'fs_workflows',
            'fs_progress',

            // Team management
            'fs_teams',
            'fs_team_members',
            'fs_team_attendance',
            'fs_team_signups',

            // Email ingestion
            'fs_email_log',

            // Portal enhancements
            'fs_opportunity_shifts',
            'fs_handoff_notifications',

            // Badges
            'fs_volunteer_badges',

            // Audit log
            'fs_audit_log',

            // Google Calendar (Session 7)
            'fs_blocked_times',

            // Feedback (Session 7)
            'fs_surveys',
            'fs_suggestions',
            'fs_testimonials',

            // Advanced Scheduling (Session 8)
            'fs_waitlist',
            'fs_substitute_requests',
            'fs_swap_history',
            'fs_availability',
            'fs_blackout_dates',
            'fs_auto_signup_log',

            // Analytics (Session 9)
            'fs_predictions',
            'fs_engagement_scores',
            'fs_reengagement_campaigns',
        );

        $missing_tables = array();
        $existing_tables = array();

        foreach ($expected_tables as $table) {
            $full_name = $wpdb->prefix . $table;
            if (in_array($full_name, $all_tables)) {
                $existing_tables[] = $table;
            } else {
                $missing_tables[] = $table;
            }
        }

        // Check cron jobs
        $expected_crons = array(
            'fs_send_attendance_reminders',
            'fs_generate_opportunities_cron',
            'fs_check_imap_inbox',
            'fs_sync_cron',
            'fs_daily_handoff_check',
            'fs_check_google_calendar_cron',
            'fs_send_event_surveys_cron',
            'fs_process_auto_signups_cron',
            'fs_update_engagement_scores_cron',
            'fs_send_reengagement_campaigns_cron',
            'fs_update_predictions_cron',
        );

        $missing_crons = array();
        $existing_crons = array();

        foreach ($expected_crons as $cron) {
            if (wp_next_scheduled($cron)) {
                $existing_crons[] = $cron;
            } else {
                $missing_crons[] = $cron;
            }
        }

        ?>
        <div class="wrap">
            <h1>🔧 Database Sync & Repair</h1>

            <div class="notice notice-info">
                <p><strong>Purpose:</strong> This tool synchronizes your database with the latest plugin code by creating missing tables and scheduling missing cron jobs.</p>
                <p><strong>When to use:</strong> After updating the plugin or if tables are missing.</p>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>📊 Database Status</h2>

                <h3 style="color: #46b450;">✓ Existing Tables (<?php echo count($existing_tables); ?>)</h3>
                <ul style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
                    <?php foreach ($existing_tables as $table): ?>
                        <li style="color: #46b450;">✓ <?php echo esc_html($table); ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!empty($missing_tables)): ?>
                    <h3 style="color: #dc3545;">✗ Missing Tables (<?php echo count($missing_tables); ?>)</h3>
                    <ul style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
                        <?php foreach ($missing_tables as $table): ?>
                            <li style="color: #dc3545;">✗ <?php echo esc_html($table); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="color: #46b450; font-weight: bold;">✓ All tables exist!</p>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>⏰ Cron Job Status</h2>

                <h3 style="color: #46b450;">✓ Scheduled Cron Jobs (<?php echo count($existing_crons); ?>)</h3>
                <ul style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
                    <?php foreach ($existing_crons as $cron): ?>
                        <li style="color: #46b450;">✓ <?php echo esc_html($cron); ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!empty($missing_crons)): ?>
                    <h3 style="color: #dc3545;">✗ Missing Cron Jobs (<?php echo count($missing_crons); ?>)</h3>
                    <ul style="columns: 2; -webkit-columns: 2; -moz-columns: 2;">
                        <?php foreach ($missing_crons as $cron): ?>
                            <li style="color: #dc3545;">✗ <?php echo esc_html($cron); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="color: #46b450; font-weight: bold;">✓ All cron jobs scheduled!</p>
                <?php endif; ?>
            </div>

            <?php if (!empty($missing_tables) || !empty($missing_crons)): ?>
                <div class="card" style="margin-top: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <h2>🔨 Sync Required</h2>
                    <p>Your database is missing <?php echo count($missing_tables); ?> tables and <?php echo count($missing_crons); ?> cron jobs.</p>

                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('This will create missing database tables and schedule cron jobs. Continue?');">
                        <input type="hidden" name="action" value="fs_sync_database">
                        <?php wp_nonce_field('fs_sync_database', 'fs_sync_nonce'); ?>

                        <button type="submit" class="button button-primary button-hero" style="margin-top: 10px;">
                            🔄 Run Database Sync
                        </button>
                    </form>

                    <p style="margin-top: 15px;"><strong>What this does:</strong></p>
                    <ul>
                        <li>✓ Creates all missing database tables</li>
                        <li>✓ Schedules all missing cron jobs</li>
                        <li>✓ Does NOT delete or modify existing data</li>
                        <li>✓ Safe to run multiple times</li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="card" style="margin-top: 20px; background: #d4edda; border-left: 4px solid #28a745;">
                    <h2 style="color: #155724;">✓ All Systems Go!</h2>
                    <p>Your database is fully synchronized. All tables exist and all cron jobs are scheduled.</p>
                </div>
            <?php endif; ?>

            <div class="card" style="margin-top: 20px;">
                <h2>ℹ️ Total Expected vs Actual</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th>Expected</th>
                            <th>Actual</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Database Tables</strong></td>
                            <td><?php echo count($expected_tables); ?></td>
                            <td><?php echo count($existing_tables); ?></td>
                            <td>
                                <?php if (count($existing_tables) == count($expected_tables)): ?>
                                    <span style="color: #46b450; font-weight: bold;">✓ Complete</span>
                                <?php else: ?>
                                    <span style="color: #dc3545; font-weight: bold;">✗ Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Cron Jobs</strong></td>
                            <td><?php echo count($expected_crons); ?></td>
                            <td><?php echo count($existing_crons); ?></td>
                            <td>
                                <?php if (count($existing_crons) == count($expected_crons)): ?>
                                    <span style="color: #46b450; font-weight: bold;">✓ Complete</span>
                                <?php else: ?>
                                    <span style="color: #dc3545; font-weight: bold;">✗ Incomplete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>📝 Alternative: Deactivate/Reactivate Plugin</h2>
                <p>You can also sync the database by deactivating and reactivating the plugin:</p>
                <ol>
                    <li>Go to <strong>Plugins → Installed Plugins</strong></li>
                    <li>Deactivate FriendShyft</li>
                    <li>Reactivate FriendShyft</li>
                </ol>
                <p><strong>Note:</strong> This runs the full activation hook, which does the same thing as the sync button above.</p>
            </div>
        </div>

        <style>
            .card {
                background: white;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .card h2 {
                margin-top: 0;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
            .card h3 {
                margin-top: 20px;
            }
            .card ul {
                list-style: none;
                padding-left: 0;
            }
            .card ul li {
                padding: 3px 0;
            }
        </style>
        <?php
    }

    public static function handle_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('fs_sync_database', 'fs_sync_nonce');

        // Run activation hook manually
        friendshyft_activate();

        // Redirect back with success message
        wp_redirect(add_query_arg(
            array(
                'page' => 'friendshyft-database-sync',
                'synced' => '1'
            ),
            admin_url('admin.php')
        ));
        exit;
    }
}
