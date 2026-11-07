<?php
/**
 * Deleting a download deletes its files (DL-1); a file left behind by an
 * earlier delete can be linked again (DL-2); the category list's quick
 * Delete says why it was refused (DL-3).
 */

class DeleteDownloadFilesTest extends WP_Ajax_UnitTestCase {

	/** @var string[] */
	private array $cleanup = array();

	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		remove_all_filters( 'isoft_fmf_delete_file_from_disk' );
		parent::tear_down();
	}

	private function category(): int {
		$id = (int) wp_insert_term( 'Del ' . wp_generate_password( 6, false ), 'isoft_fmf_category' )['term_id'];
		ISOFT_FMF_Category_Folders::ensure( $id );
		return $id;
	}

	private function download_in( int $category_id ): int {
		$id = (int) isoft_fmf_create_draft_download( array( 'title' => 'Del ' . wp_generate_password( 6, false ) ) );
		wp_set_object_terms( $id, array( $category_id ), 'isoft_fmf_category' );
		return $id;
	}

	/** Attach a small local file; returns [file_id, absolute path]. */
	private function local_file( int $download_id, string $name ): array {
		$src = get_temp_dir() . wp_generate_password( 6, false ) . '-' . $name;
		file_put_contents( $src, 'content of ' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$id = isoft_fmf_attach_file( $download_id, $src, array( 'mode' => 'move' ) );
		$this->assertIsInt( $id );
		$abs             = isoft_fmf_files_dir() . '/' . ( new ISOFT_FMF_File_Manager() )->get_file( $id )->file_path;
		$this->cleanup[] = $abs;
		return array( $id, $abs );
	}

	public function test_deleting_a_download_deletes_its_files(): void {
		$download        = $this->download_in( $this->category() );
		list( $a, $abs ) = $this->local_file( $download, 'personal-data.pdf' );
		$link            = ( new ISOFT_FMF_Files_Service() )->add_external( $download, 'https://example.org/doc' );
		$this->assertFileExists( $abs );

		wp_delete_post( $download, true );

		$this->assertFileDoesNotExist( $abs, 'the stored file is gone' );
		$this->assertNull( ( new ISOFT_FMF_File_Manager() )->get_file( $a ) );
		$this->assertNull( ( new ISOFT_FMF_File_Manager() )->get_file( (int) $link ) );
		$this->assertSame( array(), ( new ISOFT_FMF_File_Manager() )->get_files( $download ) );
	}

	public function test_trashing_a_download_keeps_its_files(): void {
		$download        = $this->download_in( $this->category() );
		list( $a, $abs ) = $this->local_file( $download, 'kept.pdf' );

		wp_trash_post( $download );

		$this->assertFileExists( $abs );
		$this->assertNotNull( ( new ISOFT_FMF_File_Manager() )->get_file( $a ) );
	}

	public function test_deleting_a_download_respects_the_keep_on_disk_filter(): void {
		add_filter( 'isoft_fmf_delete_file_from_disk', '__return_false' );
		$download        = $this->download_in( $this->category() );
		list( $a, $abs ) = $this->local_file( $download, 'mirrored.pdf' );

		wp_delete_post( $download, true );

		$this->assertFileExists( $abs, 'storage owned elsewhere is left alone' );
		$this->assertNull( ( new ISOFT_FMF_File_Manager() )->get_file( $a ) );
	}

	public function test_a_file_left_by_a_deleted_download_can_be_linked_again(): void {
		$cat             = $this->category();
		$old             = $this->download_in( $cat );
		list( $a, $abs ) = $this->local_file( $old, 'left-behind.pdf' );
		$rel             = ( new ISOFT_FMF_File_Manager() )->get_file( $a )->file_path;

		// What deleting a download did before: the post goes, the record stays.
		remove_all_actions( 'before_delete_post' );
		wp_delete_post( $old, true );
		$this->assertNotNull( ( new ISOFT_FMF_File_Manager() )->get_file( $a ) );

		$new = $this->download_in( $cat );
		$id  = ( new ISOFT_FMF_Files_Service() )->import_from_disk( $new, $rel );

		$this->assertIsInt( $id );
		$this->assertNull( ( new ISOFT_FMF_File_Manager() )->get_file( $a ), 'the stale record is dropped' );
		$this->assertSame( $new, (int) ( new ISOFT_FMF_File_Manager() )->get_file( $id )->download_id );

		$this->assertTrue( ( new ISOFT_FMF_Files_Service() )->delete( $id ) );
		$this->assertFileDoesNotExist( $abs, 'and then removed like any other file' );
	}

	public function test_quick_delete_of_a_category_in_use_answers_with_the_reason(): void {
		$this->_setRole( 'administrator' );
		$cat = $this->category();
		list( , $abs ) = $this->local_file( $this->download_in( $cat ), 'still-here.pdf' );

		$_POST['taxonomy']    = 'isoft_fmf_category';
		$_POST['tag_ID']      = (string) $cat;
		$_POST['_ajax_nonce'] = wp_create_nonce( 'delete-tag_' . $cat );
		try {
			$this->_handleAjax( 'delete-tag' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Cannot delete', $response['data']['message'] );
		$this->assertNotFalse( term_exists( $cat, 'isoft_fmf_category' ) );
		$this->assertFileExists( $abs );
	}
}
