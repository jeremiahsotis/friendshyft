<?php
/**
 * Plugin Name: FriendShyft
 * Description: Comprehensive volunteer management system for nonprofits
 * Version: 1.0.0
 * Author: Jeremiah Otis
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: friendshyft
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('FRIENDSHYFT_VERSION', '1.1.0');
define('FRIENDSHYFT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FRIENDSHYFT_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Activation hook
 */
register_activation_hook(__FILE__, 'friendshyft_activate');

function friendshyft_activate() {
    // Create database tables
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-database.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-opportunity-templates.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-time-tracking.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-badges.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-database-migrations.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'fs-email-ingestion-migration.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'fs-team-management-migration.php';

    // Create all tables using the FS_Database class
    FS_Database::create_tables();
    FS_Time_Tracking::create_tables();
    FS_Badges::create_tables();
    FS_Opportunity_Templates::create_tables();

    // Create portal enhancements tables
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-portal-enhancements.php';
    FS_Portal_Enhancements::create_favorites_table();

    // Create audit log table
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-audit-log.php';
    FS_Audit_Log::create_table();

    // Create Google Calendar sync tables
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-google-calendar-sync.php';
    FS_Google_Calendar_Sync::create_tables();

    // Create feedback system tables
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-feedback-system.php';
    FS_Feedback_System::create_tables();

    // Create advanced scheduling tables
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-waitlist-manager.php';
    FS_Waitlist_Manager::create_tables();

    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-substitute-finder.php';
    FS_Substitute_Finder::create_tables();

    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-recurring-schedules.php';
    FS_Recurring_Schedules::create_tables();

    // Create analytics tables
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-predictive-analytics.php';
    FS_Predictive_Analytics::create_tables();

    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-volunteer-retention.php';
    FS_Volunteer_Retention::create_tables();

    // Run database migrations
    FS_Database_Migrations::run_migrations();
    FS_Email_Ingestion_Migration::run();
    FS_Team_Management_Migration::run();

    // Schedule cron jobs

    // 1. Attendance reminders (daily at 9 AM)
    if (!wp_next_scheduled('fs_send_attendance_reminders')) {
        wp_schedule_event(strtotime('09:00:00'), 'daily', 'fs_send_attendance_reminders');
    }

    // 2. Opportunity generation from templates (weekly)
    if (!wp_next_scheduled('fs_generate_opportunities_cron')) {
        // Use weekly custom schedule defined in FS_Opportunity_Templates
        wp_schedule_event(time(), 'weekly', 'fs_generate_opportunities_cron');
    }

    // 3. IMAP inbox check (hourly, only if IMAP is enabled)
    if (!wp_next_scheduled('fs_check_imap_inbox')) {
        // Check every hour for new emails
        wp_schedule_event(time(), 'hourly', 'fs_check_imap_inbox');
    }

    // 4. Monday.com sync (daily, only if Monday.com is configured)
    if (!wp_next_scheduled('fs_sync_cron')) {
        // Run sync once daily
        wp_schedule_event(time(), 'daily', 'fs_sync_cron');
    }

    // 5. Handoff notifications for recurring shifts (daily)
    if (!wp_next_scheduled('fs_daily_handoff_check')) {
        wp_schedule_event(time(), 'daily', 'fs_daily_handoff_check');
    }

    // 6. Google Calendar sync (hourly)
    if (!wp_next_scheduled('fs_check_google_calendar_cron')) {
        wp_schedule_event(time(), 'hourly', 'fs_check_google_calendar_cron');
    }

    // 7. Post-event surveys (daily)
    if (!wp_next_scheduled('fs_send_event_surveys_cron')) {
        wp_schedule_event(time(), 'daily', 'fs_send_event_surveys_cron');
    }

    // 8. Process auto-signups (daily)
    if (!wp_next_scheduled('fs_process_auto_signups_cron')) {
        wp_schedule_event(time(), 'daily', 'fs_process_auto_signups_cron');
    }

    // 9. Update engagement scores (daily)
    if (!wp_next_scheduled('fs_update_engagement_scores_cron')) {
        wp_schedule_event(time(), 'daily', 'fs_update_engagement_scores_cron');
    }

    // 10. Send re-engagement campaigns (weekly)
    if (!wp_next_scheduled('fs_send_reengagement_campaigns_cron')) {
        wp_schedule_event(time(), 'weekly', 'fs_send_reengagement_campaigns_cron');
    }

    // 11. Update predictive analytics (daily)
    if (!wp_next_scheduled('fs_update_predictions_cron')) {
        wp_schedule_event(time(), 'daily', 'fs_update_predictions_cron');
    }

    // 12. Teen permission reminders + expiration checks (hourly)
    if (!wp_next_scheduled('friendshyft_permission_tick')) {
        wp_schedule_event(time(), 'hourly', 'friendshyft_permission_tick');
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, 'friendshyft_deactivate');

function friendshyft_deactivate() {
    // Clear all scheduled hooks
    wp_clear_scheduled_hook('fs_send_attendance_reminders');
    wp_clear_scheduled_hook('fs_generate_opportunities_cron');
    wp_clear_scheduled_hook('fs_check_imap_inbox');
    wp_clear_scheduled_hook('fs_sync_cron');
    wp_clear_scheduled_hook('fs_daily_handoff_check');
    wp_clear_scheduled_hook('fs_check_google_calendar_cron');
    wp_clear_scheduled_hook('fs_send_event_surveys_cron');
    wp_clear_scheduled_hook('fs_process_auto_signups_cron');
    wp_clear_scheduled_hook('fs_update_engagement_scores_cron');
    wp_clear_scheduled_hook('fs_send_reengagement_campaigns_cron');
    wp_clear_scheduled_hook('fs_update_predictions_cron');
    wp_clear_scheduled_hook('friendshyft_permission_tick');

    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Initialize the plugin
 */
add_action('plugins_loaded', 'friendshyft_init');

function friendshyft_init() {
    // Load core classes
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-monday-api.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-sync-engine.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-fs-handoff-notifications.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-reminder-schedule.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-opportunity-templates.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-calendar-export.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-eligibility-checker.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-signup.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-time-tracking.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-notifications.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-badges.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-attendance-confirmation.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-database-migrations.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-team-manager.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-team-signup.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-team-time-tracking.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-team-portal.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-team-kiosk.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-email-parser.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-email-processor.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-email-ingestion.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-interest-email-handler.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-poc-role.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-audit-log.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-google-calendar-sync.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-feedback-system.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-waitlist-manager.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-substitute-finder.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-recurring-schedules.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-predictive-analytics.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-impact-metrics.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-volunteer-retention.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-event-groups.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-signshyft-client.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-event-registrations.php';

    // Ensure latest schema is available for frontend and admin workflows.
    FS_Database_Migrations::run_migrations();

    // Initialize POC role management
    FS_POC_Role::init();

    // Initialize cron-related classes
    FS_Sync_Engine::init();
    FS_Opportunity_Templates::init();
    FS_Handoff_Notifications::init();
    FS_Email_Ingestion::init();
    FS_Google_Calendar_Sync::init();
    FS_Feedback_System::init();
    FS_Waitlist_Manager::init();
    FS_Substitute_Finder::init();
    FS_Recurring_Schedules::init();
    FS_Predictive_Analytics::init();
    FS_Volunteer_Retention::init();
    FS_Event_Registrations::init();

    if (!wp_next_scheduled('friendshyft_permission_tick')) {
        wp_schedule_event(time(), 'hourly', 'friendshyft_permission_tick');
    }

    // Load portal classes
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-volunteer-portal.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-volunteer-registration.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-kiosk.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-public-programs.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-public-opportunities.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-volunteer-profile.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-portal-enhancements.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-portal-google-calendar.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-portal-feedback.php';
    require_once FRIENDSHYFT_PLUGIN_DIR . 'public/class-event-registration-shortcode.php';

    // Initialize profile AJAX handlers and portal enhancements
    FS_Volunteer_Profile::init();
    FS_Portal_Enhancements::init();
    FS_Portal_Google_Calendar::init();
    FS_Portal_Feedback::init();
    FS_Event_Registration_Shortcode::init();
    
    // Load admin classes if in admin
    if (is_admin()) {
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-menu.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-programs.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-roles.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-admin-volunteers.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-opportunities.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-signups.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-dashboard.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/admin-achievements-dashboard.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-workflows.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-templates.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-holidays.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-add-volunteer.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-teams.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-team-migration.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-email-settings.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-email-migration.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-process-email.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-poc-dashboard.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-poc-calendar.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-poc-reports.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-activity-reports.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-bulk-operations.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-audit-log.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-feedback.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-google-settings.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-advanced-scheduling.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-analytics.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-database-sync.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-manual-db-fix.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-teen-event-admin.php';
        require_once FRIENDSHYFT_PLUGIN_DIR . 'admin/class-admin-teen-settings.php';

        // Initialize - admin_post hooks MUST be registered early
        FS_Admin_Dashboard::init();
        FS_Admin_Signups::init();
        FS_Admin_Teams::init();
        FS_Team_Migration_Runner::init();
        FS_Team_Portal::init();
        FS_Team_Kiosk::init();
        FS_Admin_Email_Settings::init();
        FS_Email_Migration_Runner::init();
        FS_Admin_Process_Email::init();
        FS_Admin_POC_Dashboard::init();
        FS_Admin_POC_Calendar::init();
        FS_Admin_POC_Reports::init();
        FS_Admin_Activity_Reports::init();
        FS_Admin_Bulk_Operations::init();
        FS_Admin_Audit_Log::init();
        FS_Admin_Feedback::init();
        FS_Admin_Google_Settings::init();
        FS_Admin_Advanced_Scheduling::init();
        FS_Admin_Analytics::init();
        FS_Admin_Database_Sync::init();
        FS_Admin_Manual_DB_Fix::init();
        FS_Teen_Event_Admin::init();
        FS_Admin_Teen_Settings::init();
    }
}

/**
 * Run database migrations on admin init
 */
add_action('admin_init', 'friendshyft_run_migrations');

function friendshyft_run_migrations() {
    if (class_exists('FS_Database_Migrations')) {
        FS_Database_Migrations::run_migrations();
    }
}

/**
 * Handle donor report CSV export
 */
add_action('admin_post_fs_export_donor_report', 'friendshyft_export_donor_report');

function friendshyft_export_donor_report() {
    if (!current_user_can('manage_friendshyft')) {
        wp_die('Unauthorized');
    }

    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-01-01');
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');

    FS_Impact_Metrics::export_donor_report_csv($start_date, $end_date);
}

/**
 * Enqueue admin styles and scripts
 */
add_action('admin_enqueue_scripts', 'friendshyft_admin_scripts');

function friendshyft_admin_scripts($hook) {
    // Only load on FriendShyft admin pages
    if (strpos($hook, 'friendshyft') === false) {
        return;
    }
    
    // Enqueue admin CSS
    wp_enqueue_style(
        'friendshyft-admin',
        FRIENDSHYFT_PLUGIN_URL . 'css/admin-style.css',
        array(),
        FRIENDSHYFT_VERSION
    );
    
    // Enqueue admin JS
    wp_enqueue_script(
        'friendshyft-admin',
        FRIENDSHYFT_PLUGIN_URL . 'js/admin-script.js',
        array('jquery'),
        FRIENDSHYFT_VERSION,
        true
    );
    
    // Localize script with AJAX URL and nonce
    wp_localize_script('friendshyft-admin', 'friendshyft_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('friendshyft_admin_nonce')
    ));
}

/**
 * Enqueue frontend styles and scripts
 */
add_action('wp_enqueue_scripts', 'friendshyft_frontend_scripts');

function friendshyft_frontend_scripts() {
    // Only load on pages with portal shortcode
    if (!is_singular() && !has_shortcode(get_post()->post_content ?? '', 'volunteer_portal')) {
        return;
    }
    
    // Enqueue jQuery
    wp_enqueue_script('jquery');
    
    // Enqueue frontend CSS
    wp_enqueue_style(
        'friendshyft-portal',
        FRIENDSHYFT_PLUGIN_URL . 'css/portal-style.css',
        array(),
        FRIENDSHYFT_VERSION
    );
    
    // Enqueue frontend JS
    wp_enqueue_script(
        'friendshyft-portal',
        FRIENDSHYFT_PLUGIN_URL . 'js/portal-script.js',
        array('jquery'),
        FRIENDSHYFT_VERSION,
        true
    );
    
    // Localize script with AJAX URL, REST URL, and nonce
    wp_localize_script('friendshyft-portal', 'friendshyft_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'rest_url' => rest_url(),
        'nonce' => wp_create_nonce('friendshyft_portal')
    ));
}

/**
 * Register shortcodes
 */
add_action('init', 'friendshyft_register_shortcodes');

function friendshyft_register_shortcodes() {
    // Volunteer portal shortcode
    add_shortcode('volunteer_portal', array('FS_Volunteer_Portal', 'render_portal'));
    
    // Interest capture form shortcode
    add_shortcode('volunteer_interest_form', array('FS_Volunteer_Registration', 'interest_form_shortcode'));
}

/**
 * Add custom capabilities
 */
add_action('admin_init', 'friendshyft_add_capabilities');

function friendshyft_add_capabilities() {
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('manage_friendshyft');
        $role->add_cap('manage_volunteers');
        $role->add_cap('manage_opportunities');
    }
}

/**
 * Custom post type for public opportunities (optional)
 */
add_action('init', 'friendshyft_register_post_types');

function friendshyft_register_post_types() {
    // This is optional - for public-facing opportunity showcase pages
    register_post_type('fs_public_opp', array(
        'labels' => array(
            'name' => 'Public Opportunities',
            'singular_name' => 'Public Opportunity'
        ),
        'public' => true,
        'has_archive' => true,
        'supports' => array('title', 'editor', 'thumbnail', 'excerpt'),
        'menu_icon' => 'dashicons-megaphone',
        'show_in_rest' => true,
    ));
}

/**
 * Add plugin action links
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'friendshyft_action_links');

function friendshyft_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=friendshyft') . '">Dashboard</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Display admin notices
 */
add_action('admin_notices', 'friendshyft_admin_notices');

function friendshyft_admin_notices() {
    // Check if tables exist
    global $wpdb;
    $volunteers_table = $wpdb->prefix . 'fs_volunteers';
    
    if ($wpdb->get_var("SHOW TABLES LIKE '$volunteers_table'") != $volunteers_table) {
        ?>
        <div class="notice notice-error">
            <p><strong>FriendShyft:</strong> Database tables are missing. Please deactivate and reactivate the plugin to create them.</p>
        </div>
        <?php
    }
}

/**
 * AJAX handler for getting volunteer info (used in admin)
 */
add_action('wp_ajax_fs_get_volunteer_info', 'friendshyft_ajax_get_volunteer_info');

function friendshyft_ajax_get_volunteer_info() {
    check_ajax_referer('friendshyft_admin_nonce', 'nonce');

    if (!current_user_can('manage_friendshyft')) {
        wp_send_json_error('Unauthorized');
    }

    $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
    
    global $wpdb;
    $volunteer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
        $volunteer_id
    ));
    
    if ($volunteer) {
        wp_send_json_success($volunteer);
    } else {
        wp_send_json_error('Volunteer not found');
    }
}

/**
 * AJAX handler for getting role info (used in admin)
 */
add_action('wp_ajax_fs_get_role_info', 'friendshyft_ajax_get_role_info');

function friendshyft_ajax_get_role_info() {
    check_ajax_referer('friendshyft_admin_nonce', 'nonce');

    if (!current_user_can('manage_friendshyft')) {
        wp_send_json_error('Unauthorized');
    }

    $role_id = intval($_POST['role_id'] ?? 0);
    
    global $wpdb;
    $role = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, p.name as program_name 
         FROM {$wpdb->prefix}fs_roles r
         LEFT JOIN {$wpdb->prefix}fs_programs p ON r.program_id = p.id
         WHERE r.id = %d",
        $role_id
    ));
    
    if ($role) {
        wp_send_json_success($role);
    } else {
        wp_send_json_error('Role not found');
    }
}

/**
 * Utility function to log errors
 */
function friendshyft_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        if (is_array($message) || is_object($message)) {
            error_log('FriendShyft: ' . print_r($message, true));
        } else {
            error_log('FriendShyft: ' . $message);
        }
    }
}

/**
 * Add rewrite rules for portal pages
 */
add_action('init', 'friendshyft_add_rewrite_rules');

function friendshyft_add_rewrite_rules() {
    // Add rewrite rule for portal subpages
    add_rewrite_rule(
        '^volunteer-portal/([^/]*)/?',
        'index.php?pagename=volunteer-portal&fs_view=$matches[1]',
        'top'
    );
    
    // Add query var
    add_filter('query_vars', function($vars) {
        $vars[] = 'fs_view';
        return $vars;
    });
}

/**
 * Check plugin version and run updates if needed
 */
add_action('plugins_loaded', 'friendshyft_check_version');

function friendshyft_check_version() {
    $installed_version = get_option('friendshyft_version', '0.0.0');
    
    if (version_compare($installed_version, FRIENDSHYFT_VERSION, '<')) {
        // Run upgrade routine
        friendshyft_upgrade($installed_version);
        
        // Update version number
        update_option('friendshyft_version', FRIENDSHYFT_VERSION);
    }
}

/**
 * Upgrade routine for database schema changes
 */
function friendshyft_upgrade($from_version) {
    global $wpdb;
    
    friendshyft_log("Upgrading FriendShyft from version {$from_version} to " . FRIENDSHYFT_VERSION);
    
    // Version-specific upgrades
    if (version_compare($from_version, '1.0.0', '<')) {
        // Add new columns for attendance confirmation
        $signups_table = $wpdb->prefix . 'fs_signups';
        
        // Check and add attendance_confirmed column
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$signups_table} LIKE 'attendance_confirmed'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$signups_table} ADD COLUMN attendance_confirmed tinyint(1) DEFAULT 0");
        }
        
        // Check and add confirmation_date column
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$signups_table} LIKE 'confirmation_date'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$signups_table} ADD COLUMN confirmation_date datetime DEFAULT NULL");
        }
        
        // Check and add reminder_sent column
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$signups_table} LIKE 'reminder_sent'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$signups_table} ADD COLUMN reminder_sent tinyint(1) DEFAULT 0");
        }
        
        friendshyft_log("Database upgraded to version 1.0.0");
    }
}

/**
 * Plugin loaded message
 */
add_action('admin_init', 'friendshyft_loaded_notice');

function friendshyft_loaded_notice() {
    if (get_transient('friendshyft_activated')) {
        delete_transient('friendshyft_activated');
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>FriendShyft</strong> has been activated successfully! <a href="<?php echo admin_url('admin.php?page=friendshyft'); ?>">Go to Dashboard</a></p>
            </div>
            <?php
        });
    }
}

// Set transient on activation
register_activation_hook(__FILE__, function() {
    set_transient('friendshyft_activated', true, 30);
});

/**
 * Dashboard widget for WordPress admin
 */
add_action('wp_dashboard_setup', 'friendshyft_add_dashboard_widget');

function friendshyft_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'friendshyft_dashboard_widget',
        '🤝 FriendShyft Quick Stats',
        'friendshyft_dashboard_widget_content'
    );
}

function friendshyft_dashboard_widget_content() {
    global $wpdb;
    
    // Get quick stats
    $total_volunteers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteers WHERE status = 'active'");
    $upcoming_opportunities = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_opportunities WHERE event_date >= CURDATE()");
    $signups_today = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups WHERE DATE(signup_date) = CURDATE()");
    $badges_awarded_today = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_badges WHERE DATE(earned_date) = CURDATE()");
    
    ?>
    <div class="friendshyft-widget">
        <style>
            .friendshyft-widget { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
            .fs-widget-stat { text-align: center; padding: 15px; background: #f0f0f1; border-radius: 4px; }
            .fs-widget-stat .number { font-size: 32px; font-weight: 700; color: #2271b1; margin-bottom: 5px; }
            .fs-widget-stat .label { font-size: 13px; color: #646970; }
            .fs-widget-footer { grid-column: 1 / -1; text-align: center; margin-top: 10px; }
        </style>
        
        <div class="fs-widget-stat">
            <div class="number"><?php echo number_format($total_volunteers); ?></div>
            <div class="label">Active Volunteers</div>
        </div>
        
        <div class="fs-widget-stat">
            <div class="number"><?php echo number_format($upcoming_opportunities); ?></div>
            <div class="label">Upcoming Opportunities</div>
        </div>
        
        <div class="fs-widget-stat">
            <div class="number"><?php echo number_format($signups_today); ?></div>
            <div class="label">Signups Today</div>
        </div>
        
        <div class="fs-widget-stat">
            <div class="number"><?php echo number_format($badges_awarded_today); ?></div>
            <div class="label">Badges Earned Today</div>
        </div>
        
        <div class="fs-widget-footer">
            <a href="<?php echo admin_url('admin.php?page=friendshyft'); ?>" class="button button-primary">View Full Dashboard</a>
        </div>
    </div>
    <?php
}
