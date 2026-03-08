<?php
if (!defined('ABSPATH')) exit;

/**
 * Google Calendar Portal Integration
 * Volunteer-facing UI for connecting/disconnecting Google Calendar
 */
class FS_Portal_Google_Calendar {

    public static function init() {
        // No specific init hooks needed - methods called from portal
    }

    /**
     * Render Google Calendar connection section for volunteer portal
     */
    public static function render_connection_section($volunteer) {
        if (!FS_Google_Calendar_Sync::is_configured()) {
            return ''; // Don't show if admin hasn't configured Google Calendar
        }

        $is_connected = FS_Google_Calendar_Sync::is_connected($volunteer->id);
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        // Show connection success/disconnection messages
        $message = '';
        if (isset($_GET['gcal_connected'])) {
            $message = '<div class="gcal-message gcal-success">✓ Google Calendar connected successfully! Your volunteer shifts will now sync automatically.</div>';
        }
        if (isset($_GET['gcal_disconnected'])) {
            $message = '<div class="gcal-message gcal-info">Google Calendar disconnected. Your shifts will no longer sync.</div>';
        }

        ob_start();
        ?>
        <style>
            .gcal-card {
                background: white;
                padding: 25px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                margin: 20px 0;
            }
            .gcal-header {
                display: flex;
                align-items: center;
                margin-bottom: 20px;
            }
            .gcal-logo {
                width: 48px;
                height: 48px;
                margin-right: 15px;
                background: linear-gradient(135deg, #4285f4 0%, #34a853 50%, #fbbc04 75%, #ea4335 100%);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 24px;
                font-weight: bold;
            }
            .gcal-title {
                flex: 1;
            }
            .gcal-title h3 {
                margin: 0 0 5px 0;
                font-size: 20px;
                color: #333;
            }
            .gcal-title p {
                margin: 0;
                color: #666;
                font-size: 14px;
            }
            .gcal-status {
                display: inline-block;
                padding: 6px 12px;
                border-radius: 4px;
                font-size: 13px;
                font-weight: 600;
            }
            .gcal-status.connected {
                background: #d4edda;
                color: #155724;
            }
            .gcal-status.disconnected {
                background: #f8d7da;
                color: #721c24;
            }
            .gcal-features {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 6px;
                margin: 20px 0;
            }
            .gcal-features h4 {
                margin: 0 0 15px 0;
                color: #4285f4;
                font-size: 16px;
            }
            .gcal-features ul {
                margin: 0;
                padding-left: 20px;
                line-height: 1.8;
            }
            .gcal-features li {
                color: #333;
            }
            .gcal-actions {
                margin-top: 20px;
            }
            .gcal-btn {
                display: inline-block;
                padding: 12px 24px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                transition: all 0.2s;
                border: none;
                cursor: pointer;
            }
            .gcal-btn-connect {
                background: #4285f4;
                color: white;
            }
            .gcal-btn-connect:hover {
                background: #357ae8;
                color: white;
            }
            .gcal-btn-disconnect {
                background: #dc3545;
                color: white;
            }
            .gcal-btn-disconnect:hover {
                background: #c82333;
                color: white;
            }
            .gcal-message {
                padding: 15px 20px;
                border-radius: 6px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .gcal-success {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
            }
            .gcal-info {
                background: #d1ecf1;
                border: 1px solid #bee5eb;
                color: #0c5460;
            }
            .gcal-warning {
                background: #fff3cd;
                border: 1px solid #ffeeba;
                color: #856404;
                margin-top: 15px;
            }
        </style>

        <div class="gcal-card">
            <?php echo $message; ?>

            <div class="gcal-header">
                <div class="gcal-logo">G</div>
                <div class="gcal-title">
                    <h3>Google Calendar Sync</h3>
                    <p>Keep your volunteer shifts in sync with your personal calendar</p>
                </div>
                <?php if ($is_connected): ?>
                    <span class="gcal-status connected">✓ Connected</span>
                <?php else: ?>
                    <span class="gcal-status disconnected">Not Connected</span>
                <?php endif; ?>
            </div>

            <div class="gcal-features">
                <h4>What You Get:</h4>
                <ul>
                    <li><strong>Automatic Sync:</strong> Volunteer shifts automatically appear in your Google Calendar</li>
                    <li><strong>Reminders:</strong> Get email and popup reminders before each shift</li>
                    <li><strong>Conflict Prevention:</strong> We'll warn you about scheduling conflicts with your personal events</li>
                    <li><strong>Always Current:</strong> Cancellations and changes sync instantly</li>
                </ul>
            </div>

            <?php if ($is_connected): ?>
                <div class="gcal-actions">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('Are you sure you want to disconnect Google Calendar? Your shifts will no longer sync automatically.');">
                        <?php wp_nonce_field('fs_disconnect_google', '_wpnonce_disconnect'); ?>
                        <input type="hidden" name="action" value="fs_disconnect_google">
                        <input type="hidden" name="volunteer_id" value="<?php echo esc_attr($volunteer->id); ?>">
                        <input type="hidden" name="access_token" value="<?php echo esc_attr($token); ?>">
                        <button type="submit" class="gcal-btn gcal-btn-disconnect">Disconnect Google Calendar</button>
                    </form>
                    <p style="color: #666; font-size: 13px; margin-top: 10px;">
                        You can reconnect anytime. Disconnecting won't delete past events from your Google Calendar.
                    </p>
                </div>
            <?php else: ?>
                <div class="gcal-actions">
                    <a href="<?php echo esc_url(FS_Google_Calendar_Sync::get_auth_url($volunteer->id, $token)); ?>" class="gcal-btn gcal-btn-connect">
                        Connect Google Calendar
                    </a>
                    <div class="gcal-warning">
                        <strong>Privacy Note:</strong> We only access your calendar to add/remove volunteer shifts. We don't read or share your personal events.
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render conflict warnings when booking a shift
     */
    public static function get_conflict_warnings($volunteer_id, $opportunity_id) {
        global $wpdb;

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            return '';
        }

        // Check for blocked times that overlap with this opportunity
        $conflicts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_blocked_times
             WHERE volunteer_id = %d
             AND source = 'google_calendar'
             AND DATE(start_time) = %s",
            $volunteer_id,
            $opportunity->event_date
        ));

        if (empty($conflicts)) {
            return '';
        }

        ob_start();
        ?>
        <div class="gcal-warning" style="margin-bottom: 15px;">
            <strong>⚠ Calendar Conflict Warning</strong>
            <p>You have the following events in your Google Calendar on <?php echo date('F j, Y', strtotime($opportunity->event_date)); ?>:</p>
            <ul>
                <?php foreach ($conflicts as $conflict): ?>
                    <li>
                        <?php echo esc_html($conflict->title); ?>
                        (<?php echo date('g:i A', strtotime($conflict->start_time)); ?> - <?php echo date('g:i A', strtotime($conflict->end_time)); ?>)
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>Please make sure you can attend both commitments before signing up.</p>
        </div>
        <?php
        return ob_get_clean();
    }
}
