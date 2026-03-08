<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Programs {
    
    public static function init() {
        // Add menu pages
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);
        
        // Handle form submissions
        add_action('admin_post_fs_save_program', array(__CLASS__, 'handle_form_submission'));
        
        // Handle delete action
        add_action('admin_init', array(__CLASS__, 'handle_delete'));
    }
    
    public static function add_menu_pages() {
        // List programs
        add_submenu_page(
            'friendshyft',
            'Volunteer Programs',
            'Programs',
            'manage_options',
            'fs-programs',
            array(__CLASS__, 'list_page')
        );
        
        // Add/Edit program (hidden from menu)
        add_submenu_page(
            null,
            'Add Program',
            'Add Program',
            'manage_options',
            'fs-add-program',
            array(__CLASS__, 'edit_page')
        );
        
        add_submenu_page(
            null,
            'Edit Program',
            'Edit Program',
            'manage_options',
            'fs-edit-program',
            array(__CLASS__, 'edit_page')
        );
    }
    
    public static function handle_form_submission() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Programs: Form submission started');
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Programs: POST data: ' . print_r($_POST, true));
        }
    
        if (!isset($_POST['fs_save_program'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: fs_save_program not set');
            }
            return;
        }
    
        if (!check_admin_referer('fs_program_form')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: Nonce check failed');
            }
            return;
        }
    
        if (!current_user_can('manage_options')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: Permission check failed');
            }
            wp_die('Unauthorized');
        }
    
        global $wpdb;
    
        $program_id = isset($_POST['program_id']) ? intval($_POST['program_id']) : 0;
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Programs: Program ID = ' . $program_id);
        }
    
        $data = array(
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'short_description' => sanitize_text_field(wp_unslash($_POST['short_description'] ?? '')),
            'long_description' => wp_kses_post(wp_unslash($_POST['long_description'] ?? '')),
            'email_description' => wp_kses_post(wp_unslash($_POST['email_description'] ?? '')),
            'schedule_days' => !empty($_POST['schedule_days']) ? implode(', ', array_map('sanitize_text_field', $_POST['schedule_days'])) : '',
            'schedule_times' => sanitize_text_field(wp_unslash($_POST['schedule_times'] ?? '')),
            'active_status' => sanitize_text_field(wp_unslash($_POST['active_status'] ?? 'active')),
            'display_order' => intval($_POST['display_order'] ?? 0),
            'last_sync' => current_time('mysql')
        );
    
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Programs: Data prepared: ' . print_r($data, true));
        }
    
        if ($program_id > 0) {
            // Update existing
            $result = $wpdb->update(
                $wpdb->prefix . 'fs_programs',
                $data,
                array('id' => $program_id)
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: Update result = ' . var_export($result, true));
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: Last error = ' . $wpdb->last_error);
            }

            // Log to audit log
            FS_Audit_Log::log('program_updated', 'program', $program_id, $data);

            $redirect = add_query_arg(
                array('page' => 'fs-programs', 'updated' => '1'),
                admin_url('admin.php')
            );
        } else {
            // Create new
            $result = $wpdb->insert(
                $wpdb->prefix . 'fs_programs',
                $data
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: Insert result = ' . var_export($result, true));
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: Insert ID = ' . $wpdb->insert_id);
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft Programs: Last error = ' . $wpdb->last_error);
            }

            $program_id = $wpdb->insert_id;

            // Log to audit log
            FS_Audit_Log::log('program_created', 'program', $program_id, $data);

            $redirect = add_query_arg(
                array('page' => 'fs-programs', 'created' => '1'),
                admin_url('admin.php')
            );
        }
    
        if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('FriendShyft Programs: Redirecting to ' . $redirect);
        }
        wp_redirect($redirect);
        exit;
    }
    
    public static function handle_delete() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'delete' || !isset($_GET['id'])) {
            return;
        }
        
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fs_delete_program_' . $_GET['id'])) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $program_id = intval($_GET['id']);
        
        // Get program for Monday.com deletion
        $program = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_programs WHERE id = %d",
            $program_id
        ));
        
        // Delete from database
        $wpdb->delete(
            $wpdb->prefix . 'fs_programs',
            array('id' => $program_id)
        );

        // Log to audit log
        FS_Audit_Log::log('program_deleted', 'program', $program_id, array(
            'name' => $program->name ?? 'Unknown',
            'monday_id' => $program->monday_id ?? null
        ));

        // Delete from Monday.com if configured and has Monday ID
        if (FS_Monday_API::is_configured() && !empty($program->monday_id)) {
            $api = new FS_Monday_API();
            $mutation = 'mutation { delete_item(item_id: ' . $program->monday_id . ') { id } }';
            $api->query_raw($mutation);
        }

        $redirect = add_query_arg(
            array('page' => 'fs-programs', 'deleted' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function list_page() {
    global $wpdb;
    
    // Handle messages
    if (isset($_GET['updated'])) {
        echo '<div class="notice notice-success"><p>Program updated successfully!</p></div>';
    }
    if (isset($_GET['created'])) {
        echo '<div class="notice notice-success"><p>Program created successfully!</p></div>';
    }
    if (isset($_GET['deleted'])) {
        echo '<div class="notice notice-success"><p>Program deleted successfully!</p></div>';
    }
    
    $programs = $wpdb->get_results(
        "SELECT p.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fs_roles WHERE program_id = p.id) as role_count
         FROM {$wpdb->prefix}fs_programs p
         ORDER BY p.display_order ASC, p.name ASC"
    );
    
    ?>
    <div class="wrap">
        <h1>
            Volunteer Programs
            <a href="<?php echo admin_url('admin.php?page=fs-add-program'); ?>" class="page-title-action">Add New Program</a>
        </h1>
        
        <?php if (empty($programs)): ?>
            <p>No programs defined yet. <a href="<?php echo admin_url('admin.php?page=fs-add-program'); ?>">Create your first program</a>.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Program Name</th>
                        <th>Description</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Display Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs as $program): ?>
                        <?php
                        // Get roles for this program
                        $roles = $wpdb->get_results($wpdb->prepare(
                            "SELECT name FROM {$wpdb->prefix}fs_roles WHERE program_id = %d ORDER BY name ASC",
                            $program->id
                        ));
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($program->name); ?></strong></td>
                            <td><?php echo esc_html($program->short_description ?: '—'); ?></td>
                            <td>
                                <?php if (!empty($roles)): ?>
                                    <details>
                                        <summary><?php echo count($roles); ?> role(s)</summary>
                                        <ul style="margin: 5px 0 0 20px;">
                                            <?php foreach ($roles as $role): ?>
                                                <li><?php echo esc_html($role->name); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php else: ?>
                                    <span style="color: #999;">No roles</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($program->active_status); ?>">
                                    <?php echo esc_html($program->active_status); ?>
                                </span>
                            </td>
                            <td><?php echo $program->display_order; ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=fs-edit-program&id=' . $program->id); ?>">Edit</a> |
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fs-programs&action=delete&id=' . $program->id), 'fs_delete_program_' . $program->id); ?>" 
                                   onclick="return confirm('Delete this program?');" 
                                   style="color: #b32d2e;">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <style>
            .status-badge {
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }
            .status-active { background: #d4edda; color: #155724; }
            .status-inactive { background: #f8d7da; color: #721c24; }
        </style>
    </div>
    <?php
}
    
    public static function edit_page() {
        global $wpdb;
    
        $program_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $is_new = $program_id === 0;
    
        $program = null;
        if (!$is_new) {
            $program = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_programs WHERE id = %d",
                $program_id
            ));
            
            if (!$program) {
                echo '<div class="wrap"><h1>Program not found</h1></div>';
                return;
            }
        }
    
        // Get highest display order for default
        $max_order = $wpdb->get_var("SELECT MAX(display_order) FROM {$wpdb->prefix}fs_programs");
        $default_order = $max_order ? $max_order + 1 : 1;
    
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? 'Add New Program' : 'Edit Program'; ?></h1>
        
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="program-form">
                <?php wp_nonce_field('fs_program_form'); ?>
                <input type="hidden" name="action" value="fs_save_program">
                <?php if (!$is_new): ?>
                    <input type="hidden" name="program_id" value="<?php echo $program_id; ?>">
                <?php endif; ?>
            
                <table class="form-table">
                    <tr>
                        <th><label for="name">Program Name *</label></th>
                        <td>
                            <input type="text" id="name" name="name" 
                                   value="<?php echo $program ? esc_attr($program->name) : ''; ?>" 
                                   class="regular-text" required>
                            <p class="description">Examples: Home Visits, Food Pantry, Thrift Store, Disaster Services</p>
                        </td>
                    </tr>
                
                    <tr>
                        <th><label for="short_description">Short Description</label></th>
                        <td>
                            <input type="text" id="short_description" name="short_description" 
                                   value="<?php echo $program ? esc_attr($program->short_description) : ''; ?>" 
                                   class="large-text" maxlength="150">
                            <p class="description">Brief one-line description (shown on interest form)</p>
                        </td>
                    </tr>
                
                    <tr>
                        <th><label for="long_description">Long Description</label></th>
                        <td>
                            <textarea id="long_description" name="long_description" rows="8" class="large-text"><?php echo $program ? wp_kses_post($program->long_description) : ''; ?></textarea>
                            <p class="description">Full description (HTML allowed: bold, italic, links, lists)</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="email_description">Email Description</label></th>
                        <td>
                            <textarea id="email_description" name="email_description" rows="5" class="large-text"><?php echo $program ? wp_kses_post($program->email_description) : ''; ?></textarea>
                            <p class="description">Description used in interest form emails. Include what volunteers will do and any details to help them understand the opportunity.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label>Schedule Days</label></th>
                        <td>
                            <?php
                            $schedule_days = $program && !empty($program->schedule_days) ? explode(', ', $program->schedule_days) : array();
                            $days = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
                            foreach ($days as $day) {
                                $checked = in_array($day, $schedule_days) ? 'checked' : '';
                                echo '<label style="display: inline-block; margin-right: 15px;">';
                                echo '<input type="checkbox" name="schedule_days[]" value="' . esc_attr($day) . '" ' . $checked . '> ' . esc_html($day);
                                echo '</label>';
                            }
                            ?>
                            <p class="description">Days this program typically operates</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="schedule_times">Schedule Times</label></th>
                        <td>
                            <input type="text" id="schedule_times" name="schedule_times" value="<?php echo $program ? esc_attr($program->schedule_times) : ''; ?>" class="regular-text" placeholder="e.g., 8:30am - Noon">
                            <p class="description">Time range for this program (e.g., "8:30am - Noon", "Morning and Afternoon")</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="display_order">Display Order *</label></th>
                        <td>
                            <input type="number" id="display_order" name="display_order" 
                                   value="<?php echo $program ? esc_attr($program->display_order) : $default_order; ?>" 
                                   min="1" required>
                            <p class="description">Order in which this program appears (lower numbers first)</p>
                        </td>
                    </tr>
                
                    <tr>
                        <th><label for="active_status">Status *</label></th>
                        <td>
                            <select id="active_status" name="active_status" required>
                                <option value="Active" <?php echo $program && $program->active_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $program && $program->active_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                            <p class="description">Only active programs appear on the volunteer interest form</p>
                        </td>
                    </tr>
                </table>
            
                <p class="submit">
                    <input type="submit" name="fs_save_program" class="button button-primary" value="<?php echo $is_new ? 'Create Program' : 'Update Program'; ?>">
                    <a href="<?php echo admin_url('admin.php?page=fs-programs'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
    
        <style>
            .program-form {
                max-width: 900px;
            }
        </style>
        <?php
    }
    
    private static function create_in_monday($program_id) {
        global $wpdb;
        
        $program = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_programs WHERE id = %d",
            $program_id
        ));
        
        if (!$program) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['programs'])) {
            return false;
        }
        
        $column_values = array(
            'text_mkxulz0s' => $program->short_description,
            'long_text_mkxum1mq' => $program->long_description,
            'color_mkxum2xc' => array('label' => $program->active_status),
            'numbers_mkxum4hw' => $program->display_order
        );
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            create_item(
                board_id: ' . $board_ids['programs'] . ',
                item_name: "' . addslashes($program->name) . '",
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
    
    private static function sync_to_monday($program_id) {
        global $wpdb;
        
        $program = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_programs WHERE id = %d",
            $program_id
        ));
        
        if (!$program || !$program->monday_id) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['programs'])) {
            return false;
        }
        
        $column_values = array(
            'text_mkxulz0s' => $program->short_description,
            'long_text_mkxum1mq' => $program->long_description,
            'color_mkxum2xc' => array('label' => $program->active_status),
            'numbers_mkxum4hw' => $program->display_order
        );
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            change_multiple_column_values(
                item_id: ' . $program->monday_id . ',
                board_id: ' . $board_ids['programs'] . ',
                column_values: "' . $column_values_escaped . '"
            ) {
                id
            }
        }';
        
        $api->query_raw($mutation);
        
        // Also update the item name
        $name_mutation = 'mutation {
            change_simple_column_value(
                item_id: ' . $program->monday_id . ',
                board_id: ' . $board_ids['programs'] . ',
                column_id: "name",
                value: "' . addslashes($program->name) . '"
            ) {
                id
            }
        }';
        
        $api->query_raw($name_mutation);
        
        return true;
    }
}

FS_Admin_Programs::init();
