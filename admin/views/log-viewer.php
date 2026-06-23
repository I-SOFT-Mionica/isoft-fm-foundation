<?php
/**
 * Download Log viewer.
 *
 * 0.12.3+: when the React log bundle is enqueued, the page is rendered
 * by blocks/log-page/index.js mounting into <div id="isoft-fmf-log-root">.
 * Server-side concerns (capability flags, retention setting, export
 * base URL, purge nonce) ride along as data-* attributes on the mount
 * node so the JS bundle stays free of WP-PHP coupling.
 *
 * Falls through to the original WP_List_Table-backed markup when the
 * bundle is unavailable (asset missing during local dev, malformed
 * deploy, or future revert).
 *
 * @var ISOFT_FMF_Log_Table $table  Prepared list table instance (fallback path only).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
	wp_die( esc_html__( 'You do not have permission to view the download log.', 'isoft-fm-foundation' ), 403 );
}

if ( wp_script_is( ISOFT_FMF_Log_Page::SCRIPT_HANDLE, 'enqueued' ) ) {
	$export_base    = admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-log' );
	$purge_url      = admin_url( 'admin-post.php' );
	$retention_days = (int) get_option( 'isoft_fmf_log_retention_days', 365 );
	$logging_on     = (bool) get_option( 'isoft_fmf_enable_logging', true );
	$can_export     = current_user_can( 'isoft_fmf_export_logs' );
	$can_purge      = current_user_can( 'isoft_fmf_manage_settings' );
	$purge_nonce    = $can_purge ? wp_create_nonce( 'isoft_fmf_purge_logs' ) : '';

	// Pre-compute the total log entry count and pages so the React
	// component can hydrate pagination + count text immediately on
	// mount — before the first REST fetch resolves. Without this the
	// table briefly says "No results" before the data lands, which
	// reads as a broken page on a 1-second cold load.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot page-render count; cheap and always fresh.
	$initial_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" );
	$initial_pages = (int) ceil( $initial_total / 25 );
	?>
	<div
		id="isoft-fmf-log-root"
		data-export-base="<?php echo esc_attr( $export_base ); ?>"
		data-purge-url="<?php echo esc_attr( $purge_url ); ?>"
		data-purge-nonce="<?php echo esc_attr( $purge_nonce ); ?>"
		data-retention-days="<?php echo esc_attr( (string) $retention_days ); ?>"
		data-logging-enabled="<?php echo $logging_on ? '1' : '0'; ?>"
		data-can-export="<?php echo $can_export ? '1' : '0'; ?>"
		data-can-purge="<?php echo $can_purge ? '1' : '0'; ?>"
		data-initial-total="<?php echo esc_attr( (string) $initial_total ); ?>"
		data-initial-pages="<?php echo esc_attr( (string) $initial_pages ); ?>"
	></div>
	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The Download Log requires JavaScript. Please enable JavaScript in your browser, or revert to the previous plugin version.', 'isoft-fm-foundation' ); ?></p>
		</div>
	</noscript>
	<?php
	return;
}

// ---------------------------------------------------------------------
// PHP fallback path. Original WP_List_Table-backed markup.
// ---------------------------------------------------------------------

// Process bulk actions before output.
$table->process_bulk_action();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display filters from query string.
$filter_download = isset( $_REQUEST['filter_download'] ) ? absint( $_REQUEST['filter_download'] ) : 0;
$search          = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// All downloads for filter dropdown.
$all_downloads = get_posts(
	array(
		'post_type'      => 'isoft_fmf_file',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	)
);

$base_url = admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-log' );
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Download Log', 'isoft-fm-foundation' ); ?></h1>

	<?php if ( current_user_can( 'isoft_fmf_export_logs' ) ) : ?>
		<a href="<?php echo esc_url( $base_url . '&isoft_fmf_action=export_csv' . ( $filter_download ? '&filter_download=' . $filter_download : '' ) . ( $search ? '&s=' . rawurlencode( $search ) : '' ) ); ?>"
			class="page-title-action">
			<?php esc_html_e( 'Export CSV', 'isoft-fm-foundation' ); ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&isoft_fmf_action=export_json' . ( $filter_download ? '&filter_download=' . $filter_download : '' ) . ( $search ? '&s=' . rawurlencode( $search ) : '' ) ); ?>"
			class="page-title-action">
			<?php esc_html_e( 'Export JSON', 'isoft-fm-foundation' ); ?>
		</a>
	<?php endif; ?>

	<hr class="wp-header-end">

	<?php if ( ! get_option( 'isoft_fmf_enable_logging', true ) ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Download logging is currently disabled. Enable it in Settings → General.', 'isoft-fm-foundation' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// Show purge notice.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only flash message after redirect; no state change.
	if ( isset( $_GET['purged'] ) ) {
		$purged = absint( wp_unslash( $_GET['purged'] ) );
		echo '<div class="notice notice-success is-dismissible"><p>';
		/* translators: %d: number of entries deleted */
		printf( esc_html__( '%d log entries deleted.', 'isoft-fm-foundation' ), (int) $purged );
		echo '</p></div>';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	?>

	<form method="get" class="isoft-fmf-log-filters">
		<input type="hidden" name="post_type" value="isoft_fmf_file" />
		<input type="hidden" name="page" value="isoft-fmf-log" />

		<div class="alignleft actions">
			<select name="filter_download">
				<option value="0"><?php esc_html_e( '— All downloads —', 'isoft-fm-foundation' ); ?></option>
				<?php foreach ( $all_downloads as $dl ) : ?>
					<option value="<?php echo absint( $dl->ID ); ?>" <?php selected( $filter_download, $dl->ID ); ?>>
						<?php echo esc_html( $dl->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'isoft-fm-foundation' ), 'action', 'filter_action', false ); ?>
		</div>

		<?php $table->search_box( __( 'Search log', 'isoft-fm-foundation' ), 'isoft_fmf_log_search' ); ?>
	</form>

	<form method="post">
		<input type="hidden" name="post_type" value="isoft_fmf_file" />
		<input type="hidden" name="page" value="isoft-fmf-log" />
		<?php if ( $filter_download ) : ?>
			<input type="hidden" name="filter_download" value="<?php echo absint( $filter_download ); ?>" />
		<?php endif; ?>
		<?php if ( $search ) : ?>
			<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
		<?php endif; ?>

		<?php $table->display(); ?>
	</form>

	<?php if ( current_user_can( 'isoft_fmf_manage_settings' ) ) : ?>
		<hr>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php echo esc_js( __( 'This will permanently delete log entries older than the configured retention period. Continue?', 'isoft-fm-foundation' ) ); ?>')">
			<?php wp_nonce_field( 'isoft_fmf_purge_logs' ); ?>
			<input type="hidden" name="action" value="isoft_fmf_purge_logs" />
			<?php submit_button( __( 'Purge old log entries', 'isoft-fm-foundation' ), 'delete', 'submit', false ); ?>
			<span class="description">
				<?php
				printf(
					/* translators: %d: retention days setting */
					esc_html__( 'Deletes entries older than %d days (configured in Settings).', 'isoft-fm-foundation' ),
					(int) get_option( 'isoft_fmf_log_retention_days', 365 )
				);
				?>
			</span>
		</form>
	<?php endif; ?>
</div>
