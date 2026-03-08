<?php
if (!defined('ABSPATH')) exit;

/**
 * Manual Email Processing Page
 * Simplified interface for processing emails manually
 */
class FS_Admin_Process_Email {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 24);
        add_action('admin_post_fs_process_manual_email', array(__CLASS__, 'process_email'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Process Email',
            'Process Email',
            'edit_posts',
            'fs-process-email',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function render_page() {
        ?>
        <div class="wrap">
            <h1>Process Volunteer Email</h1>
            <p>Paste an email from the Community Volunteer Hub to create a volunteer record.</p>
            
            <?php if (isset($_GET['result'])): 
                $result = json_decode(stripslashes($_GET['result']), true);
            ?>
                <div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?> is-dismissible">
                    <?php if ($result['success']): ?>
                        <h3>✓ Volunteer Created Successfully!</h3>
                        <p>
                            <strong>Name:</strong> <?php echo esc_html($result['name']); ?><br>
                            <strong>Email:</strong> <?php echo esc_html($result['email']); ?><br>
                            <strong>Interest:</strong> <?php echo esc_html($result['interest'] ?? 'Not specified'); ?>
                        </p>
                        <p>
                            <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $result['volunteer_id']); ?>" 
                               class="button button-primary">
                                View Volunteer #<?php echo $result['volunteer_id']; ?>
                            </a>
                        </p>
                    <?php else: ?>
                        <h3>Error Processing Email</h3>
                        <p><strong><?php echo esc_html($result['error']); ?></strong></p>
                        
                        <?php if ($result['error'] === 'Duplicate volunteer' && !empty($result['volunteer_id'])): ?>
                            <p>This email address already exists in the system.</p>
                            <p>
                                <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $result['volunteer_id']); ?>" 
                                   class="button">
                                    View Existing Volunteer #<?php echo $result['volunteer_id']; ?>
                                </a>
                            </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($result['parsed_data'])): ?>
                            <h4>Parsed Data (for reference):</h4>
                            <ul>
                                <?php if (!empty($result['parsed_data']['name'])): ?>
                                    <li><strong>Name:</strong> <?php echo esc_html($result['parsed_data']['name']); ?></li>
                                <?php endif; ?>
                                <?php if (!empty($result['parsed_data']['email'])): ?>
                                    <li><strong>Email:</strong> <?php echo esc_html($result['parsed_data']['email']); ?></li>
                                <?php endif; ?>
                                <?php if (!empty($result['parsed_data']['phone'])): ?>
                                    <li><strong>Phone:</strong> <?php echo esc_html($result['parsed_data']['phone']); ?></li>
                                <?php endif; ?>
                                <?php if (!empty($result['parsed_data']['interest'])): ?>
                                    <li><strong>Interest:</strong> <?php echo esc_html($result['parsed_data']['interest']); ?></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 900px;">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="fs_process_manual_email">
                    <?php wp_nonce_field('fs_process_manual_email'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th>Email Body:</th>
                            <td>
                                <textarea name="email_body" rows="20" class="large-text code" 
                                          placeholder="Paste the complete email body here..." required></textarea>
                                <p class="description">
                                    Copy and paste the entire email from the Community Volunteer Hub, including all headers and content.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">
                            Process Email & Create Volunteer
                        </button>
                    </p>
                </form>
            </div>
            
            <div class="card" style="max-width: 900px; margin-top: 20px;">
                <h2>Example Email Format</h2>
                <p>Emails from the Community Volunteer Hub should look like this:</p>
                <pre style="background: #f5f5f5; padding: 15px; overflow: auto;">Title: A New Response To Your Need
Body: This message is to notify you that a response has been submitted to Society of St Vincent de Paul – Fort Wayne's need.
Volunteer Opportunity: Food Pantry Volunteer
Submitter: Theresa Newman
Email: theresa.el.new@gmail.com
Phone: (419) 967-5723
Cell: (419) 967-5723
Additional Notes:
Thank you!
Your Friends at Volunteer Center</pre>
            </div>
            
            <div class="card" style="max-width: 900px; margin-top: 20px;">
                <h2>What Happens When You Process an Email?</h2>
                <ol>
                    <li><strong>Parse:</strong> The system extracts name, email, phone, and interest from the email</li>
                    <li><strong>Duplicate Check:</strong> Checks if this email already exists in the system</li>
                    <li><strong>Create Volunteer:</strong> If new, creates a volunteer record with status "Prospect"</li>
                    <li><strong>Send Welcome:</strong> Sends a welcome email with portal access link</li>
                    <li><strong>Notify Admin:</strong> Sends you a notification about the new volunteer</li>
                </ol>
                
                <p>
                    <a href="<?php echo admin_url('admin.php?page=fs-email-log'); ?>" class="button">
                        View Email Processing Log
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
    
    public static function process_email() {
        check_admin_referer('fs_process_manual_email');

        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        $email_body = $_POST['email_body'] ?? '';

        if (empty($email_body)) {
            wp_die('No email content provided');
        }
        
        // Process the email
        $result = FS_Email_Parser::parse_and_process($email_body);
        
        // If parse failed, try to get parsed data anyway for debugging
        if (!$result['success']) {
            $parsed = FS_Email_Parser::parse_email($email_body);
            if ($parsed['success']) {
                $result['parsed_data'] = $parsed;
            }
        } else {
            // Get volunteer details for success message
            global $wpdb;
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT name, email FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                $result['volunteer_id']
            ));
            
            if ($volunteer) {
                $result['name'] = $volunteer->name;
                $result['email'] = $volunteer->email;
                
                // Get latest interest
                $interest = $wpdb->get_var($wpdb->prepare(
                    "SELECT interest FROM {$wpdb->prefix}fs_volunteer_interests 
                     WHERE volunteer_id = %d 
                     ORDER BY created_date DESC 
                     LIMIT 1",
                    $result['volunteer_id']
                ));
                
                $result['interest'] = $interest;
            }
        }
        
        $redirect_url = admin_url('admin.php?page=fs-process-email&result=' . urlencode(json_encode($result)));
        wp_redirect($redirect_url);
        exit;
    }
}
