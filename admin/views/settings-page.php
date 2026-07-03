<?php
/**
 * Settings page — thin adapter that mounts the admin shell.
 *
 * When the URL asks for the PHP-rendered Maintenance or Extensions
 * tabs, this view still delegates to the shared partial — the shell's
 * Settings section will detect the tab via bootstrap.initialTab and
 * kick a client-side nav to the PHP tab URL, then the browser reloads
 * to those legacy pages.
 *
 * For all four schema tabs (general / display / security / advanced),
 * the shell renders the tab in-place; no navigation happens.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector; nonce belongs on form submit, not nav.
$isoft_fmf_active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

// Maintenance and Extensions are legacy PHP tabs — no React port. Load
// their PHP directly when the URL requests them, bypassing the shell.
if ( 'maintenance' === $isoft_fmf_active_tab ) {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'I-Soft File Manager: Foundation Settings', 'isoft-fm-foundation' ); ?></h1>
		<?php require __DIR__ . '/maintenance-tab.php'; ?>
	</div>
	<?php
	return;
}

if ( 'extensions' === $isoft_fmf_active_tab ) {
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'I-Soft File Manager: Foundation Settings', 'isoft-fm-foundation' ); ?></h1>
		<?php require __DIR__ . '/extensions-tab.php'; ?>
	</div>
	<?php
	return;
}

$isoft_fmf_section = 'settings';
require __DIR__ . '/admin-shell-mount.php';
