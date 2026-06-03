<?php
/**
 * Files meta box — upload / browse / external URL tabs + current file list.
 *
 * Expected variables:
 *   $post            WP_Post
 *   $files           array of isoft_fmf_files rows
 *   $is_new_post     bool
 *   $category_id     int|null
 *   $category        WP_Term|null
 *   $category_path   string   relative category folder path
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.
?>
<div class="isoft-fmf-files-wrap">

	<div class="isoft-fmf-target-bar">
		<?php if ( $category ) : ?>
			<span class="dashicons dashicons-category" aria-hidden="true"></span>
			<strong><?php esc_html_e( 'Target category:', 'isoft-fm-foundation' ); ?></strong>
			<span class="isoft-fmf-target-name"><?php echo esc_html( $category->name ); ?></span>
			<code class="isoft-fmf-target-path"><?php echo esc_html( $category_path ); ?></code>
		<?php else : ?>
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<em><?php esc_html_e( 'Assign a category (in the sidebar) and save the download before uploading files.', 'isoft-fm-foundation' ); ?></em>
		<?php endif; ?>
	</div>

	<nav class="isoft-fmf-tab-nav" role="tablist">
		<button type="button" class="isoft-fmf-tab-btn is-active" data-tab="upload" role="tab" aria-selected="true">
			<span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Upload', 'isoft-fm-foundation' ); ?>
		</button>
		<button type="button" class="isoft-fmf-tab-btn" data-tab="browse" role="tab" aria-selected="false">
			<span class="dashicons dashicons-portfolio"></span> <?php esc_html_e( 'From Folder', 'isoft-fm-foundation' ); ?>
		</button>
		<button type="button" class="isoft-fmf-tab-btn" data-tab="external" role="tab" aria-selected="false">
			<span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'External URL', 'isoft-fm-foundation' ); ?>
		</button>
	</nav>

	<div class="isoft-fmf-tab-panels">

		<!-- Upload tab -->
		<div class="isoft-fmf-tab-panel is-active" data-tab="upload" role="tabpanel">
			<?php if ( $category_id && ! $is_new_post ) : ?>
				<div class="isoft-fmf-dropzone" id="isoft-fmf-dropzone">
					<input type="file" id="isoft-fmf-file-input" multiple hidden />
					<div class="isoft-fmf-dropzone__inner">
						<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
						<p><?php esc_html_e( 'Drag & drop files here, or click to select.', 'isoft-fm-foundation' ); ?></p>
						<p class="description"><?php esc_html_e( 'Multiple files supported. Each file is saved directly to the category folder.', 'isoft-fm-foundation' ); ?></p>
					</div>
				</div>
				<ul class="isoft-fmf-upload-queue" id="isoft-fmf-upload-queue"></ul>
			<?php else : ?>
				<p class="isoft-fmf-notice-warning">
					<?php esc_html_e( 'Uploads are disabled until the download has a category and has been saved.', 'isoft-fm-foundation' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Browse tab -->
		<div class="isoft-fmf-tab-panel" data-tab="browse" role="tabpanel" hidden>
			<?php if ( $category_id && ! $is_new_post ) : ?>
				<p class="description">
					<?php esc_html_e( 'Files already present in the category folder that are not yet linked to this download:', 'isoft-fm-foundation' ); ?>
				</p>
				<ul class="isoft-fmf-browse-list" id="isoft-fmf-browse-list">
					<li class="isoft-fmf-browse-empty"><?php esc_html_e( 'Loading…', 'isoft-fm-foundation' ); ?></li>
				</ul>
			<?php else : ?>
				<p class="isoft-fmf-notice-warning">
					<?php esc_html_e( 'Assign a category and save the download before browsing its folder.', 'isoft-fm-foundation' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- External URL tab -->
		<div class="isoft-fmf-tab-panel" data-tab="external" role="tabpanel" hidden>
			<table class="form-table">
				<tr>
					<th><label for="isoft-fmf-ext-url"><?php esc_html_e( 'URL', 'isoft-fm-foundation' ); ?></label></th>
					<td><input type="url" id="isoft-fmf-ext-url" class="widefat" placeholder="https://…" /></td>
				</tr>
				<tr>
					<th><label for="isoft-fmf-ext-title"><?php esc_html_e( 'Title', 'isoft-fm-foundation' ); ?></label></th>
					<td><input type="text" id="isoft-fmf-ext-title" class="widefat" /></td>
				</tr>
				<tr>
					<th></th>
					<td>
						<label>
							<input type="checkbox" id="isoft-fmf-ext-mirror" />
							<?php esc_html_e( 'This is a mirror (alternate source for the same file)', 'isoft-fm-foundation' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary isoft-fmf-btn-ext-save">
					<?php esc_html_e( 'Add Link', 'isoft-fm-foundation' ); ?>
				</button>
			</p>
		</div>

	</div>

	<h4 class="isoft-fmf-section-heading"><?php esc_html_e( 'Files attached to this download', 'isoft-fm-foundation' ); ?></h4>

	<table class="widefat isoft-fmf-file-list" id="isoft-fmf-file-list">
		<thead>
			<tr>
				<th class="isoft-fmf-col-sort"></th>
				<th><?php esc_html_e( 'Title', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Filename / URL', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Type', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Size', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Downloads', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'isoft-fm-foundation' ); ?></th>
			</tr>
		</thead>
		<tbody id="isoft-fmf-file-list-body">
		<?php if ( empty( $files ) ) : ?>
			<tr class="isoft-fmf-no-files" id="isoft-fmf-no-files-row">
				<td colspan="7"><?php esc_html_e( 'No files attached yet.', 'isoft-fm-foundation' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $files as $file ) : ?>
			<tr class="isoft-fmf-file-row" data-file-id="<?php echo esc_attr( $file->id ); ?>">
				<td class="isoft-fmf-col-sort"><span class="dashicons dashicons-move isoft-fmf-sort-handle"></span></td>
				<td class="isoft-fmf-file-title"
					data-title="<?php echo esc_attr( $file->title ); ?>"
					data-description="<?php echo esc_attr( (string) $file->description ); ?>">
					<?php echo esc_html( $file->title ?: $file->file_name ?: $file->external_url ); ?>
				</td>
				<td class="isoft-fmf-file-source">
					<?php if ( 'local' === $file->file_type ) : ?>
						<?php echo esc_html( $file->file_name ); ?>
					<?php else : ?>
						<a href="<?php echo esc_url( $file->external_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $file->external_url ); ?>
						</a>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( 'local' === $file->file_type ) : ?>
						<span class="isoft-fmf-badge isoft-fmf-badge--local"><?php echo esc_html( strtoupper( pathinfo( $file->file_name ?? '', PATHINFO_EXTENSION ) ) ); ?></span>
					<?php elseif ( $file->is_mirror ) : ?>
						<span class="isoft-fmf-badge isoft-fmf-badge--mirror"><?php esc_html_e( 'Mirror', 'isoft-fm-foundation' ); ?></span>
					<?php else : ?>
						<span class="isoft-fmf-badge isoft-fmf-badge--external"><?php esc_html_e( 'External', 'isoft-fm-foundation' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo $file->file_size ? esc_html( size_format( $file->file_size ) ) : '—'; ?></td>
				<td><?php echo esc_html( number_format_i18n( $file->download_count ) ); ?></td>
				<td>
					<button type="button" class="button button-small isoft-fmf-btn-edit-file"
						data-file-id="<?php echo esc_attr( $file->id ); ?>">
						<?php esc_html_e( 'Edit', 'isoft-fm-foundation' ); ?>
					</button>
					<button type="button" class="button button-small isoft-fmf-btn-delete-file"
						data-file-id="<?php echo esc_attr( $file->id ); ?>">
						<?php esc_html_e( 'Remove', 'isoft-fm-foundation' ); ?>
					</button>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

</div>
