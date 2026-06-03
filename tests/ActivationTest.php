<?php
/**
 * Smoke tests for plugin activation: custom tables, CPT, and taxonomy registration.
 */

class ActivationTest extends WP_UnitTestCase {

	public function test_custom_tables_exist(): void {
		global $wpdb;

		foreach ( array( 'isoft_fmf_files', 'isoft_fmf_download_log', 'isoft_fmf_download_daily', 'isoft_fmf_licenses' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Table {$table} should exist after activation." );
		}
	}

	public function test_cpt_registered(): void {
		$this->assertTrue( post_type_exists( 'isoft_fmf_file' ), 'isoft_fmf_file CPT should be registered.' );
	}

	public function test_taxonomies_registered(): void {
		$this->assertTrue( taxonomy_exists( 'isoft_fmf_category' ) );
		$this->assertTrue( taxonomy_exists( 'isoft_fmf_tag' ) );
	}

	public function test_files_dir_path_is_under_uploads(): void {
		$base = wp_upload_dir()['basedir'];
		$this->assertStringStartsWith( $base, isoft_fmf_files_dir() );
		$this->assertStringEndsWith( 'isoft-fmf-files', isoft_fmf_files_dir() );
	}
}
