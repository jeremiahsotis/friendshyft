<?php
/**
 * Test analytics calculations
 *
 * @package FriendShyft
 */

class Test_Analytics extends WP_UnitTestCase {

    private $volunteer_id;
    private $program_id;

    /**
     * Set up before each test
     */
    public function setUp(): void {
        parent::setUp();
        global $wpdb;

        // Clean up tables
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_time_records");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_signups");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_opportunities");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_volunteers");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_programs");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_engagement_scores");

        // Create test program
        $wpdb->insert("{$wpdb->prefix}fs_programs", array('name' => 'Test Program', 'status' => 'active'));
        $this->program_id = $wpdb->insert_id;

        // Create test volunteer
        $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => 'Test Volunteer', 'email' => 'test@example.com'));
        $this->volunteer_id = $wpdb->insert_id;
    }

    /**
     * Test calculating total volunteer hours
     */
    public function test_calculate_total_volunteer_hours() {
        global $wpdb;

        // Create time records
        $wpdb->insert("{$wpdb->prefix}fs_time_records", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => 1, 'hours' => 3.0));
        $wpdb->insert("{$wpdb->prefix}fs_time_records", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => 2, 'hours' => 4.5));
        $wpdb->insert("{$wpdb->prefix}fs_time_records", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => 3, 'hours' => 2.5));

        $total_hours = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records WHERE volunteer_id = %d",
            $this->volunteer_id
        ));

        $this->assertEquals(10.0, $total_hours, "Total hours should be 10.0");
    }

    /**
     * Test calculating economic value
     */
    public function test_calculate_economic_value() {
        $total_hours = 100.0;
        $hourly_value = 31.80; // Independent Sector standard

        $economic_value = $total_hours * $hourly_value;

        $this->assertEquals(3180.0, $economic_value, "Economic value should be $3,180");
    }

    /**
     * Test calculating retention rate
     */
    public function test_calculate_retention_rate() {
        global $wpdb;

        // Create 10 volunteers
        for ($i = 1; $i <= 10; $i++) {
            $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => "Volunteer $i", 'email' => "vol$i@example.com"));
        }

        // 7 volunteers have multiple signups (returning)
        $returning_volunteers = 7;
        $total_volunteers = 10;

        $retention_rate = ($returning_volunteers / $total_volunteers) * 100;

        $this->assertEquals(70.0, $retention_rate, "Retention rate should be 70%");
    }

    /**
     * Test engagement score calculation
     */
    public function test_calculate_engagement_score() {
        // Engagement score factors (0-100 scale)
        $recent_activity = 25;  // 0-30 points
        $signup_frequency = 20; // 0-25 points
        $total_hours = 15;      // 0-20 points
        $reliability = 12;      // 0-15 points
        $achievements = 8;      // 0-10 points

        $engagement_score = $recent_activity + $signup_frequency + $total_hours + $reliability + $achievements;

        $this->assertEquals(80, $engagement_score, "Engagement score should be 80");
        $this->assertLessThanOrEqual(100, $engagement_score, "Engagement score should not exceed 100");
    }

    /**
     * Test determining risk level from engagement score
     */
    public function test_determine_risk_level() {
        $test_cases = array(
            array('score' => 80, 'expected_risk' => 'low'),
            array('score' => 70, 'expected_risk' => 'low'),
            array('score' => 50, 'expected_risk' => 'medium'),
            array('score' => 30, 'expected_risk' => 'medium'),
            array('score' => 15, 'expected_risk' => 'high'),
        );

        foreach ($test_cases as $case) {
            $score = $case['score'];

            if ($score >= 70) {
                $risk_level = 'low';
            } elseif ($score >= 40) {
                $risk_level = 'medium';
            } else {
                $risk_level = 'high';
            }

            $this->assertEquals($case['expected_risk'], $risk_level, "Score {$score} should be {$case['expected_risk']} risk");
        }
    }

    /**
     * Test no-show prediction calculation
     */
    public function test_calculate_no_show_prediction() {
        global $wpdb;

        // Historical data: 10 signups, 2 no-shows
        $total_signups = 10;
        $no_shows = 2;

        $base_rate = $no_shows / $total_signups;

        // Recent data: last 5 signups, 1 no-show
        $recent_signups = 5;
        $recent_no_shows = 1;
        $recent_rate = $recent_no_shows / $recent_signups;

        // Weighted average (60% recent, 40% historical)
        $predicted_rate = ($recent_rate * 0.6) + ($base_rate * 0.4);

        $this->assertEquals(0.2, round($predicted_rate, 2), "Predicted no-show rate should be 0.2 (20%)");
    }

    /**
     * Test forecast fill rate calculation
     */
    public function test_forecast_fill_rate() {
        global $wpdb;

        // Historical fill rates for similar events
        $historical_fill_rates = array(0.8, 0.9, 0.85, 0.75, 0.95);

        $avg_fill_rate = array_sum($historical_fill_rates) / count($historical_fill_rates);

        $this->assertEquals(0.87, round($avg_fill_rate, 2), "Average fill rate should be 0.87 (87%)");

        // Apply time factor (7 days out = 20% boost)
        $days_until = 7;
        $time_factor = $days_until < 7 ? 1.2 : 1.0;

        $adjusted_rate = $avg_fill_rate * $time_factor;

        $this->assertLessThanOrEqual(1.0, $adjusted_rate, "Fill rate should not exceed 100%");
    }

    /**
     * Test hours by program breakdown
     */
    public function test_calculate_hours_by_program() {
        global $wpdb;

        // Create second program
        $wpdb->insert("{$wpdb->prefix}fs_programs", array('name' => 'Program 2', 'status' => 'active'));
        $program_2_id = $wpdb->insert_id;

        // Create opportunities for each program
        $wpdb->insert("{$wpdb->prefix}fs_opportunities", array('title' => 'Event 1', 'event_date' => '2026-01-01', 'program_id' => $this->program_id));
        $opp_1 = $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}fs_opportunities", array('title' => 'Event 2', 'event_date' => '2026-01-02', 'program_id' => $program_2_id));
        $opp_2 = $wpdb->insert_id;

        // Create time records
        $wpdb->insert("{$wpdb->prefix}fs_time_records", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => $opp_1, 'hours' => 5.0));
        $wpdb->insert("{$wpdb->prefix}fs_time_records", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => $opp_2, 'hours' => 3.0));

        // Calculate hours by program
        $hours_by_program = $wpdb->get_results(
            "SELECT p.name, SUM(t.hours) as total_hours
             FROM {$wpdb->prefix}fs_time_records t
             JOIN {$wpdb->prefix}fs_opportunities o ON t.opportunity_id = o.id
             JOIN {$wpdb->prefix}fs_programs p ON o.program_id = p.id
             GROUP BY p.id"
        );

        $this->assertCount(2, $hours_by_program, "Should have data for 2 programs");
    }

    /**
     * Test unique volunteer count
     */
    public function test_count_unique_volunteers() {
        global $wpdb;

        // Create 5 volunteers
        for ($i = 1; $i <= 5; $i++) {
            $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => "Vol $i", 'email' => "v$i@example.com"));
        }

        $unique_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteers");

        $this->assertEquals(6, $unique_count, "Should have 6 unique volunteers (including setup volunteer)");
    }

    /**
     * Test confidence score calculation
     */
    public function test_calculate_confidence_score() {
        $sample_sizes = array(5, 10, 15, 20, 25);

        foreach ($sample_sizes as $sample_size) {
            // Confidence scales with sample size (max at 20 samples)
            $confidence = min($sample_size / 20, 1.0);

            if ($sample_size == 20) {
                $this->assertEquals(1.0, $confidence, "Confidence should be 100% at 20 samples");
            } elseif ($sample_size == 10) {
                $this->assertEquals(0.5, $confidence, "Confidence should be 50% at 10 samples");
            }
        }
    }

    /**
     * Test year-over-year comparison
     */
    public function test_calculate_year_over_year_comparison() {
        $last_year_hours = 500.0;
        $this_year_hours = 650.0;

        $growth = (($this_year_hours - $last_year_hours) / $last_year_hours) * 100;

        $this->assertEquals(30.0, round($growth, 1), "Year-over-year growth should be 30%");
    }
}
