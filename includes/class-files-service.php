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
		$upload_size   = (int) ( $uploaded_file['size'] ?? 0 );
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

		// Never trust $_FILES['type'] (browser-supplied). Prefer magic-byte detection.
		$detected_mime = function_exists( 'mime_content_type' ) ? mime_content_type( $target_abs ) : false;
		if ( ! $detected_mime ) {
			$ftype         = wp_check_filetype( $slug );
			$detected_mime = $ftype['type'] ?: 'application/octet-stream';
		}

		$rel_path = isoft_fmf_category_folder_path( $category_id ) . '/' . $slug;
		$file_id  = $this->files->add_local_file(
			$download_id,
			array(
				'title'     => isoft_fmf_autofill_title( $sanitized['original_title'] ),
				'file_name' => $slug,
				'file_path' => $rel_path,
				'file_size' => $upload_size,
				'file_mime' => $detected_mime,
				'file_hash' => hash_file( 'sha256', $target_abs ),
			)
		);

		if ( ! $file_id ) {
			wp_delete_file( $target_abs );
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

		// Path traversal guard — resolved path must stay under isoft_fmf_files_dir().
		$base = realpath( isoft_fmf_files_dir() );
		$abs  = realpath( "{$base}/{$rel_path}" );
		if ( ! $abs || ! $base || ! str_starts_with( $abs, $base ) || ! is_file( $abs ) ) {
			return new WP_Error(
				'isoft_fmf_file_not_found',
				__( 'File not found.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
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
