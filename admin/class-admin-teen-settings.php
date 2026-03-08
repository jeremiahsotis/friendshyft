<?php
if (!defined('ABSPATH')) exit;

/**
 * Teen/minor policy and SignShyft settings page.
 */
class FS_Admin_Teen_Settings {

    /**
     * Register menu + submit handler.
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 27);
        add_action('admin_post_fs_save_teen_permission_settings', array(__CLASS__, 'save_settings'));
    }

    /**
     * Add submenu page.
     */
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Teen & Minor Permissions',
            'Teen & Minor Permissions',
            'manage_options',
            'fs-teen-permission-settings',
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Render settings form.
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $settings = get_option('fs_teen_permission_settings', array());
        $private = get_option('fs_signshyft_private_settings', array());
        ?>
        <div class="wrap">
            <h1>Teen & Minor Permissions</h1>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="fs_save_teen_permission_settings">
                <?php wp_nonce_field('fs_save_teen_permission_settings'); ?>

                <h2>Policy</h2>
                <table class="form-table">
                    <tr>
                        <th>Minor rule</th>
                        <td>
                            <p><strong>Locked:</strong> unknown birthdate is treated as minor. Permission is required before confirmation.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="default_permission_scope">Default permission scope</label></th>
                        <td>
                            <select name="default_permission_scope" id="default_permission_scope">
                                <option value="single_event" <?php selected($settings['default_permission_scope'] ?? 'single_event', 'single_event'); ?>>single_event</option>
                                <option value="event_group" <?php selected($settings['default_permission_scope'] ?? '', 'event_group'); ?>>event_group</option>
                                <option value="opportunity_series" <?php selected($settings['default_permission_scope'] ?? '', 'opportunity_series'); ?>>opportunity_series</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Reuse</th>
                        <td><p><strong>Locked in v1:</strong> No reuse. Consent is scoped to the selected event context.</p></td>
                    </tr>
                </table>

                <h2>Reminders & Notifications</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="final_reminder_hours">Final reminder offset (hours)</label></th>
                        <td><input type="number" id="final_reminder_hours" name="final_reminder_hours" min="1" max="24" value="<?php echo esc_attr($settings['final_reminder_hours'] ?? 2); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="reminder_recipients">Reminder recipients</label></th>
                        <td>
                            <select name="reminder_recipients" id="reminder_recipients">
                                <option value="guardian_only" <?php selected($settings['reminder_recipients'] ?? 'guardian_only', 'guardian_only'); ?>>guardian_only</option>
                                <option value="guardian_plus_teen" <?php selected($settings['reminder_recipients'] ?? '', 'guardian_plus_teen'); ?>>guardian_plus_teen</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="staff_notification_emails">Staff notification emails</label></th>
                        <td><input type="text" id="staff_notification_emails" name="staff_notification_emails" class="regular-text" value="<?php echo esc_attr($settings['staff_notification_emails'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="help_contact_line">Help contact line</label></th>
                        <td><input type="text" id="help_contact_line" name="help_contact_line" class="regular-text" value="<?php echo esc_attr($settings['help_contact_line'] ?? ''); ?>"></td>
                    </tr>
                </table>

                <h2>Template Mapping</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="default_template_version_id">Default templateVersionId</label></th>
                        <td><input type="text" id="default_template_version_id" name="default_template_version_id" class="regular-text" value="<?php echo esc_attr($settings['default_template_version_id'] ?? ''); ?>"></td>
                    </tr>
                </table>

                <h2>SignShyft Integration</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="signshyft_api_base_url">API base URL</label></th>
                        <td><input type="url" id="signshyft_api_base_url" name="signshyft_api_base_url" class="regular-text" value="<?php echo esc_attr($private['signshyft_api_base_url'] ?? ''); ?>"></td>
                    </tr>
                    <tr>
                        <th>Bearer JWT</th>
                        <td>
                            <p><?php echo !empty($private['signshyft_bearer_jwt']) ? 'Stored (masked)' : 'Not set'; ?></p>
                            <input type="password" name="signshyft_bearer_jwt_replace" class="regular-text" placeholder="Enter new token to replace">
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook secret current (base64)</th>
                        <td>
                            <p><?php echo !empty($private['signshyft_webhook_secret_current_b64']) ? 'Stored (masked)' : 'Not set'; ?></p>
                            <input type="password" name="signshyft_webhook_secret_current_b64_replace" class="regular-text" placeholder="Enter new current secret to replace">
                        </td>
                    </tr>
                    <tr>
                        <th>Webhook secret next (base64)</th>
                        <td>
                            <p><?php echo !empty($private['signshyft_webhook_secret_next_b64']) ? 'Stored (masked)' : 'Not set'; ?></p>
                            <input type="password" name="signshyft_webhook_secret_next_b64_replace" class="regular-text" placeholder="Optional rotation secret">
                        </td>
                    </tr>
                </table>

                <p class="submit"><button class="button button-primary">Save Settings</button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Persist settings from admin form.
     */
    public static function save_settings() {
        check_admin_referer('fs_save_teen_permission_settings');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $settings = get_option('fs_teen_permission_settings', array());
        $settings['final_reminder_hours'] = max(1, (int) ($_POST['final_reminder_hours'] ?? 2));
        $settings['reminder_recipients'] = sanitize_text_field($_POST['reminder_recipients'] ?? 'guardian_only');
        $settings['staff_notification_emails'] = sanitize_text_field($_POST['staff_notification_emails'] ?? '');
        $settings['help_contact_line'] = sanitize_text_field($_POST['help_contact_line'] ?? '');
        $settings['default_template_version_id'] = sanitize_text_field($_POST['default_template_version_id'] ?? '');
        $settings['default_permission_scope'] = sanitize_text_field($_POST['default_permission_scope'] ?? 'single_event');

        // Locked policy values.
        $settings['hold_window_hours'] = 48;
        $settings['reminder_24h_hours'] = 24;
        $settings['reuse_enabled'] = 0;
        $settings['reuse_validity_days'] = 0;

        update_option('fs_teen_permission_settings', $settings);

        $private = get_option('fs_signshyft_private_settings', array());
        $private['signshyft_api_base_url'] = esc_url_raw($_POST['signshyft_api_base_url'] ?? '');

        if (!empty($_POST['signshyft_bearer_jwt_replace'])) {
            $private['signshyft_bearer_jwt'] = sanitize_text_field($_POST['signshyft_bearer_jwt_replace']);
        }
        if (!empty($_POST['signshyft_webhook_secret_current_b64_replace'])) {
            $private['signshyft_webhook_secret_current_b64'] = sanitize_text_field($_POST['signshyft_webhook_secret_current_b64_replace']);
        }
        if (isset($_POST['signshyft_webhook_secret_next_b64_replace']) && $_POST['signshyft_webhook_secret_next_b64_replace'] !== '') {
            $private['signshyft_webhook_secret_next_b64'] = sanitize_text_field($_POST['signshyft_webhook_secret_next_b64_replace']);
            $private['signshyft_webhook_secret_next_activated_at'] = gmdate('Y-m-d H:i:s');
        }

        update_option('fs_signshyft_private_settings', $private, false);

        wp_redirect(admin_url('admin.php?page=fs-teen-permission-settings&settings-updated=1'));
        exit;
    }
}
