<?php
if (!defined('ABSPATH')) exit;

class FS_Time_Tracking {
    
    public static function init() {
        add_action('init', array(__CLASS__, 'handle_qr_scan'));
        add_action('init', array(__CLASS__, 'register_api_endpoints'));
    }
    
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Add PIN and QR code columns to volunteers table
        $table_name = $wpdb->prefix . 'fs_volunteers';
        
        // Check if columns exist
        $pin_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'pin'");
        if (empty($pin_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN pin VARCHAR(6) DEFAULT NULL");
            $wpdb->query("ALTER TABLE $table_name ADD KEY pin (pin)");
        }
        
        $qr_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'qr_code'");
        if (empty($qr_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN qr_code VARCHAR(50) DEFAULT NULL");
            $wpdb->query("ALTER TABLE $table_name ADD KEY qr_code (qr_code)");
        }
        
        // Create time records table
        $time_table = $wpdb->prefix . 'fs_time_records';
        
        $sql = "CREATE TABLE IF NOT EXISTS $time_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) NOT NULL,
            opportunity_id bigint(20) NOT NULL,
            shift_id bigint(20) DEFAULT NULL,
            check_in datetime NOT NULL,
            check_out datetime DEFAULT NULL,
            total_hours decimal(5,2) DEFAULT NULL,
            notes text,
            created_by varchar(100),
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY opportunity_id (opportunity_id),
            KEY check_in (check_in)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Register API endpoints for kiosk
     */
    public static function register_api_endpoints() {
        add_rewrite_rule('^kiosk-api/verify-pin/?$', 'index.php?kiosk_api=verify_pin', 'top');
        add_rewrite_rule('^kiosk-api/check-in/?$', 'index.php?kiosk_api=check_in', 'top');
        add_rewrite_rule('^kiosk-api/check-out/?$', 'index.php?kiosk_api=check_out', 'top');
        
        add_filter('query_vars', function($vars) {
            $vars[] = 'kiosk_api';
            return $vars;
        });
        
        add_action('template_redirect', function() {
            $api = get_query_var('kiosk_api');
            
            if ($api === 'verify_pin') {
                FS_Kiosk::api_verify_pin();
                exit;
            } elseif ($api === 'check_in') {
                FS_Kiosk::api_check_in();
                exit;
            } elseif ($api === 'check_out') {
                FS_Kiosk::api_check_out();
                exit;
            }
        });
    }
    
    /**
     * Generate a unique 4-digit PIN for a volunteer
     */
    public static function generate_pin($volunteer_id) {
        global $wpdb;
        
        // Generate random 4-digit PIN
        do {
            $pin = str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
            
            // Check if PIN already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE pin = %s AND id != %d",
                $pin,
                $volunteer_id
            ));
        } while ($exists);
        
        // Update volunteer with PIN
        $wpdb->update(
            $wpdb->prefix . 'fs_volunteers',
            array('pin' => $pin),
            array('id' => $volunteer_id)
        );
        
        return $pin;
    }
    
    /**
     * Generate a unique QR code for a volunteer
     */
    public static function generate_qr_code($volunteer_id) {
        global $wpdb;
        
        // Generate random alphanumeric code
        do {
            $qr_code = 'VOL-' . strtoupper(wp_generate_password(12, false));
            
            // Check if code already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE qr_code = %s AND id != %d",
                $qr_code,
                $volunteer_id
            ));
        } while ($exists);
        
        // Update volunteer with QR code
        $wpdb->update(
            $wpdb->prefix . 'fs_volunteers',
            array('qr_code' => $qr_code),
            array('id' => $volunteer_id)
        );
        
        return $qr_code;
    }
    
    /**
     * Get volunteer by PIN
     */
    public static function get_volunteer_by_pin($pin) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE pin = %s",
            $pin
        ));
    }
    
    /**
     * Get volunteer by QR code
     */
    public static function get_volunteer_by_qr($qr_code) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE qr_code = %s",
            $qr_code
        ));
    }
    
    /**
     * Get current opportunities for a volunteer
     * Returns opportunities happening today that volunteer is signed up for
     */
    public static function get_current_opportunities($volunteer_id = null) {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        
        if ($volunteer_id) {
            // Get opportunities the volunteer is signed up for TODAY
            $opportunities = $wpdb->get_results($wpdb->prepare(
                "SELECT o.*, s.status as signup_status, s.shift_id,
                        sh.shift_start_time, sh.shift_end_time
                FROM {$wpdb->prefix}fs_opportunities o
                JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id
                LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON s.shift_id = sh.id
                WHERE s.volunteer_id = %d
                AND s.status IN ('confirmed', 'pending')
                AND o.event_date = %s
                AND o.status = 'Open'
                ORDER BY sh.shift_start_time ASC, o.title ASC",
                $volunteer_id,
                $today
            ));
            
            // If no signed-up opportunities, get all current ones for today
            if (empty($opportunities)) {
                $opportunities = $wpdb->get_results($wpdb->prepare(
                    "SELECT o.* FROM {$wpdb->prefix}fs_opportunities o
                    WHERE o.event_date = %s
                    AND o.status = 'Open'
                    ORDER BY o.title ASC
                    LIMIT 10",
                    $today
                ));
            }
        } else {
            // Get all opportunities for today
            $opportunities = $wpdb->get_results($wpdb->prepare(
                "SELECT o.* FROM {$wpdb->prefix}fs_opportunities o
                WHERE o.event_date = %s
                AND o.status = 'Open'
                ORDER BY o.title ASC
                LIMIT 10",
                $today
            ));
        }
        
        return $opportunities;
    }
    
    /**
     * Check if volunteer is currently checked in
     */
    public static function get_active_checkin($volunteer_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_time_records
            WHERE volunteer_id = %d
            AND check_out IS NULL
            ORDER BY check_in DESC
            LIMIT 1",
            $volunteer_id
        ));
    }
    
    /**
     * Check in a volunteer
     */
    public static function check_in($volunteer_id, $opportunity_id, $shift_id = null, $notes = '') {
        global $wpdb;

        // Check if already checked in
        $active = self::get_active_checkin($volunteer_id);
        if ($active) {
            return array(
                'success' => false,
                'message' => 'Already checked in. Please check out first.'
            );
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_time_records',
            array(
                'volunteer_id' => $volunteer_id,
                'opportunity_id' => $opportunity_id,
                'shift_id' => $shift_id,
                'check_in' => current_time('mysql'),
                'notes' => $notes,
                'created_by' => 'kiosk',
                'created_at' => current_time('mysql')
            )
        );

        // Log check-in to audit log
        if ($result && class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('volunteer_checkin', 'time_record', $wpdb->insert_id, array(
                'volunteer_id' => $volunteer_id,
                'opportunity_id' => $opportunity_id,
                'check_in_time' => current_time('mysql'),
                'method' => 'kiosk'
            ));
        }

        if ($result) {
            return array(
                'success' => true,
                'record_id' => $wpdb->insert_id,
                'message' => 'Checked in successfully!'
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to check in. Please try again.'
            );
        }
    }

    /**
     * Check in a volunteer with people count (for team check-ins via kiosk)
     */
    public static function check_in_with_count($volunteer_id, $opportunity_id, $people_count, $shift_id = null, $notes = '') {
        global $wpdb;

        // Check if already checked in
        $active = self::get_active_checkin($volunteer_id);
        if ($active) {
            return array(
                'success' => false,
                'message' => 'Already checked in. Please check out first.'
            );
        }

        // Add people_count to notes for tracking
        $full_notes = $notes ? $notes . "\n" : '';
        $full_notes .= "Team check-in: {$people_count} people";

        $result = $wpdb->insert(
            $wpdb->prefix . 'fs_time_records',
            array(
                'volunteer_id' => $volunteer_id,
                'opportunity_id' => $opportunity_id,
                'shift_id' => $shift_id,
                'check_in' => current_time('mysql'),
                'notes' => $full_notes,
                'created_by' => 'kiosk',
                'created_at' => current_time('mysql')
            )
        );

        // Log team check-in to audit log
        if ($result && class_exists('FS_Audit_Log')) {
            FS_Audit_Log::log('team_checkin', 'time_record', $wpdb->insert_id, array(
                'volunteer_id' => $volunteer_id,
                'opportunity_id' => $opportunity_id,
                'people_count' => $people_count,
                'check_in_time' => current_time('mysql'),
                'method' => 'kiosk'
            ));
        }

        if ($result) {
            return array(
                'success' => true,
                'record_id' => $wpdb->insert_id,
                'people_count' => $people_count,
                'message' => "Team checked in successfully! {$people_count} people"
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to check in. Please try again.'
            );
        }
    }
    
    /**
     * Check out a volunteer
     */
    public static function check_out($volunteer_id) {
        global $wpdb;
        
        // Get active check-in
        $active = self::get_active_checkin($volunteer_id);
        
        if (!$active) {
            return array(
                'success' => false,
                'message' => 'No active check-in found.'
            );
        }
        
        $check_out = current_time('mysql');

        // Calculate total hours
        $check_in_time = strtotime($active->check_in);
        $check_out_time = strtotime($check_out);
        $hours_per_person = ($check_out_time - $check_in_time) / 3600; // Convert seconds to hours
        $hours_per_person = round($hours_per_person, 2);

        // Check if this is a team check-in by looking at notes
        $people_count = 1;
        if ($active->notes && preg_match('/Team check-in: (\d+) people/', $active->notes, $matches)) {
            $people_count = intval($matches[1]);
        }

        // Calculate total volunteer hours (people × hours)
        $total_hours = $hours_per_person * $people_count;
        $total_hours = round($total_hours, 2);

        $result = $wpdb->update(
            $wpdb->prefix . 'fs_time_records',
            array(
                'check_out' => $check_out,
                'total_hours' => $total_hours
            ),
            array('id' => $active->id)
        );

        if ($result !== false) {
            // Log check-out to audit log
            if (class_exists('FS_Audit_Log')) {
                $log_type = $people_count > 1 ? 'team_checkout' : 'volunteer_checkout';
                $log_data = array(
                    'volunteer_id' => $volunteer_id,
                    'opportunity_id' => $active->opportunity_id,
                    'check_in_time' => $active->check_in,
                    'check_out_time' => $check_out,
                    'total_hours' => $total_hours,
                    'method' => 'kiosk'
                );

                if ($people_count > 1) {
                    $log_data['people_count'] = $people_count;
                    $log_data['hours_per_person'] = $hours_per_person;
                }

                FS_Audit_Log::log($log_type, 'time_record', $active->id, $log_data);
            }

            // Check and award badges after successful check-out
            if (class_exists('FS_Badges')) {
                FS_Badges::check_volunteer_badges($volunteer_id);
            }

            $message = 'Checked out successfully!';
            if ($people_count > 1) {
                $message = "Team checked out! {$people_count} people × {$hours_per_person} hours = {$total_hours} total hours";
            }

            return array(
                'success' => true,
                'total_hours' => $total_hours,
                'people_count' => $people_count,
                'hours_per_person' => $hours_per_person,
                'message' => $message
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to check out. Please try again.'
            );
        }
    }
    
    /**
     * Get volunteer's total hours
     */
    public static function get_volunteer_hours($volunteer_id, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $where = "volunteer_id = %d AND check_out IS NOT NULL";
        $params = array($volunteer_id);
        
        if ($start_date) {
            $where .= " AND check_in >= %s";
            $params[] = $start_date;
        }
        
        if ($end_date) {
            $where .= " AND check_in <= %s";
            $params[] = $end_date;
        }
        
        $hours = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_hours) FROM {$wpdb->prefix}fs_time_records WHERE $where",
            $params
        ));
        
        return $hours ? floatval($hours) : 0;
    }
    
    /**
     * Get volunteer's time records
     */
    public static function get_volunteer_records($volunteer_id, $limit = 20) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, o.title as opportunity_title, o.event_date,
                    sh.shift_start_time, sh.shift_end_time
             FROM {$wpdb->prefix}fs_time_records t
             JOIN {$wpdb->prefix}fs_opportunities o ON t.opportunity_id = o.id
             LEFT JOIN {$wpdb->prefix}fs_opportunity_shifts sh ON t.shift_id = sh.id
             WHERE t.volunteer_id = %d
             ORDER BY t.check_in DESC
             LIMIT %d",
            $volunteer_id,
            $limit
        ));
    }
    
    /**
     * Handle QR code scans via URL
     */
    public static function handle_qr_scan() {
        if (!isset($_GET['fs_qr_scan'])) {
            return;
        }
        
        $qr_code = sanitize_text_field($_GET['fs_qr_scan']);
        
        // Redirect to kiosk with QR code
        $kiosk_url = add_query_arg('qr', $qr_code, home_url('/volunteer-kiosk/'));
        wp_redirect($kiosk_url);
        exit;
    }
}

FS_Time_Tracking::init();
