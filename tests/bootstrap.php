<?php
/**
 * PHPUnit bootstrap file for FriendShyft plugin tests
 */

// Load Composer dependencies if available
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Get the WordPress tests directory from environment variable or default location
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// If WP test library not found, provide instructions
if (!file_exists($_tests_dir . '/includes/functions.php')) {
    echo "Could not find WordPress test library.\n";
    echo "Please set the WP_TESTS_DIR environment variable.\n";
    echo "Example: export WP_TESTS_DIR=/tmp/wordpress-tests-lib\n";
    echo "\nTo install WordPress tests:\n";
    echo "bash tests/bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";
    exit(1);
}

// Load WordPress test library
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested
 */
function _manually_load_plugin() {
    require dirname(__DIR__) . '/friendshyft.php';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';

// Activate the plugin
activate_plugin('friendshyft/friendshyft.php');

// Run activation hook
friendshyft_activate();

echo "FriendShyft test environment loaded.\n";
