<?php
/**
 * Sample wp-phpunit config.
 *
 * Copy this file to tests/wp-tests-config.php and edit the DB credentials
 * to point at a throwaway local database — the test suite drops and
 * recreates tables on every run, so NEVER point it at a real site.
 */

// Path to the WordPress codebase under test. wp-phpunit ships its own.
define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/wordpress/' );

// Test with WP_DEBUG on.
define( 'WP_DEBUG', true );

// Test database — MUST be a disposable DB, tables will be wiped.
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'I-Soft File Manager: Foundation Test Suite' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
