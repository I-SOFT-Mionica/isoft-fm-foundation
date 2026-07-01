<?php
/**
 * Broken Links page — integrity panel (PHP) + React mount.
 *
 * The React app in `blocks/broken-links-page/` owns the missing-files
 * table and the per-row Recover modal, driven by
 * [[ISOFT_FMF_Rest_Broken_Links]]. The integrity-check panel above
 * remains PHP — server-side lock state + an admin-post.php action, not
 * list/CRUD work.
 *
 * The PHP WP_List_Table fallback and the pre-React recovery dialog were
 * removed in 0.12.5 demolition.
 */

defined( 'ABSPATH' ) || exit;

$isoft_fmf_lock           = ISOFT_FMF_File_Integrity::lock_state();
$isoft_fmf_limits         = ISOFT_FMF_File_Integrity::server_limits();
$isoft_fmf_last_run       = get_option( 'isoft_fmf_integrity_last_run', array() );
$isoft_fmf_run_now_url    = wp_nonce_url(
	admin_url( 'admin-post.php?action=isoft_fmf_integrity_check_now&return=broken-links' ),
	'isoft_fmf_integrity_check_now'
);
$isoft_fmf_max_exec_label = $isoft_fmf_limits['max_execution_time'] > 0
	/* translators: %d: number of seconds */
	? sprintf( _n( '%d second', '%d seconds', $isoft_fmf_limits['max_execution_time'], 'isoft-fm-foundation' ), $isoft_fmf_limits['max_execution_time'] )
	: __( 'unlimited', 'isoft-fm-foundation' );
$isoft_fmf_mem_label = $isoft_fmf_limits['memory_limit_bytes'] > 0
	? size_format( $isoft_fmf_limits['memory_limit_bytes'] )
	: __( 'unlimited', 'isoft-fm-foundation' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag after redirect from the nonce-protected action.
$isoft_fmf_just_ran = isset( $_GET['isoft_fmf_ran'] );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag after redirect from the nonce-protected action.
$isoft_fmf_saw_running = isset( $_GET['isoft_fmf_running'] );
?>
<div class="wrap isoft-fmf-broken-links-page">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Broken Links', 'isoft-fm-foundation' ); ?></h1>

	<p class="description" style="max-width:780px;">
		<?php esc_html_e( 'Files listed here are missing from their expected folder on disk. Use the Recover action on each row to relink, move the file back, reassign the download, split it into a new download, reupload, or detach the file from this download.', 'isoft-fm-foundation' ); ?>
	</p>

	<?php if ( $isoft_fmf_just_ran ) : ?>
		<div class="notice notice-success is-dismissible" style="margin-top:1em;">
			<p><?php esc_html_e( 'Integrity check complete. The table below lists all files currently missing on disk.', 'isoft-fm-foundation' ); ?></p>
		</div>
	<?php elseif ( $isoft_fmf_saw_running ) : ?>
		<div class="notice notice-warning is-dismissible" style="margin-top:1em;">
			<p><?php esc_html_e( 'A check is already running. Please wait for it to finish, then refresh.', 'isoft-fm-foundation' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="isoft-fmf-integrity-panel" style="margin:1em 0;padding:1em 1.2em;background:#fff;border:1px solid #c3c4c7;border-radius:4px;max-width:780px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Run Integrity Check', 'isoft-fm-foundation' ); ?></h2>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Scans every local file row and verifies the file exists on disk. Missing files are flagged here; renamed-in-place files are auto-relinked when the inode fast-path is enabled.', 'isoft-fm-foundation' ); ?>
		</p>

		<?php if ( null !== $isoft_fmf_lock && 'active' === $isoft_fmf_lock['status'] ) : ?>
			<p style="margin:.8em 0;">
				<span class="dashicons dashicons-update spin" style="color:#2271b1;animation:rotation 1.5s infinite linear;"></span>
				<strong>
					<?php
					printf(
						/* translators: %d: seconds elapsed since the run started */
						esc_html__( 'Check running — started %d seconds ago.', 'isoft-fm-foundation' ),
						(int) $isoft_fmf_lock['age_seconds']
					);
					?>
				</strong>
				<br>
				<span class="description">
					<?php
					printf(
						/* translators: %d: seconds the lock will be held before considered stale */
						esc_html__( 'If the run is interrupted, the lock auto-clears after %d seconds and the next click will start a fresh scan.', 'isoft-fm-foundation' ),
						(int) $isoft_fmf_lock['ttl_seconds']
					);
					?>
				</span>
			</p>
			<p>
				<button type="button" class="button" disabled>
					<?php esc_html_e( 'Run check now', 'isoft-fm-foundation' ); ?>
				</button>
			</p>
		<?php elseif ( null !== $isoft_fmf_lock && 'stale' === $isoft_fmf_lock['status'] ) : ?>
			<p style="margin:.8em 0;color:#996800;">
				<span class="dashicons dashicons-warning"></span>
				<strong>
					<?php
					printf(
						/* translators: 1: seconds since the run started, 2: stale-after seconds */
						esc_html__( 'A previous run started %1$d seconds ago and did not finish (exceeded the %2$d-second timeout). It was likely cut off by a PHP limit.', 'isoft-fm-foundation' ),
						(int) $isoft_fmf_lock['age_seconds'],
						(int) $isoft_fmf_lock['ttl_seconds']
					);
					?>
				</strong>
				<br>
				<span class="description"><?php esc_html_e( 'Clicking "Run check now" will clear the stale lock and start a fresh scan.', 'isoft-fm-foundation' ); ?></span>
			</p>
			<p>
				<a href="<?php echo esc_url( $isoft_fmf_run_now_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Run check now', 'isoft-fm-foundation' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p>
				<a href="<?php echo esc_url( $isoft_fmf_run_now_url ); ?>" class="button button-primary"
					onclick="return confirm('<?php echo esc_js( __( 'The scan runs synchronously and may take a minute or more on large sites. Continue?', 'isoft-fm-foundation' ) ); ?>');">
					<?php esc_html_e( 'Run check now', 'isoft-fm-foundation' ); ?>
				</a>
				<?php if ( ! empty( $isoft_fmf_last_run['finished_at'] ) ) : ?>
					<span class="description" style="margin-left:1em;">
						<?php
						printf(
							/* translators: 1: time of last run, 2: # checked, 3: # still missing */
							esc_html__( 'Last run: %1$s — %2$d checked, %3$d still missing.', 'isoft-fm-foundation' ),
							esc_html( (string) $isoft_fmf_last_run['finished_at'] ),
							(int) ( $isoft_fmf_last_run['checked'] ?? 0 ),
							(int) ( $isoft_fmf_last_run['still_gone'] ?? 0 )
						);
						?>
					</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<p class="description" style="margin:.6em 0 0;font-size:11px;color:#646970;">
			<?php
			printf(
				/* translators: 1: php max_execution_time label, 2: memory_limit label, 3: yes/no whether set_time_limit() works */
				esc_html__( 'Server limits: max execution %1$s · memory %2$s · set_time_limit %3$s.', 'isoft-fm-foundation' ),
				esc_html( $isoft_fmf_max_exec_label ),
				esc_html( $isoft_fmf_mem_label ),
				$isoft_fmf_limits['can_extend_time'] ? esc_html__( 'available', 'isoft-fm-foundation' ) : esc_html__( 'blocked', 'isoft-fm-foundation' )
			);
			?>
		</p>
	</div>

	<?php
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot page-render count; cheap and always fresh.
	$isoft_fmf_initial_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files WHERE is_missing = 1" );
	$isoft_fmf_initial_pages = (int) ceil( $isoft_fmf_initial_total / 25 );
	?>
	<div
		id="isoft-fmf-broken-links-root"
		data-initial-total="<?php echo esc_attr( (string) $isoft_fmf_initial_total ); ?>"
		data-initial-pages="<?php echo esc_attr( (string) $isoft_fmf_initial_pages ); ?>"
		style="margin-top:1em;"
	></div>
	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The Broken Links page requires JavaScript. Please enable JavaScript in your browser.', 'isoft-fm-foundation' ); ?></p>
		</div>
	</noscript>
</div>
