<?php
/**
 * Public API for file state: accept changed content as the new baseline,
 * relink a moved file (reusing the Broken Links recovery), and hold a file
 * back as unavailable without deleting it.
 */
class FileStateApiTest extends WP_UnitTestCase {

	private int $category_id;
	private int $download_id;
	private ISOFT_FMF_File_Manager $files;
	/** @var string[] */
	private array $cleanup = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$this->files       = new ISOFT_FMF_File_Manager();
		$this->category_id = $this->category( 'State ' . wp_generate_password( 6, false ) );
		$this->download_id = $this->download( $this->category_id );
	}

	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		delete_option( 'isoft_fmf_unavailable_label' );
		parent::tear_down();
	}

	private function category( string $name, int $parent = 0 ): int {
		$id = (int) wp_insert_term( $name, 'isoft_fmf_category', array( 'parent' => $parent ) )['term_id'];
		ISOFT_FMF_Category_Folders::ensure( $id );
		return $id;
	}

	private function download( int $category_id ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $id, array( $category_id ), 'isoft_fmf_category' );
		return $id;
	}

	/** Attach a file with known content; returns the file ID. */
	private function stored( string $name, string $content, int $download_id = 0 ): int {
		$src = sys_get_temp_dir() . '/isoft-fmf-state-' . wp_generate_password( 8, false ) . '-' . $name;
		file_put_contents( $src, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$id = isoft_fmf_attach_file(
			$download_id ?: $this->download_id,
			$src,
			array(
				'mode'          => 'move',
				'original_name' => $name,
			)
		);
		$this->assertIsInt( $id );
		$this->cleanup[] = $this->abs( $id );
		return $id;
	}

	private function abs( int $file_id ): string {
		return isoft_fmf_files_dir() . '/' . $this->files->get_file( $file_id )->file_path;
	}

	private function overwrite( int $file_id, string $content ): void {
		file_put_contents( $this->abs( $file_id ), $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
	}

	// --- accept changed content -----------------------------------------

	public function test_accepting_changed_content_rewrites_hash_and_size_and_announces_it(): void {
		$id       = $this->stored( 'doc.txt', 'original' );
		$replaced = array();
		add_action(
			'isoft_fmf_file_replaced',
			function ( $file_id, $download_id ) use ( &$replaced ): void {
				$replaced[] = array( $file_id, $download_id );
			},
			10,
			2
		);
		$this->overwrite( $id, 'new content!' );

		$result = isoft_fmf_accept_file_content( $id );

		$this->assertTrue( $result['changed'] );
		$this->assertSame( hash( 'sha256', 'new content!' ), $result['file_hash'] );
		$file = $this->files->get_file( $id );
		$this->assertSame( hash( 'sha256', 'new content!' ), $file->file_hash );
		$this->assertSame( 12, (int) $file->file_size );
		$this->assertSame( array( array( $id, $this->download_id ) ), $replaced );
	}

	public function test_accepting_unchanged_content_is_a_no_op(): void {
		$id    = $this->stored( 'same.txt', 'same' );
		$fired = 0;
		add_action(
			'isoft_fmf_file_replaced',
			function () use ( &$fired ): void {
				++$fired;
			}
		);

		$result = isoft_fmf_accept_file_content( $id );

		$this->assertFalse( $result['changed'] );
		$this->assertSame( 0, $fired );
	}

	public function test_accepting_runs_the_veto_first(): void {
		$id = $this->stored( 'vetoed.txt', 'before' );
		$this->overwrite( $id, 'after' );
		$origin = '';
		add_filter(
			'isoft_fmf_pre_attach_file',
			function ( $allowed, $path, $context ) use ( &$origin ) {
				$origin = $context['origin'];
				return new WP_Error( 'infected', 'No.' );
			},
			10,
			3
		);

		$result = isoft_fmf_accept_file_content( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'accept', $origin );
		$this->assertSame( hash( 'sha256', 'before' ), $this->files->get_file( $id )->file_hash, 'baseline untouched on refusal' );
	}

	public function test_accepting_refuses_unknown_external_and_missing_files(): void {
		$this->assertSame( 'isoft_fmf_file_not_found', isoft_fmf_accept_file_content( 999999 )->get_error_code() );

		$ext = (int) $this->files->add_external_link( $this->download_id, 'https://example.org/x' );
		$this->assertSame( 'isoft_fmf_file_not_found', isoft_fmf_accept_file_content( $ext )->get_error_code() );

		$gone = $this->stored( 'gone.txt', 'x' );
		@unlink( $this->abs( $gone ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Simulate a missing file.
		$this->assertSame( 'isoft_fmf_file_not_on_disk', isoft_fmf_accept_file_content( $gone )->get_error_code() );
	}

	// --- relink ------------------------------------------------------------

	public function test_relink_after_a_rename_in_the_same_folder_updates_the_row(): void {
		$id     = $this->stored( 'before.txt', 'renamed' );
		$folder = isoft_fmf_category_folder_path( $this->category_id );
		rename( $this->abs( $id ), isoft_fmf_files_dir() . '/' . $folder . '/after.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Simulate an out-of-band rename.
		$this->cleanup[] = isoft_fmf_files_dir() . '/' . $folder . '/after.txt';

		$this->assertTrue( isoft_fmf_relink_file( $id, $folder . '/after.txt' ) );

		$file = $this->files->get_file( $id );
		$this->assertSame( $folder . '/after.txt', $file->file_path );
		$this->assertSame( 'after.txt', $file->file_name );
		$this->assertSame( $this->category_id, ISOFT_FMF_Files_Service::download_category_id( $this->download_id ) );
	}

	public function test_relink_into_another_category_moves_the_download_and_its_other_files(): void {
		$first  = $this->stored( 'one.txt', 'one' );
		$second = $this->stored( 'two.txt', 'two' );
		$other  = $this->category( 'Other ' . wp_generate_password( 6, false ) );
		$target = isoft_fmf_category_folder_path( $other ) . '/one.txt';
		rename( $this->abs( $first ), isoft_fmf_files_dir() . '/' . $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Simulate the operator moving the file.
		$this->cleanup[] = isoft_fmf_files_dir() . '/' . $target;

		$this->assertTrue( isoft_fmf_relink_file( $first, $target ) );

		$this->assertSame( $other, ISOFT_FMF_Files_Service::download_category_id( $this->download_id ) );
		$this->assertSame( $target, $this->files->get_file( $first )->file_path );
		$sibling = $this->abs( $second );
		$this->cleanup[] = $sibling;
		$this->assertStringStartsWith( isoft_fmf_files_dir() . '/' . isoft_fmf_category_folder_path( $other ) . '/', $sibling );
		$this->assertFileExists( $sibling, 'the other file followed the download' );
	}

	public function test_relink_to_the_current_path_is_a_no_op(): void {
		$id = $this->stored( 'still.txt', 's' );

		$this->assertTrue( isoft_fmf_relink_file( $id, $this->files->get_file( $id )->file_path ) );
	}

	public function test_relink_refuses_bad_targets(): void {
		$id = $this->stored( 'stay.txt', 'stay' );

		$this->assertSame( 'isoft_fmf_bad_target', isoft_fmf_relink_file( $id, 'nowhere/missing.txt' )->get_error_code() );
		$this->assertSame( 'isoft_fmf_bad_target', isoft_fmf_relink_file( $id, '' )->get_error_code() );
		$this->assertSame( 'isoft_fmf_bad_target', isoft_fmf_relink_file( $id, '../../wp-config.php' )->get_error_code() );
		$this->assertSame( 'isoft_fmf_bad_target', isoft_fmf_relink_file( $id, isoft_fmf_category_folder_path( $this->category_id ) )->get_error_code(), 'a folder is not a file' );
		$this->assertSame( 'isoft_fmf_file_not_found', isoft_fmf_relink_file( 999999, 'x.txt' )->get_error_code() );
	}

	public function test_relink_refuses_a_path_another_row_owns(): void {
		$a = $this->stored( 'a.txt', 'a' );
		$b = $this->stored( 'b.txt', 'b' );

		$result = isoft_fmf_relink_file( $a, $this->files->get_file( $b )->file_path );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_already_registered', $result->get_error_code() );
	}

	public function test_broken_links_reassign_still_finds_and_moves_a_file(): void {
		$id    = $this->stored( 'lost.txt', 'lost content' );
		$other = $this->category( 'Found ' . wp_generate_password( 6, false ) );
		$dest  = isoft_fmf_category_fs_path( $other ) . '/lost.txt';
		rename( $this->abs( $id ), $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Simulate the move.
		$this->cleanup[] = $dest;

		$result = ( new ISOFT_FMF_Broken_Links_Service() )->reassign( $id );

		$this->assertIsArray( $result );
		$this->assertSame( $other, ISOFT_FMF_Files_Service::download_category_id( $this->download_id ) );
	}

	public function test_folder_lookup_used_by_broken_links_matches_the_public_one(): void {
		$parent = $this->category( 'Lookup ' . wp_generate_password( 6, false ) );
		$child  = $this->category( 'Child', $parent );

		$term = ISOFT_FMF_Broken_Links_Service::find_term_by_folder_path( isoft_fmf_category_folder_path( $child ) );

		$this->assertSame( $child, (int) $term->term_id );
		$this->assertNull( ISOFT_FMF_Broken_Links_Service::find_term_by_folder_path( 'no/such/folder' ) );
	}

	// --- unavailable ---------------------------------------------------------

	public function test_unavailable_round_trips_and_announces_changes(): void {
		$id      = $this->stored( 'held.txt', 'held' );
		$changes = array();
		add_action(
			'isoft_fmf_file_availability_changed',
			function ( $file_id, $download_id, $unavailable ) use ( &$changes ): void {
				$changes[] = array( $file_id, $download_id, $unavailable );
			},
			10,
			3
		);

		$this->assertFalse( isoft_fmf_file_is_unavailable( $id ) );
		$this->assertTrue( isoft_fmf_set_file_unavailable( $id, true ) );
		$this->assertTrue( isoft_fmf_file_is_unavailable( $id ) );
		$this->assertTrue( isoft_fmf_set_file_unavailable( $id, true ), 'idempotent' );
		$this->assertTrue( isoft_fmf_set_file_unavailable( $id, false ) );
		$this->assertFalse( isoft_fmf_file_is_unavailable( $id ) );

		$this->assertSame( array( array( $id, $this->download_id, true ), array( $id, $this->download_id, false ) ), $changes );
		$this->assertFalse( isoft_fmf_set_file_unavailable( 999999, true ) );
		$this->assertFalse( isoft_fmf_file_is_unavailable( 999999 ) );
	}

	public function test_label_defaults_and_follows_the_setting(): void {
		$this->assertSame( 'Temporarily unavailable', isoft_fmf_unavailable_label() );

		update_option( 'isoft_fmf_unavailable_label', 'Under review' );
		$this->assertSame( 'Under review', isoft_fmf_unavailable_label() );

		update_option( 'isoft_fmf_unavailable_label', '   ' );
		$this->assertSame( 'Temporarily unavailable', isoft_fmf_unavailable_label() );

		$this->assertContains( 'isoft_fmf_unavailable_label', ISOFT_FMF_Settings_Service::known_keys() );
	}

	public function test_an_unavailable_file_renders_as_a_passive_label(): void {
		$id = $this->stored( 'button.txt', 'b' );
		update_option( 'isoft_fmf_unavailable_label', 'Under review' );
		isoft_fmf_set_file_unavailable( $id, true );

		$html = do_shortcode( '[isoft_fmf_download id="' . $this->download_id . '"]' );

		$this->assertStringContainsString( 'Under review', $html );
		$this->assertStringNotContainsString( 'isoft_fmf_download=' . $id, $html );
	}

	public function test_public_api_marks_it_and_offers_no_link(): void {
		update_option( 'isoft_fmf_public_api_enabled', 1 );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		( new ISOFT_FMF_Access_Control() )->recompute_effective_role( $this->download_id );
		$id = $this->stored( 'api.txt', 'a' );
		isoft_fmf_set_file_unavailable( $id, true );

		$data = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/isoft-fm-foundation/v1/public/downloads/' . $this->download_id ) )->get_data();

		$this->assertTrue( $data['files'][0]['unavailable'] );
		$this->assertNull( $data['files'][0]['download_url'] );

		$wp_rest_server = null;
		delete_option( 'isoft_fmf_public_api_enabled' );
	}
}
