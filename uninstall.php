<?php
/**
 * Uninstall handler for I-Soft File Manager: Foundation Core.
 *
 * Runs when the plugin is deleted via the WP admin.
 * Controlled by the 'isfm_delete_data_on_uninstall' option (default: off).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'isfm_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall runs once; table drop cannot go through higher-level APIs.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isfm_files" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isfm_download_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isfm_licenses" );

// Delete all isfm_file posts and their meta
$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'isfm_file'" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( $post_ids as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

// Delete all isfm_category terms
$terms = get_terms(
	array(
		'taxonomy'   => 'isfm_category',
		'hide_empty' => false,
	)
);
if ( is_array( $terms ) ) {
	foreach ( $terms as $term ) {
		wp_delete_term( $term->term_id, 'isfm_category' );
	}
}

// Delete all plugin options
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup; no WP API for wildcard option delete.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'isfm_' ) . '%'
	)
);

// Remove capabilities from all roles
$capabilities = array(
	'isfm_view_downloads',
	'isfm_create_downloads',
	'isfm_edit_own_downloads',
	'isfm_edit_all_downloads',
	'isfm_delete_downloads',
	'isfm_manage_categories',
	'isfm_view_logs',
	'isfm_export_logs',
	'isfm_manage_settings',
);

$role_names = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );
foreach ( $role_names as $role_name ) {
	$role = get_role( $role_name );
	if ( ! $role ) {
		continue;
	}
	foreach ( $capabilities as $cap ) {
		$role->remove_cap( $cap );
	}
}

// Delete custom upload folder
$upload_dir = wp_upload_dir();
$custom_dir = $upload_dir['basedir'] . '/isfm-files';
if ( is_dir( $custom_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}
	$wp_filesystem->delete( $custom_dir, true, 'd' );
}

flush_rewrite_rules();
