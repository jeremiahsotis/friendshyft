<?php
if (!defined('ABSPATH')) exit;

class FS_Opportunity_Templates {
    
    public static function init() {
        // Schedule cron job
        add_action('fs_generate_opportunities_cron', array(__CLASS__, 'generate_opportunities'));
        
        // Register cron schedule
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
    }
    
    /**
     * Add custom cron schedule for weekly generation
     */
    public static function add_cron_schedule($schedules) {
        $schedules['weekly'] = array(
            'interval' => 604800, // 7 days in seconds
            'display'  => __('Once Weekly', 'friendshyft')
        );
        return $schedules;
    }
    
    /**
     * Schedule the cron job (called on plugin activation)
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled('fs_generate_opportunities_cron')) {
            wp_schedule_event(time(), 'weekly', 'fs_generate_opportunities_cron');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Opportunity generation cron scheduled');
            }
        }
    }
    
    /**
     * Unschedule the cron job (called on plugin deactivation)
     */
    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled('fs_generate_opportunities_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fs_generate_opportunities_cron');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Opportunity generation cron unscheduled');
            }
        }
    }
    
    /**
     * Create/update template-related tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Opportunity Templates table
        $table_templates = $wpdb->prefix . 'fs_opportunity_templates';
        $sql_templates = "CREATE TABLE IF NOT EXISTS $table_templates (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text,
            location varchar(255),
            template_type varchar(50) NOT NULL,
            recurrence_pattern text,
            handoff_notifications tinyint(1) DEFAULT 0,
            start_date date NOT NULL,
            end_date date DEFAULT NULL,
            requirements text,
            required_roles text DEFAULT NULL,
            conference varchar(100),
            status varchar(50) DEFAULT 'Active',
            last_generation_date date DEFAULT NULL,
            created_date datetime NOT NULL,
            monday_id bigint(20) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY template_type (template_type),
            KEY start_date (start_date),
            KEY monday_id (monday_id)
        ) $charset_collate;";
        
        // Opportunity Shifts table
        $table_shifts = $wpdb->prefix . 'fs_opportunity_shifts';
        $sql_shifts = "CREATE TABLE IF NOT EXISTS $table_shifts (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            opportunity_id bigint(20) DEFAULT NULL,
            template_id bigint(20) DEFAULT NULL,
            shift_start_time time NOT NULL,
            shift_end_time time NOT NULL,
            spots_available int(11) NOT NULL DEFAULT 1,
            spots_filled int(11) NOT NULL DEFAULT 0,
            display_order int(11) NOT NULL DEFAULT 0,
            is_template tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY opportunity_id (opportunity_id),
            KEY template_id (template_id),
            KEY is_template (is_template)
        ) $charset_collate;";
        
        // Holidays table (NEW)
        $table_holidays = $wpdb->prefix . 'fs_holidays';
        $sql_holidays = "CREATE TABLE IF NOT EXISTS $table_holidays (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            holiday_date date NOT NULL,
            title varchar(255) NOT NULL,
            holiday_type varchar(50) NOT NULL DEFAULT 'full_day_closed',
            adjusted_open_time time DEFAULT NULL,
            adjusted_close_time time DEFAULT NULL,
            notes text,
            created_date datetime NOT NULL,
            PRIMARY KEY (id),
            KEY holiday_date (holiday_date),
            KEY holiday_type (holiday_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_templates);
        dbDelta($sql_shifts);
        dbDelta($sql_holidays);

        // Update fs_opportunity_templates table structure
        self::update_templates_table();

        // Update fs_opportunities table structure
        self::update_opportunities_table();

        // Update fs_signups table to add shift_id
        self::update_signups_table();
    }
    
    /**
     * Update templates table for new structure
     */
    private static function update_templates_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'fs_opportunity_templates';

        // Add allow_team_signups column if it doesn't exist
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'allow_team_signups'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN allow_team_signups tinyint(1) DEFAULT 0 AFTER status");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('FriendShyft: Added allow_team_signups column to opportunity_templates table');
            }
        }
    }

    /**
     * Update opportunities table for new structure
     */
    private static function update_opportunities_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'fs_opportunities';
        
        // Add template_id if it doesn't exist
        $template_id_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'template_id'");
        if (empty($template_id_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN template_id bigint(20) DEFAULT NULL AFTER id");
            $wpdb->query("ALTER TABLE $table ADD KEY template_id (template_id)");
        }

        // Add required_roles column to opportunities if it doesn't exist
        $required_roles_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'required_roles'");
        if (empty($required_roles_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN required_roles TEXT DEFAULT NULL AFTER requirements");
        }
        
        // Add event_date if it doesn't exist
        $event_date_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'event_date'");
        if (empty($event_date_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN event_date date DEFAULT NULL AFTER location");
            $wpdb->query("ALTER TABLE $table ADD KEY event_date (event_date)");
            
            // Migrate existing datetime_start to event_date
            $wpdb->query("UPDATE $table SET event_date = DATE(datetime_start) WHERE event_date IS NULL");
        }
        
        // Remove spots_available and spots_filled from opportunities (now in shifts)
        // We'll keep them for now for migration purposes, mark as deprecated
    }
    
    /**
     * Update signups table to add shift_id
     */
    private static function update_signups_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'fs_signups';
        
        $shift_id_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'shift_id'");
        if (empty($shift_id_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN shift_id bigint(20) DEFAULT NULL AFTER opportunity_id");
            $wpdb->query("ALTER TABLE $table ADD KEY shift_id (shift_id)");
        }
    }
    
    /**
     * Generate opportunities from templates
     * Called by cron job
     */
    public static function generate_opportunities() {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        $target_date = date('Y-m-d', strtotime('+90 days'));
        
        // Get all active templates
        $templates = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}fs_opportunity_templates 
            WHERE status = 'Active'
            AND start_date <= '$target_date'
            AND (end_date IS NULL OR end_date >= '$today')
        ");
        
        foreach ($templates as $template) {
            self::generate_from_template($template, $target_date);
        }
    }
    
    /**
     * Generate opportunities from a specific template
     */
    public static function generate_from_template($template, $target_date) {
        global $wpdb;
        
        // Handle flexible selection templates differently
        if ($template->template_type === 'flexible_selection') {
            self::generate_flexible_selection($template, $target_date);
            return;
        }
        
        // EXISTING DAILY/WEEKLY GENERATION CODE BELOW
        // Determine starting point
        $start = $template->last_generation_date 
            ? date('Y-m-d', strtotime($template->last_generation_date . ' +1 day'))
            : max($template->start_date, current_time('Y-m-d'));
        
        // Parse recurrence pattern
        $pattern = json_decode($template->recurrence_pattern, true);
        $days_of_week = $pattern['days_of_week'] ?? [1,2,3,4,5]; // Default M-F
        
        // Get template shifts
        $template_shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts 
            WHERE template_id = %d AND is_template = 1 
            ORDER BY display_order ASC",
            $template->id
        ));
        
        if (empty($template_shifts)) {
            return; // No shifts defined
        }
        
        // Get all holidays in the date range for quick lookup
        $holidays = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_holidays 
            WHERE holiday_date BETWEEN '$start' AND '$target_date'",
            OBJECT_K
        );
        
        // Generate opportunities
        $current_date = strtotime($start);
        $end_date = strtotime($target_date);
        if ($template->end_date) {
            $end_date = min($end_date, strtotime($template->end_date));
        }
        
        while ($current_date <= $end_date) {
            $day_of_week = date('N', $current_date); // 1=Monday, 7=Sunday
            $date_string = date('Y-m-d', $current_date);
            
            // Check if this day matches the pattern
            if (in_array($day_of_week, $days_of_week)) {
                // Check if this is a holiday
                $holiday = isset($holidays[$date_string]) ? $holidays[$date_string] : null;
                
                // Skip full-day closures
                if ($holiday && $holiday->holiday_type === 'full_day_closed') {
                    $current_date = strtotime('+1 day', $current_date);
                    continue;
                }
                
                // Check if opportunity already exists for this date
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_opportunities 
                    WHERE template_id = %d AND event_date = %s",
                    $template->id,
                    $date_string
                ));
                
                if (!$exists) {
                    // Filter shifts based on holiday adjusted hours
                    $valid_shifts = $template_shifts;
                    
                    if ($holiday && $holiday->holiday_type === 'early_close' && $holiday->adjusted_close_time) {
                        // Filter out shifts that end after the early close time
                        $valid_shifts = array_filter($template_shifts, function($shift) use ($holiday) {
                            return strtotime($shift->shift_end_time) <= strtotime($holiday->adjusted_close_time);
                        });
                    } elseif ($holiday && $holiday->holiday_type === 'late_open' && $holiday->adjusted_open_time) {
                        // Filter out shifts that start before the late open time
                        $valid_shifts = array_filter($template_shifts, function($shift) use ($holiday) {
                            return strtotime($shift->shift_start_time) >= strtotime($holiday->adjusted_open_time);
                        });
                    }
                    
                    // Only create opportunity if there are valid shifts
                    if (!empty($valid_shifts)) {
                        // Create opportunity
                        $wpdb->insert(
                            $wpdb->prefix . 'fs_opportunities',
                            array(
                                'template_id' => $template->id,
                                'title' => $template->title,
                                'description' => $template->description,
                                'location' => $template->location,
                                'event_date' => $date_string,
                                'datetime_start' => $date_string . ' ' . reset($valid_shifts)->shift_start_time,
                                'datetime_end' => $date_string . ' ' . end($valid_shifts)->shift_end_time,
                                'spots_available' => array_sum(array_column($valid_shifts, 'spots_available')),
                                'requirements' => $template->requirements,
                                'required_roles' => $template->required_roles,
                                'conference' => $template->conference,
                                'status' => 'Open',
                                'last_sync' => current_time('mysql')
                            )
                        );
                        
                        $opportunity_id = $wpdb->insert_id;

                        // Log opportunity creation from template
                        FS_Audit_Log::log('opportunity_created', 'opportunity', $opportunity_id, array(
                            'title' => $template->title,
                            'event_date' => $date_string,
                            'source' => 'template',
                            'template_id' => $template->id
                        ));

                        // Create shifts for this opportunity
                        foreach ($valid_shifts as $template_shift) {
                            $wpdb->insert(
                                $wpdb->prefix . 'fs_opportunity_shifts',
                                array(
                                    'opportunity_id' => $opportunity_id,
                                    'template_id' => $template->id,
                                    'shift_start_time' => $template_shift->shift_start_time,
                                    'shift_end_time' => $template_shift->shift_end_time,
                                    'spots_available' => $template_shift->spots_available,
                                    'spots_filled' => 0,
                                    'display_order' => $template_shift->display_order,
                                    'is_template' => 0
                                )
                            );
                        }
                    }
                }
            }
            
            $current_date = strtotime('+1 day', $current_date);
        }
        
        // Update last generation date
        $wpdb->update(
            $wpdb->prefix . 'fs_opportunity_templates',
            array('last_generation_date' => $target_date),
            array('id' => $template->id)
        );
    }

    public static function generate_flexible_selection($template, $target_date) {
        global $wpdb;

        $pattern = json_decode($template->recurrence_pattern, true);
        $week_pattern = $pattern['flexible_week_pattern'] ?? 'monday_friday';
        $slots_per_week = $pattern['flexible_slots_per_week'] ?? 1;

        // Determine starting point
        $start = $template->last_generation_date
            ? date('Y-m-d', strtotime($template->last_generation_date . ' +1 week'))
            : max($template->start_date, current_time('Y-m-d'));

        // Start from the Monday of the starting week
        $current_date = strtotime('monday this week', strtotime($start));

        // Don't start before the template's start_date
        $template_start = strtotime($template->start_date);
        if ($current_date < $template_start) {
            $current_date = $template_start;
            // Adjust to the next Monday if needed
            if (date('N', $current_date) != 1) {
                $current_date = strtotime('next monday', $current_date);
            }
        }

        // For flexible selection, always generate through the template end_date, not just target_date
        $end_date = $template->end_date ? strtotime($template->end_date) : strtotime($target_date);
        
        // Get holidays for checking
        $holidays = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_holidays 
            WHERE holiday_date BETWEEN '" . date('Y-m-d', $current_date) . "' AND '$target_date'",
            OBJECT_K
        );
        
        // Generate week blocks
        while ($current_date <= $end_date) {
            $week_start = date('Y-m-d', $current_date);
            
            // Determine week end based on pattern
            if ($week_pattern === 'monday_friday') {
                $week_end = date('Y-m-d', strtotime('+4 days', $current_date)); // Monday + 4 = Friday
            } else {
                $week_end = date('Y-m-d', strtotime('+6 days', $current_date)); // Full week
            }
            
            // Check if this week is already generated
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_opportunities 
                WHERE template_id = %d AND event_date = %s",
                $template->id,
                $week_start
            ));
            
            if (!$exists) {
                // Check if any day in this week is a full-day holiday
                $has_full_closure = false;
                $current_check = strtotime($week_start);
                $end_check = strtotime($week_end);
                
                while ($current_check <= $end_check) {
                    $check_date = date('Y-m-d', $current_check);
                    if (isset($holidays[$check_date]) && $holidays[$check_date]->holiday_type === 'full_day_closed') {
                        $has_full_closure = true;
                        break;
                    }
                    $current_check = strtotime('+1 day', $current_check);
                }
                
                // Only create if no full closure during the week
                if (!$has_full_closure) {
                    // Format week display
                    $week_display = date('M j', strtotime($week_start)) . ' - ' . date('M j, Y', strtotime($week_end));
                    
                    // Create weekly opportunity
                    $wpdb->insert(
                        $wpdb->prefix . 'fs_opportunities',
                        array(
                            'template_id' => $template->id,
                            'title' => $template->title . ' - Week of ' . $week_display,
                            'description' => $template->description,
                            'location' => $template->location,
                            'event_date' => $week_start,
                            'datetime_start' => $week_start . ' 00:00:00',
                            'datetime_end' => $week_end . ' 23:59:59',
                            'spots_available' => $slots_per_week,
                            'requirements' => $template->requirements,
                            'conference' => $template->conference,
                            'status' => 'Open',
                            'last_sync' => current_time('mysql')
                        )
                    );

                    $opportunity_id = $wpdb->insert_id;

                    // Log flexible pool opportunity creation
                    FS_Audit_Log::log('opportunity_created', 'opportunity', $opportunity_id, array(
                        'title' => $template->title . ' - Week of ' . $week_display,
                        'event_date' => $week_start,
                        'source' => 'template_flexible',
                        'template_id' => $template->id
                    ));
                }
            }
            
            // Move to next week
            $current_date = strtotime('+1 week', $current_date);
        }
        
        // Update last generation date
        $wpdb->update(
            $wpdb->prefix . 'fs_opportunity_templates',
            array('last_generation_date' => $target_date),
            array('id' => $template->id)
        );
    }
}

FS_Opportunity_Templates::init();
