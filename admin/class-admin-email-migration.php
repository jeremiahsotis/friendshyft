<?php
/**
 * Email Ingestion Migration Runner
 * 
 * Simple admin page to run the migration once
 * Add this temporarily to run migration, then remove
 */

class FS_Email_Migration_Runner {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'));
        add_action('admin_post_run_email_migration', array(__CLASS__, 'run_migration'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Email Migration',
            'Email Migration',
            'manage_options',
            'fs-email-migration',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function render_page() {
        global $wpdb;
        
        // Check if tables exist
        $email_log_exists = $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}fs_email_log'"
        );
        $interests_exists = $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}fs_volunteer_interests'"
        );
        
        // Check if volunteer fields exist
        $phone_exists = $wpdb->get_var(
            "SHOW COLUMNS FROM {$wpdb->prefix}fs_volunteers LIKE 'phone'"
        );
        
        $migration_complete = $email_log_exists && $interests_exists && $phone_exists;
        
        ?>
        <div class="wrap">
            <h1>Email Ingestion Migration</h1>
            
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
                        <th>fs_email_log table:</th>
                        <td>
                            <?php if ($email_log_exists): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>fs_volunteer_interests table:</th>
                        <td>
                            <?php if ($interests_exists): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not created</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>fs_volunteers.phone field:</th>
                        <td>
                            <?php if ($phone_exists): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not added</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <h3>What This Migration Does</h3>
                <ul>
                    <li>Creates <code>fs_email_log</code> table for tracking processed emails</li>
                    <li>Creates <code>fs_volunteer_interests</code> table for tracking volunteer interests</li>
                    <li>Adds <code>phone</code>, <code>phone_cell</code>, and <code>source</code> fields to <code>fs_volunteers</code> table</li>
                </ul>
                
                <?php if (!$migration_complete): ?>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="run_email_migration">
                        <?php wp_nonce_field('run_email_migration'); ?>
                        <p>
                            <button type="submit" class="button button-primary button-large">
                                Run Migration
                            </button>
                        </p>
                    </form>
                <?php else: ?>
                    <div class="notice notice-success inline">
                        <p><strong>Migration already completed!</strong></p>
                        <p>You can now use the email ingestion features:</p>
                        <ul>
                            <li><a href="<?php echo admin_url('admin.php?page=fs-process-email'); ?>">Process Email</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=fs-email-settings'); ?>">Email Settings</a></li>
                            <li><a href="<?php echo admin_url('admin.php?page=fs-email-log'); ?>">Email Log</a></li>
                        </ul>
                    </div>
                    
                    <h3>Rollback</h3>
                    <p>If you need to remove the email ingestion tables and fields:</p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" 
                          onsubmit="return confirm('This will delete all email log data and remove phone fields from volunteers. Continue?');">
                        <input type="hidden" name="action" value="rollback_email_migration">
                        <?php wp_nonce_field('rollback_email_migration'); ?>
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
        check_admin_referer('run_email_migration');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        require_once plugin_dir_path(__FILE__) . '../fs-email-ingestion-migration.php';
        
        try {
            FS_Email_Ingestion_Migration::run();
            wp_redirect(admin_url('admin.php?page=fs-email-migration&migrated=1'));
        } catch (Exception $e) {
            wp_redirect(admin_url('admin.php?page=fs-email-migration&error=' . urlencode($e->getMessage())));
        }
        exit;
    }
    
    public static function rollback_migration() {
        check_admin_referer('rollback_email_migration');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        require_once plugin_dir_path(__FILE__) . '../fs-email-ingestion-migration.php';
        
        try {
            FS_Email_Ingestion_Migration::rollback();
            wp_redirect(admin_url('admin.php?page=fs-email-migration&rolledback=1'));
        } catch (Exception $e) {
            wp_redirect(admin_url('admin.php?page=fs-email-migration&error=' . urlencode($e->getMessage())));
        }
        exit;
    }
}

// Initialize (add this to your main plugin file temporarily)
// FS_Email_Migration_Runner::init();
// add_action('admin_post_rollback_email_migration', array('FS_Email_Migration_Runner', 'rollback_migration'));
