<?php
/**
 * Uninstall handler for I-Soft File Manager: Foundation Core.
 *
 * Runs when the plugin is deleted via the WP admin.
 * Controlled by the 'isoft_fmf_delete_data_on_uninstall' option (default: off).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall script: locals are bounded to this one-shot teardown; never globals.

if ( ! get_option( 'isoft_fmf_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall runs once; table drop cannot go through higher-level APIs.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isoft_fmf_files" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isoft_fmf_download_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isoft_fmf_download_daily" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}isoft_fmf_licenses" );

// Delete all isoft_fmf_file posts and their meta
$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'isoft_fmf_file'" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( $post_ids as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

// Delete all isoft_fmf_category terms
$terms = get_terms(
	array(
		'taxonomy'   => 'isoft_fmf_category',
		'hide_empty' => false,
	)
);
if ( is_array( $terms ) ) {
	foreach ( $terms as $term ) {
		wp_delete_term( $term->term_id, 'isoft_fmf_category' );
	}
}

// Delete all plugin options via the WP API.
// Enumerated rather than a wildcard DELETE so Plugin Check / WPCS don't flag
// the cleanup as a direct DB query. delete_option() is a no-op on missing
// keys, so legacy/never-saved entries are safe to list.
$options = array(
	// Settings (general)
	'isoft_fmf_default_access_role',
	'isoft_fmf_enable_counting',
	'isoft_fmf_enable_logging',
	'isoft_fmf_enable_detailed_logging',
	'isoft_fmf_log_retention_days',
	'isoft_fmf_enable_pdf_thumbnails',
	'isoft_fmf_pdf_thumb_width',
	'isoft_fmf_pdf_thumb_height',
	'isoft_fmf_pdf_thumb_quality',
	'isoft_fmf_overwrite_pdf_thumbnail',
	// Settings (display)
	'isoft_fmf_default_button_text',
	'isoft_fmf_listing_layout',
	'isoft_fmf_items_per_page',
	'isoft_fmf_show_file_size',
	'isoft_fmf_show_download_count',
	'isoft_fmf_show_date',
	'isoft_fmf_date_format',
	// Settings (security)
	'isoft_fmf_serve_method',
	'isoft_fmf_nginx_config_confirmed',
	'isoft_fmf_rate_limit_per_hour',
	'isoft_fmf_block_user_agents',
	'isoft_fmf_enable_zip_bundle',
	'isoft_fmf_enable_zip_cache',
	'isoft_fmf_zip_cache_days',
	'isoft_fmf_hotlink_protection',
	// Settings (files)
	'isoft_fmf_allowed_extensions',
	'isoft_fmf_cyrillic_titles',
	// Settings (advanced)
	'isoft_fmf_archive_slug',
	'isoft_fmf_category_slug',
	'isoft_fmf_tag_slug',
	'isoft_fmf_delete_data_on_uninstall',
	// Settings (maintenance / integrity)
	'isoft_fmf_integrity_check_enabled',
	'isoft_fmf_integrity_check_time',
	'isoft_fmf_integrity_autorelink',
	'isoft_fmf_integrity_use_inode',
	'isoft_fmf_integrity_last_run',
	// Internal state
	'isoft_fmf_admin_notices',
	'isoft_fmf_db_version',
	'isoft_fmf_flush_rewrite_rules',
	'isoft_fmf_hot_calculated_at',
	'isoft_fmf_hot_downloads',
	'isoft_fmf_pdf_notice_dismissed',
	// Legacy (removed in earlier versions; cleaned for upgrade safety)
	'isoft_fmf_custom_css',
	'isoft_fmf_custom_folder',
	'isoft_fmf_storage_mode',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete known transients (per-IP rate-limit transients are dynamic and
// self-expire via their TTL; leaving them is acceptable).
$transients = array(
	'isoft_fmf_missing_count',
	'isoft_fmf_stats_overview',
);
foreach ( $transients as $transient ) {
	delete_transient( $transient );
}

// Delete per-user category ACL meta from all users.
delete_metadata( 'user', 0, '_isoft_fmf_allowed_categories', '', true );

// Remove capabilities from all roles
$capabilities = array(
	'isoft_fmf_view_downloads',
	'isoft_fmf_create_downloads',
	'isoft_fmf_edit_own_downloads',
	'isoft_fmf_edit_all_downloads',
	'isoft_fmf_delete_downloads',
	'isoft_fmf_manage_categories',
	'isoft_fmf_view_logs',
	'isoft_fmf_export_logs',
	'isoft_fmf_manage_settings',
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
$custom_dir = $upload_dir['basedir'] . '/isoft-fmf-files';
if ( is_dir( $custom_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}
	$wp_filesystem->delete( $custom_dir, true, 'd' );
}

flush_rewrite_rules();
