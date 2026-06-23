<?php
/**
 * Enqueues the React profile-ACL bundle on user profile screens.
 *
 * Mounts on profile.php (the editing-own-profile screen) and
 * user-edit.php (admin editing another user). Same mount-and-fallback
 * pattern as the other 0.12.x React pages: when the bundle loads, the
 * existing PHP <details>/<summary> checkbox tree in
 * ISOFT_FMF_Category_ACL::render_profile_field() is hidden in favour
 * of the React mount; otherwise the PHP tree stays as the no-JS
 * fallback.
 *
 * Handle suffixed `-page` per the collision lesson (PR #50).
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Profile_ACL_Page {

	public const SCRIPT_HANDLE = 'isoft-fmf-profile-acl-page';

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		// Only admins ever see the ACL field. No JS for users without
		// manage_options — keeps the bundle off non-admin profile pages.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/profile-acl.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/profile-acl.js',
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
