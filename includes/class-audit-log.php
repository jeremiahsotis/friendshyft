<?php
if (!defined('ABSPATH')) exit;

/**
 * Audit Log System
 * Tracks all significant actions in the system for accountability and debugging
 */
class FS_Audit_Log {

    /**
     * Create audit log table
     */
    public static function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_name = $wpdb->prefix . 'fs_audit_log';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            action_type varchar(50) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id bigint(20) NOT NULL,
            user_id bigint(20) DEFAULT NULL,
            user_name varchar(255) DEFAULT NULL,
            user_role varchar(50) DEFAULT NULL,
            details text,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY action_type (action_type),
            KEY entity_type (entity_type),
            KEY entity_id (entity_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log an action
     *
     * @param string $action_type Type of action (e.g., 'signup_created', 'opportunity_updated')
     * @param string $entity_type Type of entity (e.g., 'volunteer', 'opportunity', 'signup')
     * @param int $entity_id ID of the entity
     * @param array $details Additional details (will be JSON encoded)
     */
    public static function log($action_type, $entity_type, $entity_id, $details = array()) {
        global $wpdb;

        // Get current user info
        $user_id = get_current_user_id();
        $user_name = '';
        $user_role = '';

        if ($user_id) {
            $user = get_userdata($user_id);
            $user_name = $user->display_name;
            $user_role = implode(', ', $user->roles);
        } else {
            $user_name = 'System/Anonymous';
            $user_role = 'none';
        }

        // Get IP and user agent
        $ip_address = self::get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

        // Insert log entry
        $wpdb->insert(
            "{$wpdb->prefix}fs_audit_log",
            array(
                'action_type' => $action_type,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'user_id' => $user_id ?: null,
                'user_name' => $user_name,
                'user_role' => $user_role,
                'details' => json_encode($details),
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft Audit: {$action_type} - {$entity_type}:{$entity_id} by {$user_name}");
        }
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip() {
        $ip = '';

        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return substr($ip, 0, 45);
    }

    /**
     * Get logs with filters
     *
     * @param array $args Filter arguments
     * @return array Log entries
     */
    public static function get_logs($args = array()) {
        global $wpdb;

        $defaults = array(
            'action_type' => '',
            'entity_type' => '',
            'entity_id' => 0,
            'user_id' => 0,
            'date_from' => '',
            'date_to' => '',
            'limit' => 100,
            'offset' => 0
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array('1=1');
        $params = array();

        if (!empty($args['action_type'])) {
            $where_clauses[] = 'action_type = %s';
            $params[] = $args['action_type'];
        }

        if (!empty($args['entity_type'])) {
            $where_clauses[] = 'entity_type = %s';
            $params[] = $args['entity_type'];
        }

        if ($args['entity_id'] > 0) {
            $where_clauses[] = 'entity_id = %d';
            $params[] = $args['entity_id'];
        }

        if ($args['user_id'] > 0) {
            $where_clauses[] = 'user_id = %d';
            $params[] = $args['user_id'];
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $params[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "SELECT * FROM {$wpdb->prefix}fs_audit_log
                  WHERE $where_sql
                  ORDER BY created_at DESC
                  LIMIT %d OFFSET %d";

        $params[] = $args['limit'];
        $params[] = $args['offset'];

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

    /**
     * Get count of logs with filters
     */
    public static function get_log_count($args = array()) {
        global $wpdb;

        $where_clauses = array('1=1');
        $params = array();

        if (!empty($args['action_type'])) {
            $where_clauses[] = 'action_type = %s';
            $params[] = $args['action_type'];
        }

        if (!empty($args['entity_type'])) {
            $where_clauses[] = 'entity_type = %s';
            $params[] = $args['entity_type'];
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $params[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "SELECT COUNT(*) FROM {$wpdb->prefix}fs_audit_log WHERE $where_sql";

        if (empty($params)) {
            return $wpdb->get_var($query);
        }

        return $wpdb->get_var($wpdb->prepare($query, $params));
    }

    /**
     * Get human-readable action description
     */
    public static function get_action_description($log) {
        $descriptions = array(
            'signup_created' => 'Volunteer signed up for opportunity',
            'signup_cancelled' => 'Signup cancelled',
            'signup_confirmed' => 'Signup confirmed',
            'signup_status_changed' => 'Signup status changed',
            'opportunity_created' => 'Opportunity created',
            'opportunity_updated' => 'Opportunity updated',
            'opportunity_deleted' => 'Opportunity deleted',
            'volunteer_created' => 'Volunteer added',
            'volunteer_updated' => 'Volunteer profile updated',
            'volunteer_deleted' => 'Volunteer removed',
            'bulk_create_opportunity' => 'Bulk opportunity created',
            'import_volunteer' => 'Volunteer imported',
            'batch_email' => 'Batch email sent',
            'role_assigned' => 'Role assigned to volunteer',
            'role_removed' => 'Role removed from volunteer',
            'role_created' => 'Role created',
            'role_updated' => 'Role updated',
            'role_deleted' => 'Role deleted',
            'workflow_created' => 'Workflow created',
            'workflow_updated' => 'Workflow updated',
            'workflow_deleted' => 'Workflow deleted',
            'workflow_completed' => 'Workflow step completed',
            'badge_awarded' => 'Badge awarded',
            'badge_removed' => 'Badge removed',
            'team_created' => 'Team created',
            'team_updated' => 'Team updated',
            'team_deleted' => 'Team deleted',
            'team_member_added' => 'Team member added',
            'team_member_removed' => 'Team member removed',
            'team_signup_created' => 'Team signed up for opportunity',
            'team_signup_cancelled' => 'Team signup cancelled',
            'team_pin_generated' => 'Team PIN generated',
            'team_qr_generated' => 'Team QR code generated',
            'time_checked_in' => 'Volunteer checked in',
            'time_checked_out' => 'Volunteer checked out',
            'blocked_time_added' => 'Blocked time added',
            'blocked_time_deleted' => 'Blocked time deleted'
        );

        return isset($descriptions[$log->action_type]) ? $descriptions[$log->action_type] : $log->action_type;
    }
}
