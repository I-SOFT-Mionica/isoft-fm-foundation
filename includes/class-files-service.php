<?php
/**
 * Files service — orchestrates the multi-step file flows (upload,
 * browse category, import-from-disk) on top of the lower-level
 * [[ISOFT_FMF_File_Manager]] data ops.
 *
 * File_Manager owns atomic DB writes (add_local_file, add_external_link,
 * update_meta, delete_file, update_sort_order). The orchestration that
 * used to live inside the AJAX handlers in class-admin-meta-boxes.php —
 * sanitize filename, ensure category folder, run wp_handle_upload, move
 * to category, compute hash, insert row — lives here so both the REST
 * controller (ISOFT_FMF_Rest_Files) and the legacy AJAX handler can
 * call one canonical implementation.
 *
 * Operations return either an int (file_id) on success or a WP_Error
 * with an error_data array carrying an HTTP-style status hint for the
 * REST controller. The AJAX handler unwraps to its own send_json_error
 * shape.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Files_Service {

	private ISOFT_FMF_File_Manager $files;

	public function __construct() {
		$this->files = new ISOFT_FMF_File_Manager();
	}

	// ---------------------------------------------------------------------
	// Pass-through wrappers (so callers depend on the service surface only).
	// ---------------------------------------------------------------------

	/** @return object[] */
	public function list_for_download( int $download_id ): array {
		return $this->files->get_files( $download_id );
	}

	public function get( int $file_id ): ?object {
		return $this->files->get_file( $file_id );
	}

	public function update_meta( int $file_id, string $title, string $description ): bool {
		return $this->files->update_meta( $file_id, $title, $description );
	}

	public function delete( int $file_id ): bool {
		return $this->files->delete_file( $file_id );
	}

	/**
	 * @param array<int,int> $order map of file_id => sort_order
	 */
	public function reorder( array $order ): void {
		$sanitized = array();
		foreach ( $order as $fid => $pos ) {
			$sanitized[ absint( $fid ) ] = absint( $pos );
		}
		$this->files->update_sort_order( $sanitized );
	}

	public function add_external( int $download_id, string $url, string $title = '', bool $is_mirror = false ): int|WP_Error {
		$file_id = $this->files->add_external_link(
			$download_id,
			$url,
			array(
				'title'     => '' !== $title ? $title : $url,
				'is_mirror' => $is_mirror ? 1 : 0,
			)
		);
		if ( ! $file_id ) {
			return new WP_Error(
				'isoft_fmf_external_add_failed',
				__( 'Could not add link.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}
		return (int) $file_id;
	}

	// ---------------------------------------------------------------------
	// Orchestration flows (extracted from class-admin-meta-boxes AJAX).
	// ---------------------------------------------------------------------

	/**
	 * Upload pipeline: validate, sanitize filename, ensure category folder,
	 * run wp_handle_upload, move into the category, compute hash, insert
	 * tracked row. Returns the new file_id or a WP_Error.
	 *
	 * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $uploaded_file Raw $_FILES['file'] entry (caller has unslashed/validated structure).
	 */
	public function upload( int $download_id, array $uploaded_file ): int|WP_Error {
		$err = (int) ( $uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $err ) {
			return new WP_Error(
				'isoft_fmf_upload_error',
				self::upload_error_message( $err ),
				array( 'status' => 400 )
			);
		}

		$category_id = self::download_category_id( $download_id );
		if ( ! $category_id ) {
			return new WP_Error(
				'isoft_fmf_no_category',
				__( 'Assign a category to this download and save before uploading files.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		$original_name = sanitize_file_name( (string) ( $uploaded_file['name'] ?? '' ) );
		$sanitized     = isoft_fmf_sanitize_filename( $original_name );
		if ( $sanitized['error'] ) {
			return new WP_Error( 'isoft_fmf_bad_filename', $sanitized['error'], array( 'status' => 400 ) );
		}
		$slug = $sanitized['slug'];

		if ( isoft_fmf_filename_collision( $slug, $category_id ) ) {
			return new WP_Error(
				'isoft_fmf_filename_collision',
				sprintf(
					/* translators: %s: filename */
					__( 'A file named "%s" already exists in this category. Rename the file and try again.', 'isoft-fm-foundation' ),
					$slug
				),
				array( 'status' => 409 )
			);
		}

		// Veto point: runs on the PHP temp file, before wp_handle_upload() copies
		// it into the public uploads directory, so a refused file never lands
		// anywhere a web server could serve it.
		if ( ! empty( $uploaded_file['tmp_name'] ) ) {
			$refused = self::check_attach_allowed(
				(string) $uploaded_file['tmp_name'],
				array(
					'download_id'   => $download_id,
					'origin'        => 'upload',
					'original_name' => $original_name,
				)
			);
			if ( $refused ) {
				return $refused;
			}
		}

		ISOFT_FMF_Category_Folders::ensure( $category_id );
		$target_abs = isoft_fmf_category_fs_path( $category_id ) . '/' . $slug;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$handled = wp_handle_upload(
			$uploaded_file,
			array(
				'test_form' => false,
				'action'    => 'isoft_fmf_upload_file',
			)
		);
		if ( isset( $handled['error'] ) ) {
			return new WP_Error( 'isoft_fmf_handle_upload', $handled['error'], array( 'status' => 500 ) );
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem->move( $handled['file'], $target_abs, true ) ) {
			return new WP_Error(
				'isoft_fmf_move_failed',
				__( 'Failed to write the uploaded file to the category folder. Check write permissions on wp-content/uploads/isoft-fmf-files/.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		return $this->register_placed_file(
			$download_id,
			$category_id,
			$target_abs,
			$slug,
			isoft_fmf_autofill_title( $sanitized['original_title'] ),
			true
		);
	}

	/**
	 * Place a file that already exists on the server into the download's
	 * category folder and register it. See isoft_fmf_attach_file().
	 *
	 * @param array<string,mixed> $args mode, original_name, title, context.
	 */
	public function attach( int $download_id, string $source_abs, array $args = array() ): int|WP_Error {
		if ( 'isoft_fmf_file' !== get_post_type( $download_id ) ) {
			return new WP_Error( 'isoft_fmf_not_found', __( 'Download not found.', 'isoft-fm-foundation' ), array( 'status' => 404 ) );
		}

		$mode = (string) ( $args['mode'] ?? 'copy' );
		if ( ! in_array( $mode, array( 'copy', 'move', 'in_place' ), true ) ) {
			return new WP_Error( 'isoft_fmf_bad_mode', __( 'Unknown attach mode.', 'isoft-fm-foundation' ), array( 'status' => 400 ) );
		}

		$category_id = self::download_category_id( $download_id );
		if ( ! $category_id ) {
			return new WP_Error(
				'isoft_fmf_no_category',
				__( 'Assign a category to this download and save before uploading files.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		// Regular files only: a symlink could point anywhere on the server.
		if ( is_link( $source_abs ) || ! is_file( $source_abs ) || ! is_readable( $source_abs ) ) {
			return new WP_Error( 'isoft_fmf_bad_source', __( 'The source is not a readable regular file.', 'isoft-fm-foundation' ), array( 'status' => 400 ) );
		}
		$source = realpath( $source_abs );
		if ( false === $source ) {
			return new WP_Error( 'isoft_fmf_bad_source', __( 'The source is not a readable regular file.', 'isoft-fm-foundation' ), array( 'status' => 400 ) );
		}

		$original_name = sanitize_file_name( (string) ( $args['original_name'] ?? basename( $source ) ) );
		$sanitized     = isoft_fmf_sanitize_filename( $original_name );
		if ( $sanitized['error'] ) {
			return new WP_Error( 'isoft_fmf_bad_filename', $sanitized['error'], array( 'status' => 400 ) );
		}
		$slug  = $sanitized['slug'];
		$title = isset( $args['title'] ) && '' !== trim( (string) $args['title'] )
			? sanitize_text_field( (string) $args['title'] )
			: isoft_fmf_autofill_title( $sanitized['original_title'] );

		$context = array_merge(
			(array) ( $args['context'] ?? array() ),
			array(
				'download_id'   => $download_id,
				'origin'        => 'attach',
				'original_name' => $original_name,
				'mode'          => $mode,
			)
		);
		$refused = self::check_attach_allowed( $source, $context );
		if ( $refused ) {
			return $refused;
		}

		ISOFT_FMF_Category_Folders::ensure( $category_id );
		$folder = isoft_fmf_category_fs_path( $category_id );

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$collision = new WP_Error(
			'isoft_fmf_filename_collision',
			sprintf(
				/* translators: %s: filename */
				__( 'A file named "%s" already exists in this category. Rename the file and try again.', 'isoft-fm-foundation' ),
				$slug
			),
			array( 'status' => 409 )
		);

		if ( 'in_place' === $mode ) {
			// The file must sit directly in this download's category folder
			// (a subfolder belongs to a child category).
			$base = realpath( $folder );
			if ( ! $base || dirname( $source ) !== $base ) {
				return new WP_Error(
					'isoft_fmf_not_in_category_folder',
					__( 'The file is not directly inside the download\'s category folder.', 'isoft-fm-foundation' ),
					array( 'status' => 400 )
				);
			}
			$target_abs = $source;
			if ( basename( $source ) !== $slug ) {
				if ( isoft_fmf_filename_collision( $slug, $category_id ) ) {
					return $collision;
				}
				$renamed = $base . '/' . $slug;
				if ( ! $wp_filesystem->move( $source, $renamed, false ) ) {
					return new WP_Error( 'isoft_fmf_move_failed', __( 'Could not rename the file to its sanitized name.', 'isoft-fm-foundation' ), array( 'status' => 500 ) );
				}
				$target_abs = $renamed;
			}
			if ( isoft_fmf_file_for_path( isoft_fmf_category_folder_path( $category_id ) . '/' . $slug ) ) {
				return new WP_Error( 'isoft_fmf_already_registered', __( 'This file is already registered.', 'isoft-fm-foundation' ), array( 'status' => 409 ) );
			}
			return $this->register_placed_file( $download_id, $category_id, $target_abs, $slug, $title, false );
		}

		if ( isoft_fmf_filename_collision( $slug, $category_id ) ) {
			return $collision;
		}
		$target_abs = $folder . '/' . $slug;
		if ( ! $wp_filesystem->copy( $source, $target_abs, false ) ) {
			return new WP_Error(
				'isoft_fmf_move_failed',
				__( 'Failed to write the file to the category folder. Check write permissions on wp-content/uploads/isoft-fmf-files/.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		$file_id = $this->register_placed_file( $download_id, $category_id, $target_abs, $slug, $title, true );

		// Move: the source goes only once the row is saved.
		if ( 'move' === $mode && ! is_wp_error( $file_id ) ) {
			wp_delete_file( $source );
		}
		return $file_id;
	}

	/**
	 * The veto point every way of adding a file passes through. Lets an
	 * extension (a malware scanner, a policy check) refuse a file before it
	 * is stored. With no callback attached it changes nothing.
	 *
	 * @param string              $abs_path Absolute path of the file as it sits now.
	 * @param array<string,mixed> $context  download_id, origin (upload|attach|import|replace), original_name, plus caller context.
	 * @return WP_Error|null Null when the file may proceed.
	 */
	public static function check_attach_allowed( string $abs_path, array $context ): ?WP_Error {
		/**
		 * Filters whether a file may be added to a download.
		 *
		 * @param true|WP_Error        $allowed  True to proceed; return a WP_Error to refuse.
		 * @param string               $abs_path Absolute path of the file as it sits now.
		 * @param array<string,mixed>  $context  download_id, origin, original_name, plus caller context.
		 */
		$allowed = apply_filters( 'isoft_fmf_pre_attach_file', true, $abs_path, $context );
		if ( true === $allowed ) {
			return null;
		}
		if ( is_wp_error( $allowed ) ) {
			$data = $allowed->get_error_data();
			if ( is_array( $data ) && ! empty( $data['status'] ) ) {
				return $allowed;
			}
			return new WP_Error( $allowed->get_error_code(), $allowed->get_error_message(), array( 'status' => 422 ) );
		}
		return new WP_Error( 'isoft_fmf_file_refused', __( 'This file was refused.', 'isoft-fm-foundation' ), array( 'status' => 422 ) );
	}

	/**
	 * Second half shared by every way of adding a local file: detect the
	 * type, hash it and save the row. On failure the placed file is removed
	 * when this call put it there.
	 *
	 * @param bool $remove_on_fail Delete $target_abs if the row cannot be saved.
	 */
	private function register_placed_file( int $download_id, int $category_id, string $target_abs, string $slug, string $title, bool $remove_on_fail ): int|WP_Error {
		// Never trust $_FILES['type'] (browser-supplied). Prefer magic-byte detection.
		$detected_mime = function_exists( 'mime_content_type' ) ? mime_content_type( $target_abs ) : false;
		if ( ! $detected_mime ) {
			$ftype         = wp_check_filetype( $slug );
			$detected_mime = $ftype['type'] ?: 'application/octet-stream';
		}

		$file_id = $this->files->add_local_file(
			$download_id,
			array(
				'title'     => $title,
				'file_name' => $slug,
				'file_path' => isoft_fmf_category_folder_path( $category_id ) . '/' . $slug,
				'file_size' => (int) filesize( $target_abs ),
				'file_mime' => $detected_mime,
				'file_hash' => hash_file( 'sha256', $target_abs ),
			)
		);

		if ( ! $file_id ) {
			if ( $remove_on_fail ) {
				wp_delete_file( $target_abs );
			}
			return new WP_Error(
				'isoft_fmf_save_record',
				__( 'Could not save file record.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		return (int) $file_id;
	}

	/**
	 * List physical files in the download's category folder, flagging which
	 * are already tracked in isoft_fmf_files.
	 *
	 * @return array{files: array<int,array<string,mixed>>, category: string|null}
	 */
	public function browse_category( int $download_id ): array {
		$category_id = self::download_category_id( $download_id );
		if ( ! $category_id ) {
			return array(
				'files'    => array(),
				'category' => null,
			);
		}

		$folder = isoft_fmf_category_fs_path( $category_id );
		if ( ! is_dir( $folder ) ) {
			return array(
				'files'    => array(),
				'category' => isoft_fmf_category_folder_path( $category_id ),
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-request freshness required.
		$tracked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT file_path FROM {$wpdb->prefix}isoft_fmf_files WHERE download_id = %d",
				$download_id
			)
		);

		$rel_base = isoft_fmf_category_folder_path( $category_id );
		$items    = array();
		foreach ( (array) glob( "{$folder}/*" ) as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			$name    = basename( $path );
			$rel     = "{$rel_base}/{$name}";
			$items[] = array(
				'name'    => $name,
				'rel'     => $rel,
				'size'    => filesize( $path ),
				'tracked' => in_array( $rel, $tracked, true ),
			);
		}

		return array(
			'files'    => $items,
			'category' => $rel_base,
		);
	}

	/**
	 * Import an existing untracked file from disk into the isoft_fmf_files
	 * table. Returns the new file_id or a WP_Error.
	 */
	public function import_from_disk( int $download_id, string $rel_path ): int|WP_Error {
		$rel_path = sanitize_text_field( $rel_path );
		if ( '' === $rel_path ) {
			return new WP_Error(
				'isoft_fmf_no_path',
				__( 'File path required.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		// Only a file sitting directly in this download's own category folder
		// (what browse_category() offers). Anything else in storage belongs to
		// another category, the bundle cache or Foundation itself, and answers
		// like a missing file. The permission check covers this download only.
		$category_id = self::download_category_id( $download_id );
		$base        = realpath( isoft_fmf_files_dir() );
		$folder      = $category_id ? realpath( isoft_fmf_category_fs_path( $category_id ) ) : false;
		$abs         = $base ? realpath( "{$base}/{$rel_path}" ) : false;
		if ( ! $abs || ! $folder || dirname( $abs ) !== $folder || is_link( "{$base}/{$rel_path}" ) || ! is_file( $abs ) || str_starts_with( basename( $abs ), '.' ) ) {
			return new WP_Error(
				'isoft_fmf_file_not_found',
				__( 'File not found.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}

		// Store the canonical relative path, and never register a file twice.
		$rel_path = ltrim( str_replace( '\\', '/', substr( $abs, strlen( $base ) ) ), '/' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check on the write path.
		$already = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE file_path = %s', "{$wpdb->prefix}isoft_fmf_files", $rel_path ) );
		if ( $already > 0 ) {
			return new WP_Error(
				'isoft_fmf_already_registered',
				__( 'This file is already registered.', 'isoft-fm-foundation' ),
				array( 'status' => 409 )
			);
		}

		if ( in_array( strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) ), isoft_fmf_blocked_extensions(), true ) ) {
			return new WP_Error(
				'isoft_fmf_bad_filename',
				__( 'This file type can never be registered: a web server could run it.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		$refused = self::check_attach_allowed(
			$abs,
			array(
				'download_id'   => $download_id,
				'origin'        => 'import',
				'original_name' => basename( $abs ),
			)
		);
		if ( $refused ) {
			return $refused;
		}

		$name    = basename( $abs );
		$file_id = $this->files->add_local_file(
			$download_id,
			array(
				'title'     => isoft_fmf_autofill_title( pathinfo( $name, PATHINFO_FILENAME ) ),
				'file_name' => $name,
				'file_path' => $rel_path,
				'file_size' => filesize( $abs ),
				'file_mime' => function_exists( 'mime_content_type' ) ? ( mime_content_type( $abs ) ?: 'application/octet-stream' ) : 'application/octet-stream',
				'file_hash' => hash_file( 'sha256', $abs ),
			)
		);

		if ( ! $file_id ) {
			return new WP_Error(
				'isoft_fmf_save_record',
				__( 'Could not save file record.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		return (int) $file_id;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * The download's (single) category id, or 0 if none assigned.
	 * Mirrors the get_download_category() helper in class-admin-meta-boxes
	 * so the AJAX handler can be retired in 0.12.5 without orphaning it.
	 */
	public static function download_category_id( int $download_id ): int {
		$terms = wp_get_object_terms( $download_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}
		return (int) $terms[0];
	}

	/**
	 * Human-readable explanation for a PHP UPLOAD_ERR_* constant.
	 */
	public static function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded file exceeds the maximum allowed size.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Please try again.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file received by the server.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Server could not write the uploaded file to the temporary directory.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A server extension blocked the upload.', 'isoft-fm-foundation' );
			default:
				return __( 'Unknown upload error.', 'isoft-fm-foundation' );
		}
	}
}
