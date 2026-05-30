<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Settings {

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_flush_rewrite' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		$isfm_pages = array(
			'isfm_file_page_isfm-stats',
			'isfm_file_page_isfm-log',
			'isfm_file_page_isfm-settings',
			'isfm_file_page_isfm-broken-links',
		);
		if ( ! in_array( $hook, $isfm_pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'isfm-admin', ISFM_PLUGIN_URL . 'admin/css/admin-style.css', array(), ISFM_VERSION );

		if ( 'isfm_file_page_isfm-broken-links' === $hook ) {
			wp_enqueue_script( 'isfm-broken-links', ISFM_PLUGIN_URL . 'admin/js/broken-links.js', array( 'jquery' ), ISFM_VERSION, true );
			wp_localize_script(
				'isfm-broken-links',
				'isfmBrokenLinks',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'isfm_broken_links' ),
					'i18n'    => array(
						'confirmMoveBack' => __( 'Move the file back to the original folder?', 'isoft-fm-foundation' ),
						'confirmReassign' => __( 'Move this download (and all its files) to the new category?', 'isoft-fm-foundation' ),
						'confirmSplit'    => __( 'Create a new download for this file in its new category?', 'isoft-fm-foundation' ),
						'confirmDetach'   => __( 'Remove this file from the download? The file on disk will not be deleted.', 'isoft-fm-foundation' ),
						'generic_error'   => __( 'Action failed. Please reload the page and try again.', 'isoft-fm-foundation' ),
					),
				)
			);
		}
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=isfm_file',
			__( 'Statistics', 'isoft-fm-foundation' ),
			__( 'Statistics', 'isoft-fm-foundation' ),
			'isfm_view_logs',
			'isfm-stats',
			array( $this, 'render_stats' )
		);
		add_submenu_page(
			'edit.php?post_type=isfm_file',
			__( 'Download Log', 'isoft-fm-foundation' ),
			__( 'Download Log', 'isoft-fm-foundation' ),
			'isfm_view_logs',
			'isfm-log',
			array( $this, 'render_log' )
		);

		// Broken Links — label carries a count badge when rows are flagged.
		$missing_count = ISFM_File_Integrity::missing_count();
		$broken_label  = __( 'Broken Links', 'isoft-fm-foundation' );
		if ( $missing_count > 0 ) {
			$broken_label .= ' <span class="awaiting-mod isfm-broken-badge">' . number_format_i18n( $missing_count ) . '</span>';
		}
		add_submenu_page(
			'edit.php?post_type=isfm_file',
			__( 'Broken Links', 'isoft-fm-foundation' ),
			$broken_label,
			'isfm_manage_settings',
			'isfm-broken-links',
			array( $this, 'render_broken_links' )
		);

		add_submenu_page(
			'edit.php?post_type=isfm_file',
			__( 'Settings', 'isoft-fm-foundation' ),
			__( 'Settings', 'isoft-fm-foundation' ),
			'isfm_manage_settings',
			'isfm-settings',
			array( $this, 'render_page' )
		);
	}

	public function render_broken_links(): void {
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view broken links.', 'isoft-fm-foundation' ) );
		}
		$table = new ISFM_Broken_Links_Table();
		$table->prepare_items();
		require ISFM_PLUGIN_DIR . 'admin/views/broken-links-page.php';
	}

	public function render_stats(): void {
		if ( ! current_user_can( 'isfm_view_logs' ) ) {
			wp_die( esc_html__( 'You do not have permission to view statistics.', 'isoft-fm-foundation' ) );
		}
		require ISFM_PLUGIN_DIR . 'admin/views/stats-dashboard.php';
	}

	public function render_log(): void {
		if ( ! current_user_can( 'isfm_view_logs' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the download log.', 'isoft-fm-foundation' ) );
		}
		$table = new ISFM_Log_Table();
		$table->prepare_items();
		require ISFM_PLUGIN_DIR . 'admin/views/log-viewer.php';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'isoft-fm-foundation' ) );
		}
		require ISFM_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	public function register_settings(): void {
		$options = array(
			// General
			'isfm_default_access_role'      => 'sanitize_text_field',
			'isfm_enable_counting'          => 'absint',
			'isfm_enable_logging'           => 'absint',
			'isfm_enable_detailed_logging'  => 'absint',
			'isfm_log_retention_days'       => 'absint',
			'isfm_enable_pdf_thumbnails'    => 'absint',
			'isfm_pdf_thumb_width'          => 'absint',
			'isfm_pdf_thumb_height'         => 'absint',
			'isfm_pdf_thumb_quality'        => 'absint',
			'isfm_overwrite_pdf_thumbnail'  => 'absint',
			// Display
			'isfm_default_button_text'      => 'sanitize_text_field',
			'isfm_listing_layout'           => 'sanitize_text_field',
			'isfm_items_per_page'           => 'absint',
			'isfm_show_file_size'           => 'absint',
			'isfm_show_download_count'      => 'absint',
			'isfm_show_date'                => 'absint',
			'isfm_date_format'              => 'sanitize_text_field',
			// Security
			'isfm_serve_method'             => 'sanitize_text_field',
			'isfm_nginx_config_confirmed'   => 'absint',
			'isfm_rate_limit_per_hour'      => 'absint',
			'isfm_block_user_agents'        => 'sanitize_textarea_field', // Planned: user-agent blocklist enforcement in download handler.
			'isfm_enable_zip_bundle'        => 'absint', // Planned: combine multi-file downloads into a single ZIP on the fly.
			'isfm_hotlink_protection'       => 'absint',
			// Files
			'isfm_allowed_extensions'       => 'sanitize_textarea_field',
			'isfm_cyrillic_titles'          => 'absint',
			// Advanced
			'isfm_archive_slug'             => 'sanitize_title',
			'isfm_category_slug'            => 'sanitize_title',
			'isfm_tag_slug'                 => 'sanitize_title',
			'isfm_delete_data_on_uninstall' => 'absint',
			// Maintenance / File integrity
			'isfm_integrity_check_enabled'  => 'absint',
			'isfm_integrity_check_time'     => array( $this, 'sanitize_time' ),
			'isfm_integrity_autorelink'     => 'absint',
			'isfm_integrity_use_inode'      => 'absint',
		);

		foreach ( $options as $option => $sanitize ) {
			register_setting( 'isfm_settings', $option, array( 'sanitize_callback' => $sanitize ) );
		}
	}

	public function sanitize_time( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
			$h = max( 0, min( 23, (int) $m[1] ) );
			$i = max( 0, min( 59, (int) $m[2] ) );
			return sprintf( '%02d:%02d', $h, $i );
		}
		return '02:30';
	}

	public function handle_flush_rewrite(): void {
		if ( isset( $_POST['isfm_flush_rewrite'] ) && current_user_can( 'isfm_manage_settings' ) ) {
			check_admin_referer( 'isfm_settings-options' );
			flush_rewrite_rules();
			add_settings_error( 'isfm_settings', 'flushed', __( 'Rewrite rules flushed.', 'isoft-fm-foundation' ), 'updated' );
		}
	}
}
