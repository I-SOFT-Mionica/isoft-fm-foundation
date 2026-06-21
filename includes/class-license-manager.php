<?php
/**
 * Admin UI shell for the licenses page. Data layer lives in
 * [[ISOFT_FMF_License_Service]] — the historical methods on this class
 * (`get()`, `get_all()`, `install_missing_seeds()`, `seed_defaults()`,
 * `bust_cache()`) are kept as thin delegators so existing call sites
 * (templates, resolver, shortcodes, activator, tests) keep working without
 * a sweeping rename in the same PR as the service extraction.
 *
 * The 0.12.5 demolition phase will migrate those callers to the service
 * directly and remove the delegators.
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
		add_action( 'admin_init', array( $this, 'handle_form_actions' ) );
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
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Licenses', 'isoft-fm-foundation' ),
			__( 'Licenses', 'isoft-fm-foundation' ),
			'isoft_fmf_manage_settings',
			'isoft-fmf-licenses',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/licenses-page.php';
	}

	public function handle_form_actions(): void {
		if ( empty( $_POST['isoft_fmf_license_action'] ) ) {
			return;
		}
		check_admin_referer( 'isoft_fmf_license_action' );
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'isoft-fm-foundation' ) );
		}

		$action = sanitize_text_field( wp_unslash( $_POST['isoft_fmf_license_action'] ) );
		if ( 'save' === $action ) {
			$this->handle_save();
		} elseif ( 'delete' === $action ) {
			$this->handle_delete( absint( $_POST['license_id'] ?? 0 ) );
		} elseif ( 'restore_seeds' === $action ) {
			$this->handle_restore_seeds();
		}
	}

	private function handle_save(): void {
		// Nonce verified by caller (handle_form_actions).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id   = absint( $_POST['license_id'] ?? 0 );
		$data = array(
			'title'       => $_POST['title'] ?? '',
			'slug'        => $_POST['slug'] ?? ( $_POST['title'] ?? '' ),
			'description' => $_POST['description'] ?? '',
			'full_text'   => $_POST['full_text'] ?? '',
			'url'         => $_POST['url'] ?? '',
			'is_default'  => ! empty( $_POST['is_default'] ),
			'sort_order'  => $_POST['sort_order'] ?? 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $id ) {
			$this->service->update( $id, $data );
		} else {
			$this->service->create( $data );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'isoft-fmf-licenses',
					'post_type' => 'isoft_fmf_file',
					'saved'     => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	private function handle_delete( int $id ): void {
		$this->service->delete( $id );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'isoft-fmf-licenses',
					'post_type' => 'isoft_fmf_file',
					'deleted'   => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Admin action: install any seeded licenses missing by slug on this
	 * install. Doubles as the "mass-update" channel for plugin releases
	 * that add new seeds — admins click once per release to pick up new
	 * canonical entries. Existing rows are never touched (add-only by slug).
	 */
	private function handle_restore_seeds(): void {
		$added = $this->service->install_missing_seeds();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'isoft-fmf-licenses',
					'post_type' => 'isoft_fmf_file',
					'restored'  => (int) $added,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
