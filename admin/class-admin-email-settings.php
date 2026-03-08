<?php
if (!defined('ABSPATH')) exit;

/**
 * Email Settings Admin Page
 */
class FS_Admin_Email_Settings {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 25);
        add_action('admin_post_fs_save_email_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_fs_generate_email_token', array(__CLASS__, 'generate_token'));
        add_action('admin_post_fs_test_email_parse', array(__CLASS__, 'test_parse'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Email Settings',
            'Email Settings',
            'manage_options',
            'fs-email-settings',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function render_page() {
        $token = get_option('fs_email_ingest_token');
        $endpoint_url = FS_Email_Ingestion::get_endpoint_url();
        
        ?>
        <div class="wrap">
            <h1>Email Ingestion Settings</h1>
            <p>Configure automatic volunteer email parsing from Community Volunteer Hub.</p>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings saved successfully!</p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['token_generated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>New security token generated!</p>
                </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 800px;">
                <h2>API Endpoint Configuration</h2>
                
                <?php if (empty($token)): ?>
                    <div class="notice notice-warning inline">
                        <p><strong>Setup Required:</strong> Generate a security token to enable email ingestion.</p>
                    </div>
                    
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="fs_generate_email_token">
                        <?php wp_nonce_field('fs_generate_email_token'); ?>
                        <p>
                            <button type="submit" class="button button-primary">Generate Security Token</button>
                        </p>
                    </form>
                    
                <?php else: ?>
                    <table class="form-table">
                        <tr>
                            <th>Endpoint URL:</th>
                            <td>
                                <input type="text" value="<?php echo esc_attr($endpoint_url); ?>" 
                                       readonly class="large-text code" id="endpoint-url">
                                <button type="button" class="button" onclick="copyToClipboard('endpoint-url')">Copy</button>
                                <p class="description">Use this URL to configure email forwarding.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Security Token:</th>
                            <td>
                                <input type="text" value="<?php echo esc_attr($token); ?>" 
                                       readonly class="large-text code" id="security-token">
                                <button type="button" class="button" onclick="copyToClipboard('security-token')">Copy</button>
                                <p class="description">Include this as <code>X-FriendShyft-Token</code> header in POST requests.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>Example cURL Command:</th>
                            <td>
                                <textarea readonly class="large-text code" rows="4" id="curl-example">curl -X POST <?php echo esc_attr($endpoint_url); ?> \
  -H "Content-Type: application/json" \
  -H "X-FriendShyft-Token: <?php echo esc_attr($token); ?>" \
  -d '{"raw_email": "email body here"}'</textarea>
                                <button type="button" class="button" onclick="copyToClipboard('curl-example')">Copy</button>
                            </td>
                        </tr>
                    </table>
                    
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" 
                          onsubmit="return confirm('This will invalidate the current token. Any configured email forwarding will stop working. Continue?');">
                        <input type="hidden" name="action" value="fs_generate_email_token">
                        <?php wp_nonce_field('fs_generate_email_token'); ?>
                        <p>
                            <button type="submit" class="button">Regenerate Security Token</button>
                        </p>
                    </form>
                <?php endif; ?>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Test Email Parser</h2>
                <p>Paste a sample email from the Community Volunteer Hub to test parsing:</p>
                
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="fs_test_email_parse">
                    <?php wp_nonce_field('fs_test_email_parse'); ?>
                    
                    <p>
                        <textarea name="test_email" rows="15" class="large-text code" 
                                  placeholder="Paste email body here..." required></textarea>
                    </p>
                    
                    <p>
                        <label>
                            <input type="checkbox" name="create_volunteer" value="1">
                            Actually create volunteer record (if not checked, will only show parsed data)
                        </label>
                    </p>
                    
                    <p>
                        <button type="submit" class="button button-primary">Test Parse</button>
                    </p>
                </form>
                
                <?php if (isset($_GET['test_result'])): 
                    $result = json_decode(stripslashes($_GET['test_result']), true);
                ?>
                    <div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?> inline">
                        <h3>Parse Result:</h3>
                        <?php if ($result['success']): ?>
                            <p><strong>Success!</strong></p>
                            <ul>
                                <li><strong>Name:</strong> <?php echo esc_html($result['name']); ?></li>
                                <li><strong>Email:</strong> <?php echo esc_html($result['email']); ?></li>
                                <li><strong>Phone:</strong> <?php echo esc_html($result['phone'] ?? 'Not provided'); ?></li>
                                <li><strong>Interest:</strong> <?php echo esc_html($result['interest'] ?? 'Not specified'); ?></li>
                                <?php if (!empty($result['notes'])): ?>
                                    <li><strong>Notes:</strong> <?php echo esc_html($result['notes']); ?></li>
                                <?php endif; ?>
                                <?php if (!empty($result['volunteer_id'])): ?>
                                    <li><strong>Volunteer Created:</strong> 
                                        <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $result['volunteer_id']); ?>">
                                            View Volunteer #<?php echo $result['volunteer_id']; ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        <?php else: ?>
                            <p><strong>Error:</strong> <?php echo esc_html($result['error']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Email Forwarding Setup Instructions</h2>
                
                <h3>Option 1: Manual Forwarding (Recommended for Testing)</h3>
                <ol>
                    <li>When you receive an email from the Community Volunteer Hub, forward it to a dedicated email address you control</li>
                    <li>Copy the email body and paste it into the "Manual Email Processing" page</li>
                    <li>Click "Process Email" to create the volunteer</li>
                </ol>
                
                <h3>Option 2: Automated Forwarding (Requires Email Service Setup)</h3>
                <p>Configure your email service (Gmail, Mailgun, SendGrid, etc.) to forward incoming emails to the API endpoint above.</p>
                
                <h4>Gmail Example:</h4>
                <ol>
                    <li>Create a filter in Gmail for emails from the Community Volunteer Hub</li>
                    <li>Use a service like Zapier or Make (formerly Integromat) to catch forwarded emails</li>
                    <li>Configure the service to POST the email body to the endpoint URL with the security token</li>
                </ol>
                
                <h4>Mailgun Example:</h4>
                <ol>
                    <li>Set up Mailgun routes to catch incoming emails</li>
                    <li>Configure a route to POST to the endpoint URL</li>
                    <li>Include the security token in the request headers</li>
                </ol>
            </div>
        </div>
        
        <script>
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            element.select();
            document.execCommand('copy');
            
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            setTimeout(() => {
                button.textContent = originalText;
            }, 2000);
        }
        </script>
        <?php
    }
    
    public static function generate_token() {
        check_admin_referer('fs_generate_email_token');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $token = FS_Email_Ingestion::generate_token();
        update_option('fs_email_ingest_token', $token);
        
        wp_redirect(admin_url('admin.php?page=fs-email-settings&token_generated=1'));
        exit;
    }
    
    public static function test_parse() {
        check_admin_referer('fs_test_email_parse');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $test_email = $_POST['test_email'] ?? '';
        $create_volunteer = !empty($_POST['create_volunteer']);
        
        if ($create_volunteer) {
            // Actually process and create
            $result = FS_Email_Parser::parse_and_process($test_email);
            
            if ($result['success']) {
                // Get volunteer details
                global $wpdb;
                $volunteer = $wpdb->get_row($wpdb->prepare(
                    "SELECT name, email, phone FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                    $result['volunteer_id']
                ));
                
                $interest = $wpdb->get_var($wpdb->prepare(
                    "SELECT interest FROM {$wpdb->prefix}fs_volunteer_interests 
                     WHERE volunteer_id = %d 
                     ORDER BY created_date DESC 
                     LIMIT 1",
                    $result['volunteer_id']
                ));
                
                $result['name'] = $volunteer->name;
                $result['email'] = $volunteer->email;
                $result['phone'] = $volunteer->phone;
                $result['interest'] = $interest;
            }
        } else {
            // Just parse, don't create
            $result = FS_Email_Parser::parse_email($test_email);
        }
        
        $redirect_url = admin_url('admin.php?page=fs-email-settings&test_result=' . urlencode(json_encode($result)));
        wp_redirect($redirect_url);
        exit;
    }
    
    private static function parse_email($email_body) {
        // This method is no longer needed since we call the parser directly
        return FS_Email_Parser::parse_email($email_body);
    }
}
