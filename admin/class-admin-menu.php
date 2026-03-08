<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Menu {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'));
    }
    
    public static function add_menu_pages() {
        // Main menu page - defaults to My Opportunities
        add_menu_page(
            'FriendShyft',
            'FriendShyft',
            'read', // Allow all logged-in users
            'friendshyft',
            array('FS_Admin_POC_Dashboard', 'render_dashboard'), // Point to POC Dashboard
            'dashicons-groups',
            30
        );

        // My Opportunities submenu (will replace the duplicate "FriendShyft" item)
        add_submenu_page(
            'friendshyft',
            'My Opportunities',
            'My Opportunities',
            'read',
            'friendshyft', // Same slug as parent to replace duplicate
            array('FS_Admin_POC_Dashboard', 'render_dashboard')
        );

        // Admin Dashboard
        add_submenu_page(
            'friendshyft',
            'Admin Dashboard',
            'Admin Dashboard',
            'manage_options',
            'fs-admin-dashboard',
            array(__CLASS__, 'dashboard_page')
        );

        // POC Calendar
        add_submenu_page(
            'friendshyft',
            'My Calendar',
            'My Calendar',
            'read', // Allow all logged-in users (POCs and admins)
            'fs-poc-calendar',
            array('FS_Admin_POC_Calendar', 'render_page')
        );

        // POC Reports
        add_submenu_page(
            'friendshyft',
            'Reports & Analytics',
            'Reports & Analytics',
            'read', // Allow all logged-in users (POCs and admins)
            'fs-poc-reports',
            array('FS_Admin_POC_Reports', 'render_page')
        );

        // Activity Reports (Admin only)
        add_submenu_page(
            'friendshyft',
            'Activity Reports',
            'Activity Reports',
            'manage_options',
            'fs-activity-reports',
            array('FS_Admin_Activity_Reports', 'render_page')
        );

        // Bulk Operations (Admin only)
        add_submenu_page(
            'friendshyft',
            'Bulk Operations',
            'Bulk Operations',
            'manage_options',
            'fs-bulk-operations',
            array('FS_Admin_Bulk_Operations', 'render_page')
        );

        // Audit Log (Admin only)
        add_submenu_page(
            'friendshyft',
            'Audit Log',
            'Audit Log',
            'manage_options',
            'fs-audit-log',
            array('FS_Admin_Audit_Log', 'render_page')
        );

        // Feedback Management (Admin only)
        add_submenu_page(
            'friendshyft',
            'Volunteer Feedback',
            'Volunteer Feedback',
            'manage_options',
            'fs-feedback',
            array('FS_Admin_Feedback', 'render_page')
        );

        // Google Calendar Settings (Admin only)
        add_submenu_page(
            'friendshyft',
            'Google Calendar',
            'Google Calendar',
            'manage_options',
            'fs-google-settings',
            array('FS_Admin_Google_Settings', 'render_page')
        );

        // Advanced Scheduling (Admin only)
        add_submenu_page(
            'friendshyft',
            'Advanced Scheduling',
            'Advanced Scheduling',
            'manage_options',
            'fs-advanced-scheduling',
            array('FS_Admin_Advanced_Scheduling', 'render_page')
        );

        // Settings submenu
        add_submenu_page(
            'friendshyft',
            'Settings',
            'Settings',
            'manage_options',
            'fs-settings',
            array(__CLASS__, 'settings_page')
        );
    }

    public static function dashboard_page() {
        FS_Admin_Dashboard::render();
    }
    
    /*public static function volunteers_page() {
        echo '<div class="wrap">';
        echo '<h1>Volunteers</h1>';
        echo '<p>Volunteer management will be handled by the FS_Admin_Volunteers class.</p>';
        echo '<p><a href="' . admin_url('admin.php?page=fs-manage-volunteers') . '" class="button button-primary">Manage Volunteers</a></p>';
        echo '</div>';
    }
    
    public static function opportunities_page() {
        global $wpdb;
        
        $opportunities = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities 
            WHERE datetime_start >= NOW() 
            ORDER BY datetime_start ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>Opportunities</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Location</th>
                        <th>Spots</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($opportunities)): ?>
                        <tr>
                            <td colspan="5">No upcoming opportunities</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($opportunities as $opp): ?>
                            <tr>
                                <td><strong><?php echo esc_html($opp->title); ?></strong></td>
                                <td><?php echo date('M j, Y @ g:i A', strtotime($opp->datetime_start)); ?></td>
                                <td><?php echo esc_html($opp->location ?: '—'); ?></td>
                                <td><?php echo $opp->spots_filled; ?> / <?php echo $opp->spots_available; ?></td>
                                <td><?php echo esc_html($opp->status); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public static function roles_page() {
        global $wpdb;
        
        $roles = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_roles ORDER BY name ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>Roles</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Role Name</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Volunteers</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($roles)): ?>
                        <tr>
                            <td colspan="4">No roles defined</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($roles as $role): ?>
                            <?php
                            $volunteer_count = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_roles WHERE role_id = %d",
                                $role->id
                            ));
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($role->name); ?></strong></td>
                                <td><?php echo esc_html($role->description ?: '—'); ?></td>
                                <td><?php echo esc_html($role->status); ?></td>
                                <td><?php echo $volunteer_count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public static function workflows_page() {
        global $wpdb;
        
        $workflows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_workflows ORDER BY name ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>Workflows</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Workflow Name</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>In Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($workflows)): ?>
                        <tr>
                            <td colspan="4">No workflows defined</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($workflows as $workflow): ?>
                            <?php
                            $in_progress = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_progress 
                                WHERE workflow_id = %d AND overall_status != 'Complete'",
                                $workflow->id
                            ));
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($workflow->name); ?></strong></td>
                                <td><?php echo esc_html($workflow->description ?: '—'); ?></td>
                                <td><?php echo esc_html($workflow->status); ?></td>
                                <td><?php echo $in_progress; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public static function programs_page() {
        global $wpdb;
        
        $programs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_programs ORDER BY display_order ASC, name ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>Programs</h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Program Name</th>
                        <th>Short Description</th>
                        <th>Status</th>
                        <th>Display Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($programs)): ?>
                        <tr>
                            <td colspan="4">No programs defined</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($programs as $program): ?>
                            <tr>
                                <td><strong><?php echo esc_html($program->name); ?></strong></td>
                                <td><?php echo esc_html($program->short_description ?: '—'); ?></td>
                                <td><?php echo esc_html($program->active_status); ?></td>
                                <td><?php echo $program->display_order; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }*/
    
    public static function settings_page() {
        // Handle connection test
        if (isset($_POST['fs_test_connection']) && check_admin_referer('fs_settings')) {
            $api = new FS_Monday_API();
            $test_result = $api->test_connection();
            
            if ($test_result['success']) {
                echo '<div class="notice notice-success"><p>✓ ' . esc_html($test_result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ ' . esc_html($test_result['message']) . '</p></div>';
            }
        }
        
        // Handle manual sync trigger
        if (isset($_POST['fs_trigger_sync']) && check_admin_referer('fs_settings')) {
            // Trigger immediate sync
            do_action('fs_sync_cron');
            echo '<div class="notice notice-success"><p>✓ Sync triggered! Check the dashboard for updated stats.</p></div>';
        }
        
        // Handle form submission
        if (isset($_POST['fs_save_settings']) && check_admin_referer('fs_settings')) {
            update_option('fs_monday_token', sanitize_text_field($_POST['monday_token'] ?? ''));
            update_option('fs_monday_api_version', sanitize_text_field($_POST['monday_api_version'] ?? '2023-10'));

            $board_ids = array(
                'people' => intval($_POST['board_people'] ?? 0),
                'roles' => intval($_POST['board_roles'] ?? 0),
                'workflows' => intval($_POST['board_workflows'] ?? 0),
                'progress' => intval($_POST['board_progress'] ?? 0),
                'opportunities' => intval($_POST['board_opportunities'] ?? 0),
                'signups' => intval($_POST['board_signups'] ?? 0),
                'programs' => intval($_POST['board_programs'] ?? 0)
            );
            
            update_option('fs_board_ids', $board_ids);
            
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $monday_token = get_option('fs_monday_token', '');
        $monday_api_version = get_option('fs_monday_api_version', '2024-10');
        $board_ids = get_option('fs_board_ids', array());
        $status = FS_Monday_API::get_status();
        $monday_configured = $status['configured'];
        
        ?>
        <div class="wrap">
            <h1>FriendShyft Settings</h1>
            
            <!-- Monday.com Status Banner -->
            <div class="fs-status-banner <?php echo $monday_configured ? 'status-active' : 'status-inactive'; ?>">
                <div class="status-icon">
                    <?php echo $monday_configured ? '✓' : '⚠'; ?>
                </div>
                <div class="status-content">
                    <h3><?php echo esc_html($status['message']); ?></h3>
                    <p><?php echo esc_html($status['details']); ?></p>
                </div>
            </div>
            
            <form method="post">
                <?php wp_nonce_field('fs_settings'); ?>
                
                <h2>Monday.com Integration (Optional)</h2>
                <p class="description">FriendShyft works perfectly standalone. Configure Monday.com only if you want external sync capabilities.</p>
                
                <table class="form-table">
                    <tr>
                        <th><label for="monday_token">Monday.com API Token</label></th>
                        <td>
                            <input type="text" id="monday_token" name="monday_token" value="<?php echo esc_attr($monday_token); ?>" class="regular-text">
                            <p class="description">Optional: <a href="https://support.monday.com/hc/en-us/articles/360005144659-Does-monday-com-have-an-API-" target="_blank">Get your API token from Monday.com</a></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="monday_api_version">API Version</label></th>
                        <td>
                            <input type="text" id="monday_api_version" name="monday_api_version" value="<?php echo esc_attr($monday_api_version); ?>" class="regular-text">
                            <p class="description">Default: 2024-10</p>
                        </td>
                    </tr>
                </table>
                
                <?php if (!empty($monday_token)): ?>
                <div class="fs-sync-controls">
                    <h3>Connection & Sync</h3>
                    
                    <p>
                        <button type="submit" name="fs_test_connection" class="button">Test Connection</button>
                        <?php if ($monday_configured): ?>
                            <button type="submit" name="fs_trigger_sync" class="button button-secondary">Trigger Sync Now</button>
                        <?php endif; ?>
                    </p>
                    
                    <?php
                    if ($monday_configured):
                        // Show last sync time
                        global $wpdb;
                        $last_sync = $wpdb->get_var("SELECT MAX(last_sync) FROM {$wpdb->prefix}fs_volunteers");
                        if ($last_sync):
                    ?>
                    <p class="description">
                        <strong>Last sync:</strong> <?php echo human_time_diff(strtotime($last_sync), current_time('timestamp')); ?> ago 
                        (<?php echo date('M j, Y @ g:i A', strtotime($last_sync)); ?>)
                    </p>
                    <p class="description">
                        Automatic sync runs every 15 minutes when Monday.com is configured.
                    </p>
                    <?php 
                        endif;
                    endif; 
                    ?>
                </div>
                <?php endif; ?>
                
                <h2>Monday.com Board IDs</h2>
                <p class="description">Only needed if using Monday.com sync. Leave blank to use FriendShyft standalone.</p>
                
                <table class="form-table">
                    <?php
                    $board_types = FS_Monday_API::get_board_types();
                    foreach ($board_types as $key => $info):
                    ?>
                    <tr>
                        <th>
                            <label for="board_<?php echo $key; ?>">
                                <?php echo esc_html($info['label']); ?>
                                <?php if ($info['required']): ?>
                                    <span class="required-indicator" title="Required for Monday.com sync">*</span>
                                <?php endif; ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="board_<?php echo $key; ?>" 
                                   name="board_<?php echo $key; ?>" 
                                   value="<?php echo esc_attr($board_ids[$key] ?? ''); ?>" 
                                   class="regular-text"
                                   placeholder="Optional">
                            <p class="description"><?php echo esc_html($info['description']); ?></p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                
                <p class="submit">
                    <input type="submit" name="fs_save_settings" class="button button-primary" value="Save Settings">
                </p>
            </form>
            
            <div class="fs-settings-info">
                <h3>About Monday.com Integration</h3>
                <p>FriendShyft is a complete volunteer management system that works independently. Monday.com integration is entirely optional and provides:</p>
                <ul>
                    <li><strong>External data sync</strong> - Keep volunteer data synchronized with Monday.com boards</li>
                    <li><strong>Team collaboration</strong> - Share volunteer information with staff who use Monday.com</li>
                    <li><strong>Workflow automation</strong> - Trigger Monday.com automations based on volunteer actions</li>
                    <li><strong>Reporting</strong> - Use Monday.com's reporting tools alongside FriendShyft</li>
                </ul>
                <p><strong>Without Monday.com:</strong> All core features work perfectly - volunteer registration, opportunity signups, onboarding workflows, and admin management are fully functional.</p>
            </div>
        </div>
        
        <style>
            .fs-status-banner {
                display: flex;
                align-items: center;
                padding: 20px;
                margin: 20px 0;
                border-radius: 8px;
                border: 2px solid;
            }
            .status-active {
                background: #d4edda;
                border-color: #28a745;
                color: #155724;
            }
            .status-inactive {
                background: #fff3cd;
                border-color: #ffc107;
                color: #856404;
            }
            .status-icon {
                font-size: 48px;
                margin-right: 20px;
                line-height: 1;
                font-weight: bold;
            }
            .status-content h3 {
                margin: 0 0 5px 0;
                font-size: 18px;
            }
            .status-content p {
                margin: 0;
                opacity: 0.9;
            }
            .required-indicator {
                color: #dc3545;
                font-weight: bold;
                font-size: 16px;
            }
            .fs-sync-controls {
                background: #f0f0f1;
                padding: 20px;
                border-radius: 4px;
                margin: 20px 0;
            }
            .fs-sync-controls h3 {
                margin-top: 0;
            }
            .fs-settings-info {
                background: #f0f6fc;
                border: 1px solid #0073aa;
                border-radius: 4px;
                padding: 20px;
                margin-top: 30px;
            }
            .fs-settings-info h3 {
                margin-top: 0;
                color: #0073aa;
            }
            .fs-settings-info ul {
                margin: 15px 0;
                padding-left: 20px;
            }
            .fs-settings-info li {
                margin-bottom: 8px;
            }
        </style>
        <?php
    }
}

FS_Admin_Menu::init();