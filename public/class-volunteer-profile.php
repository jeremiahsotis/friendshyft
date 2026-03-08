<?php
if (!defined('ABSPATH')) exit;

class FS_Volunteer_Profile {
    
    public static function init() {
        add_action('wp_ajax_fs_update_profile', array(__CLASS__, 'ajax_update_profile'));
        add_action('wp_ajax_nopriv_fs_update_profile', array(__CLASS__, 'ajax_update_profile'));
        add_action('wp_ajax_fs_confirm_attendance', array(__CLASS__, 'ajax_confirm_attendance'));
        add_action('wp_ajax_nopriv_fs_confirm_attendance', array(__CLASS__, 'ajax_confirm_attendance'));
        add_action('wp_ajax_fs_cancel_attendance', array(__CLASS__, 'ajax_cancel_attendance'));
        add_action('wp_ajax_nopriv_fs_cancel_attendance', array(__CLASS__, 'ajax_cancel_attendance'));

        // Register REST API endpoint for token-based profile updates
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
    }

    /**
     * Register REST API routes
     */
    public static function register_rest_routes() {
        register_rest_route('friendshyft/v1', '/profile/update', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_update_profile'),
            'permission_callback' => '__return_true' // We'll handle auth in the callback
        ));

        register_rest_route('friendshyft/v1', '/confirm-attendance', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_confirm_attendance'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('friendshyft/v1', '/cancel-attendance', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rest_cancel_attendance'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * REST API handler for profile updates (bypasses admin-ajax.php)
     */
    public static function rest_update_profile($request) {
        return self::ajax_update_profile();
    }

    /**
     * REST API handler for confirm attendance
     */
    public static function rest_confirm_attendance($request) {
        $_POST = $request->get_params();
        return self::ajax_confirm_attendance();
    }

    /**
     * REST API handler for cancel attendance
     */
    public static function rest_cancel_attendance($request) {
        $_POST = $request->get_params();
        return self::ajax_cancel_attendance();
    }
    
    /**
     * Render the profile view
     */
    public static function render($volunteer, $portal_url) {
        global $wpdb;
        
        // Get volunteer's roles
        $roles = $wpdb->get_results($wpdb->prepare(
            "SELECT r.name, p.name as program_name
             FROM {$wpdb->prefix}fs_volunteer_roles vr
             JOIN {$wpdb->prefix}fs_roles r ON vr.role_id = r.id
             LEFT JOIN {$wpdb->prefix}fs_programs p ON r.program_id = p.id
             WHERE vr.volunteer_id = %d
             ORDER BY p.name, r.name",
            $volunteer->id
        ));
        
        // Check profile completion
        $required_fields = array(
            'emergency_contact_name',
            'emergency_contact_phone'
        );
        
        $missing_fields = array();
        foreach ($required_fields as $field) {
            if (empty($volunteer->$field)) {
                $missing_fields[] = $field;
            }
        }
        
        $profile_complete = empty($missing_fields);
        
        ob_start();
        ?>
        <div class="friendshyft-portal profile-view">
            <div class="portal-header">
                <div class="header-content">
                    <h1>⚙️ My Profile</h1>
                    <div class="header-actions">
                        <a href="<?php echo esc_url($portal_url); ?>" class="btn-secondary">← Back to Dashboard</a>
                    </div>
                </div>
            </div>
            
            <div class="portal-content">
                
                <?php if (!$profile_complete): ?>
                    <div class="profile-alert incomplete">
                        <h3>⚠️ Profile Incomplete</h3>
                        <p>Please complete all required fields marked with <span class="required-mark">*</span></p>
                    </div>
                <?php else: ?>
                    <div class="profile-alert complete">
                        <h3>✓ Profile Complete</h3>
                        <p>Your profile is up to date. Thank you!</p>
                    </div>
                <?php endif; ?>
                
                <form id="profile-form" class="profile-form" method="post">
                    <?php
                    // Only include nonce for WordPress logged-in users (not token-based access)
                    $using_token = isset($_GET['token']) && !empty($_GET['token']);
                    if (!$using_token) {
                        wp_nonce_field('fs_update_profile', 'profile_nonce');
                    }
                    ?>
                    <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                    
                    <!-- Basic Information -->
                    <div class="profile-section">
                        <h2>Basic Information</h2>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">Full Name <span class="required-mark">*</span></label>
                                <input type="text" id="name" name="name" value="<?php echo esc_attr($volunteer->name); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address <span class="required-mark">*</span></label>
                                <input type="email" id="email" name="email" value="<?php echo esc_attr($volunteer->email); ?>" required readonly>
                                <p class="field-note">Contact an administrator to change your email address</p>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" value="<?php echo esc_attr($volunteer->phone); ?>" placeholder="(555) 123-4567">
                            </div>
                            
                            <div class="form-group">
                                <label for="birthdate">Birth Date</label>
                                <input type="date" id="birthdate" name="birthdate" value="<?php echo esc_attr($volunteer->birthdate); ?>">
                                <p class="field-note">Used to verify age requirements for certain opportunities</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Address Information -->
                    <div class="profile-section">
                        <h2>Address</h2>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="address_line1">Street Address</label>
                                <input type="text" id="address_line1" name="address_line1" value="<?php echo esc_attr($volunteer->address_line1); ?>" placeholder="123 Main Street">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="address_line2">Apartment, Suite, etc. (Optional)</label>
                                <input type="text" id="address_line2" name="address_line2" value="<?php echo esc_attr($volunteer->address_line2); ?>" placeholder="Apt 4B">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" value="<?php echo esc_attr($volunteer->city); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="state">State</label>
                                <select id="state" name="state">
                                    <option value="">Select State</option>
                                    <?php
                                    $states = array(
                                        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
                                        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
                                        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
                                        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
                                        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
                                        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
                                        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
                                        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
                                        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
                                        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
                                        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
                                        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
                                        'WI' => 'Wisconsin', 'WY' => 'Wyoming'
                                    );
                                    foreach ($states as $code => $name) {
                                        $selected = ($volunteer->state === $code) ? 'selected' : '';
                                        echo "<option value=\"$code\" $selected>$name</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="zip_code">ZIP Code</label>
                                <input type="text" id="zip_code" name="zip_code" value="<?php echo esc_attr($volunteer->zip_code); ?>" maxlength="10" placeholder="12345">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Emergency Contact -->
                    <div class="profile-section emergency-section">
                        <h2>Emergency Contact <span class="required-mark">* Required</span></h2>
                        <p class="section-description">This person will be contacted in case of an emergency while you're volunteering.</p>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="emergency_contact_name">Contact Name <span class="required-mark">*</span></label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo esc_attr($volunteer->emergency_contact_name); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="emergency_contact_phone">Contact Phone <span class="required-mark">*</span></label>
                                <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo esc_attr($volunteer->emergency_contact_phone); ?>" required placeholder="(555) 123-4567">
                            </div>
                            
                            <div class="form-group">
                                <label for="emergency_contact_relationship">Relationship</label>
                                <select id="emergency_contact_relationship" name="emergency_contact_relationship">
                                    <option value="">Select...</option>
                                    <?php
                                    $relationships = array('Spouse', 'Parent', 'Child', 'Sibling', 'Friend', 'Other');
                                    foreach ($relationships as $rel) {
                                        $selected = ($volunteer->emergency_contact_relationship === $rel) ? 'selected' : '';
                                        echo "<option value=\"$rel\" $selected>$rel</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Volunteer Roles -->
                    <div class="profile-section">
                        <h2>Your Volunteer Roles</h2>
                        <?php if (!empty($roles)): ?>
                            <div class="roles-grid">
                                <?php foreach ($roles as $role): ?>
                                    <div class="role-card">
                                        <div class="role-icon">🎯</div>
                                        <div class="role-details">
                                            <div class="role-name"><?php echo esc_html($role->name); ?></div>
                                            <?php if ($role->program_name): ?>
                                                <div class="role-program"><?php echo esc_html($role->program_name); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-roles">You don't have any assigned volunteer roles yet. Contact an administrator to get started.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Background Check Status -->
                    <?php if (!empty($volunteer->background_check_status)): ?>
                        <div class="profile-section">
                            <h2>Background Check Status</h2>
                            <div class="bg-check-card">
                                <div class="bg-check-status status-<?php echo strtolower($volunteer->background_check_status); ?>">
                                    <strong>Status:</strong> <?php echo esc_html($volunteer->background_check_status); ?>
                                </div>
                                <?php if (!empty($volunteer->background_check_date)): ?>
                                    <div class="bg-check-detail">
                                        <strong>Date:</strong> <?php echo date('F j, Y', strtotime($volunteer->background_check_date)); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($volunteer->background_check_org)): ?>
                                    <div class="bg-check-detail">
                                        <strong>Organization:</strong> <?php echo esc_html($volunteer->background_check_org); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($volunteer->background_check_expiration)): ?>
                                    <div class="bg-check-detail">
                                        <strong>Expires:</strong> <?php echo date('F j, Y', strtotime($volunteer->background_check_expiration)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Account Security -->
                    <?php if (!empty($volunteer->wp_user_id)): ?>
                        <div class="profile-section">
                            <h2>Account Security</h2>
                            <p>To change your password, <a href="<?php echo wp_lostpassword_url(); ?>" target="_blank">click here to reset your password</a>.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary btn-save">Save Changes</button>
                        <a href="<?php echo esc_url($portal_url); ?>" class="btn-secondary">Cancel</a>
                    </div>
                </form>
                
            </div>
        </div>
        
        <style>
        .profile-view .portal-content {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .profile-alert {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .profile-alert.incomplete {
            background: #fff3cd;
            border: 2px solid #ffc107;
        }
        .profile-alert.complete {
            background: #d4edda;
            border: 2px solid #28a745;
        }
        .profile-alert h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        .profile-alert.incomplete h3 {
            color: #856404;
        }
        .profile-alert.complete h3 {
            color: #155724;
        }
        .profile-alert p {
            margin: 0;
            color: #333;
        }
        
        .profile-form {
            background: white;
        }
        
        .profile-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .profile-section h2 {
            margin: 0 0 20px 0;
            font-size: 22px;
            color: #333;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 10px;
        }
        .section-description {
            color: #666;
            font-size: 14px;
            margin: -10px 0 20px 0;
        }
        
        .emergency-section {
            border: 2px solid #f39c12;
            background: #fff9e6;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row:last-child {
            margin-bottom: 0;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }
        
        .required-mark {
            color: #dc3545;
            font-weight: 700;
        }
        
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="date"],
        .form-group select {
            padding: 12px;
            border: 2px solid #dee2e6;
            border-radius: 4px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0073aa;
        }
        
        .form-group input[readonly] {
            background: #f8f9fa;
            color: #6c757d;
            cursor: not-allowed;
        }
        
        .field-note {
            margin: 5px 0 0 0;
            font-size: 12px;
            color: #666;
            font-style: italic;
        }
        
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .role-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
        }
        
        .role-icon {
            font-size: 32px;
        }
        
        .role-name {
            font-weight: 600;
            font-size: 16px;
        }
        
        .role-program {
            font-size: 13px;
            opacity: 0.9;
            margin-top: 2px;
        }
        
        .no-roles {
            padding: 40px;
            text-align: center;
            background: #f8f9fa;
            border-radius: 8px;
            color: #666;
        }
        
        .bg-check-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #dee2e6;
        }
        
        .bg-check-status {
            font-size: 16px;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .bg-check-status.status-cleared,
        .bg-check-status.status-approved {
            background: #d4edda;
            color: #155724;
        }
        .bg-check-status.status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .bg-check-status.status-expired {
            background: #f8d7da;
            color: #721c24;
        }
        
        .bg-check-detail {
            margin-bottom: 10px;
            font-size: 14px;
            color: #333;
        }
        .bg-check-detail:last-child {
            margin-bottom: 0;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding: 20px 30px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .btn-primary,
        .btn-secondary {
            padding: 14px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #28a745;
            color: white;
        }
        .btn-primary:hover {
            background: #218838;
        }
        
        .btn-save {
            background: #0073aa;
        }
        .btn-save:hover {
            background: #005a87;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        @media (max-width: 768px) {
            .profile-section {
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .roles-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn-primary,
            .btn-secondary {
                width: 100%;
            }
        }
        </style>
        
        <script>
jQuery(document).ready(function($) {
$('#profile-form').on('submit', function(e) {
e.preventDefault();
            const $form = $(this);
            const $submitBtn = $form.find('.btn-save');
            const originalText = $submitBtn.text();
            
            $submitBtn.prop('disabled', true).text('Saving...');
            
            // Serialize form data as regular object instead of FormData
            const formData = $form.serializeArray();
            const data = {};

            // Convert to object
            $.each(formData, function(i, field) {
                data[field.name] = field.value;
            });

            // Get token if present
            const urlParams = new URLSearchParams(window.location.search);
            const token = urlParams.get('token');

            // Use REST API for token-based auth, admin-ajax for logged-in users
            let ajaxUrl, ajaxData;
            if (token) {
                // REST API endpoint bypasses admin-ajax.php security checks
                ajaxUrl = '<?php echo rest_url('friendshyft/v1/profile/update'); ?>';
                data.token = token;
                ajaxData = data;
            } else {
                // Traditional admin-ajax for logged-in users
                ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                data.action = 'fs_update_profile';
                ajaxData = data;
            }

            console.log('Sending data:', ajaxData);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    console.log('Response:', response);
                    if (response.success) {
                        // Show success message
                        const notice = $('<div class="profile-notice success">✓ ' + response.data.message + '</div>');
                        $('.portal-content').prepend(notice);
                        notice.fadeIn(300);
                        
                        // Scroll to top
                        $('html, body').animate({ scrollTop: 0 }, 300);
                        
                        // Fade out notice after 5 seconds
                        setTimeout(function() {
                            notice.fadeOut(300, function() { $(this).remove(); });
                        }, 5000);
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    console.error('Response:', xhr.responseText);

                    let errorMsg = 'Something went wrong. Please try again.';

                    // Check for specific error messages
                    if (xhr.status === 403) {
                        errorMsg = 'Session expired. Please refresh the page and try again.';
                    } else if (xhr.responseText) {
                        // Try to extract error message from response
                        const responseText = xhr.responseText.toLowerCase();
                        if (responseText.includes('expired')) {
                            errorMsg = 'Your session has expired. Please refresh the page and try again.';
                        }
                    }

                    alert(errorMsg);
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).text(originalText);
                }
            });
        });
    });
    </script>
        
        <style>
        .profile-notice {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 600;
            display: none;
        }
        .profile-notice.success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX handler for profile updates
     */
    public static function ajax_update_profile() {
        // Check for token auth first
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : null;
		
    global $wpdb;
    $volunteer = null;
    
    if ($token) {
        // Token auth - verify token
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $token
        ));
        
        if (!$volunteer) {
            wp_send_json_error(array('message' => 'Invalid authentication token'));
        }
    } else {
        // WordPress login - verify nonce
        if (!isset($_POST['profile_nonce']) || !wp_verify_nonce($_POST['profile_nonce'], 'fs_update_profile')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        // Fall back to login check
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in'));
        }
        
        $user_id = get_current_user_id();
        $monday_id = get_user_meta($user_id, 'monday_contact_id', true);
        $local_volunteer_id = get_user_meta($user_id, 'fs_volunteer_id', true);
        
        if (!empty($monday_id)) {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE monday_id = %s",
                $monday_id
            ));
        } elseif (!empty($local_volunteer_id)) {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                $local_volunteer_id
            ));
        } else {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE wp_user_id = %d",
                $user_id
            ));
        }
    }
    
    if (!$volunteer) {
        wp_send_json_error(array('message' => 'Volunteer account not found'));
    }
    
    // Sanitize and prepare data
    $data = array(
        'name' => sanitize_text_field($_POST['name'] ?? ''),
        'phone' => sanitize_text_field($_POST['phone'] ?? ''),
        'birthdate' => !empty($_POST['birthdate']) ? sanitize_text_field($_POST['birthdate']) : null,
        'address_line1' => sanitize_text_field($_POST['address_line1'] ?? ''),
        'address_line2' => sanitize_text_field($_POST['address_line2'] ?? ''),
        'city' => sanitize_text_field($_POST['city'] ?? ''),
        'state' => sanitize_text_field($_POST['state'] ?? ''),
        'zip_code' => sanitize_text_field($_POST['zip_code'] ?? ''),
        'emergency_contact_name' => sanitize_text_field($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => sanitize_text_field($_POST['emergency_contact_phone'] ?? ''),
        'emergency_contact_relationship' => sanitize_text_field($_POST['emergency_contact_relationship'] ?? '')
    );
    
    // Validate required fields
    if (empty($data['name'])) {
        wp_send_json_error(array('message' => 'Name is required'));
    }
    
    if (empty($data['emergency_contact_name']) || empty($data['emergency_contact_phone'])) {
        wp_send_json_error(array('message' => 'Emergency contact information is required'));
    }
    
    // Update database
    $result = $wpdb->update(
        $wpdb->prefix . 'fs_volunteers',
        $data,
        array('id' => $volunteer->id)
    );
    
    if ($result === false) {
        wp_send_json_error(array('message' => 'Failed to update profile. Please try again.'));
    }
    
    wp_send_json_success(array('message' => 'Profile updated successfully!'));
}
    
    /**
     * AJAX handler for attendance confirmation
     */
    public static function ajax_confirm_attendance() {
        check_ajax_referer('friendshyft_portal', 'nonce');
        
        // Check for token auth first
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : null;
        
        global $wpdb;
        $volunteer = null;
        
        if ($token) {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
                $token
            ));
        }
        
        if (!$volunteer && !is_user_logged_in()) {
            wp_send_json_error('You must be logged in');
        }

        $signup_id = intval($_POST['signup_id'] ?? 0);
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);

        // Update signup
        $result = $wpdb->update(
            $wpdb->prefix . 'fs_signups',
            array(
                'attendance_confirmed' => 1,
                'confirmation_date' => current_time('mysql')
            ),
            array(
                'id' => $signup_id,
                'volunteer_id' => $volunteer_id
            )
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to confirm attendance');
        }
        
        wp_send_json_success();
    }
    
    /**
     * AJAX handler for cancelling attendance
     */
    public static function ajax_cancel_attendance() {
        check_ajax_referer('friendshyft_portal', 'nonce');
        
        // Check for token auth first
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : null;
        
        global $wpdb;
        $volunteer = null;
        
        if ($token) {
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
                $token
            ));
        }
        
        if (!$volunteer && !is_user_logged_in()) {
            wp_send_json_error('You must be logged in');
        }

        $signup_id = intval($_POST['signup_id'] ?? 0);
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);

        // Cancel signup
        $result = $wpdb->update(
            $wpdb->prefix . 'fs_signups',
            array(
                'status' => 'cancelled',
                'cancelled_date' => current_time('mysql')
            ),
            array(
                'id' => $signup_id,
                'volunteer_id' => $volunteer_id
            )
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to cancel attendance');
        }
        
        // Update opportunity spots
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT opportunity_id FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));
        
        if ($signup) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fs_opportunities 
                 SET spots_filled = spots_filled - 1 
                 WHERE id = %d AND spots_filled > 0",
                $signup->opportunity_id
            ));
        }
        
        wp_send_json_success();
    }
}

FS_Volunteer_Profile::init();
