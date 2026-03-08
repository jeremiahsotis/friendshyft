<?php
/**
 * Test opportunity CRUD operations
 *
 * @package FriendShyft
 */

class Test_Opportunity_CRUD extends WP_UnitTestCase {

    private $program_id;

    /**
     * Set up before each test
     */
    public function setUp(): void {
        parent::setUp();
        global $wpdb;

        // Clean up tables
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_opportunities");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_programs");

        // Create test program
        $wpdb->insert(
            "{$wpdb->prefix}fs_programs",
            array(
                'name' => 'Test Program',
                'description' => 'Test program for opportunities',
                'status' => 'active',
            )
        );
        $this->program_id = $wpdb->insert_id;
    }

    /**
     * Test creating an opportunity
     */
    public function test_create_opportunity() {
        global $wpdb;

        $opportunity_data = array(
            'title' => 'Food Distribution',
            'description' => 'Help distribute food to families',
            'event_date' => '2026-01-15',
            'event_time_start' => '09:00:00',
            'event_time_end' => '12:00:00',
            'location' => '123 Main St',
            'spots_available' => 5,
            'spots_filled' => 0,
            'program_id' => $this->program_id,
            'status' => 'published',
        );

        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            $opportunity_data
        );

        $opportunity_id = $wpdb->insert_id;

        $this->assertGreaterThan(0, $opportunity_id, "Opportunity should be created with valid ID");

        // Retrieve and verify
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $this->assertEquals('Food Distribution', $opportunity->title);
        $this->assertEquals('2026-01-15', $opportunity->event_date);
        $this->assertEquals(5, $opportunity->spots_available);
        $this->assertEquals(0, $opportunity->spots_filled);
        $this->assertEquals('published', $opportunity->status);
    }

    /**
     * Test reading opportunity by ID
     */
    public function test_read_opportunity_by_id() {
        global $wpdb;

        // Create test opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Test Opportunity',
                'event_date' => '2026-02-01',
                'program_id' => $this->program_id,
            )
        );
        $opportunity_id = $wpdb->insert_id;

        // Read opportunity
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $this->assertNotNull($opportunity, "Opportunity should be found");
        $this->assertEquals('Test Opportunity', $opportunity->title);
    }

    /**
     * Test updating opportunity
     */
    public function test_update_opportunity() {
        global $wpdb;

        // Create test opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Original Title',
                'event_date' => '2026-03-01',
                'spots_available' => 5,
                'program_id' => $this->program_id,
            )
        );
        $opportunity_id = $wpdb->insert_id;

        // Update opportunity
        $wpdb->update(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Updated Title',
                'spots_available' => 10,
            ),
            array('id' => $opportunity_id)
        );

        // Verify update
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $this->assertEquals('Updated Title', $opportunity->title);
        $this->assertEquals(10, $opportunity->spots_available);
    }

    /**
     * Test deleting opportunity
     */
    public function test_delete_opportunity() {
        global $wpdb;

        // Create test opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'To Delete',
                'event_date' => '2026-04-01',
                'program_id' => $this->program_id,
            )
        );
        $opportunity_id = $wpdb->insert_id;

        // Delete opportunity
        $wpdb->delete(
            "{$wpdb->prefix}fs_opportunities",
            array('id' => $opportunity_id)
        );

        // Verify deletion
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $this->assertNull($opportunity, "Opportunity should be deleted");
    }

    /**
     * Test opportunity status changes
     */
    public function test_opportunity_status_changes() {
        global $wpdb;

        // Create opportunity as draft
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Status Test',
                'event_date' => '2026-05-01',
                'program_id' => $this->program_id,
                'status' => 'draft',
            )
        );
        $opportunity_id = $wpdb->insert_id;

        // Publish opportunity
        $wpdb->update(
            "{$wpdb->prefix}fs_opportunities",
            array('status' => 'published'),
            array('id' => $opportunity_id)
        );

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));
        $this->assertEquals('published', $opportunity->status);

        // Cancel opportunity
        $wpdb->update(
            "{$wpdb->prefix}fs_opportunities",
            array('status' => 'cancelled'),
            array('id' => $opportunity_id)
        );

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));
        $this->assertEquals('cancelled', $opportunity->status);
    }

    /**
     * Test listing upcoming opportunities
     */
    public function test_list_upcoming_opportunities() {
        global $wpdb;

        // Create past opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Past Event',
                'event_date' => '2020-01-01',
                'program_id' => $this->program_id,
                'status' => 'published',
            )
        );

        // Create future opportunities
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Future Event 1',
                'event_date' => '2026-06-01',
                'program_id' => $this->program_id,
                'status' => 'published',
            )
        );
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Future Event 2',
                'event_date' => '2026-07-01',
                'program_id' => $this->program_id,
                'status' => 'published',
            )
        );

        // Get upcoming opportunities
        $upcoming = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date >= CURDATE()
             AND status = 'published'
             ORDER BY event_date ASC"
        );

        $this->assertCount(2, $upcoming, "Should find 2 upcoming opportunities");
        $this->assertEquals('Future Event 1', $upcoming[0]->title);
    }

    /**
     * Test filtering opportunities by program
     */
    public function test_filter_opportunities_by_program() {
        global $wpdb;

        // Create second program
        $wpdb->insert(
            "{$wpdb->prefix}fs_programs",
            array('name' => 'Program 2', 'status' => 'active')
        );
        $program_2_id = $wpdb->insert_id;

        // Create opportunities for different programs
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Program 1 Event',
                'event_date' => '2026-08-01',
                'program_id' => $this->program_id,
            )
        );
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Program 2 Event',
                'event_date' => '2026-08-02',
                'program_id' => $program_2_id,
            )
        );

        // Filter by program
        $program_1_opps = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE program_id = %d",
            $this->program_id
        ));

        $program_2_opps = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE program_id = %d",
            $program_2_id
        ));

        $this->assertCount(1, $program_1_opps);
        $this->assertCount(1, $program_2_opps);
    }

    /**
     * Test checking available spots
     */
    public function test_check_available_spots() {
        global $wpdb;

        // Create opportunity with spots
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Limited Spots',
                'event_date' => '2026-09-01',
                'spots_available' => 5,
                'spots_filled' => 3,
                'program_id' => $this->program_id,
            )
        );
        $opportunity_id = $wpdb->insert_id;

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $spots_remaining = $opportunity->spots_available - $opportunity->spots_filled;
        $this->assertEquals(2, $spots_remaining, "Should have 2 spots remaining");
    }

    /**
     * Test opportunity is full
     */
    public function test_opportunity_is_full() {
        global $wpdb;

        // Create full opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Full Event',
                'event_date' => '2026-10-01',
                'spots_available' => 5,
                'spots_filled' => 5,
                'program_id' => $this->program_id,
            )
        );
        $opportunity_id = $wpdb->insert_id;

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $is_full = $opportunity->spots_filled >= $opportunity->spots_available;
        $this->assertTrue($is_full, "Opportunity should be full");
    }

    /**
     * Test incrementing spots_filled
     */
    public function test_increment_spots_filled() {
        global $wpdb;

        // Create opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Increment Test',
                'event_date' => '2026-11-01',
                'spots_available' => 10,
                'spots_filled' => 3,
                'program_id' => $this->program_id,
            )
        );
        $opportunity_id = $wpdb->insert_id;

        // Increment spots_filled
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities
             SET spots_filled = spots_filled + 1
             WHERE id = %d",
            $opportunity_id
        ));

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $this->assertEquals(4, $opportunity->spots_filled, "Spots filled should increment by 1");
    }

    /**
     * Test decrementing spots_filled
     */
    public function test_decrement_spots_filled() {
        global $wpdb;

        // Create opportunity
        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Decrement Test',
                'event_date' => '2026-12-01',
                'spots_available' => 10,
                'spots_filled' => 5,
                'program_id' => $this->program_id,
            )
        );
        $opportunity_id = $wpdb->insert_id;

        // Decrement spots_filled
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fs_opportunities
             SET spots_filled = GREATEST(spots_filled - 1, 0)
             WHERE id = %d",
            $opportunity_id
        ));

        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));

        $this->assertEquals(4, $opportunity->spots_filled, "Spots filled should decrement by 1");
    }

    /**
     * Test searching opportunities by title
     */
    public function test_search_opportunities_by_title() {
        global $wpdb;

        // Create test opportunities
        $wpdb->insert("{$wpdb->prefix}fs_opportunities", array('title' => 'Food Bank Distribution', 'event_date' => '2026-01-01', 'program_id' => $this->program_id));
        $wpdb->insert("{$wpdb->prefix}fs_opportunities", array('title' => 'Park Cleanup', 'event_date' => '2026-01-02', 'program_id' => $this->program_id));
        $wpdb->insert("{$wpdb->prefix}fs_opportunities", array('title' => 'Food Drive', 'event_date' => '2026-01-03', 'program_id' => $this->program_id));

        // Search for "food"
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE title LIKE %s",
            '%food%'
        ));

        $this->assertCount(2, $results, "Should find 2 opportunities with 'food' in title");
    }
}
