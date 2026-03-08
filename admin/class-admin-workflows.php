<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Workflows {
    
    public static function init() {
        // Add menu pages
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);
        
        // Handle form submissions
        add_action('admin_init', array(__CLASS__, 'handle_form_submission'));
        
        // Handle delete action
        add_action('admin_init', array(__CLASS__, 'handle_delete'));
    }
    
    public static function add_menu_pages() {
        // List workflows
        add_submenu_page(
            'friendshyft',
            'Onboarding Workflows',
            'Workflows',
            'manage_options',
            'fs-workflows',
            array(__CLASS__, 'list_page')
        );
        
        // Add/Edit workflow (hidden from menu)
        add_submenu_page(
            null,
            'Add Workflow',
            'Add Workflow',
            'manage_options',
            'fs-add-workflow',
            array(__CLASS__, 'edit_page')
        );
        
        add_submenu_page(
            null,
            'Edit Workflow',
            'Edit Workflow',
            'manage_options',
            'fs-edit-workflow',
            array(__CLASS__, 'edit_page')
        );
    }
    
    public static function handle_form_submission() {
        if (!isset($_POST['fs_save_workflow']) || !check_admin_referer('fs_workflow_form')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        $workflow_id = isset($_POST['workflow_id']) ? intval($_POST['workflow_id']) : 0;
        
        // Build steps array from submitted data
        $steps = array();
        if (isset($_POST['step_name']) && is_array($_POST['step_name'])) {
            foreach ($_POST['step_name'] as $index => $step_name) {
                if (!empty($step_name)) {
                    $steps[] = array(
                        'name' => sanitize_text_field(wp_unslash($step_name)),
                        'type' => sanitize_text_field(wp_unslash($_POST['step_type'][$index] ?? '')),
                        'order' => intval($_POST['step_order'][$index] ?? 0),
                        'required' => isset($_POST['step_required'][$index]) ? true : false,
                        'description' => sanitize_textarea_field(wp_unslash($_POST['step_description'][$index] ?? '')),
                        'content_url' => esc_url_raw(wp_unslash($_POST['step_content_url'][$index] ?? ''))
                    );
                }
            }

            // Sort by order
            usort($steps, function($a, $b) {
                return $a['order'] - $b['order'];
            });
        }

        $data = array(
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'status' => sanitize_text_field(wp_unslash($_POST['status'] ?? 'active')),
            'steps' => json_encode($steps),
            'last_sync' => current_time('mysql')
        );
        
        if ($workflow_id > 0) {
            // Update existing
            $wpdb->update(
                $wpdb->prefix . 'fs_workflows',
                $data,
                array('id' => $workflow_id)
            );

            // Log the update
            if (class_exists('FS_Audit_Log')) {
                FS_Audit_Log::log('workflow_updated', 'workflow', $workflow_id, $data);
            }

            // Sync to Monday.com if configured
            if (FS_Monday_API::is_configured()) {
                self::sync_to_monday($workflow_id);
            }

            $redirect = add_query_arg(
                array('page' => 'fs-workflows', 'updated' => '1'),
                admin_url('admin.php')
            );
        } else {
            // Create new
            $wpdb->insert(
                $wpdb->prefix . 'fs_workflows',
                $data
            );

            $workflow_id = $wpdb->insert_id;

            // Log the creation
            if (class_exists('FS_Audit_Log')) {
                FS_Audit_Log::log('workflow_created', 'workflow', $workflow_id, $data);
            }

            // Try to create in Monday.com if configured
            if (FS_Monday_API::is_configured()) {
                $monday_id = self::create_in_monday($workflow_id);
                if ($monday_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'fs_workflows',
                        array('monday_id' => $monday_id),
                        array('id' => $workflow_id)
                    );
                }
            }

            $redirect = add_query_arg(
                array('page' => 'fs-workflows', 'created' => '1'),
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

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fs_delete_workflow_' . $_GET['id'])) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $workflow_id = intval($_GET['id']);
        
        // Check if workflow is assigned to any volunteers
        $assigned_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_progress WHERE workflow_id = %d",
            $workflow_id
        ));
        
        if ($assigned_count > 0) {
            $redirect = add_query_arg(
                array('page' => 'fs-workflows', 'error' => 'assigned'),
                admin_url('admin.php')
            );
            wp_redirect($redirect);
            exit;
        }
        
        // Get workflow for Monday.com deletion
        $workflow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_workflows WHERE id = %d",
            $workflow_id
        ));
        
        // Delete from database
        $wpdb->delete(
            $wpdb->prefix . 'fs_workflows',
            array('id' => $workflow_id)
        );

        // Log the deletion
        if (class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('workflow_deleted', 'workflow', $workflow_id, array(
                'name' => $workflow->name ?? 'Unknown',
                'monday_id' => $workflow->monday_id ?? null
            ));
        }

        // Delete from Monday.com if configured and has Monday ID
        if (FS_Monday_API::is_configured() && !empty($workflow->monday_id)) {
            $api = new FS_Monday_API();
            $mutation = 'mutation { delete_item(item_id: ' . $workflow->monday_id . ') { id } }';
            $api->query_raw($mutation);
        }

        $redirect = add_query_arg(
            array('page' => 'fs-workflows', 'deleted' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
    
    public static function list_page() {
        global $wpdb;
        
        // Get all workflows with volunteer counts
        $workflows = $wpdb->get_results(
            "SELECT w.*, 
                    COUNT(p.volunteer_id) as volunteer_count
            FROM {$wpdb->prefix}fs_workflows w
            LEFT JOIN {$wpdb->prefix}fs_progress p ON w.id = p.workflow_id
            GROUP BY w.id
            ORDER BY w.name ASC"
        );
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Onboarding Workflows</h1>
            <a href="<?php echo admin_url('admin.php?page=fs-add-workflow'); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">
            
            <?php if (isset($_GET['created'])): ?>
                <div class="notice notice-success is-dismissible"><p>Workflow created successfully!</p></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Workflow updated successfully!</p></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible"><p>Workflow deleted successfully!</p></div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error']) && $_GET['error'] === 'assigned'): ?>
                <div class="notice notice-error is-dismissible"><p>Cannot delete workflow that is assigned to volunteers.</p></div>
            <?php endif; ?>
            
            <?php if (empty($workflows)): ?>
                <p>No workflows found. <a href="<?php echo admin_url('admin.php?page=fs-add-workflow'); ?>">Create your first workflow</a>.</p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Name</th>
                            <th style="width: 40%;">Description</th>
                            <th style="width: 10%;">Steps</th>
                            <th style="width: 10%;">Status</th>
                            <th style="width: 10%;">Volunteers</th>
                            <th style="width: 5%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workflows as $workflow): ?>
                            <?php
                            $steps = json_decode($workflow->steps, true);
                            $step_count = is_array($steps) ? count($steps) : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo admin_url('admin.php?page=fs-edit-workflow&id=' . $workflow->id); ?>">
                                            <?php echo esc_html($workflow->name); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html($workflow->description ?: '—'); ?></td>
                                <td><?php echo $step_count; ?> steps</td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr(strtolower($workflow->status)); ?>">
                                        <?php echo esc_html($workflow->status); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($workflow->volunteer_count); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-workflow&id=' . $workflow->id); ?>" class="button button-small">Edit</a>
                                    <?php if ($workflow->volunteer_count == 0): ?>
                                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fs-workflows&action=delete&id=' . $workflow->id), 'fs_delete_workflow_' . $workflow->id); ?>"
                                           class="button button-small button-link-delete"
                                           onclick="return confirm('Are you sure you want to delete this workflow?')">Delete</a>
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
            .status-inactive { background: #f8d7da; color: #721c24; }
        </style>
        <?php
    }
    
    public static function edit_page() {
        global $wpdb;
        
        $workflow_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $is_new = $workflow_id === 0;
        
        $workflow = null;
        $steps = array();
        
        if (!$is_new) {
            $workflow = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_workflows WHERE id = %d",
                $workflow_id
            ));
            
            if (!$workflow) {
                echo '<div class="wrap"><h1>Workflow not found</h1></div>';
                return;
            }
            
            $steps = json_decode($workflow->steps, true);
            if (!is_array($steps)) {
                $steps = array();
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? 'Add New Workflow' : 'Edit Workflow'; ?></h1>
            
            <form method="post" class="workflow-form" id="workflow-form">
                <?php wp_nonce_field('fs_workflow_form'); ?>
                <?php if (!$is_new): ?>
                    <input type="hidden" name="workflow_id" value="<?php echo $workflow_id; ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="name">Workflow Name *</label></th>
                        <td>
                            <input type="text" id="name" name="name" 
                                   value="<?php echo $workflow ? esc_attr($workflow->name) : ''; ?>" 
                                   class="regular-text" required>
                            <p class="description">Examples: General Volunteer, Home Visitor, Food Pantry</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="description">Description</label></th>
                        <td>
                            <textarea id="description" name="description" rows="3" class="large-text"><?php echo $workflow ? esc_textarea($workflow->description) : ''; ?></textarea>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="status">Status *</label></th>
                        <td>
                            <select id="status" name="status" required>
                                <option value="Active" <?php echo $workflow && $workflow->status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $workflow && $workflow->status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h2>Workflow Steps</h2>
                <p class="description">Define the steps volunteers need to complete in this onboarding workflow.</p>
                
                <div id="steps-container">
                    <?php if (!empty($steps)): ?>
                        <?php foreach ($steps as $index => $step): ?>
                            <?php self::render_step_row($index, $step); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php self::render_step_row(0); ?>
                    <?php endif; ?>
                </div>
                
                <p>
                    <button type="button" class="button" id="add-step">Add Step</button>
                </p>
                
                <p class="submit">
                    <input type="submit" name="fs_save_workflow" class="button button-primary" value="<?php echo $is_new ? 'Create Workflow' : 'Update Workflow'; ?>">
                    <a href="<?php echo admin_url('admin.php?page=fs-workflows'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        
        <style>
            .workflow-form {
                max-width: 1200px;
            }
            .step-row {
                background: #f8f9fa;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 15px;
                position: relative;
            }
            .step-row h3 {
                margin-top: 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .step-fields {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }
            .step-field {
                display: flex;
                flex-direction: column;
            }
            .step-field label {
                font-weight: 600;
                margin-bottom: 5px;
            }
            .step-field input,
            .step-field select,
            .step-field textarea {
                width: 100%;
            }
            .step-field-full {
                grid-column: 1 / -1;
            }
            .remove-step {
                color: #b32d2e;
                cursor: pointer;
                text-decoration: none;
            }
            .remove-step:hover {
                color: #dc3232;
            }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var stepIndex = <?php echo !empty($steps) ? count($steps) : 1; ?>;
            
            document.getElementById('add-step').addEventListener('click', function() {
                var container = document.getElementById('steps-container');
                var template = <?php echo json_encode(self::get_step_template()); ?>;
                
                // Replace placeholders
                template = template.replace(/\{\{INDEX\}\}/g, stepIndex);
                template = template.replace(/\{\{ORDER\}\}/g, stepIndex + 1);
                
                container.insertAdjacentHTML('beforeend', template);
                stepIndex++;
            });
            
            document.getElementById('steps-container').addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-step')) {
                    e.preventDefault();
                    if (confirm('Remove this step?')) {
                        e.target.closest('.step-row').remove();
                    }
                }
            });
        });
        </script>
        <?php
    }
    
    private static function render_step_row($index, $step = null) {
        ?>
        <div class="step-row">
            <h3>
                <span>Step <?php echo $index + 1; ?></span>
                <a href="#" class="remove-step">Remove</a>
            </h3>
            
            <div class="step-fields">
                <div class="step-field">
                    <label>Step Name *</label>
                    <input type="text" name="step_name[]" 
                           value="<?php echo $step ? esc_attr($step['name']) : ''; ?>" required>
                </div>
                
                <div class="step-field">
                    <label>Type *</label>
                    <select name="step_type[]" required>
                        <option value="Automated" <?php echo $step && $step['type'] === 'Automated' ? 'selected' : ''; ?>>Automated</option>
                        <option value="Manual" <?php echo $step && $step['type'] === 'Manual' ? 'selected' : ''; ?>>Manual</option>
                        <option value="In-Person" <?php echo $step && $step['type'] === 'In-Person' ? 'selected' : ''; ?>>In-Person</option>
                    </select>
                </div>
                
                <div class="step-field">
                    <label>Order *</label>
                    <input type="number" name="step_order[]" 
                           value="<?php echo $step ? esc_attr($step['order']) : $index + 1; ?>" 
                           min="1" required>
                </div>
                
                <div class="step-field">
                    <label>
                        <input type="checkbox" name="step_required[<?php echo $index; ?>]" 
                               value="1" <?php echo $step && !empty($step['required']) ? 'checked' : ''; ?>>
                        Required
                    </label>
                </div>
                
                <div class="step-field step-field-full">
                    <label>Description</label>
                    <textarea name="step_description[]" rows="2"><?php echo $step ? esc_textarea($step['description']) : ''; ?></textarea>
                </div>
                
                <div class="step-field step-field-full">
                    <label>Content URL (for Automated steps)</label>
                    <input type="url" name="step_content_url[]" 
                           value="<?php echo $step ? esc_attr($step['content_url']) : ''; ?>" 
                           placeholder="https://example.com/training-video">
                </div>
            </div>
        </div>
        <?php
    }
    
    private static function get_step_template() {
        ob_start();
        ?>
        <div class="step-row">
            <h3>
                <span>Step {{INDEX}}</span>
                <a href="#" class="remove-step">Remove</a>
            </h3>
            
            <div class="step-fields">
                <div class="step-field">
                    <label>Step Name *</label>
                    <input type="text" name="step_name[]" value="" required>
                </div>
                
                <div class="step-field">
                    <label>Type *</label>
                    <select name="step_type[]" required>
                        <option value="Automated">Automated</option>
                        <option value="Manual">Manual</option>
                        <option value="In-Person">In-Person</option>
                    </select>
                </div>
                
                <div class="step-field">
                    <label>Order *</label>
                    <input type="number" name="step_order[]" value="{{ORDER}}" min="1" required>
                </div>
                
                <div class="step-field">
                    <label>
                        <input type="checkbox" name="step_required[{{INDEX}}]" value="1">
                        Required
                    </label>
                </div>
                
                <div class="step-field step-field-full">
                    <label>Description</label>
                    <textarea name="step_description[]" rows="2"></textarea>
                </div>
                
                <div class="step-field step-field-full">
                    <label>Content URL (for Automated steps)</label>
                    <input type="url" name="step_content_url[]" placeholder="https://example.com/training-video">
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    private static function create_in_monday($workflow_id) {
        global $wpdb;
        
        $workflow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_workflows WHERE id = %d",
            $workflow_id
        ));
        
        if (!$workflow) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['workflows'])) {
            return false;
        }
        
        $column_values = array(
            'long_text_mkxrqtgr' => $workflow->description,
            'color_mkxrqvxd' => array('label' => $workflow->status)
        );
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            create_item(
                board_id: ' . $board_ids['workflows'] . ',
                item_name: "' . addslashes($workflow->name) . '",
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
    
    private static function sync_to_monday($workflow_id) {
        global $wpdb;
        
        $workflow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_workflows WHERE id = %d",
            $workflow_id
        ));
        
        if (!$workflow || !$workflow->monday_id) {
            return false;
        }
        
        $api = new FS_Monday_API();
        $board_ids = $api->get_board_ids();
        
        if (empty($board_ids['workflows'])) {
            return false;
        }
        
        $column_values = array(
            'long_text_mkxrqtgr' => $workflow->description,
            'color_mkxrqvxd' => array('label' => $workflow->status)
        );
        
        $column_values_json = json_encode($column_values);
        $column_values_escaped = addslashes($column_values_json);
        
        $mutation = 'mutation {
            change_multiple_column_values(
                item_id: ' . $workflow->monday_id . ',
                board_id: ' . $board_ids['workflows'] . ',
                column_values: "' . $column_values_escaped . '"
            ) {
                id
            }
        }';
        
        $api->query_raw($mutation);
        
        return true;
    }
}

FS_Admin_Workflows::init();