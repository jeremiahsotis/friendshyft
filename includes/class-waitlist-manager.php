<?php
if (!defined('ABSPATH')) exit;

/**
 * Waitlist Management System
 * Automatic promotion, ranked waitlists, auto-notifications
 */
class FS_Waitlist_Manager {

    public static function init() {
        add_action('wp_ajax_fs_join_waitlist', array(__CLASS__, 'join_waitlist'));
        add_action('wp_ajax_nopriv_fs_join_waitlist', array(__CLASS__, 'join_waitlist'));

        add_action('wp_ajax_fs_leave_waitlist', array(__CLASS__, 'leave_waitlist'));
        add_action('wp_ajax_nopriv_fs_leave_waitlist', array(__CLASS__, 'leave_waitlist'));

        // Hook into signup cancellation to promote from waitlist
        add_action('fs_signup_cancelled', array(__CLASS__, 'promote_from_waitlist'), 10, 3);
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $waitlist_table = $wpdb->prefix . 'fs_waitlist';
        $sql = "CREATE TABLE IF NOT EXISTS $waitlist_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            opportunity_id bigint(20) unsigned NOT NULL,
            shift_id bigint(20) unsigned NULL,
            registration_id bigint(20) unsigned NULL,
            rank_score int NOT NULL DEFAULT 0,
            priority_level varchar(20) NOT NULL DEFAULT 'normal',
            joined_at datetime NOT NULL,
            notified_at datetime NULL,
            promoted_at datetime NULL,
            status varchar(20) NOT NULL DEFAULT 'waiting',
            notes text NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY opportunity_id (opportunity_id),
            KEY shift_id (shift_id),
            KEY registration_id (registration_id),
            KEY status (status),
            KEY rank_score (rank_score),
            KEY joined_at (joined_at),
            UNIQUE KEY unique_waitlist (volunteer_id, opportunity_id, shift_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Join waitlist for an opportunity
     */
    public static function join_waitlist() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $opportunity_id = isset($_POST['opportunity_id']) ? intval($_POST['opportunity_id']) : 0;

        global $wpdb;

        // Check if opportunity exists and is full
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            wp_send_json_error('Opportunity not found');
            return;
        }

        if ($opportunity->spots_filled < $opportunity->spots_available) {
            wp_send_json_error('This opportunity still has open spots. Please sign up directly.');
            return;
        }

        // Check if already signed up
        $existing_signup = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND opportunity_id = %d AND status IN ('confirmed', 'pending')",
            $volunteer->id,
            $opportunity_id
        ));

        if ($existing_signup) {
            wp_send_json_error('You are already signed up for this opportunity');
            return;
        }

        // Check if already on waitlist
        $existing_waitlist = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_waitlist
             WHERE volunteer_id = %d AND opportunity_id = %d AND status = 'waiting'",
            $volunteer->id,
            $opportunity_id
        ));

        if ($existing_waitlist) {
            wp_send_json_error('You are already on the waitlist for this opportunity');
            return;
        }

        // Calculate rank score (based on volunteer history)
        $rank_score = self::calculate_rank_score($volunteer->id);

        // Insert into waitlist
        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_waitlist",
            array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id,
                'rank_score' => $rank_score,
                'priority_level' => 'normal',
                'joined_at' => current_time('mysql'),
                'status' => 'waiting'
            )
        );

        if ($result) {
            // Get position in waitlist
            $position = self::get_waitlist_position($volunteer->id, $opportunity_id);

            FS_Audit_Log::log('waitlist_joined', 'waitlist', $wpdb->insert_id, array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id,
                'position' => $position
            ));

            // Send confirmation email
            self::send_waitlist_confirmation($volunteer, $opportunity, $position);

            wp_send_json_success(array(
                'message' => "You've been added to the waitlist! You're #$position in line.",
                'position' => $position
            ));
        } else {
            wp_send_json_error('Failed to join waitlist');
        }
    }

    /**
     * Leave waitlist
     */
    public static function leave_waitlist() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $opportunity_id = isset($_POST['opportunity_id']) ? intval($_POST['opportunity_id']) : 0;

        global $wpdb;

        $result = $wpdb->update(
            "{$wpdb->prefix}fs_waitlist",
            array('status' => 'removed'),
            array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id,
                'status' => 'waiting'
            )
        );

        if ($result) {
            FS_Audit_Log::log('waitlist_left', 'waitlist', 0, array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id
            ));

            wp_send_json_success('You have been removed from the waitlist');
        } else {
            wp_send_json_error('Failed to leave waitlist');
        }
    }

    /**
     * Promote from waitlist when spot opens
     */
    public static function promote_from_waitlist($volunteer_id, $opportunity_id, $shift_id = null) {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft Waitlist: promote_from_waitlist called - volunteer_id: $volunteer_id, opportunity_id: $opportunity_id, shift_id: " . ($shift_id ?: 'NULL'));
        }

        // If shift-based, check shift capacity
        if ($shift_id) {
            $shift = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts WHERE id = %d",
                $shift_id
            ));

            if (!$shift || $shift->spots_filled >= $shift->spots_available) {
                return; // Shift still full or doesn't exist
            }

            // Get next person on waitlist for this specific shift
            $next_volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT w.*, v.name, v.email, v.access_token
                 FROM {$wpdb->prefix}fs_waitlist w
                 JOIN {$wpdb->prefix}fs_volunteers v ON w.volunteer_id = v.id
                 WHERE w.opportunity_id = %d
                 AND w.shift_id = %d
                 AND w.status = 'waiting'
                 ORDER BY w.rank_score DESC, w.joined_at ASC
                 LIMIT 1",
                $opportunity_id,
                $shift_id
            ));
        } else {
            // Non-shift opportunity
            $opportunity = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
                $opportunity_id
            ));

            if (!$opportunity || $opportunity->spots_filled >= $opportunity->spots_available) {
                return; // Still full or doesn't exist
            }

            // Get next person on waitlist (no shift filter)
            $next_volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT w.*, v.name, v.email, v.access_token
                 FROM {$wpdb->prefix}fs_waitlist w
                 JOIN {$wpdb->prefix}fs_volunteers v ON w.volunteer_id = v.id
                 WHERE w.opportunity_id = %d
                 AND w.shift_id IS NULL
                 AND w.status = 'waiting'
                 ORDER BY w.rank_score DESC, w.joined_at ASC
                 LIMIT 1",
                $opportunity_id
            ));
        }

        if (!$next_volunteer) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("FriendShyft Waitlist: No volunteer found on waitlist for opportunity $opportunity_id" . ($shift_id ? ", shift $shift_id" : ""));
            }
            return; // No one on waitlist
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft Waitlist: Found volunteer on waitlist - ID: {$next_volunteer->volunteer_id}, Name: {$next_volunteer->name}");
        }

        // Never auto-promote entries attached to teen registration authority.
        if (!empty($next_volunteer->registration_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("FriendShyft Waitlist: Skipping auto-promotion for registration-linked entry {$next_volunteer->id}");
            }
            return;
        }

        // Locked safety policy: no automatic promotions for minors or unknown-age volunteers.
        if (class_exists('FS_Event_Registrations') && FS_Event_Registrations::should_skip_auto_promotion_for_volunteer($next_volunteer->volunteer_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("FriendShyft Waitlist: Skipping auto-promotion for minor/unknown volunteer {$next_volunteer->volunteer_id}");
            }
            return;
        }

        // Automatically create signup for them with the correct shift
        $signup_result = FS_Signup::create($next_volunteer->volunteer_id, $opportunity_id, $shift_id);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft Waitlist: Signup result - " . print_r($signup_result, true));
        }

        if (!is_wp_error($signup_result) && isset($signup_result['success']) && $signup_result['success']) {
            // Mark as promoted
            $wpdb->update(
                "{$wpdb->prefix}fs_waitlist",
                array(
                    'status' => 'promoted',
                    'promoted_at' => current_time('mysql')
                ),
                array('id' => $next_volunteer->id)
            );

            FS_Audit_Log::log('waitlist_promoted', 'waitlist', $next_volunteer->id, array(
                'volunteer_id' => $next_volunteer->volunteer_id,
                'opportunity_id' => $opportunity_id,
                'shift_id' => $shift_id
            ));

            // Get opportunity details for notification
            $opportunity = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
                $opportunity_id
            ));

            // Send promotion notification
            self::send_promotion_notification($next_volunteer, $opportunity);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("FriendShyft Waitlist: Successfully promoted volunteer {$next_volunteer->volunteer_id} to opportunity $opportunity_id" . ($shift_id ? ", shift $shift_id" : ""));
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("FriendShyft Waitlist: Failed to create signup for promoted volunteer");
            }
        }
    }

    /**
     * Calculate rank score for volunteer
     * Higher score = higher priority
     */
    private static function calculate_rank_score($volunteer_id) {
        global $wpdb;

        $score = 0;

        // Factor 1: Number of completed signups (max 100 points)
        $completed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND status = 'confirmed'",
            $volunteer_id
        ));
        $score += min($completed_count * 5, 100);

        // Factor 2: Hours volunteered (max 100 points)
        $total_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records
             WHERE volunteer_id = %d",
            $volunteer_id
        ));
        $score += min($total_hours * 2, 100);

        // Factor 3: No-show rate (subtract up to 50 points)
        $no_shows = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND status = 'no_show'",
            $volunteer_id
        ));
        $score -= min($no_shows * 10, 50);

        // Factor 4: Badge count (5 points per badge, max 50)
        $badge_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_badges
             WHERE volunteer_id = %d",
            $volunteer_id
        ));
        $score += min($badge_count * 5, 50);

        return max($score, 0); // Don't go negative
    }

    /**
     * Get volunteer's position in waitlist
     */
    private static function get_waitlist_position($volunteer_id, $opportunity_id) {
        global $wpdb;

        $all_waiting = $wpdb->get_results($wpdb->prepare(
            "SELECT id, volunteer_id, rank_score, joined_at
             FROM {$wpdb->prefix}fs_waitlist
             WHERE opportunity_id = %d AND status = 'waiting'
             ORDER BY rank_score DESC, joined_at ASC",
            $opportunity_id
        ));

        $position = 1;
        foreach ($all_waiting as $entry) {
            if ($entry->volunteer_id == $volunteer_id) {
                return $position;
            }
            $position++;
        }

        return $position;
    }

    /**
     * Get waitlist for an opportunity
     */
    public static function get_waitlist($opportunity_id, $shift_id = null) {
        global $wpdb;

        if ($shift_id !== null) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT w.*, v.name, v.email, v.phone
                 FROM {$wpdb->prefix}fs_waitlist w
                 JOIN {$wpdb->prefix}fs_volunteers v ON w.volunteer_id = v.id
                 WHERE w.opportunity_id = %d AND w.shift_id = %d AND w.status = 'waiting'
                 ORDER BY w.rank_score DESC, w.joined_at ASC",
                $opportunity_id,
                $shift_id
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT w.*, v.name, v.email, v.phone
             FROM {$wpdb->prefix}fs_waitlist w
             JOIN {$wpdb->prefix}fs_volunteers v ON w.volunteer_id = v.id
             WHERE w.opportunity_id = %d AND w.shift_id IS NULL AND w.status = 'waiting'
             ORDER BY w.rank_score DESC, w.joined_at ASC",
            $opportunity_id
        ));
    }

    /**
     * Send waitlist confirmation email
     */
    private static function send_waitlist_confirmation($volunteer, $opportunity, $position) {
        $subject = 'You\'re on the waitlist for ' . $opportunity->title;

        $portal_url = add_query_arg(array(
            'token' => $volunteer->access_token,
            'view' => 'schedule'
        ), home_url('/volunteer-portal/'));

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #0073aa;'>You're on the Waitlist!</h2>

                <p>Hi " . esc_html($volunteer->name) . ",</p>

                <p>You've been added to the waitlist for:</p>

                <div style='background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                    <strong>" . esc_html($opportunity->title) . "</strong><br>
                    Date: " . date('F j, Y', strtotime($opportunity->event_date)) . "<br>
                    Location: " . esc_html($opportunity->location) . "
                </div>

                <p><strong>Your position: #{$position}</strong></p>

                <p>If a spot opens up, we'll automatically sign you up and send you a notification!</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . esc_url($portal_url) . "' style='display: inline-block; background: #0073aa; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        View My Schedule
                    </a>
                </div>

                <p style='color: #666; font-size: 14px;'>
                    You can remove yourself from the waitlist anytime from your volunteer portal.
                </p>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($volunteer->email, $subject, $message, $headers);
    }

    /**
     * Send promotion notification
     */
    private static function send_promotion_notification($waitlist_entry, $opportunity) {
        $subject = 'Great news! A spot opened for ' . $opportunity->title;

        $portal_url = add_query_arg(array(
            'token' => $waitlist_entry->access_token,
            'view' => 'schedule'
        ), home_url('/volunteer-portal/'));

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #28a745;'>🎉 You Got the Spot!</h2>

                <p>Hi " . esc_html($waitlist_entry->name) . ",</p>

                <p>Great news! A spot opened up and you've been automatically signed up for:</p>

                <div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 20px 0;'>
                    <strong>" . esc_html($opportunity->title) . "</strong><br>
                    Date: " . date('F j, Y', strtotime($opportunity->event_date)) . "<br>
                    Location: " . esc_html($opportunity->location) . "
                </div>

                <p>We've secured your spot! You're all set to volunteer.</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . esc_url($portal_url) . "' style='display: inline-block; background: #28a745; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        View My Schedule
                    </a>
                </div>

                <p style='color: #666; font-size: 14px;'>
                    If you can no longer make it, please cancel as soon as possible so we can offer the spot to someone else.
                </p>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($waitlist_entry->email, $subject, $message, $headers);
    }

    /**
     * Get volunteer from request (token or logged-in)
     */
    private static function get_volunteer_from_request() {
        global $wpdb;

        // Check token-based auth first
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        if ($token) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
                $token
            ));
        }

        // Check logged-in user
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
