<?php
if (!defined('ABSPATH')) exit;

/**
 * Volunteer Feedback System
 * Post-event surveys, suggestion box, and testimonials collection
 */
class FS_Feedback_System {

    public static function init() {
        add_action('wp_ajax_fs_submit_survey', array(__CLASS__, 'submit_survey'));
        add_action('wp_ajax_nopriv_fs_submit_survey', array(__CLASS__, 'submit_survey'));

        add_action('wp_ajax_fs_submit_suggestion', array(__CLASS__, 'submit_suggestion'));
        add_action('wp_ajax_nopriv_fs_submit_suggestion', array(__CLASS__, 'submit_suggestion'));

        add_action('wp_ajax_fs_submit_testimonial', array(__CLASS__, 'submit_testimonial'));
        add_action('wp_ajax_nopriv_fs_submit_testimonial', array(__CLASS__, 'submit_testimonial'));

        // Send surveys after event completion (runs daily)
        add_action('fs_send_event_surveys_cron', array(__CLASS__, 'send_post_event_surveys'));
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Post-event surveys
        $surveys_table = $wpdb->prefix . 'fs_surveys';
        $sql = "CREATE TABLE IF NOT EXISTS $surveys_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            opportunity_id bigint(20) unsigned NOT NULL,
            rating int NOT NULL,
            enjoyed_most text NULL,
            could_improve text NULL,
            would_recommend varchar(10) NOT NULL,
            additional_comments text NULL,
            submitted_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY opportunity_id (opportunity_id),
            KEY rating (rating),
            KEY submitted_at (submitted_at)
        ) $charset_collate;";

        // Suggestions
        $suggestions_table = $wpdb->prefix . 'fs_suggestions';
        $sql .= "CREATE TABLE IF NOT EXISTS $suggestions_table (
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

        // Suggestion responses (history)
        $suggestion_responses_table = $wpdb->prefix . 'fs_suggestion_responses';
        $sql .= "CREATE TABLE IF NOT EXISTS $suggestion_responses_table (
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

        // Testimonials
        $testimonials_table = $wpdb->prefix . 'fs_testimonials';
        $sql .= "CREATE TABLE IF NOT EXISTS $testimonials_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            testimonial text NOT NULL,
            impact_story text NULL,
            display_name varchar(255) NOT NULL,
            permission_to_publish tinyint(1) NOT NULL DEFAULT 0,
            is_published tinyint(1) NOT NULL DEFAULT 0,
            submitted_at datetime NOT NULL,
            published_at datetime NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY permission_to_publish (permission_to_publish),
            KEY is_published (is_published),
            KEY submitted_at (submitted_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Submit post-event survey
     */
    public static function submit_survey() {
        // Get volunteer
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $opportunity_id = isset($_POST['opportunity_id']) ? intval($_POST['opportunity_id']) : 0;
        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
        $enjoyed_most = isset($_POST['enjoyed_most']) ? sanitize_textarea_field($_POST['enjoyed_most']) : '';
        $could_improve = isset($_POST['could_improve']) ? sanitize_textarea_field($_POST['could_improve']) : '';
        $would_recommend = isset($_POST['would_recommend']) ? sanitize_text_field($_POST['would_recommend']) : '';
        $additional_comments = isset($_POST['additional_comments']) ? sanitize_textarea_field($_POST['additional_comments']) : '';

        // Validate
        if ($rating < 1 || $rating > 5) {
            wp_send_json_error('Please select a rating between 1 and 5');
            return;
        }

        global $wpdb;

        // Check if already submitted
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_surveys WHERE volunteer_id = %d AND opportunity_id = %d",
            $volunteer->id,
            $opportunity_id
        ));

        if ($existing) {
            wp_send_json_error('You have already submitted feedback for this event');
            return;
        }

        // Insert survey
        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_surveys",
            array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id,
                'rating' => $rating,
                'enjoyed_most' => $enjoyed_most,
                'could_improve' => $could_improve,
                'would_recommend' => $would_recommend,
                'additional_comments' => $additional_comments,
                'submitted_at' => current_time('mysql')
            )
        );

        if ($result) {
            FS_Audit_Log::log('survey_submitted', 'survey', $wpdb->insert_id, array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id,
                'rating' => $rating
            ));

            // Notify POC if rating is low (1-2 stars)
            if ($rating <= 2) {
                self::notify_poc_low_rating($volunteer->id, $opportunity_id, $rating);
            }

            wp_send_json_success('Thank you for your feedback!');
        } else {
            wp_send_json_error('Failed to submit survey');
        }
    }

    /**
     * Submit suggestion
     */
    public static function submit_suggestion() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
        $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : '';
        $suggestion = isset($_POST['suggestion']) ? sanitize_textarea_field($_POST['suggestion']) : '';

        // Validate
        if (empty($category) || empty($subject) || empty($suggestion)) {
            wp_send_json_error('All fields are required');
            return;
        }

        global $wpdb;

        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_suggestions",
            array(
                'volunteer_id' => $volunteer->id,
                'category' => $category,
                'subject' => $subject,
                'suggestion' => $suggestion,
                'status' => 'pending',
                'submitted_at' => current_time('mysql')
            )
        );

        if ($result) {
            FS_Audit_Log::log('suggestion_submitted', 'suggestion', $wpdb->insert_id, array(
                'volunteer_id' => $volunteer->id,
                'category' => $category
            ));

            // Notify admin of new suggestion
            self::notify_admin_new_suggestion($volunteer, $category, $subject, $suggestion);

            wp_send_json_success('Thank you for your suggestion! We review all feedback and will consider it carefully.');
        } else {
            wp_send_json_error('Failed to submit suggestion');
        }
    }

    /**
     * Submit testimonial
     */
    public static function submit_testimonial() {
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $testimonial = isset($_POST['testimonial']) ? sanitize_textarea_field($_POST['testimonial']) : '';
        $impact_story = isset($_POST['impact_story']) ? sanitize_textarea_field($_POST['impact_story']) : '';
        $display_name = isset($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : '';
        $permission_to_publish = isset($_POST['permission_to_publish']) ? 1 : 0;

        // Validate
        if (empty($testimonial) || empty($display_name)) {
            wp_send_json_error('Testimonial and display name are required');
            return;
        }

        global $wpdb;

        $result = $wpdb->insert(
            "{$wpdb->prefix}fs_testimonials",
            array(
                'volunteer_id' => $volunteer->id,
                'testimonial' => $testimonial,
                'impact_story' => $impact_story,
                'display_name' => $display_name,
                'permission_to_publish' => $permission_to_publish,
                'is_published' => 0,
                'submitted_at' => current_time('mysql')
            )
        );

        if ($result) {
            FS_Audit_Log::log('testimonial_submitted', 'testimonial', $wpdb->insert_id, array(
                'volunteer_id' => $volunteer->id,
                'permission_to_publish' => $permission_to_publish
            ));

            // Notify admin of new testimonial
            self::notify_admin_new_testimonial($volunteer, $testimonial);

            wp_send_json_success('Thank you for sharing your story! If you gave us permission to publish, we may feature it on our website.');
        } else {
            wp_send_json_error('Failed to submit testimonial');
        }
    }

    /**
     * Send post-event surveys (cron job)
     */
    public static function send_post_event_surveys() {
        global $wpdb;

        // Get opportunities that happened yesterday and haven't been surveyed
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.title as opportunity_title, v.name, v.email, v.access_token
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             WHERE o.event_date = %s
             AND s.status = 'confirmed'
             AND s.volunteer_id NOT IN (
                 SELECT volunteer_id FROM {$wpdb->prefix}fs_surveys WHERE opportunity_id = s.opportunity_id
             )",
            $yesterday
        ));

        foreach ($signups as $signup) {
            self::send_survey_email($signup);
        }
    }

    /**
     * Send survey email to volunteer
     */
    private static function send_survey_email($signup) {
        $survey_url = add_query_arg(array(
            'token' => $signup->access_token,
            'view' => 'feedback',
            'opportunity_id' => $signup->opportunity_id
        ), home_url('/volunteer-portal/'));

        $subject = 'How was your volunteer experience?';

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #0073aa;'>We'd love your feedback!</h2>

                <p>Hi " . esc_html($signup->name) . ",</p>

                <p>Thank you for volunteering for <strong>" . esc_html($signup->opportunity_title) . "</strong> yesterday!</p>

                <p>Your experience matters to us. Would you take a moment to share your thoughts?</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . esc_url($survey_url) . "' style='display: inline-block; background: #0073aa; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        Share Your Feedback
                    </a>
                </div>

                <p style='color: #666; font-size: 14px;'>
                    Your feedback helps us improve the volunteer experience for everyone.
                </p>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($signup->email, $subject, $message, $headers);
    }

    /**
     * Notify POC of low rating
     */
    private static function notify_poc_low_rating($volunteer_id, $opportunity_id, $rating) {
        global $wpdb;

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity || !$opportunity->point_of_contact_id) {
            return;
        }

        $poc = get_userdata($opportunity->point_of_contact_id);
        if (!$poc) {
            return;
        }

        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $subject = 'Low volunteer satisfaction rating received';

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h3>Low Rating Alert</h3>
            <p>A volunteer gave a low satisfaction rating for one of your opportunities:</p>
            <ul>
                <li><strong>Opportunity:</strong> {$opportunity->title}</li>
                <li><strong>Volunteer:</strong> {$volunteer->name}</li>
                <li><strong>Rating:</strong> {$rating}/5 stars</li>
                <li><strong>Date:</strong> {$opportunity->event_date}</li>
            </ul>
            <p>Please consider following up with the volunteer to address any concerns.</p>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($poc->user_email, $subject, $message, $headers);
    }

    /**
     * Notify admin of new suggestion
     */
    private static function notify_admin_new_suggestion($volunteer, $category, $subject, $suggestion) {
        $admin_email = get_option('admin_email');

        $subject_line = 'New volunteer suggestion: ' . $subject;

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h3>New Volunteer Suggestion</h3>
            <p><strong>From:</strong> {$volunteer->name} ({$volunteer->email})</p>
            <p><strong>Category:</strong> {$category}</p>
            <p><strong>Subject:</strong> {$subject}</p>
            <p><strong>Suggestion:</strong></p>
            <blockquote style='background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa;'>
                " . nl2br(esc_html($suggestion)) . "
            </blockquote>
            <p><a href='" . admin_url('admin.php?page=fs-feedback') . "'>View all suggestions in admin panel</a></p>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($admin_email, $subject_line, $message, $headers);
    }

    /**
     * Notify admin of new testimonial
     */
    private static function notify_admin_new_testimonial($volunteer, $testimonial) {
        $admin_email = get_option('admin_email');

        $subject = 'New volunteer testimonial received';

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h3>New Volunteer Testimonial</h3>
            <p><strong>From:</strong> {$volunteer->name}</p>
            <blockquote style='background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa;'>
                " . nl2br(esc_html($testimonial)) . "
            </blockquote>
            <p><a href='" . admin_url('admin.php?page=fs-feedback') . "'>Review and publish testimonials</a></p>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($admin_email, $subject, $message, $headers);
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
