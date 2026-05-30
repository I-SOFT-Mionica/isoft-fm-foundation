<?php
/**
 * Smoke tests for plugin activation: custom tables, CPT, and taxonomy registration.
 */

class ActivationTest extends WP_UnitTestCase {

	public function test_custom_tables_exist(): void {
		global $wpdb;

		foreach ( array( 'isfm_files', 'isfm_download_log', 'isfm_download_daily', 'isfm_licenses' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Table {$table} should exist after activation." );
		}
	}

	public function test_cpt_registered(): void {
		$this->assertTrue( post_type_exists( 'isfm_file' ), 'isfm_file CPT should be registered.' );
	}

	public function test_taxonomies_registered(): void {
		$this->assertTrue( taxonomy_exists( 'isfm_category' ) );
		$this->assertTrue( taxonomy_exists( 'isfm_tag' ) );
	}

	public function test_files_dir_path_is_under_uploads(): void {
		$base = wp_upload_dir()['basedir'];
		$this->assertStringStartsWith( $base, isfm_files_dir() );
		$this->assertStringEndsWith( 'isfm-files', isfm_files_dir() );
	}
}
