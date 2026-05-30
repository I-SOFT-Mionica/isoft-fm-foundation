<?php
/**
 * PHPUnit bootstrap for I-Soft File Manager: Foundation.
 *
 * Loads the wp-phpunit test framework and then our plugin via the
 * muplugins_loaded hook so WP's test installer can set up a clean DB.
 *
 * Configuration:
 *   1. Copy tests/wp-tests-config-sample.php to tests/wp-tests-config.php
 *      and fill in your local test-database credentials.
 *   2. Set WP_PHPUNIT__TESTS_CONFIG to the absolute path of that file, or
 *      let this bootstrap auto-detect it alongside itself.
 */

// Composer autoloader (phpunit, wp-phpunit, etc).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Locate the wp-phpunit framework.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}
if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	fwrite( STDERR, "Could not find wp-phpunit at {$_tests_dir}\n" );
	exit( 1 );
}

// Point wp-phpunit at our local config file if the env var isn't already set.
if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	$local_config = __DIR__ . '/wp-tests-config.php';
	if ( file_exists( $local_config ) ) {
		putenv( "WP_PHPUNIT__TESTS_CONFIG={$local_config}" );
	} else {
		fwrite( STDERR, "Missing tests/wp-tests-config.php — copy the sample and fill in DB credentials.\n" );
		exit( 1 );
	}
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin before WordPress finishes booting so CPTs, taxonomies,
 * and activation hooks are in place for the test suite.
 */
function _isfm_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/isoft-fm-foundation.php';

	// Run activation so custom tables exist in the test DB.
	if ( class_exists( 'ISFM_Activator' ) ) {
		ISFM_Activator::activate();
	}
}
tests_add_filter( 'muplugins_loaded', '_isfm_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
