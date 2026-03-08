<?php
if (!defined('ABSPATH')) exit;

class FS_Volunteer_Registration {
    
    public static function init() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Registration: File loaded at ' . date('H:i:s'));
        }
        
        add_shortcode('volunteer_interest_form', array(__CLASS__, 'interest_form_shortcode'));
        add_action('wp_ajax_nopriv_fs_submit_interest', array(__CLASS__, 'handle_interest_form'));
        add_action('wp_ajax_fs_submit_interest', array(__CLASS__, 'handle_interest_form'));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Registration: init() called');
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Registration: AJAX handlers registered');
        }
    }
    
    public static function interest_form_shortcode($atts) {
        global $wpdb;
        
        // Parse shortcode attributes
        $atts = shortcode_atts(array(
            'program_id' => '', // Specific program to pre-select
            'show_other_programs' => 'yes', // Show other programs checkbox
            'show_availability' => 'no' // Show time availability options
        ), $atts);
        
        // Get programs for checkboxes
        $programs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_programs 
            WHERE active_status = 'Active' 
            ORDER BY display_order ASC, name ASC"
        );
        
        // If specific program requested, find it
        $selected_program = null;

        // Check for program in URL parameter first, then shortcode attribute
        $program_id = !empty($_GET['program']) ? intval($_GET['program']) : (!empty($atts['program_id']) ? $atts['program_id'] : '');

        if (!empty($program_id)) {
            foreach ($programs as $prog) {
                if ($prog->id == $program_id) {
                    $selected_program = $prog;
                    break;
                }
            }
        }
    
        ob_start();
        ?>
        <div class="fs-interest-form-container">
            <form id="fs-volunteer-interest-form" class="fs-interest-form">
                <div class="fs-form-section">
                    <h3>Your Information</h3>
                
                    <div class="fs-form-group">
                        <label for="fs-name">Full Name *</label>
                        <input type="text" id="fs-name" name="name" required>
                    </div>
                
                    <div class="fs-form-group">
                        <label for="fs-email">Email Address *</label>
                        <input type="email" id="fs-email" name="email" required>
                    </div>
                
                    <div class="fs-form-group">
                        <label for="fs-phone">Phone Number *</label>
                        <input type="tel" id="fs-phone" name="phone" required>
                    </div>
                
                    <div class="fs-form-group">
                        <label for="fs-birthdate">Birthdate</label>
                        <input type="date" id="fs-birthdate" name="birthdate">
                    </div>
                </div>
            
                <?php if (!empty($programs)): ?>
                <div class="fs-form-section">
                    <h3>Areas of Interest</h3>
                    <?php if ($selected_program): ?>
                        <p class="fs-form-description">You're interested in <strong><?php echo esc_html($selected_program->name); ?></strong>. <?php echo esc_html($selected_program->short_description); ?></p>
                    <?php else: ?>
                        <p class="fs-form-description">Select the programs you'd like to learn more about:</p>
                    <?php endif; ?>
                
                    <div class="fs-programs-grid">
                        <?php if ($selected_program): ?>
                            <!-- Pre-selected program -->
                            <input type="hidden" name="programs[]" value="<?php echo esc_attr($selected_program->id); ?>">
                        
                            <?php if ($atts['show_other_programs'] === 'yes'): ?>
                                <div class="fs-program-option fs-other-programs">
                                    <label>
                                        <input type="checkbox" name="show_other_programs" id="show-other-checkbox">
                                        <span class="fs-program-name">I'm also interested in other volunteer opportunities</span>
                                    </label>
                                </div>
                            
                                <div id="other-programs-list" style="display: none;">
                                    <p style="margin: 15px 0 10px; font-weight: 600;">Other Programs:</p>
                                    <?php foreach ($programs as $program): ?>
                                        <?php if ($program->id != $selected_program->id): ?>
                                            <div class="fs-program-option">
                                                <label>
                                                    <input type="checkbox" name="additional_programs[]" value="<?php echo esc_attr($program->id); ?>">
                                                    <span class="fs-program-name"><?php echo esc_html($program->name); ?></span>
                                                    <?php if ($program->short_description): ?>
                                                        <span class="fs-program-desc"><?php echo esc_html($program->short_description); ?></span>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- All programs -->
                            <?php foreach ($programs as $program): ?>
                                <div class="fs-program-option">
                                    <label>
                                        <input type="checkbox" name="programs[]" value="<?php echo esc_attr($program->id); ?>">
                                        <span class="fs-program-name"><?php echo esc_html($program->name); ?></span>
                                        <?php if ($program->short_description): ?>
                                            <span class="fs-program-desc"><?php echo esc_html($program->short_description); ?></span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            
                <?php if ($atts['show_availability'] === 'yes'): ?>
                <div class="fs-form-section">
                    <h3>Your Availability</h3>
                    <p class="fs-form-description">When are you generally available to volunteer?</p>

                    <div class="fs-availability-options">
                        <div style="margin-bottom: 15px;">
                            <strong>Days Available:</strong><br>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_days[]" value="Monday"> Monday</label>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_days[]" value="Tuesday"> Tuesday</label>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_days[]" value="Wednesday"> Wednesday</label>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_days[]" value="Thursday"> Thursday</label>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_days[]" value="Friday"> Friday</label>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_days[]" value="Saturday"> Saturday</label>
                        </div>

                        <div>
                            <strong>Times Available:</strong><br>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_times[]" value="Morning"> Morning</label>
                            <label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="availability_times[]" value="Afternoon"> Afternoon</label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="fs-form-section">
                    <h3>Preferred Contact Method</h3>
                    <p class="fs-form-description">How would you prefer we reach out to you?</p>

                    <div class="fs-contact-preference">
                        <label style="display: block; margin-bottom: 10px;">
                            <input type="radio" name="preferred_contact" value="email" checked>
                            <strong>Email</strong> - Receive information and opportunities via email
                        </label>
                        <label style="display: block;">
                            <input type="radio" name="preferred_contact" value="phone">
                            <strong>Phone Call</strong> - Prefer to be contacted by phone
                        </label>
                    </div>
                </div>

                <div class="fs-form-actions">
                    <button type="submit" class="fs-submit-btn">Submit Interest</button>
                </div>
            
                <div id="fs-form-message" class="fs-form-message" style="display: none;"></div>
            </form>
        </div>
    
        <style>
            .fs-interest-form-container {
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
            }
            .fs-interest-form {
                background: #ffffff;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .fs-form-section {
                margin-bottom: 30px;
            }
            .fs-form-section h3 {
                margin-top: 0;
                margin-bottom: 20px;
                color: #333;
                font-size: 1.3em;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }    
            .fs-form-description {
                color: #666;
                margin-bottom: 15px;
            }
            .fs-form-group {
                margin-bottom: 20px;
            }
            .fs-form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
                color: #333;
            }
            .fs-form-group input[type="text"],
            .fs-form-group input[type="email"],
            .fs-form-group input[type="tel"],
            .fs-form-group input[type="date"] {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
            }
            .fs-form-group input:focus {
                outline: none;
                border-color: #0073aa;
                box-shadow: 0 0 0 3px rgba(0, 115, 170, 0.1);
            }    
            .fs-programs-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 15px;
            }
            .fs-program-option {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 4px;
                border: 2px solid transparent;
                transition: all 0.3s;
            }
            .fs-program-option:hover {
                border-color: #0073aa;
                background: #e7f3ff;
            }
            .fs-program-option label {
                cursor: pointer;
                display: block;    
            }
            .fs-program-option input[type="checkbox"] {
                margin-right: 10px;
            }
            .fs-program-name {
                display: block;
                font-weight: 600;
                color: #333;
                margin-bottom: 5px;
            }
            .fs-program-desc {
                display: block;
                font-size: 14px;
                color: #666;
                line-height: 1.4;
            }
            .fs-other-programs {
                grid-column: 1 / -1;
                background: #fff9e6;
                border-color: #ffc107;
            }
            #other-programs-list {
                grid-column: 1 / -1;
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }
            .fs-availability-options {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 15px;
            }
            .fs-availability-options label {
                display: flex;
                align-items: center;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
                cursor: pointer;
            }
            .fs-availability-options input {
                margin-right: 10px;
            }
            .fs-form-actions {
                text-align: center;
                margin-top: 30px;
            }
            .fs-submit-btn {
                background: #0073aa;
                color: white;
                border: none;
                padding: 15px 40px;
                font-size: 18px;
                font-weight: 600;
                border-radius: 4px;
                cursor: pointer;
                transition: background 0.3s;
            }
            .fs-submit-btn:hover {
                background: #005a87;
            }
            .fs-submit-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            .fs-form-message {
                margin-top: 20px;
                padding: 15px;
                border-radius: 4px;
                text-align: center;
            }
            .fs-form-message.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .fs-form-message.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
        </style>
    
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var form = document.getElementById('fs-volunteer-interest-form');
                if (!form) return;
  
                // Toggle other programs visibility
                var showOtherCheckbox = document.getElementById('show-other-checkbox');
                if (showOtherCheckbox) {
                    showOtherCheckbox.addEventListener('change', function() {
                        document.getElementById('other-programs-list').style.display = this.checked ? 'grid' : 'none';
                    });
                }
        
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
        
                    var button = form.querySelector('.fs-submit-btn');
                    var message = document.getElementById('fs-form-message');
        
                    button.disabled = true;
                    button.textContent = 'Submitting...';
                    message.style.display = 'none';
        
                    var formData = new FormData();
                    formData.append('action', 'fs_submit_interest');
                    formData.append('name', document.getElementById('fs-name').value);
                    formData.append('email', document.getElementById('fs-email').value);
                    formData.append('phone', document.getElementById('fs-phone').value);
                    formData.append('birthdate', document.getElementById('fs-birthdate').value);
        
                    // Programs
                    var programCheckboxes = form.querySelectorAll('input[name="programs[]"]:checked, input[name="additional_programs[]"]:checked');
                    programCheckboxes.forEach(function(checkbox) {
                        formData.append('programs[]', checkbox.value);
                    });
                    
                    // Availability - Days
                    var availabilityDays = form.querySelectorAll('input[name="availability_days[]"]:checked');
                    availabilityDays.forEach(function(checkbox) {
                        formData.append('availability_days[]', checkbox.value);
                    });

                    // Availability - Times
                    var availabilityTimes = form.querySelectorAll('input[name="availability_times[]"]:checked');
                    availabilityTimes.forEach(function(checkbox) {
                        formData.append('availability_times[]', checkbox.value);
                    });

                    // Preferred Contact Method
                    var preferredContact = form.querySelector('input[name="preferred_contact"]:checked');
                    if (preferredContact) {
                        formData.append('preferred_contact', preferredContact.value);
                    }

                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            message.className = 'fs-form-message success';
                            message.textContent = data.data.message;
                            message.style.display = 'block';
                            form.reset();
                        } else {
                            message.className = 'fs-form-message error';
                            message.textContent = data.data.message || 'An error occurred. Please try again.';
                            message.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        message.className = 'fs-form-message error';
                        message.textContent = 'Connection error. Please try again.';
                        message.style.display = 'block';
                    })
                    .finally(function() {
                        button.disabled = false;
                        button.textContent = 'Submit Interest';
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }
    
    public static function handle_interest_form() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Registration: AJAX handler called');
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Registration: POST data: ' . print_r($_POST, true));
        }
    
        try {
            global $wpdb;
        
            // Validate input
            $name = sanitize_text_field($_POST['name'] ?? '');
            $email = sanitize_email($_POST['email'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $birthdate = !empty($_POST['birthdate']) ? sanitize_text_field($_POST['birthdate']) : null;
            $programs = isset($_POST['programs']) ? array_map('intval', $_POST['programs']) : array();
            $availability_days = isset($_POST['availability_days']) ? array_map('sanitize_text_field', $_POST['availability_days']) : array();
            $availability_times = isset($_POST['availability_times']) ? array_map('sanitize_text_field', $_POST['availability_times']) : array();
            $preferred_contact = sanitize_text_field($_POST['preferred_contact'] ?? 'email');
        
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Validated data - Name: ' . $name . ', Email: ' . $email . ', Programs: ' . count($programs));
            }
        
            // Validate required fields
            if (empty($name) || empty($email) || empty($phone)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Missing required fields');
                }
                wp_send_json_error(array(
                    'message' => 'Please fill in all required fields (name, email, and phone).'
                ));
                return;
            }
        
            if (!is_email($email)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Invalid email format');
                }
                wp_send_json_error(array(
                    'message' => 'Please provide a valid email address.'
                ));
                return;
            }
        
            // Check if email already exists
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, volunteer_status FROM {$wpdb->prefix}fs_volunteers WHERE email = %s",
                $email
            ));
        
            if ($existing) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Email already exists - ID: ' . $existing->id);
                }
            
                if ($existing->volunteer_status === 'Active') {
                    wp_send_json_success(array(
                        'message' => 'You\'re already an active volunteer! Check your email for portal login information.'
                    ));
                } else {
                    wp_send_json_success(array(
                        'message' => 'We already have your information on file. We\'ll be in touch soon!'
                    ));
                }
                return;
            }
            
            // Prepare availability string (combine days and times)
            $availability_combined = array();
            if (!empty($availability_days)) {
                $availability_combined[] = 'Days: ' . implode(', ', $availability_days);
            }
            if (!empty($availability_times)) {
                $availability_combined[] = 'Times: ' . implode(', ', $availability_times);
            }
            $availability_string = !empty($availability_combined) ? implode(' | ', $availability_combined) : '';

            // Generate access token for volunteer portal
            $access_token = bin2hex(random_bytes(32));

            // Insert volunteer
            $insert_result = $wpdb->insert(
                $wpdb->prefix . 'fs_volunteers',
                array(
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'birthdate' => $birthdate,
                    'volunteer_status' => 'Pending',
                    'availability' => $availability_string,
                    'preferred_contact' => $preferred_contact,
                    'access_token' => $access_token,
                    'created_date' => current_time('mysql', false)
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        
            if ($insert_result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Database insert failed - Error: ' . $wpdb->last_error);
                }
                wp_send_json_error(array(
                    'message' => 'An error occurred while saving your information. Please try again.'
                ));
                return;
            }
        
            $volunteer_id = $wpdb->insert_id;
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Volunteer created with ID: ' . $volunteer_id);
            }
        
            // Link volunteer to selected programs
            if (!empty($programs)) {
                foreach ($programs as $program_id) {
                    // Get or create a role for this program
                    $role = $wpdb->get_row($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}fs_roles WHERE program_id = %d LIMIT 1",
                        $program_id
                    ));
                
                    if ($role) {
                        // Link volunteer to role
                        $wpdb->insert(
                            $wpdb->prefix . 'fs_volunteer_roles',
                            array(
                                'volunteer_id' => $volunteer_id,
                                'role_id' => $role->id,
                                'assigned_date' => current_time('mysql', false)
                            ),
                            array('%d', '%d', '%s')
                        );
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('FriendShyft: Linked volunteer to role ID: ' . $role->id);
                        }
                    }
                }
            }
        
            // Send emails based on preferred contact method
            FS_Interest_Email_Handler::process_submission(
                $volunteer_id,
                $programs,
                $availability_days,
                $availability_times,
                $preferred_contact
            );
        
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Success response being sent');
            }
            wp_send_json_success(array(
                'message' => 'Thank you for your interest! We\'ll be in touch soon with next steps.'
            ));
            
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Exception caught - ' . $e->getMessage());
            }
            wp_send_json_error(array(
                'message' => 'An unexpected error occurred. Please try again.'
            ));
        }
    }

    /**
     * Send notification email when someone submits interest
     */
    private static function send_interest_notification($volunteer_id, $name, $email, $programs, $availability) {
        global $wpdb;
    
        // Get program names
        $program_names = array();
        if (!empty($programs)) {
            foreach ($programs as $program_id) {
                $program = $wpdb->get_row($wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}fs_programs WHERE id = %d",
                    $program_id
                ));
                if ($program) {
                    $program_names[] = $program->name;
                }
            }
        }
    
        $programs_list = !empty($program_names) ? implode(', ', $program_names) : 'General volunteering';
        $availability_list = !empty($availability) ? implode(', ', $availability) : 'Not specified';
    
        // Email to volunteer
        $subject = 'Thank You For Your Interest in Volunteering!';
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <h2 style='color: #0073aa;'>Thank You, {$name}!</h2>
            <p>We've received your volunteer interest form and are excited that you want to join us!</p>
        
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='margin-top: 0;'>Your Information</h3>
                <p><strong>Programs of Interest:</strong> {$programs_list}</p>
                <p><strong>Availability:</strong> {$availability_list}</p>
            </div>    
        
            <h3>What's Next?</h3>
            <p>A member of our volunteer coordination team will review your information and reach out to you within 2-3 business days with:</p>
            <ul>
                <li>Information about the programs you're interested in</li>
                <li>Next steps in the volunteer onboarding process</li>
                <li>Answers to any questions you might have</li>
            </ul>
        
            <p>If you have any immediate questions, feel free to reply to this email.</p>
        
            <p style='margin-top: 30px;'>Thank you for choosing to serve with us!</p>
        </body>
        </html>
        ";
    
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($email, $subject, $message, $headers);
    
        // Email to staff (optional - get admin email)
        $admin_email = get_option('admin_email');
        $staff_subject = 'New Volunteer Interest Form Submission';
        $staff_message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>New Volunteer Interest</h2>
            <p><strong>Name:</strong> {$name}</p>
            <p><strong>Email:</strong> {$email}</p>
            <p><strong>Programs:</strong> {$programs_list}</p>
            <p><strong>Availability:</strong> {$availability_list}</p>
            <p><a href='" . admin_url('admin.php?page=fs-volunteer-detail&id=' . $volunteer_id) . "'>View in Admin</a></p>
        </body>
        </html>
        ";
    
        wp_mail($admin_email, $staff_subject, $staff_message, $headers);
    
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: Notification emails sent');
        }
    }
}

FS_Volunteer_Registration::init();
