<?php
if (!defined('ABSPATH')) exit;

/**
 * Minimal SignShyft API client with strict secret handling.
 */
class FS_SignShyft_Client {

    const TIMEOUT_SECONDS = 20;

    /**
     * Check whether required SignShyft API credentials are available.
     */
    public static function is_configured() {
        $base_url = self::get_setting('signshyft_api_base_url');
        $bearer = self::get_setting('signshyft_bearer_jwt');

        return !empty($base_url) && !empty($bearer);
    }

    /**
     * Create an envelope and return the parsed response.
     */
    public static function create_envelope($payload) {
        return self::request_json('POST', '/staff/envelopes', $payload);
    }

    /**
     * Resend a signer link and rotate recipient token.
     */
    public static function resend_recipient_link($envelope_id, $recipient_id, $channel = null) {
        $payload = array();
        if (!empty($channel)) {
            $payload['channel'] = strtoupper($channel);
        }

        return self::request_json(
            'POST',
            '/staff/envelopes/' . rawurlencode($envelope_id) . '/recipients/' . rawurlencode($recipient_id) . '/resend',
            !empty($payload) ? $payload : null
        );
    }

    /**
     * Generate or rotate a signer link for a single recipient.
     */
    public static function get_signer_link($envelope_id, $recipient_id, $rotate = true) {
        return self::request_json(
            'POST',
            '/staff/envelopes/' . rawurlencode($envelope_id) . '/signer-link',
            array(
                'recipientId' => (string) $recipient_id,
                'rotate' => (bool) $rotate,
            )
        );
    }

    /**
     * Fetch envelope details for staff workflows.
     */
    public static function get_envelope($envelope_id) {
        return self::request_json('GET', '/staff/envelopes/' . rawurlencode($envelope_id), null);
    }

    /**
     * Download finalized envelope PDF bytes.
     */
    public static function download_finalized_pdf($envelope_id) {
        $response = self::request_raw('GET', '/staff/envelopes/' . rawurlencode($envelope_id) . '/download', null, 'application/pdf');
        if (is_wp_error($response)) {
            return $response;
        }

        return array(
            'body' => wp_remote_retrieve_body($response),
            'content_type' => wp_remote_retrieve_header($response, 'content-type'),
            'status_code' => wp_remote_retrieve_response_code($response),
        );
    }

    /**
     * Read SignShyft setting from constants first, then private options.
     */
    public static function get_setting($key, $default = '') {
        $const_map = array(
            'signshyft_api_base_url' => 'FRIENDSHYFT_SIGNSHYFT_API_BASE_URL',
            'signshyft_bearer_jwt' => 'FRIENDSHYFT_SIGNSHYFT_BEARER_JWT',
            'signshyft_webhook_secret_current_b64' => 'FRIENDSHYFT_SIGNSHYFT_WEBHOOK_SECRET_CURRENT_B64',
            'signshyft_webhook_secret_next_b64' => 'FRIENDSHYFT_SIGNSHYFT_WEBHOOK_SECRET_NEXT_B64',
        );

        if (isset($const_map[$key]) && defined($const_map[$key])) {
            return constant($const_map[$key]);
        }

        $settings = get_option('fs_signshyft_private_settings', array());
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Return decoded secret bytes for webhook verification.
     */
    public static function get_webhook_secret_bytes($which = 'current') {
        $setting_key = $which === 'next'
            ? 'signshyft_webhook_secret_next_b64'
            : 'signshyft_webhook_secret_current_b64';

        $secret_b64 = self::get_setting($setting_key);
        if (empty($secret_b64)) {
            return null;
        }

        $decoded = base64_decode($secret_b64, true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * Execute a JSON request and parse response payload.
     */
    private static function request_json($method, $path, $payload = null) {
        $response = self::request_raw($method, $path, $payload, 'application/json');
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error('signshyft_invalid_json', 'SignShyft returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * Execute a signed HTTP request.
     */
    private static function request_raw($method, $path, $payload = null, $accept = 'application/json') {
        $base_url = rtrim((string) self::get_setting('signshyft_api_base_url'), '/');
        $token = (string) self::get_setting('signshyft_bearer_jwt');

        if (empty($base_url) || empty($token)) {
            return new WP_Error('signshyft_not_configured', 'SignShyft API is not configured.');
        }

        $args = array(
            'method' => strtoupper($method),
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => $accept,
            ),
        );

        if ($payload !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($base_url . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            return new WP_Error(
                'signshyft_http_error',
                'SignShyft request failed.',
                array('status' => $status)
            );
        }

        return $response;
    }
}
