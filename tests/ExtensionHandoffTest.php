<?php
/**
 * Public API that extensions build on: hash lookup, attach_file, the pre-attach
 * veto, paged local-file reads, folder/path lookups, draft dates, and the
 * directory-boundary path guard.
 */
class ExtensionHandoffTest extends WP_UnitTestCase {

	private ISOFT_FMF_Files_Service $service;
	private int $category_id;
	private int $download_id;
	/** @var string[] */
	private array $cleanup = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$this->service = new ISOFT_FMF_Files_Service();

		$this->category_id = (int) wp_insert_term( 'Handoff ' . wp_generate_password( 6, false ), 'isoft_fmf_category' )['term_id'];
		ISOFT_FMF_Category_Folders::ensure( $this->category_id );
		$this->download_id = $this->make_download( $this->category_id );
	}

	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		parent::tear_down();
	}

	private function make_download( int $category_id ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		if ( $category_id ) {
			wp_set_object_terms( $id, array( $category_id ), 'isoft_fmf_category' );
		}
		return $id;
	}

	private function source( string $name, string $content = 'hello' ): string {
		$path = sys_get_temp_dir() . '/isoft-fmf-test-' . wp_generate_password( 8, false ) . '-' . $name;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $path;
		return $path;
	}

	private function folder(): string {
		return isoft_fmf_category_fs_path( $this->category_id );
	}

	private function placed( string $name, string $content = 'inplace' ): string {
		$path = $this->folder() . '/' . $name;
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $path;
		return $path;
	}

	// --- R1 -------------------------------------------------------------

	public function test_hash_lookup_returns_the_file_id_and_zero_for_junk(): void {
		$file_id = isoft_fmf_attach_file( $this->download_id, $this->source( 'a.txt', 'same bytes' ) );
		$this->assertIsInt( $file_id );
		$hash = hash( 'sha256', 'same bytes' );

		$this->assertSame( $file_id, isoft_fmf_file_exists_by_hash( $hash ) );
		$this->assertSame( $file_id, isoft_fmf_file_exists_by_hash( strtoupper( ' ' . $hash . ' ' ) ) );
		$this->assertSame( 0, isoft_fmf_file_exists_by_hash( hash( 'sha256', 'other' ) ) );
		$this->assertSame( 0, isoft_fmf_file_exists_by_hash( 'nothex' ) );
		$this->assertSame( 0, isoft_fmf_file_exists_by_hash( substr( $hash, 1 ) ) );
		$this->assertSame( 0, isoft_fmf_file_exists_by_hash( '' ) );
	}

	// --- R2 -------------------------------------------------------------

	public function test_copy_registers_the_file_and_keeps_the_source(): void {
		$source  = $this->source( 'report.txt', 'copy me' );
		$file_id = isoft_fmf_attach_file( $this->download_id, $source );

		$this->assertIsInt( $file_id );
		$file = $this->service->get( $file_id );
		$this->assertSame( hash( 'sha256', 'copy me' ), $file->file_hash );
		$this->assertSame( 7, (int) $file->file_size );
		$this->assertFileExists( isoft_fmf_files_dir() . '/' . $file->file_path );
		$this->assertFileExists( $source );
		$this->assertSame( $this->download_id, (int) $file->download_id );
	}

	public function test_move_removes_the_source_only_after_success(): void {
		$source  = $this->source( 'moved.txt', 'move me' );
		$file_id = isoft_fmf_attach_file( $this->download_id, $source, array( 'mode' => 'move' ) );

		$this->assertIsInt( $file_id );
		$this->assertFileDoesNotExist( $source );
		$this->assertFileExists( isoft_fmf_files_dir() . '/' . $this->service->get( $file_id )->file_path );
	}

	public function test_failed_move_leaves_the_source_untouched(): void {
		$source = $this->source( 'keep.txt', 'keep me' );
		isoft_fmf_attach_file( $this->download_id, $source );
		// Same name again: collision, so nothing may be moved or deleted.
		$result = isoft_fmf_attach_file( $this->download_id, $source, array( 'mode' => 'move' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_filename_collision', $result->get_error_code() );
		$this->assertFileExists( $source );
	}

	public function test_collision_is_an_error_not_a_silent_rename(): void {
		$first  = $this->source( 'dup.txt', 'one' );
		$second = $this->source( 'dup.txt', 'two' );
		$name   = basename( $first );

		$a = isoft_fmf_attach_file( $this->download_id, $first, array( 'original_name' => 'dup.txt' ) );
		$b = isoft_fmf_attach_file( $this->download_id, $second, array( 'original_name' => 'dup.txt' ) );

		$this->assertIsInt( $a );
		$this->assertWPError( $b );
		$this->assertSame( 'isoft_fmf_filename_collision', $b->get_error_code() );
		$this->assertSame( 409, $b->get_error_data()['status'] );
		$this->assertNotSame( '', $name );
	}

	public function test_download_without_category_is_refused(): void {
		$bare   = $this->make_download( 0 );
		$result = isoft_fmf_attach_file( $bare, $this->source( 'x.txt' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_no_category', $result->get_error_code() );
	}

	public function test_unknown_download_and_bad_mode_and_bad_source_are_refused(): void {
		$this->assertSame( 'isoft_fmf_not_found', isoft_fmf_attach_file( 999999, $this->source( 'x.txt' ) )->get_error_code() );
		$this->assertSame( 'isoft_fmf_bad_mode', isoft_fmf_attach_file( $this->download_id, $this->source( 'y.txt' ), array( 'mode' => 'teleport' ) )->get_error_code() );
		$this->assertSame( 'isoft_fmf_bad_source', isoft_fmf_attach_file( $this->download_id, sys_get_temp_dir() . '/does-not-exist.txt' )->get_error_code() );
		$this->assertSame( 'isoft_fmf_bad_source', isoft_fmf_attach_file( $this->download_id, sys_get_temp_dir() )->get_error_code() );
	}

	public function test_symlinks_are_refused(): void {
		$target = $this->source( 'real.txt' );
		$link   = sys_get_temp_dir() . '/isoft-fmf-test-link-' . wp_generate_password( 6, false ) . '.txt';
		if ( ! @symlink( $target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not every platform allows it.
			$this->markTestSkipped( 'Symlinks are not available here.' );
		}
		$this->cleanup[] = $link;

		$this->assertSame( 'isoft_fmf_bad_source', isoft_fmf_attach_file( $this->download_id, $link )->get_error_code() );
	}

	public function test_in_place_registers_a_file_already_in_the_category_folder(): void {
		$path    = $this->placed( 'already-here.txt', 'inplace' );
		$file_id = isoft_fmf_attach_file( $this->download_id, $path, array( 'mode' => 'in_place' ) );

		$this->assertIsInt( $file_id );
		$this->assertFileExists( $path );
		$this->assertSame( hash( 'sha256', 'inplace' ), $this->service->get( $file_id )->file_hash );
	}

	public function test_in_place_renames_an_unsanitized_name_on_disk(): void {
		$path    = $this->placed( 'Odluka broj 1 (final).TXT', 'renamed' );
		$file_id = isoft_fmf_attach_file( $this->download_id, $path, array( 'mode' => 'in_place' ) );

		$this->assertIsInt( $file_id );
		$file = $this->service->get( $file_id );
		$this->assertSame( isoft_fmf_sanitize_filename( sanitize_file_name( 'Odluka broj 1 (final).TXT' ) )['slug'], $file->file_name );
		$this->assertFileExists( isoft_fmf_files_dir() . '/' . $file->file_path );
		if ( $file->file_name !== basename( $path ) ) {
			$this->assertFileDoesNotExist( $path );
		}
		$this->cleanup[] = isoft_fmf_files_dir() . '/' . $file->file_path;
	}

	public function test_in_place_refuses_a_file_outside_the_category_folder_or_in_a_subfolder(): void {
		$outside = $this->source( 'elsewhere.txt' );
		$this->assertSame( 'isoft_fmf_not_in_category_folder', isoft_fmf_attach_file( $this->download_id, $outside, array( 'mode' => 'in_place' ) )->get_error_code() );

		$sub = $this->folder() . '/sub';
		wp_mkdir_p( $sub );
		$nested = $sub . '/nested.txt';
		file_put_contents( $nested, 'n' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $nested;
		$this->assertSame( 'isoft_fmf_not_in_category_folder', isoft_fmf_attach_file( $this->download_id, $nested, array( 'mode' => 'in_place' ) )->get_error_code() );
	}

	public function test_in_place_refuses_a_file_that_is_already_registered(): void {
		$path = $this->placed( 'once.txt' );
		$this->assertIsInt( isoft_fmf_attach_file( $this->download_id, $path, array( 'mode' => 'in_place' ) ) );

		$again = isoft_fmf_attach_file( $this->download_id, $path, array( 'mode' => 'in_place' ) );

		$this->assertWPError( $again );
		$this->assertSame( 'isoft_fmf_already_registered', $again->get_error_code() );
	}

	public function test_title_defaults_and_can_be_set(): void {
		$a = isoft_fmf_attach_file( $this->download_id, $this->source( 'quarterly-report.txt' ), array( 'original_name' => 'quarterly-report.txt' ) );
		$b = isoft_fmf_attach_file(
			$this->download_id,
			$this->source( 'other.txt' ),
			array(
				'original_name' => 'other.txt',
				'title'         => 'My title',
			)
		);

		$this->assertNotSame( '', $this->service->get( $a )->title );
		$this->assertSame( 'My title', $this->service->get( $b )->title );
	}

	public function test_file_uploaded_action_fires(): void {
		$seen = array();
		add_action(
			'isoft_fmf_file_uploaded',
			function ( $file_id, $download_id ) use ( &$seen ): void {
				$seen[] = array( $file_id, $download_id );
			},
			10,
			2
		);

		$file_id = isoft_fmf_attach_file( $this->download_id, $this->source( 'fires.txt' ) );

		$this->assertSame( array( array( $file_id, $this->download_id ) ), $seen );
	}

	// --- R3 -------------------------------------------------------------

	public function test_veto_refuses_before_anything_is_stored_and_defaults_to_422(): void {
		add_filter( 'isoft_fmf_pre_attach_file', fn() => new WP_Error( 'infected', 'Malware found.' ) );
		$source = $this->source( 'bad.txt' );

		$result = isoft_fmf_attach_file( $this->download_id, $source );

		$this->assertWPError( $result );
		$this->assertSame( 'infected', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertCount( 0, $this->service->list_for_download( $this->download_id ) );
		$this->assertFileDoesNotExist( $this->folder() . '/' . basename( $source ) );
	}

	public function test_veto_keeps_a_status_the_callback_chose(): void {
		add_filter( 'isoft_fmf_pre_attach_file', fn() => new WP_Error( 'blocked', 'No.', array( 'status' => 403 ) ) );

		$this->assertSame( 403, isoft_fmf_attach_file( $this->download_id, $this->source( 'z.txt' ) )->get_error_data()['status'] );
	}

	public function test_veto_receives_path_and_context(): void {
		$captured = array();
		add_filter(
			'isoft_fmf_pre_attach_file',
			function ( $allowed, $path, $context ) use ( &$captured ) {
				$captured = array( $path, $context );
				return $allowed;
			},
			10,
			3
		);
		$source = $this->source( 'ctx.txt' );

		isoft_fmf_attach_file( $this->download_id, $source, array( 'context' => array( 'origin_label' => 'sentinel' ) ) );

		$this->assertSame( realpath( $source ), $captured[0] );
		$this->assertSame( 'attach', $captured[1]['origin'] );
		$this->assertSame( $this->download_id, $captured[1]['download_id'] );
		$this->assertSame( 'sentinel', $captured[1]['origin_label'] );
	}

	public function test_a_callback_that_returns_true_allows_the_file(): void {
		add_filter( 'isoft_fmf_pre_attach_file', '__return_true' );

		$this->assertIsInt( isoft_fmf_attach_file( $this->download_id, $this->source( 'fine.txt' ) ) );
	}

	public function test_a_callback_that_returns_false_refuses_the_file(): void {
		add_filter( 'isoft_fmf_pre_attach_file', '__return_false' );

		$result = isoft_fmf_attach_file( $this->download_id, $this->source( 'no.txt' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_file_refused', $result->get_error_code() );
	}

	public function test_veto_runs_on_the_upload_temp_file_before_it_reaches_public_uploads(): void {
		$tmp      = $this->source( 'upload.txt', 'uploaded' );
		$seen_tmp = '';
		$origin   = '';
		add_filter(
			'isoft_fmf_pre_attach_file',
			function ( $allowed, $path, $context ) use ( &$seen_tmp, &$origin ) {
				$seen_tmp = $path;
				$origin   = $context['origin'];
				return new WP_Error( 'infected', 'Malware found.' );
			},
			10,
			3
		);

		$result = $this->service->upload(
			$this->download_id,
			array(
				'name'     => 'upload.txt',
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => 8,
				'type'     => 'text/plain',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( $tmp, $seen_tmp );
		$this->assertSame( 'upload', $origin );
		$this->assertCount( 0, $this->service->list_for_download( $this->download_id ) );
	}

	public function test_veto_covers_import_from_disk(): void {
		$path = $this->placed( 'import-me.txt' );
		$rel  = isoft_fmf_category_folder_path( $this->category_id ) . '/import-me.txt';
		$seen = '';
		add_filter(
			'isoft_fmf_pre_attach_file',
			function ( $allowed, $p, $context ) use ( &$seen ) {
				$seen = $context['origin'];
				return new WP_Error( 'infected', 'No.' );
			},
			10,
			3
		);

		$result = $this->service->import_from_disk( $this->download_id, $rel );

		$this->assertWPError( $result );
		$this->assertSame( 'import', $seen );
		$this->assertFileExists( $path );
	}

	// --- R4 -------------------------------------------------------------

	public function test_local_files_page_in_id_order_with_a_cursor(): void {
		$ids = array();
		foreach ( array( 'one', 'two', 'three' ) as $name ) {
			$ids[] = isoft_fmf_attach_file( $this->download_id, $this->source( $name . '.txt', $name ), array( 'original_name' => $name . '.txt' ) );
		}
		( new ISOFT_FMF_File_Manager() )->add_external_link( $this->download_id, 'https://example.org/remote' );

		$all = isoft_fmf_get_local_files();
		$this->assertSame( $ids, array_column( $all, 'id' ) );

		$page = isoft_fmf_get_local_files( $ids[0], 1 );
		$this->assertSame( array( $ids[1] ), array_column( $page, 'id' ) );
		$this->assertSame( array(), isoft_fmf_get_local_files( $ids[2] ) );

		$row = $all[0];
		$this->assertSame( array( 'id', 'download_id', 'abs_path', 'file_hash', 'file_size', 'is_missing' ), array_keys( $row ) );
		$this->assertSame( hash( 'sha256', 'one' ), $row['file_hash'] );
		$this->assertStringStartsWith( isoft_fmf_files_dir() . '/', $row['abs_path'] );
		$this->assertFileExists( $row['abs_path'] );
		$this->assertFalse( $row['is_missing'] );
	}

	public function test_local_files_limit_is_clamped(): void {
		isoft_fmf_attach_file( $this->download_id, $this->source( 'a.txt' ), array( 'original_name' => 'a.txt' ) );
		isoft_fmf_attach_file( $this->download_id, $this->source( 'b.txt' ), array( 'original_name' => 'b.txt' ) );

		$this->assertCount( 1, isoft_fmf_get_local_files( 0, 0 ) );
		$this->assertCount( 2, isoft_fmf_get_local_files( 0, 99999 ) );
		$this->assertCount( 2, isoft_fmf_get_local_files( -5 ) );
	}

	// --- R6 -------------------------------------------------------------

	public function test_draft_download_accepts_a_valid_post_date(): void {
		$id   = isoft_fmf_create_draft_download(
			array(
				'title'     => 'Dated',
				'post_date' => '2026-05-12 09:30:00',
			)
		);
		$post = get_post( $id );

		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( '2026-05-12 09:30:00', $post->post_date );
		$this->assertSame( get_gmt_from_date( '2026-05-12 09:30:00' ), $post->post_date_gmt );
	}

	public function test_draft_download_ignores_a_bad_post_date(): void {
		foreach ( array( 'yesterday', '2026-02-31 10:00:00', '2026-05-12', '' ) as $bad ) {
			$id = isoft_fmf_create_draft_download(
				array(
					'title'     => 'Undated ' . $bad,
					'post_date' => $bad,
				)
			);
			$this->assertEqualsWithDelta( time(), get_post_timestamp( $id ), 60, "post_date '{$bad}'" );
		}
	}

	// --- R7 -------------------------------------------------------------

	public function test_parent_menu_slug_is_the_downloads_menu(): void {
		$this->assertSame( 'edit.php?post_type=isoft_fmf_file', isoft_fmf_admin_parent_slug() );
	}

	// --- R8 -------------------------------------------------------------

	public function test_folder_resolves_to_its_category_and_back(): void {
		$parent = (int) wp_insert_term( 'Skupstina', 'isoft_fmf_category' )['term_id'];
		$child  = (int) wp_insert_term( 'Saziv 2025', 'isoft_fmf_category', array( 'parent' => $parent ) )['term_id'];

		$folder = isoft_fmf_category_folder_path( $child );
		$this->assertSame( $child, isoft_fmf_category_for_folder( $folder ) );
		$this->assertSame( $child, isoft_fmf_category_for_folder( '/' . $folder . '/' ) );
		$this->assertSame( $child, isoft_fmf_category_for_folder( str_replace( '/', '\\', $folder ) ) );
		$this->assertSame( $parent, isoft_fmf_category_for_folder( isoft_fmf_category_folder_path( $parent ) ) );
	}

	public function test_unknown_or_hostile_folders_resolve_to_zero(): void {
		foreach ( array( '', '/', 'no-such-folder', 'a/../b', '..', '.', 'a//b' ) as $folder ) {
			$this->assertSame( 0, isoft_fmf_category_for_folder( $folder ), "folder '{$folder}'" );
		}
	}

	public function test_same_slug_under_different_parents_resolves_to_the_right_one(): void {
		$a  = (int) wp_insert_term( 'Alpha', 'isoft_fmf_category' )['term_id'];
		$b  = (int) wp_insert_term( 'Beta', 'isoft_fmf_category' )['term_id'];
		$ac = (int) wp_insert_term( 'Reports', 'isoft_fmf_category', array( 'parent' => $a ) )['term_id'];
		$bc = (int) wp_insert_term( 'Reports', 'isoft_fmf_category', array( 'parent' => $b ) )['term_id'];

		$this->assertSame( $ac, isoft_fmf_category_for_folder( isoft_fmf_category_folder_path( $ac ) ) );
		$this->assertSame( $bc, isoft_fmf_category_for_folder( isoft_fmf_category_folder_path( $bc ) ) );
	}

	public function test_path_lookup_finds_registered_files_exactly(): void {
		$file_id = isoft_fmf_attach_file( $this->download_id, $this->source( 'lookup.txt' ), array( 'original_name' => 'lookup.txt' ) );
		$rel     = $this->service->get( $file_id )->file_path;

		$this->assertSame( $file_id, isoft_fmf_file_for_path( $rel ) );
		$this->assertSame( $file_id, isoft_fmf_file_for_path( '/' . $rel ) );
		$this->assertSame( 0, isoft_fmf_file_for_path( strtoupper( $rel ) ), 'compared byte for byte, not case-insensitively' );
		$this->assertSame( 0, isoft_fmf_file_for_path( 'nope/missing.txt' ) );
		$this->assertSame( 0, isoft_fmf_file_for_path( '' ) );
	}

	// --- path guard -----------------------------------------------------

	public function test_path_guard_is_a_directory_boundary(): void {
		$base = '/var/www/uploads/isoft-fmf-files';

		$this->assertTrue( isoft_fmf_path_within( $base . '/cat/a.pdf', $base ) );
		$this->assertTrue( isoft_fmf_path_within( $base, $base ) );
		$this->assertTrue( isoft_fmf_path_within( $base . '/cat/a.pdf', $base . '/' ) );
		$this->assertFalse( isoft_fmf_path_within( $base . '-backup/a.pdf', $base ) );
		$this->assertFalse( isoft_fmf_path_within( '/var/www/uploads/other/a.pdf', $base ) );
		$this->assertFalse( isoft_fmf_path_within( $base . '/a.pdf', '' ) );
	}

	public function test_import_from_disk_refuses_a_sibling_directory_with_the_same_prefix(): void {
		$base    = realpath( isoft_fmf_files_dir() );
		$sibling = $base . '-sibling';
		wp_mkdir_p( $sibling );
		$file = $sibling . '/escape.txt';
		file_put_contents( $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $file;

		$result = $this->service->import_from_disk( $this->download_id, '../' . basename( $sibling ) . '/escape.txt' );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
		@rmdir( $sibling ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rmdir_rmdir -- Test cleanup.
	}

	// --- contract: docs/PUBLIC-API.md cannot name something that is gone --

	public function test_every_name_in_the_public_api_doc_exists_in_the_code(): void {
		$doc = dirname( __DIR__ ) . '/docs/PUBLIC-API.md';
		if ( ! is_readable( $doc ) ) {
			$this->markTestSkipped( 'docs/PUBLIC-API.md is not part of this checkout.' );
		}
		preg_match_all( '/\bisoft_fmf_[a-z][a-z0-9_]*[a-z0-9]\b/', (string) file_get_contents( $doc ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local doc file.
		$names  = array_unique( $m[0] );
		$source = '';
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
			$source .= file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source.
		}
		$source .= file_get_contents( dirname( __DIR__ ) . '/isoft-fm-foundation.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source.

		$missing = array();
		foreach ( $names as $name ) {
			if ( false === strpos( $source, $name ) ) {
				$missing[] = $name;
			}
		}
		$this->assertSame( array(), $missing, 'Named in docs/PUBLIC-API.md but not found in the code.' );
	}

	public function test_public_functions_exist_with_the_documented_signatures(): void {
		$expected = array(
			'isoft_fmf_file_exists_by_hash'   => array( 'sha256' ),
			'isoft_fmf_attach_file'           => array( 'download_id', 'source_abs', 'args' ),
			'isoft_fmf_get_local_files'       => array( 'after_id', 'limit' ),
			'isoft_fmf_category_for_folder'   => array( 'rel_folder' ),
			'isoft_fmf_file_for_path'         => array( 'rel_path' ),
			'isoft_fmf_set_category_pending'  => array( 'term_id', 'pending' ),
			'isoft_fmf_category_is_pending'   => array( 'term_id' ),
			'isoft_fmf_create_draft_download' => array( 'args' ),
			'isoft_fmf_admin_parent_slug'     => array(),
			'isoft_fmf_accept_file_content'   => array( 'file_id' ),
			'isoft_fmf_relink_file'           => array( 'file_id', 'new_rel_path' ),
			'isoft_fmf_set_file_unavailable'  => array( 'file_id', 'unavailable' ),
			'isoft_fmf_file_is_unavailable'   => array( 'file_id' ),
			'isoft_fmf_unavailable_label'     => array(),
			'isoft_fmf_download_is_public'    => array( 'download_id' ),
			'isoft_fmf_category_is_public'    => array( 'term_id' ),
			'isoft_fmf_public_file'           => array( 'file_id' ),
			'isoft_fmf_features'              => array(),
			'isoft_fmf_has_feature'           => array( 'feature' ),
		);
		foreach ( $expected as $function => $params ) {
			$this->assertTrue( function_exists( $function ), $function );
			$actual = array_map( static fn( ReflectionParameter $p ): string => $p->getName(), ( new ReflectionFunction( $function ) )->getParameters() );
			$this->assertSame( $params, $actual, $function );
		}
	}
}
