<?php
/**
 * Enqueues the React Download Log bundle on the
 * Downloads > Download Log admin screen.
 *
 * Same mount-and-fallback pattern as ISOFT_FMF_Licenses_Page /
 * ISOFT_FMF_Stats_Page. Extra requirement: pull in wp-dataviews
 * (script + style) since the log page is the first DataViews
 * consumer in this plugin.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Log_Page {

	public const SCRIPT_HANDLE = 'isoft-fmf-log';

	/** Admin-page hook suffix for the Download Log submenu (parent_slug + page_slug). */
	public const PAGE_HOOK_SUFFIX = 'isoft_fmf_file_page_isoft-fmf-log';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( self::PAGE_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/log-page.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/log-page.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? ISOFT_FMF_VERSION,
			true
		);

		// wp-components covers the Notice / Spinner / Button. DataViews
		// styles ride along inside our bundle's JS-injected CSS — we
		// can't enqueue `wp-dataviews` style independently because WP
		// core only registers that handle inside editor contexts (see
		// note in webpack.config.js explaining why DataViews itself is
		// bundled rather than externalised).
		wp_enqueue_style( 'wp-components' );

		// Shared DataViews-table styling that gives the React-mounted
		// list pages the wp-list-table look (borders, zebra striping,
		// full-width container). The base DataViews styling is tuned for
		// the site editor and reads anaemic against the gray WP admin
		// background. Apply by adding the `isoft-fmf-dataviews-table`
		// class to the mount-node wrapper.
		wp_enqueue_style(
			'isoft-fmf-dataviews-table',
			ISOFT_FMF_PLUGIN_URL . 'admin/css/dataviews-table.css',
			array( 'wp-components' ),
			ISOFT_FMF_VERSION
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'isoft-fm-foundation',
			ISOFT_FMF_PLUGIN_DIR . 'languages'
		);
	}
}
