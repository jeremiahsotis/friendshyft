<?php
/**
 * Test signup logic and conflict detection
 *
 * @package FriendShyft
 */

class Test_Signup_Logic extends WP_UnitTestCase {

    private $volunteer_id;
    private $opportunity_id;
    private $program_id;

    /**
     * Set up before each test
     */
    public function setUp(): void {
        parent::setUp();
        global $wpdb;

        // Clean up tables
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_signups");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_opportunities");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_volunteers");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_programs");

        // Create test program
        $wpdb->insert(
            "{$wpdb->prefix}fs_programs",
            array('name' => 'Test Program', 'status' => 'active')
        );
        $this->program_id = $wpdb->insert_id;

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Test Volunteer',
                'email' => 'test@example.com',
                'volunteer_status' => 'active',
            )
        );
        $this->volunteer_id = $wpdb->insert_id;

        // Create test opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Test Opportunity',
                'event_date' => '2026-01-15',
                'event_time_start' => '09:00:00',
                'event_time_end' => '12:00:00',
                'spots_available' => 5,
                'spots_filled' => 0,
                'program_id' => $this->program_id,
                'status' => 'published',
            )
        );
        $this->opportunity_id = $wpdb->insert_id;
    }

    /**
     * Test creating a signup
     */
    public function test_create_signup() {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'confirmed',
                'signup_date' => current_time('mysql'),
            )
        );

        $signup_id = $wpdb->insert_id;

        $this->assertGreaterThan(0, $signup_id, "Signup should be created with valid ID");

        // Verify signup
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));

        $this->assertEquals($this->volunteer_id, $signup->volunteer_id);
        $this->assertEquals($this->opportunity_id, $signup->opportunity_id);
        $this->assertEquals('confirmed', $signup->status);
    }

    /**
     * Test detecting time conflicts
     */
    public function test_detect_time_conflicts() {
        global $wpdb;

        // Create first signup
        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'confirmed',
            )
        );

        // Create overlapping opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Overlapping Event',
                'event_date' => '2026-01-15',
                'event_time_start' => '10:00:00',
                'event_time_end' => '13:00:00',
                'program_id' => $this->program_id,
                'status' => 'published',
            )
        );
        $overlapping_id = $wpdb->insert_id;

        // Check for conflicts
        $conflict = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.volunteer_id = %d
             AND s.status IN ('confirmed', 'pending')
             AND o.event_date = '2026-01-15'
             AND (
                 (o.event_time_start <= '10:00:00' AND o.event_time_end > '10:00:00')
                 OR (o.event_time_start < '13:00:00' AND o.event_time_end >= '13:00:00')
                 OR (o.event_time_start >= '10:00:00' AND o.event_time_end <= '13:00:00')
             )",
            $this->volunteer_id
        ));

        $this->assertGreaterThan(0, $conflict, "Should detect time conflict");
    }

    /**
     * Test no conflict for non-overlapping times
     */
    public function test_no_conflict_for_non_overlapping_times() {
        global $wpdb;

        // Create first signup (9 AM - 12 PM)
        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'confirmed',
            )
        );

        // Create non-overlapping opportunity (1 PM - 4 PM)
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Afternoon Event',
                'event_date' => '2026-01-15',
                'event_time_start' => '13:00:00',
                'event_time_end' => '16:00:00',
                'program_id' => $this->program_id,
                'status' => 'published',
            )
        );
        $afternoon_id = $wpdb->insert_id;

        // Check for conflicts (should be zero)
        $conflict = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.volunteer_id = %d
             AND s.status IN ('confirmed', 'pending')
             AND o.event_date = '2026-01-15'
             AND (
                 (o.event_time_start <= '13:00:00' AND o.event_time_end > '13:00:00')
                 OR (o.event_time_start < '16:00:00' AND o.event_time_end >= '16:00:00')
                 OR (o.event_time_start >= '13:00:00' AND o.event_time_end <= '16:00:00')
             )",
            $this->volunteer_id
        ));

        $this->assertEquals(0, $conflict, "Should not detect conflict for non-overlapping times");
    }

    /**
     * Test preventing duplicate signups
     */
    public function test_prevent_duplicate_signups() {
        global $wpdb;

        // Create first signup
        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'confirmed',
            )
        );

        // Check for existing signup
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d
             AND opportunity_id = %d
             AND status IN ('confirmed', 'pending')",
            $this->volunteer_id,
            $this->opportunity_id
        ));

        $this->assertEquals(1, $existing, "Should detect existing signup");
    }

    /**
     * Test spots_filled increment on signup
     */
    public function test_spots_filled_increment() {
        global $wpdb;

        // Get initial spots_filled
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $this->opportunity_id
        ));
        $initial_spots = $opportunity->spots_filled;

        // Create signup
        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'confirmed',
            )
        );

        // Increment spots_filled
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities
             SET spots_filled = spots_filled + 1
             WHERE id = %d",
            $this->opportunity_id
        ));

        // Verify increment
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $this->opportunity_id
        ));

        $this->assertEquals($initial_spots + 1, $opportunity->spots_filled, "Spots filled should increment");
    }

    /**
     * Test spots_filled decrement on cancellation
     */
    public function test_spots_filled_decrement() {
        global $wpdb;

        // Create signup and increment spots
        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'confirmed',
            )
        );
        $signup_id = $wpdb->insert_id;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities
             SET spots_filled = spots_filled + 1
             WHERE id = %d",
            $this->opportunity_id
        ));

        // Get current spots_filled
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $this->opportunity_id
        ));
        $current_spots = $opportunity->spots_filled;

        // Cancel signup
        $wpdb->update(
            "{$wpdb->prefix}fs_signups",
            array('status' => 'cancelled'),
            array('id' => $signup_id)
        );

        // Decrement spots_filled
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities
             SET spots_filled = GREATEST(spots_filled - 1, 0)
             WHERE id = %d",
            $this->opportunity_id
        ));

        // Verify decrement
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $this->opportunity_id
        ));

        $this->assertEquals($current_spots - 1, $opportunity->spots_filled, "Spots filled should decrement");
    }

    /**
     * Test full opportunity detection
     */
    public function test_full_opportunity_detection() {
        global $wpdb;

        // Fill opportunity to capacity
        $wpdb->update(
            "{$wpdb->prefix}fs_opportunities",
            array('spots_filled' => 5),
            array('id' => $this->opportunity_id)
        );

        // Check if full
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $this->opportunity_id
        ));

        $is_full = $opportunity->spots_filled >= $opportunity->spots_available;
        $this->assertTrue($is_full, "Opportunity should be detected as full");
    }

    /**
     * Test signup status transitions
     */
    public function test_signup_status_transitions() {
        global $wpdb;

        // Create pending signup
        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'pending',
            )
        );
        $signup_id = $wpdb->insert_id;

        // Confirm signup
        $wpdb->update(
            "{$wpdb->prefix}fs_signups",
            array('status' => 'confirmed'),
            array('id' => $signup_id)
        );

        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));
        $this->assertEquals('confirmed', $signup->status);

        // Mark as no-show
        $wpdb->update(
            "{$wpdb->prefix}fs_signups",
            array('status' => 'no_show'),
            array('id' => $signup_id)
        );

        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));
        $this->assertEquals('no_show', $signup->status);

        // Cancel signup
        $wpdb->update(
            "{$wpdb->prefix}fs_signups",
            array('status' => 'cancelled'),
            array('id' => $signup_id)
        );

        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE id = %d",
            $signup_id
        ));
        $this->assertEquals('cancelled', $signup->status);
    }

    /**
     * Test listing volunteer's signups
     */
    public function test_list_volunteer_signups() {
        global $wpdb;

        // Create multiple signups for volunteer
        for ($i = 1; $i <= 3; $i++) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_opportunities",
                array(
                    'title' => "Event $i",
                    'event_date' => "2026-0$i-15",
                    'program_id' => $this->program_id,
                )
            );
            $opp_id = $wpdb->insert_id;

            $wpdb->insert(
                "{$wpdb->prefix}fs_signups",
                array(
                    'volunteer_id' => $this->volunteer_id,
                    'opportunity_id' => $opp_id,
                    'status' => 'confirmed',
                )
            );
        }

        // Get volunteer's signups
        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE volunteer_id = %d",
            $this->volunteer_id
        ));

        $this->assertCount(3, $signups, "Should find 3 signups for volunteer");
    }

    /**
     * Test listing opportunity's signups
     */
    public function test_list_opportunity_signups() {
        global $wpdb;

        // Create multiple volunteers and sign them up
        for ($i = 1; $i <= 3; $i++) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_volunteers",
                array(
                    'name' => "Volunteer $i",
                    'email' => "vol$i@example.com",
                )
            );
            $vol_id = $wpdb->insert_id;

            $wpdb->insert(
                "{$wpdb->prefix}fs_signups",
                array(
                    'volunteer_id' => $vol_id,
                    'opportunity_id' => $this->opportunity_id,
                    'status' => 'confirmed',
                )
            );
        }

        // Get opportunity's signups
        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE opportunity_id = %d",
            $this->opportunity_id
        ));

        $this->assertCount(3, $signups, "Should find 3 signups for opportunity");
    }

    /**
     * Test cancelled signups don't count toward spots
     */
    public function test_cancelled_signups_dont_count() {
        global $wpdb;

        // Create and cancel signup
        $wpdb->insert(
            "{$wpdb->prefix}fs_signups",
            array(
                'volunteer_id' => $this->volunteer_id,
                'opportunity_id' => $this->opportunity_id,
                'status' => 'cancelled',
            )
        );

        // Count active signups
        $active_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE opportunity_id = %d
             AND status IN ('confirmed', 'pending')",
            $this->opportunity_id
        ));

        $this->assertEquals(0, $active_count, "Cancelled signups should not count toward active signups");
    }

    /**
     * Test filtering signups by status
     */
    public function test_filter_signups_by_status() {
        global $wpdb;

        // Create signups with different statuses
        $wpdb->insert("{$wpdb->prefix}fs_signups", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => $this->opportunity_id, 'status' => 'confirmed'));
        $wpdb->insert("{$wpdb->prefix}fs_signups", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => $this->opportunity_id, 'status' => 'cancelled'));
        $wpdb->insert("{$wpdb->prefix}fs_signups", array('volunteer_id' => $this->volunteer_id, 'opportunity_id' => $this->opportunity_id, 'status' => 'no_show'));

        // Filter confirmed
        $confirmed = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE status = 'confirmed'"
        );

        // Filter cancelled
        $cancelled = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_signups WHERE status = 'cancelled'"
        );

        $this->assertCount(1, $confirmed);
        $this->assertCount(1, $cancelled);
    }
}
