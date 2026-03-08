<?php
/**
 * Test database schema creation
 *
 * @package FriendShyft
 */

class Test_Database_Schema extends WP_UnitTestCase {

    /**
     * Test that all core tables are created on plugin activation
     */
    public function test_core_tables_exist() {
        global $wpdb;

        $tables = array(
            'fs_volunteers',
            'fs_roles',
            'fs_programs',
            'fs_opportunities',
            'fs_signups',
            'fs_time_records',
            'fs_badges',
            'fs_workflows',
            'fs_workflow_steps',
            'fs_volunteer_workflows',
            'fs_volunteer_interests',
            'fs_opportunity_roles',
            'fs_volunteer_roles',
            'fs_opportunity_templates',
            'fs_holidays',
        );

        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            $this->assertEquals($table_name, $result, "Table $table_name should exist");
        }
    }

    /**
     * Test that team management tables exist
     */
    public function test_team_tables_exist() {
        global $wpdb;

        $tables = array(
            'fs_teams',
            'fs_team_members',
            'fs_team_attendance',
        );

        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            $this->assertEquals($table_name, $result, "Table $table_name should exist");
        }
    }

    /**
     * Test that email ingestion tables exist
     */
    public function test_email_ingestion_tables_exist() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'fs_email_log';
        $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        $this->assertEquals($table_name, $result, "Table $table_name should exist");
    }

    /**
     * Test that audit log table exists
     */
    public function test_audit_log_table_exists() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'fs_audit_log';
        $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        $this->assertEquals($table_name, $result, "Table $table_name should exist");
    }

    /**
     * Test that Google Calendar tables exist
     */
    public function test_google_calendar_tables_exist() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'fs_blocked_times';
        $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        $this->assertEquals($table_name, $result, "Table $table_name should exist");
    }

    /**
     * Test that feedback system tables exist
     */
    public function test_feedback_tables_exist() {
        global $wpdb;

        $tables = array(
            'fs_surveys',
            'fs_suggestions',
            'fs_testimonials',
        );

        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            $this->assertEquals($table_name, $result, "Table $table_name should exist");
        }
    }

    /**
     * Test that advanced scheduling tables exist
     */
    public function test_advanced_scheduling_tables_exist() {
        global $wpdb;

        $tables = array(
            'fs_waitlist',
            'fs_substitute_requests',
            'fs_swap_history',
            'fs_availability',
            'fs_blackout_dates',
            'fs_auto_signup_log',
        );

        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            $this->assertEquals($table_name, $result, "Table $table_name should exist");
        }
    }

    /**
     * Test that analytics tables exist
     */
    public function test_analytics_tables_exist() {
        global $wpdb;

        $tables = array(
            'fs_predictions',
            'fs_engagement_scores',
            'fs_reengagement_campaigns',
        );

        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
            $this->assertEquals($table_name, $result, "Table $table_name should exist");
        }
    }

    /**
     * Test that volunteers table has correct columns
     */
    public function test_volunteers_table_structure() {
        global $wpdb;

        $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fs_volunteers");
        $column_names = wp_list_pluck($columns, 'Field');

        $expected_columns = array(
            'id',
            'name',
            'email',
            'phone',
            'date_of_birth',
            'address',
            'emergency_contact',
            'emergency_phone',
            'volunteer_status',
            'notes',
            'access_token',
            'pin',
            'qr_code',
            'created_at',
        );

        foreach ($expected_columns as $column) {
            $this->assertContains($column, $column_names, "Column $column should exist in volunteers table");
        }
    }

    /**
     * Test that opportunities table has correct columns
     */
    public function test_opportunities_table_structure() {
        global $wpdb;

        $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fs_opportunities");
        $column_names = wp_list_pluck($columns, 'Field');

        $expected_columns = array(
            'id',
            'title',
            'description',
            'event_date',
            'event_time_start',
            'event_time_end',
            'location',
            'spots_available',
            'spots_filled',
            'program_id',
            'status',
            'created_at',
        );

        foreach ($expected_columns as $column) {
            $this->assertContains($column, $column_names, "Column $column should exist in opportunities table");
        }
    }

    /**
     * Test that signups table has correct columns
     */
    public function test_signups_table_structure() {
        global $wpdb;

        $columns = $wpdb->get_results("DESCRIBE {$wpdb->prefix}fs_signups");
        $column_names = wp_list_pluck($columns, 'Field');

        $expected_columns = array(
            'id',
            'volunteer_id',
            'opportunity_id',
            'status',
            'signup_date',
        );

        foreach ($expected_columns as $column) {
            $this->assertContains($column, $column_names, "Column $column should exist in signups table");
        }
    }

    /**
     * Test that indexes are created on key columns
     */
    public function test_important_indexes_exist() {
        global $wpdb;

        // Check index on volunteers.email
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}fs_volunteers WHERE Column_name = 'email'");
        $this->assertNotEmpty($indexes, "Index should exist on volunteers.email");

        // Check index on volunteers.access_token
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}fs_volunteers WHERE Column_name = 'access_token'");
        $this->assertNotEmpty($indexes, "Index should exist on volunteers.access_token");

        // Check index on signups.volunteer_id
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}fs_signups WHERE Column_name = 'volunteer_id'");
        $this->assertNotEmpty($indexes, "Index should exist on signups.volunteer_id");

        // Check index on signups.opportunity_id
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}fs_signups WHERE Column_name = 'opportunity_id'");
        $this->assertNotEmpty($indexes, "Index should exist on signups.opportunity_id");
    }
}
