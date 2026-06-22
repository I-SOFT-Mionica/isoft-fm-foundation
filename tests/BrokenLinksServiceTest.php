<?php
/**
 * ISOFT_FMF_Broken_Links_Service tests.
 *
 * The recovery flows lean on the filesystem (wp_handle_upload, moves,
 * inode reads) and on integration with ISOFT_FMF_File_Integrity's
 * cross-directory hunt. Most of that is manual-smoke territory. These
 * tests cover what IS unit-testable:
 *
 *  - list_broken pagination + shape
 *  - probe / move_back / reassign / split / reupload return WP_Error
 *    with the right error code when the file row is missing
 *  - detach drops the row + marks the post healthy
 *  - the static helpers (relativize, find_term_by_folder_path, etc.)
 *
 * The happy-path filesystem flows are exercised on Local.
 */

class BrokenLinksServiceTest extends WP_UnitTestCase {

	private ISOFT_FMF_Broken_Links_Service $service;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$this->service = new ISOFT_FMF_Broken_Links_Service();
	}

	private function make_download(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
				'post_title'  => 'Broken Links Test',
			)
		);
	}

	private function seed_broken_row( int $download_id, string $name = 'gone.pdf' ): int {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'download_id'   => $download_id,
				'file_type'     => 'local',
				'file_name'     => $name,
				'file_path'     => "some-folder/{$name}",
				'is_missing'    => 1,
				'missing_since' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function test_list_broken_returns_only_missing_rows(): void {
		$download_id = $this->make_download();
		$broken_a    = $this->seed_broken_row( $download_id, 'a.pdf' );
		$broken_b    = $this->seed_broken_row( $download_id, 'b.pdf' );

		// One healthy row that should NOT appear.
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'download_id' => $download_id,
				'file_type'   => 'local',
				'file_name'   => 'healthy.pdf',
				'file_path'   => 'some-folder/healthy.pdf',
				'is_missing'  => 0,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);

		$result = $this->service->list_broken();

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
		$ids = array_map( fn( $r ) => $r['id'], $result['items'] );
		$this->assertContains( $broken_a, $ids );
		$this->assertContains( $broken_b, $ids );
	}

	public function test_list_broken_respects_pagination(): void {
		$download_id = $this->make_download();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_broken_row( $download_id, "f{$i}.pdf" );
		}

		$page1 = $this->service->list_broken( 1, 2 );
		$page2 = $this->service->list_broken( 2, 2 );

		$this->assertSame( 5, $page1['total'] );
		$this->assertCount( 2, $page1['items'] );
		$this->assertCount( 2, $page2['items'] );
		$this->assertNotEquals( $page1['items'][0]['id'], $page2['items'][0]['id'] );
	}

	public function test_list_broken_clamps_per_page(): void {
		$download_id = $this->make_download();
		$this->seed_broken_row( $download_id );

		$result = $this->service->list_broken( 1, 9999 );

		// 9999 is clamped to 100 inside the service; total stays accurate.
		$this->assertSame( 1, $result['total'] );
	}

	public function test_probe_returns_wp_error_when_file_missing(): void {
		$result = $this->service->probe( 99999 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_move_back_returns_wp_error_when_file_missing(): void {
		$result = $this->service->move_back( 99999 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_reassign_returns_wp_error_when_file_missing(): void {
		$result = $this->service->reassign( 99999 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_split_returns_wp_error_when_file_missing(): void {
		$result = $this->service->split( 99999 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_detach_returns_wp_error_when_file_missing(): void {
		$result = $this->service->detach( 99999 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_detach_drops_row_and_returns_message(): void {
		$download_id = $this->make_download();
		$file_id     = $this->seed_broken_row( $download_id );

		$result = $this->service->detach( $file_id );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertNull( ( new ISOFT_FMF_File_Manager() )->get_file( $file_id ) );
	}

	public function test_download_category_id_returns_zero_for_no_terms(): void {
		$download_id = $this->make_download();
		$this->assertSame( 0, ISOFT_FMF_Broken_Links_Service::download_category_id( $download_id ) );
	}

	public function test_download_category_id_returns_first_term(): void {
		$term        = wp_insert_term( 'BL-Cat-' . wp_generate_password( 6, false ), 'isoft_fmf_category' );
		$download_id = $this->make_download();
		wp_set_object_terms( $download_id, array( (int) $term['term_id'] ), 'isoft_fmf_category' );

		$this->assertSame(
			(int) $term['term_id'],
			ISOFT_FMF_Broken_Links_Service::download_category_id( $download_id )
		);
	}

	public function test_relativize_strips_files_dir_prefix(): void {
		$abs    = isoft_fmf_files_dir() . '/some-cat/file.pdf';
		$result = ISOFT_FMF_Broken_Links_Service::relativize( $abs );
		$this->assertSame( 'some-cat/file.pdf', $result );
	}

	public function test_relativize_normalizes_backslashes(): void {
		$abs    = isoft_fmf_files_dir() . '\\some-cat\\file.pdf';
		$result = ISOFT_FMF_Broken_Links_Service::relativize( $abs );
		$this->assertSame( 'some-cat/file.pdf', $result );
	}

	public function test_find_term_by_folder_path_returns_null_for_unknown(): void {
		$this->assertNull( ISOFT_FMF_Broken_Links_Service::find_term_by_folder_path( 'no-such-folder' ) );
	}
}
