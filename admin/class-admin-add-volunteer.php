<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Add_Volunteer {
    
    public static function init() {
        // Add menu page
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 25);
        
        // Handle form submission
        add_action('admin_post_fs_create_volunteer', array(__CLASS__, 'handle_form_submission'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Add Volunteer',
            'Add Volunteer',
            'manage_options',
            'fs-add-volunteer-manual',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function handle_form_submission() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: Manual volunteer creation started');
        }
        
        if (!isset($_POST['fs_create_volunteer'])) {
            return;
        }
        
        if (!check_admin_referer('fs_create_volunteer_form')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        // Validate input
        $name = sanitize_text_field(wp_unslash($_POST['name']));
        $email = sanitize_email(wp_unslash($_POST['email']));
        $phone = sanitize_text_field(wp_unslash($_POST['phone']));
        $birthdate = !empty($_POST['birthdate']) ? sanitize_text_field(wp_unslash($_POST['birthdate'])) : null;
        $volunteer_status = sanitize_text_field(wp_unslash($_POST['volunteer_status']));
        $create_wp_user = isset($_POST['create_wp_user']);
        $send_notification = isset($_POST['send_notification']);
        
        // Validate required fields
        if (empty($name) || empty($email)) {
            wp_die('Name and email are required');
        }
        
        if (!is_email($email)) {
            wp_die('Invalid email address');
        }
        
        // Check if email already exists in volunteers
        $existing_volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE email = %s",
            $email
        ));
        
        if ($existing_volunteer) {
            wp_die('A volunteer with this email already exists');
        }
        
        // Generate access token
        $access_token = self::generate_access_token();
        
        // Insert volunteer
        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_volunteers',
            array(
                'name' => $name,
                'email' => $email,
                'access_token' => $access_token,
                'phone' => $phone,
                'birthdate' => $birthdate,
                'volunteer_status' => $volunteer_status,
                'created_date' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Failed to create volunteer - ' . $wpdb->last_error);
            }
            wp_die('Failed to create volunteer: ' . $wpdb->last_error);
        }
        
        $volunteer_id = $wpdb->insert_id;
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: Volunteer created with ID: ' . $volunteer_id);
        }
        
        // Create WordPress user if requested
        $wp_user_id = null;
        $password = null;
        if ($create_wp_user) {
            // Check if user already exists
            $existing_user = get_user_by('email', $email);
            
            if ($existing_user) {
                $wp_user_id = $existing_user->ID;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: WordPress user already exists - ID: ' . $wp_user_id);
                }
            } else {
                // Generate username and password
                $username = self::generate_username($email);
                $password = wp_generate_password(12, false);
                
                $wp_user_id = wp_create_user($username, $password, $email);
                
                if (is_wp_error($wp_user_id)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('FriendShyft: Failed to create WordPress user - ' . $wp_user_id->get_error_message());
                    }
                } else {
                    // Set user role
                    $user = new WP_User($wp_user_id);
                    $user->set_role('subscriber');
                    
                    // Update display name
                    wp_update_user(array(
                        'ID' => $wp_user_id,
                        'display_name' => $name
                    ));
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('FriendShyft: WordPress user created - ID: ' . $wp_user_id);
                    }
                }
            }
        }
        
        // Send notification email if requested
        if ($send_notification) {
            self::send_welcome_email($volunteer_id, $name, $email, $access_token, $password);
        }
        
        // Redirect with success message
        $redirect = add_query_arg(
            array('page' => 'fs-volunteers', 'created' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function render_page() {
        global $wpdb;
        
        ?>
        <div class="wrap">
            <h1>Add New Volunteer</h1>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="volunteer-form">
                <?php wp_nonce_field('fs_create_volunteer_form'); ?>
                <input type="hidden" name="action" value="fs_create_volunteer">
                
                <table class="form-table">
                    <tr>
                        <th><label for="name">Full Name *</label></th>
                        <td>
                            <input type="text" id="name" name="name" class="regular-text" required>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="email">Email Address *</label></th>
                        <td>
                            <input type="email" id="email" name="email" class="regular-text" required>
                            <p class="description">Must be unique. Used for login and notifications.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="phone">Phone Number</label></th>
                        <td>
                            <input type="tel" id="phone" name="phone" class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="birthdate">Birthdate</label></th>
                        <td>
                            <input type="date" id="birthdate" name="birthdate">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="volunteer_status">Status *</label></th>
                        <td>
                            <select id="volunteer_status" name="volunteer_status" required>
                                <option value="Active">Active</option>
                                <option value="Pending">Pending</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th>Options</th>
                        <td>
                            <label>
                                <input type="checkbox" name="create_wp_user" value="1" checked>
                                Create WordPress user account (allows traditional login)
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="send_notification" value="1" checked>
                                Send welcome email with access information
                            </label>
                        </td>
                    </tr>
                </table>
                
                <div class="notice notice-info inline">
                    <p><strong>Access Methods:</strong></p>
                    <ul>
                        <li><strong>Magic Link:</strong> A unique secure link will be automatically generated for easy access (no login required)</li>
                        <li><strong>Traditional Login:</strong> If "Create WordPress user account" is checked, they can also log in with username/password</li>
                    </ul>
                </div>
                
                <p class="submit">
                    <input type="submit" name="fs_create_volunteer" class="button button-primary" value="Create Volunteer">
                    <a href="<?php echo admin_url('admin.php?page=fs-volunteers'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        
        <style>
            .volunteer-form {
                max-width: 800px;
            }
            .notice.inline {
                margin: 20px 0;
                padding: 12px;
            }
            .notice.inline ul {
                margin: 10px 0 0 20px;
            }
        </style>
        <?php
    }
    
    private static function generate_access_token() {
        return bin2hex(random_bytes(32));
    }
    
    private static function generate_username($email) {
        $username = sanitize_user(current(explode('@', $email)), true);
        
        // Ensure username is unique
        if (username_exists($username)) {
            $username .= '_' . wp_rand(100, 999);
        }
        
        return $username;
    }
    
    private static function send_welcome_email($volunteer_id, $name, $email, $access_token, $password = null) {
        $portal_url = home_url('/volunteer-portal/');
        $magic_link = add_query_arg('token', $access_token, $portal_url);
        
        $subject = 'Welcome to Our Volunteer Program!';
        
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>Welcome, {$name}!</h2>
            <p>You've been added to our volunteer program. We're excited to have you on board!</p>
            
            <div style='background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='margin-top: 0;'>Your Access Information</h3>
                <p><strong>Option 1: Magic Link (Recommended)</strong></p>
                <p>Click this link anytime to access your volunteer portal - no login required:</p>
                <p><a href='{$magic_link}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600;'>Access Your Portal</a></p>
                <p style='font-size: 12px; color: #666;'>Bookmark this link for easy access!</p>
        ";
        
        if ($password) {
            $username = self::generate_username($email);
            $message .= "
                <hr style='margin: 20px 0; border: none; border-top: 1px solid #ddd;'>
                <p><strong>Option 2: Traditional Login</strong></p>
                <p><strong>Portal:</strong> <a href='{$portal_url}'>{$portal_url}</a><br>
                <strong>Username:</strong> {$username}<br>
                <strong>Password:</strong> {$password}</p>
            ";
        }
        
        $message .= "
            </div>
            
            <h3>Next Steps:</h3>
            <ol>
                <li>Access your volunteer portal using either method above</li>
                <li>Browse available volunteer opportunities</li>
                <li>Sign up for shifts that work for your schedule</li>
            </ol>
            
            <p>If you have any questions, don't hesitate to reach out!</p>
            
            <p>Thank you for serving with us!</p>
        </body>
        </html>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($email, $subject, $message, $headers);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: Welcome email sent to ' . $email);
        }
    }
}

FS_Admin_Add_Volunteer::init();
