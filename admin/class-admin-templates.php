<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Templates {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);
        add_action('admin_init', array(__CLASS__, 'handle_form_submission'));
        add_action('admin_post_fs_delete_template', array(__CLASS__, 'handle_delete'));
        add_action('admin_post_fs_generate_now', array(__CLASS__, 'handle_generate_now'));
    }
    
    public static function add_menu_pages() {
        // List templates
        add_submenu_page(
            'friendshyft',
            'Opportunity Templates',
            'Templates',
            'manage_options',
            'fs-templates',
            array(__CLASS__, 'list_page')
        );
        
        // Add/Edit template (hidden from menu)
        add_submenu_page(
            null,
            'Add Template',
            'Add Template',
            'manage_options',
            'fs-add-template',
            array(__CLASS__, 'edit_page')
        );
        
        add_submenu_page(
            null,
            'Edit Template',
            'Edit Template',
            'manage_options',
            'fs-edit-template',
            array(__CLASS__, 'edit_page')
        );
    }
    
    public static function list_page() {
        global $wpdb;
        
        // Handle messages
        if (isset($_GET['created'])) {
            echo '<div class="notice notice-success"><p>Template created successfully!</p></div>';
        }
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Template updated successfully!</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success"><p>Template deleted successfully!</p></div>';
        }
        if (isset($_GET['generated'])) {
            echo '<div class="notice notice-success"><p>Opportunities generated successfully!</p></div>';
        }
        
        $templates = $wpdb->get_results(
            "SELECT t.*, 
                    COUNT(DISTINCT o.id) as opportunity_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}fs_opportunity_shifts WHERE template_id = t.id AND is_template = 1) as shift_count
             FROM {$wpdb->prefix}fs_opportunity_templates t
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON t.id = o.template_id
             GROUP BY t.id
             ORDER BY t.created_date DESC"
        );
        
        ?>
        <div class="wrap">
            <h1>
                Opportunity Templates
                <a href="<?php echo admin_url('admin.php?page=fs-add-template'); ?>" class="page-title-action">Add New Template</a>
            </h1>
            
            <p class="description">
                Templates automatically generate opportunities based on recurring schedules. 
                Opportunities are generated up to 90 days in advance.
            </p>
            
            <?php if (empty($templates)): ?>
                <div class="notice notice-info">
                    <p>No templates yet. <a href="<?php echo admin_url('admin.php?page=fs-add-template'); ?>">Create your first template</a> to get started!</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Template Name</th>
                            <th>Type</th>
                            <th>Schedule</th>
                            <th>Shifts</th>
                            <th>Generated Opportunities</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $template): ?>
                            <?php
                            $pattern = json_decode($template->recurrence_pattern, true);
                            $days_of_week = $pattern['days_of_week'] ?? [];
                            $day_names = array(1 => 'M', 2 => 'T', 3 => 'W', 4 => 'Th', 5 => 'F', 6 => 'Sa', 7 => 'Su');

                            // Handle schedule display based on template type
                            if ($template->template_type === 'one_time') {
                                $schedule_display = 'One-time event';
                            } elseif ($template->template_type === 'flexible_selection') {
                                $schedule_display = 'Flexible pool';
                            } elseif (empty($days_of_week)) {
                                $schedule_display = 'No days selected';
                            } else {
                                $schedule_display = implode(', ', array_map(function($d) use ($day_names) {
                                    return $day_names[$d];
                                }, $days_of_week));
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($template->title ?? ''); ?></strong>
                                    <?php if ($template->location): ?>
                                        <br><small>📍 <?php echo esc_html($template->location ?? ''); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $type_labels = array(
                                        'one_time' => 'One-time',
                                        'daily_recurring' => 'Daily Recurring',
                                        'weekly_recurring' => 'Weekly Recurring',
                                        'flexible_selection' => 'Flexible Pool',
                                        'date_range' => 'Date Range'
                                    );
                                    echo $type_labels[$template->template_type ?? ''] ?? ($template->template_type ?? '');
                                    ?>
                                </td>
                                <td>
                                    <?php echo $schedule_display; ?><br>
                                    <small>
                                        From: <?php echo date('M j, Y', strtotime($template->start_date)); ?><br>
                                        <?php if ($template->end_date): ?>
                                            To: <?php echo date('M j, Y', strtotime($template->end_date)); ?>
                                        <?php else: ?>
                                            Ongoing
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td><?php echo $template->shift_count; ?> shift(s)</td>
                                <td>
                                    <?php echo $template->opportunity_count; ?> created
                                    <?php if ($template->last_generation_date): ?>
                                        <br><small>Last: <?php echo date('M j, Y', strtotime($template->last_generation_date)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($template->status ?? 'active'); ?>">
                                        <?php echo esc_html($template->status ?? 'Active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-template&id=' . $template->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_generate_now&template_id=' . $template->id), 'fs_generate_now'); ?>">Generate Now</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=fs_delete_template&id=' . $template->id), 'fs_delete_template_' . $template->id); ?>"
                                       onclick="return confirm('Delete this template? This will NOT delete existing opportunities.');"
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
                .status-paused { background: #fff3cd; color: #856404; }
                .status-inactive { background: #f8d7da; color: #721c24; }
            </style>
        </div>
        <?php
    }
    
    public static function edit_page() {
        global $wpdb;
        
        $template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $is_new = ($template_id === 0);
        
        $template = null;
        $shifts = array();
        
        if (!$is_new) {
            $template = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates WHERE id = %d",
                $template_id
            ));
            
            if (!$template) {
                wp_die('Template not found');
            }
            
            $shifts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts 
                WHERE template_id = %d AND is_template = 1 
                ORDER BY display_order ASC",
                $template_id
            ));
        }
        
        $pattern = $template ? json_decode($template->recurrence_pattern, true) : array('days_of_week' => [1,2,3,4,5]);
        
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? 'Add New Template' : 'Edit Template'; ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin.php'); ?>">
                <?php wp_nonce_field('fs_template_form'); ?>
                <input type="hidden" name="action" value="fs_save_template">
                <input type="hidden" name="page" value="fs-templates">
                <?php if (!$is_new): ?>
                    <input type="hidden" name="template_id" value="<?php echo $template_id; ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="title">Title *</label></th>
                        <td>
                            <input type="text" id="title" name="title" 
                                   value="<?php echo $template ? esc_attr($template->title) : ''; ?>" 
                                   class="regular-text" required>
                            <p class="description">e.g., "Thrift Store Donation Sorting"</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="description">Description</label></th>
                        <td>
                            <textarea id="description" name="description" rows="8" class="large-text"><?php echo $template ? wp_kses_post($template->description) : ''; ?></textarea>
                            <p class="description">Full description (HTML allowed: bold, italic, links, lists)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="location">Location</label></th>
                        <td>
                            <input type="text" id="location" name="location" 
                                   value="<?php echo $template ? esc_attr($template->location) : ''; ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="template_type">Template Type *</label></th>
                        <td>
                            <select id="template_type" name="template_type" required onchange="toggleTemplateOptions()">
                                <option value="one_time" <?php echo $template && $template->template_type === 'one_time' ? 'selected' : ''; ?>>
                                    One-time (Single Occurrence)
                                </option>
                                <option value="daily_recurring" <?php echo $template && $template->template_type === 'daily_recurring' ? 'selected' : ''; ?>>
                                    Daily Recurring (Ongoing)
                                </option>
                                <option value="weekly_recurring" <?php echo $template && $template->template_type === 'weekly_recurring' ? 'selected' : ''; ?>>
                                    Weekly Recurring (Ongoing)
                                </option>
                                <option value="date_range" <?php echo $template && $template->template_type === 'date_range' ? 'selected' : ''; ?>>
                                    Date Range (Specific Period)
                                </option>
                                <option value="flexible_selection" <?php echo $template && $template->template_type === 'flexible_selection' ? 'selected' : ''; ?>>
                                    Flexible Selection Pool (Pick Your Weeks)
                                </option>
                            </select>
                            <p class="description" id="type-description">
                                Choose how opportunities are scheduled
                            </p>
                        </td>
                    </tr>
                    
                    <tr id="days-of-week-row">
                        <th><label>Days of Week *</label></th>
                        <td>
                            <?php
                            $days = array(
                                1 => 'Monday',
                                2 => 'Tuesday',
                                3 => 'Wednesday',
                                4 => 'Thursday',
                                5 => 'Friday',
                                6 => 'Saturday',
                                7 => 'Sunday'
                            );
                            foreach ($days as $num => $name):
                                $checked = in_array($num, $pattern['days_of_week']);
                            ?>
                                <label style="display: inline-block; margin-right: 15px;">
                                    <input type="checkbox" name="days_of_week[]" value="<?php echo $num; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                    <?php echo $name; ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Select which days this opportunity repeats</p>
                        </td>
                    </tr>
    
                    <tr id="flexible_settings" style="display: none;">
                        <th><label>Flexible Selection Settings</label></th>
                        <td>
                            <div style="margin-bottom: 15px;">
                                <label>
                                    <strong>Week Duration:</strong><br>
                                    <select name="flexible_week_pattern" style="margin-top: 5px;">
                                        <option value="monday_friday" <?php echo $template && isset($pattern['flexible_week_pattern']) && $pattern['flexible_week_pattern'] === 'monday_friday' ? 'selected' : ''; ?>>
                                            Monday - Friday (5 days)
                                        </option>
                                        <option value="full_week" <?php echo $template && isset($pattern['flexible_week_pattern']) && $pattern['flexible_week_pattern'] === 'full_week' ? 'selected' : ''; ?>>
                                            Full Week (7 days)
                                        </option>
                                    </select>
                                </label>
                            </div>
        
                            <div style="margin-bottom: 15px;">
                                <label>
                                    <strong>Slots Per Week:</strong><br>
                                    <input type="number" name="flexible_slots_per_week" 
                                           value="<?php echo $template && isset($pattern['flexible_slots_per_week']) ? $pattern['flexible_slots_per_week'] : 1; ?>" 
                                           min="1" style="width: 80px; margin-top: 5px;">
                                    <span class="description">How many volunteers can claim each week?</span>
                                </label>
                            </div>
        
                            <div style="margin-bottom: 15px;">
                                <label>
                                    <strong>Claiming Limits:</strong><br>
                                    <input type="number" name="flexible_min_claims" 
                                           value="<?php echo $template && isset($pattern['flexible_min_claims']) ? $pattern['flexible_min_claims'] : 1; ?>" 
                                           min="0" style="width: 80px; margin-top: 5px;">
                                    <span class="description">Minimum weeks volunteer must claim per period</span>
                                </label>
                                <br>
                                <input type="number" name="flexible_max_claims" 
                                       value="<?php echo $template && isset($pattern['flexible_max_claims']) ? $pattern['flexible_max_claims'] : 4; ?>" 
                                       min="1" style="width: 80px; margin-top: 5px;">
                                <span class="description">Maximum weeks volunteer can claim per period</span>
                            </div>
        
                            <div>
                                <label>
                                    <strong>Claiming Period:</strong><br>
                                    <select name="flexible_period" style="margin-top: 5px;">
                                        <option value="quarterly" <?php echo $template && isset($pattern['flexible_period']) && $pattern['flexible_period'] === 'quarterly' ? 'selected' : ''; ?>>
                                            Quarterly (3 months)
                                        </option>
                                        <option value="biannually" <?php echo $template && isset($pattern['flexible_period']) && $pattern['flexible_period'] === 'biannually' ? 'selected' : ''; ?>>
                                            Bi-annually (6 months)
                                        </option>
                                        <option value="annually" <?php echo $template && isset($pattern['flexible_period']) && $pattern['flexible_period'] === 'annually' ? 'selected' : ''; ?>>
                                            Annually (12 months)
                                        </option>
                                    </select>
                                </label>
                            </div>
        
                            <p class="description" style="margin-top: 15px;">
                                <strong>Example:</strong> "Van Driving" with 1 slot per week, volunteers must claim 1-4 weeks quarterly.
                            </p>
                        </td>
                    </tr>

                    <tr class="flexible-only-field" style="display: none;">
                        <th><label for="handoff_notifications">Handoff Notifications</label></th>
                        <td>
                            <input type="checkbox" 
                                   id="handoff_notifications" 
                                   name="handoff_notifications" 
                                   value="1" 
                                   <?php checked($template->handoff_notifications ?? 0, 1); ?>>
                            <p class="description">
                                Email volunteers at the start and near the end of each period with contact info for the next scheduled volunteer.
                                <br><strong>Only applies to Flexible Selection templates.</strong>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="start_date">Start Date *</label></th>
                        <td>
                            <input type="date" id="start_date" name="start_date" 
                                   value="<?php echo $template ? $template->start_date : date('Y-m-d'); ?>" 
                                   required>
                            <p class="description">When to begin generating opportunities</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="end_date">End Date</label></th>
                        <td>
                            <input type="date" id="end_date" name="end_date" 
                                   value="<?php echo $template ? $template->end_date : ''; ?>">
                            <p class="description">Leave blank for ongoing opportunities</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="requirements">Requirements</label></th>
                        <td>
                            <textarea id="requirements" name="requirements" rows="3" class="large-text"><?php echo $template ? wp_kses_post($template->requirements) : ''; ?></textarea>
                            <p class="description">e.g., "Background check required", "Must be 18+" (HTML allowed: bold, italic, links, lists)</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="required_roles">Required Roles</label></th>
                        <td>
                            <?php
                            $all_roles = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}fs_roles WHERE status = 'Active' ORDER BY name ASC");
                            $selected_roles = $template && !empty($template->required_roles) ? json_decode($template->required_roles, true) : array();

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
                                   value="<?php echo $template ? esc_attr($template->conference) : ''; ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="status">Status *</label></th>
                        <td>
                            <select id="status" name="status" required>
                                <option value="Active" <?php echo $template && $template->status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Paused" <?php echo $template && $template->status === 'Paused' ? 'selected' : ''; ?>>Paused</option>
                                <option value="Inactive" <?php echo $template && $template->status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                            <p class="description">Paused templates won't generate new opportunities until reactivated</p>
                        </td>
                    </tr>
<tr>
    <th><label for="allow_team_signups">Team Signups</label></th>
    <td>
        <label>
            <input type="checkbox" id="allow_team_signups" name="allow_team_signups" value="1"
                   <?php echo ($template && $template->allow_team_signups) ? 'checked' : ''; ?>>
            Allow teams to sign up for opportunities generated from this template
        </label>
        <p class="description">Check this to enable team-based signups alongside individual volunteers for all generated opportunities</p>
    </td>
</tr>
                </table>
                
                <div id="shifts-section">
                    <h2>Time Shifts</h2>
                    <p class="description">Define the available time slots for each day. For example, "9am-11am" and "11am-1pm" creates two shifts per day.</p>
                    
                    <div id="shifts-container">
                        <?php if (!empty($shifts)): ?>
                            <?php foreach ($shifts as $index => $shift): ?>
                                <?php self::render_shift_row($index, $shift); ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php self::render_shift_row(0); ?>
                        <?php endif; ?>
                    </div>
                    
                    <p>
                        <button type="button" class="button" id="add-shift">+ Add Another Shift</button>
                    </p>
                </div>
                
                <p class="submit">
                    <input type="submit" name="fs_save_template" class="button button-primary" value="<?php echo $is_new ? 'Create Template' : 'Update Template'; ?>">
                    <a href="<?php echo admin_url('admin.php?page=fs-templates'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let shiftIndex = <?php echo count($shifts); ?>;
            
            $('#add-shift').on('click', function() {
                const newRow = `
                    <div class="shift-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
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
                $(this).closest('.shift-row').remove();
            });
        });
    
        function toggleTemplateOptions() {
            const type = document.getElementById('template_type').value;
            const typeDesc = document.getElementById('type-description');
            const daysRow = document.getElementById('days-of-week-row');
            const flexibleSettings = document.getElementById('flexible_settings');
            const shiftsSection = document.getElementById('shifts-section');
            const handoffNotificationRow = document.querySelector('.flexible-only-field');

            if (type === 'one_time') {
                if (daysRow) daysRow.style.display = 'none';
                if (flexibleSettings) flexibleSettings.style.display = 'none';
                if (shiftsSection) shiftsSection.style.display = 'block';
                if (handoffNotificationRow) handoffNotificationRow.style.display = 'none';
                typeDesc.textContent = 'Creates a single opportunity on the start date';
            } else if (type === 'flexible_selection') {
                if (daysRow) daysRow.style.display = 'none';
                if (flexibleSettings) flexibleSettings.style.display = 'table-row';
                if (shiftsSection) shiftsSection.style.display = 'none';
                if (handoffNotificationRow) handoffNotificationRow.style.display = 'table-row';
                typeDesc.textContent = 'Volunteers claim entire weeks from an available pool';
            } else {
                if (daysRow) daysRow.style.display = 'table-row';
                if (flexibleSettings) flexibleSettings.style.display = 'none';
                if (shiftsSection) shiftsSection.style.display = 'block';
                if (handoffNotificationRow) handoffNotificationRow.style.display = 'none';
                typeDesc.textContent = 'Choose how opportunities are scheduled';
            }
        }
    
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleTemplateOptions();
        });
        </script>
        
        <style>
            .shift-row {
                background: #f9f9f9;
                padding: 10px;
                border-radius: 4px;
            }
        </style>
        <?php
    }
    
    private static function render_shift_row($index, $shift = null) {
        ?>
        <div class="shift-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
            <input type="time" name="shift_start_time[]" 
                   value="<?php echo $shift ? $shift->shift_start_time : '09:00'; ?>" 
                   required style="width: 120px;">
            <span>to</span>
            <input type="time" name="shift_end_time[]" 
                   value="<?php echo $shift ? $shift->shift_end_time : '11:00'; ?>" 
                   required style="width: 120px;">
            <input type="number" name="shift_spots[]" 
                   value="<?php echo $shift ? $shift->spots_available : 5; ?>" 
                   min="1" required style="width: 80px;" placeholder="Spots">
            <input type="hidden" name="shift_order[]" value="<?php echo $index; ?>">
            <button type="button" class="button remove-shift">Remove</button>
        </div>
        <?php
    }
    
    public static function handle_form_submission() {
    if (!isset($_POST['fs_save_template']) || !check_admin_referer('fs_template_form')) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    
    $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
    
    // Build recurrence pattern
    $days_of_week = isset($_POST['days_of_week']) ? array_map('intval', $_POST['days_of_week']) : array();
    $recurrence_pattern = array('days_of_week' => $days_of_week);

    // Add flexible selection settings if applicable
    if (isset($_POST['template_type']) && $_POST['template_type'] === 'flexible_selection') {
        $recurrence_pattern['flexible_week_pattern'] = sanitize_text_field($_POST['flexible_week_pattern'] ?? 'monday_friday');
        $recurrence_pattern['flexible_slots_per_week'] = intval($_POST['flexible_slots_per_week'] ?? 1);
        $recurrence_pattern['flexible_min_claims'] = intval($_POST['flexible_min_claims'] ?? 1);
        $recurrence_pattern['flexible_max_claims'] = intval($_POST['flexible_max_claims'] ?? 4);
        $recurrence_pattern['flexible_period'] = sanitize_text_field($_POST['flexible_period'] ?? 'quarterly');
    }

    $recurrence_pattern = json_encode($recurrence_pattern);

    $data = array(
        'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
        'description' => wp_kses_post(wp_unslash($_POST['description'] ?? '')),
        'location' => sanitize_text_field(wp_unslash($_POST['location'] ?? '')),
        'template_type' => sanitize_text_field(wp_unslash($_POST['template_type'] ?? '')),
        'recurrence_pattern' => $recurrence_pattern,
        'handoff_notifications' => isset($_POST['handoff_notifications']) ? 1 : 0,
        'start_date' => sanitize_text_field(wp_unslash($_POST['start_date'] ?? '')),
        'end_date' => !empty($_POST['end_date']) ? sanitize_text_field(wp_unslash($_POST['end_date'])) : null,
        'requirements' => wp_kses_post(wp_unslash($_POST['requirements'] ?? '')),
        'required_roles' => !empty($_POST['required_roles']) ? json_encode(array_map('intval', $_POST['required_roles'])) : null,
        'conference' => sanitize_text_field(wp_unslash($_POST['conference'] ?? '')),
        'status' => sanitize_text_field(wp_unslash($_POST['status'] ?? '')),
        'allow_team_signups' => isset($_POST['allow_team_signups']) ? 1 : 0
    );
    
    if ($template_id > 0) {
        // Update existing
        $wpdb->update(
            $wpdb->prefix . 'fs_opportunity_templates',
            $data,
            array('id' => $template_id)
        );
        
        // Delete old shifts
        $wpdb->delete(
            $wpdb->prefix . 'fs_opportunity_shifts',
            array('template_id' => $template_id, 'is_template' => 1)
        );
        
        $redirect = add_query_arg(
            array('page' => 'fs-templates', 'updated' => '1'),
            admin_url('admin.php')
        );
    } else {
        // Create new
        $data['created_date'] = current_time('mysql');
        
        $wpdb->insert(
            $wpdb->prefix . 'fs_opportunity_templates',
            $data
        );
        
        $template_id = $wpdb->insert_id;
        
        $redirect = add_query_arg(
            array('page' => 'fs-templates', 'created' => '1'),
            admin_url('admin.php')
        );
    }
    
    // Save shifts
    if (isset($_POST['shift_start_time']) && is_array($_POST['shift_start_time'])) {
        foreach ($_POST['shift_start_time'] as $index => $start_time) {
            $wpdb->insert(
                $wpdb->prefix . 'fs_opportunity_shifts',
                array(
                    'template_id' => $template_id,
                    'shift_start_time' => sanitize_text_field($start_time),
                    'shift_end_time' => sanitize_text_field($_POST['shift_end_time'][$index] ?? ''),
                    'spots_available' => intval($_POST['shift_spots'][$index] ?? 0),
                    'display_order' => intval($_POST['shift_order'][$index] ?? 0),
                    'is_template' => 1
                )
            );
        }
    }
    
    wp_redirect($redirect);
    exit;
}
    
    public static function handle_delete() {
        if (!isset($_GET['id'])) {
            wp_die('Missing template ID');
        }

        $template_id = intval($_GET['id']);

        // check_admin_referer() will die automatically if nonce is invalid
        check_admin_referer('fs_delete_template_' . $template_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        // Delete template shifts
        $wpdb->delete(
            $wpdb->prefix . 'fs_opportunity_shifts',
            array('template_id' => $template_id, 'is_template' => 1)
        );

        // Delete template
        $wpdb->delete(
            $wpdb->prefix . 'fs_opportunity_templates',
            array('id' => $template_id)
        );

        // Note: We do NOT delete generated opportunities

        $redirect = add_query_arg(
            array('page' => 'fs-templates', 'deleted' => '1'),
            admin_url('admin.php')
        );

        wp_redirect($redirect);
        exit;
    }
    
    public static function handle_generate_now() {
        if (!isset($_GET['template_id'])) {
            wp_die('Missing template ID');
        }
        
        if (!check_admin_referer('fs_generate_now')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $template_id = intval($_GET['template_id']);
        
        global $wpdb;
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_templates WHERE id = %d",
            $template_id
        ));
        
        if (!$template) {
            wp_die('Template not found');
        }
        
        // Generate opportunities
        $target_date = date('Y-m-d', strtotime('+90 days'));
        require_once FRIENDSHYFT_PLUGIN_DIR . 'includes/class-opportunity-templates.php';
        FS_Opportunity_Templates::generate_from_template($template, $target_date);
        
        $redirect = add_query_arg(
            array('page' => 'fs-templates', 'generated' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
}

FS_Admin_Templates::init();
