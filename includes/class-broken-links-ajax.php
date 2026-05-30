<?php
/**
 * AJAX handlers for the Broken Links recovery dialog.
 *
 * Actions exposed to the browser (each nonce-protected with 'isfm_broken_links'):
 *   isfm_recover_probe       — look up file + cross-category inode hunt, return dialog data.
 *   isfm_recover_move_back   — move a candidate file back to the expected path.
 *   isfm_recover_reassign    — reassign the download (and all its files) to the new category.
 *   isfm_recover_split       — detach this file into a new draft download in its new category.
 *   isfm_recover_reupload    — accept an uploaded replacement and update the DB row.
 *   isfm_recover_detach      — drop the isfm_files row (physical file untouched).
 */
defined( 'ABSPATH' ) || exit;

// Every public handle_* method calls $this->guard() as its first statement, which runs
// check_ajax_referer( 'isfm_broken_links', 'nonce' ) — so every $_POST/$_FILES read below is
// nonce-verified at the top of the method. The sniff can't follow the indirection.
// Every $wpdb->update writes to the plugin's custom isfm_files table and the manager cache
// is invalidated either via refresh_inode()/mark_healthy() helpers or explicit bust_cache_for()
// calls at the call site.
// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
class ISFM_Broken_Links_Ajax {

	public function register_hooks(): void {
		$actions = array(
			'probe',
			'move_back',
			'reassign',
			'split',
			'reupload',
			'detach',
		);
		foreach ( $actions as $action ) {
			add_action( "wp_ajax_isfm_recover_{$action}", array( $this, "handle_{$action}" ) );
		}
	}

	// -------------------------------------------------------------------------
	// Shared guards + helpers
	// -------------------------------------------------------------------------

	private function guard(): void {
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'isoft-fm-foundation' ) ), 403 );
		}
		check_ajax_referer( 'isfm_broken_links', 'nonce' );
	}

	private function get_file_or_die( int $file_id ): object {
		$file = ( new ISFM_File_Manager() )->get_file( $file_id );
		if ( ! $file ) {
			wp_send_json_error( array( 'message' => __( 'File record not found.', 'isoft-fm-foundation' ) ), 404 );
		}
		return $file;
	}

	private function get_download_category_id( int $download_id ): int {
		$terms = get_the_terms( $download_id, 'isfm_category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return 0;
		}
		return (int) $terms[0]->term_id;
	}

	private function wp_fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	private function refresh_inode( int $file_id, string $abs_path ): void {
		if ( ! (bool) get_option( 'isfm_integrity_use_inode', 1 ) ) {
			return;
		}
		$ino = @fileinode( $abs_path );
		if ( ! $ino ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}isfm_files",
			array( 'inode' => (int) $ino ),
			array( 'id' => $file_id ),
			array( '%d' ),
			array( '%d' )
		);
		wp_cache_delete( "file_{$file_id}", ISFM_File_Manager::CACHE_GROUP );
	}

	private function mark_healthy( int $file_id, int $download_id ): void {
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}isfm_files",
			array(
				'is_missing'    => 0,
				'missing_since' => null,
			),
			array( 'id' => $file_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		ISFM_File_Manager::bust_cache_for( $download_id, $file_id );

		// Auto-republish only if we were the ones who unpublished it.
		$auto = get_post_meta( $download_id, '_isfm_auto_unpublished_at', true );
		if ( $auto ) {
			$still_broken = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}isfm_files
					  WHERE download_id = %d
					    AND file_type   = 'local'
					    AND is_missing  = 1",
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
				delete_post_meta( $download_id, '_isfm_auto_unpublished_at' );
			}
		}
	}

	// -------------------------------------------------------------------------
	// probe — gather everything the recovery dialog needs
	// -------------------------------------------------------------------------

	public function handle_probe(): void {
		$this->guard();
		$file_id = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$file    = $this->get_file_or_die( $file_id );

		$download_id  = (int) $file->download_id;
		$expected_cat = $this->get_download_category_id( $download_id );
		$expected_dir = $expected_cat ? isfm_category_folder_path( $expected_cat ) : '';

		$candidate     = ISFM_File_Integrity::find_by_inode_anywhere( $file );
		$candidate_rel = null;
		$candidate_cat = null;
		if ( $candidate ) {
			$candidate_rel = ltrim(
				str_replace( '\\', '/', substr( $candidate, strlen( isfm_files_dir() ) ) ),
				'/'
			);
			// Extract the category folder from the relative path.
			$candidate_cat = dirname( $candidate_rel );
			if ( '.' === $candidate_cat ) {
				$candidate_cat = '';
			}
		}

		wp_send_json_success(
			array(
				'file_id'          => $file_id,
				'file_name'        => $file->file_name,
				'download_id'      => $download_id,
				'download_title'   => get_the_title( $download_id ),
				'expected_folder'  => $expected_dir,
				'candidate_found'  => (bool) $candidate,
				'candidate_folder' => $candidate_cat,
				'is_cross_cat'     => $candidate && $candidate_cat !== $expected_dir,
			)
		);
	}

	// -------------------------------------------------------------------------
	// move_back — return a cross-category file to its expected path
	// -------------------------------------------------------------------------

	public function handle_move_back(): void {
		$this->guard();
		$file_id = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$file    = $this->get_file_or_die( $file_id );

		$candidate = ISFM_File_Integrity::find_by_inode_anywhere( $file );
		if ( ! $candidate ) {
			wp_send_json_error( array( 'message' => __( 'Could not locate the file on disk.', 'isoft-fm-foundation' ) ), 404 );
		}

		$expected_cat = $this->get_download_category_id( (int) $file->download_id );
		if ( ! $expected_cat ) {
			wp_send_json_error( array( 'message' => __( 'This download has no category — cannot determine target folder.', 'isoft-fm-foundation' ) ), 400 );
		}

		$target_dir = isfm_category_fs_path( $expected_cat );
		if ( ! is_dir( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}
		$target_abs = $target_dir . '/' . basename( $candidate );

		if ( file_exists( $target_abs ) ) {
			wp_send_json_error( array( 'message' => __( 'A file with this name already exists at the expected path.', 'isoft-fm-foundation' ) ), 409 );
		}

		if ( ! $this->wp_fs()->move( $candidate, $target_abs, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Filesystem move failed. Check directory permissions.', 'isoft-fm-foundation' ) ), 500 );
		}

		$new_rel = ltrim(
			str_replace( '\\', '/', substr( $target_abs, strlen( isfm_files_dir() ) ) ),
			'/'
		);

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}isfm_files",
			array(
				'file_path' => $new_rel,
				'file_name' => basename( $target_abs ),
			),
			array( 'id' => $file_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$this->refresh_inode( $file_id, $target_abs );
		$this->mark_healthy( $file_id, (int) $file->download_id );

		wp_send_json_success( array( 'message' => __( 'File moved back to the expected folder.', 'isoft-fm-foundation' ) ) );
	}

	// -------------------------------------------------------------------------
	// reassign — move the whole download to a new category
	// -------------------------------------------------------------------------

	public function handle_reassign(): void {
		$this->guard();
		$file_id = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$file    = $this->get_file_or_die( $file_id );

		$download_id = (int) $file->download_id;

		// Locate the cross-category candidate, infer the new category from its folder.
		$candidate = ISFM_File_Integrity::find_by_inode_anywhere( $file );
		if ( ! $candidate ) {
			wp_send_json_error( array( 'message' => __( 'Could not locate the file on disk.', 'isoft-fm-foundation' ) ), 404 );
		}
		$candidate_rel = ltrim(
			str_replace( '\\', '/', substr( $candidate, strlen( isfm_files_dir() ) ) ),
			'/'
		);
		$new_cat_path  = dirname( $candidate_rel );

		$new_term = $this->find_term_by_folder_path( $new_cat_path );
		if ( ! $new_term ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: folder path */
						__( 'No isfm_category term matches the folder "%s". Create the category first, then retry.', 'isoft-fm-foundation' ),
						$new_cat_path
					),
				),
				400
			);
		}

		// Refuse if any OTHER file on this download is also flagged missing — admin must
		// resolve those first so we don't leave sibling rows in a half-moved state.
		global $wpdb;
		$others_broken = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}isfm_files
				  WHERE download_id = %d
				    AND file_type   = 'local'
				    AND is_missing  = 1
				    AND id         <> %d",
				$download_id,
				$file_id
			)
		);
		if ( $others_broken > 0 ) {
			wp_send_json_error(
				array( 'message' => __( 'Other files on this download are also flagged missing. Resolve those first, then retry reassign.', 'isoft-fm-foundation' ) ),
				409
			);
		}

		$new_dir = isfm_category_fs_path( $new_term->term_id );
		if ( ! is_dir( $new_dir ) ) {
			wp_mkdir_p( $new_dir );
		}

		// Move every other (healthy) file on the download into the new folder.
		$siblings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}isfm_files
				  WHERE download_id = %d
				    AND file_type   = 'local'
				    AND id         <> %d",
				$download_id,
				$file_id
			)
		) ?: array();

		foreach ( $siblings as $sib ) {
			$old_abs = isfm_files_dir() . '/' . $sib->file_path;
			if ( ! is_readable( $old_abs ) ) {
				continue; // Already gone; we'd have refused earlier if it were flagged.
			}
			$new_abs = $new_dir . '/' . basename( $old_abs );
			if ( file_exists( $new_abs ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: file name */
							__( 'Cannot reassign — a file named "%s" already exists in the target category folder.', 'isoft-fm-foundation' ),
							basename( $old_abs )
						),
					),
					409
				);
			}
			if ( ! $this->wp_fs()->move( $old_abs, $new_abs, false ) ) {
				wp_send_json_error( array( 'message' => __( 'Filesystem move failed during sibling move. No changes committed to the DB.', 'isoft-fm-foundation' ) ), 500 );
			}
			$new_rel = ltrim(
				str_replace( '\\', '/', substr( $new_abs, strlen( isfm_files_dir() ) ) ),
				'/'
			);
			$wpdb->update(
				"{$wpdb->prefix}isfm_files",
				array( 'file_path' => $new_rel ),
				array( 'id' => (int) $sib->id ),
				array( '%s' ),
				array( '%d' )
			);
			$this->refresh_inode( (int) $sib->id, $new_abs );
			ISFM_File_Manager::bust_cache_for( $download_id, (int) $sib->id );
		}

		// The original missing file is already in the new folder — just update its row.
		$wpdb->update(
			"{$wpdb->prefix}isfm_files",
			array(
				'file_path' => $candidate_rel,
				'file_name' => basename( $candidate ),
			),
			array( 'id' => $file_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$this->refresh_inode( $file_id, $candidate );

		// Reassign the post's taxonomy term.
		wp_set_object_terms( $download_id, array( (int) $new_term->term_id ), 'isfm_category', false );

		$this->mark_healthy( $file_id, $download_id );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: category name */
					__( 'Download reassigned to "%s".', 'isoft-fm-foundation' ),
					$new_term->name
				),
			)
		);
	}

	/**
	 * Walk isfm_category terms and find the one whose folder path matches $path.
	 * Linear but cheap — category counts are in the hundreds, not millions.
	 */
	private function find_term_by_folder_path( string $path ): ?object {
		$terms = get_terms(
			array(
				'taxonomy'   => 'isfm_category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return null;
		}
		foreach ( $terms as $term ) {
			if ( isfm_category_folder_path( (int) $term->term_id ) === $path ) {
				return $term;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// split — detach file into a new draft download
	// -------------------------------------------------------------------------

	public function handle_split(): void {
		$this->guard();
		$file_id = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$file    = $this->get_file_or_die( $file_id );

		$candidate = ISFM_File_Integrity::find_by_inode_anywhere( $file );
		if ( ! $candidate ) {
			wp_send_json_error( array( 'message' => __( 'Could not locate the file on disk.', 'isoft-fm-foundation' ) ), 404 );
		}
		$candidate_rel = ltrim(
			str_replace( '\\', '/', substr( $candidate, strlen( isfm_files_dir() ) ) ),
			'/'
		);
		$new_cat_path  = dirname( $candidate_rel );
		$new_term      = $this->find_term_by_folder_path( $new_cat_path );
		if ( ! $new_term ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: folder path */
						__( 'No isfm_category term matches the folder "%s". Create the category first, then retry.', 'isoft-fm-foundation' ),
						$new_cat_path
					),
				),
				400
			);
		}

		$old_download_id = (int) $file->download_id;
		$new_post_id     = isfm_create_draft_download(
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
			wp_send_json_error( array( 'message' => __( 'Failed to create the new draft download.', 'isoft-fm-foundation' ) ), 500 );
		}

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}isfm_files",
			array(
				'download_id' => $new_post_id,
				'file_path'   => $candidate_rel,
				'file_name'   => basename( $candidate ),
			),
			array( 'id' => $file_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		$this->refresh_inode( $file_id, $candidate );
		ISFM_File_Manager::bust_cache_for( $old_download_id );
		$this->mark_healthy( $file_id, $new_post_id );

		// The OLD download may now be missing-only if this was its last file. Re-evaluate
		// the auto-unpublish flag; we don't force anything, just let the next scan pick up.
		wp_send_json_success(
			array(
				'message'     => sprintf(
					/* translators: %s: new post title */
					__( 'Created new draft download "%s".', 'isoft-fm-foundation' ),
					get_the_title( $new_post_id )
				),
				'new_post_id' => $new_post_id,
				'edit_url'    => get_edit_post_link( $new_post_id, 'raw' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// reupload — admin provides a fresh file
	// -------------------------------------------------------------------------

	public function handle_reupload(): void {
		$this->guard();
		$file_id = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$file    = $this->get_file_or_die( $file_id );

		if ( empty( $_FILES['replacement'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'isoft-fm-foundation' ) ), 400 );
		}

		$expected_cat = $this->get_download_category_id( (int) $file->download_id );
		if ( ! $expected_cat ) {
			wp_send_json_error( array( 'message' => __( 'This download has no category.', 'isoft-fm-foundation' ) ), 400 );
		}
		$target_dir = isfm_category_fs_path( $expected_cat );
		if ( ! is_dir( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}
		$target_abs = $target_dir . '/' . basename( (string) $file->file_name );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload(
			$_FILES['replacement'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_handle_upload validates.
			array(
				'test_form' => false,
				'action'    => 'isfm_recover_reupload',
			)
		);
		if ( isset( $upload['error'] ) ) {
			wp_send_json_error( array( 'message' => $upload['error'] ), 500 );
		}
		if ( file_exists( $target_abs ) ) {
			wp_delete_file( $target_abs );
		}
		if ( ! $this->wp_fs()->move( $upload['file'], $target_abs, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to write the uploaded file to the expected path.', 'isoft-fm-foundation' ) ), 500 );
		}

		$new_hash = hash_file( 'sha256', $target_abs ) ?: '';
		$new_size = (int) filesize( $target_abs );
		$new_rel  = ltrim(
			str_replace( '\\', '/', substr( $target_abs, strlen( isfm_files_dir() ) ) ),
			'/'
		);

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}isfm_files",
			array(
				'file_path' => $new_rel,
				'file_size' => $new_size,
				'file_hash' => $new_hash,
			),
			array( 'id' => $file_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		$this->refresh_inode( $file_id, $target_abs );
		$this->mark_healthy( $file_id, (int) $file->download_id );

		wp_send_json_success( array( 'message' => __( 'File reuploaded and relinked.', 'isoft-fm-foundation' ) ) );
	}

	// -------------------------------------------------------------------------
	// detach — drop the DB row entirely
	// -------------------------------------------------------------------------

	public function handle_detach(): void {
		$this->guard();
		$file_id = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$file    = $this->get_file_or_die( $file_id );

		( new ISFM_File_Manager() )->delete_file( $file_id );

		// If that was the last missing file on the post, maybe republish.
		$this->mark_healthy( $file_id, (int) $file->download_id );

		wp_send_json_success( array( 'message' => __( 'File detached from download.', 'isoft-fm-foundation' ) ) );
	}
}
