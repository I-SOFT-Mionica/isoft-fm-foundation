<?php
/**
 * Admin UI shell for the Licenses page: registers the submenu, renders
 * the React mount, and preserves the pre-0.12.0 public API (`get()`,
 * `get_all()`, `install_missing_seeds()`, `seed_defaults()`,
 * `bust_cache()`) as thin delegators onto [[ISOFT_FMF_License_Service]].
 *
 * All CRUD is served by [[ISOFT_FMF_Rest_Licenses]] and driven by the
 * React app in `blocks/licenses-page/`. The legacy `admin_init` POST
 * handler and its fallback form were demolished in 0.12.5.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_License_Manager {

	public const CACHE_GROUP = ISOFT_FMF_License_Service::CACHE_GROUP;

	private ISOFT_FMF_License_Service $service;

	public function __construct() {
		$this->service = new ISOFT_FMF_License_Service();
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	// ---------------------------------------------------------------------
	// Delegators — preserve the pre-0.12.0 public API.
	// ---------------------------------------------------------------------

	public static function seed_defaults(): array {
		return ISOFT_FMF_License_Service::seed_defaults();
	}

	public static function bust_cache( ?int $id = null ): void {
		ISOFT_FMF_License_Service::bust_cache( $id );
	}

	public function install_missing_seeds(): int {
		return $this->service->install_missing_seeds();
	}

	/** @return object[] */
	public function get_all(): array {
		return $this->service->list();
	}

	public function get( int $id ): ?object {
		return $this->service->get( $id );
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
