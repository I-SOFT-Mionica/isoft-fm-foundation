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

	/**
	 * One option group per tab — so saving one tab cannot wipe checkboxes
	 * on another. WP's Settings API iterates every option registered to
	 * the submitted group and reads $_POST[option]; unchecked checkboxes
	 * are absent from POST and would absint('') → 0, silently unsetting
	 * them. Grouping per tab keeps each save scoped to its tab's fields.
	 *
	 * Schema lives on [[ISOFT_FMF_Settings_Service]] so both this legacy
	 * register_setting() path and the REST controller share one source.
	 */
	public function register_settings(): void {
		foreach ( ISOFT_FMF_Settings_Service::groups() as $group => $options ) {
			foreach ( $options as $option => $sanitize ) {
				register_setting( $group, $option, array( 'sanitize_callback' => $sanitize ) );
			}
		}
	}

	// Backward-compat delegators — pre-0.12.0 these were instance methods on
	// this class. The schema references the static versions on the service
	// now; these stay so any external caller passing array( $settings,
	// 'sanitize_time' ) keeps working until 0.12.5 demolition.

	public function sanitize_time( $value ): string {
		return ISOFT_FMF_Settings_Service::sanitize_time( $value );
	}

	public function sanitize_link_target( $value ): string {
		return ISOFT_FMF_Settings_Service::sanitize_link_target( $value );
	}

	public function handle_flush_rewrite(): void {
		if ( isset( $_POST['isoft_fmf_flush_rewrite'] ) && current_user_can( 'isoft_fmf_manage_settings' ) ) {
			// The Advanced tab's form posts to options.php with the
			// isoft_fmf_advanced group, so settings_fields() generates a
			// nonce keyed to that group name.
			check_admin_referer( 'isoft_fmf_advanced-options' );
			( new ISOFT_FMF_Settings_Service() )->flush_rewrite();
			add_settings_error( 'isoft_fmf_settings', 'flushed', __( 'Rewrite rules flushed.', 'isoft-fm-foundation' ), 'updated' );
		}
	}
}
