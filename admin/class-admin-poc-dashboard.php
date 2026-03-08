<?php
if (!defined('ABSPATH')) exit;

/**
 * Point of Contact Dashboard
 * Provides POCs with a focused view of their opportunities and volunteers
 */
class FS_Admin_POC_Dashboard {

    public static function init() {
        // Register AJAX handlers for quick actions
        add_action('wp_ajax_fs_poc_quick_signup', array(__CLASS__, 'ajax_quick_signup'));
        add_action('wp_ajax_fs_poc_approve_volunteer', array(__CLASS__, 'ajax_approve_volunteer'));
        add_action('wp_ajax_fs_poc_reject_volunteer', array(__CLASS__, 'ajax_reject_volunteer'));
        add_action('wp_ajax_fs_poc_get_emergency_contacts', array(__CLASS__, 'ajax_get_emergency_contacts'));
    }

    public static function render_dashboard() {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();

        // Get POC opportunities
        $opportunities = FS_POC_Role::get_poc_opportunities($user_id);

        // Get volunteers for POC opportunities
        $volunteers = FS_POC_Role::get_poc_volunteers($user_id);

        // Get interested volunteers
        $interested = FS_POC_Role::get_interested_volunteers($user_id);

        // Get approved volunteers
        $approved = FS_POC_Role::get_approved_volunteers($user_id);

        ?>
        <div class="wrap">
            <h1>My Opportunities Dashboard</h1>
            <p>Welcome, <?php echo esc_html($user->display_name); ?>! Here's an overview of the opportunities you're managing.</p>

            <div class="fs-poc-dashboard-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
                <div class="stat-box" style="background: #fff; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo count($opportunities); ?></div>
                    <div style="color: #666; margin-top: 5px;">My Opportunities</div>
                </div>
                <div class="stat-box" style="background: #fff; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo count($volunteers); ?></div>
                    <div style="color: #666; margin-top: 5px;">Active Volunteers</div>
                </div>
                <div class="stat-box" style="background: #fff; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?php echo count($interested); ?></div>
                    <div style="color: #666; margin-top: 5px;">Interested Volunteers</div>
                </div>
                <div class="stat-box" style="background: #fff; border-left: 4px solid #667eea; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="font-size: 32px; font-weight: bold; color: #667eea;"><?php echo count($approved); ?></div>
                    <div style="color: #666; margin-top: 5px;">Approved for Roles</div>
                </div>
            </div>

            <h2>My Opportunities</h2>
            <?php if (empty($opportunities)): ?>
                <div class="notice notice-info">
                    <p>You don't have any opportunities assigned to you yet.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Signups</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($opportunities as $opp):
                            global $wpdb;
                            $signups_count = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
                                WHERE opportunity_id = %d AND status = 'confirmed'",
                                $opp->id
                            ));
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($opp->title); ?></strong>
                                <?php if ($opp->conference): ?>
                                    <br><span class="description"><?php echo esc_html($opp->conference); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($opp->event_date)); ?></td>
                            <td><?php echo esc_html($opp->location); ?></td>
                            <td>
                                <strong><?php echo $opp->spots_filled; ?></strong> / <?php echo $opp->spots_available; ?>
                                <?php if ($opp->spots_filled >= $opp->spots_available): ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #28a745;" title="Full"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_colors = array(
                                    'active' => '#28a745',
                                    'draft' => '#ffc107',
                                    'cancelled' => '#dc3545'
                                );
                                $color = isset($status_colors[$opp->status]) ? $status_colors[$opp->status] : '#666';
                                ?>
                                <span style="display: inline-block; padding: 3px 8px; background: <?php echo $color; ?>15; color: <?php echo $color; ?>; border-radius: 3px; font-size: 12px;">
                                    <?php echo ucfirst($opp->status); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=fs-manage-signups&opportunity_id=' . $opp->id); ?>" class="button button-small">
                                    View Signups
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top: 40px;">Active Volunteers</h2>
            <?php if (empty($volunteers)): ?>
                <p>No volunteers have signed up for your opportunities yet.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Opportunity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $seen_volunteers = array();
                        foreach ($volunteers as $vol):
                            if (in_array($vol->id, $seen_volunteers)) continue;
                            $seen_volunteers[] = $vol->id;
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($vol->name); ?></strong></td>
                            <td><?php echo esc_html($vol->email); ?></td>
                            <td><?php echo esc_html($vol->opportunity_title); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $vol->id); ?>" class="button button-small">
                                    View Profile
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 40px;">
                <div>
                    <h2>Interested Volunteers</h2>
                    <?php if (empty($interested)): ?>
                        <p class="description">No volunteers have expressed interest in your programs yet.</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Interest Area</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($interested as $vol): ?>
                                <tr>
                                    <td><?php echo esc_html($vol->name); ?></td>
                                    <td><?php echo esc_html($vol->interest_area); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $vol->id); ?>" class="button button-small">
                                            View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div>
                    <h2>Approved for Required Roles</h2>
                    <?php if (empty($approved)): ?>
                        <p class="description">No volunteers are approved for the roles required by your opportunities yet.</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approved as $vol): ?>
                                <tr>
                                    <td><?php echo esc_html($vol->name); ?></td>
                                    <td><?php echo esc_html($vol->role_name); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $vol->id); ?>" class="button button-small">
                                            View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top: 40px; padding: 20px; background: #f8f9fa; border-left: 4px solid #0073aa;">
                <h3 style="margin-top: 0;">Quick Actions</h3>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=fs-opportunities'); ?>" class="button">View All Opportunities</a>
                    <a href="<?php echo admin_url('admin.php?page=fs-volunteers'); ?>" class="button">View All Volunteers</a>
                    <a href="<?php echo admin_url('admin.php?page=fs-workflows'); ?>" class="button">Manage Workflows</a>
                    <a href="<?php echo admin_url('admin.php?page=fs-poc-calendar'); ?>" class="button">Calendar View</a>
                    <button id="emergency-contacts-btn" class="button" onclick="showEmergencyContacts()">
                        <span class="dashicons dashicons-sos" style="margin-top: 3px;"></span> Emergency Contacts
                    </button>
                </p>
            </div>

            <!-- Emergency Contacts Modal -->
            <div id="emergency-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 10000; max-width: 600px; width: 90%;">
                <h2 style="margin-top: 0; color: #dc3545;">
                    <span class="dashicons dashicons-sos" style="font-size: 24px; margin-right: 10px;"></span>
                    Emergency Contacts
                </h2>
                <div id="emergency-contacts-list">
                    <p>Loading...</p>
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <button class="button" onclick="closeEmergencyModal()">Close</button>
                </div>
            </div>
            <div id="emergency-overlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999;" onclick="closeEmergencyModal()"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Quick signup functionality
            $('.quick-signup-btn').on('click', function() {
                const volunteerId = $(this).data('volunteer-id');
                const opportunityId = $(this).data('opportunity-id');
                const button = $(this);

                if (!confirm('Add this volunteer to the opportunity?')) {
                    return;
                }

                button.prop('disabled', true).text('Adding...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'fs_poc_quick_signup',
                        volunteer_id: volunteerId,
                        opportunity_id: opportunityId,
                        nonce: '<?php echo wp_create_nonce('friendshyft_poc_actions'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.text('✓ Added').css('background', '#28a745');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false).text('Add to Opportunity');
                        }
                    },
                    error: function() {
                        alert('Connection error. Please try again.');
                        button.prop('disabled', false).text('Add to Opportunity');
                    }
                });
            });

            // Approve/reject functionality
            $('.approve-btn, .reject-btn').on('click', function() {
                const volunteerId = $(this).data('volunteer-id');
                const roleId = $(this).data('role-id');
                const action = $(this).hasClass('approve-btn') ? 'fs_poc_approve_volunteer' : 'fs_poc_reject_volunteer';
                const actionText = $(this).hasClass('approve-btn') ? 'approve' : 'reject';
                const button = $(this);

                if (!confirm('Are you sure you want to ' + actionText + ' this volunteer?')) {
                    return;
                }

                button.prop('disabled', true).text('Processing...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: action,
                        volunteer_id: volunteerId,
                        role_id: roleId,
                        nonce: '<?php echo wp_create_nonce('friendshyft_poc_actions'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.closest('tr').fadeOut();
                            setTimeout(function() {
                                location.reload();
                            }, 500);
                        } else {
                            alert('Error: ' + response.data);
                            button.prop('disabled', false).text(actionText === 'approve' ? 'Approve' : 'Reject');
                        }
                    },
                    error: function() {
                        alert('Connection error. Please try again.');
                        button.prop('disabled', false).text(actionText === 'approve' ? 'Approve' : 'Reject');
                    }
                });
            });
        });

        function showEmergencyContacts() {
            document.getElementById('emergency-modal').style.display = 'block';
            document.getElementById('emergency-overlay').style.display = 'block';

            jQuery.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'fs_poc_get_emergency_contacts',
                    nonce: '<?php echo wp_create_nonce('friendshyft_poc_actions'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        document.getElementById('emergency-contacts-list').innerHTML = response.data;
                    } else {
                        document.getElementById('emergency-contacts-list').innerHTML = '<p style="color: #dc3545;">Error loading contacts.</p>';
                    }
                },
                error: function() {
                    document.getElementById('emergency-contacts-list').innerHTML = '<p style="color: #dc3545;">Connection error. Please try again.</p>';
                }
            });
        }

        function closeEmergencyModal() {
            document.getElementById('emergency-modal').style.display = 'none';
            document.getElementById('emergency-overlay').style.display = 'none';
        }
        </script>

        <style>
            .quick-action-btn {
                margin-left: 5px;
                font-size: 11px;
                padding: 2px 8px;
            }
            .approve-btn {
                background: #28a745;
                color: white;
                border: none;
            }
            .reject-btn {
                background: #dc3545;
                color: white;
                border: none;
            }
            #emergency-modal h2 {
                border-bottom: 2px solid #dc3545;
                padding-bottom: 10px;
            }
            .emergency-contact-card {
                background: #f8f9fa;
                padding: 15px;
                margin: 10px 0;
                border-left: 4px solid #0073aa;
                border-radius: 4px;
            }
            .emergency-contact-card strong {
                color: #0073aa;
                font-size: 16px;
            }
        </style>
        <?php
    }

    /**
     * AJAX: Quick signup volunteer to opportunity
     */
    public static function ajax_quick_signup() {
        check_ajax_referer('friendshyft_poc_actions', 'nonce');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $volunteer_id = intval($_POST['volunteer_id']);
        $opportunity_id = intval($_POST['opportunity_id']);

        // Verify POC owns this opportunity
        if (!FS_POC_Role::is_poc_for_opportunity(get_current_user_id(), $opportunity_id)) {
            wp_send_json_error('You do not manage this opportunity');
            return;
        }

        // Create signup
        $result = FS_Signup::create($volunteer_id, $opportunity_id, null);

        if ($result['success']) {
            wp_send_json_success('Volunteer added successfully');
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Approve volunteer for role
     */
    public static function ajax_approve_volunteer() {
        check_ajax_referer('friendshyft_poc_actions', 'nonce');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $volunteer_id = intval($_POST['volunteer_id']);
        $role_id = intval($_POST['role_id']);

        global $wpdb;

        // Update volunteer role status
        $updated = $wpdb->update(
            "{$wpdb->prefix}fs_volunteer_roles",
            array('status' => 'approved'),
            array(
                'volunteer_id' => $volunteer_id,
                'role_id' => $role_id
            )
        );

        if ($updated !== false) {
            wp_send_json_success('Volunteer approved');
        } else {
            wp_send_json_error('Failed to approve volunteer');
        }
    }

    /**
     * AJAX: Reject volunteer for role
     */
    public static function ajax_reject_volunteer() {
        check_ajax_referer('friendshyft_poc_actions', 'nonce');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $volunteer_id = intval($_POST['volunteer_id']);
        $role_id = intval($_POST['role_id']);

        global $wpdb;

        // Remove volunteer role
        $deleted = $wpdb->delete(
            "{$wpdb->prefix}fs_volunteer_roles",
            array(
                'volunteer_id' => $volunteer_id,
                'role_id' => $role_id
            )
        );

        if ($deleted !== false) {
            wp_send_json_success('Volunteer rejected');
        } else {
            wp_send_json_error('Failed to reject volunteer');
        }
    }

    /**
     * AJAX: Get emergency contacts for POC's volunteers
     */
    public static function ajax_get_emergency_contacts() {
        check_ajax_referer('friendshyft_poc_actions', 'nonce');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $user_id = get_current_user_id();

        global $wpdb;

        // Get volunteers for POC's opportunities
        $volunteers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT v.id, v.name, v.email, v.phone
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE o.point_of_contact_id = %d
             AND s.status = 'confirmed'
             ORDER BY v.name ASC",
            $user_id
        ));

        if (empty($volunteers)) {
            wp_send_json_success('<p>No volunteer contacts available.</p>');
            return;
        }

        $html = '<p style="color: #666; margin-bottom: 20px;">Emergency contacts for volunteers signed up for your opportunities:</p>';

        foreach ($volunteers as $vol) {
            $html .= '<div class="emergency-contact-card">';
            $html .= '<strong>' . esc_html($vol->name) . '</strong><br>';
            $html .= '<span class="dashicons dashicons-email" style="color: #0073aa;"></span> ';
            $html .= '<a href="mailto:' . esc_attr($vol->email) . '">' . esc_html($vol->email) . '</a><br>';

            if ($vol->phone) {
                $html .= '<span class="dashicons dashicons-phone" style="color: #0073aa;"></span> ';
                $html .= '<a href="tel:' . esc_attr($vol->phone) . '">' . esc_html($vol->phone) . '</a>';
            }

            $html .= '</div>';
        }

        wp_send_json_success($html);
    }
}
