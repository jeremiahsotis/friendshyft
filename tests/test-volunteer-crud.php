<?php
/**
 * Test volunteer CRUD operations
 *
 * @package FriendShyft
 */

class Test_Volunteer_CRUD extends WP_UnitTestCase {

    /**
     * Set up before each test
     */
    public function setUp(): void {
        parent::setUp();
        global $wpdb;
        // Clean up volunteers table
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_volunteers");
    }

    /**
     * Test creating a volunteer
     */
    public function test_create_volunteer() {
        global $wpdb;

        $volunteer_data = array(
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '555-1234',
            'date_of_birth' => '1990-01-01',
            'volunteer_status' => 'active',
        );

        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            $volunteer_data
        );

        $volunteer_id = $wpdb->insert_id;

        $this->assertGreaterThan(0, $volunteer_id, "Volunteer should be created with valid ID");

        // Retrieve and verify
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $this->assertEquals('John Doe', $volunteer->name);
        $this->assertEquals('john@example.com', $volunteer->email);
        $this->assertEquals('555-1234', $volunteer->phone);
        $this->assertEquals('1990-01-01', $volunteer->date_of_birth);
        $this->assertEquals('active', $volunteer->volunteer_status);
    }

    /**
     * Test access token generation
     */
    public function test_access_token_generation() {
        global $wpdb;

        $access_token = bin2hex(random_bytes(32));

        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'access_token' => $access_token,
            )
        );

        $volunteer_id = $wpdb->insert_id;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $this->assertEquals(64, strlen($volunteer->access_token), "Access token should be 64 characters");
        $this->assertEquals($access_token, $volunteer->access_token);
    }

    /**
     * Test PIN generation
     */
    public function test_pin_generation() {
        global $wpdb;

        $pin = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Bob Wilson',
                'email' => 'bob@example.com',
                'pin' => $pin,
            )
        );

        $volunteer_id = $wpdb->insert_id;
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $this->assertEquals(4, strlen($volunteer->pin), "PIN should be 4 digits");
        $this->assertMatchesRegularExpression('/^\d{4}$/', $volunteer->pin, "PIN should be numeric");
    }

    /**
     * Test reading volunteer by ID
     */
    public function test_read_volunteer_by_id() {
        global $wpdb;

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Test User',
                'email' => 'test@example.com',
            )
        );
        $volunteer_id = $wpdb->insert_id;

        // Read volunteer
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $this->assertNotNull($volunteer, "Volunteer should be found");
        $this->assertEquals('Test User', $volunteer->name);
    }

    /**
     * Test reading volunteer by email
     */
    public function test_read_volunteer_by_email() {
        global $wpdb;

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Email Test',
                'email' => 'email-test@example.com',
            )
        );

        // Read volunteer by email
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE email = %s",
            'email-test@example.com'
        ));

        $this->assertNotNull($volunteer, "Volunteer should be found by email");
        $this->assertEquals('Email Test', $volunteer->name);
    }

    /**
     * Test reading volunteer by access token
     */
    public function test_read_volunteer_by_token() {
        global $wpdb;

        $access_token = bin2hex(random_bytes(32));

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Token Test',
                'email' => 'token@example.com',
                'access_token' => $access_token,
            )
        );

        // Read volunteer by token
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
            $access_token
        ));

        $this->assertNotNull($volunteer, "Volunteer should be found by access token");
        $this->assertEquals('Token Test', $volunteer->name);
    }

    /**
     * Test updating volunteer
     */
    public function test_update_volunteer() {
        global $wpdb;

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Original Name',
                'email' => 'original@example.com',
                'phone' => '555-0000',
            )
        );
        $volunteer_id = $wpdb->insert_id;

        // Update volunteer
        $wpdb->update(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Updated Name',
                'phone' => '555-9999',
            ),
            array('id' => $volunteer_id)
        );

        // Verify update
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $this->assertEquals('Updated Name', $volunteer->name);
        $this->assertEquals('555-9999', $volunteer->phone);
        $this->assertEquals('original@example.com', $volunteer->email, "Email should remain unchanged");
    }

    /**
     * Test deleting volunteer
     */
    public function test_delete_volunteer() {
        global $wpdb;

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'To Delete',
                'email' => 'delete@example.com',
            )
        );
        $volunteer_id = $wpdb->insert_id;

        // Delete volunteer
        $wpdb->delete(
            "{$wpdb->prefix}fs_volunteers",
            array('id' => $volunteer_id)
        );

        // Verify deletion
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $this->assertNull($volunteer, "Volunteer should be deleted");
    }

    /**
     * Test deactivating volunteer (soft delete)
     */
    public function test_deactivate_volunteer() {
        global $wpdb;

        // Create test volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Active User',
                'email' => 'active@example.com',
                'volunteer_status' => 'active',
            )
        );
        $volunteer_id = $wpdb->insert_id;

        // Deactivate volunteer
        $wpdb->update(
            "{$wpdb->prefix}fs_volunteers",
            array('volunteer_status' => 'inactive'),
            array('id' => $volunteer_id)
        );

        // Verify status
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));

        $this->assertEquals('inactive', $volunteer->volunteer_status);
    }

    /**
     * Test duplicate email detection
     */
    public function test_duplicate_email_detection() {
        global $wpdb;

        // Create first volunteer
        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'First User',
                'email' => 'duplicate@example.com',
            )
        );

        // Check for duplicate
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteers WHERE email = %s",
            'duplicate@example.com'
        ));

        $this->assertEquals(1, $existing, "Duplicate check should find existing email");
    }

    /**
     * Test listing volunteers with pagination
     */
    public function test_list_volunteers_pagination() {
        global $wpdb;

        // Create 25 test volunteers
        for ($i = 1; $i <= 25; $i++) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_volunteers",
                array(
                    'name' => "Volunteer $i",
                    'email' => "volunteer$i@example.com",
                )
            );
        }

        // Get first page (10 results)
        $page_1 = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers ORDER BY id ASC LIMIT 10 OFFSET 0"
        );

        // Get second page
        $page_2 = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers ORDER BY id ASC LIMIT 10 OFFSET 10"
        );

        $this->assertCount(10, $page_1, "First page should have 10 results");
        $this->assertCount(10, $page_2, "Second page should have 10 results");
        $this->assertNotEquals($page_1[0]->id, $page_2[0]->id, "Pages should have different volunteers");
    }

    /**
     * Test searching volunteers by name
     */
    public function test_search_volunteers_by_name() {
        global $wpdb;

        // Create test volunteers
        $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => 'John Smith', 'email' => 'john@example.com'));
        $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => 'Jane Doe', 'email' => 'jane@example.com'));
        $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => 'Bob Johnson', 'email' => 'bob@example.com'));

        // Search for "john"
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE name LIKE %s",
            '%john%'
        ));

        $this->assertCount(2, $results, "Should find 2 volunteers with 'john' in name");
    }

    /**
     * Test filtering volunteers by status
     */
    public function test_filter_volunteers_by_status() {
        global $wpdb;

        // Create test volunteers
        $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => 'Active 1', 'email' => 'a1@example.com', 'volunteer_status' => 'active'));
        $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => 'Active 2', 'email' => 'a2@example.com', 'volunteer_status' => 'active'));
        $wpdb->insert("{$wpdb->prefix}fs_volunteers", array('name' => 'Inactive 1', 'email' => 'i1@example.com', 'volunteer_status' => 'inactive'));

        // Filter active volunteers
        $active = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE volunteer_status = 'active'"
        );

        // Filter inactive volunteers
        $inactive = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE volunteer_status = 'inactive'"
        );

        $this->assertCount(2, $active, "Should find 2 active volunteers");
        $this->assertCount(1, $inactive, "Should find 1 inactive volunteer");
    }
}
