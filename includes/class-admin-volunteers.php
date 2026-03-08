<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Volunteers {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);
        add_action('admin_init', array(__CLASS__, 'handle_form_submission'));
        add_action('admin_post_fs_add_role', array(__CLASS__, 'handle_add_role'));
        add_action('admin_post_fs_remove_role', array(__CLASS__, 'handle_remove_role'));
        add_action('admin_post_fs_assign_workflow', array(__CLASS__, 'handle_assign_workflow'));
        add_action('admin_init', array(__CLASS__, 'handle_pin_qr_actions'));
        add_action('admin_post_fs_generate_token', array(__CLASS__, 'handle_generate_token'));
        add_action('admin_post_fs_resend_welcome_email', array(__CLASS__, 'handle_resend_welcome_email'));
        add_action('admin_post_fs_export_volunteers_csv', array(__CLASS__, 'handle_export_volunteers_csv'));

        // AJAX handler for admin workflow step completion
        add_action('wp_ajax_fs_admin_complete_step', array(__CLASS__, 'ajax_admin_complete_step'));

        // AJAX handlers for blocked time management
        add_action('wp_ajax_fs_add_blocked_time', array(__CLASS__, 'ajax_add_blocked_time'));
        add_action('wp_ajax_fs_delete_blocked_time', array(__CLASS__, 'ajax_delete_blocked_time'));
    }
    
    public static function add_menu_pages() {
        // Volunteers list
        add_submenu_page(
            'friendshyft',
            'Manage Volunteers',
            'Volunteers',
            'manage_options',
            'fs-volunteers',
            array(__CLASS__, 'volunteers_list_page')
        );
        
        // Volunteer detail (hidden from menu)
        add_submenu_page(
            null,
            'Volunteer Details',
            'Volunteer Details',
            'manage_options',
            'fs-volunteer-detail',
            array(__CLASS__, 'volunteer_detail_page')
        );
        
        // Edit volunteer (hidden from menu)
        add_submenu_page(
            null,
            'Edit Volunteer',
            'Edit Volunteer',
            'manage_options',
            'fs-edit-volunteer',
            array(__CLASS__, 'edit_volunteer_page')
        );
    }
    
    public static function handle_form_submission() {
        // Handle volunteer edit form
        if (isset($_POST['fs_save_volunteer'])) {
            if (!check_admin_referer('fs_volunteer_form')) {
                return;
            }
            
            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }
            
            global $wpdb;
            
            $volunteer_id = isset($_POST['volunteer_id']) ? intval($_POST['volunteer_id']) : 0;
            
            $data = array(
                'name' => sanitize_text_field($_POST['name'] ?? ''),
                'email' => sanitize_email($_POST['email'] ?? ''),
                'phone' => sanitize_text_field($_POST['phone'] ?? ''),
                'birthdate' => !empty($_POST['birthdate']) ? sanitize_text_field($_POST['birthdate']) : null,
                'volunteer_status' => sanitize_text_field($_POST['volunteer_status'] ?? 'active'),
                'types' => sanitize_text_field($_POST['types'] ?? ''),
                'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
                'background_check_status' => sanitize_text_field($_POST['background_check_status'] ?? 'pending'),
                'background_check_date' => !empty($_POST['background_check_date']) ? sanitize_text_field($_POST['background_check_date']) : null,
                'background_check_org' => sanitize_text_field($_POST['background_check_org'] ?? ''),
                'background_check_expiration' => !empty($_POST['background_check_expiration']) ? sanitize_text_field($_POST['background_check_expiration']) : null,
                'last_sync' => current_time('mysql')
            );
            
            if ($volunteer_id > 0) {
                // Update existing volunteer
                $wpdb->update(
                    $wpdb->prefix . 'fs_volunteers',
                    $data,
                    array('id' => $volunteer_id)
                );
                
                // Sync to Monday.com if configured
                if (FS_Monday_API::is_configured()) {
                    $volunteer = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                        $volunteer_id
                    ));
                    
                    if ($volunteer && $volunteer->monday_id) {
                        self::sync_volunteer_to_monday($volunteer);
                    }
                }
                
                $redirect = add_query_arg(
                    array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'updated' => '1'),
                    admin_url('admin.php')
                );
            } else {
                // This shouldn't happen as we don't have an "add volunteer" form yet
                // Volunteers are created via the public registration form
                return;
            }
            
            wp_redirect($redirect);
            exit;
        }
        
        // Handle bulk PIN generation
        if (isset($_POST['action']) && $_POST['action'] === 'generate_all_pins') {
            check_admin_referer('fs_bulk_pin_generation');
            
            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }
            
            global $wpdb;
            $volunteers = $wpdb->get_results(
                "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE (pin IS NULL OR pin = '') AND volunteer_status = 'Active'"
            );
            
            $count = 0;
            foreach ($volunteers as $volunteer) {
                FS_Time_Tracking::generate_pin($volunteer->id);
                $count++;
            }
            
            $redirect = add_query_arg(
                array('page' => 'fs-volunteers', 'pins_generated' => $count),
                admin_url('admin.php')
            );
            wp_redirect($redirect);
            exit;
        }
        
        // Handle bulk QR generation
        if (isset($_POST['action']) && $_POST['action'] === 'generate_all_qrs') {
            check_admin_referer('fs_bulk_qr_generation');
            
            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized');
            }
            
            global $wpdb;
            $volunteers = $wpdb->get_results(
                "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE (qr_code IS NULL OR qr_code = '') AND volunteer_status = 'Active'"
            );
            
            $count = 0;
            foreach ($volunteers as $volunteer) {
                FS_Time_Tracking::generate_qr_code($volunteer->id);
                $count++;
            }
            
            $redirect = add_query_arg(
                array('page' => 'fs-volunteers', 'qrs_generated' => $count),
                admin_url('admin.php')
            );
            wp_redirect($redirect);
            exit;
        }
    }
    
    public static function handle_pin_qr_actions() {
        if (!isset($_POST['action']) || !isset($_POST['volunteer_id'])) {
            return;
        }

        // Only handle specific PIN/QR actions, not all requests with volunteer_id
        $action = sanitize_text_field($_POST['action']);
        if (!in_array($action, array('generate_pin', 'regenerate_pin', 'generate_qr', 'regenerate_qr'))) {
            return;
        }

        if (!check_admin_referer('fs_volunteer_action')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
        $action = sanitize_text_field($_POST['action'] ?? '');
        
        switch ($action) {
            case 'generate_pin':
                $pin = FS_Time_Tracking::generate_pin($volunteer_id);
                $redirect = add_query_arg(
                    array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'pin_generated' => '1'),
                    admin_url('admin.php')
                );
                wp_redirect($redirect);
                exit;
                
            case 'regenerate_pin':
                $pin = FS_Time_Tracking::generate_pin($volunteer_id);
                $redirect = add_query_arg(
                    array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'pin_regenerated' => '1'),
                    admin_url('admin.php')
                );
                wp_redirect($redirect);
                exit;
                
            case 'generate_qr':
                $qr_code = FS_Time_Tracking::generate_qr_code($volunteer_id);
                $redirect = add_query_arg(
                    array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'qr_generated' => '1'),
                    admin_url('admin.php')
                );
                wp_redirect($redirect);
                exit;
                
            case 'regenerate_qr':
                $qr_code = FS_Time_Tracking::generate_qr_code($volunteer_id);
                $redirect = add_query_arg(
                    array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'qr_regenerated' => '1'),
                    admin_url('admin.php')
                );
                wp_redirect($redirect);
                exit;
        }
    }

    public static function handle_generate_token() {
        if (!check_admin_referer('fs_generate_token')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);

        global $wpdb;

        // Check if volunteer exists
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            wp_die('Volunteer not found');
        }

        // Generate new token (or keep existing if present)
        if (empty($volunteer->access_token)) {
            $access_token = bin2hex(random_bytes(32));

            $wpdb->update(
                $wpdb->prefix . 'fs_volunteers',
                array('access_token' => $access_token),
                array('id' => $volunteer_id)
            );
        } else {
            $access_token = $volunteer->access_token;
        }

        $redirect = add_query_arg(
            array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'token_generated' => '1'),
            admin_url('admin.php')
        );

        wp_redirect($redirect);
        exit;
    }

    public static function handle_add_role() {
        if (!check_admin_referer('fs_volunteer_action')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
        $role_id = intval($_POST['role_id'] ?? 0);

        global $wpdb;

        // Check if already assigned
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_volunteer_roles WHERE volunteer_id = %d AND role_id = %d",
            $volunteer_id,
            $role_id
        ));
        
        if (!$exists) {
            $wpdb->insert(
                $wpdb->prefix . 'fs_volunteer_roles',
                array(
                    'volunteer_id' => $volunteer_id,
                    'role_id' => $role_id,
                    'assigned_date' => current_time('mysql')
                )
            );
        }
        
        $redirect = add_query_arg(
            array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'role_added' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function handle_remove_role() {
        if (!check_admin_referer('fs_volunteer_action')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
        $role_id = intval($_POST['role_id'] ?? 0);

        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'fs_volunteer_roles',
            array(
                'volunteer_id' => $volunteer_id,
                'role_id' => $role_id
            )
        );
        
        $redirect = add_query_arg(
            array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'role_removed' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function handle_assign_workflow() {
        if (!check_admin_referer('fs_volunteer_action')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
        $workflow_id = intval($_POST['workflow_id'] ?? 0);

        global $wpdb;

        // Check if already assigned
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_progress WHERE volunteer_id = %d AND workflow_id = %d",
            $volunteer_id,
            $workflow_id
        ));
        
        if ($exists) {
            $redirect = add_query_arg(
                array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'workflow_error' => '1'),
                admin_url('admin.php')
            );
            wp_redirect($redirect);
            exit;
        }
        
        // Get workflow steps
        $workflow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_workflows WHERE id = %d",
            $workflow_id
        ));
        
        if (!$workflow) {
            $redirect = add_query_arg(
                array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'workflow_error' => '1'),
                admin_url('admin.php')
            );
            wp_redirect($redirect);
            exit;
        }
        
        $steps = json_decode($workflow->steps, true);
        
        // Initialize step completions
        $step_completions = array();
        foreach ($steps as $step) {
            $step_completions[] = array(
                'name' => $step['name'],
                'type' => $step['type'],
                'required' => $step['required'],
                'completed' => false,
                'completed_date' => null,
                'completed_by' => null,
                'monday_id' => null
            );
        }
        
        // Create progress record
        $wpdb->insert(
            $wpdb->prefix . 'fs_progress',
            array(
                'volunteer_id' => $volunteer_id,
                'workflow_id' => $workflow_id,
                'overall_status' => 'Not Started',
                'progress_percentage' => 0,
                'step_completions' => json_encode($step_completions),
                'last_sync' => current_time('mysql')
            )
        );
        
        $redirect = add_query_arg(
            array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'workflow_assigned' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function volunteers_list_page() {
        
        global $wpdb;
        
        // Get filter values
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Build query
        $where = array('1=1');
        $params = array();

        if ($status_filter) {
            $where[] = 'volunteer_status = %s';
            $params[] = $status_filter;
        }
        
        if ($search) {
            $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $where_clause = implode(' AND ', $where);

        // Get volunteers
        if (!empty($params)) {
            $volunteers = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE $where_clause ORDER BY name ASC",
                ...$params
            ));
        } else {
            $volunteers = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE $where_clause ORDER BY name ASC"
            );
        }

        // Get unique statuses for filter
        $statuses = $wpdb->get_col("SELECT DISTINCT volunteer_status FROM {$wpdb->prefix}fs_volunteers ORDER BY volunteer_status");
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Volunteers</h1>
            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_export_volunteers_csv'), 'fs_export_volunteers'); ?>" class="page-title-action">
                Export to CSV
            </a>
            <hr class="wp-header-end">
            
            <?php if (isset($_GET['pins_generated'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['pins_generated']); ?> PINs generated successfully!</p></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['qrs_generated'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo intval($_GET['qrs_generated']); ?> QR codes generated successfully!</p></div>
            <?php endif; ?>
            
            <div style="margin-bottom: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                <h3 style="margin-top: 0;">Quick Actions</h3>
                <form method="post" style="display: inline-block; margin-right: 15px;">
                    <?php wp_nonce_field('fs_bulk_pin_generation'); ?>
                    <input type="hidden" name="action" value="generate_all_pins">
                    <button type="submit" class="button" onclick="return confirm('Generate PINs for all volunteers who don\'t have one?');">
                        🔢 Generate Missing PINs
                    </button>
                </form>
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('fs_bulk_qr_generation'); ?>
                    <input type="hidden" name="action" value="generate_all_qrs">
                    <button type="submit" class="button" onclick="return confirm('Generate QR codes for all volunteers who don\'t have one?');">
                        📱 Generate Missing QR Codes
                    </button>
                </form>
            </div>
            
            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="fs-volunteer-filter" style="display: flex; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="fs-volunteers">

                    <select name="status" id="fs-status-filter" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php selected($status_filter, $status); ?>>
                                <?php echo esc_html($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search volunteers...">

                    <input type="submit" class="button" value="Search">

                    <?php if ($status_filter || $search): ?>
                        <a href="<?php echo admin_url('admin.php?page=fs-volunteers'); ?>" class="button">Clear Filters</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <?php if (empty($volunteers)): ?>
                <p>No volunteers found.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Name</th>
                            <th style="width: 25%;">Email</th>
                            <th style="width: 15%;">Phone</th>
                            <th style="width: 15%;">Status</th>
                            <th style="width: 10%;">PIN</th>
                            <th style="width: 10%;">QR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($volunteers as $volunteer): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $volunteer->id); ?>">
                                            <?php echo esc_html($volunteer->name); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html($volunteer->email); ?></td>
                                <td><?php echo esc_html($volunteer->phone ?: '—'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr(strtolower($volunteer->volunteer_status ?: 'unknown')); ?>">
                                        <?php echo esc_html($volunteer->volunteer_status ?: 'Unknown'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($volunteer->pin): ?>
                                        <code><?php echo esc_html($volunteer->pin); ?></code>
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($volunteer->qr_code): ?>
                                        ✓
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <style>
            .status-badge {
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                display: inline-block;
            }
            .status-active { background: #d4edda; color: #155724; }
            .status-prospect { background: #fff3cd; color: #856404; }
            .status-inactive { background: #f8d7da; color: #721c24; }
            .status-unknown { background: #f0f0f0; color: #666; }
        </style>
        <?php
    }
    
    public static function volunteer_detail_page() {
        
        global $wpdb;
        
        $volunteer_id = intval($_GET['id'] ?? 0);
        
        if (!$volunteer_id) {
            echo '<div class="wrap"><h1>Volunteer not found</h1></div>';
            return;
        }
        
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));
        
        if (!$volunteer) {
            echo '<div class="wrap"><h1>Volunteer not found</h1></div>';
            return;
        }
        
        // Get roles
        $roles = $wpdb->get_results($wpdb->prepare(
            "SELECT r.* FROM {$wpdb->prefix}fs_volunteer_roles vr
            JOIN {$wpdb->prefix}fs_roles r ON vr.role_id = r.id
            WHERE vr.volunteer_id = %d
            ORDER BY r.name",
            $volunteer->id
        ));
        
        // Get progress records
        $progress_records = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, w.name as workflow_name, w.steps as workflow_steps
            FROM {$wpdb->prefix}fs_progress p
            JOIN {$wpdb->prefix}fs_workflows w ON p.workflow_id = w.id
            WHERE p.volunteer_id = %d
            ORDER BY p.last_sync DESC",
            $volunteer->id
        ));
        
        // Get the first progress record for display
        $progress = !empty($progress_records) ? $progress_records[0] : null;
        $workflow_steps = array();
        $step_completions = array();
        
        if ($progress) {
            if ($progress->workflow_steps) {
                $workflow_steps = json_decode($progress->workflow_steps, true);
            }
            
            if ($progress->step_completions) {
                $step_completions_array = json_decode($progress->step_completions, true);
                
                // Index by name for easy lookup
                $completions_by_name = array();
                foreach ($step_completions_array as $completion) {
                    $completions_by_name[$completion['name']] = $completion;
                }
                $step_completions = $completions_by_name;
            }
        }
        
        // Get upcoming signups
        $upcoming_signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.title, o.datetime_start, o.datetime_end, o.location
            FROM {$wpdb->prefix}fs_signups s
            JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
            WHERE s.volunteer_id = %d 
            AND s.status IN ('confirmed', 'pending')
            AND o.datetime_start >= NOW()
            ORDER BY o.datetime_start ASC",
            $volunteer->id
        ));
        
        // Get past signups
        $past_signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, o.title, o.datetime_start, o.datetime_end, o.location
            FROM {$wpdb->prefix}fs_signups s
            JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
            WHERE s.volunteer_id = %d 
            AND (s.status = 'completed' OR o.datetime_start < NOW())
            ORDER BY o.datetime_start DESC
            LIMIT 20",
            $volunteer->id
        ));
        
        // Get all roles for assignment
        $all_roles = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_roles WHERE status = 'Active' ORDER BY name"
        );
        
        // Get all workflows for assignment
        $all_workflows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_workflows WHERE status = 'Active' ORDER BY name"
        );
        
        $board_ids = get_option('fs_board_ids', array());
        
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html($volunteer->name); ?>
                <a href="<?php echo admin_url('admin.php?page=fs-edit-volunteer&id=' . $volunteer->id); ?>" class="page-title-action">
                    Edit
                </a>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_resend_welcome_email&volunteer_id=' . $volunteer->id), 'fs_resend_welcome_' . $volunteer->id); ?>"
                   class="page-title-action"
                   onclick="return confirm('Resend welcome email to <?php echo esc_js($volunteer->email); ?>?');">
                    Resend Welcome Email
                </a>
                <?php if (!empty($board_ids['people']) && !empty($volunteer->monday_id)): ?>
                <a href="https://monday.com/boards/<?php echo $board_ids['people']; ?>/pulses/<?php echo $volunteer->monday_id; ?>" target="_blank" class="page-title-action">
                    View in Monday.com
                </a>
                <?php endif; ?>
            </h1>
            
            <?php
            // Show success/error messages
            if (isset($_GET['role_added'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Role added successfully!</p></div>';
            }
            if (isset($_GET['role_removed'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Role removed successfully!</p></div>';
            }
            if (isset($_GET['workflow_assigned'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Workflow assigned successfully!</p></div>';
            }
            if (isset($_GET['workflow_error'])) {
                echo '<div class="notice notice-error is-dismissible"><p>Error assigning workflow. Please try again.</p></div>';
            }
            if (isset($_GET['updated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Volunteer updated successfully!</p></div>';
            }
            if (isset($_GET['pin_generated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>PIN generated successfully!</p></div>';
            }
            if (isset($_GET['pin_regenerated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>PIN regenerated successfully!</p></div>';
            }
            if (isset($_GET['qr_generated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>QR code generated successfully!</p></div>';
            }
            if (isset($_GET['qr_regenerated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>QR code regenerated successfully!</p></div>';
            }
            if (isset($_GET['token_generated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Access token generated successfully! See the magic link below.</p></div>';
            }
            if (isset($_GET['welcome_sent'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Welcome email resent successfully!</p></div>';
            }
            if (isset($_GET['welcome_error'])) {
                echo '<div class="notice notice-error is-dismissible"><p>Error sending welcome email. Please try again.</p></div>';
            }
            ?>
            
            <?php if ($progress && $progress->overall_status !== 'Complete'): ?>
            <div class="onboarding-section">
                <h2>Onboarding Progress</h2>
                <div class="onboarding-card">
                    <div class="onboarding-header">
                        <div>
                            <h3><?php echo esc_html($progress->workflow_name); ?></h3>
                            <p class="onboarding-status">Status: <strong><?php echo esc_html($progress->overall_status); ?></strong></p>
                        </div>
                        <div class="progress-circle">
                            <span class="progress-number"><?php echo $progress->progress_percentage; ?>%</span>
                        </div>
                    </div>
                    
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: <?php echo $progress->progress_percentage; ?>%"></div>
                    </div>
                    
                    <?php if (!empty($workflow_steps)): ?>
                    <div class="onboarding-steps">
                        <?php foreach ($workflow_steps as $step): ?>
                            <?php
                            $completion = isset($step_completions[$step['name']]) ? $step_completions[$step['name']] : null;
                            $is_completed = $completion && $completion['completed'];
                            $step_class = $is_completed ? 'step-complete' : 'step-incomplete';
                            $can_staff_complete = !$is_completed && in_array($step['type'], array('Manual', 'In-Person'));
                            ?>
                            <div class="onboarding-step <?php echo $step_class; ?>" data-step-name="<?php echo esc_attr($step['name']); ?>">
                                <div class="step-icon">
                                    <?php if ($is_completed): ?>
                                        ✓
                                    <?php else: ?>
                                        <?php echo $step['order']; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="step-content">
                                    <h4><?php echo esc_html($step['name']); ?></h4>
                                    <div class="step-meta">
                                        <span class="step-type type-<?php echo esc_attr(strtolower($step['type'])); ?>">
                                            <?php echo esc_html($step['type']); ?>
                                        </span>
                                        <?php if ($step['required']): ?>
                                            <span class="step-required">Required</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($step['description']): ?>
                                        <p class="step-description"><?php echo nl2br(esc_html($step['description'])); ?></p>
                                    <?php endif; ?>
                                    
                                    <?php if ($step['content_url'] && $step['type'] === 'Automated'): ?>
                                        <p class="step-url">
                                            <strong>Content:</strong> 
                                            <a href="<?php echo esc_url($step['content_url']); ?>" target="_blank">
                                                <?php echo esc_html($step['content_url']); ?>
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_completed): ?>
                                        <div class="step-completion-info">
                                            <p class="completion-status">✓ Completed</p>
                                            <?php if ($completion['completed_date']): ?>
                                                <p class="completion-date">
                                                    Date: <?php echo date('M j, Y', strtotime($completion['completed_date'])); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($completion['completed_by']): ?>
                                                <p class="completion-by">
                                                    By: <?php echo esc_html($completion['completed_by']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($can_staff_complete): ?>
                                        <div class="step-actions">
                                            <button class="button button-primary complete-step-btn" 
                                                    data-progress-id="<?php echo $progress->id; ?>"
                                                    data-step-name="<?php echo esc_attr($step['name']); ?>">
                                                Mark Complete
                                            </button>
                                            <span class="step-help-text">
                                                <?php if ($step['type'] === 'Manual'): ?>
                                                    Staff review required
                                                <?php else: ?>
                                                    In-person training required
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php elseif ($step['type'] === 'Automated'): ?>
                                        <p class="step-pending">⏳ Volunteer must complete this step</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="volunteer-detail-grid">
                <div class="detail-section">
                    <div class="detail-card">
                        <h2>Contact Information</h2>
                        <table class="form-table">
                            <tr>
                                <th>Email</th>
                                <td><?php echo esc_html($volunteer->email); ?></td>
                            </tr>
                            <tr>
                                <th>Phone</th>
                                <td><?php echo esc_html($volunteer->phone ?: '—'); ?></td>
                            </tr>
                            <tr>
                                <th>Birthdate</th>
                                <td>
                                    <?php 
                                    if ($volunteer->birthdate) {
                                        echo esc_html(date('F j, Y', strtotime($volunteer->birthdate)));
                                        $age = floor((time() - strtotime($volunteer->birthdate)) / 31556926);
                                        echo ' <span class="description">(Age: ' . $age . ')</span>';
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="detail-card">
                        <h2>Portal Access</h2>
                        <table class="form-table">
                            <tr>
                                <th>Access Token</th>
                                <td>
                                    <?php if ($volunteer->access_token): ?>
                                        <span style="color: #0a0;">✓ Token exists</span>
                                    <?php else: ?>
                                        <span style="color: #c00;">✗ No token generated</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Magic Link</th>
                                <td>
                                    <?php if ($volunteer->access_token): ?>
                                        <?php
                                        $portal_url = home_url('/volunteer-portal/');
                                        $magic_link = add_query_arg('token', $volunteer->access_token, $portal_url);
                                        ?>
                                        <div style="margin-bottom: 10px;">
                                            <input type="text" value="<?php echo esc_attr($magic_link); ?>" readonly
                                                   style="width: 100%; font-family: monospace; font-size: 12px; padding: 8px;"
                                                   onclick="this.select();" />
                                        </div>
                                        <p class="description">Copy this link to send to the volunteer. They can bookmark it for easy access without logging in.</p>
                                        <a href="<?php echo esc_url($magic_link); ?>" target="_blank" class="button button-secondary" style="margin-top: 10px;">
                                            Test Link
                                        </a>
                                    <?php else: ?>
                                        <p class="description">Generate a token to create the magic link.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Actions</th>
                                <td>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                        <?php wp_nonce_field('fs_generate_token'); ?>
                                        <input type="hidden" name="action" value="fs_generate_token" />
                                        <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>" />
                                        <?php if ($volunteer->access_token): ?>
                                            <button type="submit" class="button button-secondary"
                                                    onclick="return confirm('The existing token will remain the same. Click OK to continue.');">
                                                View Token
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="button button-primary">
                                                Generate Access Token
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="detail-card">
                        <h2>Kiosk Access</h2>
                        <table class="form-table">
                            <tr>
                                <th>PIN Code</th>
                                <td>
                                    <?php if ($volunteer->pin): ?>
                                        <code style="font-size: 1.5em; font-weight: bold; color: #0073aa;"><?php echo esc_html($volunteer->pin); ?></code>
                                        <form method="post" style="display: inline; margin-left: 15px;">
                                            <input type="hidden" name="action" value="regenerate_pin">
                                            <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                                            <?php wp_nonce_field('fs_volunteer_action'); ?>
                                            <button type="submit" class="button" onclick="return confirm('Generate a new PIN? The old PIN will stop working.');">
                                                🔄 Regenerate
                                            </button>
                                        </form>
                                        <button class="button" onclick="printPin(<?php echo $volunteer->id; ?>, '<?php echo esc_js($volunteer->name); ?>', '<?php echo esc_js($volunteer->pin); ?>')">
                                            🖨️ Print PIN Card
                                        </button>
                                    <?php else: ?>
                                        <p class="description">No PIN assigned</p>
                                        <form method="post" style="margin-top: 10px;">
                                            <input type="hidden" name="action" value="generate_pin">
                                            <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                                            <?php wp_nonce_field('fs_volunteer_action'); ?>
                                            <button type="submit" class="button button-primary">Generate PIN</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>QR Code</th>
                                <td>
                                    <?php if ($volunteer->qr_code): ?>
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <div id="qr-code-<?php echo $volunteer->id; ?>" style="background: white; padding: 10px; border: 2px solid #ddd; border-radius: 8px;"></div>
                                            <div>
                                                <p><code><?php echo esc_html($volunteer->qr_code); ?></code></p>
                                                <form method="post" style="margin: 10px 0;">
                                                    <input type="hidden" name="action" value="regenerate_qr">
                                                    <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                                                    <?php wp_nonce_field('fs_volunteer_action'); ?>
                                                    <button type="submit" class="button" onclick="return confirm('Generate a new QR code? The old code will stop working.');">
                                                        🔄 Regenerate
                                                    </button>
                                                </form>
                                                <button class="button" onclick="printQR(<?php echo $volunteer->id; ?>, '<?php echo esc_js($volunteer->name); ?>', '<?php echo esc_js($volunteer->qr_code); ?>')">
                                                    🖨️ Print QR Card
                                                </button>
                                            </div>
                                        </div>
                                        <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>
                                        <script>
                                        QRCode.toCanvas(document.getElementById('qr-code-<?php echo $volunteer->id; ?>'), 
                                            '<?php echo home_url('/?fs_qr_scan=' . urlencode($volunteer->qr_code)); ?>', 
                                            { width: 150 },
                                            function(error) {
                                                if (error) console.error(error);
                                            }
                                        );
                                        </script>
                                    <?php else: ?>
                                        <p class="description">No QR code assigned</p>
                                        <form method="post" style="margin-top: 10px;">
                                            <input type="hidden" name="action" value="generate_qr">
                                            <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                                            <?php wp_nonce_field('fs_volunteer_action'); ?>
                                            <button type="submit" class="button button-primary">Generate QR Code</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="detail-card">
                        <h2>Volunteer Status</h2>
                        <table class="form-table">
                            <tr>
                                <th>Status</th>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr(strtolower($volunteer->volunteer_status ?: 'unknown')); ?>">
                                        <?php echo esc_html($volunteer->volunteer_status ?: 'Unknown'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Types</th>
                                <td><?php echo esc_html($volunteer->types ?: '—'); ?></td>
                            </tr>
                            <tr>
                                <th>Created</th>
                                <td><?php echo $volunteer->created_date ? esc_html(date('F j, Y', strtotime($volunteer->created_date))) : '—'; ?></td>
                            </tr>
                            <tr>
                                <th>Last Synced</th>
                                <td><?php echo $volunteer->last_sync ? esc_html(date('F j, Y g:i A', strtotime($volunteer->last_sync))) : '—'; ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="detail-card">
                        <h2>Background Check</h2>
                        <table class="form-table">
                            <tr>
                                <th>Status</th>
                                <td>
                                    <span class="bg-check-badge bg-<?php echo esc_attr(strtolower($volunteer->background_check_status ?: 'none')); ?>">
                                        <?php echo esc_html($volunteer->background_check_status ?: 'Not Started'); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Completed</th>
                                <td><?php echo $volunteer->background_check_date ? esc_html(date('F j, Y', strtotime($volunteer->background_check_date))) : '—'; ?></td>
                            </tr>
                            <tr>
                                <th>Organization</th>
                                <td><?php echo esc_html($volunteer->background_check_org ?: '—'); ?></td>
                            </tr>
                            <tr>
                                <th>Expires</th>
                                <td><?php echo $volunteer->background_check_expiration ? esc_html(date('F j, Y', strtotime($volunteer->background_check_expiration))) : '—'; ?></td>
                            </tr>
                        </table>
                    </div>
                    
                    <?php if ($volunteer->notes): ?>
                    <div class="detail-card">
                        <h2>Notes</h2>
                        <p><?php echo nl2br(esc_html($volunteer->notes)); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="sidebar-section">
                    <div class="detail-card">
                        <h2>Roles</h2>
                        <?php if (!empty($roles)): ?>
                            <ul class="role-list">
                                <?php foreach ($roles as $role): ?>
                                    <li>
                                        <?php echo esc_html($role->name); ?>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;" onsubmit="return confirm('Remove this role?');">
                                            <input type="hidden" name="action" value="fs_remove_role">
                                            <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                                            <input type="hidden" name="role_id" value="<?php echo $role->id; ?>">
                                            <?php wp_nonce_field('fs_volunteer_action'); ?>
                                            <button type="submit" class="button-link-delete">Remove</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="description">No roles assigned</p>
                        <?php endif; ?>
                        
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 15px;">
                            <input type="hidden" name="action" value="fs_add_role">
                            <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                            <?php wp_nonce_field('fs_volunteer_action'); ?>
                            <select name="role_id" required style="width: 100%;">
                                <option value="">Select a role...</option>
                                <?php foreach ($all_roles as $role): ?>
                                    <option value="<?php echo $role->id; ?>"><?php echo esc_html($role->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary" style="margin-top: 10px; width: 100%;">
                                Add Role
                            </button>
                        </form>
                    </div>

                    <div class="detail-card">
                        <h2>Blocked Time</h2>
                        <p class="description" style="margin-top: 0;">Manually block dates/times when this volunteer is unavailable</p>
                        <?php
                        // Get blocked times for this volunteer
                        $blocked_times = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}fs_blocked_times
                             WHERE volunteer_id = %d
                             ORDER BY start_time ASC",
                            $volunteer->id
                        ));
                        ?>
                        <?php if (!empty($blocked_times)): ?>
                            <table class="widefat" style="margin-top: 10px;">
                                <thead>
                                    <tr>
                                        <th>Start Time</th>
                                        <th>End Time</th>
                                        <th>Source</th>
                                        <th>Title</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blocked_times as $blocked): ?>
                                        <tr>
                                            <td><?php echo esc_html(date('M j, Y g:i A', strtotime($blocked->start_time))); ?></td>
                                            <td><?php echo esc_html(date('M j, Y g:i A', strtotime($blocked->end_time))); ?></td>
                                            <td>
                                                <?php if ($blocked->source === 'google_calendar'): ?>
                                                    <span style="color: #4285f4;">📅 Google Calendar</span>
                                                <?php else: ?>
                                                    <span style="color: #666;">✏️ Manual</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($blocked->title ?: '—'); ?></td>
                                            <td>
                                                <?php if ($blocked->source !== 'google_calendar'): ?>
                                                    <button type="button" class="button-link-delete delete-blocked-time"
                                                            data-blocked-id="<?php echo $blocked->id; ?>"
                                                            data-nonce="<?php echo wp_create_nonce('fs_delete_blocked_' . $blocked->id); ?>">
                                                        Delete
                                                    </button>
                                                <?php else: ?>
                                                    <span style="color: #999;">Auto-synced</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="description">No blocked times</p>
                        <?php endif; ?>

                        <h3 style="margin-top: 20px;">Add Blocked Time</h3>
                        <form id="add-blocked-time-form" style="background: #f9f9f9; padding: 15px; border-radius: 4px;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                <div>
                                    <label><strong>Start Date & Time *</strong></label>
                                    <input type="datetime-local" id="blocked_start_time" required style="width: 100%;">
                                </div>
                                <div>
                                    <label><strong>End Date & Time *</strong></label>
                                    <input type="datetime-local" id="blocked_end_time" required style="width: 100%;">
                                </div>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <label><strong>Title (optional)</strong></label>
                                <input type="text" id="blocked_title" placeholder="e.g., Vacation, Doctor's appointment" style="width: 100%;">
                            </div>
                            <button type="submit" class="button button-primary" style="width: 100%;">
                                Add Blocked Time
                            </button>
                        </form>
                    </div>

                    <div class="detail-card">
                        <h2>Onboarding Progress</h2>
                        <?php if (!empty($progress_records)): ?>
                            <?php foreach ($progress_records as $prog): ?>
                                <div class="progress-item">
                                    <strong><?php echo esc_html($prog->workflow_name); ?></strong>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" style="width: <?php echo $prog->progress_percentage; ?>%"></div>
                                    </div>
                                    <span class="progress-text"><?php echo $prog->progress_percentage; ?>% - <?php echo esc_html($prog->overall_status); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="description">No onboarding assigned</p>
                        <?php endif; ?>
                        
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 15px;">
                            <input type="hidden" name="action" value="fs_assign_workflow">
                            <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                            <?php wp_nonce_field('fs_volunteer_action'); ?>
                            <select name="workflow_id" required style="width: 100%;">
                                <option value="">Select a workflow...</option>
                                <?php foreach ($all_workflows as $workflow): ?>
                                    <option value="<?php echo $workflow->id; ?>"><?php echo esc_html($workflow->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary" style="margin-top: 10px; width: 100%;">
                                Assign Workflow
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="detail-card" style="margin-top: 20px;">
                <h2>Upcoming Volunteer Shifts</h2>
                <?php if (!empty($upcoming_signups)): ?>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th>Opportunity</th>
                                <th>Date & Time</th>
                                <th>Location</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_signups as $signup): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($signup->title); ?></strong></td>
                                    <td>
                                        <?php echo date('M j, Y @ g:i A', strtotime($signup->datetime_start)); ?>
                                        <?php if ($signup->datetime_end): ?>
                                            - <?php echo date('g:i A', strtotime($signup->datetime_end)); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($signup->location ?: '—'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($signup->status); ?>">
                                            <?php echo esc_html(ucfirst($signup->status)); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="description">No upcoming signups</p>
                <?php endif; ?>
            </div>
            
            <div class="detail-card" style="margin-top: 20px;">
                <h2>Recent History</h2>
                <?php if (!empty($past_signups)): ?>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th>Opportunity</th>
                                <th>Date</th>
                                <th>Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($past_signups as $signup): ?>
                                <tr>
                                    <td><?php echo esc_html($signup->title); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($signup->datetime_start)); ?></td>
                                    <td><?php echo esc_html($signup->location ?: '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="description">No history</p>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .volunteer-detail-grid {
                display: grid;
                grid-template-columns: 2fr 1fr;
                gap: 20px;
                margin: 20px 0;
            }
            .detail-card {
                background: white;
                border: 1px solid #ccd0d4;
                padding: 20px;
                margin-bottom: 20px;
            }
            .detail-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #ccd0d4;
            }
            .status-badge {
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                display: inline-block;
            }
            .status-active { background: #d4edda; color: #155724; }
            .status-prospect { background: #fff3cd; color: #856404; }
            .status-inactive { background: #f8d7da; color: #721c24; }
            .status-confirmed { background: #d4edda; color: #155724; }
            .status-pending { background: #fff3cd; color: #856404; }
            .status-cancelled { background: #f8d7da; color: #721c24; }
            
            .bg-check-badge {
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                display: inline-block;
            }
            .bg-approved { background: #d4edda; color: #155724; }
            .bg-pending { background: #fff3cd; color: #856404; }
            .bg-not, .bg-none { background: #f8d7da; color: #721c24; }
            
            .role-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .role-list li {
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .role-list li:last-child {
                border-bottom: none;
            }
            .progress-item {
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 1px solid #f0f0f1;
            }
            .progress-item:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
            .progress-bar-container {
                width: 100%;
                height: 10px;
                background: #f0f0f1;
                border-radius: 5px;
                overflow: hidden;
                margin: 8px 0;
            }
            .progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #28a745, #20c997);
            }
            .progress-text {
                font-size: 12px;
                color: #666;
            }
            
            /* Onboarding Section Styles */
            .onboarding-section {
                margin-bottom: 30px;
            }
            .onboarding-card {
                background: white;
                border: 2px solid #0073aa;
                border-radius: 8px;
                padding: 25px;
            }
            .onboarding-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }
            .onboarding-header h3 {
                margin: 0 0 5px 0;
                font-size: 1.3em;
            }
            .onboarding-status {
                color: #666;
                margin: 0;
            }
            .progress-circle {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                background: #e7f3ff;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 4px solid #0073aa;
            }
            .progress-number {
                font-size: 1.5em;
                font-weight: bold;
                color: #0073aa;
            }
            .onboarding-steps {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-top: 20px;
            }
            .onboarding-step {
                display: flex;
                gap: 15px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 8px;
                border: 2px solid #ddd;
            }
            .onboarding-step.step-complete {
                background: #d4edda;
                border-color: #28a745;
            }
            .step-icon {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                flex-shrink: 0;
            }
            .step-complete .step-icon {
                background: #28a745;
                color: white;
            }
            .step-content {
                flex: 1;
            }
            .step-content h4 {
                margin: 0 0 8px 0;
                font-size: 1.1em;
            }
            .step-meta {
                display: flex;
                gap: 10px;
                margin-bottom: 10px;
            }
            .step-type {
                padding: 3px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .type-automated { background: #e7f3ff; color: #0066cc; }
            .type-manual { background: #fff3cd; color: #856404; }
            .type-in-person { background: #f8d7da; color: #721c24; }
            .step-required {
                padding: 3px 10px;
                background: #dc3545;
                color: white;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .step-description {
                color: #666;
                font-size: 14px;
                line-height: 1.5;
                margin: 10px 0;
            }
            .step-url {
                margin: 10px 0;
                padding: 8px;
                background: #f0f6fc;
                border-radius: 4px;
                font-size: 13px;
            }
            .step-url a {
                color: #0066cc;
                text-decoration: none;
                word-break: break-all;
            }
            .step-url a:hover {
                text-decoration: underline;
            }
            .step-actions {
                margin-top: 15px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .step-help-text {
                color: #666;
                font-size: 13px;
                font-style: italic;
            }
            .step-completion-info {
                margin-top: 10px;
                padding: 10px;
                background: #d4edda;
                border-radius: 4px;
            }
            .completion-status {
                color: #155724;
                font-weight: 600;
                margin: 0 0 5px 0;
            }
            .completion-date,
            .completion-by {
                color: #155724;
                font-size: 13px;
                margin: 2px 0;
            }
            .step-pending {
                color: #856404;
                font-weight: 600;
                margin: 10px 0 0 0;
                font-size: 14px;
            }
            
            @media (max-width: 1200px) {
                .volunteer-detail-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.complete-step-btn').on('click', function() {
                var button = $(this);
                var progressId = button.data('progress-id');
                var stepName = button.data('step-name');
                var stepContainer = button.closest('.onboarding-step');
                
                if (!confirm('Mark this step as complete for this volunteer?')) {
                    return;
                }
                
                button.prop('disabled', true).text('Processing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fs_admin_complete_step',
                        nonce: '<?php echo wp_create_nonce('fs_admin_step_completion'); ?>',
                        progress_id: progressId,
                        step_name: stepName
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update the UI
                            stepContainer.removeClass('step-incomplete').addClass('step-complete');
                            stepContainer.find('.step-icon').html('✓');
                            
                            // Replace button with completion info
                            var completionInfo = '<div class="step-completion-info">' +
                                '<p class="completion-status">✓ Completed</p>' +
                                '<p class="completion-date">Date: ' + response.data.completed_date + '</p>' +
                                '<p class="completion-by">By: ' + response.data.completed_by + '</p>' +
                                '</div>';
                            
                            button.closest('.step-actions').replaceWith(completionInfo);
                            
                            // Show success message
                            alert('Step marked as complete!');
                            
                            // Reload page to update progress percentage
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            alert('Error: ' + response.data.message);
                            button.prop('disabled', false).text('Mark Complete');
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                        button.prop('disabled', false).text('Mark Complete');
                    }
                });
            });

            // Add blocked time form handler
            $('#add-blocked-time-form').on('submit', function(e) {
                e.preventDefault();

                var startTime = $('#blocked_start_time').val();
                var endTime = $('#blocked_end_time').val();
                var title = $('#blocked_title').val();

                if (!startTime || !endTime) {
                    alert('Please fill in both start and end times');
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fs_add_blocked_time',
                        nonce: '<?php echo wp_create_nonce('fs_add_blocked_time'); ?>',
                        volunteer_id: <?php echo $volunteer->id; ?>,
                        start_time: startTime,
                        end_time: endTime,
                        title: title
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Blocked time added successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });

            // Delete blocked time handler
            $('.delete-blocked-time').on('click', function() {
                if (!confirm('Delete this blocked time?')) {
                    return;
                }

                var button = $(this);
                var blockedId = button.data('blocked-id');
                var nonce = button.data('nonce');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'fs_delete_blocked_time',
                        nonce: nonce,
                        blocked_id: blockedId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Blocked time deleted successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            });
        });

        function printPin(volunteerId, volunteerName, pin) {
            const printWindow = window.open('', '_blank', 'width=400,height=600');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>PIN Card - ` + volunteerName + `</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            padding: 40px;
                            text-align: center;
                        }
                        .card {
                            border: 3px solid #333;
                            border-radius: 15px;
                            padding: 40px;
                            max-width: 350px;
                            margin: 0 auto;
                        }
                        .logo {
                            font-size: 3em;
                            margin-bottom: 20px;
                        }
                        h1 {
                            margin: 0 0 10px 0;
                            font-size: 1.8em;
                        }
                        .name {
                            font-size: 1.3em;
                            color: #666;
                            margin-bottom: 30px;
                        }
                        .pin-label {
                            font-size: 1.1em;
                            color: #666;
                            margin-bottom: 10px;
                        }
                        .pin {
                            font-size: 4em;
                            font-weight: bold;
                            color: #0073aa;
                            letter-spacing: 10px;
                            margin: 20px 0;
                        }
                        .instructions {
                            margin-top: 30px;
                            font-size: 0.9em;
                            color: #666;
                            line-height: 1.6;
                        }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <div class="logo">🙋</div>
                        <h1>Volunteer Check-In</h1>
                        <div class="name">` + volunteerName + `</div>
                        <div class="pin-label">Your PIN Code:</div>
                        <div class="pin">` + pin + `</div>
                        <div class="instructions">
                            Use this PIN at the volunteer kiosk to check in and out of your shifts.
                            <br><br>
                            Keep this card with you or memorize your PIN.
                        </div>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => printWindow.print(), 500);
        }
        
        function printQR(volunteerId, volunteerName, qrCode) {
            const printWindow = window.open('', '_blank', 'width=400,height=700');
            
            // Create QR code canvas
            const canvas = document.createElement('canvas');
            QRCode.toCanvas(canvas, 
                '<?php echo home_url('/?fs_qr_scan='); ?>' + qrCode, 
                { width: 300 },
                function(error) {
                    if (error) {
                        console.error(error);
                        return;
                    }
                    
                    const qrDataUrl = canvas.toDataURL();

                    printWindow.document.write(`
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>QR Card - ` + volunteerName + `</title>
                            <style>
                                body {
                                    font-family: Arial, sans-serif;
                                    padding: 40px;
                                    text-align: center;
                                }
                                .card {
                                    border: 3px solid #333;
                                    border-radius: 15px;
                                    padding: 40px;
                                    max-width: 350px;
                                    margin: 0 auto;
                                }
                                .logo {
                                    font-size: 3em;
                                    margin-bottom: 20px;
                                }
                                h1 {
                                    margin: 0 0 10px 0;
                                    font-size: 1.8em;
                                }
                                .name {
                                    font-size: 1.3em;
                                    color: #666;
                                    margin-bottom: 30px;
                                }
                                .qr-container {
                                    background: white;
                                    padding: 20px;
                                    border-radius: 10px;
                                    display: inline-block;
                                    margin: 20px 0;
                                }
                                .qr-container img {
                                    display: block;
                                }
                                .instructions {
                                    margin-top: 30px;
                                    font-size: 0.9em;
                                    color: #666;
                                    line-height: 1.6;
                                }
                            </style>
                        </head>
                        <body>
                            <div class="card">
                                <div class="logo">🙋</div>
                                <h1>Volunteer Check-In</h1>
                                <div class="name">` + volunteerName + `</div>
                                <div class="qr-container">
                                    <img src="` + qrDataUrl + `" alt="QR Code">
                                </div>
                                <div class="instructions">
                                    Scan this QR code at the volunteer kiosk to quickly check in and out of your shifts.
                                </div>
                            </div>
                        </body>
                        </html>
                    `);
                    printWindow.document.close();
                    printWindow.focus();
                    setTimeout(() => printWindow.print(), 500);
                }
            );
        }
        </script>
        <?php
    }
    
    public static function edit_volunteer_page() {
        global $wpdb;
        
        $volunteer_id = intval($_GET['id'] ?? 0);
        
        if (!$volunteer_id) {
            echo '<div class="wrap"><h1>Volunteer not found</h1></div>';
            return;
        }
        
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));
        
        if (!$volunteer) {
            echo '<div class="wrap"><h1>Volunteer not found</h1></div>';
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>Edit Volunteer</h1>
            
            <form method="post">
                <?php wp_nonce_field('fs_volunteer_form'); ?>
                <input type="hidden" name="volunteer_id" value="<?php echo $volunteer->id; ?>">
                
                <table class="form-table">
                    <tr>
                        <th><label for="name">Name *</label></th>
                        <td>
                            <input type="text" id="name" name="name" value="<?php echo esc_attr($volunteer->name); ?>" class="regular-text" required>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="email">Email *</label></th>
                        <td>
                            <input type="email" id="email" name="email" value="<?php echo esc_attr($volunteer->email); ?>" class="regular-text" required>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="phone">Phone</label></th>
                        <td>
                            <input type="tel" id="phone" name="phone" value="<?php echo esc_attr($volunteer->phone); ?>" class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="birthdate">Birthdate</label></th>
                        <td>
                            <input type="date" id="birthdate" name="birthdate" value="<?php echo esc_attr($volunteer->birthdate); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="volunteer_status">Status *</label></th>
                        <td>
                            <select id="volunteer_status" name="volunteer_status" required>
                                <option value="Prospect" <?php selected($volunteer->volunteer_status, 'Prospect'); ?>>Prospect</option>
                                <option value="Active" <?php selected($volunteer->volunteer_status, 'Active'); ?>>Active</option>
                                <option value="Inactive" <?php selected($volunteer->volunteer_status, 'Inactive'); ?>>Inactive</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="types">Types/Programs</label></th>
                        <td>
                            <input type="text" id="types" name="types" value="<?php echo esc_attr($volunteer->types); ?>" class="regular-text">
                            <p class="description">Comma-separated list of volunteer types or programs</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="notes">Notes</label></th>
                        <td>
                            <textarea id="notes" name="notes" rows="5" class="large-text"><?php echo esc_textarea($volunteer->notes); ?></textarea>
                        </td>
                    </tr>
                    
                    <tr>
                        <th colspan="2"><h3>Background Check</h3></th>
                    </tr>
                    
                    <tr>
                        <th><label for="background_check_status">Status</label></th>
                        <td>
                            <select id="background_check_status" name="background_check_status">
                                <option value="">Not Started</option>
                                <option value="Pending" <?php selected($volunteer->background_check_status, 'Pending'); ?>>Pending</option>
                                <option value="Approved" <?php selected($volunteer->background_check_status, 'Approved'); ?>>Approved</option>
                                <option value="Not Approved" <?php selected($volunteer->background_check_status, 'Not Approved'); ?>>Not Approved</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="background_check_date">Date Completed</label></th>
                        <td>
                            <input type="date" id="background_check_date" name="background_check_date" value="<?php echo esc_attr($volunteer->background_check_date); ?>">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="background_check_org">Organization</label></th>
                        <td>
                            <input type="text" id="background_check_org" name="background_check_org" value="<?php echo esc_attr($volunteer->background_check_org); ?>" class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="background_check_expiration">Expiration Date</label></th>
                        <td>
                            <input type="date" id="background_check_expiration" name="background_check_expiration" value="<?php echo esc_attr($volunteer->background_check_expiration); ?>">
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="fs_save_volunteer" class="button button-primary" value="Update Volunteer">
                    <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $volunteer->id); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    private static function sync_volunteer_to_monday($volunteer) {
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['people'])) {
            return false;
        }
        
        $column_values = array(
            'contact_email' => array('email' => $volunteer->email, 'text' => $volunteer->name),
            'contact_phone' => array('phone' => $volunteer->phone, 'countryShortName' => 'US'),
            'color_mkxsmnr7' => array('label' => $volunteer->volunteer_status),
            'text_mkxsni16' => $volunteer->types,
            'long_text_mkxsmz1j' => $volunteer->notes
        );
        
        if (!empty($volunteer->birthdate)) {
            $column_values['date_mkxs5njb'] = array('date' => $volunteer->birthdate);
        }
        
        if (!empty($volunteer->background_check_status)) {
            $column_values['color_mkxsnf9n'] = array('label' => $volunteer->background_check_status);
        }
        
        if (!empty($volunteer->background_check_date)) {
            $column_values['date_mkxspb8d'] = array('date' => $volunteer->background_check_date);
        }
        
        if (!empty($volunteer->background_check_org)) {
            $column_values['text_mkxsp89v'] = $volunteer->background_check_org;
        }
        
        if (!empty($volunteer->background_check_expiration)) {
            $column_values['date_mkxspcsq'] = array('date' => $volunteer->background_check_expiration);
        }
        
        if (!empty($volunteer->wp_user_id)) {
            $column_values['numeric_mkxsjwt7'] = $volunteer->wp_user_id;
        }
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            change_multiple_column_values(
                item_id: ' . $volunteer->monday_id . ',
                board_id: ' . $board_ids['people'] . ',
                column_values: "' . $column_values_escaped . '"
            ) {
                id
            }
        }';
        
        $result = $api->query_raw($mutation);
        
        // Also update the name
        if ($result) {
            $name_mutation = 'mutation {
                change_simple_column_value(
                    item_id: ' . $volunteer->monday_id . ',
                    board_id: ' . $board_ids['people'] . ',
                    column_id: "name",
                    value: "' . addslashes($volunteer->name) . '"
                ) {
                    id
                }
            }';
            
            $api->query_raw($name_mutation);
        }
        
        return $result;
    }

    public static function handle_resend_welcome_email() {
        $volunteer_id = isset($_GET['volunteer_id']) ? intval($_GET['volunteer_id']) : 0;

        check_admin_referer('fs_resend_welcome_' . $volunteer_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            wp_die('Volunteer not found');
        }

        // Send welcome email
        $result = self::send_welcome_email($volunteer);

        if ($result) {
            wp_redirect(add_query_arg(
                array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'welcome_sent' => '1'),
                admin_url('admin.php')
            ));
        } else {
            wp_redirect(add_query_arg(
                array('page' => 'fs-volunteer-detail', 'id' => $volunteer_id, 'welcome_error' => '1'),
                admin_url('admin.php')
            ));
        }
        exit;
    }

    private static function send_welcome_email($volunteer) {
        $subject = 'Welcome to FriendShyft!';

        $portal_url = add_query_arg(
            array('token' => $volunteer->access_token),
            home_url('/volunteer-portal/')
        );

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #0073aa;'>Welcome to FriendShyft, " . esc_html($volunteer->name) . "!</h2>

                <p>We're excited to have you join our volunteer community!</p>

                <p>Click the button below to access your volunteer portal where you can:</p>
                <ul>
                    <li>View available opportunities</li>
                    <li>Sign up for shifts</li>
                    <li>Track your volunteer hours</li>
                    <li>View your achievements and badges</li>
                </ul>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . esc_url($portal_url) . "' style='display: inline-block; background: #0073aa; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        Access My Volunteer Portal
                    </a>
                </div>

                <p style='color: #666; font-size: 14px;'>
                    You can bookmark this link for easy access to your portal in the future:<br>
                    <a href='" . esc_url($portal_url) . "'>" . esc_html($portal_url) . "</a>
                </p>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($volunteer->email, $subject, $message, $headers);
    }

    public static function handle_export_volunteers_csv() {
        check_admin_referer('fs_export_volunteers');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        // Get all volunteers
        $volunteers = $wpdb->get_results(
            "SELECT v.*,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') as role_names
             FROM {$wpdb->prefix}fs_volunteers v
             LEFT JOIN {$wpdb->prefix}fs_volunteer_roles vr ON v.id = vr.volunteer_id
             LEFT JOIN {$wpdb->prefix}fs_roles r ON vr.role_id = r.id
             GROUP BY v.id
             ORDER BY v.name ASC"
        );

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=volunteers-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Write headers
        fputcsv($output, array(
            'ID',
            'Name',
            'Email',
            'Phone',
            'Status',
            'Roles',
            'Birth Date',
            'Background Check Status',
            'Background Check Date',
            'Background Check Org',
            'Background Check Expiration',
            'Types',
            'Notes',
            'Created Date',
            'Portal URL'
        ));

        // Write data
        foreach ($volunteers as $volunteer) {
            $portal_url = add_query_arg(
                array('token' => $volunteer->access_token),
                home_url('/volunteer-portal/')
            );

            fputcsv($output, array(
                $volunteer->id,
                $volunteer->name,
                $volunteer->email,
                $volunteer->phone,
                $volunteer->volunteer_status,
                $volunteer->role_names ?: '',
                $volunteer->birthdate,
                $volunteer->background_check_status,
                $volunteer->background_check_date,
                $volunteer->background_check_org,
                $volunteer->background_check_expiration,
                $volunteer->types,
                $volunteer->notes,
                $volunteer->created_date,
                $portal_url
            ));
        }

        fclose($output);
        exit;
    }

    /**
     * AJAX handler for admin completing workflow steps on behalf of volunteers
     */
    public static function ajax_admin_complete_step() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fs_admin_step_completion')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        $progress_id = isset($_POST['progress_id']) ? intval($_POST['progress_id']) : 0;
        $step_name = isset($_POST['step_name']) ? sanitize_text_field($_POST['step_name']) : '';

        if (!$progress_id || !$step_name) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
            return;
        }

        global $wpdb;

        // Get progress record with volunteer info
        $progress = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, v.name as volunteer_name, v.monday_id as volunteer_monday_id
             FROM {$wpdb->prefix}fs_progress p
             JOIN {$wpdb->prefix}fs_volunteers v ON p.volunteer_id = v.id
             WHERE p.id = %d",
            $progress_id
        ));

        if (!$progress) {
            wp_send_json_error(array('message' => 'Progress record not found'));
            return;
        }

        // Get step completions
        $step_completions = json_decode($progress->step_completions, true);
        if (!is_array($step_completions)) {
            wp_send_json_error(array('message' => 'Invalid step completions data'));
            return;
        }

        // Find the step
        $step_index = null;
        $step_monday_id = null;
        foreach ($step_completions as $index => $completion) {
            if ($completion['name'] === $step_name) {
                $step_index = $index;
                $step_monday_id = $completion['monday_id'] ?? null;
                break;
            }
        }

        if ($step_index === null) {
            wp_send_json_error(array('message' => 'Step not found'));
            return;
        }

        // Check if already completed
        if (!empty($step_completions[$step_index]['completed'])) {
            wp_send_json_error(array('message' => 'Step already completed'));
            return;
        }

        // Update Monday.com subitem if configured
        if (FS_Monday_API::is_configured() && !empty($step_monday_id)) {
            $api = new FS_Monday_API();
            $today = date('Y-m-d');

            // Get admin user name
            $current_user = wp_get_current_user();
            $completed_by = $current_user->display_name;

            $column_values = array(
                'boolean_mkxs3zj3' => array('checked' => true),
                'date_mkxsxg0a' => array('date' => $today),
                'text_mkxsqhb1' => $completed_by . ' (Admin)'
            );

            $column_values_json = json_encode($column_values);
            $column_values_escaped = addslashes($column_values_json);

            // Get board ID for the subitem
            $query = 'query {
                items(ids: [' . intval($step_monday_id) . ']) {
                    board {
                        id
                    }
                }
            }';

            $board_result = $api->query_raw($query);
            $subitem_board_id = $board_result['items'][0]['board']['id'] ?? null;

            if ($subitem_board_id) {
                $mutation = 'mutation {
                    change_multiple_column_values(
                        item_id: ' . intval($step_monday_id) . ',
                        board_id: ' . intval($subitem_board_id) . ',
                        column_values: "' . $column_values_escaped . '"
                    ) {
                        id
                    }
                }';

                $result = $api->query_raw($mutation);

                if (!$result || !isset($result['change_multiple_column_values']['id'])) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('FriendShyft: Failed to update Monday.com step, continuing with local update');
                    }
                }
            }
        }

        // Update local database
        $current_user = wp_get_current_user();
        $step_completions[$step_index]['completed'] = true;
        $step_completions[$step_index]['completed_date'] = date('Y-m-d');
        $step_completions[$step_index]['completed_by'] = $current_user->display_name . ' (Admin)';

        $update_result = $wpdb->update(
            $wpdb->prefix . 'fs_progress',
            array('step_completions' => json_encode(array_values($step_completions))),
            array('id' => $progress_id)
        );

        if ($update_result === false) {
            wp_send_json_error(array('message' => 'Failed to update database'));
            return;
        }

        // Log the action in audit log
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('workflow_completed', 'progress', $progress_id, array(
                'step_name' => $step_name,
                'volunteer_id' => $progress->volunteer_id,
                'volunteer_name' => $progress->volunteer_name,
                'workflow_id' => $progress->workflow_id,
                'completed_by_admin' => true
            ));
        }

        wp_send_json_success(array(
            'message' => 'Step marked as complete',
            'step_name' => $step_name
        ));
    }

    /**
     * AJAX handler for adding blocked time
     */
    public static function ajax_add_blocked_time() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fs_add_blocked_time')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        $volunteer_id = isset($_POST['volunteer_id']) ? intval($_POST['volunteer_id']) : 0;
        $start_time = isset($_POST['start_time']) ? sanitize_text_field($_POST['start_time']) : '';
        $end_time = isset($_POST['end_time']) ? sanitize_text_field($_POST['end_time']) : '';
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';

        if (!$volunteer_id || !$start_time || !$end_time) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
            return;
        }

        // Convert HTML5 datetime-local format to MySQL datetime
        $start_datetime = date('Y-m-d H:i:s', strtotime($start_time));
        $end_datetime = date('Y-m-d H:i:s', strtotime($end_time));

        // Validate dates
        if ($end_datetime <= $start_datetime) {
            wp_send_json_error(array('message' => 'End time must be after start time'));
            return;
        }

        global $wpdb;

        // Verify volunteer exists
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        if (!$volunteer) {
            wp_send_json_error(array('message' => 'Volunteer not found'));
            return;
        }

        // Insert blocked time
        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_blocked_times',
            array(
                'volunteer_id' => $volunteer_id,
                'start_time' => $start_datetime,
                'end_time' => $end_datetime,
                'source' => 'manual',
                'title' => $title,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to add blocked time'));
            return;
        }

        // Log the action in audit log
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('blocked_time_added', 'volunteer', $volunteer_id, array(
                'start_time' => $start_datetime,
                'end_time' => $end_datetime,
                'title' => $title,
                'source' => 'manual'
            ));
        }

        wp_send_json_success(array('message' => 'Blocked time added successfully'));
    }

    /**
     * AJAX handler for deleting blocked time
     */
    public static function ajax_delete_blocked_time() {
        $blocked_id = isset($_POST['blocked_id']) ? intval($_POST['blocked_id']) : 0;

        if (!$blocked_id) {
            wp_send_json_error(array('message' => 'Missing blocked time ID'));
            return;
        }

        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fs_delete_blocked_' . $blocked_id)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        // Check admin permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        global $wpdb;

        // Get blocked time info for audit log
        $blocked = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_blocked_times WHERE id = %d",
            $blocked_id
        ));

        if (!$blocked) {
            wp_send_json_error(array('message' => 'Blocked time not found'));
            return;
        }

        // Don't allow deletion of Google Calendar synced times
        if ($blocked->source === 'google_calendar') {
            wp_send_json_error(array('message' => 'Cannot delete Google Calendar synced blocked times'));
            return;
        }

        // Delete the blocked time
        $result = $wpdb->delete(
            $wpdb->prefix . 'fs_blocked_times',
            array('id' => $blocked_id),
            array('%d')
        );

        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to delete blocked time'));
            return;
        }

        // Log the action in audit log
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('blocked_time_deleted', 'volunteer', $blocked->volunteer_id, array(
                'blocked_id' => $blocked_id,
                'start_time' => $blocked->start_time,
                'end_time' => $blocked->end_time,
                'title' => $blocked->title
            ));
        }

        wp_send_json_success(array('message' => 'Blocked time deleted successfully'));
    }
}

FS_Admin_Volunteers::init();