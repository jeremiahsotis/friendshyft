<?php
if (!defined('ABSPATH')) exit;

/**
 * Public teen event registration shortcode.
 */
class FS_Event_Registration_Shortcode {

    /**
     * Register shortcode and submit handlers.
     */
    public static function init() {
        add_shortcode('fs_event_registration', array(__CLASS__, 'render_shortcode'));
        add_action('wp_ajax_fs_submit_event_registration', array(__CLASS__, 'handle_submit'));
        add_action('wp_ajax_nopriv_fs_submit_event_registration', array(__CLASS__, 'handle_submit'));
    }

    /**
     * Render registration UI for a specific event group.
     */
    public static function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'event_group_id' => 0,
        ), $atts);

        $event_group_id = (int) $atts['event_group_id'];
        if ($event_group_id < 1) {
            return '<div class="fs-event-registration-error">Missing event_group_id.</div>';
        }

        $event_group = FS_Event_Groups::get($event_group_id);
        if (!$event_group) {
            return '<div class="fs-event-registration-error">Event group not found.</div>';
        }

        $sessions = FS_Event_Groups::get_sessions($event_group_id);
        if (empty($sessions)) {
            return '<div class="fs-event-registration-empty">No sessions are currently available for this event.</div>';
        }

        $sessions_by_day = array();
        foreach ($sessions as $session) {
            $day = $session['event_date'];
            if (!isset($sessions_by_day[$day])) {
                $sessions_by_day[$day] = array();
            }
            $sessions_by_day[$day][] = $session;
        }

        ob_start();
        ?>
        <div id="fs-event-registration-<?php echo esc_attr($event_group_id); ?>" class="fs-event-registration-wrap" data-event-group-id="<?php echo esc_attr($event_group_id); ?>" data-selection-mode="<?php echo esc_attr($event_group->selection_mode); ?>">
            <div class="fs-event-card">
                <h2><?php echo esc_html($event_group->title); ?></h2>
                <?php if (!empty($event_group->description)): ?>
                    <div class="fs-event-description"><?php echo wpautop(wp_kses_post($event_group->description)); ?></div>
                <?php endif; ?>
                <?php if (!empty($event_group->location)): ?>
                    <p><strong>Location:</strong> <?php echo esc_html($event_group->location); ?></p>
                <?php endif; ?>
            </div>

            <form class="fs-event-registration-form">
                <input type="hidden" name="action" value="fs_submit_event_registration" />
                <input type="hidden" name="event_group_id" value="<?php echo esc_attr($event_group_id); ?>" />
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('fs_event_registration_' . $event_group_id)); ?>" />

                <fieldset>
                    <legend>Teen Information</legend>
                    <label>Full Name* <input type="text" name="teen_name" required></label>
                    <label>Email* <input type="email" name="teen_email" required></label>
                    <label>Phone <input type="text" name="teen_phone"></label>
                    <label>Birthdate* <input type="date" name="teen_birthdate" required></label>
                </fieldset>

                <fieldset class="fs-guardian-fields">
                    <legend>Guardian Information (required for minors and unknown age)</legend>
                    <label>Guardian Email* <input type="email" name="guardian_email"></label>
                    <label>Guardian Name <input type="text" name="guardian_name"></label>
                    <label>Guardian Phone <input type="text" name="guardian_phone"></label>
                </fieldset>

                <fieldset>
                    <legend>Session Selection</legend>
                    <?php if ($event_group->selection_mode === FS_Event_Groups::SELECTION_ALL): ?>
                        <p>All listed sessions will be included in this registration.</p>
                        <ul>
                            <?php foreach ($sessions as $session): ?>
                                <li>
                                    <?php echo esc_html(self::format_session_label($session)); ?>
                                    <strong>(<?php echo esc_html($session['remaining_spots']); ?> spots remaining)</strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif ($event_group->selection_mode === FS_Event_Groups::SELECTION_DAYS_ONLY): ?>
                        <p>Select the day(s) you want. Each day maps to one session.</p>
                        <?php foreach ($sessions_by_day as $day => $day_sessions): ?>
                            <label class="fs-session-choice">
                                <input type="checkbox" name="selected_days[]" value="<?php echo esc_attr($day); ?>">
                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($day))); ?>
                                <small><?php echo esc_html($day_sessions[0]['remaining_spots']); ?> spots remaining</small>
                            </label>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>Select one or more sessions.</p>
                        <?php foreach ($sessions_by_day as $day => $day_sessions): ?>
                            <h4><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($day))); ?></h4>
                            <?php foreach ($day_sessions as $session): ?>
                                <label class="fs-session-choice">
                                    <input type="checkbox" name="selected_sessions[]" value="<?php echo esc_attr($session['session_key']); ?>">
                                    <?php echo esc_html(self::format_session_label($session)); ?>
                                    <small><?php echo esc_html($session['remaining_spots']); ?> spots remaining</small>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </fieldset>

                <button type="submit">Submit Registration</button>
                <div class="fs-event-message" style="display:none;"></div>
            </form>
        </div>
        <style>
            .fs-event-registration-wrap { max-width: 900px; margin: 0 auto; padding: 18px; }
            .fs-event-card { background: #f7f8f9; border: 1px solid #d6d9dd; border-radius: 8px; padding: 16px; margin-bottom: 18px; }
            .fs-event-registration-form fieldset { border: 1px solid #d6d9dd; border-radius: 8px; margin: 0 0 16px 0; padding: 14px; }
            .fs-event-registration-form legend { font-weight: 600; padding: 0 6px; }
            .fs-event-registration-form label { display: block; margin-bottom: 12px; }
            .fs-event-registration-form input[type="text"],
            .fs-event-registration-form input[type="email"],
            .fs-event-registration-form input[type="date"] { width: 100%; max-width: 500px; padding: 9px; }
            .fs-session-choice { display: block; padding: 7px 0; }
            .fs-session-choice small { display: block; color: #60646b; }
            .fs-event-registration-form button { background: #0073aa; color: #fff; border: 0; border-radius: 5px; padding: 12px 18px; cursor: pointer; }
            .fs-event-message { margin-top: 14px; padding: 12px; border-radius: 6px; }
            .fs-event-message.success { background: #dff2e4; color: #155724; }
            .fs-event-message.error { background: #fde2e4; color: #8a1c28; }
        </style>
        <script>
            (function() {
                var wrapper = document.getElementById('fs-event-registration-<?php echo esc_js($event_group_id); ?>');
                if (!wrapper) return;

                var form = wrapper.querySelector('.fs-event-registration-form');
                var birthdateInput = form.querySelector('input[name="teen_birthdate"]');
                var guardianFieldset = form.querySelector('.fs-guardian-fields');
                var message = form.querySelector('.fs-event-message');

                function updateGuardianVisibility() {
                    var value = birthdateInput.value;
                    var isMinor = true;
                    if (value) {
                        var dob = new Date(value + 'T00:00:00');
                        var now = new Date();
                        var age = now.getFullYear() - dob.getFullYear();
                        var m = now.getMonth() - dob.getMonth();
                        if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) age--;
                        isMinor = age < 18;
                    }
                    guardianFieldset.style.display = isMinor ? 'block' : 'none';
                    var guardianEmail = form.querySelector('input[name="guardian_email"]');
                    guardianEmail.required = isMinor;
                }

                birthdateInput.addEventListener('change', updateGuardianVisibility);
                updateGuardianVisibility();

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    message.style.display = 'none';

                    var button = form.querySelector('button[type="submit"]');
                    button.disabled = true;
                    button.textContent = 'Submitting...';

                    var data = new FormData(form);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        body: data
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(result) {
                        if (result.success) {
                            message.className = 'fs-event-message success';
                            message.textContent = result.data.message;
                            form.reset();
                            updateGuardianVisibility();
                        } else {
                            message.className = 'fs-event-message error';
                            message.textContent = result.data && result.data.message ? result.data.message : 'Registration failed.';
                        }
                        message.style.display = 'block';
                    })
                    .catch(function() {
                        message.className = 'fs-event-message error';
                        message.textContent = 'Network error. Please try again.';
                        message.style.display = 'block';
                    })
                    .finally(function() {
                        button.disabled = false;
                        button.textContent = 'Submit Registration';
                    });
                });
            })();
        </script>
        <?php

        return ob_get_clean();
    }

    /**
     * AJAX submit endpoint.
     */
    public static function handle_submit() {
        $event_group_id = isset($_POST['event_group_id']) ? (int) $_POST['event_group_id'] : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        if ($event_group_id < 1 || !wp_verify_nonce($nonce, 'fs_event_registration_' . $event_group_id)) {
            wp_send_json_error(array('message' => 'Invalid registration request.'));
            return;
        }

        $payload = array(
            'event_group_id' => $event_group_id,
            'teen_name' => sanitize_text_field($_POST['teen_name'] ?? ''),
            'teen_email' => sanitize_email($_POST['teen_email'] ?? ''),
            'teen_phone' => sanitize_text_field($_POST['teen_phone'] ?? ''),
            'teen_birthdate' => sanitize_text_field($_POST['teen_birthdate'] ?? ''),
            'guardian_email' => sanitize_email($_POST['guardian_email'] ?? ''),
            'guardian_name' => sanitize_text_field($_POST['guardian_name'] ?? ''),
            'guardian_phone' => sanitize_text_field($_POST['guardian_phone'] ?? ''),
            'selected_sessions' => isset($_POST['selected_sessions']) ? array_map('sanitize_text_field', (array) $_POST['selected_sessions']) : array(),
            'selected_days' => isset($_POST['selected_days']) ? array_map('sanitize_text_field', (array) $_POST['selected_days']) : array(),
        );

        $result = FS_Event_Registrations::create_registration($payload);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }

        $held_count = count($result['held_sessions']);
        $wait_count = count($result['waitlisted_sessions']);
        $message = array();

        if ($held_count > 0) {
            $message[] = $held_count . ' session(s) held pending approval.';
        }
        if ($wait_count > 0) {
            $message[] = $wait_count . ' session(s) added to the waitlist.';
        }
        if ($result['permission_status'] === FS_Event_Registrations::PERMISSION_SENT) {
            if (($result['permission_channel'] ?? FS_Event_Registrations::PERMISSION_CHANNEL_SIGNSHYFT) === FS_Event_Registrations::PERMISSION_CHANNEL_MANUAL) {
                $message[] = 'Guardian permission email sent via manual fallback workflow.';
            } else {
                $message[] = 'Guardian permission email sent. The signing window expires in 48 hours.';
            }
        } elseif (
            ($result['permission_channel'] ?? '') === FS_Event_Registrations::PERMISSION_CHANNEL_MANUAL
            && ($result['permission_request_state'] ?? '') === 'manual_pending_staff'
            && $held_count > 0
        ) {
            $message[] = 'Your sessions are held pending approval. Staff will send a parent permission link shortly.';
        } elseif ($held_count === 0) {
            $message[] = 'No permission form was sent because this is currently waitlist-only.';
        }

        wp_send_json_success(array(
            'message' => implode(' ', $message),
            'registration_id' => $result['registration_id'],
        ));
    }

    /**
     * Session display helper.
     */
    private static function format_session_label($session) {
        $parts = array($session['title']);

        if (!empty($session['datetime_start'])) {
            $parts[] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($session['datetime_start']));
        }

        if (!empty($session['datetime_end'])) {
            $parts[] = date_i18n(get_option('time_format'), strtotime($session['datetime_end']));
        }

        return implode(' - ', $parts);
    }
}
