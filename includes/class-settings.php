<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Settings {

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_flush_rewrite' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		$isoft_fmf_pages = array(
			'isoft_fmf_file_page_isoft-fmf-stats',
			'isoft_fmf_file_page_isoft-fmf-log',
			'isoft_fmf_file_page_isoft-fmf-settings',
			'isoft_fmf_file_page_isoft-fmf-broken-links',
		);
		if ( ! in_array( $hook, $isoft_fmf_pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'isoft-fmf-admin', ISOFT_FMF_PLUGIN_URL . 'admin/css/admin-style.css', array(), ISOFT_FMF_VERSION );

		if ( 'isoft_fmf_file_page_isoft-fmf-broken-links' === $hook ) {
			wp_enqueue_script( 'isoft-fmf-broken-links', ISOFT_FMF_PLUGIN_URL . 'admin/js/broken-links.js', array( 'jquery' ), ISOFT_FMF_VERSION, true );
			wp_localize_script(
				'isoft-fmf-broken-links',
				'isfmBrokenLinks',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'isoft_fmf_broken_links' ),
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
			'edit.php?post_type=isoft_fmf_file',
			__( 'Statistics', 'isoft-fm-foundation' ),
			__( 'Statistics', 'isoft-fm-foundation' ),
			'isoft_fmf_view_logs',
			'isoft-fmf-stats',
			array( $this, 'render_stats' )
		);
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Download Log', 'isoft-fm-foundation' ),
			__( 'Download Log', 'isoft-fm-foundation' ),
			'isoft_fmf_view_logs',
			'isoft-fmf-log',
			array( $this, 'render_log' )
		);

		// Broken Links — label carries a count badge when rows are flagged.
		$missing_count = ISOFT_FMF_File_Integrity::missing_count();
		$broken_label  = __( 'Broken Links', 'isoft-fm-foundation' );
		if ( $missing_count > 0 ) {
			$broken_label .= ' <span class="awaiting-mod isoft-fmf-broken-badge">' . number_format_i18n( $missing_count ) . '</span>';
		}
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Broken Links', 'isoft-fm-foundation' ),
			$broken_label,
			'isoft_fmf_manage_settings',
			'isoft-fmf-broken-links',
			array( $this, 'render_broken_links' )
		);

		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Settings', 'isoft-fm-foundation' ),
			__( 'Settings', 'isoft-fm-foundation' ),
			'isoft_fmf_manage_settings',
			'isoft-fmf-settings',
			array( $this, 'render_page' )
		);
	}

	public function render_broken_links(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view broken links.', 'isoft-fm-foundation' ) );
		}
		$table = new ISOFT_FMF_Broken_Links_Table();
		$table->prepare_items();
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/broken-links-page.php';
	}

	public function render_stats(): void {
		if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
			wp_die( esc_html__( 'You do not have permission to view statistics.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/stats-dashboard.php';
	}

	public function render_log(): void {
		if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the download log.', 'isoft-fm-foundation' ) );
		}
		$table = new ISOFT_FMF_Log_Table();
		$table->prepare_items();
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/log-viewer.php';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	public function register_settings(): void {
		$options = array(
			// General
			'isoft_fmf_default_access_role'      => 'sanitize_text_field',
			'isoft_fmf_enable_counting'          => 'absint',
			'isoft_fmf_enable_logging'           => 'absint',
			'isoft_fmf_enable_detailed_logging'  => 'absint',
			'isoft_fmf_log_retention_days'       => 'absint',
			'isoft_fmf_enable_pdf_thumbnails'    => 'absint',
			'isoft_fmf_pdf_thumb_width'          => 'absint',
			'isoft_fmf_pdf_thumb_height'         => 'absint',
			'isoft_fmf_pdf_thumb_quality'        => 'absint',
			'isoft_fmf_overwrite_pdf_thumbnail'  => 'absint',
			// Display
			'isoft_fmf_default_button_text'      => 'sanitize_text_field',
			'isoft_fmf_listing_layout'           => 'sanitize_text_field',
			'isoft_fmf_items_per_page'           => 'absint',
			'isoft_fmf_show_file_size'           => 'absint',
			'isoft_fmf_show_download_count'      => 'absint',
			'isoft_fmf_show_date'                => 'absint',
			'isoft_fmf_date_format'              => 'sanitize_text_field',
			// Security
			'isoft_fmf_serve_method'             => 'sanitize_text_field',
			'isoft_fmf_nginx_config_confirmed'   => 'absint',
			'isoft_fmf_rate_limit_per_hour'      => 'absint',
			'isoft_fmf_block_user_agents'        => 'sanitize_textarea_field',
			'isoft_fmf_enable_zip_bundle'        => 'absint',
			'isoft_fmf_enable_zip_cache'         => 'absint',
			'isoft_fmf_zip_cache_days'           => 'absint',
			'isoft_fmf_hotlink_protection'       => 'absint',
			// Files
			'isoft_fmf_allowed_extensions'       => 'sanitize_textarea_field',
			'isoft_fmf_cyrillic_titles'          => 'absint',
			// Advanced
			'isoft_fmf_archive_slug'             => 'sanitize_title',
			'isoft_fmf_category_slug'            => 'sanitize_title',
			'isoft_fmf_tag_slug'                 => 'sanitize_title',
			'isoft_fmf_delete_data_on_uninstall' => 'absint',
			// Maintenance / File integrity
			'isoft_fmf_integrity_check_enabled'  => 'absint',
			'isoft_fmf_integrity_check_time'     => array( $this, 'sanitize_time' ),
			'isoft_fmf_integrity_autorelink'     => 'absint',
			'isoft_fmf_integrity_use_inode'      => 'absint',
		);

		foreach ( $options as $option => $sanitize ) {
			register_setting( 'isoft_fmf_settings', $option, array( 'sanitize_callback' => $sanitize ) );
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
		if ( isset( $_POST['isoft_fmf_flush_rewrite'] ) && current_user_can( 'isoft_fmf_manage_settings' ) ) {
			check_admin_referer( 'isoft_fmf_settings-options' );
			flush_rewrite_rules();
			add_settings_error( 'isoft_fmf_settings', 'flushed', __( 'Rewrite rules flushed.', 'isoft-fm-foundation' ), 'updated' );
		}
	}
}
