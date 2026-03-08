<?php
if (!defined('ABSPATH')) exit;

/**
 * Team Management Migration Runner
 * 
 * Admin page to run team management database migration
 */

class FS_Team_Migration_Runner {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'));
        add_action('admin_post_run_team_migration', array(__CLASS__, 'run_migration'));
        add_action('admin_post_rollback_team_migration', array(__CLASS__, 'rollback_migration'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Team Migration',
            'Team Migration',
            'manage_options',
            'fs-team-migration',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function render_page() {
        require_once plugin_dir_path(__FILE__) . '../fs-team-management-migration.php';
        
        $status = FS_Team_Management_Migration::check_status();
        $migration_complete = !in_array(false, $status, true);
        
        ?>
        <div class="wrap">
            <h1>Team Management Migration</h1>
            
            <?php if (isset($_GET['migrated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Migration completed successfully!</strong></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['rolledback'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Migration rolled back successfully!</strong></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Error:</strong> <?php echo esc_html($_GET['error']); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 800px;">
                <h2>Migration Status</h2>
                
                <table class="widefat">
                    <tr>
                        <th>fs_teams table:</th>
                        <td>
                            <?php if ($status['teams_table']): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>fs_team_members table:</th>
                        <td>
                            <?php if ($status['members_table']): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>fs_team_signups table:</th>
                        <td>
                            <?php if ($status['signups_table']): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>fs_team_attendance table:</th>
                        <td>
                            <?php if ($status['attendance_table']): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>fs_opportunities.allow_team_signups:</th>
                        <td>
                            <?php if ($status['opportunities_column']): ?>
                                <span style="color: green;">✓ Added</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not added</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <h3>What This Migration Does</h3>
                <ul>
                    <li>Creates <code>fs_teams</code> table for team identities</li>
                    <li>Creates <code>fs_team_members</code> table for optional individual tracking</li>
                    <li>Creates <code>fs_team_signups</code> table for team shift claims</li>
                    <li>Creates <code>fs_team_attendance</code> table for time tracking</li>
                    <li>Adds <code>allow_team_signups</code> column to <code>fs_opportunities</code></li>
                </ul>
                
                <?php if (!$migration_complete): ?>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="run_team_migration">
                        <?php wp_nonce_field('run_team_migration'); ?>
                        <p>
                            <button type="submit" class="button button-primary button-large">
                                Run Migration
                            </button>
                        </p>
                    </form>
                <?php else: ?>
                    <div class="notice notice-success inline">
                        <p><strong>Migration completed!</strong></p>
                        <p>You can now use team management features:</p>
                        <ul>
                            <li><a href="<?php echo admin_url('admin.php?page=fs-teams'); ?>">Manage Teams</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=fs-opportunities'); ?>">Enable Team Signups on Opportunities</a></li>
                        </ul>
                    </div>
                    
                    <h3>Rollback</h3>
                    <p>If you need to remove all team management tables:</p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" 
                          onsubmit="return confirm('This will delete ALL team data including signups and attendance. Continue?');">
                        <input type="hidden" name="action" value="rollback_team_migration">
                        <?php wp_nonce_field('rollback_team_migration'); ?>
                        <p>
                            <button type="submit" class="button">
                                Rollback Migration
                            </button>
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    public static function run_migration() {
        check_admin_referer('run_team_migration');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        require_once plugin_dir_path(__FILE__) . '../fs-team-management-migration.php';
        
        try {
            FS_Team_Management_Migration::run();
            wp_redirect(admin_url('admin.php?page=fs-team-migration&migrated=1'));
        } catch (Exception $e) {
            wp_redirect(admin_url('admin.php?page=fs-team-migration&error=' . urlencode($e->getMessage())));
        }
        exit;
    }
    
    public static function rollback_migration() {
        check_admin_referer('rollback_team_migration');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        require_once plugin_dir_path(__FILE__) . '../fs-team-management-migration.php';
        
        try {
            FS_Team_Management_Migration::rollback();
            wp_redirect(admin_url('admin.php?page=fs-team-migration&rolledback=1'));
        } catch (Exception $e) {
            wp_redirect(admin_url('admin.php?page=fs-team-migration&error=' . urlencode($e->getMessage())));
        }
        exit;
    }
}
