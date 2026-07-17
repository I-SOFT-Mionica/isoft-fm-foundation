<?php
/**
 * Licenses page — thin adapter that mounts the admin shell.
 *
 * The React shell in blocks/admin-shell/index.js reads
 * data-section="licenses" and renders the Licenses section, plus the
 * shared tab strip that offers client-side nav to the other 4 sections.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
	wp_die( esc_html__( 'You do not have permission to manage licenses.', 'isoft-fm-foundation' ), 403 );
}

$isoft_fmf_section = 'licenses';
require __DIR__ . '/admin-shell-mount.php';
