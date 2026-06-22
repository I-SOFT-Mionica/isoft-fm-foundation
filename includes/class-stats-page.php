<?php
/**
 * Enqueues the React Statistics dashboard bundle on the
 * Downloads > Statistics admin screen.
 *
 * Same mount-and-fallback pattern as ISOFT_FMF_Licenses_Page: the view
 * at admin/views/stats-dashboard.php branches on wp_script_is(); when
 * the bundle is enqueued the React app renders the dashboard, otherwise
 * the original PHP markup falls through. Existing CSS in
 * admin/css/admin-styles.css carries the visual treatment for both
 * paths because the React app reuses the same class names
 * (.isoft-fmf-stat-cards, .isoft-fmf-bar-chart, etc.).
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Stats_Page {

	public const SCRIPT_HANDLE = 'isoft-fmf-stats';

	/** Admin-page hook suffix for the Statistics submenu (parent_slug + page_slug). */
	public const PAGE_HOOK_SUFFIX = 'isoft_fmf_file_page_isoft-fmf-stats';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( self::PAGE_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/stats-page.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/stats-page.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? ISOFT_FMF_VERSION,
			true
		);

		wp_enqueue_style( 'wp-components' );

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'isoft-fm-foundation',
			ISOFT_FMF_PLUGIN_DIR . 'languages'
		);
	}
}
