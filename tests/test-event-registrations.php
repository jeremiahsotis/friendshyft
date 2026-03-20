<?php
/**
 * Test teen event registration lifecycle fixes.
 *
 * @package FriendShyft
 */

class Test_Event_Registrations extends WP_UnitTestCase {

    private $program_id;
    private $event_group_id;
    private $opportunity_id;

    public function setUp(): void {
        parent::setUp();
        global $wpdb;

        reset_phpmailer_instance();

        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_waitlist");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_signups");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_event_registrations");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_event_groups");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_opportunities");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_volunteers");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fs_programs");

        $wpdb->insert(
            "{$wpdb->prefix}fs_programs",
            array(
                'name' => 'Teen Program',
                'status' => 'active',
            )
        );
        $this->program_id = (int) $wpdb->insert_id;

        $wpdb->insert(
            "{$wpdb->prefix}fs_event_groups",
            array(
                'title' => 'Youth Service Day',
                'selection_mode' => FS_Event_Groups::SELECTION_SESSIONS_ANY,
                'requires_minor_permission' => 1,
                'minor_age_threshold' => 18,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );
        $this->event_group_id = (int) $wpdb->insert_id;

        $wpdb->insert(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'title' => 'Packing Day',
                'event_date' => '2026-04-15',
                'datetime_start' => '2026-04-15 09:00:00',
                'datetime_end' => '2026-04-15 12:00:00',
                'spots_available' => 3,
                'spots_filled' => 0,
                'program_id' => $this->program_id,
                'event_group_id' => $this->event_group_id,
                'status' => 'Open',
            )
        );
        $this->opportunity_id = (int) $wpdb->insert_id;
    }

    public function test_create_registration_activates_pending_volunteer_and_holds_spot() {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Teen Volunteer',
                'email' => 'teen@example.com',
                'volunteer_status' => 'Pending',
                'created_date' => current_time('mysql'),
            )
        );
        $volunteer_id = (int) $wpdb->insert_id;

        $result = FS_Event_Registrations::create_registration(array(
            'event_group_id' => $this->event_group_id,
            'teen_name' => 'Teen Volunteer',
            'teen_email' => 'teen@example.com',
            'teen_birthdate' => '2010-05-01',
            'guardian_email' => 'guardian@example.com',
            'selected_sessions' => array(FS_Event_Groups::build_session_key($this->opportunity_id, null)),
        ));

        $this->assertIsArray($result);
        $this->assertSame(1, count($result['held_sessions']));
        $this->assertSame(0, count($result['waitlisted_sessions']));

        $volunteer_status = $wpdb->get_var($wpdb->prepare(
            "SELECT volunteer_status
             FROM {$wpdb->prefix}fs_volunteers
             WHERE id = %d",
            $volunteer_id
        ));
        $this->assertSame('Active', $volunteer_status);

        $signup_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status
             FROM {$wpdb->prefix}fs_signups
             WHERE registration_id = %d",
            (int) $result['registration_id']
        ));
        $this->assertSame('pending', $signup_status);
    }

    public function test_signed_permission_promotion_confirms_waitlisted_session_and_repairs_volunteer_status() {
        global $wpdb;

        $wpdb->update(
            "{$wpdb->prefix}fs_opportunities",
            array(
                'spots_available' => 1,
                'spots_filled' => 1,
            ),
            array('id' => $this->opportunity_id)
        );

        $result = FS_Event_Registrations::create_registration(array(
            'event_group_id' => $this->event_group_id,
            'teen_name' => 'Waitlisted Teen',
            'teen_email' => 'waitlisted@example.com',
            'teen_birthdate' => '2011-06-01',
            'guardian_email' => 'guardian@example.com',
            'selected_sessions' => array(FS_Event_Groups::build_session_key($this->opportunity_id, null)),
        ));

        $this->assertIsArray($result);
        $this->assertSame(0, count($result['held_sessions']));
        $this->assertSame(1, count($result['waitlisted_sessions']));

        $registration_id = (int) $result['registration_id'];
        $registration = FS_Event_Registrations::get_registration_with_context($registration_id);
        $this->assertNotEmpty($registration);

        $wpdb->update(
            "{$wpdb->prefix}fs_volunteers",
            array('volunteer_status' => 'Pending'),
            array('id' => (int) $registration->volunteer_id)
        );
        $wpdb->update(
            "{$wpdb->prefix}fs_event_registrations",
            array('permission_status' => FS_Event_Registrations::PERMISSION_SIGNED),
            array('id' => $registration_id)
        );
        $wpdb->update(
            "{$wpdb->prefix}fs_opportunities",
            array('spots_filled' => 0),
            array('id' => $this->opportunity_id)
        );

        $waitlist_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$wpdb->prefix}fs_waitlist
             WHERE registration_id = %d
               AND status = 'waiting'",
            $registration_id
        ));

        $promote_result = FS_Event_Registrations::promote_waitlist_entry($waitlist_id);

        $this->assertIsArray($promote_result);
        $this->assertTrue($promote_result['success']);
        $this->assertSame('confirmed', $promote_result['signup_status']);

        $volunteer_status = $wpdb->get_var($wpdb->prepare(
            "SELECT volunteer_status
             FROM {$wpdb->prefix}fs_volunteers
             WHERE id = %d",
            (int) $registration->volunteer_id
        ));
        $this->assertSame('Active', $volunteer_status);

        $signup_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status
             FROM {$wpdb->prefix}fs_signups
             WHERE registration_id = %d",
            $registration_id
        ));
        $this->assertSame('confirmed', $signup_status);

        $waitlist_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status
             FROM {$wpdb->prefix}fs_waitlist
             WHERE id = %d",
            $waitlist_id
        ));
        $this->assertSame('promoted', $waitlist_status);
    }

    public function test_signed_registration_allows_minor_signup_confirmation() {
        global $wpdb;

        $wpdb->insert(
            "{$wpdb->prefix}fs_volunteers",
            array(
                'name' => 'Confirmed Teen',
                'email' => 'confirmed-teen@example.com',
                'birthdate' => '2010-07-01',
                'volunteer_status' => 'Active',
                'created_date' => current_time('mysql'),
            )
        );
        $volunteer_id = (int) $wpdb->insert_id;

        $wpdb->insert(
            "{$wpdb->prefix}fs_event_registrations",
            array(
                'event_group_id' => $this->event_group_id,
                'volunteer_id' => $volunteer_id,
                'guardian_email' => 'guardian@example.com',
                'status' => FS_Event_Registrations::STATUS_ACTIVE,
                'permission_status' => FS_Event_Registrations::PERMISSION_SIGNED,
                'permission_channel' => FS_Event_Registrations::PERMISSION_CHANNEL_MANUAL,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            )
        );
        $registration_id = (int) $wpdb->insert_id;

        $signup = (object) array(
            'registration_id' => $registration_id,
            'birthdate' => '2010-07-01',
        );

        $gate = FS_Event_Registrations::should_block_confirmation($signup);

        $this->assertFalse($gate['blocked']);
        $this->assertSame('', $gate['message']);
    }
}
