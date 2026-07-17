<?php
/**
 * Enqueues the React taxonomy-form bundle on the
 * Downloads > Categories add/edit screens.
 *
 * Scope (0.12.4 sub-PR 2): the icon field on category add + edit gets
 * a media-library picker via MediaUpload. The access-role and license
 * selects stay as PHP dropdowns — they already work and a React port
 * wouldn't improve the UX. License-inheritance preview (closest-set
 * resolver) is deferred until the editor-sidebar surface where it
 * actually shows what a download would inherit.
 *
 * Hook suffix: edit-tags.php for add screens, term.php for edit
 * screens. Both are taxonomy=isoft_fmf_category — gated below.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Taxonomy_Form_Page {

	public const SCRIPT_HANDLE = 'isoft-fmf-taxonomy-form-page';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}

		// Both hooks fire for every taxonomy; scope to ours via the
		// $taxonomy query var that WP sets on both screens.
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen-routing parameter; nonce belongs on form submit.
		if ( 'isoft_fmf_category' !== $taxonomy ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/taxonomy-form.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		// wp_enqueue_media() is what makes the WP media library modal
		// available to MediaUpload. Without this the modal won't open
		// (the underlying wp.media().open() throws).
		wp_enqueue_media();

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/taxonomy-form.js',
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
