<?php
if (!defined('ABSPATH')) exit;

class FS_Notifications {
    
    public static function init() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Notifications: Initializing');
        }

        // Volunteer registration notifications
        add_action('fs_volunteer_registered', array(__CLASS__, 'send_welcome_email'), 10, 2);
        add_action('fs_volunteer_registered_staff', array(__CLASS__, 'send_staff_notification'));

        // Volunteer signup notifications
        add_action('fs_volunteer_signup', array(__CLASS__, 'send_signup_confirmation'), 10, 3);

        // Step completion notifications
        add_action('fs_step_completed', array(__CLASS__, 'send_step_completion_notification'), 10, 3);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Notifications: All action hooks registered');
        }
    }
    
    public static function send_welcome_email($user_id, $credentials) {
        $user = get_userdata($user_id);

        if (!$user) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft Notifications: User not found: ' . $user_id);
            }
            return;
        }
        
        $to = $user->user_email;
        $subject = 'Welcome to FriendShyft!';
        
        $login_url = wp_login_url();
        $portal_url = home_url('/volunteer-portal');
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>Welcome, {$user->display_name}!</h2>
            <p>Thank you for your interest in volunteering with us!</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='margin-top: 0;'>Your Login Credentials</h3>
                <p><strong>Username:</strong> {$credentials['username']}</p>
                <p><strong>Password:</strong> {$credentials['password']}</p>
                <p style='margin-bottom: 0;'><em>We recommend changing your password after your first login.</em></p>
            </div>
            
            <p>
                <a href='{$login_url}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>Log In Now</a>
            </p>
            
            <p>Once logged in, visit your <a href='{$portal_url}'>volunteer portal</a> to:</p>
            <ul>
                <li>Browse available volunteer opportunities</li>
                <li>Sign up for shifts that work with your schedule</li>
                <li>Track your volunteer hours</li>
                <li>Complete your onboarding steps</li>
            </ul>
            
            <p>We're excited to have you join us!</p>
            
            <p style='margin-top: 30px;'>
                Best regards,<br>
                The Volunteer Team
            </p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log('FriendShyft Notifications: Welcome email sent to ' . $to);
            } else {
                error_log('FriendShyft Notifications: Failed to send welcome email to ' . $to);
            }
        }
    }
    
    public static function send_staff_notification($data) {
        $admin_email = get_option('admin_email');
        
        $subject = 'New Volunteer Registration';
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>New Volunteer Registration</h2>
            <p>A new volunteer has registered:</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p><strong>Name:</strong> {$data['name']}</p>
                <p><strong>Email:</strong> {$data['email']}</p>
                <p><strong>Phone:</strong> {$data['phone']}</p>
        ";
        
        if (!empty($data['interested_programs'])) {
            $message .= "<p><strong>Interested in:</strong> {$data['interested_programs']}</p>";
        }
        
        $message .= "
            </div>
            
            <p>
                <a href='" . admin_url('admin.php?page=fs-volunteers') . "' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>View in Admin</a>
            </p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($admin_email, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log('FriendShyft Notifications: Staff notification sent');
            } else {
                error_log('FriendShyft Notifications: Failed to send staff notification');
            }
        }
    }
    
    /**
     * Send signup confirmation email
     * 
     * @param object $volunteer Volunteer record
     * @param object $opportunity Opportunity record
     * @param object|null $shift Shift record if applicable
     */
    public static function send_signup_confirmation($volunteer, $opportunity, $shift = null) {
        $to = $volunteer->email;
        $subject = 'Volunteer Opportunity Confirmation';
        
        // Format date and time
        $event_date_formatted = date('l, F j, Y', strtotime($opportunity->event_date));
        
        // Determine time display
        if ($shift) {
            $time_display = date('g:i A', strtotime($shift->shift_start_time)) . ' - ' . 
                           date('g:i A', strtotime($shift->shift_end_time));
        } else {
            $time_display = 'All day';
        }
        
        // Build portal link - use token if available, otherwise regular login
        if (!empty($volunteer->access_token)) {
            $portal_url = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal'));
        } else {
            $portal_url = home_url('/volunteer-portal');
        }
        
        // Build calendar export link
        $calendar_url = FS_Calendar_Export::get_export_url($opportunity->id, $shift ? $shift->id : null);
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>Signup Confirmed!</h2>
            <p>Hello {$volunteer->name},</p>
            <p>You have successfully signed up for the following volunteer opportunity:</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0073aa;'>
                <h3 style='margin-top: 0; color: #0073aa;'>{$opportunity->title}</h3>
                <p><strong>📅 Date:</strong> {$event_date_formatted}</p>
                <p><strong>🕐 Time:</strong> {$time_display}</p>
        ";
        
        if ($opportunity->location) {
            $message .= "<p><strong>📍 Location:</strong> " . esc_html($opportunity->location) . "</p>";
        }
        
        if ($opportunity->description) {
            $message .= "<div style='margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;'>";
            $message .= "<p><strong>Details:</strong></p>";
            $message .= "<p>" . nl2br(esc_html($opportunity->description)) . "</p>";
            $message .= "</div>";
        }
        
        $message .= "
            </div>
            
            <p>
                <a href='{$calendar_url}' style='display: inline-block; background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px;'>📅 Add to Calendar</a>
                <a href='{$portal_url}' style='display: inline-block; background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>View My Signups</a>
            </p>
            
            <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                Need to cancel? Visit your <a href='{$portal_url}'>volunteer portal</a> to manage your signups.
            </p>
            
            <p style='margin-top: 30px;'>
                Thank you for volunteering!<br>
                The Volunteer Team
            </p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log("FriendShyft Notifications: Signup confirmation sent to {$to} for opportunity {$opportunity->id}");
            } else {
                error_log("FriendShyft Notifications: Failed to send signup confirmation to {$to}");
            }
        }
    }
    
    /**
     * Send cancellation confirmation email
     * 
     * @param object $volunteer Volunteer record
     * @param object $opportunity Opportunity record
     * @param object|null $shift Shift record if applicable
     */
    public static function send_cancellation_confirmation($volunteer, $opportunity, $shift = null) {
        $to = $volunteer->email;
        $subject = 'Volunteer Signup Cancelled';
        
        // Format date and time
        $event_date_formatted = date('l, F j, Y', strtotime($opportunity->event_date));
        
        // Determine time display
        if ($shift) {
            $time_display = date('g:i A', strtotime($shift->shift_start_time)) . ' - ' . 
                           date('g:i A', strtotime($shift->shift_end_time));
        } else {
            $time_display = 'All day';
        }
        
        // Build portal link
        if (!empty($volunteer->access_token)) {
            $portal_url = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal/opportunities'));
        } else {
            $portal_url = home_url('/volunteer-portal/opportunities');
        }
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #dc3545;'>Signup Cancelled</h2>
            <p>Hello {$volunteer->name},</p>
            <p>Your signup for the following volunteer opportunity has been cancelled:</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                <h3 style='margin-top: 0;'>{$opportunity->title}</h3>
                <p><strong>📅 Date:</strong> {$event_date_formatted}</p>
                <p><strong>🕐 Time:</strong> {$time_display}</p>
        ";
        
        if ($opportunity->location) {
            $message .= "<p><strong>📍 Location:</strong> " . esc_html($opportunity->location) . "</p>";
        }
        
        $message .= "
            </div>
            
            <p>If you cancelled by mistake, you can sign up again if spots are still available.</p>
            
            <p>
                <a href='{$portal_url}' style='display: inline-block; background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>Browse Other Opportunities</a>
            </p>
            
            <p style='margin-top: 30px;'>
                Thank you for your continued support!<br>
                The Volunteer Team
            </p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log("FriendShyft Notifications: Cancellation confirmation sent to {$to}");
            } else {
                error_log("FriendShyft Notifications: Failed to send cancellation confirmation to {$to}");
            }
        }
    }
    
    public static function send_step_completion_notification($volunteer, $workflow_name, $step_name) {
        $to = $volunteer->email;
        $subject = 'Onboarding Step Completed!';
        
        // Build portal link
        if (!empty($volunteer->access_token)) {
            $portal_url = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal'));
        } else {
            $portal_url = home_url('/volunteer-portal');
        }
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #28a745;'>✓ Step Completed!</h2>
            <p>Hello {$volunteer->name},</p>
            <p>Great job! You've completed a step in your volunteer onboarding:</p>
            
            <div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;'>
                <p style='margin: 0;'><strong>Workflow:</strong> {$workflow_name}</p>
                <p style='margin: 10px 0 0 0;'><strong>Step:</strong> {$step_name}</p>
            </div>
            
            <p>Keep up the excellent work! Complete your remaining steps to get started volunteering.</p>
            
            <p>
                <a href='{$portal_url}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>View My Progress</a>
            </p>
            
            <p style='margin-top: 30px;'>
                Best regards,<br>
                The Volunteer Team
            </p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log("FriendShyft Notifications: Step completion sent to {$to}");
            } else {
                error_log("FriendShyft Notifications: Failed to send step completion to {$to}");
            }
        }
    }

    /**
 * Send reminder email 24 hours before opportunity
 * 
 * @param object $volunteer Volunteer record
 * @param object $opportunity Opportunity record
 * @param object|null $shift Shift record if applicable
 */
public static function send_opportunity_reminder($volunteer, $opportunity, $shift = null) {
    $to = $volunteer->email;
    $subject = 'Reminder: Volunteer Shift Tomorrow';
    
    // Format date and time
    $event_date_formatted = date('l, F j, Y', strtotime($opportunity->event_date));
    
    // Determine time display
    if ($shift) {
        $time_display = date('g:i A', strtotime($shift->shift_start_time)) . ' - ' . 
                       date('g:i A', strtotime($shift->shift_end_time));
    } else {
        $time_display = 'All day';
    }
    
    // Build portal link
    if (!empty($volunteer->access_token)) {
        $portal_url = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal'));
    } else {
        $portal_url = home_url('/volunteer-portal');
    }
    
    // Build calendar export link
    $calendar_url = FS_Calendar_Export::get_export_url($opportunity->id, $shift ? $shift->id : null);
    
    $message = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <h2 style='color: #0073aa;'>⏰ Reminder: Your Shift is Tomorrow!</h2>
        <p>Hello {$volunteer->name},</p>
        <p>This is a friendly reminder that you're scheduled to volunteer tomorrow:</p>
        
        <div style='background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;'>
            <h3 style='margin-top: 0; color: #856404;'>{$opportunity->title}</h3>
            <p><strong>📅 Date:</strong> {$event_date_formatted}</p>
            <p><strong>🕐 Time:</strong> {$time_display}</p>
    ";
    
    if ($opportunity->location) {
        $message .= "<p><strong>📍 Location:</strong> " . esc_html($opportunity->location) . "</p>";
    }
    
    if ($opportunity->requirements) {
        $message .= "<div style='margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;'>";
        $message .= "<p><strong>⚠️ Remember:</strong></p>";
        $message .= "<p>" . nl2br(esc_html($opportunity->requirements)) . "</p>";
        $message .= "</div>";
    }
    
    $message .= "
        </div>
        
        <p>
            <a href='{$calendar_url}' style='display: inline-block; background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px;'>📅 Add to Calendar</a>
            <a href='{$portal_url}' style='display: inline-block; background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>View Details</a>
        </p>
        
        <p style='color: #666; font-size: 14px; margin-top: 30px;'>
            Need to cancel? Please visit your <a href='{$portal_url}'>volunteer portal</a> as soon as possible.
        </p>
        
        <p style='margin-top: 30px;'>
            We're looking forward to seeing you!<br>
            The Volunteer Team
        </p>
    </body>
    </html>
    ";
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    $sent = wp_mail($to, $subject, $message, $headers);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        if ($sent) {
            error_log("FriendShyft Notifications: Reminder sent to {$to} for opportunity {$opportunity->id}");
        } else {
            error_log("FriendShyft Notifications: Failed to send reminder to {$to}");
        }
    }
}

    /**
 * Send badge earned notification
 * 
 * @param object $volunteer Volunteer record
 * @param array $badges Array of newly earned badges
 */
public static function send_badge_notification($volunteer, $badges) {
    $to = $volunteer->email;
    $subject = count($badges) > 1 ? 'You Earned New Badges!' : 'You Earned a New Badge!';

    // Build portal link
    if (!empty($volunteer->access_token)) {
        $portal_url = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal'));
    } else {
        $portal_url = home_url('/volunteer-portal');
    }

    $badges_html = '';
    foreach ($badges as $badge) {
        $badges_html .= "
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin: 10px 0; text-align: center;'>
            <div style='font-size: 48px; margin-bottom: 10px;'>{$badge['icon']}</div>
            <h3 style='margin: 0; color: white;'>{$badge['name']}</h3>
        </div>
        ";
    }

    $message = "
    <html>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <h2 style='color: #0073aa;'>🎉 Congratulations, {$volunteer->name}!</h2>
        <p>You've earned " . (count($badges) > 1 ? 'new badges' : 'a new badge') . "!</p>

        {$badges_html}

        <p style='text-align: center; margin-top: 30px;'>
            <a href='{$portal_url}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>View All Your Badges</a>
        </p>

        <p>Keep up the amazing work! Your dedication makes a real difference.</p>

        <p style='margin-top: 30px;'>
            Thank you for volunteering!<br>
            The Volunteer Team
        </p>
    </body>
    </html>
    ";

    $headers = array('Content-Type: text/html; charset=UTF-8');

    $sent = wp_mail($to, $subject, $message, $headers);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        if ($sent) {
            error_log("FriendShyft Notifications: Badge notification sent to {$to}");
        } else {
            error_log("FriendShyft Notifications: Failed to send badge notification to {$to}");
        }
    }
}

    /**
     * Send notification when individual signup is merged into team
     *
     * @param object $volunteer Volunteer record
     * @param object $opportunity Opportunity record
     * @param int $team_signup_id Team signup ID
     */
    public static function send_team_merge_notification($volunteer, $opportunity, $team_signup_id) {
        $to = $volunteer->email;
        $subject = 'Your Signup Has Been Updated - Team Assignment';

        // Format date
        $event_date_formatted = date('l, F j, Y', strtotime($opportunity->event_date));

        // Build portal link
        if (!empty($volunteer->access_token)) {
            $portal_url = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal'));
        } else {
            $portal_url = home_url('/volunteer-portal');
        }

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>Your Signup Has Been Updated</h2>
            <p>Hello {$volunteer->name},</p>
            <p>Great news! Your individual signup has been merged into a team signup for better coordination.</p>

            <div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0073aa;'>
                <h3 style='margin-top: 0; color: #0073aa;'>{$opportunity->title}</h3>
                <p><strong>📅 Date:</strong> {$event_date_formatted}</p>
        ";

        if ($opportunity->location) {
            $message .= "<p><strong>📍 Location:</strong> " . esc_html($opportunity->location) . "</p>";
        }

        $message .= "
                <div style='background: white; padding: 15px; border-radius: 4px; margin-top: 15px;'>
                    <p style='margin: 0; color: #0073aa;'><strong>ℹ️ What This Means:</strong></p>
                    <p style='margin: 10px 0 0 0;'>You're still confirmed for this opportunity! Your signup has been coordinated with other volunteers as part of a team. No action is needed from you.</p>
                </div>
            </div>

            <p>
                <a href='{$portal_url}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>View My Signups</a>
            </p>

            <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                If you have any questions about this change, please contact us.
            </p>

            <p style='margin-top: 30px;'>
                Thank you for volunteering!<br>
                The Volunteer Team
            </p>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');

        $sent = wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log("FriendShyft Notifications: Team merge notification sent to {$to} for team signup {$team_signup_id}");
            } else {
                error_log("FriendShyft Notifications: Failed to send team merge notification to {$to}");
            }
        }
    }

    /**
     * Send notification to Point of Contact when volunteer signs up
     *
     * @param object $volunteer Volunteer record
     * @param object $opportunity Opportunity record
     * @param object|null $shift Shift record if applicable
     */
    public static function send_poc_signup_notification($volunteer, $opportunity, $shift = null) {
        // Get POC user
        if (empty($opportunity->point_of_contact_id)) {
            return; // No POC assigned
        }

        $poc_user = get_userdata($opportunity->point_of_contact_id);
        if (!$poc_user) {
            return;
        }

        $to = $poc_user->user_email;
        $subject = 'New Volunteer Signup: ' . $opportunity->title;

        // Format date and time
        $event_date_formatted = date('l, F j, Y', strtotime($opportunity->event_date));

        // Determine time display
        if ($shift) {
            $time_display = date('g:i A', strtotime($shift->shift_start_time)) . ' - ' .
                           date('g:i A', strtotime($shift->shift_end_time));
        } else {
            $time_display = 'All day';
        }

        // Build admin link
        $admin_url = admin_url('admin.php?page=fs-manage-signups&opportunity_id=' . $opportunity->id);

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>New Volunteer Signup</h2>
            <p>Hello {$poc_user->display_name},</p>
            <p>A volunteer has signed up for an opportunity you're managing:</p>

            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0073aa;'>
                <h3 style='margin-top: 0;'>{$opportunity->title}</h3>
                <p><strong>Volunteer:</strong> {$volunteer->name}</p>
                <p><strong>Email:</strong> {$volunteer->email}</p>
        ";

        if (!empty($volunteer->phone)) {
            $message .= "<p><strong>Phone:</strong> {$volunteer->phone}</p>";
        }

        $message .= "
                <p style='margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;'>
                    <strong>📅 Date:</strong> {$event_date_formatted}<br>
                    <strong>🕐 Time:</strong> {$time_display}
                </p>
            </div>

            <p>
                <a href='{$admin_url}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>View All Signups</a>
            </p>

            <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                You're receiving this notification because you're the Point of Contact for this opportunity.
            </p>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');

        $sent = wp_mail($to, $subject, $message, $headers);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($sent) {
                error_log("FriendShyft Notifications: POC notification sent to {$to} for opportunity {$opportunity->id}");
            } else {
                error_log("FriendShyft Notifications: Failed to send POC notification to {$to}");
            }
        }
    }

    /**
     * Teen email when at least one session is held as pending.
     */
    public static function send_teen_registration_received_email($volunteer, $event_group, $held_sessions, $waitlisted_sessions, $permission_triggered) {
        $to = $volunteer->email;
        $subject = 'Registration received (pending approval)';

        $held_lines = '';
        foreach ((array) $held_sessions as $session) {
            $held_lines .= '<li>' . esc_html(self::format_session_for_email($session)) . '</li>';
        }

        $wait_lines = '';
        foreach ((array) $waitlisted_sessions as $session) {
            $wait_lines .= '<li>' . esc_html(self::format_session_for_email($session)) . '</li>';
        }

        $message = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
            <h2 style='color:#0073aa;'>Registration Received</h2>
            <p>Hello " . esc_html($volunteer->name) . ",</p>
            <p>We received your registration for <strong>" . esc_html($event_group->title) . "</strong>.</p>
            <p><strong>Held Sessions (pending staff approval):</strong></p>
            <ul>{$held_lines}</ul>
        ";

        if (!empty($waitlisted_sessions)) {
            $message .= "<p><strong>Waitlisted Sessions:</strong></p><ul>{$wait_lines}</ul>";
        }

        if ($permission_triggered) {
            $message .= "<p>A parent/guardian permission email has been sent and must be completed before confirmation.</p>";
        }

        $message .= "<p>Thank you for volunteering.</p></body></html>";
        wp_mail($to, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Teen email for waitlist-only submissions.
     */
    public static function send_teen_waitlist_only_email($volunteer, $event_group, $waitlisted_sessions) {
        $to = $volunteer->email;
        $subject = 'Waitlist request received';

        $wait_lines = '';
        foreach ((array) $waitlisted_sessions as $session) {
            $wait_lines .= '<li>' . esc_html(self::format_session_for_email($session)) . '</li>';
        }

        $message = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
            <h2 style='color:#0073aa;'>Waitlist Request Received</h2>
            <p>Hello " . esc_html($volunteer->name) . ",</p>
            <p>You're currently on the waitlist for <strong>" . esc_html($event_group->title) . "</strong>:</p>
            <ul>{$wait_lines}</ul>
            <p>No permission form is requested yet because no spot is currently held. If a spot opens, we will reach out and permission will be required then.</p>
        </body></html>";
        wp_mail($to, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Guardian initial permission request email.
     */
    public static function send_guardian_permission_request($registration, $signer_url, $expires_mysql) {
        self::send_guardian_permission_message(
            $registration,
            $signer_url,
            $expires_mysql,
            'Permission form required for teen volunteer registration',
            'A permission form is required for this registration.'
        );
    }

    /**
     * Guardian 24-hour reminder.
     */
    public static function send_guardian_permission_24h_reminder($registration, $signer_url, $expires_mysql) {
        self::send_guardian_permission_message(
            $registration,
            $signer_url,
            $expires_mysql,
            'Reminder: permission form needed within 24 hours',
            'This is a reminder that permission is still needed.'
        );
    }

    /**
     * Guardian final reminder.
     */
    public static function send_guardian_permission_final_reminder($registration, $signer_url, $expires_mysql) {
        self::send_guardian_permission_message(
            $registration,
            $signer_url,
            $expires_mysql,
            'Final reminder: permission form needed soon',
            'Final reminder: permission is needed soon to keep this registration active.'
        );
    }

    /**
     * Staff notification when permission is signed.
     */
    public static function send_staff_permission_signed_notification($registration) {
        $settings = get_option('fs_teen_permission_settings', array());
        $emails_csv = isset($settings['staff_notification_emails']) ? $settings['staff_notification_emails'] : get_option('admin_email');
        $emails = array_filter(array_map('trim', explode(',', (string) $emails_csv)));
        if (empty($emails)) {
            return;
        }

        $subject = 'Permission signed: ' . ($registration->teen_name ?? 'Teen') . ' for ' . ($registration->event_group_title ?? 'Event');
        $details_url = admin_url('admin.php?page=fs-teen-registrations&registration_id=' . (int) $registration->id);
        $message = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
            <h2 style='color:#0073aa;'>Permission Signed</h2>
            <p><strong>Teen:</strong> " . esc_html($registration->teen_name ?? '') . "</p>
            <p><strong>Event Group:</strong> " . esc_html($registration->event_group_title ?? '') . "</p>
            <p><a href='" . esc_url($details_url) . "'>Open registration in admin</a></p>
            <p>Ready for review and approval.</p>
        </body></html>";

        wp_mail($emails, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Staff notification when manual permission fallback is required.
     */
    public static function send_staff_manual_permission_required_notification($registration, $reason_code = '') {
        $settings = get_option('fs_teen_permission_settings', array());
        $emails_csv = isset($settings['staff_notification_emails']) ? $settings['staff_notification_emails'] : get_option('admin_email');
        $emails = array_filter(array_map('trim', explode(',', (string) $emails_csv)));
        if (empty($emails)) {
            return;
        }

        $subject = 'Manual guardian permission required: ' . ($registration->teen_name ?? 'Teen');
        $details_url = admin_url('admin.php?page=fs-teen-registrations&registration_id=' . (int) $registration->id);
        $reason_line = !empty($reason_code) ? '<p><strong>Reason:</strong> ' . esc_html($reason_code) . '</p>' : '';
        $expires_line = !empty($registration->permission_expires_at)
            ? '<p><strong>Expires:</strong> ' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($registration->permission_expires_at))) . '</p>'
            : '';

        $message = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
            <h2 style='color:#0073aa;'>Manual Permission Action Needed</h2>
            <p><strong>Teen:</strong> " . esc_html($registration->teen_name ?? '') . "</p>
            <p><strong>Event Group:</strong> " . esc_html($registration->event_group_title ?? '') . "</p>
            {$reason_line}
            {$expires_line}
            <p>Add the third-party signer link and send the guardian request from the registration details page.</p>
            <p><a href='" . esc_url($details_url) . "'>Open registration in admin</a></p>
        </body></html>";

        wp_mail($emails, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Shared guardian email builder.
     */
    private static function send_guardian_permission_message($registration, $signer_url, $expires_mysql, $subject, $lead_copy) {
        if (empty($registration->guardian_email) || empty($signer_url)) {
            return;
        }

        $settings = get_option('fs_teen_permission_settings', array());
        $help_contact = !empty($settings['help_contact_line']) ? $settings['help_contact_line'] : '';
        $deadline = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($expires_mysql));

        $message = "
        <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
            <h2 style='color:#0073aa;'>" . esc_html($subject) . "</h2>
            <p>{$lead_copy}</p>
            <p><strong>Teen:</strong> " . esc_html($registration->teen_name ?? '') . "<br>
               <strong>Event:</strong> " . esc_html($registration->event_group_title ?? '') . "</p>
            <p><a href='" . esc_url($signer_url) . "' style='display:inline-block;background:#0073aa;color:#fff;padding:10px 16px;text-decoration:none;border-radius:4px;'>Open Permission Form</a></p>
            <p><strong>Deadline:</strong> " . esc_html($deadline) . "</p>
            " . (!empty($help_contact) ? '<p>If you need help, contact ' . esc_html($help_contact) . '.</p>' : '') . "
        </body></html>";

        wp_mail($registration->guardian_email, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Session label formatter for teen emails.
     */
    private static function format_session_for_email($session) {
        $title = isset($session['title']) ? $session['title'] : 'Session';
        $start = !empty($session['datetime_start']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($session['datetime_start'])) : '';
        $end = !empty($session['datetime_end']) ? date_i18n(get_option('time_format'), strtotime($session['datetime_end'])) : '';

        $parts = array_filter(array($title, $start, $end));
        return implode(' - ', $parts);
    }
}

FS_Notifications::init();
