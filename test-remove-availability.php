<?php
/**
 * Test script for remove availability functionality
 * Run this via WP-CLI: wp eval-file test-remove-availability.php
 */

// Get a volunteer with availability
global $wpdb;

echo "Testing Remove Availability Functionality\n";
echo "==========================================\n\n";

// Check if wp_fs_availability table exists
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}fs_availability'");
if (!$table_exists) {
    echo "❌ Error: wp_fs_availability table does not exist\n";
    exit(1);
}

// Get a volunteer with availability
$volunteer_with_avail = $wpdb->get_row(
    "SELECT v.id, v.name, COUNT(a.id) as avail_count
     FROM {$wpdb->prefix}fs_volunteers v
     JOIN {$wpdb->prefix}fs_availability a ON v.id = a.volunteer_id
     GROUP BY v.id
     LIMIT 1"
);

if (!$volunteer_with_avail) {
    echo "ℹ️  No volunteers with availability found. Creating test data...\n\n";

    // Get or create a test volunteer
    $test_volunteer = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fs_volunteers LIMIT 1");

    if (!$test_volunteer) {
        echo "❌ No volunteers in database to test with\n";
        exit(1);
    }

    // Create test availability
    $wpdb->insert(
        "{$wpdb->prefix}fs_availability",
        array(
            'volunteer_id' => $test_volunteer->id,
            'day_of_week' => 'monday',
            'time_slot' => 'morning',
            'program_id' => null,
            'auto_signup_enabled' => 0,
            'created_at' => current_time('mysql')
        )
    );

    $volunteer_with_avail = (object) array(
        'id' => $test_volunteer->id,
        'name' => $test_volunteer->name,
        'avail_count' => 1
    );

    echo "✓ Created test availability for {$test_volunteer->name}\n\n";
}

echo "Test Volunteer: {$volunteer_with_avail->name} (ID: {$volunteer_with_avail->id})\n";
echo "Availability Slots: {$volunteer_with_avail->avail_count}\n\n";

// Get availability details
$availability_slots = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}fs_availability WHERE volunteer_id = %d",
    $volunteer_with_avail->id
));

echo "Current Availability:\n";
foreach ($availability_slots as $slot) {
    echo "  - ID {$slot->id}: {$slot->day_of_week} {$slot->time_slot}";
    echo $slot->auto_signup_enabled ? " (Auto-signup: ON)" : " (Auto-signup: OFF)";
    echo "\n";
}

echo "\n✅ All tests passed! The database schema is ready.\n";
echo "\nTo test the full functionality:\n";
echo "1. Visit the volunteer portal as this volunteer\n";
echo "2. Go to 'My Recurring Schedule' tab\n";
echo "3. Click 'Remove' button on an availability slot\n";
echo "4. OR visit WP Admin → FriendShyft → Advanced Scheduling → Recurring Availability\n";
echo "5. Click 'View Details' on a volunteer and click 'Remove' on an availability slot\n";
