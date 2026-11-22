<?php
/**
 * Admin UI shell for the Licenses page: registers the submenu and renders
 * the React mount. The data layer is ISOFT_FMF_License_Service (the
 * pre-0.12.0 delegators here were removed in 0.19.0).
 *
 * All CRUD is served by [[ISOFT_FMF_Rest_Licenses]] and driven by the
 * React app in `blocks/licenses-page/`. The legacy `admin_init` POST
 * handler and its fallback form were demolished in 0.12.5.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_License_Manager {

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	// ---------------------------------------------------------------------
	// Admin UI.
	// ---------------------------------------------------------------------

	public function register_menu(): void {
		// Position 16 places Licenses right after Tags in the CPT
		// submenu. WordPress core assigns each taxonomy submenu entry
		// position 15 (see wp-admin/menu.php's CPT registration block);
		// 16 lands us adjacent to the last one — Tags — so the Content
		// group (Categories, Tags, Licenses) reads as a visual cluster.
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Licenses', 'isoft-fm-foundation' ),
			__( 'Licenses', 'isoft-fm-foundation' ),
			'isoft_fmf_manage_settings',
			'isoft-fmf-licenses',
			array( $this, 'render_page' ),
			16
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/licenses-page.php';
	}
}
