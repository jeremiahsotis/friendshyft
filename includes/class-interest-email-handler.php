<?php
if (!defined('ABSPATH')) exit;

/**
 * Interest Form Email Handler
 * Handles email logic for interest form submissions based on preferred contact method
 */
class FS_Interest_Email_Handler {

    /**
     * Process interest form submission and send appropriate emails
     *
     * @param int $volunteer_id Volunteer ID
     * @param array $programs Array of program IDs selected
     * @param array $availability_days Array of available days
     * @param array $availability_times Array of available times
     * @param string $preferred_contact 'email' or 'phone'
     */
    public static function process_submission($volunteer_id, $programs, $availability_days, $availability_times, $preferred_contact) {
        global $wpdb;

        // Get volunteer data
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            return false;
        }

        // Get program details
        $program_details = self::get_program_details($programs);

        // Get POC emails
        $poc_emails = self::get_poc_emails($programs);

        if ($preferred_contact === 'phone') {
            // Phone preference: Only notify POCs/admin
            self::send_phone_preference_notification($volunteer, $program_details, $poc_emails);
        } else {
            // Email preference: Send welcome email + POC notification
            self::send_welcome_email($volunteer, $program_details, $availability_days, $availability_times);
            self::send_email_preference_notification($volunteer, $program_details, $poc_emails);
        }

        return true;
    }

    /**
     * Get program details with email information
     */
    private static function get_program_details($program_ids) {
        global $wpdb;

        if (empty($program_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($program_ids), '%d'));
        $query = "SELECT * FROM {$wpdb->prefix}fs_programs
                  WHERE id IN ($placeholders)
                  ORDER BY display_order ASC";

        return $wpdb->get_results($wpdb->prepare($query, $program_ids));
    }

    /**
     * Get POC email addresses for selected programs
     * Traverses: Program → Roles → Opportunities → POC emails
     */
    private static function get_poc_emails($program_ids) {
        global $wpdb;

        if (empty($program_ids)) {
            return array();
        }

        $poc_emails = array();

        foreach ($program_ids as $program_id) {
            // Get roles for this program
            $roles = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_roles WHERE program_id = %d",
                $program_id
            ));

            foreach ($roles as $role) {
                // Get opportunities for this role
                $opportunities = $wpdb->get_results($wpdb->prepare(
                    "SELECT point_of_contact_id FROM {$wpdb->prefix}fs_opportunities
                     WHERE role_id = %d AND point_of_contact_id IS NOT NULL",
                    $role->id
                ));

                foreach ($opportunities as $opp) {
                    // Get POC email
                    $poc = $wpdb->get_row($wpdb->prepare(
                        "SELECT email FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                        $opp->point_of_contact_id
                    ));

                    if ($poc && !empty($poc->email)) {
                        $poc_emails[] = $poc->email;
                    }
                }
            }
        }

        // Remove duplicates and return
        return array_unique($poc_emails);
    }

    /**
     * Send notification when volunteer prefers phone contact
     */
    private static function send_phone_preference_notification($volunteer, $programs, $poc_emails) {
        $to = !empty($poc_emails) ? implode(', ', $poc_emails) : get_option('admin_email');
        $subject = 'New Volunteer Interest - Phone Contact Preferred';

        $program_names = array_map(function($p) { return $p->name; }, $programs);

        $message = "A new volunteer has submitted an interest form and prefers to be contacted by phone.\n\n";
        $message .= "Volunteer Details:\n";
        $message .= "Name: {$volunteer->name}\n";
        $message .= "Email: {$volunteer->email}\n";
        $message .= "Phone: {$volunteer->phone}\n";
        $message .= "Birthdate: {$volunteer->birthdate}\n\n";
        $message .= "Programs of Interest:\n";
        $message .= "- " . implode("\n- ", $program_names) . "\n\n";
        $message .= "Please contact this volunteer by phone to discuss volunteer opportunities.\n\n";
        $message .= "View volunteer profile: " . admin_url('admin.php?page=fs-volunteers');

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $headers[] = 'From: FriendShyft <jeremiah@svdpsfw.org>';

        wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Sent phone preference notification to ' . $to);
        }
    }

    /**
     * Send notification when volunteer prefers email contact
     */
    private static function send_email_preference_notification($volunteer, $programs, $poc_emails) {
        $to = !empty($poc_emails) ? implode(', ', $poc_emails) : get_option('admin_email');
        $subject = 'New Volunteer Interest - Email Communication Initiated';

        $program_names = array_map(function($p) { return $p->name; }, $programs);

        $message = "A new volunteer has submitted an interest form and prefers email contact.\n\n";
        $message .= "A welcome email with program information has been automatically sent to the volunteer.\n\n";
        $message .= "Volunteer Details:\n";
        $message .= "Name: {$volunteer->name}\n";
        $message .= "Email: {$volunteer->email}\n";
        $message .= "Phone: {$volunteer->phone}\n";
        $message .= "Birthdate: {$volunteer->birthdate}\n\n";
        $message .= "Programs of Interest:\n";
        $message .= "- " . implode("\n- ", $program_names) . "\n\n";
        $message .= "The volunteer can now respond to the welcome email or access their volunteer portal.\n\n";
        $message .= "View volunteer profile: " . admin_url('admin.php?page=fs-volunteers');

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $headers[] = 'From: FriendShyft <jeremiah@svdpsfw.org>';

        wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Sent email preference notification to ' . $to);
        }
    }

    /**
     * Send welcome email to volunteer with program details and availability matching
     */
    private static function send_welcome_email($volunteer, $programs, $availability_days, $availability_times) {
        $to = $volunteer->email;
        $subject = 'Welcome to St. Vincent de Paul Volunteer Program!';

        // Build opening based on number of programs
        $opening = self::build_opening($programs, $volunteer);

        // Build program details section with availability matching
        $program_details = self::build_program_details($programs, $availability_days, $availability_times);

        // Build closing
        $closing = "Please feel free to respond to this email with any questions or let me know when you'd like to schedule your first shift!\n\n";
        $closing .= "Looking forward to working with you!\n\n";
        $closing .= "Jeremiah Otis\n";
        $closing .= "Volunteer Coordinator\n";
        $closing .= "St. Vincent de Paul Society of Fort Worth";

        $message = $opening . "\n\n" . $program_details . "\n\n" . $closing;

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        $headers[] = 'From: Jeremiah Otis <jeremiah@svdpsfw.org>';
        $headers[] = 'Reply-To: jeremiah@svdpsfw.org';

        wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Sent welcome email to ' . $volunteer->email);
        }
    }

    /**
     * Build opening paragraph based on number of programs
     */
    private static function build_opening($programs, $volunteer) {
        $portal_url = home_url('/volunteer-portal/?token=' . $volunteer->access_token);
        $program_count = count($programs);

        $opening = "Thank you for your interest in volunteering with St. Vincent de Paul! I've activated your personal volunteer portal on our website where you can track your hours and stay updated on opportunities:\n\n";
        $opening .= $portal_url . "\n\n";
        $opening .= "Feel free to bookmark that page for easy access.\n\n";

        if ($program_count === 1) {
            // Single program - no additional text
            return $opening;
        } elseif ($program_count === 2) {
            $prog_names = array_map(function($p) { return $p->name; }, $programs);
            $opening .= "I appreciate your interest in both {$prog_names[0]} and our {$prog_names[1]}!";
            return $opening;
        } else {
            // 3+ programs
            $opening .= "I love your enthusiasm for getting involved in so many different areas!";
            return $opening;
        }
    }

    /**
     * Build program details section with availability matching
     */
    private static function build_program_details($programs, $availability_days, $availability_times) {
        $details = "Here's information about the programs you're interested in:\n\n";

        foreach ($programs as $program) {
            $details .= "**{$program->name}**\n";

            // Add schedule info
            if (!empty($program->schedule_days) && !empty($program->schedule_times)) {
                $schedule = $program->schedule_days . ", " . $program->schedule_times;
                $details .= $schedule . "\n";
            }

            // Add email description
            if (!empty($program->email_description)) {
                $details .= $program->email_description . "\n";
            }

            // Add availability match
            $match_text = self::get_availability_match_text($program, $availability_days, $availability_times);
            if (!empty($match_text)) {
                $details .= $match_text . "\n";
            }

            $details .= "\n";
        }

        return $details;
    }

    /**
     * Get availability match text based on program schedule vs volunteer availability
     */
    private static function get_availability_match_text($program, $volunteer_days, $volunteer_times) {
        if (empty($program->schedule_days) || empty($program->schedule_times)) {
            return '';
        }

        if (empty($volunteer_days) || empty($volunteer_times)) {
            return '';
        }

        // Parse program schedule
        $program_days = array_map('trim', explode(',', $program->schedule_days));
        $program_times_str = strtolower($program->schedule_times);

        // Determine if program operates in morning/afternoon
        $program_morning = (strpos($program_times_str, 'morning') !== false) ||
                          (preg_match('/\d+:\d+\s*am/i', $program_times_str));
        $program_afternoon = (strpos($program_times_str, 'afternoon') !== false) ||
                            (strpos($program_times_str, 'pm') !== false &&
                             !preg_match('/\d+:\d+\s*pm\s*-\s*\d+:\d+\s*am/i', $program_times_str));

        // Check day overlap
        $day_overlap = count(array_intersect($program_days, $volunteer_days));
        $total_program_days = count($program_days);

        // Check time overlap
        $time_match = false;
        if (in_array('Morning', $volunteer_times) && $program_morning) {
            $time_match = true;
        }
        if (in_array('Afternoon', $volunteer_times) && $program_afternoon) {
            $time_match = true;
        }

        // Determine match level
        if ($day_overlap === $total_program_days && $time_match) {
            // Perfect match
            return "This fits your availability perfectly and is one of our biggest needs right now.";
        } elseif ($day_overlap > 0 && $time_match) {
            // Partial day match but time works
            return "This might work with your schedule.";
        } elseif ($day_overlap === $total_program_days && !$time_match) {
            // Days match but times don't
            return "This might not fit your timeframe availability, but we can discuss flexible options.";
        } elseif (!$time_match) {
            // Times don't match
            $vol_time_str = implode(' and ', $volunteer_times);
            return "This might not fit your {$vol_time_str} availability, but we can discuss flexible options.";
        } else {
            // Some overlap
            return "This might work with your schedule.";
        }
    }
}
