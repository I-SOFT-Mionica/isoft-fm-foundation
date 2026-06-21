<?php
/**
 * ISOFT_FMF_Files_Service tests.
 *
 * Covers the pass-through wrappers, add_external/import error shapes,
 * the browse_category empty-cases, and the path-traversal guard on
 * import_from_disk. The wp_handle_upload pipeline is excluded — it's
 * tightly coupled to PHP $_FILES + the wp-admin filesystem helpers
 * and is covered by manual smoke tests on Local.
 */

class FilesServiceTest extends WP_UnitTestCase {

	private ISOFT_FMF_Files_Service $service;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$this->service = new ISOFT_FMF_Files_Service();
	}

	private function make_download( int $category_id = 0 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
				'post_title'  => 'Test Download',
			)
		);
		if ( $category_id > 0 ) {
			wp_set_object_terms( $post_id, array( $category_id ), 'isoft_fmf_category' );
		}
		return $post_id;
	}

	private function make_category( string $name = 'Cat' ): int {
		$term = wp_insert_term( $name, 'isoft_fmf_category' );
		return (int) $term['term_id'];
	}

	public function test_add_external_creates_row_and_returns_id(): void {
		$download_id = $this->make_download();

		$file_id = $this->service->add_external( $download_id, 'https://example.org/lic', 'My Link' );

		$this->assertIsInt( $file_id );
		$this->assertGreaterThan( 0, $file_id );
		$file = $this->service->get( $file_id );
		$this->assertSame( 'My Link', $file->title );
	}

	public function test_add_external_defaults_title_to_url(): void {
		$download_id = $this->make_download();

		$file_id = $this->service->add_external( $download_id, 'https://example.org/lic' );

		$this->assertSame( 'https://example.org/lic', $this->service->get( $file_id )->title );
	}

	public function test_add_external_returns_wp_error_when_save_fails(): void {
		$result = $this->service->add_external( 0, 'https://example.org/lic' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_external_add_failed', $result->get_error_code() );
	}

	public function test_update_meta_delegates_to_file_manager(): void {
		$download_id = $this->make_download();
		$file_id     = $this->service->add_external( $download_id, 'https://example.org/lic', 'Original' );

		$this->assertTrue( $this->service->update_meta( $file_id, 'Updated', 'A description' ) );
		$this->assertSame( 'Updated', $this->service->get( $file_id )->title );
	}

	public function test_delete_removes_row(): void {
		$download_id = $this->make_download();
		$file_id     = $this->service->add_external( $download_id, 'https://example.org/lic' );

		$this->assertTrue( $this->service->delete( $file_id ) );
		$this->assertNull( $this->service->get( $file_id ) );
	}

	public function test_reorder_writes_positions_with_absint(): void {
		$download_id = $this->make_download();
		$a           = $this->service->add_external( $download_id, 'https://example.org/a' );
		$b           = $this->service->add_external( $download_id, 'https://example.org/b' );

		// Mixed string-keyed input must still work (matches the legacy POST
		// shape where $_POST['order'] is keyed by stringy file_ids).
		$this->service->reorder(
			array(
				(string) $a => '5',
				(string) $b => '1',
			)
		);

		$rows = $this->service->list_for_download( $download_id );
		// Lower sort_order comes first.
		$this->assertSame( $b, (int) $rows[0]->id );
		$this->assertSame( $a, (int) $rows[1]->id );
	}

	public function test_browse_category_returns_empty_for_no_category(): void {
		$download_id = $this->make_download();

		$result = $this->service->browse_category( $download_id );

		$this->assertSame( array(), $result['files'] );
		$this->assertNull( $result['category'] );
	}

	public function test_browse_category_returns_empty_when_folder_missing(): void {
		$category_id = $this->make_category( 'No Folder Cat ' . wp_generate_password( 6, false ) );
		$download_id = $this->make_download( $category_id );

		$result = $this->service->browse_category( $download_id );

		$this->assertSame( array(), $result['files'] );
		$this->assertNotNull( $result['category'] );
	}

	public function test_import_returns_wp_error_for_empty_path(): void {
		$download_id = $this->make_download();

		$result = $this->service->import_from_disk( $download_id, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_no_path', $result->get_error_code() );
	}

	public function test_import_rejects_path_traversal(): void {
		$download_id = $this->make_download();

		$result = $this->service->import_from_disk( $download_id, '../../../etc/passwd' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_import_rejects_nonexistent_path(): void {
		$download_id = $this->make_download();

		$result = $this->service->import_from_disk( $download_id, 'not-a-real/file.bin' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_download_category_id_returns_zero_for_no_category(): void {
		$download_id = $this->make_download();
		$this->assertSame( 0, ISOFT_FMF_Files_Service::download_category_id( $download_id ) );
	}

	public function test_download_category_id_returns_first_assignment(): void {
		$category_id = $this->make_category( 'Primary ' . wp_generate_password( 6, false ) );
		$download_id = $this->make_download( $category_id );

		$this->assertSame( $category_id, ISOFT_FMF_Files_Service::download_category_id( $download_id ) );
	}

	public function test_upload_error_message_returns_specific_strings(): void {
		$this->assertStringContainsString(
			'exceeds',
			ISOFT_FMF_Files_Service::upload_error_message( UPLOAD_ERR_INI_SIZE )
		);
		$this->assertStringContainsString(
			'interrupted',
			ISOFT_FMF_Files_Service::upload_error_message( UPLOAD_ERR_PARTIAL )
		);
		$this->assertStringContainsString(
			'No file',
			ISOFT_FMF_Files_Service::upload_error_message( UPLOAD_ERR_NO_FILE )
		);
	}
}
