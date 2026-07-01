<?php
/**
 * Download Log — React mount.
 *
 * Rendered by [[ISOFT_FMF_Settings::render_log]]. The React app in
 * `blocks/log-page/` drives DataViews against `/logs`. Server-side
 * concerns (retention, export URL, purge nonce, initial count) ride on
 * data-* attributes so the JS bundle stays free of WP-PHP coupling.
 *
 * The PHP fallback (WP_List_Table + form filters) was removed in 0.12.5
 * demolition.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
	wp_die( esc_html__( 'You do not have permission to view the download log.', 'isoft-fm-foundation' ), 403 );
}

$isoft_fmf_export_base    = admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-log' );
$isoft_fmf_purge_url      = admin_url( 'admin-post.php' );
$isoft_fmf_retention_days = (int) get_option( 'isoft_fmf_log_retention_days', 365 );
$isoft_fmf_logging_on     = (bool) get_option( 'isoft_fmf_enable_logging', true );
$isoft_fmf_can_export     = current_user_can( 'isoft_fmf_export_logs' );
$isoft_fmf_can_purge      = current_user_can( 'isoft_fmf_manage_settings' );
$isoft_fmf_purge_nonce    = $isoft_fmf_can_purge ? wp_create_nonce( 'isoft_fmf_purge_logs' ) : '';

// Pre-compute total row count + page count so React can hydrate
// pagination + count text immediately on mount, before the first REST
// fetch resolves. Without this the table briefly says "No results"
// before data lands, which reads as a broken page on a 1-second cold
// load.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot page-render count; cheap and always fresh.
$isoft_fmf_initial_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" );
$isoft_fmf_initial_pages = (int) ceil( $isoft_fmf_initial_total / 25 );
?>
<div class="wrap">
	<div
		id="isoft-fmf-log-root"
		data-export-base="<?php echo esc_attr( $isoft_fmf_export_base ); ?>"
		data-purge-url="<?php echo esc_attr( $isoft_fmf_purge_url ); ?>"
		data-purge-nonce="<?php echo esc_attr( $isoft_fmf_purge_nonce ); ?>"
		data-retention-days="<?php echo esc_attr( (string) $isoft_fmf_retention_days ); ?>"
		data-logging-enabled="<?php echo $isoft_fmf_logging_on ? '1' : '0'; ?>"
		data-can-export="<?php echo $isoft_fmf_can_export ? '1' : '0'; ?>"
		data-can-purge="<?php echo $isoft_fmf_can_purge ? '1' : '0'; ?>"
		data-initial-total="<?php echo esc_attr( (string) $isoft_fmf_initial_total ); ?>"
		data-initial-pages="<?php echo esc_attr( (string) $isoft_fmf_initial_pages ); ?>"
	></div>
	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The Download Log requires JavaScript. Please enable JavaScript in your browser.', 'isoft-fm-foundation' ); ?></p>
		</div>
	</noscript>
</div>
