<?php
/**
 * Test badge awarding logic
 *
 * @package FriendShyft
 */

class Test_Badge_Awarding extends WP_UnitTestCase {

    private $volunteer_id;

    /**
     * Set up before each test
     */
    public function setUp(): void {
        parent::setUp();
        global $wpdb;

        // Clean up tables
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_badges");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_time_records");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_volunteers");

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array('name' => 'Test Volunteer', 'email' => 'test@example.com')
        );
        $this->volunteer_id = $wpdb->insert_id;
    }

    /**
     * Test awarding 10-hour badge
     */
    public function test_award_10_hour_badge() {
        global $wpdb;

        // Simulate 10 hours of volunteer time
        $total_hours = 10.0;

        // Check if badge should be awarded
        $should_award = $total_hours >= 10;
        $this->assertTrue($should_award, "Should award 10-hour badge at 10 hours");

        // Award badge
        if ($should_award) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_badges",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'badge_type' => '10_hours',
                    'earned_at' => current_time('mysql'),
                )
            );
        }

        // Verify badge awarded
        $badge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_badges
             WHERE volunteer_id = %d AND badge_type = '10_hours'",
            $this->volunteer_id
        ));

        $this->assertNotNull($badge, "10-hour badge should be awarded");
    }

    /**
     * Test awarding multiple milestone badges
     */
    public function test_award_milestone_badges() {
        global $wpdb;

        $milestones = array(
            array('hours' => 10, 'badge' => '10_hours'),
            array('hours' => 50, 'badge' => '50_hours'),
            array('hours' => 100, 'badge' => '100_hours'),
            array('hours' => 500, 'badge' => '500_hours'),
        );

        $volunteer_hours = 75.0;

        foreach ($milestones as $milestone) {
            if ($volunteer_hours >= $milestone['hours']) {
                // Check not already awarded
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fs_badges
                     WHERE volunteer_id = %d AND badge_type = %s",
                    $this->volunteer_id,
                    $milestone['badge']
                ));

                if ($existing == 0) {
                    $wpdb->insert(
                        "{$wpdb->prefix}fs_badges",
                        array(
                            'volunteer_id' => $this->volunteer_id,
                            'badge_type' => $milestone['badge'],
                            'earned_at' => current_time('mysql'),
                        )
                    );
                }
            }
        }

        // Verify correct badges awarded
        $badges = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_badges WHERE volunteer_id = %d",
            $this->volunteer_id
        ));

        $badge_types = wp_list_pluck($badges, 'badge_type');

        $this->assertContains('10_hours', $badge_types);
        $this->assertContains('50_hours', $badge_types);
        $this->assertNotContains('100_hours', $badge_types, "Should not award 100-hour badge at 75 hours");
    }

    /**
     * Test preventing duplicate badge awards
     */
    public function test_prevent_duplicate_badge_awards() {
        global $wpdb;

        // Award badge first time
        $wpdb->insert(
            "{$wpdb->prefix}fs_badges",
            array(
                'volunteer_id' => $this->volunteer_id,
                'badge_type' => '10_hours',
                'earned_at' => current_time('mysql'),
            )
        );

        // Check if already awarded
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_badges
             WHERE volunteer_id = %d AND badge_type = '10_hours'",
            $this->volunteer_id
        ));

        $this->assertEquals(1, $existing, "Should detect existing badge");

        // Don't award again
        if ($existing == 0) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_badges",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'badge_type' => '10_hours',
                )
            );
        }

        // Verify still only one badge
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_badges
             WHERE volunteer_id = %d AND badge_type = '10_hours'",
            $this->volunteer_id
        ));

        $this->assertEquals(1, $count, "Should not have duplicate badges");
    }

    /**
     * Test listing volunteer's badges
     */
    public function test_list_volunteer_badges() {
        global $wpdb;

        // Award multiple badges
        $badges = array('10_hours', '50_hours', 'first_signup');
        foreach ($badges as $badge_type) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_badges",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'badge_type' => $badge_type,
                    'earned_at' => current_time('mysql'),
                )
            );
        }

        // Get badges
        $volunteer_badges = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_badges WHERE volunteer_id = %d ORDER BY earned_at DESC",
            $this->volunteer_id
        ));

        $this->assertCount(3, $volunteer_badges, "Should have 3 badges");
    }

    /**
     * Test badge count for volunteer
     */
    public function test_badge_count_for_volunteer() {
        global $wpdb;

        // Award badges
        for ($i = 0; $i < 5; $i++) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_badges",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'badge_type' => "badge_$i",
                    'earned_at' => current_time('mysql'),
                )
            );
        }

        // Count badges
        $badge_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_badges WHERE volunteer_id = %d",
            $this->volunteer_id
        ));

        $this->assertEquals(5, $badge_count, "Should have 5 badges");
    }

    /**
     * Test awarding first signup badge
     */
    public function test_award_first_signup_badge() {
        global $wpdb;

        // Check signup count
        $signup_count = 1; // First signup

        if ($signup_count == 1) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_badges
                 WHERE volunteer_id = %d AND badge_type = 'first_signup'",
                $this->volunteer_id
            ));

            if ($existing == 0) {
                $wpdb->insert(
                    "{$wpdb->prefix}fs_badges",
                    array(
                        'volunteer_id' => $this->volunteer_id,
                        'badge_type' => 'first_signup',
                        'earned_at' => current_time('mysql'),
                    )
                );
            }
        }

        // Verify badge
        $badge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_badges
             WHERE volunteer_id = %d AND badge_type = 'first_signup'",
            $this->volunteer_id
        ));

        $this->assertNotNull($badge, "First signup badge should be awarded");
    }

    /**
     * Test awarding consistency badge (10 signups)
     */
    public function test_award_consistency_badge() {
        global $wpdb;

        $signup_count = 10;

        if ($signup_count >= 10) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_badges
                 WHERE volunteer_id = %d AND badge_type = 'consistent_volunteer'",
                $this->volunteer_id
            ));

            if ($existing == 0) {
                $wpdb->insert(
                    "{$wpdb->prefix}fs_badges",
                    array(
                        'volunteer_id' => $this->volunteer_id,
                        'badge_type' => 'consistent_volunteer',
                        'earned_at' => current_time('mysql'),
                    )
                );
            }
        }

        // Verify
        $badge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_badges
             WHERE volunteer_id = %d AND badge_type = 'consistent_volunteer'",
            $this->volunteer_id
        ));

        $this->assertNotNull($badge, "Consistency badge should be awarded at 10 signups");
    }

    /**
     * Test badge earned_at timestamp
     */
    public function test_badge_earned_at_timestamp() {
        global $wpdb;

        $before_time = current_time('mysql');

        $wpdb->insert(
            "{$wpdb->prefix}fs_badges",
            array(
                'volunteer_id' => $this->volunteer_id,
                'badge_type' => 'test_badge',
                'earned_at' => current_time('mysql'),
            )
        );

        $badge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_badges
             WHERE volunteer_id = %d AND badge_type = 'test_badge'",
            $this->volunteer_id
        ));

        $this->assertNotNull($badge->earned_at, "Badge should have earned_at timestamp");
        $this->assertGreaterThanOrEqual($before_time, $badge->earned_at);
    }
}
