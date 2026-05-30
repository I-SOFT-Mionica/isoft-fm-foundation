<?php
/**
 * Admin view: Downloads → Broken Links.
 *
 * $table is an ISFM_Broken_Links_Table prepared by ISFM_Settings::render_broken_links().
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap isfm-broken-links-page">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Broken Links', 'isoft-fm-foundation' ); ?></h1>

	<p class="description" style="max-width:780px;">
		<?php esc_html_e( 'Files listed here are missing from their expected folder on disk. Use the Recover action on each row to relink, move the file back, reassign the download, split it into a new download, reupload, or detach the file from this download.', 'isoft-fm-foundation' ); ?>
	</p>

	<?php if ( empty( $table->items ) ) : ?>
		<div class="notice notice-success inline" style="margin-top:1em;">
			<p><?php esc_html_e( 'All files are present. Nothing to recover.', 'isoft-fm-foundation' ); ?></p>
		</div>
	<?php else : ?>
		<form method="get">
			<input type="hidden" name="post_type" value="isfm_file" />
			<input type="hidden" name="page" value="isfm-broken-links" />
			<?php $table->display(); ?>
		</form>
	<?php endif; ?>

	<!-- Recovery dialog template (hidden, cloned by JS per row click). -->
	<div id="isfm-recover-dialog" style="display:none;" aria-hidden="true">
		<div class="isfm-recover-dialog__backdrop"></div>
		<div class="isfm-recover-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="isfm-recover-title">
			<button type="button" class="isfm-recover-close" aria-label="<?php esc_attr_e( 'Close', 'isoft-fm-foundation' ); ?>">&times;</button>
			<h2 id="isfm-recover-title"><?php esc_html_e( 'Recover File', 'isoft-fm-foundation' ); ?></h2>
			<div class="isfm-recover-status" aria-live="polite"></div>
			<div class="isfm-recover-summary"></div>
			<div class="isfm-recover-actions">
				<p class="isfm-recover-cross-cat" hidden>
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
				<p class="isfm-recover-fallback">
					<label class="button">
						<?php esc_html_e( 'Reupload…', 'isoft-fm-foundation' ); ?>
						<input type="file" class="isfm-recover-file" hidden>
					</label>
					<button type="button" class="button button-link-delete" data-action="detach">
						<?php esc_html_e( 'Detach file', 'isoft-fm-foundation' ); ?>
					</button>
				</p>
			</div>
		</div>
	</div>
</div>
