<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Roles {
    
    public static function init() {
        // Add menu pages
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);
        
        // Handle form submissions
        add_action('admin_init', array(__CLASS__, 'handle_form_submission'));
        
        // Handle delete action
        add_action('admin_init', array(__CLASS__, 'handle_delete'));
    }
    
    public static function add_menu_pages() {
        // List roles
        add_submenu_page(
            'friendshyft',
            'Volunteer Roles',
            'Roles',
            'manage_options',
            'fs-roles',
            array(__CLASS__, 'list_page')
        );
        
        // Add/Edit role (hidden from menu)
        add_submenu_page(
            null,
            'Add Role',
            'Add Role',
            'manage_options',
            'fs-add-role',
            array(__CLASS__, 'edit_page')
        );
        
        add_submenu_page(
            null,
            'Edit Role',
            'Edit Role',
            'manage_options',
            'fs-edit-role',
            array(__CLASS__, 'edit_page')
        );
    }
    
    public static function handle_form_submission() {
    // Only process if our specific action is set
    if (!isset($_POST['action']) || $_POST['action'] !== 'fs_save_role') {
        return;
    }
    
    if (!check_admin_referer('fs_role_form')) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    
    $role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : 0;
    
    $data = array(
        'name' => sanitize_text_field(wp_unslash($_POST['name'])),
        'description' => wp_kses_post(wp_unslash($_POST['description'])),
        'program_id' => !empty($_POST['program_id']) ? intval($_POST['program_id']) : null,
        'status' => sanitize_text_field(wp_unslash($_POST['status'])),
        'training_required' => isset($_POST['training_required']) ? 1 : 0,
        'minimum_age' => !empty($_POST['minimum_age']) ? intval($_POST['minimum_age']) : null,
        'workflow_id' => !empty($_POST['workflow_id']) ? intval($_POST['workflow_id']) : null,
        'last_sync' => current_time('mysql')
    );
    
    if ($role_id > 0) {
        // Update existing
        $wpdb->update(
            $wpdb->prefix . 'fs_roles',
            $data,
            array('id' => $role_id)
        );

        // Log the update
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('role_updated', 'role', $role_id, $data);
        }

        $redirect = add_query_arg(
            array('page' => 'fs-roles', 'updated' => '1'),
            admin_url('admin.php')
        );
    } else {
        // Create new
        $wpdb->insert(
            $wpdb->prefix . 'fs_roles',
            $data
        );

        $role_id = $wpdb->insert_id;

        // Log the creation
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('role_created', 'role', $role_id, $data);
        }

        $redirect = add_query_arg(
            array('page' => 'fs-roles', 'created' => '1'),
            admin_url('admin.php')
        );
    }
    
    wp_redirect($redirect);
    exit;
}
    
    public static function handle_delete() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'delete' || !isset($_GET['id'])) {
            return;
        }
        
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fs_delete_role_' . $_GET['id'])) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $role_id = intval($_GET['id']);
        
        // Check if role is assigned to any volunteers
        $assigned_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_roles WHERE role_id = %d",
            $role_id
        ));
        
        if ($assigned_count > 0) {
            $redirect = add_query_arg(
                array('page' => 'fs-roles', 'error' => 'assigned'),
                admin_url('admin.php')
            );
            wp_redirect($redirect);
            exit;
        }
        
        // Get role for Monday.com deletion
        $role = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_roles WHERE id = %d",
            $role_id
        ));
        
        // Delete from database
        $wpdb->delete(
            $wpdb->prefix . 'fs_roles',
            array('id' => $role_id)
        );

        // Log the deletion
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('role_deleted', 'role', $role_id, array(
                'name' => $role->name ?? 'Unknown',
                'monday_id' => $role->monday_id ?? null
            ));
        }

        // Delete from Monday.com if configured and has Monday ID
        if (FS_Monday_API::is_configured() && !empty($role->monday_id)) {
            $api = new FS_Monday_API();
            $mutation = 'mutation { delete_item(item_id: ' . $role->monday_id . ') { id } }';
            $api->query_raw($mutation);
        }

        $redirect = add_query_arg(
            array('page' => 'fs-roles', 'deleted' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function list_page() {
    global $wpdb;
    
    // Handle messages
    if (isset($_GET['updated'])) {
        echo '<div class="notice notice-success"><p>Role updated successfully!</p></div>';
    }
    if (isset($_GET['created'])) {
        echo '<div class="notice notice-success"><p>Role created successfully!</p></div>';
    }
    if (isset($_GET['deleted'])) {
        echo '<div class="notice notice-success"><p>Role deleted successfully!</p></div>';
    }
    
    $roles = $wpdb->get_results(
        "SELECT r.*, p.name as program_name, w.name as workflow_name,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_roles WHERE role_id = r.id) as volunteer_count
         FROM {$wpdb->prefix}fs_roles r
         LEFT JOIN {$wpdb->prefix}fs_programs p ON r.program_id = p.id
         LEFT JOIN {$wpdb->prefix}fs_workflows w ON r.workflow_id = w.id
         ORDER BY p.name ASC, r.name ASC"
    );
    
    ?>
    <div class="wrap">
        <h1>
            Volunteer Roles
            <a href="<?php echo admin_url('admin.php?page=fs-add-role'); ?>" class="page-title-action">Add New Role</a>
        </h1>
        
        <?php if (empty($roles)): ?>
            <p>No roles defined yet. <a href="<?php echo admin_url('admin.php?page=fs-add-role'); ?>">Create your first role</a>.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Role Name</th>
                        <th>Program</th>
                        <th>Workflow</th>
                        <th>Description</th>
                        <th>Requirements</th>
                        <th>Status</th>
                        <th>Volunteers</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><strong><?php echo esc_html($role->name); ?></strong></td>
                            <td>
                                <?php if ($role->program_name): ?>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-program&id=' . $role->program_id); ?>">
                                        <?php echo esc_html($role->program_name); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($role->workflow_name): ?>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-workflow&id=' . $role->workflow_id); ?>">
                                        <?php echo esc_html($role->workflow_name); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($role->description ?: '—'); ?></td>
                            <td>
                                <?php
                                $requirements = array();
                                if (!empty($role->training_required)) {
                                    $requirements[] = 'Training Required';
                                }
                                if (!empty($role->minimum_age)) {
                                    $requirements[] = 'Min Age: ' . $role->minimum_age;
                                }
                                echo esc_html(!empty($requirements) ? implode(', ', $requirements) : '—');
                                ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($role->status); ?>">
                                    <?php echo esc_html($role->status); ?>
                                </span>
                            </td>
                            <td><?php echo $role->volunteer_count; ?> volunteer(s)</td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=fs-edit-role&id=' . $role->id); ?>">Edit</a> |
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fs-roles&action=delete&id=' . $role->id), 'fs_delete_role_' . $role->id); ?>"
                                   onclick="return confirm('Delete this role?');"
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
        
        $role_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $is_new = $role_id === 0;
        
        $role = null;
        if (!$is_new) {
            $role = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_roles WHERE id = %d",
                $role_id
            ));
            
            if (!$role) {
                echo '<div class="wrap"><h1>Role not found</h1></div>';
                return;
            }
            
            // Get volunteers assigned to this role
            $volunteers = $wpdb->get_results($wpdb->prepare(
                "SELECT v.* FROM {$wpdb->prefix}fs_volunteers v
                JOIN {$wpdb->prefix}fs_volunteer_roles vr ON v.id = vr.volunteer_id
                WHERE vr.role_id = %d
                ORDER BY v.name",
                $role_id
            ));
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? 'Add New Role' : 'Edit Role'; ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin.php'); ?>">
    <?php wp_nonce_field('fs_role_form'); ?>
    <input type="hidden" name="page" value="fs-roles">
    <input type="hidden" name="action" value="fs_save_role">
    <?php if (!$is_new): ?>
        <input type="hidden" name="role_id" value="<?php echo $role_id; ?>">
    <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="name">Role Name *</label></th>
                        <td>
                            <input type="text" id="name" name="name" 
                                   value="<?php echo $role ? esc_attr($role->name) : ''; ?>" 
                                   class="regular-text" required>
                            <p class="description">Examples: Home Visitor, Food Pantry, Thrift Store, Board Member</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="description">Description</label></th>
                        <td>
                            <textarea id="description" name="description" rows="8" class="large-text"><?php echo $role ? wp_kses_post($role->description) : ''; ?></textarea>
                            <p class="description">Full description (HTML allowed: bold, italic, links, lists)</p>
                        </td>
                    </tr>

                    <tr>
    <th><label for="program_id">Program</label></th>
    <td>
        <select id="program_id" name="program_id">
            <option value="">— No Program —</option>
            <?php
            $programs = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}fs_programs ORDER BY name ASC");
            foreach ($programs as $prog):
            ?>
                <option value="<?php echo $prog->id; ?>" <?php echo $role && $role->program_id == $prog->id ? 'selected' : ''; ?>>
                    <?php echo esc_html($prog->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Optionally assign this role to a program</p>
    </td>
</tr>
                    
                    <tr>
                        <th><label for="status">Status *</label></th>
                        <td>
                            <select id="status" name="status" required>
                                <option value="Active" <?php echo $role && $role->status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $role && $role->status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                            <p class="description">Only active roles can be assigned to volunteers</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="training_required">Training Required</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="training_required" name="training_required" value="1" <?php echo $role && $role->training_required ? 'checked' : ''; ?>>
                                Yes, this role requires training
                            </label>
                            <p class="description">Check if volunteers must complete training before taking this role</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="minimum_age">Minimum Age</label></th>
                        <td>
                            <input type="number" id="minimum_age" name="minimum_age"
                                   value="<?php echo $role && $role->minimum_age ? esc_attr($role->minimum_age) : ''; ?>"
                                   min="0" max="99" class="small-text">
                            <p class="description">Minimum age required for this role (leave blank for no age restriction)</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="workflow_id">Onboarding Workflow</label></th>
                        <td>
                            <select id="workflow_id" name="workflow_id">
                                <option value="">— No Workflow —</option>
                                <?php
                                $workflows = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}fs_workflows WHERE status = 'Active' ORDER BY name ASC");
                                foreach ($workflows as $workflow):
                                ?>
                                    <option value="<?php echo $workflow->id; ?>" <?php echo $role && $role->workflow_id == $workflow->id ? 'selected' : ''; ?>>
                                        <?php echo esc_html($workflow->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Assign volunteers with this role to an onboarding workflow</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
    <input type="submit" name="fs_save_role" class="button button-primary" value="<?php echo $is_new ? 'Create Role' : 'Update Role'; ?>">
    <a href="<?php echo admin_url('admin.php?page=fs-roles'); ?>" class="button">Cancel</a>
</p>
            </form>
            
            <?php if (!$is_new && !empty($volunteers)): ?>
                <hr>
                <h2>Volunteers with this Role (<?php echo count($volunteers); ?>)</h2>
                <table class="wp-list-table widefat">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($volunteers as $vol): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $vol->id); ?>">
                                        <?php echo esc_html($vol->name); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($vol->email); ?></td>
                                <td><?php echo esc_html($vol->volunteer_status); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $vol->id); ?>" class="button button-small">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <style>
            .role-form {
                max-width: 900px;
            }
        </style>
        <?php
    }
    
    private static function create_in_monday($role_id) {
        global $wpdb;
        
        $role = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_roles WHERE id = %d",
            $role_id
        ));
        
        if (!$role) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['roles'])) {
            return false;
        }
        
        $column_values = array(
            'long_text_mkxsd0cf' => $role->description,
            'color_mkxsn8ho' => array('label' => $role->status)
        );
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            create_item(
                board_id: ' . $board_ids['roles'] . ',
                item_name: "' . addslashes($role->name) . '",
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
    
    private static function sync_to_monday($role_id) {
        global $wpdb;
        
        $role = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_roles WHERE id = %d",
            $role_id
        ));
        
        if (!$role || !$role->monday_id) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['roles'])) {
            return false;
        }
        
        $column_values = array(
            'long_text_mkxsd0cf' => $role->description,
            'color_mkxsn8ho' => array('label' => $role->status)
        );
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            change_multiple_column_values(
                item_id: ' . $role->monday_id . ',
                board_id: ' . $board_ids['roles'] . ',
                column_values: "' . $column_values_escaped . '"
            ) {
                id
            }
        }';
        
        $api->query_raw($mutation);
        
        // Also update the item name
        $name_mutation = 'mutation {
            change_simple_column_value(
                item_id: ' . $role->monday_id . ',
                board_id: ' . $board_ids['roles'] . ',
                column_id: "name",
                value: "' . addslashes($role->name) . '"
            ) {
                id
            }
        }';
        
        $api->query_raw($name_mutation);
        
        return true;
    }
}

FS_Admin_Roles::init();
