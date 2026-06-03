<?php defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals. ?>
<div class="isoft-fmf-extensions-tab" style="margin-top:1.5em;">
	<h2><?php esc_html_e( 'Installed Extensions', 'isoft-fm-foundation' ); ?></h2>

	<?php $extensions = ISOFT_FMF_Extension_Api::get_all(); ?>

	<?php if ( empty( $extensions ) ) : ?>
		<p><?php esc_html_e( 'No extensions are currently active.', 'isoft-fm-foundation' ); ?></p>
	<?php else : ?>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Extension', 'isoft-fm-foundation' ); ?></th>
					<th><?php esc_html_e( 'Version', 'isoft-fm-foundation' ); ?></th>
					<th><?php esc_html_e( 'Author', 'isoft-fm-foundation' ); ?></th>
					<th><?php esc_html_e( 'Description', 'isoft-fm-foundation' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $extensions as $ext ) : ?>
				<tr>
					<td>
						<?php if ( ! empty( $ext['url'] ) ) : ?>
							<a href="<?php echo esc_url( $ext['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ext['name'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $ext['name'] ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $ext['version'] ); ?></td>
					<td><?php echo esc_html( $ext['author'] ?? '—' ); ?></td>
					<td><?php echo esc_html( $ext['description'] ?? '' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2 style="margin-top:2em;"><?php esc_html_e( 'Available Extensions', 'isoft-fm-foundation' ); ?></h2>
	<div class="isoft-fmf-extension-cards" style="display:flex;gap:1.5em;flex-wrap:wrap;margin-top:1em;">
		<div class="card" style="max-width:360px;">
			<h3>
				I-Soft File Manager: Foundation Sentinel
				<span class="isoft-fmf-soon-badge"><?php esc_html_e( 'Coming soon', 'isoft-fm-foundation' ); ?></span>
			</h3>
			<p><?php esc_html_e( 'Monitor local server folders for new files via rclone mirroring, SFTP drops, or scheduled folder scans. Automatically creates draft download entries when files appear in a category folder — no manual uploads needed.', 'isoft-fm-foundation' ); ?></p>
			<p><a href="https://isoft.rs/sentinel" target="_blank" rel="noopener" class="button" aria-disabled="true"><?php esc_html_e( 'Learn More', 'isoft-fm-foundation' ); ?></a></p>
		</div>
		<div class="card" style="max-width:360px;">
			<h3>
				I-Soft File Manager: Foundation Orbit
				<span class="isoft-fmf-soon-badge"><?php esc_html_e( 'Coming soon', 'isoft-fm-foundation' ); ?></span>
			</h3>
			<p><?php esc_html_e( 'Sync files from Google Shared Drives. Departments drop files into a shared folder — Orbit picks them up and creates draft downloads for review.', 'isoft-fm-foundation' ); ?></p>
			<p><a href="https://isoft.rs/orbit" target="_blank" rel="noopener" class="button" aria-disabled="true"><?php esc_html_e( 'Learn More', 'isoft-fm-foundation' ); ?></a></p>
		</div>
	</div>
</div>
