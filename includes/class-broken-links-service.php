<?php
/**
 * Pure data + orchestration layer for the Broken Links recovery flows.
 *
 * Six operations extracted from class-broken-links-ajax.php:
 *   - probe()       — collect everything the recovery dialog needs
 *   - move_back()   — move a cross-category file back to the expected path
 *   - reassign()    — reassign the download (and all its files) to the new category
 *   - split()       — detach the file into a new draft download in its new category
 *   - reupload()    — accept a replacement upload and update the DB row
 *   - detach()      — drop the isoft_fmf_files row (file on disk untouched)
 *
 * Plus a list_broken() reader for the REST GET /broken-links route.
 *
 * Each operation returns an associative result array on success or a
 * WP_Error with HTTP-status hint in error_data on failure. No
 * superglobals, no wp_send_json_* — that's the AJAX/REST layer's job.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Broken_Links_Service {

	private ISOFT_FMF_File_Manager $files;

	public function __construct() {
		$this->files = new ISOFT_FMF_File_Manager();
	}

	// ---------------------------------------------------------------------
	// Read
	// ---------------------------------------------------------------------

	/**
	 * Paginated list of currently broken files.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int}
	 */
	public function list_broken( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin list; freshness > cache.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files WHERE is_missing = 1" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, download_id, file_name, file_path, file_type, missing_since
				   FROM {$wpdb->prefix}isoft_fmf_files
				  WHERE is_missing = 1
				  ORDER BY missing_since DESC, id DESC
				  LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		) ?: array();

		$items = array_map(
			static function ( $r ): array {
				return array(
					'id'             => (int) $r->id,
					'download_id'    => (int) $r->download_id,
					'download_title' => get_the_title( (int) $r->download_id ),
					'file_name'      => (string) $r->file_name,
					'file_path'      => (string) $r->file_path,
					'file_type'      => (string) $r->file_type,
					'missing_since'  => $r->missing_since,
				);
			},
			$rows
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	// ---------------------------------------------------------------------
	// Recovery operations
	// ---------------------------------------------------------------------

	public function probe( int $file_id ): array|WP_Error {
		$file = $this->files->get_file( $file_id );
		if ( ! $file ) {
			return self::not_found();
		}

		$download_id  = (int) $file->download_id;
		$expected_cat = self::download_category_id( $download_id );
		$expected_dir = $expected_cat ? isoft_fmf_category_folder_path( $expected_cat ) : '';

		$candidate     = ISOFT_FMF_File_Integrity::find_anywhere( $file );
		$candidate_rel = null;
		$candidate_cat = null;
		if ( $candidate ) {
			$candidate_rel = self::relativize( $candidate );
			$candidate_cat = dirname( $candidate_rel );
			if ( '.' === $candidate_cat ) {
				$candidate_cat = '';
			}
		}

		return array(
			'file_id'          => $file_id,
			'file_name'        => $file->file_name,
			'download_id'      => $download_id,
			'download_title'   => get_the_title( $download_id ),
			'expected_folder'  => $expected_dir,
			'candidate_found'  => (bool) $candidate,
			'candidate_folder' => $candidate_cat,
			'is_cross_cat'     => $candidate && $candidate_cat !== $expected_dir,
		);
	}

	public function move_back( int $file_id ): array|WP_Error {
		$file = $this->files->get_file( $file_id );
		if ( ! $file ) {
			return self::not_found();
		}

		$candidate = ISOFT_FMF_File_Integrity::find_anywhere( $file );
		if ( ! $candidate ) {
			return new WP_Error(
				'isoft_fmf_no_candidate',
				__( 'Could not locate the file on disk.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}

		$expected_cat = self::download_category_id( (int) $file->download_id );
		if ( ! $expected_cat ) {
			return new WP_Error(
				'isoft_fmf_no_category',
				__( 'This download has no category — cannot determine target folder.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		$target_dir = isoft_fmf_category_fs_path( $expected_cat );
		if ( ! is_dir( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}
		$target_abs = $target_dir . '/' . basename( $candidate );

		if ( file_exists( $target_abs ) ) {
			return new WP_Error(
				'isoft_fmf_target_exists',
				__( 'A file with this name already exists at the expected path.', 'isoft-fm-foundation' ),
				array( 'status' => 409 )
			);
		}

		if ( ! self::wp_fs()->move( $candidate, $target_abs, false ) ) {
			return new WP_Error(
				'isoft_fmf_move_failed',
				__( 'Filesystem move failed. Check directory permissions.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		$new_rel = self::relativize( $target_abs );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$wpdb->update(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'file_path' => $new_rel,
				'file_name' => basename( $target_abs ),
			),
			array( 'id' => $file_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		self::refresh_inode( $file_id, $target_abs );
		self::mark_healthy( $file_id, (int) $file->download_id );

		return array( 'message' => __( 'File moved back to the expected folder.', 'isoft-fm-foundation' ) );
	}

	public function reassign( int $file_id ): array|WP_Error {
		$file = $this->files->get_file( $file_id );
		if ( ! $file ) {
			return self::not_found();
		}

		$download_id = (int) $file->download_id;

		$candidate = ISOFT_FMF_File_Integrity::find_anywhere( $file );
		if ( ! $candidate ) {
			return new WP_Error(
				'isoft_fmf_no_candidate',
				__( 'Could not locate the file on disk.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}
		$candidate_rel = self::relativize( $candidate );
		$new_cat_path  = dirname( $candidate_rel );

		$new_term = self::find_term_by_folder_path( $new_cat_path );
		if ( ! $new_term ) {
			return new WP_Error(
				'isoft_fmf_no_term_for_folder',
				sprintf(
					/* translators: %s: folder path */
					__( 'No isoft_fmf_category term matches the folder "%s". Create the category first, then retry.', 'isoft-fm-foundation' ),
					$new_cat_path
				),
				array( 'status' => 400 )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same.
		$others_broken = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files
				  WHERE download_id = %d AND file_type = 'local' AND is_missing = 1 AND id <> %d",
				$download_id,
				$file_id
			)
		);
		if ( $others_broken > 0 ) {
			return new WP_Error(
				'isoft_fmf_other_broken',
				__( 'Other files on this download are also flagged missing. Resolve those first, then retry reassign.', 'isoft-fm-foundation' ),
				array( 'status' => 409 )
			);
		}

		$new_dir = isoft_fmf_category_fs_path( $new_term->term_id );
		if ( ! is_dir( $new_dir ) ) {
			wp_mkdir_p( $new_dir );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same.
		$siblings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}isoft_fmf_files
				  WHERE download_id = %d AND file_type = 'local' AND id <> %d",
				$download_id,
				$file_id
			)
		) ?: array();

		foreach ( $siblings as $sib ) {
			$old_abs = isoft_fmf_files_dir() . '/' . $sib->file_path;
			if ( ! is_readable( $old_abs ) ) {
				continue;
			}
			$new_abs = $new_dir . '/' . basename( $old_abs );
			if ( file_exists( $new_abs ) ) {
				return new WP_Error(
					'isoft_fmf_target_exists',
					sprintf(
						/* translators: %s: file name */
						__( 'Cannot reassign — a file named "%s" already exists in the target category folder.', 'isoft-fm-foundation' ),
						basename( $old_abs )
					),
					array( 'status' => 409 )
				);
			}
			if ( ! self::wp_fs()->move( $old_abs, $new_abs, false ) ) {
				return new WP_Error(
					'isoft_fmf_move_failed',
					__( 'Filesystem move failed during sibling move. No changes committed to the DB.', 'isoft-fm-foundation' ),
					array( 'status' => 500 )
				);
			}
			$new_rel = self::relativize( $new_abs );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
			$wpdb->update(
				"{$wpdb->prefix}isoft_fmf_files",
				array( 'file_path' => $new_rel ),
				array( 'id' => (int) $sib->id ),
				array( '%s' ),
				array( '%d' )
			);
			self::refresh_inode( (int) $sib->id, $new_abs );
			ISOFT_FMF_File_Manager::bust_cache_for( $download_id, (int) $sib->id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same.
		$wpdb->update(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'file_path' => $candidate_rel,
				'file_name' => basename( $candidate ),
			),
			array( 'id' => $file_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		self::refresh_inode( $file_id, $candidate );

		wp_set_object_terms( $download_id, array( (int) $new_term->term_id ), 'isoft_fmf_category', false );
		self::mark_healthy( $file_id, $download_id );

		return array(
			'message' => sprintf(
				/* translators: %s: category name */
				__( 'Download reassigned to "%s".', 'isoft-fm-foundation' ),
				$new_term->name
			),
		);
	}

	public function split( int $file_id ): array|WP_Error {
		$file = $this->files->get_file( $file_id );
		if ( ! $file ) {
			return self::not_found();
		}

		$candidate = ISOFT_FMF_File_Integrity::find_anywhere( $file );
		if ( ! $candidate ) {
			return new WP_Error(
				'isoft_fmf_no_candidate',
				__( 'Could not locate the file on disk.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}
		$candidate_rel = self::relativize( $candidate );
		$new_cat_path  = dirname( $candidate_rel );
		$new_term      = self::find_term_by_folder_path( $new_cat_path );
		if ( ! $new_term ) {
			return new WP_Error(
				'isoft_fmf_no_term_for_folder',
				sprintf(
					/* translators: %s: folder path */
					__( 'No isoft_fmf_category term matches the folder "%s". Create the category first, then retry.', 'isoft-fm-foundation' ),
					$new_cat_path
				),
				array( 'status' => 400 )
			);
		}

		$old_download_id = (int) $file->download_id;
		$new_post_id     = isoft_fmf_create_draft_download(
			array(
				'title'       => $file->title ?: $file->file_name,
				'description' => sprintf(
					/* translators: %s: original download title */
					__( 'Split from "%s" on the Broken Links screen.', 'isoft-fm-foundation' ),
					get_the_title( $old_download_id )
				),
				'category_id' => (int) $new_term->term_id,
			)
		);
		if ( ! $new_post_id ) {
			return new WP_Error(
				'isoft_fmf_draft_failed',
				__( 'Failed to create the new draft download.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$wpdb->update(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'download_id' => $new_post_id,
				'file_path'   => $candidate_rel,
				'file_name'   => basename( $candidate ),
			),
			array( 'id' => $file_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		self::refresh_inode( $file_id, $candidate );
		ISOFT_FMF_File_Manager::bust_cache_for( $old_download_id );
		self::mark_healthy( $file_id, $new_post_id );

		return array(
			'message'     => sprintf(
				/* translators: %s: new post title */
				__( 'Created new draft download "%s".', 'isoft-fm-foundation' ),
				get_the_title( $new_post_id )
			),
			'new_post_id' => $new_post_id,
			'edit_url'    => get_edit_post_link( $new_post_id, 'raw' ),
		);
	}

	/**
	 * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $uploaded_file Raw $_FILES['replacement'] entry.
	 */
	public function reupload( int $file_id, array $uploaded_file ): array|WP_Error {
		$file = $this->files->get_file( $file_id );
		if ( ! $file ) {
			return self::not_found();
		}

		$expected_cat = self::download_category_id( (int) $file->download_id );
		if ( ! $expected_cat ) {
			return new WP_Error(
				'isoft_fmf_no_category',
				__( 'This download has no category.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}
		$target_dir = isoft_fmf_category_fs_path( $expected_cat );
		if ( ! is_dir( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}
		$target_abs = $target_dir . '/' . basename( (string) $file->file_name );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload(
			$uploaded_file,
			array(
				'test_form' => false,
				'action'    => 'isoft_fmf_recover_reupload',
			)
		);
		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'isoft_fmf_handle_upload', $upload['error'], array( 'status' => 500 ) );
		}
		if ( file_exists( $target_abs ) ) {
			wp_delete_file( $target_abs );
		}
		if ( ! self::wp_fs()->move( $upload['file'], $target_abs, true ) ) {
			return new WP_Error(
				'isoft_fmf_move_failed',
				__( 'Failed to write the uploaded file to the expected path.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		$new_hash = hash_file( 'sha256', $target_abs ) ?: '';
		$new_size = (int) filesize( $target_abs );
		$new_rel  = self::relativize( $target_abs );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$wpdb->update(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'file_path' => $new_rel,
				'file_size' => $new_size,
				'file_hash' => $new_hash,
			),
			array( 'id' => $file_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		self::refresh_inode( $file_id, $target_abs );
		self::mark_healthy( $file_id, (int) $file->download_id );

		return array( 'message' => __( 'File reuploaded and relinked.', 'isoft-fm-foundation' ) );
	}

	public function detach( int $file_id ): array|WP_Error {
		$file = $this->files->get_file( $file_id );
		if ( ! $file ) {
			return self::not_found();
		}
		$this->files->delete_file( $file_id );
		self::mark_healthy( $file_id, (int) $file->download_id );

		return array( 'message' => __( 'File detached from download.', 'isoft-fm-foundation' ) );
	}

	// ---------------------------------------------------------------------
	// Helpers (static — shared by all flows, also reachable by tests)
	// ---------------------------------------------------------------------

	public static function download_category_id( int $download_id ): int {
		$terms = get_the_terms( $download_id, 'isoft_fmf_category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return 0;
		}
		return (int) $terms[0]->term_id;
	}

	/** Normalize an absolute path under the files root to a rel/with/forward/slashes path. */
	public static function relativize( string $abs ): string {
		return ltrim(
			str_replace( '\\', '/', substr( $abs, strlen( isoft_fmf_files_dir() ) ) ),
			'/'
		);
	}

	/**
	 * Linear scan of isoft_fmf_category terms looking for one whose folder
	 * matches $path. Category counts are in the hundreds — fine for linear.
	 */
	public static function find_term_by_folder_path( string $path ): ?object {
		$terms = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return null;
		}
		foreach ( $terms as $term ) {
			if ( isoft_fmf_category_folder_path( (int) $term->term_id ) === $path ) {
				return $term;
			}
		}
		return null;
	}

	private static function refresh_inode( int $file_id, string $abs_path ): void {
		if ( ! (bool) get_option( 'isoft_fmf_integrity_use_inode', 1 ) ) {
			return;
		}
		$ino = @fileinode( $abs_path );
		if ( ! $ino ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$wpdb->update(
			"{$wpdb->prefix}isoft_fmf_files",
			array( 'inode' => (int) $ino ),
			array( 'id' => $file_id ),
			array( '%d' ),
			array( '%d' )
		);
		wp_cache_delete( "file_{$file_id}", ISOFT_FMF_File_Manager::CACHE_GROUP );
	}

	/**
	 * Flip is_missing back to 0 and clear missing_since. If the post was
	 * auto-unpublished by our daily scan AND there are no more broken files
	 * on it, re-publish.
	 */
	private static function mark_healthy( int $file_id, int $download_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; cache invalidated below.
		$wpdb->update(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'is_missing'    => 0,
				'missing_since' => null,
			),
			array( 'id' => $file_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		ISOFT_FMF_File_Manager::bust_cache_for( $download_id, $file_id );

		$auto = get_post_meta( $download_id, '_isoft_fmf_auto_unpublished_at', true );
		if ( $auto ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same.
			$still_broken = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files
					  WHERE download_id = %d AND file_type = 'local' AND is_missing = 1",
					$download_id
				)
			);
			if ( 0 === $still_broken && 'draft' === get_post_status( $download_id ) ) {
				wp_update_post(
					array(
						'ID'          => $download_id,
						'post_status' => 'publish',
					)
				);
				delete_post_meta( $download_id, '_isoft_fmf_auto_unpublished_at' );
			}
		}
	}

	private static function wp_fs(): WP_Filesystem_Base {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	private static function not_found(): WP_Error {
		return new WP_Error(
			'isoft_fmf_file_not_found',
			__( 'File record not found.', 'isoft-fm-foundation' ),
			array( 'status' => 404 )
		);
	}
}
