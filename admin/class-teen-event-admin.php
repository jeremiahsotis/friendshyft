<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin UI for teen event registration operations.
 */
class FS_Teen_Event_Admin {

    /**
     * Register menu pages and actions.
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 26);
        add_action('admin_post_fs_teen_resend_permission', array(__CLASS__, 'handle_resend_permission'));
        add_action('admin_post_fs_teen_send_manual_permission', array(__CLASS__, 'handle_send_manual_permission'));
        add_action('admin_post_fs_teen_mark_manual_permission_signed', array(__CLASS__, 'handle_mark_manual_permission_signed'));
        add_action('admin_post_fs_teen_promote_waitlist', array(__CLASS__, 'handle_promote_waitlist'));
        add_action('admin_post_fs_teen_download_pdf', array(__CLASS__, 'handle_download_pdf'));
        add_action('admin_post_fs_teen_download_manual_pdf', array(__CLASS__, 'handle_download_manual_pdf'));
        add_action('admin_post_fs_save_event_group', array(__CLASS__, 'handle_save_event_group'));
    }

    /**
     * Add submenu pages under FriendShyft.
     */
    public static function add_menu_pages() {
        add_submenu_page(
            'friendshyft',
            'Teen Event Registrations',
            'Teen Event Registrations',
            'manage_options',
            'fs-teen-registrations',
            array(__CLASS__, 'render_registrations_page')
        );

        add_submenu_page(
            'friendshyft',
            'Teen Event Groups',
            'Teen Event Groups',
            'manage_options',
            'fs-event-groups',
            array(__CLASS__, 'render_event_groups_page')
        );
    }

    /**
     * Render registration list + details view.
     */
    public static function render_registrations_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $filters = array(
            'status' => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'active',
            'permission_status' => isset($_GET['permission_status']) ? sanitize_text_field($_GET['permission_status']) : '',
            'event_group_id' => isset($_GET['event_group_id']) ? (int) $_GET['event_group_id'] : 0,
        );

        $registrations = FS_Event_Registrations::list_registrations($filters);
        $event_groups = FS_Event_Groups::list_all();
        $selected_registration_id = isset($_GET['registration_id']) ? (int) $_GET['registration_id'] : 0;
        $selected_registration = $selected_registration_id > 0 ? FS_Event_Registrations::get_registration_with_context($selected_registration_id) : null;
        ?>
        <div class="wrap">
            <h1>Teen Event Registrations</h1>

            <?php self::render_admin_notice_from_query(); ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:15px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page" value="fs-teen-registrations">

                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active" <?php selected($filters['status'], 'active'); ?>>Active</option>
                    <option value="expired" <?php selected($filters['status'], 'expired'); ?>>Expired</option>
                    <option value="cancelled" <?php selected($filters['status'], 'cancelled'); ?>>Cancelled</option>
                </select>

                <select name="permission_status">
                    <option value="">All permission states</option>
                    <option value="not_required" <?php selected($filters['permission_status'], 'not_required'); ?>>Not Required</option>
                    <option value="not_sent" <?php selected($filters['permission_status'], 'not_sent'); ?>>Not Sent</option>
                    <option value="sent" <?php selected($filters['permission_status'], 'sent'); ?>>Sent</option>
                    <option value="signed" <?php selected($filters['permission_status'], 'signed'); ?>>Signed</option>
                    <option value="expired" <?php selected($filters['permission_status'], 'expired'); ?>>Expired</option>
                </select>

                <select name="event_group_id">
                    <option value="">All event groups</option>
                    <?php foreach ($event_groups as $group): ?>
                        <option value="<?php echo (int) $group->id; ?>" <?php selected($filters['event_group_id'], (int) $group->id); ?>>
                            <?php echo esc_html($group->title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="button">Filter</button>
            </form>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Teen</th>
                        <th>Event Group</th>
                        <th>Held Sessions</th>
                        <th>Waitlisted Sessions</th>
                        <th>Permission Status</th>
                        <th>Expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($registrations)): ?>
                    <tr><td colspan="7">No registrations found.</td></tr>
                <?php else: ?>
                    <?php foreach ($registrations as $registration): ?>
                        <?php $permission_channel = self::get_permission_channel($registration); ?>
                        <tr>
                            <td><?php echo esc_html($registration->teen_name); ?></td>
                            <td><?php echo esc_html($registration->event_group_title); ?></td>
                            <td><?php echo (int) $registration->pending_count; ?></td>
                            <td><?php echo (int) $registration->waitlist_count; ?></td>
                            <td>
                                <strong><?php echo esc_html($registration->permission_status); ?></strong><br>
                                <small><?php echo esc_html($permission_channel); ?></small>
                            </td>
                            <td><?php echo !empty($registration->permission_expires_at) ? esc_html(date_i18n('M j, Y g:i A', strtotime($registration->permission_expires_at))) : '—'; ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'fs-teen-registrations', 'registration_id' => (int) $registration->id), admin_url('admin.php'))); ?>">Details</a>
                                <?php if ($registration->permission_status === 'sent' && $permission_channel !== FS_Event_Registrations::PERMISSION_CHANNEL_MANUAL): ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fs_teen_resend_permission&registration_id=' . (int) $registration->id), 'fs_teen_resend_permission_' . (int) $registration->id)); ?>">Resend</a>
                                <?php endif; ?>
                                <?php if ($permission_channel === FS_Event_Registrations::PERMISSION_CHANNEL_MANUAL && in_array($registration->permission_status, array('not_sent', 'sent'), true)): ?>
                                    <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'fs-teen-registrations', 'registration_id' => (int) $registration->id), admin_url('admin.php')) . '#manual-permission'); ?>">
                                        <?php echo $registration->permission_status === 'sent' ? 'Resend Manual' : 'Send Manual'; ?>
                                    </a>
                                <?php endif; ?>
                                <?php if ($registration->permission_status === 'signed' && !empty($registration->signshyft_envelope_id)): ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fs_teen_download_pdf&registration_id=' . (int) $registration->id), 'fs_teen_download_pdf_' . (int) $registration->id)); ?>">View PDF</a>
                                <?php endif; ?>
                                <?php if ($registration->permission_status === 'signed' && !empty($registration->manual_signed_document_path)): ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fs_teen_download_manual_pdf&registration_id=' . (int) $registration->id), 'fs_teen_download_manual_pdf_' . (int) $registration->id)); ?>">View Manual PDF</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($selected_registration): ?>
                <?php self::render_registration_details($selected_registration); ?>
            <?php endif; ?>

            <h2 style="margin-top:30px;">Minor Permission Triage</h2>
            <?php $triage_rows = FS_Event_Registrations::get_minor_permission_triage_rows(); ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Teen</th>
                        <th>Birthdate</th>
                        <th>Event Group</th>
                        <th>Permission Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($triage_rows)): ?>
                    <tr><td colspan="5">No triage items.</td></tr>
                <?php else: ?>
                    <?php foreach ($triage_rows as $row): ?>
                        <?php $permission_channel = self::get_permission_channel($row); ?>
                        <tr>
                            <td><?php echo esc_html($row->teen_name); ?></td>
                            <td><?php echo !empty($row->birthdate) ? esc_html($row->birthdate) : '<em>Unknown</em>'; ?></td>
                            <td><?php echo esc_html($row->event_group_title); ?></td>
                            <td><?php echo esc_html($row->permission_status . ' (' . $permission_channel . ')'); ?></td>
                            <td><?php echo esc_html(date_i18n('M j, Y g:i A', strtotime($row->created_at))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render event group management page.
     */
    public static function render_event_groups_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $groups = FS_Event_Groups::list_all();
        $edit_group_id = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
        $group = $edit_group_id > 0 ? FS_Event_Groups::get($edit_group_id) : null;

        $opportunities = $wpdb->get_results(
            "SELECT id, title, event_date, datetime_start
             FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date >= CURDATE()
             ORDER BY event_date ASC, datetime_start ASC"
        );
        $assigned_ids = array();
        if ($group) {
            $assigned_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_opportunities WHERE event_group_id = %d",
                (int) $group->id
            ));
            $assigned_ids = array_map('intval', $assigned_ids);
        }
        ?>
        <div class="wrap">
            <h1>Teen Event Groups</h1>
            <?php self::render_admin_notice_from_query(); ?>

            <div style="display:grid; grid-template-columns:minmax(0, 2fr) minmax(0, 1fr); gap:20px;">
                <div>
                    <h2><?php echo $group ? 'Edit Event Group' : 'Create Event Group'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="fs_save_event_group">
                        <?php wp_nonce_field('fs_save_event_group'); ?>
                        <input type="hidden" name="group_id" value="<?php echo $group ? (int) $group->id : 0; ?>">

                        <table class="form-table">
                            <tr>
                                <th><label for="title">Title</label></th>
                                <td><input type="text" class="regular-text" name="title" id="title" required value="<?php echo esc_attr($group->title ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="description">Description</label></th>
                                <td><textarea class="large-text" rows="3" name="description" id="description"><?php echo esc_textarea($group->description ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="location">Location</label></th>
                                <td><input type="text" class="regular-text" name="location" id="location" value="<?php echo esc_attr($group->location ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="selection_mode">Selection Mode</label></th>
                                <td>
                                    <select name="selection_mode" id="selection_mode">
                                        <option value="ALL" <?php selected($group->selection_mode ?? '', 'ALL'); ?>>ALL</option>
                                        <option value="DAYS_ONLY" <?php selected($group->selection_mode ?? '', 'DAYS_ONLY'); ?>>DAYS_ONLY</option>
                                        <option value="SESSIONS_ANY" <?php selected($group->selection_mode ?? '', 'SESSIONS_ANY'); ?>>SESSIONS_ANY</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="signshyft_template_version_id">Template Version ID</label></th>
                                <td><input type="text" class="regular-text" name="signshyft_template_version_id" id="signshyft_template_version_id" value="<?php echo esc_attr($group->signshyft_template_version_id ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th>Minor Permission</th>
                                <td>
                                    <label><input type="checkbox" name="requires_minor_permission" value="1" <?php checked(!empty($group->requires_minor_permission)); ?>> Require permission for this event group</label><br>
                                    <label>Threshold age <input type="number" min="1" max="99" name="minor_age_threshold" value="<?php echo esc_attr($group->minor_age_threshold ?? 18); ?>" style="width:80px;"></label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="reminder_final_hours">Final reminder hours</label></th>
                                <td><input type="number" min="1" max="72" name="reminder_final_hours" id="reminder_final_hours" value="<?php echo esc_attr($group->reminder_final_hours ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="opportunity_ids">Sessions</label></th>
                                <td>
                                    <select name="opportunity_ids[]" id="opportunity_ids" multiple size="10" style="width:100%; max-width:560px;">
                                        <?php foreach ($opportunities as $opp): ?>
                                            <option value="<?php echo (int) $opp->id; ?>" <?php selected(in_array((int) $opp->id, $assigned_ids, true)); ?>>
                                                <?php echo esc_html($opp->title . ' - ' . date_i18n('M j, Y', strtotime($opp->event_date))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Hold Ctrl/Cmd to select multiple opportunities.</p>
                                </td>
                            </tr>
                        </table>

                        <p><button class="button button-primary">Save Event Group</button></p>
                    </form>
                </div>

                <div>
                    <h2>Existing Groups</h2>
                    <ul>
                        <?php if (empty($groups)): ?>
                            <li>No event groups yet.</li>
                        <?php else: ?>
                            <?php foreach ($groups as $existing_group): ?>
                                <li style="margin-bottom:10px;">
                                    <strong><?php echo esc_html($existing_group->title); ?></strong><br>
                                    <?php echo (int) $existing_group->session_count; ?> sessions<br>
                                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'fs-event-groups', 'group_id' => (int) $existing_group->id), admin_url('admin.php'))); ?>">Edit</a>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle resend permission action.
     */
    public static function handle_resend_permission() {
        $registration_id = isset($_GET['registration_id']) ? (int) $_GET['registration_id'] : 0;
        check_admin_referer('fs_teen_resend_permission_' . $registration_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $result = FS_Event_Registrations::trigger_permission_workflow($registration_id, true);
        if (is_wp_error($result)) {
            self::redirect_with_notice('fs-teen-registrations', 'error', $result->get_error_message(), array('registration_id' => $registration_id));
        }

        self::redirect_with_notice('fs-teen-registrations', 'updated', 'Permission link resent.', array('registration_id' => $registration_id));
    }

    /**
     * Handle sending/resending manual permission email with a third-party signer URL.
     */
    public static function handle_send_manual_permission() {
        $registration_id = isset($_POST['registration_id']) ? (int) $_POST['registration_id'] : 0;
        check_admin_referer('fs_teen_send_manual_permission_' . $registration_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $manual_signer_url = esc_url_raw(wp_unslash($_POST['manual_signer_url'] ?? ''));
        $result = FS_Event_Registrations::send_manual_permission_request($registration_id, $manual_signer_url);
        if (is_wp_error($result)) {
            self::redirect_with_notice('fs-teen-registrations', 'error', $result->get_error_message(), array('registration_id' => $registration_id));
        }

        $message = !empty($result['is_resend']) ? 'Manual permission link resent.' : 'Manual permission link sent.';
        self::redirect_with_notice('fs-teen-registrations', 'updated', $message, array('registration_id' => $registration_id));
    }

    /**
     * Handle manual completion by uploading a signed PDF.
     */
    public static function handle_mark_manual_permission_signed() {
        $registration_id = isset($_POST['registration_id']) ? (int) $_POST['registration_id'] : 0;
        check_admin_referer('fs_teen_mark_manual_permission_signed_' . $registration_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $uploaded_file = isset($_FILES['manual_signed_pdf']) ? $_FILES['manual_signed_pdf'] : null;
        $result = FS_Event_Registrations::mark_manual_permission_signed($registration_id, $uploaded_file);
        if (is_wp_error($result)) {
            self::redirect_with_notice('fs-teen-registrations', 'error', $result->get_error_message(), array('registration_id' => $registration_id));
        }

        self::redirect_with_notice(
            'fs-teen-registrations',
            'updated',
            'Manual permission marked as signed. Pending signups can now be confirmed.',
            array('registration_id' => $registration_id)
        );
    }

    /**
     * Handle promote waitlist action.
     */
    public static function handle_promote_waitlist() {
        $waitlist_id = isset($_GET['waitlist_id']) ? (int) $_GET['waitlist_id'] : 0;
        $registration_id = isset($_GET['registration_id']) ? (int) $_GET['registration_id'] : 0;
        check_admin_referer('fs_teen_promote_waitlist_' . $waitlist_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $result = FS_Event_Registrations::promote_waitlist_entry($waitlist_id);
        if (is_wp_error($result)) {
            self::redirect_with_notice('fs-teen-registrations', 'error', $result->get_error_message(), array('registration_id' => $registration_id));
        }

        self::redirect_with_notice('fs-teen-registrations', 'updated', 'Waitlist entry promoted to pending hold.', array('registration_id' => $registration_id));
    }

    /**
     * Stream finalized PDF to browser.
     */
    public static function handle_download_pdf() {
        $registration_id = isset($_GET['registration_id']) ? (int) $_GET['registration_id'] : 0;
        check_admin_referer('fs_teen_download_pdf_' . $registration_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $registration = FS_Event_Registrations::get_registration_with_context($registration_id);
        if (!$registration || empty($registration->signshyft_envelope_id)) {
            wp_die('Signed envelope not found.');
        }

        $pdf = FS_SignShyft_Client::download_finalized_pdf($registration->signshyft_envelope_id);
        if (is_wp_error($pdf)) {
            wp_die('Failed to fetch signed PDF.');
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="permission-' . (int) $registration_id . '.pdf"');
        echo $pdf['body'];
        exit;
    }

    /**
     * Stream manually uploaded signed PDF from protected storage.
     */
    public static function handle_download_manual_pdf() {
        $registration_id = isset($_GET['registration_id']) ? (int) $_GET['registration_id'] : 0;
        check_admin_referer('fs_teen_download_manual_pdf_' . $registration_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $file = FS_Event_Registrations::get_manual_signed_document_for_download($registration_id);
        if (is_wp_error($file)) {
            wp_die($file->get_error_message());
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . sanitize_file_name($file['filename']) . '"');
        header('Content-Length: ' . filesize($file['absolute_path']));
        readfile($file['absolute_path']);
        exit;
    }

    /**
     * Save event group settings.
     */
    public static function handle_save_event_group() {
        check_admin_referer('fs_save_event_group');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $group_data = array(
            'id' => isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0,
            'title' => sanitize_text_field($_POST['title'] ?? ''),
            'description' => wp_kses_post($_POST['description'] ?? ''),
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'selection_mode' => sanitize_text_field($_POST['selection_mode'] ?? ''),
            'requires_minor_permission' => !empty($_POST['requires_minor_permission']) ? 1 : 0,
            'minor_age_threshold' => isset($_POST['minor_age_threshold']) ? (int) $_POST['minor_age_threshold'] : 18,
            'signshyft_template_version_id' => sanitize_text_field($_POST['signshyft_template_version_id'] ?? ''),
            'reminder_final_hours' => isset($_POST['reminder_final_hours']) ? (int) $_POST['reminder_final_hours'] : null,
            'reminder_recipients' => 'guardian_only',
        );

        $opportunity_ids = isset($_POST['opportunity_ids']) ? array_map('intval', (array) $_POST['opportunity_ids']) : array();
        $result = FS_Event_Groups::save_group($group_data, $opportunity_ids);
        if (is_wp_error($result)) {
            self::redirect_with_notice('fs-event-groups', 'error', $result->get_error_message());
        }

        self::redirect_with_notice('fs-event-groups', 'updated', 'Event group saved.', array('group_id' => (int) $result));
    }

    /**
     * Render registration detail card.
     */
    private static function render_registration_details($registration) {
        global $wpdb;

        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.title
             FROM {$wpdb->prefix}fs_signups s
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON o.id = s.opportunity_id
             WHERE s.registration_id = %d
             ORDER BY s.id ASC",
            (int) $registration->id
        ));
        $waitlist = $wpdb->get_results($wpdb->prepare(
            "SELECT w.*, o.title
             FROM {$wpdb->prefix}fs_waitlist w
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON o.id = w.opportunity_id
             WHERE w.registration_id = %d
             ORDER BY w.joined_at ASC",
            (int) $registration->id
        ));
        $permission_channel = self::get_permission_channel($registration);
        $is_manual_channel = $permission_channel === FS_Event_Registrations::PERMISSION_CHANNEL_MANUAL;
        $can_manage_manual_permission = $is_manual_channel && in_array($registration->permission_status, array('not_sent', 'sent'), true);
        ?>
        <h2 style="margin-top:30px;">Registration Details #<?php echo (int) $registration->id; ?></h2>
        <p><strong>Teen:</strong> <?php echo esc_html($registration->teen_name); ?> (<?php echo esc_html($registration->teen_email); ?>)</p>
        <p><strong>Guardian:</strong> <?php echo esc_html($registration->guardian_email ?: '—'); ?></p>
        <p><strong>Permission:</strong> <?php echo esc_html($registration->permission_status); ?></p>
        <p><strong>Permission Channel:</strong> <?php echo esc_html($permission_channel); ?></p>
        <p><strong>Permission Expires:</strong> <?php echo !empty($registration->permission_expires_at) ? esc_html(date_i18n('M j, Y g:i A', strtotime($registration->permission_expires_at))) : '—'; ?></p>
        <p><strong>Envelope ID:</strong> <?php echo !empty($registration->signshyft_envelope_id) ? esc_html($registration->signshyft_envelope_id) : '—'; ?></p>
        <?php if ($is_manual_channel): ?>
            <p><strong>Manual Signer URL:</strong> <?php echo !empty($registration->manual_signer_url) ? esc_html($registration->manual_signer_url) : '—'; ?></p>
            <p><strong>Manual Request Sent:</strong> <?php echo !empty($registration->manual_request_sent_at) ? esc_html(date_i18n('M j, Y g:i A', strtotime($registration->manual_request_sent_at))) : '—'; ?></p>
        <?php endif; ?>

        <?php if ($can_manage_manual_permission): ?>
            <div id="manual-permission" style="margin:16px 0; padding:16px; border:1px solid #ccd0d4; background:#fff;">
                <h3 style="margin-top:0;">Manual Permission Workflow</h3>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                    <input type="hidden" name="action" value="fs_teen_send_manual_permission">
                    <input type="hidden" name="registration_id" value="<?php echo (int) $registration->id; ?>">
                    <?php wp_nonce_field('fs_teen_send_manual_permission_' . (int) $registration->id); ?>

                    <p>
                        <label for="manual_signer_url_<?php echo (int) $registration->id; ?>"><strong>Third-party signer link</strong></label><br>
                        <input
                            id="manual_signer_url_<?php echo (int) $registration->id; ?>"
                            type="url"
                            name="manual_signer_url"
                            class="regular-text"
                            required
                            value="<?php echo esc_attr($registration->manual_signer_url ?? ''); ?>"
                            placeholder="https://..."
                        >
                    </p>

                    <p>
                        <button class="button button-primary">
                            <?php echo $registration->permission_status === 'sent' ? 'Resend Manual Request' : 'Send Manual Request'; ?>
                        </button>
                    </p>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="fs_teen_mark_manual_permission_signed">
                    <input type="hidden" name="registration_id" value="<?php echo (int) $registration->id; ?>">
                    <?php wp_nonce_field('fs_teen_mark_manual_permission_signed_' . (int) $registration->id); ?>

                    <p>
                        <label for="manual_signed_pdf_<?php echo (int) $registration->id; ?>"><strong>Upload signed PDF</strong></label><br>
                        <input id="manual_signed_pdf_<?php echo (int) $registration->id; ?>" type="file" name="manual_signed_pdf" accept="application/pdf,.pdf" required>
                    </p>

                    <p>
                        <button class="button">Mark Manual Permission Signed</button>
                    </p>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($registration->manual_signed_document_path)): ?>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fs_teen_download_manual_pdf&registration_id=' . (int) $registration->id), 'fs_teen_download_manual_pdf_' . (int) $registration->id)); ?>">
                    View Manual Signed PDF
                </a>
            </p>
        <?php endif; ?>

        <h3>Sessions</h3>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>Session</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($signups as $signup): ?>
                <tr>
                    <td><?php echo esc_html($signup->title); ?></td>
                    <td><?php echo esc_html($signup->status); ?></td>
                    <td>—</td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($waitlist as $entry): ?>
                <tr>
                    <td><?php echo esc_html($entry->title); ?></td>
                    <td>waitlist (<?php echo esc_html($entry->status); ?>)</td>
                    <td>
                        <?php if ($entry->status === 'waiting'): ?>
                            <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(
                                admin_url('admin-post.php?action=fs_teen_promote_waitlist&waitlist_id=' . (int) $entry->id . '&registration_id=' . (int) $registration->id),
                                'fs_teen_promote_waitlist_' . (int) $entry->id
                            )); ?>">Promote</a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Normalize channel value for older rows.
     */
    private static function get_permission_channel($registration) {
        return !empty($registration->permission_channel)
            ? $registration->permission_channel
            : FS_Event_Registrations::PERMISSION_CHANNEL_SIGNSHYFT;
    }

    /**
     * Render simple query-string notices.
     */
    private static function render_admin_notice_from_query() {
        $status = isset($_GET['notice_status']) ? sanitize_text_field($_GET['notice_status']) : '';
        $message = isset($_GET['notice_message']) ? sanitize_text_field(wp_unslash($_GET['notice_message'])) : '';
        if (empty($status) || empty($message)) {
            return;
        }

        $class = $status === 'updated' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Redirect helper for admin notices.
     */
    private static function redirect_with_notice($page, $status, $message, $extra = array()) {
        $query = array_merge(array(
            'page' => $page,
            'notice_status' => $status,
            'notice_message' => rawurlencode($message),
        ), $extra);

        wp_redirect(add_query_arg($query, admin_url('admin.php')));
        exit;
    }
}
