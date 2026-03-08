<?php
if (!defined('ABSPATH')) exit;

/**
 * Recurring Personal Schedules
 * Volunteers set ongoing availability, auto-signup, blackout dates
 */
class FS_Recurring_Schedules {

    public static function init() {
        add_action('wp_ajax_fs_save_availability', array(__CLASS__, 'save_availability'));
        add_action('wp_ajax_nopriv_fs_save_availability', array(__CLASS__, 'save_availability'));

        add_action('wp_ajax_fs_remove_availability', array(__CLASS__, 'remove_availability'));
        add_action('wp_ajax_nopriv_fs_remove_availability', array(__CLASS__, 'remove_availability'));

        add_action('wp_ajax_fs_add_blackout_date', array(__CLASS__, 'add_blackout_date'));
        add_action('wp_ajax_nopriv_fs_add_blackout_date', array(__CLASS__, 'add_blackout_date'));

        add_action('wp_ajax_fs_remove_blackout_date', array(__CLASS__, 'remove_blackout_date'));
        add_action('wp_ajax_nopriv_fs_remove_blackout_date', array(__CLASS__, 'remove_blackout_date'));

        add_action('wp_ajax_fs_toggle_auto_signup', array(__CLASS__, 'toggle_auto_signup'));
        add_action('wp_ajax_nopriv_fs_toggle_auto_signup', array(__CLASS__, 'toggle_auto_signup'));

        // Hook into opportunity creation to auto-signup volunteers
        add_action('fs_opportunity_created', array(__CLASS__, 'auto_signup_matching_volunteers'), 10, 1);

        // Daily cron to process auto-signups
        add_action('fs_process_auto_signups_cron', array(__CLASS__, 'process_auto_signups'));
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $availability_table = $wpdb->prefix . 'fs_availability';
        $sql = "CREATE TABLE IF NOT EXISTS $availability_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            day_of_week varchar(10) NOT NULL,
            time_slot varchar(20) NOT NULL,
            program_id bigint(20) unsigned NULL,
            auto_signup_enabled tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY day_of_week (day_of_week),
            KEY auto_signup_enabled (auto_signup_enabled),
            UNIQUE KEY unique_availability (volunteer_id, day_of_week, time_slot)
        ) $charset_collate;";

        $blackout_dates_table = $wpdb->prefix . 'fs_blackout_dates';
        $sql .= "CREATE TABLE IF NOT EXISTS $blackout_dates_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            start_date date NOT NULL,
            end_date date NOT NULL,
            reason text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) $charset_collate;";

        $auto_signup_log_table = $wpdb->prefix . 'fs_auto_signup_log';
        $sql .= "CREATE TABLE IF NOT EXISTS $auto_signup_log_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            opportunity_id bigint(20) unsigned NOT NULL,
            signup_id bigint(20) unsigned NULL,
            success tinyint(1) NOT NULL DEFAULT 0,
            reason text NULL,
            processed_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY opportunity_id (opportunity_id),
            KEY processed_at (processed_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Save availability preferences
     */
    public static function save_availability() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $day_of_week = isset($_POST['day_of_week']) ? sanitize_text_field($_POST['day_of_week']) : '';
        $time_slot = isset($_POST['time_slot']) ? sanitize_text_field($_POST['time_slot']) : '';
        $program_id = isset($_POST['program_id']) ? intval($_POST['program_id']) : null;
        $auto_signup = isset($_POST['auto_signup']) ? (bool)$_POST['auto_signup'] : false;

        // Validate day of week
        $valid_days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        if (!in_array($day_of_week, $valid_days)) {
            wp_send_json_error('Invalid day of week');
            return;
        }

        // Validate time slot
        $valid_slots = array('morning', 'afternoon', 'evening', 'all_day');
        if (!in_array($time_slot, $valid_slots)) {
            wp_send_json_error('Invalid time slot');
            return;
        }

        global $wpdb;

        // Insert or update
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_availability
             WHERE volunteer_id = %d AND day_of_week = %s AND time_slot = %s",
            $volunteer->id,
            $day_of_week,
            $time_slot
        ));

        if ($existing) {
            $result = $wpdb->update(
                "{$wpdb->prefix}fs_availability",
                array(
                    'program_id' => $program_id,
                    'auto_signup_enabled' => $auto_signup ? 1 : 0,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing)
            );
        } else {
            $result = $wpdb->insert(
                "{$wpdb->prefix}fs_availability",
                array(
                    'volunteer_id' => $volunteer->id,
                    'day_of_week' => $day_of_week,
                    'time_slot' => $time_slot,
                    'program_id' => $program_id,
                    'auto_signup_enabled' => $auto_signup ? 1 : 0,
                    'created_at' => current_time('mysql')
                )
            );
        }

        if ($result !== false) {
            FS_Audit_Log::log('availability_saved', 'availability', $existing ?: $wpdb->insert_id, array(
                'volunteer_id' => $volunteer->id,
                'day_of_week' => $day_of_week,
                'time_slot' => $time_slot,
                'auto_signup' => $auto_signup
            ));

            wp_send_json_success('Availability saved successfully');
        } else {
            wp_send_json_error('Failed to save availability');
        }
    }

    /**
     * Remove availability slot
     */
    public static function remove_availability() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $availability_id = isset($_POST['availability_id']) ? intval($_POST['availability_id']) : 0;

        global $wpdb;

        // Verify ownership
        $availability = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_availability WHERE id = %d AND volunteer_id = %d",
            $availability_id,
            $volunteer->id
        ));

        if (!$availability) {
            wp_send_json_error('Availability slot not found or does not belong to you');
            return;
        }

        $result = $wpdb->delete(
            "{$wpdb->prefix}fs_availability",
            array('id' => $availability_id)
        );

        if ($result) {
            FS_Audit_Log::log('availability_removed', 'availability', $availability_id, array(
                'volunteer_id' => $volunteer->id,
                'day_of_week' => $availability->day_of_week,
                'time_slot' => $availability->time_slot
            ));

            wp_send_json_success('Availability removed successfully');
        } else {
            wp_send_json_error('Failed to remove availability');
        }
    }

    /**
     * Add blackout date
     */
    public static function add_blackout_date() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        // Validate dates
        if (strtotime($start_date) === false || strtotime($end_date) === false) {
            wp_send_json_error('Invalid dates');
            return;
        }

        if (strtotime($start_date) > strtotime($end_date)) {
            wp_send_json_error('End date must be after start date');
            return;
        }

        global $wpdb;

        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_blackout_dates",
            array(
                'volunteer_id' => $volunteer->id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'reason' => $reason,
                'created_at' => current_time('mysql')
            )
        );

        if ($result) {
            FS_Audit_Log::log('blackout_date_added', 'blackout_date', $wpdb->insert_id, array(
                'volunteer_id' => $volunteer->id,
                'start_date' => $start_date,
                'end_date' => $end_date
            ));

            wp_send_json_success('Blackout date added successfully');
        } else {
            wp_send_json_error('Failed to add blackout date');
        }
    }

    /**
     * Remove blackout date
     */
    public static function remove_blackout_date() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $blackout_id = isset($_POST['blackout_id']) ? intval($_POST['blackout_id']) : 0;

        global $wpdb;

        // Verify ownership
        $blackout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_blackout_dates WHERE id = %d AND volunteer_id = %d",
            $blackout_id,
            $volunteer->id
        ));

        if (!$blackout) {
            wp_send_json_error('Blackout date not found or does not belong to you');
            return;
        }

        $result = $wpdb->delete(
            "{$wpdb->prefix}fs_blackout_dates",
            array('id' => $blackout_id)
        );

        if ($result) {
            FS_Audit_Log::log('blackout_date_removed', 'blackout_date', $blackout_id, array(
                'volunteer_id' => $volunteer->id
            ));

            wp_send_json_success('Blackout date removed');
        } else {
            wp_send_json_error('Failed to remove blackout date');
        }
    }

    /**
     * Toggle auto-signup for availability
     */
    public static function toggle_auto_signup() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $availability_id = isset($_POST['availability_id']) ? intval($_POST['availability_id']) : 0;
        $enabled = isset($_POST['enabled']) ? (bool)$_POST['enabled'] : false;

        global $wpdb;

        $result = $wpdb->update(
            "{$wpdb->prefix}fs_availability",
            array('auto_signup_enabled' => $enabled ? 1 : 0),
            array(
                'id' => $availability_id,
                'volunteer_id' => $volunteer->id
            )
        );

        if ($result !== false) {
            wp_send_json_success($enabled ? 'Auto-signup enabled' : 'Auto-signup disabled');
        } else {
            wp_send_json_error('Failed to toggle auto-signup');
        }
    }

    /**
     * Auto-signup matching volunteers when opportunity created
     */
    public static function auto_signup_matching_volunteers($opportunity_id) {
        global $wpdb;

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity || $opportunity->status !== 'published') {
            return;
        }

        // Get day of week and time slot approximation
        $day_of_week = strtolower(date('l', strtotime($opportunity->event_date)));

        // Get volunteers with matching availability and auto-signup enabled
        $volunteers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT a.volunteer_id, v.*
             FROM {$wpdb->prefix}fs_availability a
             JOIN {$wpdb->prefix}fs_volunteers v ON a.volunteer_id = v.id
             WHERE a.day_of_week = %s
             AND a.auto_signup_enabled = 1
             AND v.volunteer_status = 'active'
             AND (a.program_id IS NULL OR a.program_id = %d)",
            $day_of_week,
            $opportunity->program_id
        ));

        foreach ($volunteers as $volunteer) {
            self::attempt_auto_signup($volunteer, $opportunity);
        }
    }

    /**
     * Process auto-signups (cron job)
     */
    public static function process_auto_signups() {
        global $wpdb;

        // Get opportunities in next 30 days that aren't full
        $opportunities = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             AND status = 'published'
             AND spots_filled < spots_available"
        );

        foreach ($opportunities as $opportunity) {
            self::auto_signup_matching_volunteers($opportunity->id);
        }
    }

    /**
     * Attempt auto-signup for a volunteer
     */
    private static function attempt_auto_signup($volunteer, $opportunity) {
        global $wpdb;

        // Check if already signed up
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND opportunity_id = %d",
            $volunteer->id,
            $opportunity->id
        ));

        if ($existing) {
            self::log_auto_signup($volunteer->id, $opportunity->id, null, false, 'Already signed up');
            return;
        }

        // Check blackout dates
        $is_blacked_out = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_blackout_dates
             WHERE volunteer_id = %d
             AND %s BETWEEN start_date AND end_date",
            $volunteer->id,
            $opportunity->event_date
        ));

        if ($is_blacked_out) {
            self::log_auto_signup($volunteer->id, $opportunity->id, null, false, 'Blackout date');
            return;
        }

        // Check eligibility
        $is_eligible = FS_Eligibility_Checker::check_eligibility($volunteer->id, $opportunity->id);
        if (!$is_eligible) {
            self::log_auto_signup($volunteer->id, $opportunity->id, null, false, 'Not eligible');
            return;
        }

        // Check conflicts
        $has_conflict = FS_Signup::check_conflict($volunteer->id, $opportunity->id);
        if ($has_conflict) {
            self::log_auto_signup($volunteer->id, $opportunity->id, null, false, 'Schedule conflict');
            return;
        }

        // Attempt signup
        $signup_result = FS_Signup::create($volunteer->id, $opportunity->id, null);

        if (!is_wp_error($signup_result)) {
            self::log_auto_signup($volunteer->id, $opportunity->id, $signup_result, true, 'Success');

            // Send notification
            self::send_auto_signup_notification($volunteer, $opportunity);
        } else {
            self::log_auto_signup($volunteer->id, $opportunity->id, null, false, $signup_result->get_error_message());
        }
    }

    /**
     * Log auto-signup attempt
     */
    private static function log_auto_signup($volunteer_id, $opportunity_id, $signup_id, $success, $reason) {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}fs_auto_signup_log",
            array(
                'volunteer_id' => $volunteer_id,
                'opportunity_id' => $opportunity_id,
                'signup_id' => $signup_id,
                'success' => $success ? 1 : 0,
                'reason' => $reason,
                'processed_at' => current_time('mysql')
            )
        );
    }

    /**
     * Send auto-signup notification
     */
    private static function send_auto_signup_notification($volunteer, $opportunity) {
        $subject = 'You\'ve been automatically signed up for ' . $opportunity->title;

        $portal_url = add_query_arg(array(
            'token' => $volunteer->access_token,
            'view' => 'schedule'
        ), home_url('/volunteer-portal/'));

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #0073aa;'>Automatic Signup</h2>

                <p>Hi " . esc_html($volunteer->name) . ",</p>

                <p>Based on your availability preferences, we've automatically signed you up for:</p>

                <div style='background: #f0f8ff; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                    <strong>" . esc_html($opportunity->title) . "</strong><br>
                    Date: " . date('F j, Y', strtotime($opportunity->event_date)) . "<br>
                    Location: " . esc_html($opportunity->location) . "
                </div>

                <p>This matches your availability schedule. If you can no longer make it, please cancel as soon as possible.</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . esc_url($portal_url) . "' style='display: inline-block; background: #0073aa; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        View My Schedule
                    </a>
                </div>

                <p style='color: #666; font-size: 14px;'>
                    You can manage your availability preferences and blackout dates in the volunteer portal.
                </p>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($volunteer->email, $subject, $message, $headers);
    }

    /**
     * Get volunteer's availability
     */
    public static function get_availability($volunteer_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.name as program_name
             FROM {$wpdb->prefix}fs_availability a
             LEFT JOIN {$wpdb->prefix}fs_programs p ON a.program_id = p.id
             WHERE a.volunteer_id = %d
             ORDER BY
                 FIELD(a.day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'),
                 FIELD(a.time_slot, 'morning', 'afternoon', 'evening', 'all_day')",
            $volunteer_id
        ));
    }

    /**
     * Get volunteer's blackout dates
     */
    public static function get_blackout_dates($volunteer_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_blackout_dates
             WHERE volunteer_id = %d
             ORDER BY start_date ASC",
            $volunteer_id
        ));
    }

    /**
     * Get auto-signup log for volunteer
     */
    public static function get_auto_signup_log($volunteer_id, $limit = 50) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, o.title, o.event_date
             FROM {$wpdb->prefix}fs_auto_signup_log l
             JOIN {$wpdb->prefix}fs_opportunities o ON l.opportunity_id = o.id
             WHERE l.volunteer_id = %d
             ORDER BY l.processed_at DESC
             LIMIT %d",
            $volunteer_id,
            $limit
        ));
    }

    /**
     * Get volunteer from request (token or logged-in)
     */
    private static function get_volunteer_from_request() {
        global $wpdb;

        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        if ($token) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
                $token
            ));
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE monday_user_id = %d",
                $user_id
            ));
        }

        return null;
    }
}
