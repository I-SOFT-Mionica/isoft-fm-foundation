<?php
/**
 * ISOFT_FMF_Maintenance_Service tests.
 *
 * Covers what's testable without a real filesystem fixture:
 *  - install_demo refuses when downloads already exist
 *  - install_demo runs the install flow on an empty install
 *  - remove_demo always returns removed=true (idempotent)
 *  - clear_bundle_cache deleted-count
 *  - purge_logs delegates to the Download_Logger
 *  - export_csv / export_json render strings with sensible headers/shape
 *  - export_filename produces date-stamped names
 *
 * Integrity scan is left to manual smoke — it scans the real uploads
 * directory and we don't want to pollute it from tests.
 */

class MaintenanceServiceTest extends WP_UnitTestCase {

	private ISOFT_FMF_Maintenance_Service $service;

	public function set_up(): void {
		parent::set_up();
		$this->service = new ISOFT_FMF_Maintenance_Service();
	}

	private function delete_all_downloads(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'isoft_fmf_file',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	public function test_install_demo_refuses_when_content_exists(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
				'post_title'  => 'Pre-existing',
			)
		);

		$result = $this->service->install_demo();

		$this->assertFalse( $result['installed'] );
		$this->assertNotEmpty( $result['reason'] );
	}

	public function test_install_demo_on_empty_install_returns_installed(): void {
		$this->delete_all_downloads();

		$result = $this->service->install_demo();

		$this->assertTrue( $result['installed'] );
		$this->assertTrue( ISOFT_FMF_Demo_Content::has_content() );
	}

	public function test_remove_demo_is_idempotent_on_empty_install(): void {
		$this->delete_all_downloads();

		$result = $this->service->remove_demo();

		$this->assertTrue( $result['removed'] );
	}

	public function test_clear_bundle_cache_returns_deleted_count(): void {
		// Seed the cache directory with two files; clear_bundle_cache should
		// return deleted=2.
		$dir = isoft_fmf_files_dir() . '/.bundle-cache';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/a.zip', 'x' );
		file_put_contents( $dir . '/b.zip', 'y' );

		$result = $this->service->clear_bundle_cache();

		$this->assertSame( 2, $result['deleted'] );
		$this->assertFileDoesNotExist( $dir . '/a.zip' );
	}

	public function test_clear_bundle_cache_with_missing_dir_returns_zero(): void {
		$dir = isoft_fmf_files_dir() . '/.bundle-cache';
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( "{$dir}/*" ) as $f ) {
				wp_delete_file( $f );
			}
			@rmdir( $dir );
		}

		$result = $this->service->clear_bundle_cache();

		$this->assertSame( 0, $result['deleted'] );
	}

	public function test_purge_logs_returns_int_deleted(): void {
		$result = $this->service->purge_logs();
		$this->assertIsInt( $result['deleted'] );
	}

	public function test_export_csv_starts_with_header_row(): void {
		$body = $this->service->export_csv();

		$first_line = strtok( $body, "\n" );
		$this->assertStringContainsString( 'download_id', $first_line );
		$this->assertStringContainsString( 'license_id_at_download', $first_line );
	}

	public function test_export_json_decodes_to_expected_shape(): void {
		$body = $this->service->export_json();
		$data = json_decode( $body, true );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'exported_at', $data );
		$this->assertArrayHasKey( 'rows', $data );
		$this->assertIsArray( $data['rows'] );
	}

	public function test_export_filename_contains_date_and_extension(): void {
		$this->assertStringStartsWith( 'isoft-fmf-log-', $this->service->export_filename( 'csv' ) );
		$this->assertStringEndsWith( '.csv', $this->service->export_filename( 'csv' ) );
		$this->assertStringEndsWith( '.json', $this->service->export_filename( 'json' ) );
	}
}
