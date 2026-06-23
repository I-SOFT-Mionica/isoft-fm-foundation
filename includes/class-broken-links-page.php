<?php
/**
 * Enqueues the React Broken Links bundle on the
 * Downloads > Broken Links admin screen.
 *
 * Same mount-and-fallback pattern as the other 0.12.x React pages.
 * Pulls in the shared isoft-fmf-dataviews-table style; @wordpress/dataviews
 * itself is bundled inside the JS entry (see webpack.config.js comment
 * — wp-dataviews script handle is only registered inside editor
 * contexts in WP core).
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Broken_Links_Page {

	// Handle deliberately suffixed `-page` to avoid colliding with the
	// `isoft-fmf-broken-links` handle the legacy class-settings.php
	// enqueue still uses for admin/js/broken-links.js (the jQuery
	// fallback). When both classes registered the same handle, Settings
	// won (registered first), the React bundle silently no-op'd, and
	// the page rendered with the React mount div but no React script
	// tag, leaving the mount empty. The legacy handle gets dropped
	// entirely in 0.12.5 demolition.
	public const SCRIPT_HANDLE = 'isoft-fmf-broken-links-page';

	/** Admin-page hook suffix for the Broken Links submenu (parent_slug + page_slug). */
	public const PAGE_HOOK_SUFFIX = 'isoft_fmf_file_page_isoft-fmf-broken-links';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( self::PAGE_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/broken-links-page.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/broken-links-page.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? ISOFT_FMF_VERSION,
			true
		);

		wp_enqueue_style( 'wp-components' );
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
