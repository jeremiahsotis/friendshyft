<?php
if (!defined('ABSPATH')) exit;

/**
 * Volunteer Retention Analytics
 * Engagement scoring, at-risk identification, automated re-engagement
 */
class FS_Volunteer_Retention {

    public static function init() {
        // Schedule daily engagement score updates
        add_action('fs_update_engagement_scores_cron', array(__CLASS__, 'update_all_engagement_scores'));

        // Schedule weekly re-engagement campaigns
        add_action('fs_send_reengagement_campaigns_cron', array(__CLASS__, 'send_reengagement_campaigns'));

        // AJAX handlers for admin dashboard
        add_action('wp_ajax_fs_get_at_risk_volunteers', array(__CLASS__, 'ajax_get_at_risk_volunteers'));
        add_action('wp_ajax_fs_send_manual_reengagement', array(__CLASS__, 'ajax_send_manual_reengagement'));
        add_action('wp_ajax_fs_get_engagement_trends', array(__CLASS__, 'ajax_get_engagement_trends'));
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Engagement scores table (historical tracking)
        $engagement_table = $wpdb->prefix . 'fs_engagement_scores';
        $sql = "CREATE TABLE IF NOT EXISTS $engagement_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            score int NOT NULL DEFAULT 0,
            trend varchar(20) NOT NULL DEFAULT 'stable',
            risk_level varchar(20) NOT NULL DEFAULT 'low',
            last_activity_date datetime NULL,
            days_inactive int NOT NULL DEFAULT 0,
            total_hours decimal(10,2) NOT NULL DEFAULT 0,
            signups_last_30_days int NOT NULL DEFAULT 0,
            signups_last_90_days int NOT NULL DEFAULT 0,
            no_show_rate decimal(5,2) NOT NULL DEFAULT 0,
            calculated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY risk_level (risk_level),
            KEY calculated_at (calculated_at),
            KEY score (score)
        ) $charset_collate;";

        // Re-engagement campaigns log
        $campaigns_table = $wpdb->prefix . 'fs_reengagement_campaigns';
        $sql2 = "CREATE TABLE IF NOT EXISTS $campaigns_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            campaign_type varchar(50) NOT NULL,
            sent_at datetime NOT NULL,
            opened_at datetime NULL,
            clicked_at datetime NULL,
            converted_at datetime NULL,
            conversion_type varchar(50) NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY campaign_type (campaign_type),
            KEY sent_at (sent_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
    }

    /**
     * Calculate engagement score for a volunteer
     * Score range: 0-100
     */
    public static function calculate_engagement_score($volunteer_id) {
        global $wpdb;

        $score = 0;
        $factors = array();

        // Factor 1: Recent activity (0-30 points)
        $last_signup = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(s.signup_date)
             FROM {$wpdb->prefix}fs_signups s
             WHERE s.volunteer_id = %d",
            $volunteer_id
        ));

        if ($last_signup) {
            $days_since = (strtotime('now') - strtotime($last_signup)) / 86400;

            if ($days_since <= 7) {
                $activity_points = 30;
            } elseif ($days_since <= 30) {
                $activity_points = 25;
            } elseif ($days_since <= 60) {
                $activity_points = 15;
            } elseif ($days_since <= 90) {
                $activity_points = 10;
            } else {
                $activity_points = 0;
            }

            $score += $activity_points;
            $factors['recent_activity'] = array(
                'points' => $activity_points,
                'days_since_last' => round($days_since)
            );
        }

        // Factor 2: Signup frequency (0-25 points)
        $signups_30 = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d
             AND signup_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $volunteer_id
        ));

        $signups_90 = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d
             AND signup_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            $volunteer_id
        ));

        $frequency_points = min($signups_30 * 5 + $signups_90 * 2, 25);
        $score += $frequency_points;
        $factors['signup_frequency'] = array(
            'points' => $frequency_points,
            'last_30_days' => $signups_30,
            'last_90_days' => $signups_90
        );

        // Factor 3: Total hours contributed (0-20 points)
        $total_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(hours), 0)
             FROM {$wpdb->prefix}fs_time_records
             WHERE volunteer_id = %d",
            $volunteer_id
        ));

        $hours_points = min($total_hours / 5, 20);
        $score += $hours_points;
        $factors['total_hours'] = array(
            'points' => $hours_points,
            'hours' => round($total_hours, 1)
        );

        // Factor 4: Reliability (0-15 points) - inverse of no-show rate
        $total_signups = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d",
            $volunteer_id
        ));

        $no_shows = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d
             AND status = 'no_show'",
            $volunteer_id
        ));

        $no_show_rate = $total_signups > 0 ? ($no_shows / $total_signups) : 0;
        $reliability_points = round(15 * (1 - $no_show_rate));
        $score += $reliability_points;
        $factors['reliability'] = array(
            'points' => $reliability_points,
            'no_show_rate' => round($no_show_rate * 100, 1)
        );

        // Factor 5: Badge achievements (0-10 points)
        $badge_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fs_badges
             WHERE volunteer_id = %d",
            $volunteer_id
        ));

        $badge_points = min($badge_count * 2, 10);
        $score += $badge_points;
        $factors['achievements'] = array(
            'points' => $badge_points,
            'badges' => $badge_count
        );

        return array(
            'score' => round($score),
            'factors' => $factors,
            'last_activity' => $last_signup,
            'days_inactive' => isset($days_since) ? round($days_since) : null,
            'no_show_rate' => round($no_show_rate * 100, 1),
            'signups_30' => $signups_30,
            'signups_90' => $signups_90,
            'total_hours' => round($total_hours, 1)
        );
    }

    /**
     * Determine risk level based on engagement score and trends
     */
    private static function determine_risk_level($score, $previous_score = null) {
        // Calculate trend
        $trend = 'stable';
        if ($previous_score !== null) {
            $change = $score - $previous_score;
            if ($change >= 10) {
                $trend = 'improving';
            } elseif ($change <= -10) {
                $trend = 'declining';
            }
        }

        // Determine risk level
        if ($score >= 70) {
            $risk_level = 'low';
        } elseif ($score >= 40) {
            $risk_level = $trend === 'declining' ? 'medium' : 'low';
        } elseif ($score >= 20) {
            $risk_level = 'medium';
        } else {
            $risk_level = 'high';
        }

        // Override: declining trend + low score = high risk
        if ($trend === 'declining' && $score < 30) {
            $risk_level = 'high';
        }

        return array(
            'risk_level' => $risk_level,
            'trend' => $trend
        );
    }

    /**
     * Update engagement scores for all volunteers
     */
    public static function update_all_engagement_scores() {
        global $wpdb;

        // Get all active volunteers
        $volunteers = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}fs_volunteers
             WHERE volunteer_status = 'active'"
        );

        foreach ($volunteers as $volunteer) {
            self::update_engagement_score($volunteer->id);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Updated engagement scores for ' . count($volunteers) . ' volunteers');
        }
    }

    /**
     * Update engagement score for a single volunteer
     */
    public static function update_engagement_score($volunteer_id) {
        global $wpdb;

        // Get previous score
        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT score FROM {$wpdb->prefix}fs_engagement_scores
             WHERE volunteer_id = %d
             ORDER BY calculated_at DESC
             LIMIT 1",
            $volunteer_id
        ));

        $previous_score = $previous ? $previous->score : null;

        // Calculate new score
        $engagement = self::calculate_engagement_score($volunteer_id);
        $risk_data = self::determine_risk_level($engagement['score'], $previous_score);

        // Insert new score record
        $wpdb->insert(
            "{$wpdb->prefix}fs_engagement_scores",
            array(
                'volunteer_id' => $volunteer_id,
                'score' => $engagement['score'],
                'trend' => $risk_data['trend'],
                'risk_level' => $risk_data['risk_level'],
                'last_activity_date' => $engagement['last_activity'],
                'days_inactive' => $engagement['days_inactive'],
                'total_hours' => $engagement['total_hours'],
                'signups_last_30_days' => $engagement['signups_30'],
                'signups_last_90_days' => $engagement['signups_90'],
                'no_show_rate' => $engagement['no_show_rate'],
                'calculated_at' => current_time('mysql')
            )
        );

        return $engagement['score'];
    }

    /**
     * Get at-risk volunteers
     */
    public static function get_at_risk_volunteers($risk_level = 'high', $limit = 50) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.name, v.email, v.phone,
                    e.score, e.trend, e.risk_level, e.days_inactive,
                    e.last_activity_date, e.total_hours,
                    e.signups_last_30_days, e.signups_last_90_days
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN (
                 SELECT e1.*
                 FROM {$wpdb->prefix}fs_engagement_scores e1
                 INNER JOIN (
                     SELECT volunteer_id, MAX(calculated_at) as max_date
                     FROM {$wpdb->prefix}fs_engagement_scores
                     GROUP BY volunteer_id
                 ) e2 ON e1.volunteer_id = e2.volunteer_id
                     AND e1.calculated_at = e2.max_date
             ) e ON v.id = e.volunteer_id
             WHERE e.risk_level = %s
             AND v.volunteer_status = 'active'
             ORDER BY e.score ASC, e.days_inactive DESC
             LIMIT %d",
            $risk_level,
            $limit
        ));
    }

    /**
     * Send re-engagement campaigns to at-risk volunteers
     */
    public static function send_reengagement_campaigns() {
        global $wpdb;

        // Get high-risk volunteers who haven't received a campaign in the last 14 days
        $at_risk = $wpdb->get_results(
            "SELECT v.id, v.name, v.email, v.access_token,
                    e.score, e.days_inactive, e.last_activity_date
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN (
                 SELECT e1.*
                 FROM {$wpdb->prefix}fs_engagement_scores e1
                 INNER JOIN (
                     SELECT volunteer_id, MAX(calculated_at) as max_date
                     FROM {$wpdb->prefix}fs_engagement_scores
                     GROUP BY volunteer_id
                 ) e2 ON e1.volunteer_id = e2.volunteer_id
                     AND e1.calculated_at = e2.max_date
             ) e ON v.id = e.volunteer_id
             WHERE e.risk_level = 'high'
             AND v.volunteer_status = 'active'
             AND v.id NOT IN (
                 SELECT volunteer_id
                 FROM {$wpdb->prefix}fs_reengagement_campaigns
                 WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             )
             LIMIT 25"
        );

        $sent_count = 0;

        foreach ($at_risk as $volunteer) {
            // Determine campaign type based on inactivity
            if ($volunteer->days_inactive >= 90) {
                $campaign_type = 'long_term_inactive';
            } elseif ($volunteer->days_inactive >= 30) {
                $campaign_type = 'we_miss_you';
            } else {
                $campaign_type = 'low_engagement';
            }

            // Send re-engagement email
            $result = self::send_reengagement_email($volunteer, $campaign_type);

            if ($result) {
                // Log campaign
                $wpdb->insert(
                    "{$wpdb->prefix}fs_reengagement_campaigns",
                    array(
                        'volunteer_id' => $volunteer->id,
                        'campaign_type' => $campaign_type,
                        'sent_at' => current_time('mysql')
                    )
                );

                FS_Audit_Log::log('reengagement_sent', 'volunteer', $volunteer->id, array(
                    'campaign_type' => $campaign_type,
                    'days_inactive' => $volunteer->days_inactive,
                    'score' => $volunteer->score
                ));

                $sent_count++;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Sent $sent_count re-engagement campaigns");
        }

        return $sent_count;
    }

    /**
     * Send re-engagement email
     */
    private static function send_reengagement_email($volunteer, $campaign_type) {
        $portal_url = add_query_arg(array(
            'token' => $volunteer->access_token,
            'utm_source' => 'reengagement',
            'utm_campaign' => $campaign_type
        ), home_url('/volunteer-portal/'));

        // Campaign-specific messaging
        switch ($campaign_type) {
            case 'long_term_inactive':
                $subject = 'We Miss You! Come Back to Volunteering';
                $headline = 'We Haven\'t Seen You in a While!';
                $message = "It's been over 3 months since your last volunteer shift, and we truly miss having you on our team.";
                $cta = 'Explore New Opportunities';
                break;

            case 'we_miss_you':
                $subject = 'We Miss Your Impact!';
                $headline = 'Your Community Needs You';
                $message = "It's been a while since your last shift. We'd love to have you back making a difference!";
                $cta = 'See Available Shifts';
                break;

            default: // low_engagement
                $subject = 'New Volunteer Opportunities Await!';
                $headline = 'Ready for Your Next Shift?';
                $message = "We have exciting new volunteer opportunities that match your interests.";
                $cta = 'View Opportunities';
                break;
        }

        // Get upcoming opportunities that might interest them
        global $wpdb;
        $opportunities = $wpdb->get_results($wpdb->prepare(
            "SELECT o.id, o.title, o.event_date, o.location, o.spots_available, o.spots_filled
             FROM {$wpdb->prefix}fs_opportunities o
             WHERE o.event_date >= CURDATE()
             AND o.spots_filled < o.spots_available
             AND o.status = 'published'
             AND (o.program_id IN (
                 SELECT DISTINCT op2.program_id
                 FROM {$wpdb->prefix}fs_signups s2
                 JOIN {$wpdb->prefix}fs_opportunities op2 ON s2.opportunity_id = op2.id
                 WHERE s2.volunteer_id = %d
             ) OR 1=1)
             ORDER BY o.event_date ASC
             LIMIT 3",
            $volunteer->id
        ));

        $opportunities_html = '';
        foreach ($opportunities as $opp) {
            $spots_left = $opp->spots_available - $opp->spots_filled;
            $opportunities_html .= "
                <div style='background: #f9f9f9; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa;'>
                    <strong style='color: #0073aa;'>" . esc_html($opp->title) . "</strong><br>
                    <span style='color: #666;'>
                        📅 " . date('l, F j, Y', strtotime($opp->event_date)) . "<br>
                        📍 " . esc_html($opp->location) . "<br>
                        👥 " . $spots_left . " spot" . ($spots_left != 1 ? 's' : '') . " available
                    </span>
                </div>";
        }

        $email_body = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #0073aa;'>$headline</h2>

                <p>Hi " . esc_html($volunteer->name) . ",</p>

                <p>$message</p>

                <p>Your volunteer work makes a real difference in our community, and we'd be thrilled to see you back in action.</p>

                " . ($opportunities_html ? "
                <h3 style='color: #0073aa; margin-top: 30px;'>Upcoming Opportunities:</h3>
                $opportunities_html
                " : "") . "

                <div style='text-align: center; margin: 40px 0;'>
                    <a href='" . esc_url($portal_url) . "' style='display: inline-block; background: #0073aa; color: white; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;'>
                        $cta
                    </a>
                </div>

                <p style='color: #666; font-size: 14px;'>
                    If you have any questions or need help getting back involved, just reply to this email. We're here to help!
                </p>

                <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                    Thank you for being part of our volunteer community.<br>
                    Together, we're making a difference!
                </p>
            </div>
        </body>
        </html>";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($volunteer->email, $subject, $email_body, $headers);
    }

    /**
     * Get engagement trends over time for a volunteer
     */
    public static function get_engagement_trends($volunteer_id, $days = 90) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT score, trend, risk_level, days_inactive,
                    signups_last_30_days, calculated_at
             FROM {$wpdb->prefix}fs_engagement_scores
             WHERE volunteer_id = %d
             AND calculated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY calculated_at ASC",
            $volunteer_id,
            $days
        ));
    }

    /**
     * Get engagement statistics summary
     */
    public static function get_engagement_statistics() {
        global $wpdb;

        // Get most recent scores for all volunteers
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(DISTINCT e.volunteer_id) as total_volunteers,
                SUM(CASE WHEN e.risk_level = 'high' THEN 1 ELSE 0 END) as high_risk,
                SUM(CASE WHEN e.risk_level = 'medium' THEN 1 ELSE 0 END) as medium_risk,
                SUM(CASE WHEN e.risk_level = 'low' THEN 1 ELSE 0 END) as low_risk,
                SUM(CASE WHEN e.trend = 'improving' THEN 1 ELSE 0 END) as improving,
                SUM(CASE WHEN e.trend = 'declining' THEN 1 ELSE 0 END) as declining,
                SUM(CASE WHEN e.trend = 'stable' THEN 1 ELSE 0 END) as stable,
                AVG(e.score) as avg_score
             FROM {$wpdb->prefix}fs_engagement_scores e
             INNER JOIN (
                 SELECT volunteer_id, MAX(calculated_at) as max_date
                 FROM {$wpdb->prefix}fs_engagement_scores
                 GROUP BY volunteer_id
             ) e2 ON e.volunteer_id = e2.volunteer_id
                 AND e.calculated_at = e2.max_date"
        );

        return $stats;
    }

    /**
     * AJAX: Get at-risk volunteers
     */
    public static function ajax_get_at_risk_volunteers() {
        check_ajax_referer('friendshyft_admin_nonce', 'nonce');

        if (!current_user_can('manage_friendshyft')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $risk_level = isset($_POST['risk_level']) ? sanitize_text_field($_POST['risk_level']) : 'high';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;

        $volunteers = self::get_at_risk_volunteers($risk_level, $limit);

        wp_send_json_success($volunteers);
    }

    /**
     * AJAX: Send manual re-engagement
     */
    public static function ajax_send_manual_reengagement() {
        check_ajax_referer('friendshyft_admin_nonce', 'nonce');

        if (!current_user_can('manage_friendshyft')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $volunteer_id = isset($_POST['volunteer_id']) ? intval($_POST['volunteer_id']) : 0;

        global $wpdb;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT v.*, e.days_inactive
             FROM {$wpdb->prefix}fs_volunteers v
             LEFT JOIN (
                 SELECT e1.*
                 FROM {$wpdb->prefix}fs_engagement_scores e1
                 INNER JOIN (
                     SELECT volunteer_id, MAX(calculated_at) as max_date
                     FROM {$wpdb->prefix}fs_engagement_scores
                     GROUP BY volunteer_id
                 ) e2 ON e1.volunteer_id = e2.volunteer_id
                     AND e1.calculated_at = e2.max_date
             ) e ON v.id = e.volunteer_id
             WHERE v.id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            wp_send_json_error('Volunteer not found');
            return;
        }

        // Determine campaign type
        $campaign_type = $volunteer->days_inactive >= 90
            ? 'long_term_inactive'
            : ($volunteer->days_inactive >= 30 ? 'we_miss_you' : 'low_engagement');

        $result = self::send_reengagement_email($volunteer, $campaign_type);

        if ($result) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_reengagement_campaigns",
                array(
                    'volunteer_id' => $volunteer_id,
                    'campaign_type' => $campaign_type,
                    'sent_at' => current_time('mysql')
                )
            );

            wp_send_json_success('Re-engagement email sent successfully');
        } else {
            wp_send_json_error('Failed to send email');
        }
    }

    /**
     * AJAX: Get engagement trends
     */
    public static function ajax_get_engagement_trends() {
        check_ajax_referer('friendshyft_admin_nonce', 'nonce');

        if (!current_user_can('manage_friendshyft')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $volunteer_id = isset($_POST['volunteer_id']) ? intval($_POST['volunteer_id']) : 0;
        $days = isset($_POST['days']) ? intval($_POST['days']) : 90;

        $trends = self::get_engagement_trends($volunteer_id, $days);

        wp_send_json_success($trends);
    }
}
