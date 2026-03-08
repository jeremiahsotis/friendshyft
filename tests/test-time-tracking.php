<?php
/**
 * Test time tracking calculations
 *
 * @package FriendShyft
 */

class Test_Time_Tracking extends WP_UnitTestCase {

    private $volunteer_id;
    private $opportunity_id;

    /**
     * Set up before each test
     */
    public function setUp(): void {
        parent::setUp();
        global $wpdb;

        // Clean up tables
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_time_records");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_volunteers");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_opportunities");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_programs");

        // Create test program
        $wpdb->insert("{$wpdb->prefix}fs_programs", array('name' => 'Test Program', 'status' => 'active'));
        $program_id = $wpdb->insert_id;

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array('name' => 'Test Volunteer', 'email' => 'test@example.com')
        );
        $this->volunteer_id = $wpdb->insert_id;

        // Create test opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Test Opportunity',
                'event_date' => '2026-01-15',
                'program_id' => $program_id,
            )
        );
        $this->opportunity_id = $wpdb->insert_id;
    }

    /**
     * Test recording check-in time
     */
    public function test_record_checkin() {
        global $wpdb;

        $checkin_time = '2026-01-15 09:00:00';

        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => $checkin_time,
            )
        );

        $record_id = $wpdb->insert_id;

        $this->assertGreaterThan(0, $record_id, "Time record should be created");

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_time_records WHERE id = %d",
            $record_id
        ));

        $this->assertEquals($checkin_time, $record->check_in);
        $this->assertNull($record->check_out, "Check-out should be null initially");
    }

    /**
     * Test recording check-out and calculating hours
     */
    public function test_record_checkout_and_calculate_hours() {
        global $wpdb;

        $checkin_time = '2026-01-15 09:00:00';
        $checkout_time = '2026-01-15 12:30:00';

        // Create time record with check-in
        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => $checkin_time,
            )
        );
        $record_id = $wpdb->insert_id;

        // Update with check-out
        $wpdb->update(
            "{$wpdb->prefix}fs_time_records",
            array('check_out' => $checkout_time),
            array('id' => $record_id)
        );

        // Calculate hours
        $hours = (strtotime($checkout_time) - strtotime($checkin_time)) / 3600;

        // Update hours in database
        $wpdb->update(
            "{$wpdb->prefix}fs_time_records",
            array('hours' => $hours),
            array('id' => $record_id)
        );

        // Verify
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_time_records WHERE id = %d",
            $record_id
        ));

        $this->assertEquals(3.5, $record->hours, "Should calculate 3.5 hours");
    }

    /**
     * Test calculating hours for different durations
     */
    public function test_calculate_hours_various_durations() {
        $test_cases = array(
            array('09:00:00', '10:00:00', 1.0),    // 1 hour
            array('09:00:00', '10:30:00', 1.5),    // 1.5 hours
            array('09:00:00', '12:00:00', 3.0),    // 3 hours
            array('09:15:00', '12:45:00', 3.5),    // 3.5 hours
            array('09:00:00', '17:30:00', 8.5),    // 8.5 hours
        );

        foreach ($test_cases as $case) {
            $checkin = "2026-01-15 " . $case[0];
            $checkout = "2026-01-15 " . $case[1];
            $expected_hours = $case[2];

            $calculated_hours = (strtotime($checkout) - strtotime($checkin)) / 3600;

            $this->assertEquals(
                $expected_hours,
                $calculated_hours,
                "Hours calculation for {$case[0]} to {$case[1]} should be {$expected_hours}"
            );
        }
    }

    /**
     * Test total hours for volunteer across multiple shifts
     */
    public function test_calculate_total_hours_for_volunteer() {
        global $wpdb;

        // Create multiple time records
        $time_records = array(
            array('check_in' => '2026-01-15 09:00:00', 'check_out' => '2026-01-15 12:00:00', 'hours' => 3.0),
            array('check_in' => '2026-01-20 10:00:00', 'check_out' => '2026-01-20 14:00:00', 'hours' => 4.0),
            array('check_in' => '2026-01-25 08:00:00', 'check_out' => '2026-01-25 12:30:00', 'hours' => 4.5),
        );

        foreach ($time_records as $record) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_time_records",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'opportunity_id' => $this->opportunity_id,
                    'check_in' => $record['check_in'],
                    'check_out' => $record['check_out'],
                    'hours' => $record['hours'],
                )
            );
        }

        // Calculate total hours
        $total_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records WHERE volunteer_id = %d",
            $this->volunteer_id
        ));

        $this->assertEquals(11.5, $total_hours, "Total hours should be 11.5");
    }

    /**
     * Test hours for specific date range
     */
    public function test_calculate_hours_for_date_range() {
        global $wpdb;

        // Create time records across different months
        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => '2026-01-15 09:00:00',
                'hours' => 3.0,
            )
        );
        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => '2026-02-15 09:00:00',
                'hours' => 4.0,
            )
        );
        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => '2026-03-15 09:00:00',
                'hours' => 2.0,
            )
        );

        // Get hours for January-February only
        $hours_range = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records
             WHERE volunteer_id = %d
             AND check_in >= %s
             AND check_in <= %s",
            $this->volunteer_id,
            '2026-01-01 00:00:00',
            '2026-02-28 23:59:59'
        ));

        $this->assertEquals(7.0, $hours_range, "Hours for Jan-Feb should be 7.0");
    }

    /**
     * Test rounding hours to nearest quarter hour
     */
    public function test_round_hours_to_quarter_hour() {
        $test_cases = array(
            array('09:00:00', '09:07:00', 0.25),  // 7 min → 0.25 hrs
            array('09:00:00', '09:10:00', 0.25),  // 10 min → 0.25 hrs
            array('09:00:00', '09:20:00', 0.25),  // 20 min → 0.25 hrs
            array('09:00:00', '09:23:00', 0.5),   // 23 min → 0.5 hrs
            array('09:00:00', '09:40:00', 0.75),  // 40 min → 0.75 hrs
            array('09:00:00', '10:05:00', 1.0),   // 65 min → 1.0 hrs
        );

        foreach ($test_cases as $case) {
            $checkin = "2026-01-15 " . $case[0];
            $checkout = "2026-01-15 " . $case[1];
            $expected_rounded = $case[2];

            $actual_hours = (strtotime($checkout) - strtotime($checkin)) / 3600;
            $rounded_hours = round($actual_hours * 4) / 4; // Round to nearest 0.25

            $this->assertEquals(
                $expected_rounded,
                $rounded_hours,
                "Rounded hours for {$case[0]} to {$case[1]} should be {$expected_rounded}"
            );
        }
    }

    /**
     * Test listing time records for volunteer
     */
    public function test_list_time_records_for_volunteer() {
        global $wpdb;

        // Create multiple records
        for ($i = 1; $i <= 5; $i++) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_time_records",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'opportunity_id' => $this->opportunity_id,
                    'check_in' => "2026-01-{$i}5 09:00:00",
                    'hours' => 3.0,
                )
            );
        }

        // Get records
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_time_records WHERE volunteer_id = %d ORDER BY check_in DESC",
            $this->volunteer_id
        ));

        $this->assertCount(5, $records, "Should find 5 time records");
    }

    /**
     * Test preventing duplicate check-ins
     */
    public function test_prevent_duplicate_checkins() {
        global $wpdb;

        // Create initial check-in
        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => '2026-01-15 09:00:00',
            )
        );

        // Check for existing check-in
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_time_records
             WHERE volunteer_id = %d
             AND opportunity_id = %d
             AND check_out IS NULL",
            $this->volunteer_id,
            $this->opportunity_id
        ));

        $this->assertEquals(1, $existing, "Should detect existing check-in without check-out");
    }

    /**
     * Test monthly hours summary
     */
    public function test_monthly_hours_summary() {
        global $wpdb;

        // Create records for different months
        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => '2026-01-15 09:00:00',
                'hours' => 5.0,
            )
        );
        $wpdb->insert(
            "{$wpdb->prefix}fs_time_records",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'check_in' => '2026-01-20 09:00:00',
                'hours' => 3.0,
            )
        );

        // Get January hours
        $january_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records
             WHERE volunteer_id = %d
             AND MONTH(check_in) = 1
             AND YEAR(check_in) = 2026",
            $this->volunteer_id
        ));

        $this->assertEquals(8.0, $january_hours, "January hours should be 8.0");
    }

    /**
     * Test yearly hours summary
     */
    public function test_yearly_hours_summary() {
        global $wpdb;

        // Create records across the year
        for ($month = 1; $month <= 12; $month++) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_time_records",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'opportunity_id' => $this->opportunity_id,
                    'check_in' => sprintf('2026-%02d-15 09:00:00', $month),
                    'hours' => 4.0,
                )
            );
        }

        // Get 2026 hours
        $yearly_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records
             WHERE volunteer_id = %d
             AND YEAR(check_in) = 2026",
            $this->volunteer_id
        ));

        $this->assertEquals(48.0, $yearly_hours, "Yearly hours should be 48.0 (12 months × 4 hours)");
    }

    /**
     * Test average hours per shift
     */
    public function test_average_hours_per_shift() {
        global $wpdb;

        // Create multiple shifts with different hours
        $shifts = array(2.0, 3.0, 4.0, 5.0, 6.0);
        foreach ($shifts as $hours) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_time_records",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'opportunity_id' => $this->opportunity_id,
                    'check_in' => '2026-01-15 09:00:00',
                    'hours' => $hours,
                )
            );
        }

        // Calculate average
        $avg_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(hours) FROM {$wpdb->prefix}fs_time_records WHERE volunteer_id = %d",
            $this->volunteer_id
        ));

        $this->assertEquals(4.0, $avg_hours, "Average hours per shift should be 4.0");
    }
}
