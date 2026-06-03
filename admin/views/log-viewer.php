<?php
/**
 * Download Log viewer — Phase 4.
 *
 * @var ISOFT_FMF_Log_Table $table  Prepared list table instance.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

// Belt-and-braces capability gate even though the menu page is already capability-gated.
if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
	wp_die( esc_html__( 'You do not have permission to view the download log.', 'isoft-fm-foundation' ), 403 );
}

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
