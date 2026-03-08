<?php
if (!defined('ABSPATH')) exit;

/**
 * Substitute Finder System
 * Request coverage, notify qualified substitutes, track swap history
 */
class FS_Substitute_Finder {

    public static function init() {
        add_action('wp_ajax_fs_request_substitute', array(__CLASS__, 'request_substitute'));
        add_action('wp_ajax_nopriv_fs_request_substitute', array(__CLASS__, 'request_substitute'));

        add_action('wp_ajax_fs_accept_substitute', array(__CLASS__, 'accept_substitute'));
        add_action('wp_ajax_nopriv_fs_accept_substitute', array(__CLASS__, 'accept_substitute'));

        add_action('wp_ajax_fs_cancel_substitute_request', array(__CLASS__, 'cancel_request'));
        add_action('wp_ajax_nopriv_fs_cancel_substitute_request', array(__CLASS__, 'cancel_request'));
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $substitute_requests_table = $wpdb->prefix . 'fs_substitute_requests';
        $sql = "CREATE TABLE IF NOT EXISTS $substitute_requests_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            signup_id bigint(20) unsigned NOT NULL,
            original_volunteer_id bigint(20) unsigned NOT NULL,
            substitute_volunteer_id bigint(20) unsigned NULL,
            opportunity_id bigint(20) unsigned NOT NULL,
            reason text NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            requested_at datetime NOT NULL,
            fulfilled_at datetime NULL,
            cancelled_at datetime NULL,
            PRIMARY KEY (id),
            KEY signup_id (signup_id),
            KEY original_volunteer_id (original_volunteer_id),
            KEY substitute_volunteer_id (substitute_volunteer_id),
            KEY opportunity_id (opportunity_id),
            KEY status (status),
            KEY requested_at (requested_at)
        ) $charset_collate;";

        $swap_history_table = $wpdb->prefix . 'fs_swap_history';
        $sql .= "CREATE TABLE IF NOT EXISTS $swap_history_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            substitute_request_id bigint(20) unsigned NOT NULL,
            original_volunteer_id bigint(20) unsigned NOT NULL,
            substitute_volunteer_id bigint(20) unsigned NOT NULL,
            opportunity_id bigint(20) unsigned NOT NULL,
            swapped_at datetime NOT NULL,
            notes text NULL,
            PRIMARY KEY (id),
            KEY substitute_request_id (substitute_request_id),
            KEY original_volunteer_id (original_volunteer_id),
            KEY substitute_volunteer_id (substitute_volunteer_id),
            KEY opportunity_id (opportunity_id),
            KEY swapped_at (swapped_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Request a substitute
     */
    public static function request_substitute() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $signup_id = isset($_POST['signup_id']) ? intval($_POST['signup_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        global $wpdb;

        // Verify signup belongs to this volunteer
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, o.title, o.event_date, o.location
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.id = %d AND s.volunteer_id = %d AND s.status = 'confirmed'",
            $signup_id,
            $volunteer->id
        ));

        if (!$signup) {
            wp_send_json_error('Signup not found or does not belong to you');
            return;
        }

        // Check if event is in the past
        if (strtotime($signup->event_date) < time()) {
            wp_send_json_error('Cannot request substitutes for past events');
            return;
        }

        // Check if already has pending request
        $existing_request = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_substitute_requests
             WHERE signup_id = %d AND status = 'pending'",
            $signup_id
        ));

        if ($existing_request) {
            wp_send_json_error('You already have a pending substitute request for this shift');
            return;
        }

        // Create substitute request
        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_substitute_requests",
            array(
                'signup_id' => $signup_id,
                'original_volunteer_id' => $volunteer->id,
                'opportunity_id' => $signup->opportunity_id,
                'reason' => $reason,
                'status' => 'pending',
                'requested_at' => current_time('mysql')
            )
        );

        if ($result) {
            $request_id = $wpdb->insert_id;

            FS_Audit_Log::log('substitute_requested', 'substitute_request', $request_id, array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $signup->opportunity_id,
                'signup_id' => $signup_id
            ));

            // Find and notify qualified substitutes
            $qualified_volunteers = self::find_qualified_substitutes($signup->opportunity_id, $volunteer->id);
            self::notify_qualified_substitutes($request_id, $signup, $qualified_volunteers);

            wp_send_json_success(array(
                'message' => 'Substitute request posted! We\'ve notified ' . count($qualified_volunteers) . ' qualified volunteers.',
                'notified_count' => count($qualified_volunteers)
            ));
        } else {
            wp_send_json_error('Failed to create substitute request');
        }
    }

    /**
     * Accept substitute request
     */
    public static function accept_substitute() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;

        global $wpdb;

        // Get request details
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, s.id as signup_id, o.title, o.event_date
             FROM {$wpdb->prefix}fs_substitute_requests r
             JOIN {$wpdb->prefix}fs_signups s ON r.signup_id = s.id
             JOIN {$wpdb->prefix}fs_opportunities o ON r.opportunity_id = o.id
             WHERE r.id = %d AND r.status = 'pending'",
            $request_id
        ));

        if (!$request) {
            wp_send_json_error('Request not found or already fulfilled');
            return;
        }

        // Check if substitute is different from original volunteer
        if ($request->original_volunteer_id == $volunteer->id) {
            wp_send_json_error('You cannot substitute for yourself');
            return;
        }

        // Check eligibility for this opportunity
        $is_eligible = FS_Eligibility_Checker::check_eligibility($volunteer->id, $request->opportunity_id);
        if (!$is_eligible) {
            wp_send_json_error('You do not meet the requirements for this opportunity');
            return;
        }

        // Check for scheduling conflicts
        $has_conflict = FS_Signup::check_conflict($volunteer->id, $request->opportunity_id);
        if ($has_conflict) {
            wp_send_json_error('You have a scheduling conflict with this shift');
            return;
        }

        // Update original signup to cancelled
        $wpdb->update(
            "{$wpdb->prefix}fs_signups",
            array('status' => 'cancelled'),
            array('id' => $request->signup_id)
        );

        // Create new signup for substitute
        $new_signup_result = FS_Signup::create($volunteer->id, $request->opportunity_id, null);

        if (!is_wp_error($new_signup_result)) {
            // Mark request as fulfilled
            $wpdb->update(
                "{$wpdb->prefix}fs_substitute_requests",
                array(
                    'substitute_volunteer_id' => $volunteer->id,
                    'status' => 'fulfilled',
                    'fulfilled_at' => current_time('mysql')
                ),
                array('id' => $request_id)
            );

            // Record in swap history
            $wpdb->insert(
                "{$wpdb->prefix}fs_swap_history",
                array(
                    'substitute_request_id' => $request_id,
                    'original_volunteer_id' => $request->original_volunteer_id,
                    'substitute_volunteer_id' => $volunteer->id,
                    'opportunity_id' => $request->opportunity_id,
                    'swapped_at' => current_time('mysql')
                )
            );

            FS_Audit_Log::log('substitute_accepted', 'substitute_request', $request_id, array(
                'substitute_volunteer_id' => $volunteer->id,
                'original_volunteer_id' => $request->original_volunteer_id,
                'opportunity_id' => $request->opportunity_id
            ));

            // Send notifications
            self::send_substitute_confirmation($volunteer, $request);
            self::send_original_volunteer_notification($request, $volunteer);

            wp_send_json_success('You have successfully accepted this shift! Thank you for helping out.');
        } else {
            wp_send_json_error('Failed to create substitute signup: ' . $new_signup_result->get_error_message());
        }
    }

    /**
     * Cancel substitute request
     */
    public static function cancel_request() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;

        global $wpdb;

        // Verify request belongs to this volunteer
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_substitute_requests
             WHERE id = %d AND original_volunteer_id = %d AND status = 'pending'",
            $request_id,
            $volunteer->id
        ));

        if (!$request) {
            wp_send_json_error('Request not found or does not belong to you');
            return;
        }

        // Update status to cancelled
        $result = $wpdb->update(
            "{$wpdb->prefix}fs_substitute_requests",
            array(
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql')
            ),
            array('id' => $request_id)
        );

        if ($result) {
            FS_Audit_Log::log('substitute_request_cancelled', 'substitute_request', $request_id, array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $request->opportunity_id
            ));

            wp_send_json_success('Substitute request cancelled');
        } else {
            wp_send_json_error('Failed to cancel request');
        }
    }

    /**
     * Find qualified substitutes
     */
    private static function find_qualified_substitutes($opportunity_id, $exclude_volunteer_id) {
        global $wpdb;

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            return array();
        }

        // Get volunteers with matching roles and no conflicts
        $volunteers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT v.*
             FROM {$wpdb->prefix}fs_volunteers v
             WHERE v.id != %d
             AND v.volunteer_status = 'active'
             AND EXISTS (
                 SELECT 1 FROM {$wpdb->prefix}fs_volunteer_roles vr
                 WHERE vr.volunteer_id = v.id
                 AND vr.role_id IN (
                     SELECT role_id FROM {$wpdb->prefix}fs_opportunity_roles WHERE opportunity_id = %d
                 )
             )
             AND NOT EXISTS (
                 SELECT 1 FROM {$wpdb->prefix}fs_signups s
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 WHERE s.volunteer_id = v.id
                 AND s.status = 'confirmed'
                 AND o.event_date = %s
             )",
            $exclude_volunteer_id,
            $opportunity_id,
            $opportunity->event_date
        ));

        return $volunteers;
    }

    /**
     * Notify qualified substitutes
     */
    private static function notify_qualified_substitutes($request_id, $signup, $volunteers) {
        foreach ($volunteers as $volunteer) {
            $subject = 'Substitute needed for ' . $signup->title;

            $accept_url = add_query_arg(array(
                'token' => $volunteer->access_token,
                'view' => 'substitutes',
                'request_id' => $request_id
            ), home_url('/volunteer-portal/'));

            $message = "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #0073aa;'>Help Needed: Substitute Volunteer</h2>

                    <p>Hi " . esc_html($volunteer->name) . ",</p>

                    <p>A volunteer needs a substitute for an upcoming shift. Can you help?</p>

                    <div style='background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                        <strong>" . esc_html($signup->title) . "</strong><br>
                        Date: " . date('F j, Y', strtotime($signup->event_date)) . "<br>
                        Location: " . esc_html($signup->location) . "
                    </div>

                    <p>You're receiving this because you're qualified for this shift and don't have any conflicts.</p>

                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='" . esc_url($accept_url) . "' style='display: inline-block; background: #0073aa; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                            View Substitute Requests
                        </a>
                    </div>

                    <p style='color: #666; font-size: 14px;'>
                        First one to accept gets the shift!
                    </p>
                </div>
            </body>
            </html>
            ";

            $headers = array('Content-Type: text/html; charset=UTF-8');
            wp_mail($volunteer->email, $subject, $message, $headers);
        }
    }

    /**
     * Send substitute confirmation
     */
    private static function send_substitute_confirmation($substitute_volunteer, $request) {
        $subject = 'You\'re confirmed as a substitute for ' . $request->title;

        $portal_url = add_query_arg(array(
            'token' => $substitute_volunteer->access_token,
            'view' => 'schedule'
        ), home_url('/volunteer-portal/'));

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #28a745;'>Thanks for Stepping Up!</h2>

                <p>Hi " . esc_html($substitute_volunteer->name) . ",</p>

                <p>You've been confirmed as a substitute volunteer for:</p>

                <div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 20px 0;'>
                    <strong>" . esc_html($request->title) . "</strong><br>
                    Date: " . date('F j, Y', strtotime($request->event_date)) . "
                </div>

                <p>Thank you for helping out on short notice!</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . esc_url($portal_url) . "' style='display: inline-block; background: #28a745; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        View My Schedule
                    </a>
                </div>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($substitute_volunteer->email, $subject, $message, $headers);
    }

    /**
     * Send notification to original volunteer
     */
    private static function send_original_volunteer_notification($request, $substitute_volunteer) {
        global $wpdb;

        $original_volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $request->original_volunteer_id
        ));

        if (!$original_volunteer) {
            return;
        }

        $subject = 'Good news! A substitute has been found for ' . $request->title;

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #28a745;'>Substitute Found!</h2>

                <p>Hi " . esc_html($original_volunteer->name) . ",</p>

                <p>Great news! " . esc_html($substitute_volunteer->name) . " has accepted your substitute request for:</p>

                <div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 20px 0;'>
                    <strong>" . esc_html($request->title) . "</strong><br>
                    Date: " . date('F j, Y', strtotime($request->event_date)) . "
                </div>

                <p>Your shift has been covered. You're all set!</p>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($original_volunteer->email, $subject, $message, $headers);
    }

    /**
     * Get active substitute requests for opportunity
     */
    public static function get_active_requests($opportunity_id = null) {
        global $wpdb;

        $where = "WHERE r.status = 'pending'";
        if ($opportunity_id) {
            $where .= $wpdb->prepare(" AND r.opportunity_id = %d", $opportunity_id);
        }

        return $wpdb->get_results(
            "SELECT r.*, o.title, o.event_date, o.location, v.name as original_volunteer_name
             FROM {$wpdb->prefix}fs_substitute_requests r
             JOIN {$wpdb->prefix}fs_opportunities o ON r.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_volunteers v ON r.original_volunteer_id = v.id
             $where
             ORDER BY r.requested_at DESC"
        );
    }

    /**
     * Get swap history for volunteer
     */
    public static function get_swap_history($volunteer_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, o.title, o.event_date,
             v1.name as original_volunteer_name,
             v2.name as substitute_volunteer_name
             FROM {$wpdb->prefix}fs_swap_history h
             JOIN {$wpdb->prefix}fs_opportunities o ON h.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_volunteers v1 ON h.original_volunteer_id = v1.id
             JOIN {$wpdb->prefix}fs_volunteers v2 ON h.substitute_volunteer_id = v2.id
             WHERE h.original_volunteer_id = %d OR h.substitute_volunteer_id = %d
             ORDER BY h.swapped_at DESC",
            $volunteer_id,
            $volunteer_id
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
