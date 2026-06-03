<?php
/**
 * Maintenance tab — File Integrity Check settings + Run now.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$enabled    = (bool) get_option( 'isfm_integrity_check_enabled', 0 );
$time       = (string) get_option( 'isfm_integrity_check_time', '02:30' );
$autorelink = (bool) get_option( 'isfm_integrity_autorelink', 1 );
$use_inode  = (bool) get_option( 'isfm_integrity_use_inode', 1 );
$last_run   = get_option( 'isfm_integrity_last_run', array() );

list( $cur_h, $cur_m ) = array_pad( explode( ':', $time ), 2, '00' );

$run_now_url = wp_nonce_url(
	admin_url( 'admin-post.php?action=isfm_integrity_check_now' ),
	'isfm_integrity_check_now'
);

$just_ran = isset( $_GET['isfm_ran'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="isfm-maintenance-tab" style="margin-top:1.5em;">

	<h2><?php esc_html_e( 'File Integrity Check', 'isoft-fm-foundation' ); ?></h2>
	<p class="description" style="max-width:780px;">
		<?php esc_html_e( 'When enabled, I-Soft File Manager: Foundation runs a daily scan that looks for files missing from their expected folder. Missing files are flagged on the Broken Links screen and will appear as "Temporarily unavailable" on the front end. If a file was simply renamed on disk, the scan can auto-recover it via the POSIX inode fast path.', 'isoft-fm-foundation' ); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'isfm_settings' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable daily integrity check', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="isfm_integrity_check_enabled" value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Run a daily scan for missing files', 'isoft-fm-foundation' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default on new installs. Turning it on schedules the scan; turning it off unschedules it.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Scan time (site timezone)', 'isoft-fm-foundation' ); ?></th>
				<td>
					<input type="time"
						name="isfm_integrity_check_time"
						value="<?php echo esc_attr( sprintf( '%02d:%02d', (int) $cur_h, (int) $cur_m ) ); ?>"
						step="60" />
					<p class="description">
						<?php esc_html_e( 'Pick any time that does not overlap the 01:00 HOT recalculation job. Default 02:30.', 'isoft-fm-foundation' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-relink renamed files', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="isfm_integrity_autorelink" value="1" <?php checked( $autorelink ); ?> />
						<?php esc_html_e( 'If a file appears to have been renamed in place, auto-fix the database link.', 'isoft-fm-foundation' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Requires inode fast-path (below) to be enabled. The candidate file is also hash-verified before committing, to guard against inode recycling.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Inode fast-path (Linux / macOS)', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="isfm_integrity_use_inode" value="1" <?php checked( $use_inode ); ?> />
						<?php esc_html_e( 'Use POSIX inodes to recover renamed files quickly.', 'isoft-fm-foundation' ); ?>
					</label>
					<div style="margin-top:.8em;padding:.8em 1em;background:#fff8e1;border-left:4px solid #dba617;max-width:780px;">
						<strong style="display:block;margin-bottom:.3em;">
							<?php esc_html_e( '⚠ Windows hosting: disable this option.', 'isoft-fm-foundation' ); ?>
						</strong>
						<p style="margin:0;">
							<?php esc_html_e( 'Windows (NTFS) does not provide stable POSIX inodes. If your site runs on Windows hosting, turn this off — otherwise rename recovery will silently fail and files may be incorrectly flagged as missing. On Linux / macOS hosting (the vast majority of WordPress installs), leave this on.', 'isoft-fm-foundation' ); ?>
						</p>
					</div>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Maintenance Settings', 'isoft-fm-foundation' ) ); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Run Now', 'isoft-fm-foundation' ); ?></h2>
	<p>
		<a href="<?php echo esc_url( $run_now_url ); ?>" class="button button-secondary">
			<?php esc_html_e( 'Run integrity check now', 'isoft-fm-foundation' ); ?>
		</a>
		<?php if ( $just_ran ) : ?>
			<span style="color:#008a20;margin-left:1em;">
				<?php esc_html_e( 'Check completed. See the summary below.', 'isoft-fm-foundation' ); ?>
			</span>
		<?php endif; ?>
	</p>

	<?php if ( ! empty( $last_run ) && is_array( $last_run ) ) : ?>
		<h3><?php esc_html_e( 'Last Run', 'isoft-fm-foundation' ); ?></h3>
		<table class="widefat" style="max-width:520px;">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Started', 'isoft-fm-foundation' ); ?></th>
					<td><?php echo esc_html( $last_run['started_at'] ?? '—' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Finished', 'isoft-fm-foundation' ); ?></th>
					<td><?php echo esc_html( $last_run['finished_at'] ?? '—' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Checked', 'isoft-fm-foundation' ); ?></th>
					<td><?php echo esc_html( (int) ( $last_run['checked'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Healed', 'isoft-fm-foundation' ); ?></th>
					<td><?php echo esc_html( (int) ( $last_run['healed'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Relinked (renamed)', 'isoft-fm-foundation' ); ?></th>
					<td><?php echo esc_html( (int) ( $last_run['relinked'] ?? 0 ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Still missing', 'isoft-fm-foundation' ); ?></th>
					<td><?php echo esc_html( (int) ( $last_run['still_gone'] ?? 0 ) ); ?></td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>

	<hr>

	<h2><?php esc_html_e( 'Demo Content', 'isoft-fm-foundation' ); ?></h2>

	<?php if ( ! ISFM_Demo_Content::has_content() ) : ?>
		<p class="description" style="max-width:780px;">
			<?php esc_html_e( 'Install sample categories and downloads to explore the plugin. Creates a realistic category tree with PDF and DOCX files demonstrating nested categories, access roles, and multi-file downloads.', 'isoft-fm-foundation' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=isfm_install_demo' ), 'isfm_install_demo' ) ); ?>"
				class="button button-primary"
				onclick="return confirm('<?php echo esc_js( __( 'Install demo categories and downloads?', 'isoft-fm-foundation' ) ); ?>');">
				<?php esc_html_e( 'Install Demo Content', 'isoft-fm-foundation' ); ?>
			</a>
		</p>
	<?php elseif ( ISFM_Demo_Content::has_demo_content() ) : ?>
		<p class="description" style="max-width:780px;">
			<?php esc_html_e( 'Demo content is installed. You can remove it when you are ready to add your own documents.', 'isoft-fm-foundation' ); ?>
		</p>
		<p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=isfm_remove_demo' ), 'isfm_remove_demo' ) ); ?>"
				class="button"
				onclick="return confirm('<?php echo esc_js( __( 'Remove all demo categories, downloads, and files? This cannot be undone.', 'isoft-fm-foundation' ) ); ?>');">
				<?php esc_html_e( 'Remove Demo Content', 'isoft-fm-foundation' ); ?>
			</a>
		</p>
	<?php else : ?>
		<p class="description">
			<?php esc_html_e( 'Your site has download content. Demo content can only be installed on a fresh setup with no existing downloads.', 'isoft-fm-foundation' ); ?>
		</p>
	<?php endif; ?>

</div>
