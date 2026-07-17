<?php
/**
 * Enqueues the editor-sidebar Gutenberg plugin on the block-editor screens
 * for the isoft_fmf_file post type.
 *
 * The JS itself (blocks/editor-sidebar/index.js, built to
 * blocks/build/editor-sidebar.js by @wordpress/scripts) registers a
 * PluginDocumentSettingPanel via registerPlugin(). Built-in WP behavior
 * then auto-mounts the panel whenever the editor screen loads with this
 * script enqueued.
 *
 * The screen-scoping happens at enqueue time (here) AND at registration
 * time (the ScopedSidebar wrapper in index.js bails when post type is
 * not isoft_fmf_file). Defense in depth: if WP starts auto-enqueueing
 * block-editor assets globally on a future release, the JS guard still
 * keeps us off other post types' editors.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Editor_Sidebar {

	public const SCRIPT_HANDLE = 'isoft-fmf-editor-sidebar';

	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		// Bail if we're on the block editor for any other post type. WP's
		// enqueue_block_editor_assets fires on every Gutenberg-mounted
		// screen, including widgets / site-editor.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'isoft_fmf_file' !== ( $screen->post_type ?? '' ) ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/editor-sidebar.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			// Built asset missing — local dev where `npm run build` hasn't run,
			// or a malformed deploy. Leaving silently is safer than throwing
			// at the user; the existing meta boxes still render below the
			// editor canvas so editing keeps working.
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/editor-sidebar.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? ISOFT_FMF_VERSION,
			true
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'isoft-fm-foundation',
			ISOFT_FMF_PLUGIN_DIR . 'languages'
		);
	}
}
