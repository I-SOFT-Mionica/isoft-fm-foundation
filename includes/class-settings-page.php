<?php
/**
 * Enqueues the React Settings bundle on the
 * Downloads > Settings admin screen.
 *
 * Scope: the 4 option-driven tabs (General, Display, Security, Advanced)
 * become a React app. The Maintenance and Extensions tabs stay
 * PHP-rendered because they're action/marketing surfaces with no
 * benefit from a React port (stateful integrity-check lock, server
 * action buttons, marketing cards). The PHP view branches per-tab so
 * the user sees either the React mount or the legacy form on the
 * same nav-tab-wrapper navigation.
 *
 * Handle uses the `-page` suffix per [[script-handle-collisions]]:
 * `isoft-fmf-settings-page`, never `isoft-fmf-settings`, to avoid
 * silently colliding with any legacy handle that class-settings.php
 * might use for the admin stylesheet.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Settings_Page {

	public const SCRIPT_HANDLE = 'isoft-fmf-settings-page';

	/** Admin-page hook suffix for the Settings submenu (parent_slug + page_slug). */
	public const PAGE_HOOK_SUFFIX = 'isoft_fmf_file_page_isoft-fmf-settings';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( self::PAGE_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/settings-page.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/settings-page.js',
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
