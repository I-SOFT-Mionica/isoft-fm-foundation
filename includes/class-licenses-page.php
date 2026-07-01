<?php
/**
 * Enqueues the React Licenses page bundle on the Downloads > Licenses
 * admin screen.
 *
 * The PHP view at admin/views/licenses-page.php emits a mount node
 * `<div id="isoft-fmf-licenses-root">` and a JS-disabled `<noscript>`
 * notice. When this script is enqueued, the React app takes over; the
 * PHP fallback form was demolished in 0.12.5, so a missing bundle now
 * shows only the noscript notice.
 *
 * Screen scoping happens at the hook-suffix level: the Licenses submenu
 * registers under hook suffix 'isoft_fmf_file_page_isoft-fmf-licenses'
 * (built from parent slug + page slug). The enqueue callback receives
 * the current admin-page hook suffix and bails on every other screen.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Licenses_Page {

	public const SCRIPT_HANDLE = 'isoft-fmf-licenses';

	/** Admin-page hook suffix for the Licenses submenu (parent_slug + page_slug). */
	public const PAGE_HOOK_SUFFIX = 'isoft_fmf_file_page_isoft-fmf-licenses';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( self::PAGE_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/licenses-page.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			// Built asset missing — local dev where `npm run build` hasn't
			// run, or a malformed deploy. Leaving silently lets the PHP
			// fallback render so admins can still manage licenses.
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/licenses-page.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? ISOFT_FMF_VERSION,
			true
		);

		// @wordpress/components Modal / Notice / ConfirmDialog styles ride
		// on wp-components. Without this the modal renders unstyled (no
		// backdrop, broken layout).
		wp_enqueue_style( 'wp-components' );

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'isoft-fm-foundation',
			ISOFT_FMF_PLUGIN_DIR . 'languages'
		);
	}
}
