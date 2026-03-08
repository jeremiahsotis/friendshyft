<?php
if (!defined('ABSPATH')) exit;

class FS_Database {
    
    public static function activate() {
        self::create_tables();
        self::set_default_settings();

        // Create time tracking tables
        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-time-tracking.php';
        FS_Time_Tracking::create_tables();

        // Create opportunity template tables
        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-opportunity-templates.php';
        FS_Opportunity_Templates::create_tables();

	// Create feedback system tables
	require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-feedback-system.php';
	FS_Feedback_System::create_tables();
    }
    
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Volunteers table
        $table_volunteers = $wpdb->prefix . 'fs_volunteers';
        $sql_volunteers = "CREATE TABLE IF NOT EXISTS $table_volunteers (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            monday_id bigint(20) DEFAULT NULL,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            access_token varchar(64) DEFAULT NULL,
            phone varchar(50),
            birthdate date,
            volunteer_status varchar(50),
            types text,
            notes text,
            background_check_status varchar(50),
            background_check_date date,
            background_check_org varchar(255),
            background_check_expiration date,
            created_date date,
            wp_user_id bigint(20),
            last_sync datetime,
            pin varchar(6) DEFAULT NULL,
            qr_code varchar(50) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY monday_id (monday_id),
            KEY email (email),
            KEY access_token (access_token),
            KEY wp_user_id (wp_user_id),
            KEY volunteer_status (volunteer_status),
            KEY pin (pin),
            KEY qr_code (qr_code),
            UNIQUE KEY access_token_unique (access_token)
        ) $charset_collate;";
        
        // Roles table
        $table_roles = $wpdb->prefix . 'fs_roles';
        $sql_roles = "CREATE TABLE IF NOT EXISTS $table_roles (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            monday_id bigint(20) DEFAULT NULL,
            name varchar(255) NOT NULL,
            description text,
            program_id bigint(20) DEFAULT NULL,
            status varchar(50) DEFAULT 'Active',
            last_sync datetime,
            PRIMARY KEY (id),
            KEY monday_id (monday_id),
            KEY program_id (program_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Volunteer Roles junction table
        $table_volunteer_roles = $wpdb->prefix . 'fs_volunteer_roles';
        $sql_volunteer_roles = "CREATE TABLE IF NOT EXISTS $table_volunteer_roles (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) NOT NULL,
            role_id bigint(20) NOT NULL,
            monday_connection_id bigint(20) DEFAULT NULL,
            assigned_date datetime,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY role_id (role_id),
            UNIQUE KEY unique_volunteer_role (volunteer_id, role_id)
        ) $charset_collate;";
        
        // Workflows table
        $table_workflows = $wpdb->prefix . 'fs_workflows';
        $sql_workflows = "CREATE TABLE IF NOT EXISTS $table_workflows (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            monday_id bigint(20) DEFAULT NULL,
            name varchar(255) NOT NULL,
            description text,
            steps text,
            status varchar(50) DEFAULT 'Active',
            last_sync datetime,
            PRIMARY KEY (id),
            KEY monday_id (monday_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Progress table
        $table_progress = $wpdb->prefix . 'fs_progress';
        $sql_progress = "CREATE TABLE IF NOT EXISTS $table_progress (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            monday_id bigint(20) DEFAULT NULL,
            volunteer_id bigint(20) NOT NULL,
            workflow_id bigint(20) NOT NULL,
            overall_status varchar(50),
            progress_percentage int(3),
            step_completions text,
            completed tinyint(1) DEFAULT NULL,
            last_sync datetime,
            PRIMARY KEY (id),
            KEY monday_id (monday_id),
            KEY volunteer_id (volunteer_id),
            KEY workflow_id (workflow_id)
        ) $charset_collate;";
        
        // Opportunities table
        $table_opportunities = $wpdb->prefix . 'fs_opportunities';
        $sql_opportunities = "CREATE TABLE IF NOT EXISTS $table_opportunities (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            monday_id bigint(20) DEFAULT NULL,
            template_id bigint(20) DEFAULT NULL,
            title varchar(255) NOT NULL,
            description text,
            location varchar(255),
            event_date date NOT NULL,
            datetime_start datetime DEFAULT NULL,
            datetime_end datetime DEFAULT NULL,
            spots_available int(11) DEFAULT 0,
            spots_filled int(11) DEFAULT 0,
            requirements text,
            conference varchar(255),
            event_group_id bigint(20) unsigned DEFAULT NULL,
            status varchar(50) DEFAULT 'Open',
            last_sync datetime,
            PRIMARY KEY (id),
            KEY monday_id (monday_id),
            KEY template_id (template_id),
            KEY event_date (event_date),
            KEY event_group_id (event_group_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Signups table
        $table_signups = $wpdb->prefix . 'fs_signups';
        $sql_signups = "CREATE TABLE IF NOT EXISTS $table_signups (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            opportunity_id bigint(20) NOT NULL,
            volunteer_id bigint(20) NOT NULL,
            registration_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'confirmed',
            signup_date datetime NOT NULL,
            cancelled_date datetime DEFAULT NULL,
            attendance_confirmed tinyint(1) DEFAULT 0,
            confirmation_date datetime DEFAULT NULL,
            reminder_sent tinyint(1) DEFAULT 0,
            notes text,
            PRIMARY KEY (id),
            KEY opportunity_id (opportunity_id),
            KEY volunteer_id (volunteer_id),
            KEY registration_id (registration_id),
            KEY status (status),
            KEY attendance_confirmed (attendance_confirmed)
        ) $charset_collate;";

        // Event groups table
        $table_event_groups = $wpdb->prefix . 'fs_event_groups';
        $sql_event_groups = "CREATE TABLE IF NOT EXISTS $table_event_groups (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description longtext NULL,
            location varchar(255) NULL,
            selection_mode varchar(20) NOT NULL,
            min_select int DEFAULT NULL,
            max_select int DEFAULT NULL,
            day_label_mode varchar(20) NOT NULL DEFAULT 'AUTO',
            requires_minor_permission tinyint(1) NOT NULL DEFAULT 0,
            minor_age_threshold int NOT NULL DEFAULT 18,
            signshyft_template_version_id varchar(64) DEFAULT NULL,
            reminder_final_hours int DEFAULT NULL,
            reminder_recipients varchar(30) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY selection_mode (selection_mode),
            KEY requires_minor_permission (requires_minor_permission)
        ) $charset_collate;";

        // Event registrations table
        $table_event_registrations = $wpdb->prefix . 'fs_event_registrations';
        $sql_event_registrations = "CREATE TABLE IF NOT EXISTS $table_event_registrations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_group_id bigint(20) unsigned NOT NULL,
            volunteer_id bigint(20) unsigned NOT NULL,
            guardian_email varchar(255) DEFAULT NULL,
            guardian_name varchar(255) DEFAULT NULL,
            guardian_phone varchar(50) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            permission_status varchar(20) NOT NULL DEFAULT 'not_required',
            permission_channel varchar(20) NOT NULL DEFAULT 'signshyft',
            permission_expires_at datetime DEFAULT NULL,
            reminder_24h_sent_at datetime DEFAULT NULL,
            reminder_final_sent_at datetime DEFAULT NULL,
            permission_signed_at datetime DEFAULT NULL,
            signshyft_envelope_id varchar(128) DEFAULT NULL,
            signshyft_recipient_id varchar(128) DEFAULT NULL,
            signshyft_status varchar(64) DEFAULT NULL,
            manual_signer_url text DEFAULT NULL,
            manual_request_sent_at datetime DEFAULT NULL,
            manual_signed_document_path text DEFAULT NULL,
            document_object_key varchar(255) DEFAULT NULL,
            document_sha256 varchar(128) DEFAULT NULL,
            template_scope varchar(30) NOT NULL DEFAULT 'single_event',
            template_scope_ref_id varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY event_group_id (event_group_id),
            KEY volunteer_id (volunteer_id),
            KEY status (status),
            KEY permission_status (permission_status),
            KEY permission_channel (permission_channel),
            KEY permission_expires_at (permission_expires_at),
            KEY signshyft_envelope_id (signshyft_envelope_id)
        ) $charset_collate;";

        // Webhook delivery tracking table (idempotency + minimal audit)
        $table_webhook_deliveries = $wpdb->prefix . 'fs_signshyft_webhook_deliveries';
        $sql_webhook_deliveries = "CREATE TABLE IF NOT EXISTS $table_webhook_deliveries (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            delivery_id varchar(64) NOT NULL,
            event_type varchar(64) NOT NULL,
            envelope_id varchar(128) DEFAULT NULL,
            received_at datetime NOT NULL,
            processed_at datetime DEFAULT NULL,
            status_code int DEFAULT NULL,
            error_reason varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY delivery_id (delivery_id),
            KEY event_type (event_type),
            KEY envelope_id (envelope_id),
            KEY received_at (received_at)
        ) $charset_collate;";
        
        // Programs table
        $table_programs = $wpdb->prefix . 'fs_programs';
        $sql_programs = "CREATE TABLE IF NOT EXISTS $table_programs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            monday_id bigint(20) DEFAULT NULL,
            name varchar(255) NOT NULL,
            short_description varchar(255),
            long_description text,
            active_status varchar(50) DEFAULT 'Active',
            display_order int(11) DEFAULT 0,
            last_sync datetime,
            PRIMARY KEY (id),
            KEY monday_id (monday_id),
            KEY active_status (active_status),
            KEY display_order (display_order)
        ) $charset_collate;";

        // Handoff Notifications table
        $table_handoff = $wpdb->prefix . 'fs_handoff_notifications';
        $sql_handoff = "CREATE TABLE IF NOT EXISTS $table_handoff (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            template_id bigint(20) NOT NULL,
            period_start date NOT NULL,
            period_end date NOT NULL,
            volunteer_id bigint(20) NOT NULL,
            next_volunteer_id bigint(20) DEFAULT NULL,
            notification_type enum('period_start','period_end') NOT NULL,
            sent_date datetime NOT NULL,
            KEY idx_template_period (template_id,period_start,volunteer_id,notification_type),
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_volunteers);
        dbDelta($sql_roles);
        dbDelta($sql_volunteer_roles);
        dbDelta($sql_workflows);
        dbDelta($sql_progress);
        dbDelta($sql_opportunities);
        dbDelta($sql_signups);
        dbDelta($sql_programs);
        dbDelta($sql_handoff);
        dbDelta($sql_event_groups);
        dbDelta($sql_event_registrations);
        dbDelta($sql_webhook_deliveries);
    }
    
    private static function set_default_settings() {
        // Set default Monday.com board IDs if not already set
        if (!get_option('fs_board_ids')) {
            add_option('fs_board_ids', array(
                'people' => '',
                'opportunities' => '',
                'signups' => '',
                'roles' => '',
                'workflows' => '',
                'progress' => '',
                'programs' => ''
            ));
        }
        
        // Set default notification settings
        if (!get_option('fs_notification_settings')) {
            add_option('fs_notification_settings', array(
                'welcome_email_enabled' => true,
                'staff_notification_enabled' => true,
                'signup_confirmation_enabled' => true,
                'step_completion_enabled' => true
            ));
        }
        
        // Set Monday.com API settings
        if (!get_option('fs_monday_api_token')) {
            add_option('fs_monday_api_token', '');
        }
        
        if (!get_option('fs_monday_enabled')) {
            add_option('fs_monday_enabled', false);
        }

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
    
    public static function deactivate() {
        // Optionally clear scheduled events
        wp_clear_scheduled_hook('fs_sync_volunteers');
    }
    
    public static function uninstall() {
        global $wpdb;
        
        // Drop all tables
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_time_records");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_signups");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_opportunities");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_progress");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_workflows");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_volunteer_roles");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_roles");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_volunteers");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_programs");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_handoff_notifications");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_event_groups");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_event_registrations");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_signshyft_webhook_deliveries");
        
        // Delete all options
        delete_option('fs_board_ids');
        delete_option('fs_notification_settings');
        delete_option('fs_monday_api_token');
        delete_option('fs_monday_enabled');
        delete_option('fs_teen_permission_settings');
        
        // Clear scheduled events
        wp_clear_scheduled_hook('fs_sync_volunteers');
    }
    
}
