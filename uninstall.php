<?php
/**
 * Uninstall handler for I-Soft File Manager: Foundation Core.
 *
 * Runs when the plugin is deleted via the WP admin.
 * Controlled by the 'isfm_delete_data_on_uninstall' option (default: off).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall script: locals are bounded to this one-shot teardown; never globals.

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

// Delete all plugin options via the WP API.
// Enumerated rather than a wildcard DELETE so Plugin Check / WPCS don't flag
// the cleanup as a direct DB query. delete_option() is a no-op on missing
// keys, so legacy/never-saved entries are safe to list.
$options = array(
	// Settings (general)
	'isfm_default_access_role',
	'isfm_enable_counting',
	'isfm_enable_logging',
	'isfm_enable_detailed_logging',
	'isfm_log_retention_days',
	'isfm_enable_pdf_thumbnails',
	'isfm_pdf_thumb_width',
	'isfm_pdf_thumb_height',
	'isfm_pdf_thumb_quality',
	'isfm_overwrite_pdf_thumbnail',
	// Settings (display)
	'isfm_default_button_text',
	'isfm_listing_layout',
	'isfm_items_per_page',
	'isfm_show_file_size',
	'isfm_show_download_count',
	'isfm_show_date',
	'isfm_date_format',
	// Settings (security)
	'isfm_serve_method',
	'isfm_nginx_config_confirmed',
	'isfm_rate_limit_per_hour',
	'isfm_block_user_agents',
	'isfm_enable_zip_bundle',
	'isfm_hotlink_protection',
	// Settings (files)
	'isfm_allowed_extensions',
	'isfm_cyrillic_titles',
	// Settings (advanced)
	'isfm_archive_slug',
	'isfm_category_slug',
	'isfm_tag_slug',
	'isfm_delete_data_on_uninstall',
	// Settings (maintenance / integrity)
	'isfm_integrity_check_enabled',
	'isfm_integrity_check_time',
	'isfm_integrity_autorelink',
	'isfm_integrity_use_inode',
	'isfm_integrity_last_run',
	// Internal state
	'isfm_admin_notices',
	'isfm_db_version',
	'isfm_flush_rewrite_rules',
	'isfm_hot_calculated_at',
	'isfm_hot_downloads',
	'isfm_pdf_notice_dismissed',
	// Legacy (removed in earlier versions; cleaned for upgrade safety)
	'isfm_custom_css',
	'isfm_custom_folder',
	'isfm_storage_mode',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete known transients (per-IP rate-limit transients are dynamic and
// self-expire via their TTL; leaving them is acceptable).
$transients = array(
	'isfm_missing_count',
	'isfm_stats_overview',
);
foreach ( $transients as $transient ) {
	delete_transient( $transient );
}

// Delete per-user category ACL meta from all users.
delete_metadata( 'user', 0, '_isfm_allowed_categories', '', true );

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
