<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Opportunities {
    
    public static function init() {
        // Add menu pages
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);

        // Handle form submissions
        add_action('admin_post_fs_save_opportunity', array(__CLASS__, 'handle_form_submission'));

        // Handle delete action
        add_action('admin_post_fs_delete_opportunity', array(__CLASS__, 'handle_delete'));

        // Handle cancel future series action
        add_action('admin_post_fs_cancel_future_series', array(__CLASS__, 'handle_cancel_future_series'));
    }
    
    public static function add_menu_pages() {
        // List opportunities
        add_submenu_page(
            'friendshyft',
            'Opportunities',
            'Opportunities',
            'manage_options',
            'fs-opportunities',
            array(__CLASS__, 'list_page')
        );
        
        // Add/Edit opportunity (hidden from menu)
        add_submenu_page(
            null, // Hidden from menu
            'Add Opportunity',
            'Add Opportunity',
            'manage_options',
            'fs-add-opportunity',
            array(__CLASS__, 'edit_page')
        );
        
        add_submenu_page(
            null, // Hidden from menu
            'Edit Opportunity',
            'Edit Opportunity',
            'manage_options',
            'fs-edit-opportunity',
            array(__CLASS__, 'edit_page')
        );
    }
    
    public static function handle_form_submission() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: handle_form_submission called');
    }
    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('POST data: ' . print_r($_POST, true));
    }

    if (!isset($_POST['fs_save_opportunity'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: fs_save_opportunity not set');
        }
        return;
    }

    if (!check_admin_referer('fs_opportunity_form')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: Nonce check failed');
        }
        return;
    }

    if (!current_user_can('manage_options')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: User lacks permissions');
        }
        wp_die('Unauthorized');
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: All checks passed, proceeding with save');
    }
    
    global $wpdb;
    
    $opportunity_id = isset($_POST['opportunity_id']) ? intval($_POST['opportunity_id']) : 0;
    $edit_mode = isset($_POST['edit_mode']) ? sanitize_text_field($_POST['edit_mode']) : 'single';
    $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : null;
    
    if ($edit_mode === 'series' && $template_id) {
        // SERIES EDIT MODE - Update all future opportunities AND their shifts
        $from_opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT event_date FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $data = array(
            'description' => wp_kses_post(wp_unslash($_POST['description'])),
            'location' => sanitize_text_field(wp_unslash($_POST['location'])),
            'requirements' => wp_kses_post(wp_unslash($_POST['requirements'])),
            'required_roles' => !empty($_POST['required_roles']) ? json_encode(array_map('intval', $_POST['required_roles'])) : null,
            'conference' => sanitize_text_field(wp_unslash($_POST['conference'])),
            'status' => sanitize_text_field(wp_unslash($_POST['status'])),
            'allow_team_signups' => isset($_POST['allow_team_signups']) ? 1 : 0,
            'point_of_contact_id' => !empty($_POST['point_of_contact_id']) ? intval($_POST['point_of_contact_id']) : null
        );

        // Update all future opportunities in the series
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities
            SET description = %s, location = %s, requirements = %s, required_roles = %s, conference = %s, status = %s, allow_team_signups = %d, point_of_contact_id = %d
            WHERE template_id = %d AND event_date >= %s",
            $data['description'],
            $data['location'],
            $data['requirements'],
            $data['required_roles'],
            $data['conference'],
            $data['status'],
            $data['allow_team_signups'],
            $data['point_of_contact_id'],
            $template_id,
            $from_opportunity->event_date
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("FriendShyft: Updated $affected opportunities in series");
        }

        // Update shifts for all opportunities in the series
        // First, get all opportunity IDs in the series
        $series_opportunity_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_opportunities
            WHERE template_id = %d AND event_date >= %s",
            $template_id,
            $from_opportunity->event_date
        ));

        if (!empty($series_opportunity_ids) && isset($_POST['shift_start_time']) && is_array($_POST['shift_start_time'])) {
            $new_shifts_data = array();
            $total_spots = 0;

            // Collect new shift data from form
            foreach ($_POST['shift_start_time'] as $index => $start_time) {
                $spots = intval($_POST['shift_spots'][$index]);
                $total_spots += $spots;

                $new_shifts_data[] = array(
                    'shift_start_time' => sanitize_text_field($start_time),
                    'shift_end_time' => sanitize_text_field($_POST['shift_end_time'][$index]),
                    'spots_available' => $spots,
                    'display_order' => intval($_POST['shift_order'][$index])
                );
            }

            // For each opportunity in the series, update its shifts
            foreach ($series_opportunity_ids as $opp_id) {
                // Get existing shifts for this opportunity
                $existing_shifts = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, spots_filled FROM {$wpdb->prefix}fs_opportunity_shifts
                    WHERE opportunity_id = %d
                    ORDER BY display_order ASC",
                    $opp_id
                ));

                // Update or create shifts to match the new configuration
                foreach ($new_shifts_data as $shift_index => $shift_data) {
                    if (isset($existing_shifts[$shift_index])) {
                        // Update existing shift, preserving spots_filled
                        $existing_shift = $existing_shifts[$shift_index];
                        $wpdb->update(
                            $wpdb->prefix . 'fs_opportunity_shifts',
                            array(
                                'shift_start_time' => $shift_data['shift_start_time'],
                                'shift_end_time' => $shift_data['shift_end_time'],
                                'spots_available' => $shift_data['spots_available'],
                                'display_order' => $shift_data['display_order']
                            ),
                            array('id' => $existing_shift->id)
                        );
                    } else {
                        // Create new shift if we have more shifts than before
                        $wpdb->insert(
                            $wpdb->prefix . 'fs_opportunity_shifts',
                            array(
                                'opportunity_id' => $opp_id,
                                'template_id' => $template_id,
                                'shift_start_time' => $shift_data['shift_start_time'],
                                'shift_end_time' => $shift_data['shift_end_time'],
                                'spots_available' => $shift_data['spots_available'],
                                'spots_filled' => 0,
                                'display_order' => $shift_data['display_order'],
                                'is_template' => 0
                            )
                        );
                    }
                }

                // Delete extra shifts if we have fewer shifts now
                if (count($existing_shifts) > count($new_shifts_data)) {
                    for ($i = count($new_shifts_data); $i < count($existing_shifts); $i++) {
                        $wpdb->delete(
                            $wpdb->prefix . 'fs_opportunity_shifts',
                            array('id' => $existing_shifts[$i]->id)
                        );
                    }
                }

                // Update opportunity totals
                $wpdb->update(
                    $wpdb->prefix . 'fs_opportunities',
                    array('spots_available' => $total_spots),
                    array('id' => $opp_id)
                );
            }

            // Update template shifts as well for future generation
            // First, get existing template shifts
            $template_shifts = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_opportunity_shifts
                WHERE template_id = %d AND is_template = 1
                ORDER BY display_order ASC",
                $template_id
            ));

            // Update or create template shifts
            foreach ($new_shifts_data as $shift_index => $shift_data) {
                if (isset($template_shifts[$shift_index])) {
                    // Update existing template shift
                    $wpdb->update(
                        $wpdb->prefix . 'fs_opportunity_shifts',
                        array(
                            'shift_start_time' => $shift_data['shift_start_time'],
                            'shift_end_time' => $shift_data['shift_end_time'],
                            'spots_available' => $shift_data['spots_available'],
                            'display_order' => $shift_data['display_order']
                        ),
                        array('id' => $template_shifts[$shift_index]->id)
                    );
                } else {
                    // Create new template shift
                    $wpdb->insert(
                        $wpdb->prefix . 'fs_opportunity_shifts',
                        array(
                            'template_id' => $template_id,
                            'shift_start_time' => $shift_data['shift_start_time'],
                            'shift_end_time' => $shift_data['shift_end_time'],
                            'spots_available' => $shift_data['spots_available'],
                            'spots_filled' => 0,
                            'display_order' => $shift_data['display_order'],
                            'is_template' => 1
                        )
                    );
                }
            }

            // Delete extra template shifts if we have fewer shifts now
            if (count($template_shifts) > count($new_shifts_data)) {
                for ($i = count($new_shifts_data); $i < count($template_shifts); $i++) {
                    $wpdb->delete(
                        $wpdb->prefix . 'fs_opportunity_shifts',
                        array('id' => $template_shifts[$i]->id)
                    );
                }
            }
        }

        // Log series update
        FS_Audit_Log::log('opportunity_updated', 'opportunity', $opportunity_id, array(
            'edit_mode' => 'series',
            'template_id' => $template_id,
            'affected_count' => $affected,
            'from_date' => $from_opportunity->event_date
        ));

        // Also update the template itself
        $wpdb->update(
            $wpdb->prefix . 'fs_opportunity_templates',
            $data,
            array('id' => $template_id)
        );

        $redirect = add_query_arg(
            array('page' => 'fs-opportunities', 'updated' => 'series'),
            admin_url('admin.php')
        );

    } else {
        // SINGLE EDIT MODE or NEW OPPORTUNITY
        $data = array(
            'title' => sanitize_text_field(wp_unslash($_POST['title'])),
            'description' => wp_kses_post(wp_unslash($_POST['description'])),
            'location' => sanitize_text_field(wp_unslash($_POST['location'])),
            'event_date' => sanitize_text_field(wp_unslash($_POST['event_date'])),
            'datetime_start' => !empty($_POST['datetime_start']) ? sanitize_text_field(wp_unslash($_POST['datetime_start'])) : null,
            'datetime_end' => !empty($_POST['datetime_end']) ? sanitize_text_field(wp_unslash($_POST['datetime_end'])) : null,
            'requirements' => wp_kses_post(wp_unslash($_POST['requirements'])),
            'conference' => sanitize_text_field(wp_unslash($_POST['conference'])),
            'status' => sanitize_text_field(wp_unslash($_POST['status'])),
            'allow_team_signups' => isset($_POST['allow_team_signups']) ? 1 : 0,
            'point_of_contact_id' => !empty($_POST['point_of_contact_id']) ? intval($_POST['point_of_contact_id']) : null
        );
        
        if ($opportunity_id > 0) {
            // UPDATE EXISTING OPPORTUNITY
            $wpdb->update(
                $wpdb->prefix . 'fs_opportunities',
                $data,
                array('id' => $opportunity_id)
            );

            // Log opportunity update
            FS_Audit_Log::log('opportunity_updated', 'opportunity', $opportunity_id, array(
                'title' => $data['title'],
                'event_date' => $data['event_date'],
                'edit_mode' => 'single'
            ));

            // Handle shifts
            $existing_shift_ids = isset($_POST['shift_ids']) ? array_map('intval', $_POST['shift_ids']) : array();
            
            // Update or create shifts
            if (isset($_POST['shift_start_time']) && is_array($_POST['shift_start_time'])) {
                $total_spots = 0;
                
                foreach ($_POST['shift_start_time'] as $index => $start_time) {
                    $spots = intval($_POST['shift_spots'][$index]);
                    $total_spots += $spots;
                    
                    $shift_data = array(
                        'shift_start_time' => sanitize_text_field($start_time),
                        'shift_end_time' => sanitize_text_field($_POST['shift_end_time'][$index]),
                        'spots_available' => $spots,
                        'display_order' => intval($_POST['shift_order'][$index])
                    );
                    
                    if (isset($existing_shift_ids[$index]) && $existing_shift_ids[$index] > 0) {
                        // Update existing shift
                        $wpdb->update(
                            $wpdb->prefix . 'fs_opportunity_shifts',
                            $shift_data,
                            array('id' => $existing_shift_ids[$index])
                        );
                    } else {
                        // Create new shift
                        $shift_data['opportunity_id'] = $opportunity_id;
                        $shift_data['spots_filled'] = 0;
                        $shift_data['is_template'] = 0;
                        $wpdb->insert($wpdb->prefix . 'fs_opportunity_shifts', $shift_data);
                    }
                }
                
                // Update opportunity totals
                $wpdb->update(
                    $wpdb->prefix . 'fs_opportunities',
                    array('spots_available' => $total_spots),
                    array('id' => $opportunity_id)
                );
            } elseif (isset($_POST['spots_available'])) {
                // Legacy support for old single-spot opportunities
                $wpdb->update(
                    $wpdb->prefix . 'fs_opportunities',
                    array('spots_available' => intval($_POST['spots_available'])),
                    array('id' => $opportunity_id)
                );
            }
            
            $redirect = add_query_arg(
                array('page' => 'fs-opportunities', 'updated' => '1'),
                admin_url('admin.php')
            );
            
        } else {
            // CREATE NEW OPPORTUNITY
            $data['template_id'] = null; // One-off opportunities have no template
            $data['spots_available'] = 0; // Will be calculated from shifts
            $data['spots_filled'] = 0;
            
            $wpdb->insert(
                $wpdb->prefix . 'fs_opportunities',
                $data
            );

            $opportunity_id = $wpdb->insert_id;

            // Log opportunity creation
            FS_Audit_Log::log('opportunity_created', 'opportunity', $opportunity_id, array(
                'title' => $data['title'],
                'event_date' => $data['event_date'],
                'source' => 'manual'
            ));
            
            // Create shifts
            if (isset($_POST['shift_start_time']) && is_array($_POST['shift_start_time'])) {
                $total_spots = 0;
                
                foreach ($_POST['shift_start_time'] as $index => $start_time) {
                    $spots = intval($_POST['shift_spots'][$index]);
                    $total_spots += $spots;
                    
                    $wpdb->insert(
                        $wpdb->prefix . 'fs_opportunity_shifts',
                        array(
                            'opportunity_id' => $opportunity_id,
                            'shift_start_time' => sanitize_text_field($start_time),
                            'shift_end_time' => sanitize_text_field($_POST['shift_end_time'][$index]),
                            'spots_available' => $spots,
                            'spots_filled' => 0,
                            'display_order' => intval($_POST['shift_order'][$index]),
                            'is_template' => 0
                        )
                    );
                }
                
                // Update opportunity with total spots
                $wpdb->update(
                    $wpdb->prefix . 'fs_opportunities',
                    array('spots_available' => $total_spots),
                    array('id' => $opportunity_id)
                );
            }
            
            $redirect = add_query_arg(
                array('page' => 'fs-opportunities', 'created' => '1'),
                admin_url('admin.php')
            );
        }
    }
    
    wp_redirect($redirect);
    exit;
}
    
    public static function handle_delete() {
        if (!isset($_GET['id'])) {
            wp_die('Missing opportunity ID');
        }

        $opportunity_id = intval($_GET['id']);

        // check_admin_referer() will die automatically if nonce is invalid
        check_admin_referer('fs_delete_opportunity_' . $opportunity_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        // Get opportunity for Monday.com deletion
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        // Log opportunity deletion before actually deleting
        if ($opportunity) {
            FS_Audit_Log::log('opportunity_deleted', 'opportunity', $opportunity_id, array(
                'title' => $opportunity->title,
                'event_date' => $opportunity->event_date,
                'template_id' => $opportunity->template_id
            ));
        }

        // Delete from database
        $wpdb->delete(
            $wpdb->prefix . 'fs_opportunities',
            array('id' => $opportunity_id)
        );

        // Delete from Monday.com if configured and has Monday ID
        if (FS_Monday_API::is_configured() && !empty($opportunity->monday_id)) {
            $api = new FS_Monday_API();
            $mutation = 'mutation { delete_item(item_id: ' . $opportunity->monday_id . ') { id } }';
            $api->query_raw($mutation);
        }

        $redirect = add_query_arg(
            array('page' => 'fs-opportunities', 'deleted' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function list_page() {
        global $wpdb;
        
        // Handle messages
        if (isset($_GET['created'])) {
            echo '<div class="notice notice-success"><p>Opportunity created successfully!</p></div>';
        }
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Opportunity updated successfully!</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success"><p>Opportunity deleted successfully!</p></div>';
        }
        if (isset($_GET['series_cancelled'])) {
            echo '<div class="notice notice-success"><p>All future opportunities cancelled successfully!</p></div>';
        }
        
        // Get filter parameters
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
        $template_filter = isset($_GET['template_filter']) ? intval($_GET['template_filter']) : 0;
        
        // Build query
        $where = array("1=1");
        if ($status_filter) {
            $where[] = $wpdb->prepare("o.status = %s", $status_filter);
        }
        if ($template_filter) {
            $where[] = $wpdb->prepare("o.template_id = %d", $template_filter);
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Get opportunities with template info
        $opportunities = $wpdb->get_results(
            "SELECT o.*, 
                    t.title as template_title,
                    t.status as template_status,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups WHERE opportunity_id = o.id AND status = 'confirmed') as signup_count
             FROM {$wpdb->prefix}fs_opportunities o
             LEFT JOIN {$wpdb->prefix}fs_opportunity_templates t ON o.template_id = t.id
             WHERE $where_clause
             ORDER BY o.event_date DESC, o.title ASC
             LIMIT 100"
        );
        
        // Get templates for filter dropdown
        $templates = $wpdb->get_results(
            "SELECT id, title FROM {$wpdb->prefix}fs_opportunity_templates ORDER BY title ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>
                Volunteer Opportunities
                <a href="<?php echo admin_url('admin.php?page=fs-add-opportunity'); ?>" class="page-title-action">Add One-Off Opportunity</a>
                <a href="<?php echo admin_url('admin.php?page=fs-templates'); ?>" class="page-title-action">Manage Templates</a>
            </h1>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="status_filter" id="status-filter" onchange="applyFilters()">
                        <option value="">All Statuses</option>
                        <option value="Draft" <?php selected($status_filter, 'Draft'); ?>>Draft</option>
                        <option value="Open" <?php selected($status_filter, 'Open'); ?>>Open</option>
                        <option value="Closed" <?php selected($status_filter, 'Closed'); ?>>Closed</option>
                        <option value="Cancelled" <?php selected($status_filter, 'Cancelled'); ?>>Cancelled</option>
                    </select>
                    
                    <select name="template_filter" id="template-filter" onchange="applyFilters()">
                        <option value="">All Sources</option>
                        <option value="-1" <?php selected($template_filter, -1); ?>>One-Off Only</option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?php echo $t->id; ?>" <?php selected($template_filter, $t->id); ?>>
                                <?php echo esc_html($t->title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <?php if (empty($opportunities)): ?>
                <p>No opportunities found. <a href="<?php echo admin_url('admin.php?page=fs-add-opportunity'); ?>">Create your first opportunity</a> or <a href="<?php echo admin_url('admin.php?page=fs-templates'); ?>">set up a template</a>.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Title</th>
                            <th style="width: 15%;">Date</th>
                            <th style="width: 10%;">Shifts</th>
                            <th style="width: 10%;">Signups</th>
                            <th style="width: 15%;">Source</th>
                            <th style="width: 10%;">Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($opportunities as $opp): ?>
                            <?php
                            // Get shift count
                            $shift_count = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_opportunity_shifts WHERE opportunity_id = %d",
                                $opp->id
                            ));
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($opp->title ?? ''); ?></strong>
                                    <?php if ($opp->location): ?>
                                        <br><small>📍 <?php echo esc_html($opp->location ?? ''); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('D, M j, Y', strtotime($opp->event_date)); ?></td>
                                <td><?php echo $shift_count; ?> shift(s)</td>
                                <td>
                                    <?php echo $opp->signup_count; ?> / <?php echo $opp->spots_available; ?>
                                    <?php if ($opp->signup_count >= $opp->spots_available): ?>
                                        <span style="color: #dc3545; font-weight: 600;">FULL</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($opp->template_id): ?>
                                        <a href="<?php echo admin_url('admin.php?page=fs-edit-template&id=' . $opp->template_id); ?>" title="View Template">
                                            🔁 <?php echo esc_html($opp->template_title ?? ''); ?>
                                        </a>
                                        <?php if ($opp->template_status === 'Paused'): ?>
                                            <br><small style="color: #856404;">⏸ Paused</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #666;">One-Off</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($opp->status ?? 'open'); ?>">
                                        <?php echo esc_html($opp->status ?? 'Open'); ?>
                                    </span>
                                </td>
                                <td>
    <a href="<?php echo admin_url('admin.php?page=fs-edit-opportunity&id=' . $opp->id); ?>">Edit</a>
    <?php if ($opp->template_id): ?>
        | <a href="<?php echo admin_url('admin.php?page=fs-edit-opportunity&id=' . $opp->id . '&edit_mode=series'); ?>" style="font-weight: 600;">Edit Series</a>
    <?php endif; ?>
    | <a href="<?php echo admin_url('admin.php?page=fs-manage-signups&opportunity_id=' . $opp->id); ?>" style="font-weight: 600; color: #0073aa;">Manage Signups</a>
    | <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_delete_opportunity&id=' . $opp->id), 'fs_delete_opportunity_' . $opp->id); ?>"
       onclick="return confirm('Delete this opportunity?');"
       style="color: #b32d2e;">Delete</a>
</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <script>
            function applyFilters() {
                const status = document.getElementById('status-filter').value;
                const template = document.getElementById('template-filter').value;
                window.location.href = '<?php echo admin_url('admin.php?page=fs-opportunities'); ?>&status_filter=' + status + '&template_filter=' + template;
            }
            </script>
            
            <style>
                .status-badge {
                    padding: 4px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: 600;
                }
                .status-open { background: #d4edda; color: #155724; }
                .status-closed { background: #f8d7da; color: #721c24; }
                .status-cancelled { background: #e2e3e5; color: #383d41; }
            </style>
        </div>
        <?php
    }
    
    public static function edit_page() {
    global $wpdb;

    $opportunity_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $edit_mode = isset($_GET['edit_mode']) ? sanitize_text_field($_GET['edit_mode']) : 'single';
    $is_new = ($opportunity_id === 0);

    $opportunity = null;
    $template = null;
    $shifts = array();

    if (!$is_new) {
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));
    
        if (!$opportunity) {
            wp_die('Opportunity not found');
        }
    
        // Get template if this is a template-generated opportunity
        if ($opportunity->template_id) {
            $template = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates WHERE id = %d",
                $opportunity->template_id
            ));
        }
    
        // Get shifts
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts 
            WHERE opportunity_id = %d 
            ORDER BY display_order ASC",
            $opportunity_id
        ));
    }

    ?>
    <div class="wrap">
        <h1>
            <?php 
            if ($is_new) {
                echo 'Add One-Off Opportunity';
            } elseif ($edit_mode === 'series' && $template) {
                echo 'Edit Series: ' . esc_html($template->title);
            } else {
                echo 'Edit Opportunity';
            }
            ?>
        </h1>
    
        <?php if ($edit_mode === 'series' && $template): ?>
            <div class="notice notice-warning">
                <p><strong>⚠️ Series Edit Mode:</strong> Changes will apply to ALL future opportunities in this series starting from <strong><?php echo date('M j, Y', strtotime($opportunity->event_date)); ?></strong>.</p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=fs-edit-opportunity&id=' . $opportunity_id); ?>" class="button">Edit Just This Occurrence</a>
                    <a href="<?php echo admin_url('admin.php?page=fs-edit-template&id=' . $template->id); ?>" class="button">Edit Entire Template</a>
                </p>
            </div>
        <?php elseif ($template && $edit_mode !== 'series'): ?>
            <div class="notice notice-info">
                <p>This opportunity is part of the <strong><?php echo esc_html($template->title); ?></strong> series.</p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=fs-edit-opportunity&id=' . $opportunity_id . '&edit_mode=series'); ?>" class="button button-primary">Edit All Future Occurrences</a>
                    <a href="<?php echo admin_url('admin.php?page=fs-edit-template&id=' . $template->id); ?>" class="button">Edit Template</a>
                </p>
            </div>
        <?php endif; ?>
    
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('fs_opportunity_form'); ?>
            <input type="hidden" name="action" value="fs_save_opportunity">
            <input type="hidden" name="edit_mode" value="<?php echo esc_attr($edit_mode); ?>">
            <?php if (!$is_new): ?>
                <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">
                <?php if ($template): ?>
                    <input type="hidden" name="template_id" value="<?php echo $template->id; ?>">
                <?php endif; ?>
            <?php endif; ?>
        
            <table class="form-table">
                <tr>
                    <th><label for="title">Title *</label></th>
                    <td>
                        <input type="text" id="title" name="title" 
                               value="<?php echo $opportunity ? esc_attr($opportunity->title) : ''; ?>" 
                               class="regular-text" required
                               <?php echo ($edit_mode === 'series') ? 'readonly' : ''; ?>>
                        <?php if ($edit_mode === 'series'): ?>
                            <p class="description">Title cannot be changed in series mode. Edit the template to change the title.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            
                <?php if ($edit_mode !== 'series'): ?>
                <tr>
                    <th><label for="event_date">Event Date *</label></th>
                    <td>
                        <input type="date" id="event_date" name="event_date" 
                               value="<?php echo $opportunity ? $opportunity->event_date : ''; ?>" 
                               required>
                    </td>
                </tr>
                <?php endif; ?>
                
                <tr>
                    <th><label for="description">Description</label></th>
                    <td>
                        <textarea id="description" name="description" rows="8" class="large-text"><?php echo $opportunity ? wp_kses_post($opportunity->description) : ''; ?></textarea>
                        <p class="description">Full description (HTML allowed: bold, italic, links, lists)</p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="location">Location</label></th>
                    <td>
                        <input type="text" id="location" name="location" 
                               value="<?php echo $opportunity ? esc_attr($opportunity->location) : ''; ?>" 
                               class="regular-text">
                    </td>
                </tr>
            
                <tr>
                    <th><label for="requirements">Requirements</label></th>
                    <td>
                        <textarea id="requirements" name="requirements" rows="3" class="large-text"><?php echo $opportunity ? wp_kses_post($opportunity->requirements) : ''; ?></textarea>
                        <p class="description">e.g., "Background check required", "Must be 18+" (HTML allowed: bold, italic, links, lists)</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="required_roles">Required Roles</label></th>
                    <td>
                        <?php
                        $all_roles = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}fs_roles WHERE status = 'Active' ORDER BY name ASC");
                        $selected_roles = $opportunity && !empty($opportunity->required_roles) ? json_decode($opportunity->required_roles, true) : array();
    
                        if (empty($all_roles)): ?>
                            <p class="description">No active roles defined. <a href="<?php echo admin_url('admin.php?page=fs-roles'); ?>" target="_blank">Create roles first</a>.</p>
                        <?php else: ?>
                            <p class="description">Leave unchecked to allow all volunteers, or select specific roles:</p>
                            <?php foreach ($all_roles as $role): ?>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" name="required_roles[]" value="<?php echo $role->id; ?>" 
                                           <?php echo in_array($role->id, $selected_roles) ? 'checked' : ''; ?>>
                                    <?php echo esc_html($role->name); ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            
                <tr>
                    <th><label for="conference">Conference/Category</label></th>
                    <td>
                        <input type="text" id="conference" name="conference" 
                               value="<?php echo $opportunity ? esc_attr($opportunity->conference) : ''; ?>" 
                               class="regular-text">
                    </td>
                </tr>
            
                <tr>
                    <th><label for="status">Status *</label></th>
                    <td>
                        <select id="status" name="status" required>
                            <option value="Draft" <?php echo $opportunity && $opportunity->status === 'Draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="Open" <?php echo $opportunity && $opportunity->status === 'Open' ? 'selected' : ''; ?>>Open</option>
                            <option value="Closed" <?php echo $opportunity && $opportunity->status === 'Closed' ? 'selected' : ''; ?>>Closed</option>
                            <option value="Cancelled" <?php echo $opportunity && $opportunity->status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                        <p class="description">Draft opportunities are not visible to volunteers</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="point_of_contact_id">Point of Contact</label></th>
                    <td>
                        <?php
                        // Get users who are POCs or admins
                        $poc_users = get_users(array(
                            'role__in' => array('fs_point_of_contact', 'administrator'),
                            'orderby' => 'display_name'
                        ));
                        ?>
                        <select id="point_of_contact_id" name="point_of_contact_id">
                            <option value="">None</option>
                            <?php foreach ($poc_users as $user): ?>
                                <option value="<?php echo $user->ID; ?>"
                                        <?php echo $opportunity && $opportunity->point_of_contact_id == $user->ID ? 'selected' : ''; ?>>
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">This person will be notified when volunteers sign up and can manage this opportunity from their dashboard.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="allow_team_signups">Team Signups</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="allow_team_signups" name="allow_team_signups" value="1"
                                   <?php echo ($opportunity && $opportunity->allow_team_signups) ? 'checked' : ''; ?>>
                            Allow teams to sign up for this opportunity
                        </label>
                        <p class="description">Check this to enable team-based signups alongside individual volunteers</p>
                    </td>
                </tr>
            </table>

            <h2>Time Shifts</h2>
            <?php if ($edit_mode === 'series'): ?>
                <div class="notice notice-info inline" style="margin: 10px 0;">
                    <p><strong>Note:</strong> Changes to shifts will apply to all future opportunities in this series.</p>
                </div>
            <?php endif; ?>
            <div id="shifts-container">
                <?php if (!empty($shifts)): ?>
                    <?php foreach ($shifts as $index => $shift): ?>
                        <div class="shift-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center; background: #f9f9f9; padding: 10px; border-radius: 4px;">
                            <input type="hidden" name="shift_ids[]" value="<?php echo $shift->id; ?>">
                            <input type="time" name="shift_start_time[]" value="<?php echo $shift->shift_start_time; ?>" required style="width: 120px;">
                            <span>to</span>
                            <input type="time" name="shift_end_time[]" value="<?php echo $shift->shift_end_time; ?>" required style="width: 120px;">
                            <input type="number" name="shift_spots[]" value="<?php echo $shift->spots_available; ?>" min="1" required style="width: 80px;" placeholder="Spots">
                            <?php if ($edit_mode !== 'series'): ?>
                                <span style="color: #666; font-size: 13px;">(<?php echo $shift->spots_filled; ?> filled)</span>
                            <?php endif; ?>
                            <input type="hidden" name="shift_order[]" value="<?php echo $index; ?>">
                            <button type="button" class="button remove-shift">Remove</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="shift-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center; background: #f9f9f9; padding: 10px; border-radius: 4px;">
                        <input type="time" name="shift_start_time[]" value="09:00" required style="width: 120px;">
                        <span>to</span>
                        <input type="time" name="shift_end_time[]" value="11:00" required style="width: 120px;">
                        <input type="number" name="shift_spots[]" value="5" min="1" required style="width: 80px;" placeholder="Spots">
                        <input type="hidden" name="shift_order[]" value="0">
                        <button type="button" class="button remove-shift">Remove</button>
                    </div>
                <?php endif; ?>
            </div>

            <p>
                <button type="button" class="button" id="add-shift">+ Add Another Shift</button>
            </p>
        
            <p class="submit">
                <input type="submit" name="fs_save_opportunity" class="button button-primary" 
                       value="<?php echo $is_new ? 'Create Opportunity' : ($edit_mode === 'series' ? 'Update Series' : 'Update Opportunity'); ?>">
                <a href="<?php echo admin_url('admin.php?page=fs-opportunities'); ?>" class="button">Cancel</a>
            
                <?php if ($template && $edit_mode === 'series'): ?>
                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_cancel_future_series&template_id=' . $template->id . '&from_date=' . $opportunity->event_date), 'fs_cancel_series'); ?>" 
                       class="button" 
                       onclick="return confirm('Cancel all future opportunities in this series starting from <?php echo date('M j, Y', strtotime($opportunity->event_date)); ?>? This cannot be undone.');"
                       style="float: right; background: #dc3545; color: white; border-color: #dc3545;">
                        Cancel All Future Occurrences
                    </a>
                <?php endif; ?>
            </p>
        </form>

        <script>
        jQuery(document).ready(function($) {
            let shiftIndex = <?php echo count($shifts); ?>;

            $('#add-shift').on('click', function() {
                const newRow = `
                    <div class="shift-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center; background: #f9f9f9; padding: 10px; border-radius: 4px;">
                        <input type="time" name="shift_start_time[]" required style="width: 120px;">
                        <span>to</span>
                        <input type="time" name="shift_end_time[]" required style="width: 120px;">
                        <input type="number" name="shift_spots[]" value="5" min="1" required style="width: 80px;" placeholder="Spots">
                        <input type="hidden" name="shift_order[]" value="` + shiftIndex + `">
                        <button type="button" class="button remove-shift">Remove</button>
                    </div>
                `;
                $('#shifts-container').append(newRow);
                shiftIndex++;
            });

            $(document).on('click', '.remove-shift', function() {
                if ($('.shift-row').length > 1) {
                    $(this).closest('.shift-row').remove();
                } else {
                    alert('You must have at least one shift.');
                }
            });
        });
        </script>
    </div>
    <?php
}
    
    private static function create_in_monday($opportunity_id) {
        global $wpdb;
        
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));
        
        if (!$opportunity) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['opportunities'])) {
            return false;
        }
        
        $column_values = array(
            'text_mkxuk1uh' => $opportunity->location,
            'date_mkxuk4g0' => array(
                'date' => date('Y-m-d', strtotime($opportunity->datetime_start)),
                'time' => date('H:i:s', strtotime($opportunity->datetime_start))
            ),
            'numbers_mkxuk56w' => $opportunity->spots_available,
            'numbers_mkxuk7cu' => $opportunity->spots_filled,
            'color_mkxuk8ey' => array('label' => $opportunity->status)
        );
        
        if (!empty($opportunity->description)) {
            $column_values['long_text_mkxuk01a'] = $opportunity->description;
        }
        
        if (!empty($opportunity->conference)) {
            $column_values['text_mkxukgkn'] = $opportunity->conference;
        }
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            create_item(
                board_id: ' . $board_ids['opportunities'] . ',
                item_name: "' . addslashes($opportunity->title) . '",
                column_values: "' . $column_values_escaped . '"
            ) {
                id
            }
        }';
        
        $result = $api->query_raw($mutation);
        
        if ($result && isset($result['create_item']['id'])) {
            return $result['create_item']['id'];
        }
        
        return false;
    }
    
    private static function sync_to_monday($opportunity_id) {
        global $wpdb;
        
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));
        
        if (!$opportunity || !$opportunity->monday_id) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['opportunities'])) {
            return false;
        }
        
        $column_values = array(
            'text_mkxuk1uh' => $opportunity->location,
            'date_mkxuk4g0' => array(
                'date' => date('Y-m-d', strtotime($opportunity->datetime_start)),
                'time' => date('H:i:s', strtotime($opportunity->datetime_start))
            ),
            'numbers_mkxuk56w' => $opportunity->spots_available,
            'numbers_mkxuk7cu' => $opportunity->spots_filled,
            'color_mkxuk8ey' => array('label' => $opportunity->status),
            'long_text_mkxuk01a' => $opportunity->description,
            'text_mkxukgkn' => $opportunity->conference
        );
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            change_multiple_column_values(
                item_id: ' . $opportunity->monday_id . ',
                board_id: ' . $board_ids['opportunities'] . ',
                column_values: "' . $column_values_escaped . '"
            ) {
                id
            }
        }';
        
        $api->query_raw($mutation);
        
        return true;
    }

    public static function handle_cancel_future_series() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft: handle_cancel_future_series called');
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('GET data: ' . print_r($_GET, true));
        }
        
        if (!check_admin_referer('fs_cancel_series')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Cancel series nonce check failed');
            }
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $template_id = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0;
        $from_date = isset($_GET['from_date']) ? sanitize_text_field($_GET['from_date']) : '';
        
        if (!$template_id || !$from_date) {
            wp_die('Missing required parameters');
        }
        
        global $wpdb;
        
        // Cancel all future opportunities - use query() instead of update() for >= operator
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities 
            SET status = 'Cancelled' 
            WHERE template_id = %d AND event_date >= %s",
            $template_id,
            $from_date
        ));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("FriendShyft: Cancelled $affected opportunities");
        }
        
        $redirect = add_query_arg(
            array('page' => 'fs-opportunities', 'series_cancelled' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
}

FS_Admin_Opportunities::init();
