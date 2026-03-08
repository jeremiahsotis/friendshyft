<?php
if (!defined('ABSPATH')) exit;

/**
 * Google Calendar Sync
 * Two-way synchronization between FriendShyft and Google Calendar
 */
class FS_Google_Calendar_Sync {

    public static function init() {
        add_action('admin_post_fs_oauth_callback', array(__CLASS__, 'handle_oauth_callback'));
        add_action('admin_post_fs_disconnect_google', array(__CLASS__, 'disconnect_google_calendar'));
        add_action('fs_signup_created', array(__CLASS__, 'sync_signup_to_google'), 10, 2);
        add_action('fs_signup_cancelled', array(__CLASS__, 'remove_from_google'), 10, 2);
        add_action('fs_check_google_calendar_cron', array(__CLASS__, 'sync_from_google'));
    }

    /**
     * Check if Google Calendar is configured
     */
    public static function is_configured() {
        $client_id = get_option('fs_google_client_id');
        $client_secret = get_option('fs_google_client_secret');
        return !empty($client_id) && !empty($client_secret);
    }

    /**
     * Check if volunteer has connected their Google Calendar
     */
    public static function is_connected($volunteer_id) {
        global $wpdb;
        $token = $wpdb->get_var($wpdb->prepare(
            "SELECT google_refresh_token FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));
        return !empty($token);
    }

    /**
     * Get OAuth authorization URL
     */
    public static function get_auth_url($volunteer_id, $access_token) {
        if (!self::is_configured()) {
            return false;
        }

        $client_id = get_option('fs_google_client_id');
        $redirect_uri = admin_url('admin-post.php?action=fs_oauth_callback');

        $state = base64_encode(json_encode(array(
            'volunteer_id' => $volunteer_id,
            'access_token' => $access_token,
            'nonce' => wp_create_nonce('google_oauth_' . $volunteer_id)
        )));

        $params = array(
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state
        );

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback from Google
     */
    public static function handle_oauth_callback() {
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            wp_die('Invalid OAuth callback');
        }

        $state = json_decode(base64_decode($_GET['state']), true);
        $volunteer_id = intval($state['volunteer_id']);
        $access_token = sanitize_text_field($state['access_token']);
        $nonce = sanitize_text_field($state['nonce']);

        if (!wp_verify_nonce($nonce, 'google_oauth_' . $volunteer_id)) {
            wp_die('Invalid nonce');
        }

        // Exchange authorization code for tokens
        $tokens = self::exchange_code_for_tokens($_GET['code']);

        if (!$tokens) {
            wp_die('Failed to obtain access tokens');
        }

        // Store refresh token in database
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'google_refresh_token' => $tokens['refresh_token'],
                'google_calendar_id' => 'primary'
            ),
            array('id' => $volunteer_id)
        );

        // Create calendar events for all future signups
        self::sync_all_signups_to_google($volunteer_id);

        // Log action
        FS_Audit_Log::log('google_calendar_connected', 'volunteer', $volunteer_id);

        // Redirect back to portal
        wp_redirect(home_url('/volunteer-portal/?token=' . $access_token . '&gcal_connected=1'));
        exit;
    }

    /**
     * Exchange authorization code for access/refresh tokens
     */
    private static function exchange_code_for_tokens($code) {
        $client_id = get_option('fs_google_client_id');
        $client_secret = get_option('fs_google_client_secret');
        $redirect_uri = admin_url('admin-post.php?action=fs_oauth_callback');

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code' => $code,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri' => $redirect_uri,
                'grant_type' => 'authorization_code'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return isset($body['refresh_token']) ? $body : false;
    }

    /**
     * Get fresh access token using refresh token
     */
    private static function get_access_token($volunteer_id) {
        global $wpdb;

        $refresh_token = $wpdb->get_var($wpdb->prepare(
            "SELECT google_refresh_token FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$refresh_token) {
            return false;
        }

        $client_id = get_option('fs_google_client_id');
        $client_secret = get_option('fs_google_client_secret');

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'refresh_token' => $refresh_token,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'grant_type' => 'refresh_token'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return isset($body['access_token']) ? $body['access_token'] : false;
    }

    /**
     * Sync a signup to Google Calendar (create event)
     */
    public static function sync_signup_to_google($volunteer_id, $opportunity_id) {
        if (!self::is_connected($volunteer_id)) {
            return;
        }

        $access_token = self::get_access_token($volunteer_id);
        if (!$access_token) {
            return;
        }

        global $wpdb;

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        if (!$opportunity) {
            return;
        }

        // Create Google Calendar event
        $event = array(
            'summary' => $opportunity->title,
            'description' => $opportunity->description,
            'location' => $opportunity->location,
            'start' => array(
                'date' => $opportunity->event_date
            ),
            'end' => array(
                'date' => $opportunity->event_date
            ),
            'reminders' => array(
                'useDefault' => false,
                'overrides' => array(
                    array('method' => 'email', 'minutes' => 24 * 60), // 1 day before
                    array('method' => 'popup', 'minutes' => 60) // 1 hour before
                )
            )
        );

        $calendar_id = $wpdb->get_var($wpdb->prepare(
            "SELECT google_calendar_id FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $response = wp_remote_post(
            'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($event),
                'timeout' => 15
            )
        );

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['id'])) {
                // Store Google event ID in signup record
                $wpdb->update(
                    "{$wpdb->prefix}fs_signups",
                    array('google_event_id' => $body['id']),
                    array(
                        'volunteer_id' => $volunteer_id,
                        'opportunity_id' => $opportunity_id
                    )
                );

                FS_Audit_Log::log('google_event_created', 'signup', $opportunity_id, array(
                    'volunteer_id' => $volunteer_id,
                    'google_event_id' => $body['id']
                ));
            }
        }
    }

    /**
     * Remove signup from Google Calendar (delete event)
     */
    public static function remove_from_google($volunteer_id, $opportunity_id) {
        if (!self::is_connected($volunteer_id)) {
            return;
        }

        $access_token = self::get_access_token($volunteer_id);
        if (!$access_token) {
            return;
        }

        global $wpdb;

        $google_event_id = $wpdb->get_var($wpdb->prepare(
            "SELECT google_event_id FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND opportunity_id = %d AND google_event_id IS NOT NULL",
            $volunteer_id,
            $opportunity_id
        ));

        if (!$google_event_id) {
            return;
        }

        $calendar_id = $wpdb->get_var($wpdb->prepare(
            "SELECT google_calendar_id FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        wp_remote_request(
            'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($google_event_id),
            array(
                'method' => 'DELETE',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token
                ),
                'timeout' => 15
            )
        );

        FS_Audit_Log::log('google_event_deleted', 'signup', $opportunity_id, array(
            'volunteer_id' => $volunteer_id,
            'google_event_id' => $google_event_id
        ));
    }

    /**
     * Sync all future signups to Google Calendar
     */
    private static function sync_all_signups_to_google($volunteer_id) {
        global $wpdb;

        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.event_date
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.volunteer_id = %d
             AND s.status = 'confirmed'
             AND o.event_date >= CURDATE()
             AND s.google_event_id IS NULL",
            $volunteer_id
        ));

        foreach ($signups as $signup) {
            self::sync_signup_to_google($volunteer_id, $signup->opportunity_id);
        }
    }

    /**
     * Sync from Google Calendar (check for external events that block volunteer time)
     * Run via cron to pull changes from Google
     */
    public static function sync_from_google() {
        global $wpdb;

        // Get all volunteers with Google Calendar connected
        $volunteers = $wpdb->get_results(
            "SELECT id, google_refresh_token, google_calendar_id
             FROM {$wpdb->prefix}fs_volunteers
             WHERE google_refresh_token IS NOT NULL"
        );

        foreach ($volunteers as $volunteer) {
            $access_token = self::get_access_token($volunteer->id);
            if (!$access_token) {
                continue;
            }

            // Get events from Google Calendar for next 30 days
            $time_min = gmdate('c');
            $time_max = gmdate('c', strtotime('+30 days'));

            $response = wp_remote_get(
                'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($volunteer->google_calendar_id) . '/events?' . http_build_query(array(
                    'timeMin' => $time_min,
                    'timeMax' => $time_max,
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime'
                )),
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token
                    ),
                    'timeout' => 15
                )
            );

            if (is_wp_error($response)) {
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($body['items'])) {
                continue;
            }

            // Store external events as "blocked time" for conflict detection
            self::update_blocked_times($volunteer->id, $body['items']);
        }
    }

    /**
     * Update blocked times for conflict detection
     */
    private static function update_blocked_times($volunteer_id, $events) {
        global $wpdb;

        // Clear existing blocked times for this volunteer
        $wpdb->delete(
            "{$wpdb->prefix}fs_blocked_times",
            array('volunteer_id' => $volunteer_id, 'source' => 'google_calendar')
        );

        // Insert new blocked times from Google Calendar
        foreach ($events as $event) {
            // Skip all-day events
            if (!isset($event['start']['dateTime'])) {
                continue;
            }

            // Skip events created by FriendShyft (they have our event ID)
            if (isset($event['extendedProperties']['private']['friendshyft_signup_id'])) {
                continue;
            }

            $start_time = gmdate('Y-m-d H:i:s', strtotime($event['start']['dateTime']));
            $end_time = gmdate('Y-m-d H:i:s', strtotime($event['end']['dateTime']));

            $wpdb->insert(
                "{$wpdb->prefix}fs_blocked_times",
                array(
                    'volunteer_id' => $volunteer_id,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'source' => 'google_calendar',
                    'google_event_id' => $event['id'],
                    'title' => isset($event['summary']) ? $event['summary'] : 'Busy',
                    'created_at' => current_time('mysql')
                )
            );
        }
    }

    /**
     * Disconnect Google Calendar
     */
    public static function disconnect_google_calendar() {
        check_admin_referer('fs_disconnect_google', '_wpnonce_disconnect');

        $volunteer_id = intval($_POST['volunteer_id']);
        $access_token = sanitize_text_field($_POST['access_token']);

        // Verify volunteer
        global $wpdb;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d AND access_token = %s",
            $volunteer_id,
            $access_token
        ));

        if (!$volunteer) {
            wp_die('Unauthorized');
        }

        // Clear Google Calendar data
        $wpdb->update(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'google_refresh_token' => null,
                'google_calendar_id' => null
            ),
            array('id' => $volunteer_id)
        );

        // Clear blocked times
        $wpdb->delete(
            "{$wpdb->prefix}fs_blocked_times",
            array('volunteer_id' => $volunteer_id, 'source' => 'google_calendar')
        );

        // Log action
        FS_Audit_Log::log('google_calendar_disconnected', 'volunteer', $volunteer_id);

        wp_redirect(home_url('/volunteer-portal/?token=' . $access_token . '&gcal_disconnected=1'));
        exit;
    }

    /**
     * Create database tables for Google Calendar sync
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Add columns to volunteers table
        $volunteers_table = $wpdb->prefix . 'fs_volunteers';

        $columns_to_add = array(
            'google_refresh_token' => "ALTER TABLE $volunteers_table ADD COLUMN google_refresh_token VARCHAR(255) NULL AFTER access_token",
            'google_calendar_id' => "ALTER TABLE $volunteers_table ADD COLUMN google_calendar_id VARCHAR(255) NULL AFTER google_refresh_token"
        );

        foreach ($columns_to_add as $column => $sql) {
            $exists = $wpdb->get_var("SHOW COLUMNS FROM $volunteers_table LIKE '$column'");
            if (!$exists) {
                $wpdb->query($sql);
            }
        }

        // Add column to signups table
        $signups_table = $wpdb->prefix . 'fs_signups';
        $exists = $wpdb->get_var("SHOW COLUMNS FROM $signups_table LIKE 'google_event_id'");
        if (!$exists) {
            $wpdb->query("ALTER TABLE $signups_table ADD COLUMN google_event_id VARCHAR(255) NULL AFTER status");
        }

        // Create blocked times table
        $blocked_times_table = $wpdb->prefix . 'fs_blocked_times';
        $sql = "CREATE TABLE IF NOT EXISTS $blocked_times_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) unsigned NOT NULL,
            start_time datetime NOT NULL,
            end_time datetime NOT NULL,
            source varchar(50) NOT NULL DEFAULT 'google_calendar',
            google_event_id varchar(255) NULL,
            title varchar(255) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY start_time (start_time),
            KEY end_time (end_time),
            KEY source (source)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
