<?php
if (!defined('ABSPATH')) exit;

/**
 * Google Calendar Settings
 * Admin interface for configuring Google Calendar OAuth
 */
class FS_Admin_Google_Settings {

    public static function init() {
        add_action('admin_post_fs_save_google_settings', array(__CLASS__, 'save_settings'));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        // Handle save
        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Google Calendar settings saved successfully!</p></div>';
        }

        $client_id = get_option('fs_google_client_id', '');
        $client_secret = get_option('fs_google_client_secret', '');
        $is_configured = !empty($client_id) && !empty($client_secret);

        // Get statistics
        global $wpdb;
        $connected_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteers WHERE google_refresh_token IS NOT NULL"
        );
        $synced_events_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups WHERE google_event_id IS NOT NULL"
        );
        $blocked_times_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_blocked_times WHERE source = 'google_calendar'"
        );

        ?>
        <div class="wrap">
            <h1>Google Calendar Integration</h1>
            <p>Configure two-way synchronization with Google Calendar for volunteers.</p>

            <!-- Statistics -->
            <?php if ($is_configured): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
                <div style="background: white; border-left: 4px solid #4285f4; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #4285f4;"><?php echo number_format($connected_count); ?></div>
                    <div style="color: #666; margin-top: 5px;">Connected Volunteers</div>
                </div>
                <div style="background: white; border-left: 4px solid #34a853; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #34a853;"><?php echo number_format($synced_events_count); ?></div>
                    <div style="color: #666; margin-top: 5px;">Synced Events</div>
                </div>
                <div style="background: white; border-left: 4px solid #fbbc04; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #fbbc04;"><?php echo number_format($blocked_times_count); ?></div>
                    <div style="color: #666; margin-top: 5px;">Blocked Time Slots</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Configuration Status -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Configuration Status</h2>
                <?php if ($is_configured): ?>
                    <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 4px;">
                        <strong>✓ Google Calendar is configured</strong><br>
                        Volunteers can now connect their Google Calendars from the volunteer portal.
                    </div>
                <?php else: ?>
                    <div style="background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; border-radius: 4px;">
                        <strong>⚠ Google Calendar is not configured</strong><br>
                        To enable Google Calendar sync, you need to set up OAuth credentials.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Setup Instructions -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Setup Instructions</h2>
                <ol style="line-height: 1.8;">
                    <li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                    <li>Create a new project or select an existing one</li>
                    <li>Enable the <strong>Google Calendar API</strong> for your project</li>
                    <li>Go to <strong>APIs & Services → Credentials</strong></li>
                    <li>Click <strong>Create Credentials → OAuth 2.0 Client ID</strong></li>
                    <li>Configure the consent screen if prompted</li>
                    <li>Select <strong>Web application</strong> as the application type</li>
                    <li>Add this Authorized redirect URI:
                        <code style="background: #f5f5f5; padding: 5px 10px; display: block; margin: 10px 0; font-size: 12px;">
                            <?php echo esc_url(admin_url('admin-post.php?action=fs_oauth_callback')); ?>
                        </code>
                    </li>
                    <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> to the form below</li>
                    <li>Click <strong>Save Settings</strong></li>
                </ol>
            </div>

            <!-- Settings Form -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>OAuth Credentials</h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('fs_save_google_settings', '_wpnonce_google'); ?>
                    <input type="hidden" name="action" value="fs_save_google_settings">

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="client_id">Client ID</label>
                            </th>
                            <td>
                                <input type="text"
                                       id="client_id"
                                       name="client_id"
                                       value="<?php echo esc_attr($client_id); ?>"
                                       class="regular-text"
                                       placeholder="123456789-abcdefg.apps.googleusercontent.com">
                                <p class="description">Your Google OAuth 2.0 Client ID</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="client_secret">Client Secret</label>
                            </th>
                            <td>
                                <input type="text"
                                       id="client_secret"
                                       name="client_secret"
                                       value="<?php echo esc_attr($client_secret); ?>"
                                       class="regular-text"
                                       placeholder="GOCSPX-...">
                                <p class="description">Your Google OAuth 2.0 Client Secret</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Save Settings</button>
                    </p>
                </form>
            </div>

            <!-- How It Works -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>How It Works</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div>
                        <h3 style="color: #4285f4; margin-top: 0;">📤 FriendShyft → Google</h3>
                        <ul style="line-height: 1.8;">
                            <li>When volunteers sign up for shifts, events are automatically added to their Google Calendar</li>
                            <li>When signups are cancelled, events are removed from Google Calendar</li>
                            <li>Reminders are set automatically (1 day + 1 hour before)</li>
                        </ul>
                    </div>
                    <div>
                        <h3 style="color: #34a853; margin-top: 0;">📥 Google → FriendShyft</h3>
                        <ul style="line-height: 1.8;">
                            <li>Hourly sync pulls events from volunteers' Google Calendars</li>
                            <li>External events create "blocked time" entries</li>
                            <li>Prevents signup conflicts with personal commitments</li>
                        </ul>
                    </div>
                    <div>
                        <h3 style="color: #fbbc04; margin-top: 0;">🔐 Security</h3>
                        <ul style="line-height: 1.8;">
                            <li>OAuth 2.0 authentication (industry standard)</li>
                            <li>Volunteers explicitly authorize access</li>
                            <li>Can disconnect anytime from portal</li>
                            <li>Only calendar access, no other Google data</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Cron Job Info -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Scheduled Sync</h2>
                <p>The Google Calendar sync runs automatically every hour via WordPress cron. To check if it's working:</p>
                <ol style="line-height: 1.8;">
                    <li>Go to <strong>WP Admin → Tools → Site Health → Info → Scheduled Events</strong></li>
                    <li>Look for: <code>fs_check_google_calendar_cron</code></li>
                    <li>Verify it's scheduled to run hourly</li>
                </ol>
                <p style="background: #f0f8ff; border-left: 4px solid #0073aa; padding: 15px; margin-top: 20px;">
                    <strong>Note:</strong> If your site doesn't get regular traffic, consider setting up a real cron job to trigger <code>wp-cron.php</code> hourly for reliable syncing.
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Save Google Calendar settings
     */
    public static function save_settings() {
        check_admin_referer('fs_save_google_settings', '_wpnonce_google');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $client_id = isset($_POST['client_id']) ? sanitize_text_field($_POST['client_id']) : '';
        $client_secret = isset($_POST['client_secret']) ? sanitize_text_field($_POST['client_secret']) : '';

        update_option('fs_google_client_id', $client_id);
        update_option('fs_google_client_secret', $client_secret);

        FS_Audit_Log::log('google_settings_updated', 'settings', 0, array(
            'has_client_id' => !empty($client_id),
            'has_client_secret' => !empty($client_secret)
        ));

        wp_redirect(admin_url('admin.php?page=fs-google-settings&settings-updated=1'));
        exit;
    }
}
