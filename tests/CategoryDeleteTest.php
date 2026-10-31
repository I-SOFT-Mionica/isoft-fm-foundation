<?php
/**
 * Deleting a category: refused while it has downloads, subcategories or
 * anything in its folder; otherwise its folder goes with it.
 */
class CategoryDeleteTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $cleanup = array();

	public function tear_down(): void {
		foreach ( array_reverse( $this->cleanup ) as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			} elseif ( is_dir( $path ) ) {
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
			}
		}
		parent::tear_down();
	}

	private function category( int $parent = 0 ): int {
		$id = (int) wp_insert_term( 'Del ' . wp_generate_password( 6, false ), 'isoft_fmf_category', array( 'parent' => $parent ) )['term_id'];
		ISOFT_FMF_Category_Folders::ensure( $id );
		$this->cleanup[] = isoft_fmf_category_fs_path( $id );
		return $id;
	}

	private function put( int $term_id, string $name ): string {
		$path = isoft_fmf_category_fs_path( $term_id ) . '/' . $name;
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $path;
		return $path;
	}

	/** Delete and return the refusal message, or '' when it went through. */
	private function delete( int $term_id ): string {
		try {
			wp_delete_term( $term_id, 'isoft_fmf_category' );
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_an_empty_category_is_deleted_with_its_folder(): void {
		$id     = $this->category();
		$folder = isoft_fmf_category_fs_path( $id );
		$this->assertDirectoryExists( $folder );

		$this->assertSame( '', $this->delete( $id ) );

		$this->assertNull( get_term( $id, 'isoft_fmf_category' ) );
		$this->assertDirectoryDoesNotExist( $folder );
	}

	public function test_system_clutter_does_not_count_and_goes_with_the_folder(): void {
		$id     = $this->category();
		$folder = isoft_fmf_category_fs_path( $id );
		$this->put( $id, 'Thumbs.db' );
		$this->put( $id, '.DS_Store' );

		$this->assertSame( '', $this->delete( $id ) );

		$this->assertDirectoryDoesNotExist( $folder );
	}

	public function test_a_file_in_the_folder_blocks_the_delete(): void {
		$id   = $this->category();
		$file = $this->put( $id, 'synced-from-elsewhere.pdf' );

		$message = $this->delete( $id );

		$this->assertStringContainsString( 'synced-from-elsewhere.pdf', $message );
		$this->assertInstanceOf( WP_Term::class, get_term( $id, 'isoft_fmf_category' ) );
		$this->assertFileExists( $file );
	}

	public function test_a_hidden_partial_transfer_blocks_the_delete(): void {
		$id = $this->category();
		$this->put( $id, '.report.pdf.abc123.partial' );

		$this->assertNotSame( '', $this->delete( $id ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $id, 'isoft_fmf_category' ) );
	}

	public function test_a_subcategory_blocks_the_delete(): void {
		$parent = $this->category();
		$child  = $this->category( $parent );

		$this->assertStringContainsString( 'subcategor', $this->delete( $parent ) );

		$this->assertInstanceOf( WP_Term::class, get_term( $parent, 'isoft_fmf_category' ) );
		$this->assertDirectoryExists( isoft_fmf_category_fs_path( $child ) );
	}

	public function test_a_pending_subcategory_blocks_it_too(): void {
		$parent = $this->category();
		$child  = $this->category( $parent );
		isoft_fmf_set_category_pending( $child, true );
		wp_set_current_user( 0 );

		$this->assertStringContainsString( 'subcategor', $this->delete( $parent ) );
	}

	public function test_downloads_block_the_delete(): void {
		$id       = $this->category();
		$download = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $download, array( $id ), 'isoft_fmf_category' );

		$this->assertStringContainsString( 'download', $this->delete( $id ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $id, 'isoft_fmf_category' ) );
	}

	public function test_files_of_a_trashed_download_still_block_the_delete(): void {
		$id       = $this->category();
		$download = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $download, array( $id ), 'isoft_fmf_category' );
		$this->put( $id, 'trashed.pdf' );
		wp_trash_post( $download );

		$this->assertStringContainsString( 'trashed.pdf', $this->delete( $id ) );
	}
}
