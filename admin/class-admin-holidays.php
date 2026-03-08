<?php
if (!defined('ABSPATH')) exit;

class FS_Admin_Holidays {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_pages'), 20);
        add_action('admin_init', array(__CLASS__, 'handle_form_submission'));
        add_action('admin_init', array(__CLASS__, 'handle_delete'));
    }
    
    public static function add_menu_pages() {
        // List holidays
        add_submenu_page(
            'friendshyft',
            'Holiday Calendar',
            'Holidays',
            'manage_options',
            'fs-holidays',
            array(__CLASS__, 'list_page')
        );
        
        // Add/Edit holiday (hidden from menu)
        add_submenu_page(
            null,
            'Add Holiday',
            'Add Holiday',
            'manage_options',
            'fs-add-holiday',
            array(__CLASS__, 'edit_page')
        );
        
        add_submenu_page(
            null,
            'Edit Holiday',
            'Edit Holiday',
            'manage_options',
            'fs-edit-holiday',
            array(__CLASS__, 'edit_page')
        );
    }
    
    public static function list_page() {
        global $wpdb;
        
        // Handle messages
        if (isset($_GET['created'])) {
            echo '<div class="notice notice-success"><p>Holiday added successfully!</p></div>';
        }
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Holiday updated successfully!</p></div>';
        }
        if (isset($_GET['deleted'])) {
            echo '<div class="notice notice-success"><p>Holiday deleted successfully!</p></div>';
        }
        
        // Get current year and next year
        $current_year = date('Y');
        $year_filter = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
        
        $holidays = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_holidays 
            WHERE YEAR(holiday_date) = %d
            ORDER BY holiday_date ASC",
            $year_filter
        ));
        
        ?>
        <div class="wrap">
            <h1>
                Holiday Calendar
                <a href="<?php echo admin_url('admin.php?page=fs-add-holiday'); ?>" class="page-title-action">Add Holiday</a>
            </h1>
            
            <p class="description">
                Holidays automatically block opportunity generation on specified dates. You can set full-day closures or adjusted hours.
            </p>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <label for="year-filter" class="screen-reader-text">Filter by year</label>
                    <select name="year" id="year-filter" onchange="window.location.href='<?php echo admin_url('admin.php?page=fs-holidays&year='); ?>' + this.value;">
                        <?php for ($y = $current_year - 1; $y <= $current_year + 5; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php selected($year_filter, $y); ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <?php if (empty($holidays)): ?>
                <div class="notice notice-info">
                    <p>No holidays defined for <?php echo $year_filter; ?>. <a href="<?php echo admin_url('admin.php?page=fs-add-holiday'); ?>">Add your first holiday</a>!</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Date</th>
                            <th style="width: 25%;">Holiday Name</th>
                            <th style="width: 15%;">Type</th>
                            <th style="width: 20%;">Adjusted Hours</th>
                            <th>Notes</th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holidays as $holiday): ?>
                            <tr>
                                <td><?php echo date('D, M j, Y', strtotime($holiday->holiday_date)); ?></td>
                                <td><strong><?php echo esc_html($holiday->title); ?></strong></td>
                                <td>
                                    <?php
                                    $type_labels = array(
                                        'full_day_closed' => '🚫 Full Day Closed',
                                        'early_close' => '⏰ Early Close',
                                        'late_open' => '⏰ Late Open'
                                    );
                                    echo $type_labels[$holiday->holiday_type] ?? $holiday->holiday_type;
                                    ?>
                                </td>
                                <td>
                                    <?php if ($holiday->holiday_type === 'early_close' && $holiday->adjusted_close_time): ?>
                                        Close at <?php echo date('g:i A', strtotime($holiday->adjusted_close_time)); ?>
                                    <?php elseif ($holiday->holiday_type === 'late_open' && $holiday->adjusted_open_time): ?>
                                        Open at <?php echo date('g:i A', strtotime($holiday->adjusted_open_time)); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $holiday->notes ? esc_html($holiday->notes) : '—'; ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-edit-holiday&id=' . $holiday->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=fs-holidays&action=delete&id=' . $holiday->id), 'fs_delete_holiday_' . $holiday->id); ?>" 
                                       onclick="return confirm('Delete this holiday?');" 
                                       style="color: #b32d2e;">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public static function edit_page() {
        global $wpdb;
        
        $holiday_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $is_new = ($holiday_id === 0);
        
        $holiday = null;
        if (!$is_new) {
            $holiday = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_holidays WHERE id = %d",
                $holiday_id
            ));
            
            if (!$holiday) {
                wp_die('Holiday not found');
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo $is_new ? 'Add Holiday' : 'Edit Holiday'; ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin.php'); ?>">
                <?php wp_nonce_field('fs_holiday_form'); ?>
                <input type="hidden" name="action" value="fs_save_holiday">
                <input type="hidden" name="page" value="fs-holidays">
                <?php if (!$is_new): ?>
                    <input type="hidden" name="holiday_id" value="<?php echo $holiday_id; ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="title">Holiday Name *</label></th>
                        <td>
                            <input type="text" id="title" name="title" 
                                   value="<?php echo $holiday ? esc_attr($holiday->title) : ''; ?>" 
                                   class="regular-text" required>
                            <p class="description">e.g., "Christmas Day", "Thanksgiving", "Staff Training Day"</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="holiday_date">Date *</label></th>
                        <td>
                            <input type="date" id="holiday_date" name="holiday_date" 
                                   value="<?php echo $holiday ? $holiday->holiday_date : ''; ?>" 
                                   required>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="holiday_type">Type *</label></th>
                        <td>
                            <select id="holiday_type" name="holiday_type" required onchange="toggleAdjustedHours()">
                                <option value="full_day_closed" <?php echo $holiday && $holiday->holiday_type === 'full_day_closed' ? 'selected' : ''; ?>>
                                    Full Day Closed
                                </option>
                                <option value="early_close" <?php echo $holiday && $holiday->holiday_type === 'early_close' ? 'selected' : ''; ?>>
                                    Early Close
                                </option>
                                <option value="late_open" <?php echo $holiday && $holiday->holiday_type === 'late_open' ? 'selected' : ''; ?>>
                                    Late Open
                                </option>
                            </select>
                            <p class="description">Choose how this holiday affects operations</p>
                        </td>
                    </tr>
                    
                    <tr id="adjusted_open_row" style="display: none;">
                        <th><label for="adjusted_open_time">Opens At</label></th>
                        <td>
                            <input type="time" id="adjusted_open_time" name="adjusted_open_time" 
                                   value="<?php echo $holiday ? $holiday->adjusted_open_time : ''; ?>">
                            <p class="description">What time does the facility open on this day?</p>
                        </td>
                    </tr>
                    
                    <tr id="adjusted_close_row" style="display: none;">
                        <th><label for="adjusted_close_time">Closes At</label></th>
                        <td>
                            <input type="time" id="adjusted_close_time" name="adjusted_close_time" 
                                   value="<?php echo $holiday ? $holiday->adjusted_close_time : ''; ?>">
                            <p class="description">What time does the facility close on this day?</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="notes">Notes</label></th>
                        <td>
                            <textarea id="notes" name="notes" rows="3" class="large-text"><?php echo $holiday ? esc_textarea($holiday->notes) : ''; ?></textarea>
                            <p class="description">Optional notes about this holiday</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="fs_save_holiday" class="button button-primary" value="<?php echo $is_new ? 'Add Holiday' : 'Update Holiday'; ?>">
                    <a href="<?php echo admin_url('admin.php?page=fs-holidays'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        
        <script>
        function toggleAdjustedHours() {
            const type = document.getElementById('holiday_type').value;
            const openRow = document.getElementById('adjusted_open_row');
            const closeRow = document.getElementById('adjusted_close_row');
            
            if (type === 'late_open') {
                openRow.style.display = 'table-row';
                closeRow.style.display = 'none';
            } else if (type === 'early_close') {
                openRow.style.display = 'none';
                closeRow.style.display = 'table-row';
            } else {
                openRow.style.display = 'none';
                closeRow.style.display = 'none';
            }
        }
        
        // Initialize on page load
        toggleAdjustedHours();
        </script>
        <?php
    }
    
    public static function handle_form_submission() {
        if (!isset($_POST['fs_save_holiday']) || !check_admin_referer('fs_holiday_form')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        $holiday_id = isset($_POST['holiday_id']) ? intval($_POST['holiday_id']) : 0;
        
        $data = array(
            'title' => sanitize_text_field(wp_unslash($_POST['title'])),
            'holiday_date' => sanitize_text_field(wp_unslash($_POST['holiday_date'])),
            'holiday_type' => sanitize_text_field(wp_unslash($_POST['holiday_type'])),
            'adjusted_open_time' => !empty($_POST['adjusted_open_time']) ? sanitize_text_field(wp_unslash($_POST['adjusted_open_time'])) : null,
            'adjusted_close_time' => !empty($_POST['adjusted_close_time']) ? sanitize_text_field(wp_unslash($_POST['adjusted_close_time'])) : null,
            'notes' => sanitize_textarea_field(wp_unslash($_POST['notes']))
        );
        
        if ($holiday_id > 0) {
            // Update existing
            $wpdb->update(
                $wpdb->prefix . 'fs_holidays',
                $data,
                array('id' => $holiday_id)
            );
            
            $redirect = add_query_arg(
                array('page' => 'fs-holidays', 'updated' => '1'),
                admin_url('admin.php')
            );
        } else {
            // Create new
            $data['created_date'] = current_time('mysql');
            
            $wpdb->insert(
                $wpdb->prefix . 'fs_holidays',
                $data
            );
            
            $redirect = add_query_arg(
                array('page' => 'fs-holidays', 'created' => '1'),
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
        
        $holiday_id = intval($_GET['id']);
        
        if (!check_admin_referer('fs_delete_holiday_' . $holiday_id)) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        $wpdb->delete(
            $wpdb->prefix . 'fs_holidays',
            array('id' => $holiday_id)
        );
        
        $redirect = add_query_arg(
            array('page' => 'fs-holidays', 'deleted' => '1'),
            admin_url('admin.php')
        );
        
        wp_redirect($redirect);
        exit;
    }
}

FS_Admin_Holidays::init();