<?php
/**
 * Admin view: Downloads → Broken Links.
 *
 * $table is an ISOFT_FMF_Broken_Links_Table prepared by ISOFT_FMF_Settings::render_broken_links().
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$lock           = ISOFT_FMF_File_Integrity::lock_state();
$limits         = ISOFT_FMF_File_Integrity::server_limits();
$last_run       = get_option( 'isoft_fmf_integrity_last_run', array() );
$run_now_url    = wp_nonce_url(
	admin_url( 'admin-post.php?action=isoft_fmf_integrity_check_now&return=broken-links' ),
	'isoft_fmf_integrity_check_now'
);
$max_exec_label = $limits['max_execution_time'] > 0
	/* translators: %d: number of seconds */
	? sprintf( _n( '%d second', '%d seconds', $limits['max_execution_time'], 'isoft-fm-foundation' ), $limits['max_execution_time'] )
	: __( 'unlimited', 'isoft-fm-foundation' );
$mem_label = $limits['memory_limit_bytes'] > 0
	? size_format( $limits['memory_limit_bytes'] )
	: __( 'unlimited', 'isoft-fm-foundation' );

$just_ran    = isset( $_GET['isoft_fmf_ran'] );      // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$saw_running = isset( $_GET['isoft_fmf_running'] );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap isoft-fmf-broken-links-page">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Broken Links', 'isoft-fm-foundation' ); ?></h1>

	<p class="description" style="max-width:780px;">
		<?php esc_html_e( 'Files listed here are missing from their expected folder on disk. Use the Recover action on each row to relink, move the file back, reassign the download, split it into a new download, reupload, or detach the file from this download.', 'isoft-fm-foundation' ); ?>
	</p>

	<?php if ( $just_ran ) : ?>
		<div class="notice notice-success is-dismissible" style="margin-top:1em;">
			<p><?php esc_html_e( 'Integrity check complete. The table below lists all files currently missing on disk.', 'isoft-fm-foundation' ); ?></p>
		</div>
	<?php elseif ( $saw_running ) : ?>
		<div class="notice notice-warning is-dismissible" style="margin-top:1em;">
			<p><?php esc_html_e( 'A check is already running. Please wait for it to finish, then refresh.', 'isoft-fm-foundation' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="isoft-fmf-integrity-panel" style="margin:1em 0;padding:1em 1.2em;background:#fff;border:1px solid #c3c4c7;border-radius:4px;max-width:780px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Run Integrity Check', 'isoft-fm-foundation' ); ?></h2>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Scans every local file row and verifies the file exists on disk. Missing files are flagged here; renamed-in-place files are auto-relinked when the inode fast-path is enabled.', 'isoft-fm-foundation' ); ?>
		</p>

		<?php if ( null !== $lock && 'active' === $lock['status'] ) : ?>
			<p style="margin:.8em 0;">
				<span class="dashicons dashicons-update spin" style="color:#2271b1;animation:rotation 1.5s infinite linear;"></span>
				<strong>
					<?php
					printf(
						/* translators: %d: seconds elapsed since the run started */
						esc_html__( 'Check running — started %d seconds ago.', 'isoft-fm-foundation' ),
						(int) $lock['age_seconds']
					);
					?>
				</strong>
				<br>
				<span class="description">
					<?php
					printf(
						/* translators: %d: seconds the lock will be held before considered stale */
						esc_html__( 'If the run is interrupted, the lock auto-clears after %d seconds and the next click will start a fresh scan.', 'isoft-fm-foundation' ),
						(int) $lock['ttl_seconds']
					);
					?>
				</span>
			</p>
			<p>
				<button type="button" class="button" disabled>
					<?php esc_html_e( 'Run check now', 'isoft-fm-foundation' ); ?>
				</button>
			</p>
		<?php elseif ( null !== $lock && 'stale' === $lock['status'] ) : ?>
			<p style="margin:.8em 0;color:#996800;">
				<span class="dashicons dashicons-warning"></span>
				<strong>
					<?php
					printf(
						/* translators: 1: seconds since the run started, 2: stale-after seconds */
						esc_html__( 'A previous run started %1$d seconds ago and did not finish (exceeded the %2$d-second timeout). It was likely cut off by a PHP limit.', 'isoft-fm-foundation' ),
						(int) $lock['age_seconds'],
						(int) $lock['ttl_seconds']
					);
					?>
				</strong>
				<br>
				<span class="description"><?php esc_html_e( 'Clicking "Run check now" will clear the stale lock and start a fresh scan.', 'isoft-fm-foundation' ); ?></span>
			</p>
			<p>
				<a href="<?php echo esc_url( $run_now_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Run check now', 'isoft-fm-foundation' ); ?>
				</a>
			</p>
		<?php else : ?>
			<p>
				<a href="<?php echo esc_url( $run_now_url ); ?>" class="button button-primary"
					onclick="return confirm('<?php echo esc_js( __( 'The scan runs synchronously and may take a minute or more on large sites. Continue?', 'isoft-fm-foundation' ) ); ?>');">
					<?php esc_html_e( 'Run check now', 'isoft-fm-foundation' ); ?>
				</a>
				<?php if ( ! empty( $last_run['finished_at'] ) ) : ?>
					<span class="description" style="margin-left:1em;">
						<?php
						printf(
							/* translators: 1: time of last run, 2: # checked, 3: # still missing */
							esc_html__( 'Last run: %1$s — %2$d checked, %3$d still missing.', 'isoft-fm-foundation' ),
							esc_html( (string) $last_run['finished_at'] ),
							(int) ( $last_run['checked'] ?? 0 ),
							(int) ( $last_run['still_gone'] ?? 0 )
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
				esc_html( $max_exec_label ),
				esc_html( $mem_label ),
				$limits['can_extend_time'] ? esc_html__( 'available', 'isoft-fm-foundation' ) : esc_html__( 'blocked', 'isoft-fm-foundation' )
			);
			?>
		</p>
	</div>

	<?php
	// React path (0.12.3+). The React app owns the broken-files list,
	// per-row Recover action, and the recovery modal that talks to
	// /broken-links/{id}/probe + /recover + /reupload. PHP keeps the
	// integrity-check panel above (server-side lock state + admin-post
	// action) since that's not list/CRUD work.
	if ( wp_script_is( ISOFT_FMF_Broken_Links_Page::SCRIPT_HANDLE, 'enqueued' ) ) :
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot page-render count; cheap and always fresh.
		$initial_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files WHERE is_missing = 1" );
		$initial_pages = (int) ceil( $initial_total / 25 );
		?>
		<div
			id="isoft-fmf-broken-links-root"
			data-initial-total="<?php echo esc_attr( (string) $initial_total ); ?>"
			data-initial-pages="<?php echo esc_attr( (string) $initial_pages ); ?>"
			style="margin-top:1em;"
		></div>
		<noscript>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'The Broken Links page requires JavaScript. Please enable JavaScript in your browser, or revert to the previous plugin version.', 'isoft-fm-foundation' ); ?></p>
			</div>
		</noscript>
	<?php else : ?>

		<?php if ( empty( $table->items ) ) : ?>
			<div class="notice notice-success inline" style="margin-top:1em;">
				<p><?php esc_html_e( 'All files are present. Nothing to recover.', 'isoft-fm-foundation' ); ?></p>
			</div>
		<?php else : ?>
			<form method="get">
				<input type="hidden" name="post_type" value="isoft_fmf_file" />
				<input type="hidden" name="page" value="isoft-fmf-broken-links" />
				<?php $table->display(); ?>
			</form>
		<?php endif; ?>

		<!-- Recovery dialog template (hidden, cloned by JS per row click). PHP fallback only. -->
		<div id="isoft-fmf-recover-dialog" style="display:none;" aria-hidden="true">
			<div class="isoft-fmf-recover-dialog__backdrop"></div>
			<div class="isoft-fmf-recover-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="isoft-fmf-recover-title">
				<button type="button" class="isoft-fmf-recover-close" aria-label="<?php esc_attr_e( 'Close', 'isoft-fm-foundation' ); ?>">&times;</button>
				<h2 id="isoft-fmf-recover-title"><?php esc_html_e( 'Recover File', 'isoft-fm-foundation' ); ?></h2>
				<div class="isoft-fmf-recover-status" aria-live="polite"></div>
				<div class="isoft-fmf-recover-summary"></div>
				<div class="isoft-fmf-recover-actions">
					<p class="isoft-fmf-recover-cross-cat" hidden>
						<strong><?php esc_html_e( 'File found in a different category folder.', 'isoft-fm-foundation' ); ?></strong><br>
						<span class="description"><?php esc_html_e( 'Pick how to resolve the mismatch:', 'isoft-fm-foundation' ); ?></span>
					</p>
					<p>
						<button type="button" class="button" data-action="move_back" hidden>
							<?php esc_html_e( '1. Move file back', 'isoft-fm-foundation' ); ?>
						</button>
						<button type="button" class="button" data-action="reassign" hidden>
							<?php esc_html_e( '2. Reassign download', 'isoft-fm-foundation' ); ?>
						</button>
						<button type="button" class="button" data-action="split" hidden>
							<?php esc_html_e( '3. Split into new download', 'isoft-fm-foundation' ); ?>
						</button>
					</p>
					<p class="isoft-fmf-recover-fallback">
						<label class="button">
							<?php esc_html_e( 'Reupload…', 'isoft-fm-foundation' ); ?>
							<input type="file" class="isoft-fmf-recover-file" hidden>
						</label>
						<button type="button" class="button button-link-delete" data-action="detach">
							<?php esc_html_e( 'Detach file', 'isoft-fm-foundation' ); ?>
						</button>
					</p>
				</div>
			</div>
		</div>

	<?php endif; ?>
</div>
