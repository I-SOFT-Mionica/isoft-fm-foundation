<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Admin_Meta_Boxes {

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'strip_post_password' ), 10, 2 );
		add_action( 'wp_ajax_isoft_fmf_delete_file', array( $this, 'ajax_delete_file' ) );
		add_action( 'wp_ajax_isoft_fmf_save_file_order', array( $this, 'ajax_save_order' ) );
		add_action( 'wp_ajax_isoft_fmf_add_external', array( $this, 'ajax_add_external' ) );
		add_action( 'wp_ajax_isoft_fmf_update_file_meta', array( $this, 'ajax_update_file_meta' ) );
		add_action( 'wp_ajax_isoft_fmf_upload_file', array( $this, 'ajax_upload_file' ) );
		add_action( 'wp_ajax_isoft_fmf_browse_category', array( $this, 'ajax_browse_category' ) );
		add_action( 'wp_ajax_isoft_fmf_import_file', array( $this, 'ajax_import_file' ) );
	}

	/** Resolve the single isoft_fmf_category term id assigned to a download. */
	public static function get_download_category( int $download_id ): ?int {
		$terms = wp_get_object_terms( $download_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}
		return (int) $terms[0];
	}

	/**
	 * Append the most recent MySQL error (if any) to an AJAX error message
	 * so the admin can see *why* a DB write failed instead of a generic
	 * "Could not save…". These endpoints are capability-gated to users who
	 * can edit the post, so exposing the raw MySQL error to them is safe.
	 *
	 * Surfaced when a host's WP DB user lacked CREATE — every AJAX endpoint
	 * just said "Could not save file record" with no clue the underlying
	 * cause was "Table 'wp_isoft_fmf_files' doesn't exist".
	 */
	private static function db_error_suffix(): string {
		global $wpdb;
		return $wpdb->last_error ? ' (' . $wpdb->last_error . ')' : '';
	}

	/**
	 * Map a PHP UPLOAD_ERR_* code to a human-readable explanation of what
	 * actually went wrong. The default WordPress message is the same for
	 * every failure mode ("upload error") which tells the admin nothing.
	 */
	private static function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
				return __( 'The file is larger than the server allows (php.ini upload_max_filesize). Ask your host to raise it.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The file is larger than the form size limit.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'Upload was interrupted before completing. Try again.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Server is missing a temporary upload folder. This is a host-level configuration issue.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Server could not write the upload to disk — check upload directory permissions.', 'isoft-fm-foundation' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension blocked the upload (often a security plugin or mod_security rule).', 'isoft-fm-foundation' );
			default:
				return __( 'Upload error (unknown cause).', 'isoft-fm-foundation' );
		}
	}

	public function enqueue( string $hook ): void {
		global $post_type;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'isoft_fmf_file' !== $post_type ) {
			return;
		}
		wp_enqueue_script(
			'isoft-fmf-admin',
			ISOFT_FMF_PLUGIN_URL . 'admin/js/admin-script.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			ISOFT_FMF_VERSION,
			true
		);
		wp_localize_script(
			'isoft-fmf-admin',
			'ISOFT_FMF',
			array(
				'nonce'   => wp_create_nonce( 'isoft_fmf_admin' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'confirmDelete' => __( 'Remove this file from the download?', 'isoft-fm-foundation' ),
					'edit'          => __( 'Edit', 'isoft-fm-foundation' ),
					'remove'        => __( 'Remove', 'isoft-fm-foundation' ),
					'mirror'        => __( 'Mirror', 'isoft-fm-foundation' ),
					'external'      => __( 'External', 'isoft-fm-foundation' ),
					'noFiles'       => __( 'No files attached yet.', 'isoft-fm-foundation' ),
					'title'         => __( 'Title', 'isoft-fm-foundation' ),
					'description'   => __( 'Description', 'isoft-fm-foundation' ),
					'save'          => __( 'Save', 'isoft-fm-foundation' ),
					'cancel'        => __( 'Cancel', 'isoft-fm-foundation' ),
					'saving'        => __( 'Saving…', 'isoft-fm-foundation' ),
					'linking'       => __( 'Linking…', 'isoft-fm-foundation' ),
					'retry'         => __( 'Retry', 'isoft-fm-foundation' ),
					'error'         => __( 'Error', 'isoft-fm-foundation' ),
					'networkError'  => __( 'Network error', 'isoft-fm-foundation' ),
					'serverError'   => __( 'Server error', 'isoft-fm-foundation' ),
					'linked'        => __( 'linked', 'isoft-fm-foundation' ),
					'alreadyLinked' => __( 'already linked', 'isoft-fm-foundation' ),
					'linkButton'    => __( 'Link to this download', 'isoft-fm-foundation' ),
					'loading'       => __( 'Loading…', 'isoft-fm-foundation' ),
					'noFolderFiles' => __( 'No files found in this category folder.', 'isoft-fm-foundation' ),
				),
			)
		);
		wp_enqueue_style( 'isoft-fmf-admin', ISOFT_FMF_PLUGIN_URL . 'admin/css/admin-style.css', array(), ISOFT_FMF_VERSION );
		wp_add_inline_style( 'isoft-fmf-admin', '#visibility-action, .misc-pub-visibility { display: none !important; }' );
	}

	public function register(): void {
		// Files meta box — primary file-management UI, kept as a classic
		// meta box (rich jQuery-sortable + AJAX-upload flow). The editor
		// sidebar's FilesPanel is a compact summary that scrolls to this
		// meta box; it does not replace it.
		add_meta_box( 'isoft-fmf-files', __( 'Files', 'isoft-fm-foundation' ), array( $this, 'render_files' ), 'isoft_fmf_file', 'normal', 'high' );
	}

	// --- Render callbacks ---

	public function render_files( WP_Post $post ): void {
		$files         = ( new ISOFT_FMF_File_Manager() )->get_files( $post->ID );
		$is_new_post   = 'auto-draft' === $post->post_status || 0 === $post->ID;
		$category_id   = self::get_download_category( $post->ID );
		$category      = $category_id ? get_term( $category_id, 'isoft_fmf_category' ) : null;
		$category_path = $category_id ? isoft_fmf_category_folder_path( $category_id ) : '';
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/meta-box-files.php';
	}

	/**
	 * Strip post_password for isoft_fmf_file posts — our RBAC replaces WP password protection.
	 */
	public function strip_post_password( array $data, array $postarr ): array {
		unset( $postarr );
		if ( 'isoft_fmf_file' === ( $data['post_type'] ?? '' ) ) {
			$data['post_password'] = '';
		}
		return $data;
	}

	// --- AJAX handlers ---

	public function ajax_delete_file(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );
		$file_id = absint( $_POST['file_id'] ?? 0 );
		if ( ! $file_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'isoft-fm-foundation' ) ) );
		}
		$service = new ISOFT_FMF_Files_Service();
		$file    = $service->get( $file_id );
		if ( ! $file || ! current_user_can( 'edit_post', (int) $file->download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}
		if ( ! $service->delete( $file_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete file.', 'isoft-fm-foundation' ) . self::db_error_suffix() ) );
		}
		wp_send_json_success();
	}

	public function ajax_save_order(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}
		$order = isset( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized inside the service.
		if ( ! is_array( $order ) ) {
			wp_send_json_error();
		}
		( new ISOFT_FMF_Files_Service() )->reorder( $order );
		wp_send_json_success();
	}

	public function ajax_add_external(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );
		$download_id = absint( $_POST['download_id'] ?? 0 );
		$url         = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! $download_id || ! $url ) {
			wp_send_json_error( array( 'message' => __( 'URL is required.', 'isoft-fm-foundation' ) ) );
		}
		if ( ! current_user_can( 'edit_post', $download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}
		$title  = sanitize_text_field( wp_unslash( $_POST['title'] ?? $url ) );
		$result = ( new ISOFT_FMF_Files_Service() )->add_external(
			$download_id,
			$url,
			$title,
			! empty( $_POST['is_mirror'] )
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() . self::db_error_suffix() ) );
		}
		wp_send_json_success( array( 'file_id' => $result ) );
	}

	/**
	 * Update a file record's editable metadata (title + description).
	 */
	public function ajax_update_file_meta(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );

		$file_id = absint( $_POST['file_id'] ?? 0 );
		if ( ! $file_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file id.', 'isoft-fm-foundation' ) ) );
		}

		$service = new ISOFT_FMF_Files_Service();
		$file    = $service->get( $file_id );
		if ( ! $file || ! current_user_can( 'edit_post', (int) $file->download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}

		$title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );

		if ( ! $service->update_meta( $file_id, $title, $description ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not save changes.', 'isoft-fm-foundation' ) . self::db_error_suffix() ) );
		}

		wp_send_json_success( array( 'file' => $service->get( $file_id ) ) );
	}

	/**
	 * Receive a single uploaded file, place it in the download's category
	 * folder, and insert an isoft_fmf_files row.
	 */
	public function ajax_upload_file(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );

		$download_id = absint( $_POST['download_id'] ?? 0 );
		if ( ! $download_id || ! current_user_can( 'edit_post', $download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received by the server. The request may have been blocked by a security plugin or rejected at the web-server level.', 'isoft-fm-foundation' ) ) );
		}

		$service = new ISOFT_FMF_Files_Service();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw $_FILES entry forwarded to the service; wp_handle_upload validates inside.
		$result = $service->upload( $download_id, $_FILES['file'] );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			if ( in_array( $result->get_error_code(), array( 'isoft_fmf_save_record' ), true ) ) {
				$msg .= self::db_error_suffix();
			}
			wp_send_json_error( array( 'message' => $msg ) );
		}

		wp_send_json_success( array( 'file' => $service->get( $result ) ) );
	}

	/**
	 * List the physical contents of the download's current category folder,
	 * flagging which files are already tracked in isoft_fmf_files.
	 */
	public function ajax_browse_category(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );

		$download_id = absint( $_POST['download_id'] ?? 0 );
		if ( ! $download_id || ! current_user_can( 'edit_post', $download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}

		wp_send_json_success( ( new ISOFT_FMF_Files_Service() )->browse_category( $download_id ) );
	}

	/**
	 * Import an existing untracked file from disk into the isoft_fmf_files table.
	 */
	public function ajax_import_file(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );

		$download_id = absint( $_POST['download_id'] ?? 0 );
		$rel_path    = sanitize_text_field( wp_unslash( $_POST['rel_path'] ?? '' ) );

		if ( ! $download_id || ! $rel_path || ! current_user_can( 'edit_post', $download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}

		$service = new ISOFT_FMF_Files_Service();
		$result  = $service->import_from_disk( $download_id, $rel_path );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			if ( 'isoft_fmf_save_record' === $result->get_error_code() ) {
				$msg .= self::db_error_suffix();
			}
			wp_send_json_error( array( 'message' => $msg ) );
		}

		wp_send_json_success( array( 'file' => $service->get( $result ) ) );
	}
}
