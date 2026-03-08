<?php
if (!defined('ABSPATH')) exit;

/**
 * Manual Database Fix Tool
 * Fixes missing tables and column issues
 */
class FS_Admin_Manual_DB_Fix {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 101);
        add_action('admin_post_fs_run_manual_fix', array(__CLASS__, 'run_fix'));
    }

    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Manual Database Fix',
            '🔧 Manual Fix',
            'manage_options',
            'friendshyft-manual-fix',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        ?>
        <div class="wrap">
            <h1>🔧 Manual Database Fix</h1>

            <div class="notice notice-warning">
                <p><strong>This tool will:</strong></p>
                <ul>
                    <li>Create 6 missing tables (surveys, suggestions, suggestion_responses, substitute_requests, availability, blackout_dates)</li>
                    <li>Fix column names in wp_fs_suggestions (title→subject, description→suggestion, updated_at→reviewed_at)</li>
                    <li>Add missing <code>hours</code> column to wp_fs_time_records</li>
                    <li>Safe to run multiple times</li>
                </ul>
            </div>

            <?php if (isset($_GET['fixed'])): ?>
                <div class="notice notice-success">
                    <p><strong>✅ Database fixed successfully!</strong></p>
                    <p>Check the log below for details.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('Run manual database fix?');">
                <input type="hidden" name="action" value="fs_run_manual_fix">
                <?php wp_nonce_field('fs_manual_fix', 'fix_nonce'); ?>

                <p>
                    <button type="submit" class="button button-primary button-hero">
                        🔧 Run Manual Fix
                    </button>
                </p>
            </form>

            <div class="card" style="margin-top: 20px;">
                <h2>What This Fixes</h2>

                <h3>Missing Tables (6):</h3>
                <ul>
                    <li><code>wp_fs_surveys</code> - Post-event surveys</li>
                    <li><code>wp_fs_suggestions</code> - Volunteer suggestions</li>
                    <li><code>wp_fs_suggestion_responses</code> - Suggestion response history</li>
                    <li><code>wp_fs_substitute_requests</code> - Substitute coverage requests</li>
                    <li><code>wp_fs_availability</code> - Recurring weekly availability</li>
                    <li><code>wp_fs_blackout_dates</code> - Volunteer blackout dates</li>
                </ul>

                <h3>Missing/Incorrect Columns:</h3>
                <ul>
                    <li><code>wp_fs_time_records.hours</code> - Calculated hours for shifts</li>
                    <li><code>wp_fs_suggestions</code> - Renames columns to match code expectations (title→subject, description→suggestion)</li>
                </ul>

                <h3>What Will NOT Be Fixed (Requires Code Changes):</h3>
                <ul>
                    <li>Column name references (start_time vs event_time_start) - Will fix in code</li>
                    <li>Badge table naming (wp_fs_badges vs wp_fs_volunteer_badges) - Will fix in code</li>
                </ul>
            </div>
        </div>
        <?php
    }

    public static function run_fix() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('fs_manual_fix', 'fix_nonce');

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $log = array();

        // 1. Create wp_fs_surveys table
        $surveys_table = $wpdb->prefix . 'fs_surveys';
        if ($wpdb->get_var("SHOW TABLES LIKE '$surveys_table'") != $surveys_table) {
            $sql = "CREATE TABLE $surveys_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                volunteer_id bigint(20) unsigned NOT NULL,
                opportunity_id bigint(20) unsigned NOT NULL,
                rating int NOT NULL,
                comments text NULL,
                submitted_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY volunteer_id (volunteer_id),
                KEY opportunity_id (opportunity_id),
                KEY submitted_at (submitted_at)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            $log[] = "✅ Created wp_fs_surveys table";
        } else {
            $log[] = "ℹ️ wp_fs_surveys already exists";
        }

        // 2. Create wp_fs_suggestions table
        $suggestions_table = $wpdb->prefix . 'fs_suggestions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$suggestions_table'") != $suggestions_table) {
            $sql = "CREATE TABLE $suggestions_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                volunteer_id bigint(20) unsigned NOT NULL,
                category varchar(50) NOT NULL,
                subject varchar(255) NOT NULL,
                suggestion text NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                admin_response text NULL,
                submitted_at datetime NOT NULL,
                reviewed_at datetime NULL,
                PRIMARY KEY (id),
                KEY volunteer_id (volunteer_id),
                KEY category (category),
                KEY status (status),
                KEY submitted_at (submitted_at)
            ) $charset_collate;";

            dbDelta($sql);
            $log[] = "✅ Created wp_fs_suggestions table";
        } else {
            // Table exists - check if we need to fix column names
            $columns = $wpdb->get_col("DESCRIBE $suggestions_table", 0);

            // Rename 'title' to 'subject' if it exists
            if (in_array('title', $columns) && !in_array('subject', $columns)) {
                $wpdb->query("ALTER TABLE $suggestions_table CHANGE COLUMN title subject varchar(255) NOT NULL");
                $log[] = "✅ Renamed 'title' column to 'subject' in wp_fs_suggestions";
            }

            // Rename 'description' to 'suggestion' if it exists
            if (in_array('description', $columns) && !in_array('suggestion', $columns)) {
                $wpdb->query("ALTER TABLE $suggestions_table CHANGE COLUMN description suggestion text NOT NULL");
                $log[] = "✅ Renamed 'description' column to 'suggestion' in wp_fs_suggestions";
            }

            // Rename 'updated_at' to 'reviewed_at' if it exists
            if (in_array('updated_at', $columns) && !in_array('reviewed_at', $columns)) {
                $wpdb->query("ALTER TABLE $suggestions_table CHANGE COLUMN updated_at reviewed_at datetime NULL");
                $log[] = "✅ Renamed 'updated_at' column to 'reviewed_at' in wp_fs_suggestions";
            }

            if (!in_array('title', $columns) && in_array('subject', $columns)) {
                $log[] = "ℹ️ wp_fs_suggestions already has correct schema";
            }
        }

        // 2b. Create wp_fs_suggestion_responses table
        $suggestion_responses_table = $wpdb->prefix . 'fs_suggestion_responses';
        if ($wpdb->get_var("SHOW TABLES LIKE '$suggestion_responses_table'") != $suggestion_responses_table) {
            $sql = "CREATE TABLE $suggestion_responses_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                suggestion_id bigint(20) unsigned NOT NULL,
                admin_user_id bigint(20) unsigned NOT NULL,
                response text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY suggestion_id (suggestion_id),
                KEY admin_user_id (admin_user_id),
                KEY created_at (created_at)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            $log[] = "✅ Created wp_fs_suggestion_responses table";
        } else {
            $log[] = "ℹ️ wp_fs_suggestion_responses already exists";
        }

        // 3. Create wp_fs_substitute_requests table
        $substitute_table = $wpdb->prefix . 'fs_substitute_requests';
        if ($wpdb->get_var("SHOW TABLES LIKE '$substitute_table'") != $substitute_table) {
            $sql = "CREATE TABLE $substitute_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                signup_id bigint(20) unsigned NOT NULL,
                original_volunteer_id bigint(20) unsigned NOT NULL,
                substitute_volunteer_id bigint(20) unsigned NULL,
                opportunity_id bigint(20) unsigned NOT NULL,
                reason text NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                requested_at datetime NOT NULL,
                fulfilled_at datetime NULL,
                PRIMARY KEY (id),
                KEY signup_id (signup_id),
                KEY original_volunteer_id (original_volunteer_id),
                KEY substitute_volunteer_id (substitute_volunteer_id),
                KEY opportunity_id (opportunity_id),
                KEY status (status)
            ) $charset_collate;";

            dbDelta($sql);
            $log[] = "✅ Created wp_fs_substitute_requests table";
        } else {
            $log[] = "ℹ️ wp_fs_substitute_requests already exists";
        }

        // 4. Create wp_fs_availability table
        $availability_table = $wpdb->prefix . 'fs_availability';
        if ($wpdb->get_var("SHOW TABLES LIKE '$availability_table'") != $availability_table) {
            $sql = "CREATE TABLE $availability_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                volunteer_id bigint(20) unsigned NOT NULL,
                day_of_week int NOT NULL,
                time_slot varchar(20) NOT NULL,
                auto_signup tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NULL,
                PRIMARY KEY (id),
                KEY volunteer_id (volunteer_id),
                KEY day_of_week (day_of_week),
                UNIQUE KEY unique_volunteer_day_time (volunteer_id, day_of_week, time_slot)
            ) $charset_collate;";

            dbDelta($sql);
            $log[] = "✅ Created wp_fs_availability table";
        } else {
            $log[] = "ℹ️ wp_fs_availability already exists";
        }

        // 5. Create wp_fs_blackout_dates table
        $blackout_table = $wpdb->prefix . 'fs_blackout_dates';
        if ($wpdb->get_var("SHOW TABLES LIKE '$blackout_table'") != $blackout_table) {
            $sql = "CREATE TABLE $blackout_table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                volunteer_id bigint(20) unsigned NOT NULL,
                start_date date NOT NULL,
                end_date date NOT NULL,
                reason varchar(255) NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY volunteer_id (volunteer_id),
                KEY start_date (start_date),
                KEY end_date (end_date)
            ) $charset_collate;";

            dbDelta($sql);
            $log[] = "✅ Created wp_fs_blackout_dates table";
        } else {
            $log[] = "ℹ️ wp_fs_blackout_dates already exists";
        }

        // 6. Add hours column to wp_fs_time_records if missing
        $time_records_table = $wpdb->prefix . 'fs_time_records';
        $columns = $wpdb->get_col("DESCRIBE $time_records_table");

        if (!in_array('hours', $columns)) {
            $wpdb->query("ALTER TABLE $time_records_table ADD COLUMN hours decimal(10,2) NULL AFTER check_out");
            $log[] = "✅ Added 'hours' column to wp_fs_time_records";
        } else {
            $log[] = "ℹ️ 'hours' column already exists in wp_fs_time_records";
        }

        // Store log in transient
        set_transient('fs_manual_fix_log', $log, 300);

        wp_redirect(add_query_arg(
            array(
                'page' => 'friendshyft-manual-fix',
                'fixed' => '1'
            ),
            admin_url('admin.php')
        ));
        exit;
    }
}
