<?php
if (!defined('ABSPATH')) exit;

/**
 * Teen/minor registration authority object + SignShyft lifecycle.
 */
class FS_Event_Registrations {

    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    const PERMISSION_NOT_REQUIRED = 'not_required';
    const PERMISSION_NOT_SENT = 'not_sent';
    const PERMISSION_SENT = 'sent';
    const PERMISSION_SIGNED = 'signed';
    const PERMISSION_EXPIRED = 'expired';
    const PERMISSION_CHANNEL_SIGNSHYFT = 'signshyft';
    const PERMISSION_CHANNEL_MANUAL = 'manual';

    const MANUAL_HOLD_WINDOW_DAYS = 7;

    const WEBHOOK_ROUTE = 'friendshyft/v1/signshyft/webhook';

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        add_action('friendshyft_permission_tick', array(__CLASS__, 'run_permission_tick'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
    }

    /**
     * Register REST webhook receiver endpoint.
     */
    public static function register_rest_routes() {
        register_rest_route('friendshyft/v1', '/signshyft/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_signshyft_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Create a registration and mixed pending/waitlist outcomes.
     */
    public static function create_registration($payload) {
        global $wpdb;

        $event_group_id = isset($payload['event_group_id']) ? (int) $payload['event_group_id'] : 0;
        $event_group = FS_Event_Groups::get($event_group_id);
        if (!$event_group) {
            return new WP_Error('event_group_missing', 'Event group not found.');
        }

        $sessions = FS_Event_Groups::get_sessions($event_group_id);
        if (empty($sessions)) {
            return new WP_Error('event_group_empty', 'No sessions are configured for this event group.');
        }

        $selection = self::normalize_selection($payload, $event_group, $sessions);
        if (is_wp_error($selection)) {
            return $selection;
        }

        $volunteer = self::upsert_volunteer_by_email(array(
            'name' => $payload['teen_name'] ?? '',
            'email' => $payload['teen_email'] ?? '',
            'phone' => $payload['teen_phone'] ?? '',
            'birthdate' => $payload['teen_birthdate'] ?? '',
        ));
        if (is_wp_error($volunteer)) {
            return $volunteer;
        }

        $requires_permission = self::requires_permission($volunteer, $event_group);
        if ($requires_permission && empty($payload['guardian_email'])) {
            return new WP_Error('guardian_required', 'Guardian email is required for minor or unknown-age registrations.');
        }

        $now = current_time('mysql');
        $registration_data = array(
            'event_group_id' => (int) $event_group_id,
            'volunteer_id' => (int) $volunteer->id,
            'guardian_email' => sanitize_email($payload['guardian_email'] ?? ''),
            'guardian_name' => sanitize_text_field($payload['guardian_name'] ?? ''),
            'guardian_phone' => sanitize_text_field($payload['guardian_phone'] ?? ''),
            'status' => self::STATUS_ACTIVE,
            'permission_status' => $requires_permission ? self::PERMISSION_NOT_SENT : self::PERMISSION_NOT_REQUIRED,
            'permission_channel' => self::PERMISSION_CHANNEL_SIGNSHYFT,
            'template_scope' => self::get_permission_scope(),
            'template_scope_ref_id' => (string) $event_group_id,
            'created_at' => $now,
            'updated_at' => $now,
        );

        $inserted = $wpdb->insert($wpdb->prefix . 'fs_event_registrations', $registration_data);
        if (!$inserted) {
            return new WP_Error('registration_create_failed', 'Could not create registration authority object.');
        }

        $registration_id = (int) $wpdb->insert_id;

        $held = array();
        $waitlisted = array();

        foreach ($selection as $session) {
            $available = self::session_has_remaining_capacity($session['opportunity_id'], $session['shift_id']);
            if ($available) {
                $signup_result = FS_Signup::create(
                    (int) $volunteer->id,
                    (int) $session['opportunity_id'],
                    $session['shift_id'],
                    'pending',
                    $registration_id
                );

                if (is_array($signup_result) && !empty($signup_result['success'])) {
                    $held[] = $session;
                } else {
                    $waitlisted[] = self::insert_waitlist_entry($volunteer->id, $session, $registration_id);
                }
                continue;
            }

            $waitlisted[] = self::insert_waitlist_entry($volunteer->id, $session, $registration_id);
        }

        $permission_status = $registration_data['permission_status'];
        $permission_channel = self::PERMISSION_CHANNEL_SIGNSHYFT;
        $permission_request_state = 'not_required';

        if ($requires_permission && !empty($held)) {
            $permission_result = self::trigger_permission_with_fallback($registration_id);
            if (is_wp_error($permission_result)) {
                return $permission_result;
            }

            $permission_status = $permission_result['permission_status'];
            $permission_channel = $permission_result['permission_channel'];
            $permission_request_state = $permission_result['request_state'];
        } elseif ($requires_permission) {
            $permission_status = self::PERMISSION_NOT_SENT;
            $permission_channel = self::PERMISSION_CHANNEL_SIGNSHYFT;
            $permission_request_state = !empty($held) ? 'queued' : 'awaiting_hold_capacity';
        }

        self::send_teen_submission_email($volunteer, $event_group, $held, $waitlisted, $requires_permission);

        return array(
            'registration_id' => $registration_id,
            'held_sessions' => $held,
            'waitlisted_sessions' => $waitlisted,
            'permission_required' => $requires_permission,
            'permission_status' => $permission_status,
            'permission_channel' => $permission_channel,
            'permission_request_state' => $permission_request_state,
            'expires_at' => self::get_permission_expiry_for_registration($registration_id),
        );
    }

    /**
     * Trigger SignShyft envelope creation/resend for a registration.
     */
    public static function trigger_permission_workflow($registration_id, $force_resend = false) {
        global $wpdb;

        $registration = self::get_registration_with_context($registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        if ($registration->permission_status === self::PERMISSION_SIGNED) {
            return array('already_signed' => true);
        }

        $pending_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fs_signups
             WHERE registration_id = %d AND status = 'pending'",
            (int) $registration_id
        ));
        if ($pending_count < 1) {
            return new WP_Error('no_pending_holds', 'No pending holds exist for permission workflow.');
        }

        if (!FS_SignShyft_Client::is_configured()) {
            return new WP_Error('signshyft_not_configured', 'SignShyft integration is not configured.');
        }

        $hold_window_hours = self::get_hold_window_hours();
        $expires_unix = time() + ($hold_window_hours * HOUR_IN_SECONDS);
        $expires_mysql = get_date_from_gmt(gmdate('Y-m-d H:i:s', $expires_unix));
        $expires_iso = gmdate('c', $expires_unix);

        $response = null;
        if (!empty($registration->signshyft_envelope_id) && !empty($registration->signshyft_recipient_id) && ($registration->permission_status === self::PERMISSION_SENT || $force_resend)) {
            $response = FS_SignShyft_Client::resend_recipient_link(
                $registration->signshyft_envelope_id,
                $registration->signshyft_recipient_id,
                !empty($registration->guardian_phone) ? 'SMS' : 'EMAIL'
            );
        } else {
            $template_version_id = self::resolve_template_version_id($registration);
            if (empty($template_version_id)) {
                return new WP_Error('template_missing', 'No SignShyft template version is configured.');
            }

            $session_ref = self::get_registration_session_refs($registration_id);
            $payload = array(
                'templateVersionId' => $template_version_id,
                'expiresAt' => $expires_iso,
                'externalRef' => array(
                    'registration_id' => (string) $registration_id,
                    'volunteer_id' => (string) $registration->volunteer_id,
                    'event_group_id' => (string) $registration->event_group_id,
                    'session_refs' => $session_ref,
                ),
                'recipients' => array(
                    array(
                        'role' => 'guardian',
                        'signingMode' => 'ORDERED',
                        'orderIndex' => 1,
                        'name' => (string) $registration->guardian_name,
                        'email' => (string) $registration->guardian_email,
                        'phone' => (string) $registration->guardian_phone,
                        'otpPolicy' => !empty($registration->guardian_phone) ? 'SMS' : 'EMAIL',
                    ),
                ),
            );
            $response = FS_SignShyft_Client::create_envelope($payload);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        if (!is_array($response)) {
            return new WP_Error('signshyft_response_invalid', 'Unexpected SignShyft response.');
        }

        if (isset($response['success']) && $response['success'] === false) {
            return new WP_Error('signshyft_refusal', 'SignShyft refused request.', $response);
        }

        $envelope_id = !empty($response['id']) ? $response['id'] : $registration->signshyft_envelope_id;
        $recipient_data = self::extract_guardian_recipient($response, $registration->signshyft_recipient_id);

        if (is_wp_error($recipient_data)) {
            return $recipient_data;
        }

        $wpdb->update(
            $wpdb->prefix . 'fs_event_registrations',
            array(
                'permission_status' => self::PERMISSION_SENT,
                'permission_channel' => self::PERMISSION_CHANNEL_SIGNSHYFT,
                'permission_expires_at' => $expires_mysql,
                'reminder_24h_sent_at' => null,
                'reminder_final_sent_at' => null,
                'signshyft_envelope_id' => $envelope_id,
                'signshyft_recipient_id' => $recipient_data['recipient_id'],
                'signshyft_status' => isset($response['status']) ? sanitize_text_field($response['status']) : 'SENT',
                'manual_signer_url' => null,
                'manual_request_sent_at' => null,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $registration_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        self::send_guardian_permission_email('request', $registration, $recipient_data['signer_url'], $expires_mysql);

        return array(
            'envelope_id' => $envelope_id,
            'recipient_id' => $recipient_data['recipient_id'],
            'permission_expires_at' => $expires_mysql,
        );
    }

    /**
     * Trigger permission request and automatically move to manual fallback when needed.
     */
    private static function trigger_permission_with_fallback($registration_id, $force_resend = false) {
        $registration = self::get_registration_with_context($registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        if (($registration->permission_channel ?? self::PERMISSION_CHANNEL_SIGNSHYFT) === self::PERMISSION_CHANNEL_MANUAL) {
            return array(
                'permission_status' => $registration->permission_status ?: self::PERMISSION_NOT_SENT,
                'permission_channel' => self::PERMISSION_CHANNEL_MANUAL,
                'request_state' => $registration->permission_status === self::PERMISSION_SENT
                    ? 'manual_sent'
                    : 'manual_pending_staff',
            );
        }

        $result = self::trigger_permission_workflow($registration_id, $force_resend);
        if (!is_wp_error($result)) {
            return array(
                'permission_status' => self::PERMISSION_SENT,
                'permission_channel' => self::PERMISSION_CHANNEL_SIGNSHYFT,
                'request_state' => 'sent',
            );
        }

        if (!self::should_use_manual_fallback($result)) {
            return $result;
        }

        $fallback_result = self::activate_manual_permission_fallback($registration_id, $result->get_error_code());
        if (is_wp_error($fallback_result)) {
            return $fallback_result;
        }

        return array(
            'permission_status' => self::PERMISSION_NOT_SENT,
            'permission_channel' => self::PERMISSION_CHANNEL_MANUAL,
            'request_state' => 'manual_pending_staff',
        );
    }

    /**
     * True when SignShyft errors should use manual fallback instead of hard-failing signup.
     */
    private static function should_use_manual_fallback($error) {
        if (!is_wp_error($error)) {
            return false;
        }

        $fallback_codes = array(
            'signshyft_not_configured',
            'signshyft_http_error',
            'signshyft_invalid_json',
            'signshyft_response_invalid',
            'signshyft_refusal',
            'template_missing',
            'signer_link_missing',
            'http_request_failed',
        );

        return in_array($error->get_error_code(), $fallback_codes, true);
    }

    /**
     * Switch registration into manual permission mode and notify staff.
     */
    private static function activate_manual_permission_fallback($registration_id, $reason_code = '') {
        global $wpdb;

        $registration = self::get_registration_with_context($registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        $expires_mysql = self::get_manual_expiry_for_registration($registration);
        if (is_wp_error($expires_mysql)) {
            return $expires_mysql;
        }

        $wpdb->update(
            $wpdb->prefix . 'fs_event_registrations',
            array(
                'permission_channel' => self::PERMISSION_CHANNEL_MANUAL,
                'permission_status' => self::PERMISSION_NOT_SENT,
                'permission_expires_at' => $expires_mysql,
                'manual_request_sent_at' => null,
                'reminder_24h_sent_at' => null,
                'reminder_final_sent_at' => null,
                'signshyft_status' => 'MANUAL_FALLBACK',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $registration_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        $registration = self::get_registration_with_context($registration_id);
        if (class_exists('FS_Notifications') && $registration) {
            FS_Notifications::send_staff_manual_permission_required_notification($registration, (string) $reason_code);
        }

        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log(
                'manual_permission_fallback_required',
                'registration',
                (int) $registration_id,
                array(
                    'reason_code' => (string) $reason_code,
                    'permission_expires_at' => $expires_mysql,
                )
            );
        }

        return array('success' => true);
    }

    /**
     * Run reminder and expiration maintenance.
     */
    public static function run_permission_tick() {
        self::send_due_permission_reminders();
        self::expire_unsigned_permissions();
        self::cleanup_old_webhook_deliveries();
    }

    /**
     * Send T-24h and final reminders for sent permissions.
     */
    public static function send_due_permission_reminders() {
        global $wpdb;

        $registrations = $wpdb->get_results(
            "SELECT r.*, v.name AS teen_name, g.title AS event_group_title
             FROM {$wpdb->prefix}fs_event_registrations r
             LEFT JOIN {$wpdb->prefix}fs_volunteers v ON v.id = r.volunteer_id
             LEFT JOIN {$wpdb->prefix}fs_event_groups g ON g.id = r.event_group_id
             WHERE r.status = 'active'
               AND r.permission_status = 'sent'
               AND r.permission_expires_at IS NOT NULL"
        );

        if (empty($registrations)) {
            return;
        }

        $now = time();
        foreach ($registrations as $registration) {
            $expires_ts = strtotime(get_gmt_from_date($registration->permission_expires_at));
            if (!$expires_ts || $expires_ts <= $now) {
                continue;
            }

            $hours_left = ($expires_ts - $now) / HOUR_IN_SECONDS;
            $final_hours = self::get_final_reminder_hours($registration);

            if ($hours_left <= 24 && empty($registration->reminder_24h_sent_at)) {
                $sent = self::send_guardian_reminder_for_registration($registration, '24h');
                if ($sent) {
                    $wpdb->update(
                        $wpdb->prefix . 'fs_event_registrations',
                        array('reminder_24h_sent_at' => current_time('mysql'), 'updated_at' => current_time('mysql')),
                        array('id' => (int) $registration->id),
                        array('%s', '%s'),
                        array('%d')
                    );
                }
            }

            if ($hours_left <= $final_hours && empty($registration->reminder_final_sent_at)) {
                $sent = self::send_guardian_reminder_for_registration($registration, 'final');
                if ($sent) {
                    $wpdb->update(
                        $wpdb->prefix . 'fs_event_registrations',
                        array('reminder_final_sent_at' => current_time('mysql'), 'updated_at' => current_time('mysql')),
                        array('id' => (int) $registration->id),
                        array('%s', '%s'),
                        array('%d')
                    );
                }
            }
        }
    }

    /**
     * Expire unsigned permissions and release held spots.
     */
    public static function expire_unsigned_permissions() {
        global $wpdb;

        $expired_registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$wpdb->prefix}fs_event_registrations
             WHERE status = %s
               AND permission_expires_at IS NOT NULL
               AND permission_expires_at <= %s
               AND (
                    (COALESCE(permission_channel, %s) = %s AND permission_status IN (%s, %s))
                    OR
                    (COALESCE(permission_channel, %s) <> %s AND permission_status = %s)
               )",
            self::STATUS_ACTIVE,
            current_time('mysql'),
            self::PERMISSION_CHANNEL_SIGNSHYFT,
            self::PERMISSION_CHANNEL_MANUAL,
            self::PERMISSION_NOT_SENT,
            self::PERMISSION_SENT,
            self::PERMISSION_CHANNEL_SIGNSHYFT,
            self::PERMISSION_CHANNEL_MANUAL,
            self::PERMISSION_SENT
        ));

        if (empty($expired_registrations)) {
            return;
        }

        foreach ($expired_registrations as $registration) {
            self::expire_registration((int) $registration->id);
        }
    }

    /**
     * Expire one registration and clean dependent rows.
     */
    public static function expire_registration($registration_id) {
        global $wpdb;

        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT id, opportunity_id, shift_id, status
             FROM {$wpdb->prefix}fs_signups
             WHERE registration_id = %d",
            (int) $registration_id
        ));

        foreach ($signups as $signup) {
            if ($signup->status !== 'pending') {
                continue;
            }

            $wpdb->update(
                $wpdb->prefix . 'fs_signups',
                array(
                    'status' => 'expired',
                    'cancelled_date' => current_time('mysql'),
                ),
                array('id' => (int) $signup->id),
                array('%s', '%s'),
                array('%d')
            );

            if (!empty($signup->shift_id)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}fs_opportunity_shifts
                     SET spots_filled = GREATEST(0, spots_filled - 1)
                     WHERE id = %d",
                    (int) $signup->shift_id
                ));
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fs_opportunities
                 SET spots_filled = GREATEST(0, spots_filled - 1)
                 WHERE id = %d",
                (int) $signup->opportunity_id
            ));
        }

        $wpdb->delete(
            $wpdb->prefix . 'fs_waitlist',
            array('registration_id' => (int) $registration_id),
            array('%d')
        );

        $wpdb->update(
            $wpdb->prefix . 'fs_event_registrations',
            array(
                'status' => self::STATUS_EXPIRED,
                'permission_status' => self::PERMISSION_EXPIRED,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $registration_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Decide if signup confirmation should be blocked.
     */
    public static function should_block_confirmation($signup) {
        global $wpdb;

        $registration = null;
        $registration_permission_allows_confirmation = false;
        if (!empty($signup->registration_id)) {
            $registration = self::get_registration_for_confirmation_gate((int) $signup->registration_id);
        } elseif (!empty($signup->volunteer_id) && !empty($signup->opportunity_id)) {
            $registration = self::find_active_registration_for_opportunity((int) $signup->volunteer_id, (int) $signup->opportunity_id);
            if ($registration && !empty($signup->id)) {
                self::reconcile_registration_entries((int) $registration->id);
                $registration = self::get_registration_for_confirmation_gate((int) $registration->id);
            }
        }

        if ($registration && in_array($registration->permission_status, array(self::PERMISSION_SIGNED, self::PERMISSION_NOT_REQUIRED), true)) {
            $registration_permission_allows_confirmation = true;
        }

        if ($registration && !$registration_permission_allows_confirmation) {
            return array(
                'blocked' => true,
                'message' => 'Guardian permission must be signed before this signup can be confirmed.',
            );
        }

        $is_minor = self::is_minor_or_unknown($signup->birthdate ?? null, self::get_minor_age_threshold_default());
        if ($is_minor && !$registration_permission_allows_confirmation) {
            return array(
                'blocked' => true,
                'message' => 'Under-18 or unknown-age volunteer requires guardian permission before confirmation.',
            );
        }

        return array('blocked' => false, 'message' => '');
    }

    /**
     * Find the active teen registration governing a grouped opportunity.
     */
    public static function find_active_registration_for_opportunity($volunteer_id, $opportunity_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*
             FROM {$wpdb->prefix}fs_event_registrations r
             INNER JOIN {$wpdb->prefix}fs_opportunities o ON o.event_group_id = r.event_group_id
             WHERE r.volunteer_id = %d
               AND o.id = %d
               AND r.status = %s
             ORDER BY r.created_at DESC
             LIMIT 1",
            (int) $volunteer_id,
            (int) $opportunity_id,
            self::STATUS_ACTIVE
        ));
    }

    /**
     * Adopt manual signups/waitlist entries back into their teen registration authority object.
     */
    public static function reconcile_registration_entries($registration_id) {
        global $wpdb;

        $registration = self::get_registration_with_context((int) $registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        self::ensure_volunteer_active((int) $registration->volunteer_id);

        $opportunity_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id
             FROM {$wpdb->prefix}fs_opportunities
             WHERE event_group_id = %d",
            (int) $registration->event_group_id
        ));

        if (empty($opportunity_ids)) {
            return array(
                'linked_signups' => 0,
                'linked_waitlist' => 0,
            );
        }

        $placeholders = implode(', ', array_fill(0, count($opportunity_ids), '%d'));

        $signup_query = "UPDATE {$wpdb->prefix}fs_signups
                         SET registration_id = %d
                         WHERE registration_id IS NULL
                           AND volunteer_id = %d
                           AND opportunity_id IN ($placeholders)
                           AND status NOT IN ('cancelled', 'expired')";
        $signup_params = array_merge(
            array((int) $registration_id, (int) $registration->volunteer_id),
            array_map('intval', $opportunity_ids)
        );
        $linked_signups = $wpdb->query($wpdb->prepare($signup_query, ...$signup_params));

        $waitlist_query = "UPDATE {$wpdb->prefix}fs_waitlist
                           SET registration_id = %d
                           WHERE registration_id IS NULL
                             AND volunteer_id = %d
                             AND opportunity_id IN ($placeholders)
                             AND status = 'waiting'";
        $waitlist_params = array_merge(
            array((int) $registration_id, (int) $registration->volunteer_id),
            array_map('intval', $opportunity_ids)
        );
        $linked_waitlist = $wpdb->query($wpdb->prepare($waitlist_query, ...$waitlist_params));

        if (self::should_confirm_after_permission_signed($registration)) {
            $sync_result = self::sync_registration_after_permission_signed((int) $registration_id);
            if (is_wp_error($sync_result) && class_exists('FS_Audit_Log')) {
                FS_Audit_Log::log(
                    'registration_reconcile_sync_failed',
                    'registration',
                    (int) $registration_id,
                    array('error_code' => $sync_result->get_error_code())
                );
            }
        }

        return array(
            'linked_signups' => max(0, (int) $linked_signups),
            'linked_waitlist' => max(0, (int) $linked_waitlist),
        );
    }

    /**
     * Global helper for waitlist autopromotion safety.
     */
    public static function should_skip_auto_promotion_for_volunteer($volunteer_id) {
        global $wpdb;

        $birthdate = $wpdb->get_var($wpdb->prepare(
            "SELECT birthdate FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            (int) $volunteer_id
        ));

        return self::is_minor_or_unknown($birthdate, self::get_minor_age_threshold_default());
    }

    /**
     * Return registrations requiring staff triage.
     */
    public static function get_minor_permission_triage_rows() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT r.*, v.name AS teen_name, v.birthdate, g.title AS event_group_title
             FROM {$wpdb->prefix}fs_event_registrations r
             LEFT JOIN {$wpdb->prefix}fs_volunteers v ON v.id = r.volunteer_id
             LEFT JOIN {$wpdb->prefix}fs_event_groups g ON g.id = r.event_group_id
             WHERE r.status = 'active'
               AND (v.birthdate IS NULL OR r.permission_status IN ('not_sent','sent'))
             ORDER BY r.created_at DESC"
        );
    }

    /**
     * Handle inbound SignShyft webhook with v1 signature verification + idempotency.
     */
    public static function handle_signshyft_webhook($request) {
        $raw_body = $request->get_body();
        $headers = $request->get_headers();

        $verification = self::verify_webhook_signature($raw_body, $headers);
        if (is_wp_error($verification)) {
            return new WP_REST_Response(array('ok' => false, 'error' => $verification->get_error_code()), (int) $verification->get_error_data('status') ?: 401);
        }

        $delivery_id = $verification['delivery_id'];
        if (self::has_processed_delivery($delivery_id)) {
            return new WP_REST_Response(array('ok' => true, 'duplicate' => true), 200);
        }

        $payload = json_decode($raw_body, true);
        if (!is_array($payload)) {
            self::record_webhook_delivery($delivery_id, $verification['event_type'], '', 400, 'malformed_json', false);
            return new WP_REST_Response(array('ok' => false, 'error' => 'malformed_json'), 400);
        }

        $envelope_id = isset($payload['envelopeId']) ? sanitize_text_field($payload['envelopeId']) : '';
        $event_type = isset($payload['eventType']) ? sanitize_text_field($payload['eventType']) : $verification['event_type'];

        $processed = self::process_webhook_payload($payload);
        if (is_wp_error($processed)) {
            self::record_webhook_delivery($delivery_id, $event_type, $envelope_id, 500, $processed->get_error_code(), false);
            return new WP_REST_Response(array('ok' => false, 'error' => $processed->get_error_code()), 500);
        }

        self::record_webhook_delivery($delivery_id, $event_type, $envelope_id, 200, '', true);
        return new WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Fetch a registration with joined display context.
     */
    public static function get_registration_with_context($registration_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, v.name AS teen_name, v.email AS teen_email, v.phone AS teen_phone, v.birthdate, v.volunteer_status,
                    g.title AS event_group_title, g.signshyft_template_version_id, g.reminder_final_hours
             FROM {$wpdb->prefix}fs_event_registrations r
             LEFT JOIN {$wpdb->prefix}fs_volunteers v ON v.id = r.volunteer_id
             LEFT JOIN {$wpdb->prefix}fs_event_groups g ON g.id = r.event_group_id
             WHERE r.id = %d",
            (int) $registration_id
        ));
    }

    /**
     * List registrations for admin table.
     */
    public static function list_registrations($filters = array()) {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (!empty($filters['permission_status'])) {
            $where[] = 'r.permission_status = %s';
            $params[] = $filters['permission_status'];
        }

        if (!empty($filters['event_group_id'])) {
            $where[] = 'r.event_group_id = %d';
            $params[] = (int) $filters['event_group_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'r.status = %s';
            $params[] = $filters['status'];
        }

        $sql = "SELECT r.*, v.name AS teen_name, v.birthdate, g.title AS event_group_title,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups s WHERE s.registration_id = r.id AND s.status = 'pending') AS pending_count,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}fs_waitlist w WHERE w.registration_id = r.id AND w.status = 'waiting') AS waitlist_count
                FROM {$wpdb->prefix}fs_event_registrations r
                LEFT JOIN {$wpdb->prefix}fs_volunteers v ON v.id = r.volunteer_id
                LEFT JOIN {$wpdb->prefix}fs_event_groups g ON g.id = r.event_group_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.created_at DESC";

        if (empty($params)) {
            return $wpdb->get_results($sql);
        }

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Promote one waitlist entry to pending hold and trigger permission if needed.
     */
    public static function promote_waitlist_entry($waitlist_id) {
        global $wpdb;

        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_waitlist WHERE id = %d AND status = 'waiting'",
            (int) $waitlist_id
        ));
        if (!$entry) {
            return new WP_Error('waitlist_not_found', 'Waitlist entry not found.');
        }

        $remaining = self::session_has_remaining_capacity($entry->opportunity_id, $entry->shift_id);
        if (!$remaining) {
            return new WP_Error('no_capacity', 'No remaining spots available for this session.');
        }

        $registration = null;
        $signup_status = 'pending';
        if (!empty($entry->registration_id)) {
            self::ensure_volunteer_active((int) $entry->volunteer_id);
            $registration = self::get_registration_with_context((int) $entry->registration_id);
            if ($registration && self::should_confirm_after_permission_signed($registration)) {
                $signup_status = 'confirmed';
            }
        }

        $signup_result = FS_Signup::create(
            (int) $entry->volunteer_id,
            (int) $entry->opportunity_id,
            !empty($entry->shift_id) ? (int) $entry->shift_id : null,
            $signup_status,
            !empty($entry->registration_id) ? (int) $entry->registration_id : null
        );
        if (empty($signup_result['success'])) {
            return new WP_Error('promote_failed', isset($signup_result['message']) ? $signup_result['message'] : 'Could not promote waitlist entry.');
        }

        $wpdb->update(
            $wpdb->prefix . 'fs_waitlist',
            array('status' => 'promoted', 'promoted_at' => current_time('mysql')),
            array('id' => (int) $entry->id),
            array('%s', '%s'),
            array('%d')
        );

        if (!empty($entry->registration_id) && $signup_status === 'pending') {
            if (!$registration) {
                $registration = self::get_registration_with_context((int) $entry->registration_id);
            }

            if ($registration && self::requires_permission((object) array('birthdate' => $registration->birthdate), $registration)) {
                $permission_result = self::trigger_permission_with_fallback((int) $entry->registration_id);
                if (is_wp_error($permission_result) && class_exists('FS_Audit_Log')) {
                    FS_Audit_Log::log(
                        'permission_trigger_failed_after_waitlist_promotion',
                        'registration',
                        (int) $entry->registration_id,
                        array('error_code' => $permission_result->get_error_code())
                    );
                }
            }
        }

        return array(
            'success' => true,
            'signup_status' => $signup_status,
        );
    }

    /**
     * Send or resend a manual permission request using a staff-provided signer URL.
     */
    public static function send_manual_permission_request($registration_id, $manual_signer_url = '') {
        global $wpdb;

        $registration = self::get_registration_with_context($registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        if ($registration->permission_status === self::PERMISSION_SIGNED) {
            return new WP_Error('already_signed', 'Permission is already marked as signed.');
        }

        $pending_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}fs_signups
             WHERE registration_id = %d AND status = 'pending'",
            (int) $registration_id
        ));
        if ($pending_count < 1) {
            return new WP_Error('no_pending_holds', 'No pending holds exist for permission workflow.');
        }

        $manual_signer_url = trim((string) $manual_signer_url);
        if ($manual_signer_url === '' && !empty($registration->manual_signer_url)) {
            $manual_signer_url = (string) $registration->manual_signer_url;
        }
        $manual_signer_url = esc_url_raw($manual_signer_url);
        if (empty($manual_signer_url) || !wp_http_validate_url($manual_signer_url)) {
            return new WP_Error('manual_signer_url_invalid', 'A valid third-party signer URL is required.');
        }

        $expires_mysql = !empty($registration->permission_expires_at)
            ? $registration->permission_expires_at
            : self::get_manual_expiry_for_registration($registration);
        if (is_wp_error($expires_mysql)) {
            return $expires_mysql;
        }

        $is_resend = !empty($registration->manual_request_sent_at);
        $update_data = array(
            'permission_channel' => self::PERMISSION_CHANNEL_MANUAL,
            'permission_status' => self::PERMISSION_SENT,
            'manual_signer_url' => $manual_signer_url,
            'manual_request_sent_at' => current_time('mysql'),
            'permission_expires_at' => $expires_mysql,
            'updated_at' => current_time('mysql'),
        );

        if (!$is_resend) {
            $update_data['reminder_24h_sent_at'] = null;
            $update_data['reminder_final_sent_at'] = null;
        }

        $wpdb->update(
            $wpdb->prefix . 'fs_event_registrations',
            $update_data,
            array('id' => (int) $registration_id)
        );

        $registration = self::get_registration_with_context($registration_id);
        self::send_guardian_permission_email('request', $registration, $manual_signer_url, $expires_mysql);

        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log(
                $is_resend ? 'manual_permission_request_resent' : 'manual_permission_request_sent',
                'registration',
                (int) $registration_id,
                array(
                    'permission_expires_at' => $expires_mysql,
                    'has_manual_signer_url' => true,
                )
            );
        }

        return array(
            'success' => true,
            'is_resend' => $is_resend,
            'permission_status' => self::PERMISSION_SENT,
            'permission_channel' => self::PERMISSION_CHANNEL_MANUAL,
            'expires_at' => $expires_mysql,
        );
    }

    /**
     * Mark manual permission as signed and persist the uploaded PDF in private storage.
     */
    public static function mark_manual_permission_signed($registration_id, $uploaded_file) {
        global $wpdb;

        $registration = self::get_registration_with_context($registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        if ($registration->permission_status === self::PERMISSION_SIGNED) {
            return new WP_Error('already_signed', 'Permission is already marked as signed.');
        }

        if (!is_array($uploaded_file) || empty($uploaded_file['tmp_name']) || !isset($uploaded_file['error'])) {
            return new WP_Error('manual_pdf_missing', 'Signed PDF upload is required.');
        }
        if ((int) $uploaded_file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('manual_pdf_upload_failed', 'Signed PDF upload failed.');
        }

        $original_name = isset($uploaded_file['name']) ? sanitize_file_name($uploaded_file['name']) : '';
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            return new WP_Error('manual_pdf_invalid_type', 'Signed file must be a PDF.');
        }

        if (!function_exists('wp_check_filetype_and_ext')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file_info = wp_check_filetype_and_ext($uploaded_file['tmp_name'], $original_name);
        if (($file_info['ext'] ?? '') !== 'pdf') {
            return new WP_Error('manual_pdf_invalid_type', 'Signed file must be a valid PDF.');
        }

        $stored = self::store_manual_signed_pdf((int) $registration_id, $uploaded_file);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $wpdb->update(
            $wpdb->prefix . 'fs_event_registrations',
            array(
                'permission_channel' => self::PERMISSION_CHANNEL_MANUAL,
                'permission_status' => self::PERMISSION_SIGNED,
                'permission_signed_at' => current_time('mysql'),
                'manual_signed_document_path' => $stored['relative_path'],
                'document_sha256' => $stored['sha256'],
                'signshyft_status' => 'MANUAL_COMPLETED',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $registration_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        $sync_result = self::sync_registration_after_permission_signed((int) $registration_id);
        if (is_wp_error($sync_result)) {
            if (class_exists('FS_Audit_Log')) {
                FS_Audit_Log::log(
                    'registration_sync_after_manual_permission_failed',
                    'registration',
                    (int) $registration_id,
                    array('error_code' => $sync_result->get_error_code())
                );
            }

            $sync_result = array(
                'confirmed_count' => 0,
                'promoted_count' => 0,
            );
        }

        $registration = self::get_registration_with_context($registration_id);
        if (class_exists('FS_Notifications') && $registration) {
            FS_Notifications::send_staff_permission_signed_notification($registration);
        }

        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log(
                'manual_permission_marked_signed',
                'registration',
                (int) $registration_id,
                array(
                    'manual_signed_document_path' => $stored['relative_path'],
                    'document_sha256' => $stored['sha256'],
                )
            );
        }

        return array(
            'success' => true,
            'confirmed_count' => (int) ($sync_result['confirmed_count'] ?? 0),
            'promoted_count' => (int) ($sync_result['promoted_count'] ?? 0),
        );
    }

    /**
     * Return absolute file path for an uploaded manual signed PDF.
     */
    public static function get_manual_signed_document_for_download($registration_id) {
        $registration = self::get_registration_with_context($registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        if (empty($registration->manual_signed_document_path)) {
            return new WP_Error('manual_pdf_not_found', 'No manual signed PDF is available for this registration.');
        }

        $absolute_path = self::resolve_private_storage_path($registration->manual_signed_document_path);
        if (is_wp_error($absolute_path)) {
            return $absolute_path;
        }

        if (!file_exists($absolute_path)) {
            return new WP_Error('manual_pdf_not_found', 'Signed PDF file could not be found.');
        }

        return array(
            'absolute_path' => $absolute_path,
            'filename' => 'permission-' . (int) $registration_id . '-manual.pdf',
        );
    }

    /**
     * Parse and validate selection by event group selection_mode.
     */
    private static function normalize_selection($payload, $event_group, $sessions) {
        $selection_mode = strtoupper((string) $event_group->selection_mode);
        $selected_keys = array();

        if ($selection_mode === FS_Event_Groups::SELECTION_ALL) {
            return $sessions;
        }

        if ($selection_mode === FS_Event_Groups::SELECTION_DAYS_ONLY) {
            $selected_days = isset($payload['selected_days']) ? (array) $payload['selected_days'] : array();
            $selected_days = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_days))));

            if (empty($selected_days)) {
                return new WP_Error('selection_required', 'Select at least one day.');
            }

            foreach ($selected_days as $day) {
                $day_sessions = array_values(array_filter($sessions, function($session) use ($day) {
                    return $session['event_date'] === $day;
                }));

                if (count($day_sessions) !== 1) {
                    return new WP_Error('days_mode_invalid', 'Days-only mode requires exactly one session per selected day.');
                }

                $selected_keys[] = $day_sessions[0]['session_key'];
            }
        } else {
            $selected_keys = isset($payload['selected_sessions']) ? (array) $payload['selected_sessions'] : array();
            $selected_keys = array_values(array_unique(array_filter(array_map('sanitize_text_field', $selected_keys))));
        }

        if (empty($selected_keys)) {
            return new WP_Error('selection_required', 'Select at least one session.');
        }

        $session_map = array();
        foreach ($sessions as $session) {
            $session_map[$session['session_key']] = $session;
        }

        $selected = array();
        foreach ($selected_keys as $key) {
            if (!isset($session_map[$key])) {
                return new WP_Error('selection_invalid', 'One or more selected sessions is invalid.');
            }
            $selected[] = $session_map[$key];
        }

        $min_select = $event_group->min_select !== null ? (int) $event_group->min_select : 0;
        $max_select = $event_group->max_select !== null ? (int) $event_group->max_select : 0;

        if ($min_select > 0 && count($selected) < $min_select) {
            return new WP_Error('selection_too_small', 'Not enough sessions selected.');
        }
        if ($max_select > 0 && count($selected) > $max_select) {
            return new WP_Error('selection_too_large', 'Too many sessions selected.');
        }

        return $selected;
    }

    /**
     * Minor policy: unknown birthdate is always minor.
     */
    public static function is_minor_or_unknown($birthdate, $threshold) {
        if (empty($birthdate)) {
            return true;
        }

        $birth_ts = strtotime($birthdate);
        if (!$birth_ts) {
            return true;
        }

        $today = new DateTimeImmutable(current_time('Y-m-d'));
        $dob = new DateTimeImmutable(date('Y-m-d', $birth_ts));
        $age = (int) $dob->diff($today)->y;

        return $age < (int) $threshold;
    }

    /**
     * Decide if guardian permission is required.
     */
    private static function requires_permission($volunteer, $event_group) {
        $threshold = !empty($event_group->minor_age_threshold) ? (int) $event_group->minor_age_threshold : self::get_minor_age_threshold_default();
        $minor_or_unknown = self::is_minor_or_unknown($volunteer->birthdate ?? null, $threshold);

        // Locked policy: minors and unknown ages always require permission.
        if ($minor_or_unknown) {
            return true;
        }

        // Event-level override can require permission for all registrants.
        return !empty($event_group->requires_minor_permission);
    }

    /**
     * Insert waitlist row for teen registration with FIFO policy.
     */
    private static function insert_waitlist_entry($volunteer_id, $session, $registration_id) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'fs_waitlist',
            array(
                'volunteer_id' => (int) $volunteer_id,
                'opportunity_id' => (int) $session['opportunity_id'],
                'shift_id' => $session['shift_id'] !== null ? (int) $session['shift_id'] : null,
                'registration_id' => (int) $registration_id,
                'rank_score' => 0,
                'priority_level' => 'normal',
                'joined_at' => current_time('mysql'),
                'status' => 'waiting',
            ),
            array('%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        return $session;
    }

    /**
     * Determine whether a session has remaining capacity right now.
     */
    private static function session_has_remaining_capacity($opportunity_id, $shift_id = null) {
        global $wpdb;

        if (!empty($shift_id)) {
            $shift = $wpdb->get_row($wpdb->prepare(
                "SELECT spots_available, spots_filled
                 FROM {$wpdb->prefix}fs_opportunity_shifts
                 WHERE id = %d",
                (int) $shift_id
            ));
            if (!$shift) {
                return false;
            }

            return ((int) $shift->spots_available - (int) $shift->spots_filled) > 0;
        }

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT spots_available, spots_filled
             FROM {$wpdb->prefix}fs_opportunities
             WHERE id = %d",
            (int) $opportunity_id
        ));
        if (!$opportunity) {
            return false;
        }

        return ((int) $opportunity->spots_available - (int) $opportunity->spots_filled) > 0;
    }

    /**
     * Upsert volunteer by exact email with safe merge rules.
     */
    private static function upsert_volunteer_by_email($data) {
        global $wpdb;

        $email = sanitize_email($data['email'] ?? '');
        if (empty($email) || !is_email($email)) {
            return new WP_Error('teen_email_invalid', 'Teen email is required and must be valid.');
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE email = %s LIMIT 1",
            $email
        ));

        $birthdate = !empty($data['birthdate']) ? sanitize_text_field($data['birthdate']) : null;
        $name = sanitize_text_field($data['name'] ?? '');
        $phone = sanitize_text_field($data['phone'] ?? '');

        if ($existing) {
            $update_data = array();

            if (empty($existing->name) && !empty($name)) {
                $update_data['name'] = $name;
            }
            if (empty($existing->phone) && !empty($phone)) {
                $update_data['phone'] = $phone;
            }
            if (empty($existing->birthdate) && !empty($birthdate)) {
                $update_data['birthdate'] = $birthdate;
            }
            if (self::should_activate_volunteer_status($existing->volunteer_status ?? '')) {
                $update_data['volunteer_status'] = 'Active';
            }

            if (!empty($update_data)) {
                $wpdb->update(
                    $wpdb->prefix . 'fs_volunteers',
                    $update_data,
                    array('id' => (int) $existing->id)
                );
            }

            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                (int) $existing->id
            ));
        }

        $access_token = bin2hex(random_bytes(32));
        $created = $wpdb->insert(
            $wpdb->prefix . 'fs_volunteers',
            array(
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'birthdate' => $birthdate,
                'volunteer_status' => 'Active',
                'access_token' => $access_token,
                'created_date' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$created) {
            return new WP_Error('volunteer_create_failed', 'Unable to create volunteer profile.');
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            (int) $wpdb->insert_id
        ));
    }

    /**
     * Send teen summary email for submission outcome.
     */
    private static function send_teen_submission_email($volunteer, $event_group, $held, $waitlisted, $permission_required) {
        if (!class_exists('FS_Notifications')) {
            return;
        }

        if (!empty($held)) {
            FS_Notifications::send_teen_registration_received_email($volunteer, $event_group, $held, $waitlisted, $permission_required);
            return;
        }

        FS_Notifications::send_teen_waitlist_only_email($volunteer, $event_group, $waitlisted);
    }

    /**
     * Send permission request/reminder email to guardian.
     */
    private static function send_guardian_permission_email($type, $registration, $signer_url, $expires_mysql) {
        if (!class_exists('FS_Notifications')) {
            return;
        }

        if ($type === 'final') {
            FS_Notifications::send_guardian_permission_final_reminder($registration, $signer_url, $expires_mysql);
            return;
        }

        if ($type === '24h') {
            FS_Notifications::send_guardian_permission_24h_reminder($registration, $signer_url, $expires_mysql);
            return;
        }

        FS_Notifications::send_guardian_permission_request($registration, $signer_url, $expires_mysql);
    }

    /**
     * Send reminder for a specific registration using non-rotating link fetch.
     */
    private static function send_guardian_reminder_for_registration($registration, $reminder_type) {
        $channel = !empty($registration->permission_channel) ? $registration->permission_channel : self::PERMISSION_CHANNEL_SIGNSHYFT;

        if ($channel === self::PERMISSION_CHANNEL_MANUAL) {
            if (empty($registration->manual_signer_url) || empty($registration->manual_request_sent_at)) {
                return false;
            }

            self::send_guardian_permission_email(
                $reminder_type,
                $registration,
                $registration->manual_signer_url,
                $registration->permission_expires_at
            );

            return true;
        }

        if (empty($registration->signshyft_envelope_id) || empty($registration->signshyft_recipient_id)) {
            return false;
        }

        $link = FS_SignShyft_Client::get_signer_link(
            $registration->signshyft_envelope_id,
            $registration->signshyft_recipient_id,
            false
        );
        if (is_wp_error($link)) {
            return false;
        }
        if (empty($link['signerUrl'])) {
            return false;
        }

        self::send_guardian_permission_email(
            $reminder_type,
            $registration,
            $link['signerUrl'],
            $registration->permission_expires_at
        );

        return true;
    }

    /**
     * Pull signerUrl + recipientId without persisting signerUrl.
     */
    private static function extract_guardian_recipient($response, $fallback_recipient_id = '') {
        // Resend/get-link response shape.
        if (!empty($response['signerUrl'])) {
            return array(
                'recipient_id' => !empty($response['recipientId']) ? $response['recipientId'] : $fallback_recipient_id,
                'signer_url' => $response['signerUrl'],
            );
        }

        if (!empty($response['recipients']) && is_array($response['recipients'])) {
            foreach ($response['recipients'] as $recipient) {
                if (empty($recipient['signerUrl'])) {
                    continue;
                }
                if (!empty($recipient['role']) && strtolower($recipient['role']) !== 'guardian') {
                    continue;
                }

                return array(
                    'recipient_id' => isset($recipient['recipientId']) ? $recipient['recipientId'] : $fallback_recipient_id,
                    'signer_url' => $recipient['signerUrl'],
                );
            }

            $first = $response['recipients'][0];
            if (!empty($first['signerUrl'])) {
                return array(
                    'recipient_id' => isset($first['recipientId']) ? $first['recipientId'] : $fallback_recipient_id,
                    'signer_url' => $first['signerUrl'],
                );
            }
        }

        return new WP_Error('signer_link_missing', 'No signerUrl returned by SignShyft.');
    }

    /**
     * Choose template version ID with event-group override first.
     */
    private static function resolve_template_version_id($registration) {
        if (!empty($registration->signshyft_template_version_id)) {
            return $registration->signshyft_template_version_id;
        }

        $settings = get_option('fs_teen_permission_settings', array());
        return !empty($settings['default_template_version_id']) ? $settings['default_template_version_id'] : '';
    }

    /**
     * Return refs for auditable session mapping in SignShyft externalRef.
     */
    private static function get_registration_session_refs($registration_id) {
        global $wpdb;

        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT opportunity_id, shift_id
             FROM {$wpdb->prefix}fs_signups
             WHERE registration_id = %d",
            (int) $registration_id
        ));

        $waitlist = $wpdb->get_results($wpdb->prepare(
            "SELECT opportunity_id, shift_id
             FROM {$wpdb->prefix}fs_waitlist
             WHERE registration_id = %d",
            (int) $registration_id
        ));

        $refs = array();
        foreach (array_merge($signups, $waitlist) as $row) {
            $refs[] = array(
                'opportunity_id' => (int) $row->opportunity_id,
                'shift_id' => isset($row->shift_id) ? (int) $row->shift_id : null,
            );
        }

        return $refs;
    }

    /**
     * Verify webhook v1 signature.
     */
    private static function verify_webhook_signature($raw_body, $headers) {
        $event = self::get_header_value($headers, 'x-signshyft-event');
        $delivery_id = self::get_header_value($headers, 'x-signshyft-delivery-id');
        $timestamp = self::get_header_value($headers, 'x-signshyft-timestamp');
        $signature = self::get_header_value($headers, 'x-signshyft-signature');

        if ($event === '' || $delivery_id === '' || $timestamp === '' || $signature === '') {
            return new WP_Error('missing_headers', 'Missing required signature headers.', array('status' => 401));
        }

        if (!preg_match('/^v1=([a-f0-9]{64})$/', $signature, $match)) {
            return new WP_Error('invalid_signature_format', 'Malformed signature format.', array('status' => 401));
        }

        $timestamp_int = (int) $timestamp;
        if (abs(time() - $timestamp_int) > 900) {
            return new WP_Error('timestamp_window', 'Webhook timestamp is outside allowed window.', array('status' => 401));
        }

        $body_sha = hash('sha256', $raw_body);
        $string_to_sign = "v1\n{$timestamp}\n{$delivery_id}\n{$body_sha}";

        $provided_sig = $match[1];
        $secret_current = FS_SignShyft_Client::get_webhook_secret_bytes('current');
        $secret_next = FS_SignShyft_Client::get_webhook_secret_bytes('next');
        if (!self::is_next_secret_within_grace_window()) {
            $secret_next = null;
        }

        $valid = false;
        foreach (array($secret_current, $secret_next) as $secret_bytes) {
            if (empty($secret_bytes)) {
                continue;
            }

            $computed = hash_hmac('sha256', $string_to_sign, $secret_bytes);
            if (hash_equals($computed, $provided_sig)) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            return new WP_Error('signature_mismatch', 'Signature verification failed.', array('status' => 401));
        }

        return array(
            'event_type' => $event,
            'delivery_id' => $delivery_id,
        );
    }

    /**
     * Enforce 24-hour grace period for secondary webhook secret verification.
     */
    private static function is_next_secret_within_grace_window() {
        $next_b64 = FS_SignShyft_Client::get_setting('signshyft_webhook_secret_next_b64');
        if (empty($next_b64)) {
            return false;
        }

        $activated_at = FS_SignShyft_Client::get_setting('signshyft_webhook_secret_next_activated_at');
        if (empty($activated_at)) {
            return true;
        }

        $activated_ts = strtotime($activated_at . ' UTC');
        if (!$activated_ts) {
            return true;
        }

        return (time() - $activated_ts) <= DAY_IN_SECONDS;
    }

    /**
     * Process verified payload and update registration state.
     */
    private static function process_webhook_payload($payload) {
        global $wpdb;

        $event_type = isset($payload['eventType']) ? $payload['eventType'] : '';
        $envelope_id = isset($payload['envelopeId']) ? $payload['envelopeId'] : '';
        $status = isset($payload['status']) ? $payload['status'] : '';

        $registration_id = null;
        if (!empty($payload['externalRef']) && is_array($payload['externalRef']) && !empty($payload['externalRef']['registration_id'])) {
            $registration_id = (int) $payload['externalRef']['registration_id'];
        }

        if (!$registration_id && !empty($envelope_id)) {
            $registration_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_event_registrations WHERE signshyft_envelope_id = %s LIMIT 1",
                sanitize_text_field($envelope_id)
            ));
        }

        if (!$registration_id) {
            return new WP_Error('registration_lookup_failed', 'Could not map webhook event to registration.');
        }

        $update = array(
            'permission_channel' => self::PERMISSION_CHANNEL_SIGNSHYFT,
            'signshyft_status' => sanitize_text_field($status),
            'updated_at' => current_time('mysql'),
        );

        if ($event_type === 'ENVELOPE_COMPLETED' || strtoupper($status) === 'COMPLETED') {
            $update['permission_status'] = self::PERMISSION_SIGNED;
            $update['permission_signed_at'] = !empty($payload['completedAt']) ? gmdate('Y-m-d H:i:s', strtotime($payload['completedAt'])) : current_time('mysql');

            if (!empty($payload['document']) && is_array($payload['document'])) {
                $update['document_object_key'] = !empty($payload['document']['objectKey']) ? sanitize_text_field($payload['document']['objectKey']) : null;
                $update['document_sha256'] = !empty($payload['document']['sha256']) ? sanitize_text_field($payload['document']['sha256']) : null;
            }
        } elseif ($event_type === 'ENVELOPE_EXPIRED' || strtoupper($status) === 'EXPIRED') {
            $update['permission_status'] = self::PERMISSION_EXPIRED;
            $update['status'] = self::STATUS_EXPIRED;
        }

        $wpdb->update(
            $wpdb->prefix . 'fs_event_registrations',
            $update,
            array('id' => $registration_id)
        );

        if (isset($update['permission_status']) && $update['permission_status'] === self::PERMISSION_SIGNED) {
            $sync_result = self::sync_registration_after_permission_signed((int) $registration_id);
            if (is_wp_error($sync_result) && class_exists('FS_Audit_Log')) {
                FS_Audit_Log::log(
                    'registration_sync_after_webhook_permission_failed',
                    'registration',
                    (int) $registration_id,
                    array('error_code' => $sync_result->get_error_code())
                );
            }

            $registration = self::get_registration_with_context($registration_id);
            if (class_exists('FS_Notifications') && $registration) {
                FS_Notifications::send_staff_permission_signed_notification($registration);
            }
        }

        if (isset($update['permission_status']) && $update['permission_status'] === self::PERMISSION_EXPIRED) {
            self::expire_registration($registration_id);
        }

        return true;
    }

    /**
     * Check if a deliveryId has already been persisted.
     */
    private static function has_processed_delivery($delivery_id) {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$wpdb->prefix}fs_signshyft_webhook_deliveries
             WHERE delivery_id = %s
             LIMIT 1",
            sanitize_text_field($delivery_id)
        ));

        return !empty($existing);
    }

    /**
     * Persist minimal webhook metadata for idempotency and ops audit.
     */
    private static function record_webhook_delivery($delivery_id, $event_type, $envelope_id, $status_code, $error_reason, $processed) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'fs_signshyft_webhook_deliveries',
            array(
                'delivery_id' => sanitize_text_field($delivery_id),
                'event_type' => sanitize_text_field($event_type),
                'envelope_id' => sanitize_text_field($envelope_id),
                'received_at' => current_time('mysql'),
                'processed_at' => $processed ? current_time('mysql') : null,
                'status_code' => (int) $status_code,
                'error_reason' => sanitize_text_field($error_reason),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
    }

    /**
     * Keep webhook idempotency records for at least 30 days.
     */
    private static function cleanup_old_webhook_deliveries() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fs_signshyft_webhook_deliveries
             WHERE received_at < DATE_SUB(NOW(), INTERVAL 31 DAY)"
        );
    }

    /**
     * Read a required header from REST request headers map.
     */
    private static function get_header_value($headers, $header_name) {
        $needle = str_replace('_', '-', strtolower($header_name));
        foreach ((array) $headers as $key => $value) {
            $normalized_key = str_replace('_', '-', strtolower((string) $key));
            if ($normalized_key !== $needle) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            return trim((string) $value);
        }

        return '';
    }

    /**
     * Compute manual fallback expiry (fixed policy: 7 days from teen submission).
     */
    private static function get_manual_expiry_for_registration($registration) {
        $created_at = !empty($registration->created_at) ? $registration->created_at : current_time('mysql');
        $created_gmt = get_gmt_from_date($created_at);
        $created_ts = strtotime($created_gmt);
        if (!$created_ts) {
            $created_ts = time();
        }

        $expires_ts = $created_ts + (self::MANUAL_HOLD_WINDOW_DAYS * DAY_IN_SECONDS);
        return get_date_from_gmt(gmdate('Y-m-d H:i:s', $expires_ts));
    }

    /**
     * Store private manual permission files outside web root when possible.
     */
    private static function get_private_storage_root() {
        $candidates = array(
            wp_normalize_path(trailingslashit(dirname(ABSPATH)) . 'friendshyft-private'),
            wp_normalize_path(trailingslashit(WP_CONTENT_DIR) . 'friendshyft-private'),
        );

        foreach ($candidates as $candidate) {
            if (!file_exists($candidate) && !wp_mkdir_p($candidate)) {
                continue;
            }

            if (!is_dir($candidate) || !is_writable($candidate)) {
                continue;
            }

            self::harden_private_storage_directory($candidate);
            return $candidate;
        }

        return new WP_Error('private_storage_unavailable', 'Could not initialize private storage for signed documents.');
    }

    /**
     * Write deny-listing files to private directory where supported.
     */
    private static function harden_private_storage_directory($directory) {
        $directory = wp_normalize_path($directory);
        $index_file = trailingslashit($directory) . 'index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\n");
        }

        $htaccess_file = trailingslashit($directory) . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $rules = "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
            @file_put_contents($htaccess_file, $rules);
        }

        $web_config_file = trailingslashit($directory) . 'web.config';
        if (!file_exists($web_config_file)) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n";
            @file_put_contents($web_config_file, $xml);
        }
    }

    /**
     * Persist uploaded PDF to private storage and return metadata.
     */
    private static function store_manual_signed_pdf($registration_id, $uploaded_file) {
        $storage_root = self::get_private_storage_root();
        if (is_wp_error($storage_root)) {
            return $storage_root;
        }

        $permissions_dir = wp_normalize_path(trailingslashit($storage_root) . 'permissions');
        if (!file_exists($permissions_dir) && !wp_mkdir_p($permissions_dir)) {
            return new WP_Error('private_storage_permissions_dir_failed', 'Could not create permissions storage directory.');
        }
        if (!is_dir($permissions_dir) || !is_writable($permissions_dir)) {
            return new WP_Error('private_storage_permissions_dir_unwritable', 'Permissions storage directory is not writable.');
        }
        self::harden_private_storage_directory($permissions_dir);

        $token = strtolower(wp_generate_password(10, false, false));
        $filename = 'registration-' . (int) $registration_id . '-signed-' . gmdate('YmdHis') . '-' . $token . '.pdf';
        $absolute_path = wp_normalize_path(trailingslashit($permissions_dir) . $filename);

        $moved = false;
        if (is_uploaded_file($uploaded_file['tmp_name'])) {
            $moved = move_uploaded_file($uploaded_file['tmp_name'], $absolute_path);
        }
        if (!$moved) {
            $moved = @rename($uploaded_file['tmp_name'], $absolute_path);
        }
        if (!$moved) {
            $moved = @copy($uploaded_file['tmp_name'], $absolute_path);
        }
        if (!$moved) {
            return new WP_Error('manual_pdf_store_failed', 'Could not store uploaded signed PDF.');
        }

        @chmod($absolute_path, 0600);
        $sha256 = hash_file('sha256', $absolute_path);
        if ($sha256 === false || $sha256 === '') {
            return new WP_Error('manual_pdf_hash_failed', 'Could not compute signed PDF checksum.');
        }

        return array(
            'absolute_path' => $absolute_path,
            'relative_path' => 'permissions/' . $filename,
            'sha256' => $sha256,
        );
    }

    /**
     * Resolve a stored private path and guard against traversal.
     */
    private static function resolve_private_storage_path($relative_path) {
        $storage_root = self::get_private_storage_root();
        if (is_wp_error($storage_root)) {
            return $storage_root;
        }

        $storage_root = trailingslashit(wp_normalize_path($storage_root));
        $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
        $absolute_path = wp_normalize_path($storage_root . $relative_path);

        if (strpos($absolute_path, $storage_root) !== 0) {
            return new WP_Error('manual_pdf_invalid_path', 'Invalid signed PDF path.');
        }

        return $absolute_path;
    }

    /**
     * Scope lock for v1 permission reuse policy.
     */
    private static function get_permission_scope() {
        $settings = get_option('fs_teen_permission_settings', array());
        return !empty($settings['default_permission_scope']) ? $settings['default_permission_scope'] : 'single_event';
    }

    /**
     * Hold window in hours (policy locked default 48).
     */
    private static function get_hold_window_hours() {
        $settings = get_option('fs_teen_permission_settings', array());
        $hours = isset($settings['hold_window_hours']) ? (int) $settings['hold_window_hours'] : 48;
        return max(1, $hours);
    }

    /**
     * Default age threshold.
     */
    private static function get_minor_age_threshold_default() {
        return 18;
    }

    /**
     * Reminder final offset with event override support.
     */
    private static function get_final_reminder_hours($registration) {
        if (!empty($registration->reminder_final_hours)) {
            return max(1, (int) $registration->reminder_final_hours);
        }

        $settings = get_option('fs_teen_permission_settings', array());
        $hours = isset($settings['final_reminder_hours']) ? (int) $settings['final_reminder_hours'] : 2;
        return max(1, $hours);
    }

    /**
     * Convenience for public confirmation.
     */
    private static function get_permission_expiry_for_registration($registration_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT permission_expires_at
             FROM {$wpdb->prefix}fs_event_registrations
             WHERE id = %d",
            (int) $registration_id
        ));
    }

    /**
     * Lightweight registration lookup for admin confirmation gates.
     */
    private static function get_registration_for_confirmation_gate($registration_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, permission_status
             FROM {$wpdb->prefix}fs_event_registrations
             WHERE id = %d",
            (int) $registration_id
        ));
    }

    /**
     * Teen registration records should not strand volunteers in a pending account state.
     */
    private static function should_activate_volunteer_status($status) {
        $normalized = strtolower(trim((string) $status));
        return $normalized === '' || $normalized === 'pending';
    }

    /**
     * Repair older teen registrations that were created with pending volunteer status.
     */
    private static function ensure_volunteer_active($volunteer_id) {
        global $wpdb;

        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, volunteer_status
             FROM {$wpdb->prefix}fs_volunteers
             WHERE id = %d",
            (int) $volunteer_id
        ));

        if (!$volunteer) {
            return false;
        }

        if (!self::should_activate_volunteer_status($volunteer->volunteer_status ?? '')) {
            return true;
        }

        $wpdb->update(
            $wpdb->prefix . 'fs_volunteers',
            array('volunteer_status' => 'Active'),
            array('id' => (int) $volunteer_id),
            array('%s'),
            array('%d')
        );

        return true;
    }

    /**
     * Signed permission means any newly opened teen spot can move straight to confirmed.
     */
    private static function should_confirm_after_permission_signed($registration) {
        return !empty($registration) && ($registration->permission_status ?? '') === self::PERMISSION_SIGNED;
    }

    /**
     * When permission lands, confirm held spots and grab any waitlisted spots that have opened.
     */
    private static function sync_registration_after_permission_signed($registration_id) {
        global $wpdb;

        $registration = self::get_registration_with_context((int) $registration_id);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found.');
        }

        self::ensure_volunteer_active((int) $registration->volunteer_id);

        $pending_signup_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id
             FROM {$wpdb->prefix}fs_signups
             WHERE registration_id = %d
               AND status = 'pending'",
            (int) $registration_id
        ));

        if (!empty($pending_signup_ids)) {
            $wpdb->update(
                $wpdb->prefix . 'fs_signups',
                array('status' => 'confirmed'),
                array(
                    'registration_id' => (int) $registration_id,
                    'status' => 'pending',
                ),
                array('%s'),
                array('%d', '%s')
            );
        }

        $promoted_count = 0;
        $waitlist_entries = $wpdb->get_results($wpdb->prepare(
            "SELECT id, opportunity_id, shift_id
             FROM {$wpdb->prefix}fs_waitlist
             WHERE registration_id = %d
               AND status = 'waiting'
             ORDER BY joined_at ASC, id ASC",
            (int) $registration_id
        ));

        foreach ($waitlist_entries as $entry) {
            $shift_id = !empty($entry->shift_id) ? (int) $entry->shift_id : null;
            if (!self::session_has_remaining_capacity((int) $entry->opportunity_id, $shift_id)) {
                continue;
            }

            $promote_result = self::promote_waitlist_entry((int) $entry->id);
            if (is_wp_error($promote_result)) {
                if (class_exists('FS_Audit_Log')) {
                    FS_Audit_Log::log(
                        'registration_waitlist_sync_failed_after_permission',
                        'registration',
                        (int) $registration_id,
                        array(
                            'waitlist_id' => (int) $entry->id,
                            'error_code' => $promote_result->get_error_code(),
                        )
                    );
                }
                continue;
            }

            $promoted_count++;
        }

        return array(
            'confirmed_count' => count($pending_signup_ids),
            'promoted_count' => $promoted_count,
        );
    }
}
