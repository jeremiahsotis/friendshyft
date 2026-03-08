<?php
/**
 * FriendShyft Team Management - Database Migration
 * 
 * Creates:
 * - Teams table (persistent team identities)
 * - Team members table (optional individual tracking)
 * - Team signups table (team shift claims)
 * - Team attendance table (time tracking)
 * - Modifies opportunities to allow team signups
 */

class FS_Team_Management_Migration {
    
    public static function run() {
        global $wpdb;
        
        // Create teams table
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fs_teams (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                type varchar(20) NOT NULL DEFAULT 'recurring',
                team_leader_volunteer_id bigint(20) DEFAULT NULL,
                default_size int(11) NOT NULL DEFAULT 1,
                description text DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_date datetime NOT NULL,
                PRIMARY KEY (id),
                KEY team_leader (team_leader_volunteer_id),
                KEY type (type),
                KEY status (status),
                KEY name (name)
            )
        ");
        
        // Create team members table (optional individual tracking)
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fs_team_members (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                team_id bigint(20) NOT NULL,
                volunteer_id bigint(20) DEFAULT NULL,
                name varchar(255) DEFAULT NULL,
                role varchar(20) DEFAULT 'member',
                notes text DEFAULT NULL,
                added_date datetime NOT NULL,
                PRIMARY KEY (id),
                KEY team_id (team_id),
                KEY volunteer_id (volunteer_id),
                KEY role (role)
            )
        ");
        
        // Create team signups table
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fs_team_signups (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                team_id bigint(20) NOT NULL,
                opportunity_id bigint(20) NOT NULL,
                shift_id bigint(20) DEFAULT NULL,
                period_id bigint(20) DEFAULT NULL,
                scheduled_size int(11) NOT NULL,
                actual_attendance int(11) DEFAULT NULL,
                signup_date datetime NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'scheduled',
                notes text DEFAULT NULL,
                PRIMARY KEY (id),
                KEY team_id (team_id),
                KEY opportunity_id (opportunity_id),
                KEY shift_id (shift_id),
                KEY period_id (period_id),
                KEY status (status),
                KEY signup_date (signup_date)
            )
        ");
        
        // Create team attendance table (time tracking)
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fs_team_attendance (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                team_signup_id bigint(20) NOT NULL,
                check_in_time datetime NOT NULL,
                check_out_time datetime DEFAULT NULL,
                people_count int(11) NOT NULL,
                hours_per_person decimal(10,2) DEFAULT NULL,
                total_hours decimal(10,2) DEFAULT NULL,
                notes text DEFAULT NULL,
                PRIMARY KEY (id),
                KEY team_signup_id (team_signup_id),
                KEY check_in_time (check_in_time)
            )
        ");
        
        // Add allow_team_signups to opportunities (check if column exists first)
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}fs_opportunities LIKE 'allow_team_signups'"
        );

        if (empty($column_exists)) {
            $wpdb->query("
                ALTER TABLE {$wpdb->prefix}fs_opportunities
                ADD COLUMN allow_team_signups TINYINT(1) DEFAULT 0 AFTER template_id
            ");
        }
        
        return true;
    }
    
    public static function rollback() {
        global $wpdb;
        
        // Drop tables
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_team_attendance");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_team_signups");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_team_members");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_teams");
        
        // Remove column from opportunities
        $wpdb->query("
            ALTER TABLE {$wpdb->prefix}fs_opportunities 
            DROP COLUMN allow_team_signups
        ");
        
        return true;
    }
    
    /**
     * Check migration status
     */
    public static function check_status() {
        global $wpdb;
        
        $status = array(
            'teams_table' => false,
            'members_table' => false,
            'signups_table' => false,
            'attendance_table' => false,
            'opportunities_column' => false
        );
        
        $status['teams_table'] = (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}fs_teams'"
        );
        
        $status['members_table'] = (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}fs_team_members'"
        );
        
        $status['signups_table'] = (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}fs_team_signups'"
        );
        
        $status['attendance_table'] = (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$wpdb->prefix}fs_team_attendance'"
        );
        
        $status['opportunities_column'] = (bool) $wpdb->get_var(
            "SHOW COLUMNS FROM {$wpdb->prefix}fs_opportunities LIKE 'allow_team_signups'"
        );
        
        return $status;
    }
}
