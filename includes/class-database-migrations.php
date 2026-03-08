<?php
if (!defined('ABSPATH')) exit;

class FS_Database_Migrations {
    
    public static function run_migrations() {
        global $wpdb;
        
        $current_version = get_option('friendshyft_db_version', '0');
        
        // Version 1.0 migrations
        if (version_compare($current_version, '1.0', '<')) {
            self::create_badges_table();
            update_option('friendshyft_db_version', '1.0');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.0');
            }
        }
        
        // Version 1.1 migrations - Profile fields
        if (version_compare($current_version, '1.1', '<')) {
            self::add_profile_fields();
            update_option('friendshyft_db_version', '1.1');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.1');
            }
        }

        // Version 1.2 migrations - Point of Contact
        if (version_compare($current_version, '1.2', '<')) {
            self::add_point_of_contact_fields();
            update_option('friendshyft_db_version', '1.2');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.2');
            }
        }

        // Version 1.3 migrations - Role requirements
        if (version_compare($current_version, '1.3', '<')) {
            self::add_role_requirement_fields();
            update_option('friendshyft_db_version', '1.3');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.3');
            }
        }

        // Version 1.4 migrations - Waitlist shift support
        if (version_compare($current_version, '1.4', '<')) {
            self::add_waitlist_shift_support();
            update_option('friendshyft_db_version', '1.4');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.4');
            }
        }

        // Version 1.5 migrations - Team PIN and QR code
        if (version_compare($current_version, '1.5', '<')) {
            self::add_team_pin_qr_fields();
            update_option('friendshyft_db_version', '1.5');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.5');
            }
        }

        // Version 1.6 migrations - Workflow-to-Role linking
        if (version_compare($current_version, '1.6', '<')) {
            self::add_workflow_to_role_field();
            update_option('friendshyft_db_version', '1.6');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.6');
            }
        }

        // Version 1.7 migrations - Program email fields
        if (version_compare($current_version, '1.7', '<')) {
            self::add_program_email_fields();
            update_option('friendshyft_db_version', '1.7');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.7');
            }
        }

        // Version 1.8 migrations - Volunteer preferred contact
        if (version_compare($current_version, '1.8', '<')) {
            self::add_volunteer_preferred_contact();
            update_option('friendshyft_db_version', '1.8');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.8');
            }
        }

        // Version 1.9 migrations - Availability table fixes, signups created_at, badges table
        if (version_compare($current_version, '1.9', '<')) {
            self::add_availability_program_id();
            self::fix_signups_created_at();
            self::create_badges_table_if_missing();
            update_option('friendshyft_db_version', '1.9');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 1.9');
            }
        }

        // Version 2.0 migrations - Feedback system tables
        if (version_compare($current_version, '2.0', '<')) {
            self::create_feedback_tables();
            update_option('friendshyft_db_version', '2.0');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 2.0 - Feedback tables');
            }
        }

        // Version 2.1 migrations - Teen registration + SignShyft integration schema
        if (version_compare($current_version, '2.1', '<')) {
            self::add_teen_registration_schema();
            update_option('friendshyft_db_version', '2.1');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 2.1 - Teen registration schema');
            }
        }

        // Version 2.2 migrations - Manual guardian permission fallback fields
        if (version_compare($current_version, '2.2', '<')) {
            self::add_manual_permission_fallback_schema();
            update_option('friendshyft_db_version', '2.2');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Migrations completed for version 2.2 - Manual permission fallback schema');
            }
        }

        // Future migrations go here
    }
    
    /**
     * Create feedback system tables
     */
    private static function create_feedback_tables() {
        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-feedback-system.php';
        FS_Feedback_System::create_tables();
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Created feedback system tables');
        }
    }
    
    private static function create_badges_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fs_volunteer_badges';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) NOT NULL,
            badge_type varchar(50) NOT NULL,
            badge_level varchar(50) NOT NULL,
            earned_date datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY badge_type (badge_type),
            KEY earned_date (earned_date),
            UNIQUE KEY volunteer_badge (volunteer_id, badge_type, badge_level)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Created/verified fs_volunteer_badges table');
        }
    }

    /**
     * Add profile fields to volunteers table
     */
    private static function add_profile_fields() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_volunteers';
        
        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);
        
        // Emergency contact fields
        if (!in_array('emergency_contact_name', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN emergency_contact_name varchar(255) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added emergency_contact_name column');
            }
        }

        if (!in_array('emergency_contact_phone', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN emergency_contact_phone varchar(50) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added emergency_contact_phone column');
            }
        }

        if (!in_array('emergency_contact_relationship', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN emergency_contact_relationship varchar(100) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added emergency_contact_relationship column');
            }
        }
        
        // Address fields
        if (!in_array('address_line1', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN address_line1 varchar(255) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added address_line1 column');
            }
        }

        if (!in_array('address_line2', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN address_line2 varchar(255) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added address_line2 column');
            }
        }

        if (!in_array('city', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN city varchar(100) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added city column');
            }
        }

        if (!in_array('state', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN state varchar(2) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added state column');
            }
        }

        if (!in_array('zip_code', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN zip_code varchar(10) DEFAULT NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added zip_code column');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Profile fields migration completed');
        }
    }

    /**
     * Add point of contact field to opportunities table
     */
    private static function add_point_of_contact_fields() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_opportunities';

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add point_of_contact_id column
        if (!in_array('point_of_contact_id', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN point_of_contact_id bigint(20) DEFAULT NULL AFTER status");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added point_of_contact_id column to opportunities table');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Point of contact migration completed');
        }
    }

    /**
     * Add training_required and minimum_age fields to roles table
     */
    private static function add_role_requirement_fields() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_roles';

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add training_required column
        if (!in_array('training_required', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN training_required tinyint(1) DEFAULT 0 AFTER status");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added training_required column to roles table');
            }
        }

        // Add minimum_age column
        if (!in_array('minimum_age', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN minimum_age int DEFAULT NULL AFTER training_required");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added minimum_age column to roles table');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Role requirement fields migration completed');
        }
    }

    /**
     * Add shift support to waitlist table
     */
    private static function add_waitlist_shift_support() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_waitlist';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if (!$table_exists) {
            // Table doesn't exist, create it with the new schema
            FS_Waitlist_Manager::create_tables();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Created waitlist table with shift support');
            }
            return;
        }

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add shift_id column if it doesn't exist
        if (!in_array('shift_id', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN shift_id bigint(20) unsigned NULL AFTER opportunity_id");
            $wpdb->query("ALTER TABLE $table_name ADD KEY shift_id (shift_id)");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added shift_id column to waitlist table');
            }
        }

        // Update unique constraint to include shift_id
        $wpdb->query("ALTER TABLE $table_name DROP INDEX IF EXISTS unique_waitlist");
        $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY unique_waitlist (volunteer_id, opportunity_id, shift_id)");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Waitlist shift support migration completed');
        }
    }

    /**
     * Add PIN and QR code fields to teams table
     */
    private static function add_team_pin_qr_fields() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'fs_teams';

        // Check if columns already exist
        $pin_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM `{$table_name}` LIKE 'pin'"
        );

        $qr_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM `{$table_name}` LIKE 'qr_code'"
        );

        // Add PIN column if it doesn't exist
        if (empty($pin_exists)) {
            $wpdb->query(
                "ALTER TABLE `{$table_name}`
                 ADD COLUMN `pin` varchar(6) DEFAULT NULL,
                 ADD INDEX `pin` (`pin`)"
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added pin column to teams table');
            }
        }

        // Add QR code column if it doesn't exist
        if (empty($qr_exists)) {
            $wpdb->query(
                "ALTER TABLE `{$table_name}`
                 ADD COLUMN `qr_code` varchar(255) DEFAULT NULL,
                 ADD INDEX `qr_code` (`qr_code`)"
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added qr_code column to teams table');
            }
        }
    }

    /**
     * Add workflow_id field to roles table for workflow-to-role linking
     */
    private static function add_workflow_to_role_field() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_roles';

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add workflow_id column
        if (!in_array('workflow_id', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN workflow_id bigint(20) DEFAULT NULL AFTER minimum_age");
            $wpdb->query("ALTER TABLE $table_name ADD KEY workflow_id (workflow_id)");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added workflow_id column to roles table');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Workflow-to-role linking migration completed');
        }
    }

    /**
     * Add email fields to programs table for interest email customization
     */
    private static function add_program_email_fields() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_programs';

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add email_description column
        if (!in_array('email_description', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN email_description TEXT DEFAULT NULL AFTER long_description");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added email_description column to programs table');
            }
        }

        // Add schedule_days column
        if (!in_array('schedule_days', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN schedule_days VARCHAR(255) DEFAULT NULL AFTER email_description");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added schedule_days column to programs table');
            }
        }

        // Add schedule_times column
        if (!in_array('schedule_times', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN schedule_times VARCHAR(255) DEFAULT NULL AFTER schedule_days");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added schedule_times column to programs table');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Program email fields migration completed');
        }
    }

    /**
     * Add preferred_contact and availability fields to volunteers table
     */
    private static function add_volunteer_preferred_contact() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_volunteers';

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add availability column
        if (!in_array('availability', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN availability TEXT DEFAULT NULL AFTER notes");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added availability column to volunteers table');
            }
        }

        // Add preferred_contact column
        if (!in_array('preferred_contact', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN preferred_contact VARCHAR(20) DEFAULT 'email' AFTER availability");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added preferred_contact column to volunteers table');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Volunteer preferred contact migration completed');
        }
    }

    /**
     * Fix availability table - add all missing columns
     */
    private static function add_availability_program_id() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_availability';

        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if (!$table_exists) {
            // Table doesn't exist, create it via the recurring schedules class
            if (class_exists('FS_Recurring_Schedules')) {
                FS_Recurring_Schedules::create_tables();
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Created availability tables');
            }
            return;
        }

        // Get existing columns
        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        // Add program_id column if it doesn't exist
        if (!in_array('program_id', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN program_id bigint(20) unsigned NULL AFTER time_slot");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added program_id column to availability table');
            }
        }

        // Add auto_signup_enabled column if it doesn't exist
        if (!in_array('auto_signup_enabled', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN auto_signup_enabled tinyint(1) NOT NULL DEFAULT 0 AFTER program_id");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added auto_signup_enabled column to availability table');
            }
        }

        // Add created_at column if it doesn't exist
        if (!in_array('created_at', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added created_at column to availability table');
            }
        }

        // Add updated_at column if it doesn't exist
        if (!in_array('updated_at', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN updated_at datetime NULL");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added updated_at column to availability table');
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Availability table migration completed');
        }
    }

    /**
     * Fix signups table - add created_at column
     */
    private static function fix_signups_created_at() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_signups';

        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);

        if (!in_array('created_at', $columns)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added created_at column to signups table');
            }
        }
    }

    /**
     * Create badges table if missing
     */
    private static function create_badges_table_if_missing() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_badges';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                description text,
                icon varchar(50) DEFAULT '🏆',
                badge_type varchar(50) NOT NULL DEFAULT 'manual',
                criteria_type varchar(50) DEFAULT NULL,
                criteria_value int DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY badge_type (badge_type),
                KEY status (status)
            ) $charset_collate;");

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Created fs_badges table');
            }
        }
    }

    /**
     * Add teen registration authority schema and SignShyft webhook tracking.
     */
    private static function add_teen_registration_schema() {
        global $wpdb;

        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-database.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-waitlist-manager.php';

        // Ensure latest dbDelta definitions for new installs and upgrades.
        FS_Database::create_tables();
        FS_Waitlist_Manager::create_tables();

        self::add_column_if_missing(
            $wpdb->prefix . 'fs_opportunities',
            'event_group_id',
            "bigint(20) unsigned DEFAULT NULL"
        );
        self::add_index_if_missing(
            $wpdb->prefix . 'fs_opportunities',
            'event_group_id',
            "ADD KEY event_group_id (event_group_id)"
        );

        self::add_column_if_missing(
            $wpdb->prefix . 'fs_signups',
            'registration_id',
            "bigint(20) unsigned DEFAULT NULL"
        );
        self::add_index_if_missing(
            $wpdb->prefix . 'fs_signups',
            'registration_id',
            "ADD KEY registration_id (registration_id)"
        );

        self::add_column_if_missing(
            $wpdb->prefix . 'fs_waitlist',
            'registration_id',
            "bigint(20) unsigned DEFAULT NULL"
        );
        self::add_index_if_missing(
            $wpdb->prefix . 'fs_waitlist',
            'registration_id',
            "ADD KEY registration_id (registration_id)"
        );

        if (!get_option('fs_teen_permission_settings')) {
            add_option('fs_teen_permission_settings', array(
                'final_reminder_hours' => 2,
                'reminder_recipients' => 'guardian_only',
                'staff_notification_emails' => get_option('admin_email'),
                'help_contact_line' => '',
                'hold_window_hours' => 48,
                'reminder_24h_hours' => 24,
                'default_template_version_id' => '',
                'default_permission_scope' => 'single_event',
                'reuse_enabled' => 0,
                'reuse_validity_days' => 0,
            ));
        }
    }

    /**
     * Add manual permission fallback columns for existing sites.
     */
    private static function add_manual_permission_fallback_schema() {
        global $wpdb;

        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-database.php';
        FS_Database::create_tables();

        $table = $wpdb->prefix . 'fs_event_registrations';

        self::add_column_if_missing(
            $table,
            'permission_channel',
            "varchar(20) NOT NULL DEFAULT 'signshyft'"
        );
        self::add_index_if_missing(
            $table,
            'permission_channel',
            "ADD KEY permission_channel (permission_channel)"
        );

        self::add_column_if_missing(
            $table,
            'manual_signer_url',
            "text DEFAULT NULL"
        );
        self::add_column_if_missing(
            $table,
            'manual_request_sent_at',
            "datetime DEFAULT NULL"
        );
        self::add_column_if_missing(
            $table,
            'manual_signed_document_path',
            "text DEFAULT NULL"
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET permission_channel = %s
                 WHERE permission_channel IS NULL OR permission_channel = ''",
                'signshyft'
            )
        );
    }

    /**
     * Add a table column only when missing.
     */
    private static function add_column_if_missing($table_name, $column_name, $definition_sql) {
        global $wpdb;

        $columns = $wpdb->get_col("DESCRIBE $table_name", 0);
        if (in_array($column_name, $columns, true)) {
            return;
        }

        $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column_name $definition_sql");
    }

    /**
     * Add an index only when missing.
     */
    private static function add_index_if_missing($table_name, $index_name, $index_sql_fragment) {
        global $wpdb;

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name = '{$index_name}'");
        if (!empty($indexes)) {
            return;
        }

        $wpdb->query("ALTER TABLE $table_name $index_sql_fragment");
    }

    /**
     * Get current database version
     */
    public static function get_current_version() {
        return get_option('friendshyft_db_version', '0');
    }

    /**
     * Force run all migrations (use with caution)
     */
    public static function force_run_all() {
        delete_option('friendshyft_db_version');
        self::run_migrations();
    }
}
