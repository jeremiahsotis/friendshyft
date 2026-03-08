<?php
if (!defined('ABSPATH')) exit;

/**
 * Point of Contact Role Management
 * Handles creation and permissions for Point of Contact users
 */
class FS_POC_Role {

    public static function init() {
        // Create role on plugin activation if it doesn't exist
        add_action('admin_init', array(__CLASS__, 'ensure_role_exists'));
    }

    /**
     * Ensure the POC role exists with proper capabilities
     */
    public static function ensure_role_exists() {
        if (!get_role('fs_point_of_contact')) {
            self::create_role();
        }
    }

    /**
     * Create the Point of Contact role
     */
    public static function create_role() {
        $capabilities = array(
            'read' => true,

            // Dashboard access
            'fs_view_poc_dashboard' => true,

            // Opportunity viewing (read-only for their assigned opportunities)
            'fs_view_assigned_opportunities' => true,

            // Volunteer management for their opportunities
            'fs_view_volunteers' => true,
            'fs_view_volunteer_details' => true,

            // Signup management for their opportunities
            'fs_manage_signups' => true,
            'fs_approve_volunteers' => true,
            'fs_cancel_signups' => true,

            // Workflow management
            'fs_manage_workflows' => true,
            'fs_view_workflows' => true,

            // Reporting for their opportunities
            'fs_view_reports' => true,
            'fs_export_data' => true,
        );

        add_role(
            'fs_point_of_contact',
            'Point of Contact',
            $capabilities
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Created Point of Contact role');
        }
    }

    /**
     * Check if user is a POC for a specific opportunity
     */
    public static function is_poc_for_opportunity($user_id, $opportunity_id) {
        global $wpdb;

        $opp = $wpdb->get_row($wpdb->prepare(
            "SELECT point_of_contact_id FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        return $opp && $opp->point_of_contact_id == $user_id;
    }

    /**
     * Get all opportunities for a POC
     */
    public static function get_poc_opportunities($user_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities
            WHERE point_of_contact_id = %d
            ORDER BY event_date ASC",
            $user_id
        ));
    }

    /**
     * Get all volunteers signed up for POC's opportunities
     */
    public static function get_poc_volunteers($user_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT v.*, s.opportunity_id, o.title as opportunity_title
            FROM {$wpdb->prefix}fs_volunteers v
            JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
            JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
            WHERE o.point_of_contact_id = %d
            AND s.status = 'confirmed'
            ORDER BY v.name ASC",
            $user_id
        ));
    }

    /**
     * Get volunteers interested in programs associated with POC's opportunities
     */
    public static function get_interested_volunteers($user_id) {
        global $wpdb;

        // Get unique programs from POC's opportunities
        $programs = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT conference FROM {$wpdb->prefix}fs_opportunities
            WHERE point_of_contact_id = %d AND conference IS NOT NULL",
            $user_id
        ));

        if (empty($programs)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($programs), '%s'));

        // Check if volunteer_interests table exists
        $table_exists = $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}fs_volunteer_interests'"
        );

        if (!$table_exists) {
            return array();
        }

        // Get volunteers interested in these programs
        $query = $wpdb->prepare(
            "SELECT DISTINCT v.*, vi.interest
            FROM {$wpdb->prefix}fs_volunteers v
            JOIN {$wpdb->prefix}fs_volunteer_interests vi ON v.id = vi.volunteer_id
            WHERE vi.interest IN ($placeholders)
            ORDER BY v.name ASC",
            $programs
        );

        return $wpdb->get_results($query);
    }

    /**
     * Get volunteers approved for roles required by POC's opportunities
     */
    public static function get_approved_volunteers($user_id) {
        global $wpdb;

        // Get unique required roles from POC's opportunities
        $opportunities = self::get_poc_opportunities($user_id);
        $role_ids = array();

        foreach ($opportunities as $opp) {
            if ($opp->required_roles) {
                $roles = json_decode($opp->required_roles, true);
                if (is_array($roles)) {
                    $role_ids = array_merge($role_ids, $roles);
                }
            }
        }

        $role_ids = array_unique($role_ids);

        if (empty($role_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($role_ids), '%d'));

        // Get volunteers approved for these roles
        $query = $wpdb->prepare(
            "SELECT DISTINCT v.*, r.name as role_name, vr.role_id
            FROM {$wpdb->prefix}fs_volunteers v
            JOIN {$wpdb->prefix}fs_volunteer_roles vr ON v.id = vr.volunteer_id
            JOIN {$wpdb->prefix}fs_roles r ON vr.role_id = r.id
            WHERE vr.role_id IN ($placeholders)
            ORDER BY v.name ASC",
            $role_ids
        );

        return $wpdb->get_results($query);
    }

    /**
     * Check if current user has POC capabilities
     */
    public static function current_user_is_poc() {
        $user = wp_get_current_user();
        return in_array('fs_point_of_contact', $user->roles) || current_user_can('manage_options');
    }
}
