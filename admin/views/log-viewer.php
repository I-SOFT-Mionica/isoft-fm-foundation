<?php
/**
 * Download Log — thin adapter that mounts the admin shell.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
	wp_die( esc_html__( 'You do not have permission to view the download log.', 'isoft-fm-foundation' ), 403 );
}

$isoft_fmf_section = 'log';
require __DIR__ . '/admin-shell-mount.php';
