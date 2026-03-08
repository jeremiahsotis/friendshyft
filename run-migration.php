<?php
/**
 * Run database migrations manually
 * Access via browser: http://friendshyft.local/wp-content/plugins/friendshyft/run-migration.php
 *
 * This file can be safely deleted after migration is confirmed complete.
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../../wp-load.php';
}

// Only allow admins to run this
if (!current_user_can('manage_options')) {
    die('Access denied - please log in as admin first');
}

echo "<pre>";
echo "=== FriendShyft Database Migration Tool ===\n\n";

// Show current version
echo "Current DB version: " . get_option('friendshyft_db_version', '0') . "\n";

// Force reset to run all migrations
echo "Resetting DB version to force re-run...\n";
update_option('friendshyft_db_version', '1.8');

// Run the migration
if (class_exists('FS_Database_Migrations')) {
    echo "Running migrations...\n\n";
    FS_Database_Migrations::run_migrations();
    echo "\nNew DB version: " . FS_Database_Migrations::get_current_version() . "\n";
} else {
    echo "ERROR: FS_Database_Migrations class not found. Make sure the plugin is active.\n";
}

// Verify tables
global $wpdb;

echo "\n=== Verifying Tables ===\n\n";

// Check fs_availability columns
$availability_columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}fs_availability", 0);
echo "fs_availability columns: " . implode(', ', $availability_columns) . "\n";

// Check fs_signups columns
$signups_columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}fs_signups", 0);
echo "fs_signups columns: " . implode(', ', $signups_columns) . "\n";

// Check if fs_badges exists
$badges_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fs_badges'");
echo "fs_badges table exists: " . ($badges_exists ? 'YES' : 'NO') . "\n";

echo "\n=== Migration Complete ===\n";
echo "</pre>";
