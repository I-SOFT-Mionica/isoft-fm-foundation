<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Admin_Meta_Boxes {

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post_isoft_fmf_file', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'render_access_role_in_publish_box' ) );
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
		// Files first — that is the primary purpose of this CPT.
		add_meta_box( 'isoft-fmf-files', __( 'Files', 'isoft-fm-foundation' ), array( $this, 'render_files' ), 'isoft_fmf_file', 'normal', 'high' );
		// Description replaces the removed post editor.
		add_meta_box( 'isoft-fmf-description', __( 'Description', 'isoft-fm-foundation' ), array( $this, 'render_description' ), 'isoft_fmf_file', 'normal', 'high' );
		add_meta_box( 'isoft-fmf-version-info', __( 'Version & License', 'isoft-fm-foundation' ), array( $this, 'render_version_info' ), 'isoft_fmf_file', 'normal', 'default' );
		add_meta_box( 'isoft-fmf-stats', __( 'Statistics', 'isoft-fm-foundation' ), array( $this, 'render_stats' ), 'isoft_fmf_file', 'side', 'default' );
	}

	// --- Render callbacks ---

	public function render_files( WP_Post $post ): void {
		wp_nonce_field( "isoft_fmf_save_meta_{$post->ID}", 'isoft_fmf_meta_nonce' );
		$files         = ( new ISOFT_FMF_File_Manager() )->get_files( $post->ID );
		$is_new_post   = 'auto-draft' === $post->post_status || 0 === $post->ID;
		$category_id   = self::get_download_category( $post->ID );
		$category      = $category_id ? get_term( $category_id, 'isoft_fmf_category' ) : null;
		$category_path = $category_id ? isoft_fmf_category_folder_path( $category_id ) : '';
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/meta-box-files.php';
	}

	public function render_description( WP_Post $post ): void {
		$description = $post->post_content;
		?>
		<label for="isoft-fmf-description" class="screen-reader-text"><?php esc_html_e( 'Description', 'isoft-fm-foundation' ); ?></label>
		<textarea
			id="isoft-fmf-description"
			name="content"
			rows="4"
			class="widefat"
			style="resize:vertical"
		><?php echo esc_textarea( $description ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Optional. Shown on the download page below the title.', 'isoft-fm-foundation' ); ?></p>
		<?php
	}

	/**
	 * Render the Access Role dropdown inside the Publish meta box.
	 */
	public function render_access_role_in_publish_box( WP_Post $post ): void {
		if ( 'isoft_fmf_file' !== $post->post_type ) {
			return;
		}
		// Empty meta on a brand-new download defaults to 'inherit', which
		// falls through to the assigned category's role (or, failing that,
		// the global default). Existing downloads keep whatever literal role
		// they were saved with.
		$access_role = get_post_meta( $post->ID, '_isoft_fmf_access_role', true );
		if ( '' === $access_role ) {
			$access_role = 'inherit';
		}
		$roles = array(
			'inherit'       => __( '— Inherit from category —', 'isoft-fm-foundation' ),
			'public'        => __( 'Public (everyone)', 'isoft-fm-foundation' ),
			'subscriber'    => __( 'Subscriber+', 'isoft-fm-foundation' ),
			'contributor'   => __( 'Contributor+', 'isoft-fm-foundation' ),
			'author'        => __( 'Author+', 'isoft-fm-foundation' ),
			'editor'        => __( 'Editor+', 'isoft-fm-foundation' ),
			'administrator' => __( 'Administrator only', 'isoft-fm-foundation' ),
		);
		?>
		<div class="misc-pub-section misc-pub-isoft-fmf-access">
			<span class="dashicons dashicons-lock" style="color:#82878c;margin-right:2px;"></span>
			<label for="isoft-fmf-access-role"><strong><?php esc_html_e( 'Access:', 'isoft-fm-foundation' ); ?></strong></label>
			<select name="_isoft_fmf_access_role" id="isoft-fmf-access-role" style="margin-left:4px;">
				<?php foreach ( $roles as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $access_role, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
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

	public function render_version_info( WP_Post $post ): void {
		$version        = (string) get_post_meta( $post->ID, '_isoft_fmf_version', true );
		$changelog      = (string) get_post_meta( $post->ID, '_isoft_fmf_changelog', true );
		$license_id     = (int) get_post_meta( $post->ID, '_isoft_fmf_license_id', true );
		$author_name    = (string) get_post_meta( $post->ID, '_isoft_fmf_author_name', true );
		$author_url     = (string) get_post_meta( $post->ID, '_isoft_fmf_author_url', true );
		$date_published = (string) get_post_meta( $post->ID, '_isoft_fmf_date_published', true );
		$require_agree  = (bool) get_post_meta( $post->ID, '_isoft_fmf_require_agree', true );
		$agree_text     = (string) get_post_meta( $post->ID, '_isoft_fmf_agree_text', true );
		$featured       = (bool) get_post_meta( $post->ID, '_isoft_fmf_featured', true );
		$external_only  = (bool) get_post_meta( $post->ID, '_isoft_fmf_external_only', true );
		$licenses       = ( new ISOFT_FMF_License_Manager() )->get_all();
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/meta-box-version-info.php';
	}

	public function render_stats( WP_Post $post ): void {
		$files           = ( new ISOFT_FMF_File_Manager() )->get_files( $post->ID );
		$total_downloads = (int) get_post_meta( $post->ID, '_isoft_fmf_download_count', true );
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/meta-box-stats.php';
	}

	// --- Save ---

	public function save( int $post_id, WP_Post $post ): void {
		unset( $post );
		if ( ! isset( $_POST['isoft_fmf_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['isoft_fmf_meta_nonce'] ) ), "isoft_fmf_save_meta_{$post_id}" )
		) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'isoft_fmf_edit_own_downloads', $post_id ) ) {
			return;
		}

		// 'inherit' is the sentinel that defers to the category role (resolved
		// in ISOFT_FMF_Access_Control); the rest are the literal role keys.
		$valid_roles = array( 'inherit', 'public', 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

		// Access role — rendered in the Publish box via post_submitbox_misc_actions.
		$default_role = get_option( 'isoft_fmf_default_access_role', 'public' );
		$role         = sanitize_text_field( wp_unslash( $_POST['_isoft_fmf_access_role'] ?? $default_role ) );
		update_post_meta( $post_id, '_isoft_fmf_access_role', in_array( $role, $valid_roles, true ) ? $role : $default_role );

		// Agreement — rendered in Version & License box.
		update_post_meta( $post_id, '_isoft_fmf_require_agree', ! empty( $_POST['_isoft_fmf_require_agree'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_isoft_fmf_agree_text', wp_kses_post( wp_unslash( $_POST['_isoft_fmf_agree_text'] ?? '' ) ) );

		// Listing flags — both rendered in the Version & License box.
		update_post_meta( $post_id, '_isoft_fmf_featured', ! empty( $_POST['_isoft_fmf_featured'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_isoft_fmf_external_only', ! empty( $_POST['_isoft_fmf_external_only'] ) ? 1 : 0 );

		update_post_meta( $post_id, '_isoft_fmf_version', sanitize_text_field( wp_unslash( $_POST['_isoft_fmf_version'] ?? '' ) ) );
		update_post_meta( $post_id, '_isoft_fmf_changelog', wp_kses_post( wp_unslash( $_POST['_isoft_fmf_changelog'] ?? '' ) ) );
		update_post_meta( $post_id, '_isoft_fmf_license_id', absint( $_POST['_isoft_fmf_license_id'] ?? 0 ) );
		update_post_meta( $post_id, '_isoft_fmf_author_name', sanitize_text_field( wp_unslash( $_POST['_isoft_fmf_author_name'] ?? '' ) ) );
		update_post_meta( $post_id, '_isoft_fmf_author_url', esc_url_raw( wp_unslash( $_POST['_isoft_fmf_author_url'] ?? '' ) ) );
		update_post_meta( $post_id, '_isoft_fmf_date_published', sanitize_text_field( wp_unslash( $_POST['_isoft_fmf_date_published'] ?? '' ) ) );
	}

	// --- AJAX handlers ---

	public function ajax_delete_file(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );
		$file_id = absint( $_POST['file_id'] ?? 0 );
		if ( ! $file_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'isoft-fm-foundation' ) ) );
		}
		$file = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
		if ( ! $file || ! current_user_can( 'edit_post', (int) $file->download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}
		if ( ! ( new ISOFT_FMF_File_Manager() )->delete_file( $file_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete file.', 'isoft-fm-foundation' ) . self::db_error_suffix() ) );
		}
		wp_send_json_success();
	}

	public function ajax_save_order(): void {
		check_ajax_referer( 'isoft_fmf_admin', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}
		$order = isset( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per-element below.
		if ( ! is_array( $order ) ) {
			wp_send_json_error();
		}
		$sanitized = array();
		foreach ( $order as $fid => $pos ) {
			$sanitized[ absint( $fid ) ] = absint( $pos );
		}
		( new ISOFT_FMF_File_Manager() )->update_sort_order( $sanitized );
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
		$file_id = ( new ISOFT_FMF_File_Manager() )->add_external_link(
			$download_id,
			$url,
			array(
				'title'     => sanitize_text_field( wp_unslash( $_POST['title'] ?? $url ) ),
				'is_mirror' => (int) ! empty( $_POST['is_mirror'] ),
			)
		);
		if ( ! $file_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not add link.', 'isoft-fm-foundation' ) . self::db_error_suffix() ) );
		}
		wp_send_json_success( array( 'file_id' => $file_id ) );
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

		$manager = new ISOFT_FMF_File_Manager();
		$file    = $manager->get_file( $file_id );
		if ( ! $file || ! current_user_can( 'edit_post', (int) $file->download_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'isoft-fm-foundation' ) ), 403 );
		}

		$title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );

		if ( ! $manager->update_meta( $file_id, $title, $description ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not save changes.', 'isoft-fm-foundation' ) . self::db_error_suffix() ) );
		}

		wp_send_json_success( array( 'file' => $manager->get_file( $file_id ) ) );
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Reading the PHP-set integer error code; no admin input.
		$upload_error_code = isset( $_FILES['file']['error'] ) ? (int) $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $upload_error_code ) {
			wp_send_json_error( array( 'message' => self::upload_error_message( $upload_error_code ) ) );
		}

		$category_id = self::get_download_category( $download_id );
		if ( ! $category_id ) {
			wp_send_json_error( array( 'message' => __( 'Assign a category to this download and save before uploading files.', 'isoft-fm-foundation' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_handle_upload() validates the raw $_FILES entry below; individual fields used here are sanitized before use.
		$upload        = $_FILES['file'];
		$original_name = sanitize_file_name( wp_unslash( $upload['name'] ) );
		$upload_size   = isset( $upload['size'] ) ? (int) $upload['size'] : 0;
		$sanitized     = isoft_fmf_sanitize_filename( $original_name );

		if ( $sanitized['error'] ) {
			wp_send_json_error( array( 'message' => $sanitized['error'] ) );
		}

		$slug = $sanitized['slug'];
		if ( isoft_fmf_filename_collision( $slug, $category_id ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
					/* translators: %s: filename */
						__( 'A file named "%s" already exists in this category. Rename the file and try again.', 'isoft-fm-foundation' ),
						$slug
					),
				)
			);
		}

		ISOFT_FMF_Category_Folders::ensure( $category_id );
		$target_abs = isoft_fmf_category_fs_path( $category_id ) . '/' . $slug;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$handled = wp_handle_upload(
			$_FILES['file'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_handle_upload validates.
			array(
				'test_form' => false,
				'action'    => 'isoft_fmf_upload_file',
			)
		);
		if ( isset( $handled['error'] ) ) {
			wp_send_json_error( array( 'message' => $handled['error'] ) );
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem->move( $handled['file'], $target_abs, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to write the uploaded file to the category folder. Check write permissions on wp-content/uploads/isoft-fmf-files/.', 'isoft-fm-foundation' ) ) );
		}

		// Mime resolution: prefer server-side magic-byte detection, fall back
		// to WP's extension map. Never trust $_FILES['type'] (browser-supplied,
		// trivially spoofable).
		$detected_mime = function_exists( 'mime_content_type' ) ? mime_content_type( $target_abs ) : false;
		if ( ! $detected_mime ) {
			$ftype         = wp_check_filetype( $slug );
			$detected_mime = $ftype['type'] ?: 'application/octet-stream';
		}

		$rel_path = isoft_fmf_category_folder_path( $category_id ) . '/' . $slug;
		$manager  = new ISOFT_FMF_File_Manager();
		$file_id  = $manager->add_local_file(
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
			wp_send_json_error( array( 'message' => __( 'Could not save file record.', 'isoft-fm-foundation' ) . self::db_error_suffix() ) );
		}

		wp_send_json_success( array( 'file' => $manager->get_file( $file_id ) ) );
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

		$category_id = self::get_download_category( $download_id );
		if ( ! $category_id ) {
			wp_send_json_success(
				array(
					'files'    => array(),
					'category' => null,
				)
			);
		}

		$folder = isoft_fmf_category_fs_path( $category_id );
		if ( ! is_dir( $folder ) ) {
			wp_send_json_success(
				array(
					'files'    => array(),
					'category' => isoft_fmf_category_folder_path( $category_id ),
				)
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin meta box: listing files under the download's category folder; single-request freshness required.
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

		wp_send_json_success(
			array(
				'files'    => $items,
				'category' => $rel_base,
			)
		);
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

		// Path traversal guard — resolved path must stay under isoft_fmf_files_dir().
		$base = realpath( isoft_fmf_files_dir() );
		$abs  = realpath( "{$base}/{$rel_path}" );
		if ( ! $abs || ! $base || ! str_starts_with( $abs, $base ) || ! is_file( $abs ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'isoft-fm-foundation' ) ) );
		}

		$name    = basename( $abs );
		$manager = new ISOFT_FMF_File_Manager();
		$file_id = $manager->add_local_file(
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
			wp_send_json_error( array( 'message' => __( 'Could not save file record.', 'isoft-fm-foundation' ) . self::db_error_suffix() ) );
		}

		wp_send_json_success( array( 'file' => $manager->get_file( $file_id ) ) );
	}
}
