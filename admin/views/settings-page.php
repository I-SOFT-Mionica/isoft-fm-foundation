<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
	<h1><?php esc_html_e( 'I-Soft File Manager: Foundation Settings', 'isoft-fm-foundation' ); ?></h1>

	<?php settings_errors( 'isfm_settings' ); ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector; nonce belongs on form submit, not nav.
	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
	$tabs       = array(
		'general'     => __( 'General', 'isoft-fm-foundation' ),
		'display'     => __( 'Display', 'isoft-fm-foundation' ),
		'security'    => __( 'Security', 'isoft-fm-foundation' ),
		'advanced'    => __( 'Advanced', 'isoft-fm-foundation' ),
		'maintenance' => __( 'Maintenance', 'isoft-fm-foundation' ),
		'extensions'  => __( 'Extensions', 'isoft-fm-foundation' ),
	);
	?>
	<nav class="nav-tab-wrapper">
		<?php foreach ( $tabs as $tab => $label ) : ?>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page'      => 'isfm-settings',
						'post_type' => 'isfm_file',
						'tab'       => $tab,
					),
					admin_url( 'edit.php' )
				)
			);
			?>
						"
				class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'extensions' === $active_tab ) : ?>
		<?php require __DIR__ . '/extensions-tab.php'; ?>
	<?php elseif ( 'maintenance' === $active_tab ) : ?>
		<?php require __DIR__ . '/maintenance-tab.php'; ?>
	<?php else : ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'isfm_settings' ); ?>

		<?php if ( 'general' === $active_tab ) : ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Storage Location', 'isoft-fm-foundation' ); ?></th>
				<td>
					<code><?php echo esc_html( isfm_files_dir() ); ?></code>
					<p class="description"><?php esc_html_e( 'All files are stored here, organised by category. The folder is protected by .htaccess and served through the plugin\'s secure download handler.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default Access Role', 'isoft-fm-foundation' ); ?></th>
				<td>
					<select name="isfm_default_access_role">
						<?php
						foreach ( array(
							'public'        => __( 'Public', 'isoft-fm-foundation' ),
							'subscriber'    => __( 'Subscriber+', 'isoft-fm-foundation' ),
							'editor'        => __( 'Editor+', 'isoft-fm-foundation' ),
							'administrator' => __( 'Administrator only', 'isoft-fm-foundation' ),
						) as $v => $l ) :
							?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( get_option( 'isfm_default_access_role', 'public' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Download Counting', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isfm_enable_counting" value="1" <?php checked( get_option( 'isfm_enable_counting', 1 ) ); ?> /> <?php esc_html_e( 'Count downloads', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Basic Logging', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isfm_enable_logging" value="1" <?php checked( get_option( 'isfm_enable_logging', 1 ) ); ?> /> <?php esc_html_e( 'Log downloads (timestamp, file, user)', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Detailed Logging', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label><input type="checkbox" name="isfm_enable_detailed_logging" value="1" <?php checked( get_option( 'isfm_enable_detailed_logging', 0 ) ); ?> /> <?php esc_html_e( 'Also log IP address, user agent, and referer', 'isoft-fm-foundation' ); ?></label>
					<p class="description"><?php esc_html_e( 'Collects personally identifiable information (PII). Enable only when needed for security investigation.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="isfm-log-retention"><?php esc_html_e( 'Log Retention (days)', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<input type="number" name="isfm_log_retention_days" id="isfm-log-retention" value="<?php echo esc_attr( get_option( 'isfm_log_retention_days', 365 ) ); ?>" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( '0 = keep forever.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'PDF Thumbnails', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isfm_enable_pdf_thumbnails" value="1" <?php checked( get_option( 'isfm_enable_pdf_thumbnails', 1 ) ); ?> /> <?php esc_html_e( 'Auto-generate thumbnail from PDF first page', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="isfm-allowed-extensions"><?php esc_html_e( 'Allowed File Extensions', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<textarea name="isfm_allowed_extensions" id="isfm-allowed-extensions" class="regular-text" rows="3"><?php echo esc_textarea( get_option( 'isfm_allowed_extensions', 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,zip,rar,7z,jpg,jpeg,png,gif,webp,mp4,mp3,wav' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Comma-separated list of permitted extensions. Uploads with unlisted extensions are blocked.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Cyrillic Titles', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label><input type="checkbox" name="isfm_cyrillic_titles" value="1" <?php checked( get_option( 'isfm_cyrillic_titles', 0 ) ); ?> /> <?php esc_html_e( 'Auto-convert upload title to Serbian Cyrillic', 'isoft-fm-foundation' ); ?></label>
					<p class="description"><?php esc_html_e( 'When enabled, the title field is pre-filled with a Cyrillic transliteration of the filename.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
		</table>

		<?php elseif ( 'display' === $active_tab ) : ?>
		<table class="form-table">
			<tr>
				<th><label for="isfm-default-button-text"><?php esc_html_e( 'Default Button Text', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<input type="text" name="isfm_default_button_text" id="isfm-default-button-text"
						value="<?php echo esc_attr( get_option( 'isfm_default_button_text', '' ) ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Download', 'isoft-fm-foundation' ); ?>" />
					<p class="description"><?php esc_html_e( 'Text shown on download buttons site-wide. Leave empty to use "Download".', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default Layout', 'isoft-fm-foundation' ); ?></th>
				<td>
					<select name="isfm_listing_layout">
						<?php
						foreach ( array(
							'list'  => __( 'List', 'isoft-fm-foundation' ),
							'grid'  => __( 'Grid', 'isoft-fm-foundation' ),
							'table' => __( 'Table', 'isoft-fm-foundation' ),
						) as $v => $l ) :
							?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( get_option( 'isfm_listing_layout', 'list' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="isfm-items-per-page"><?php esc_html_e( 'Items Per Page', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="number" name="isfm_items_per_page" id="isfm-items-per-page" value="<?php echo esc_attr( get_option( 'isfm_items_per_page', 10 ) ); ?>" min="1" max="100" class="small-text" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Show in Listings', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label><input type="checkbox" name="isfm_show_file_size" value="1" <?php checked( get_option( 'isfm_show_file_size', 1 ) ); ?> /> <?php esc_html_e( 'File size', 'isoft-fm-foundation' ); ?></label><br>
					<label><input type="checkbox" name="isfm_show_download_count" value="1" <?php checked( get_option( 'isfm_show_download_count', 1 ) ); ?> /> <?php esc_html_e( 'Download count', 'isoft-fm-foundation' ); ?></label><br>
					<label><input type="checkbox" name="isfm_show_date" value="1" <?php checked( get_option( 'isfm_show_date', 1 ) ); ?> /> <?php esc_html_e( 'Date', 'isoft-fm-foundation' ); ?></label>
				</td>
			</tr>
		</table>

		<hr>

		<h2><?php esc_html_e( 'Theming', 'isoft-fm-foundation' ); ?></h2>
		<p><?php esc_html_e( 'Visual styling is exposed via CSS custom properties on :root. Override any value via WordPress Customizer — no plugin file edits, no selector knowledge needed.', 'isoft-fm-foundation' ); ?></p>

		<details class="isfm-theming-details" open>
			<summary><strong><?php esc_html_e( 'Example: recolor PDF icons + soften card borders', 'isoft-fm-foundation' ); ?></strong></summary>
			<pre class="isfm-theming-snippet"><code><?php echo esc_html( ":root {\n    --isfm-icon-pdf-bg: #1a73e8;\n    --isfm-card-border: #ddd;\n}" ); ?></code></pre>
		</details>

		<details class="isfm-theming-details">
			<summary><strong><?php esc_html_e( 'All CSS variables (18)', 'isoft-fm-foundation' ); ?></strong></summary>
			<table class="widefat striped isfm-theming-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Variable', 'isoft-fm-foundation' ); ?></th>
						<th><?php esc_html_e( 'Default', 'isoft-fm-foundation' ); ?></th>
						<th><?php esc_html_e( 'Controls', 'isoft-fm-foundation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>--isfm-card-bg</code></td><td><code>#fff</code></td><td><?php esc_html_e( 'Card background', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-card-border</code></td><td><code>#e5e5e5</code></td><td><?php esc_html_e( 'Card and grid borders', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-row-border</code></td><td><code>#f0f0f0</code></td><td><?php esc_html_e( 'Per-file row separator', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-title-band-bg</code></td><td><code>#f6f7f9</code></td><td><?php esc_html_e( 'Grid-mode title band background', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-meta-color</code></td><td><code>#666</code></td><td><?php esc_html_e( 'Date / size / count text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-empty-color</code></td><td><code>#888</code></td><td><?php esc_html_e( '"No files available" text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-badge-hot-bg</code></td><td><code>#e74c3c</code></td><td><?php esc_html_e( 'HOT badge background', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-badge-hot-color</code></td><td><code>#fff</code></td><td><?php esc_html_e( 'HOT badge text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-color</code></td><td><code>#fff</code></td><td><?php esc_html_e( 'File-type icon/badge text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-pdf-bg</code></td><td><code>#c0392b</code></td><td><?php esc_html_e( 'PDF file color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-doc-bg</code></td><td><code>#2980b9</code></td><td><?php esc_html_e( 'DOC / DOCX color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-xls-bg</code></td><td><code>#27ae60</code></td><td><?php esc_html_e( 'XLS / XLSX color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-ppt-bg</code></td><td><code>#e67e22</code></td><td><?php esc_html_e( 'PPT / PPTX color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-zip-bg</code></td><td><code>#8e44ad</code></td><td><?php esc_html_e( 'Archive (ZIP / RAR / 7Z) color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-img-bg</code></td><td><code>#16a085</code></td><td><?php esc_html_e( 'Image color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-vid-bg</code></td><td><code>#2c3e50</code></td><td><?php esc_html_e( 'Video color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-aud-bg</code></td><td><code>#d35400</code></td><td><?php esc_html_e( 'Audio color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isfm-icon-file-bg</code></td><td><code>#7f8c8d</code></td><td><?php esc_html_e( 'Generic / unknown file color', 'isoft-fm-foundation' ); ?></td></tr>
				</tbody>
			</table>
		</details>

		<details class="isfm-theming-details">
			<summary><strong><?php esc_html_e( 'Public CSS classes (advanced)', 'isoft-fm-foundation' ); ?></strong></summary>
			<p class="description"><?php esc_html_e( 'For deeper changes (layout, spacing, typography), target these classes directly. All public classes use the .isfm- prefix with BEM naming.', 'isoft-fm-foundation' ); ?></p>
			<table class="widefat striped isfm-theming-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Class', 'isoft-fm-foundation' ); ?></th>
						<th><?php esc_html_e( 'Element', 'isoft-fm-foundation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>.isfm-download-card</code></td><td><?php esc_html_e( 'Outer wrapper around one download', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-download-card__title</code></td><td><?php esc_html_e( 'Multi-file card heading', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-file-item</code></td><td><?php esc_html_e( 'Per-file row', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-file-item__icon</code></td><td><?php esc_html_e( 'Large file-type tile (list mode only)', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-file-item__title</code></td><td><?php esc_html_e( 'File or download title link', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-file-item__meta</code></td><td><?php esc_html_e( 'Date / size / count meta block', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-file-item__action</code></td><td><?php esc_html_e( 'Action column (button or status label)', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-download-btn</code></td><td><?php esc_html_e( 'The action button itself', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-meta--type</code></td><td><?php esc_html_e( 'Inline file-type badge (grid mode only)', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-badge--hot</code></td><td><?php esc_html_e( 'HOT marker', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-grid</code></td><td><?php esc_html_e( 'Grid wrapper', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-list-wrap</code></td><td><?php esc_html_e( 'List wrapper', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isfm-category-grid</code></td><td><?php esc_html_e( 'Category grid wrapper', 'isoft-fm-foundation' ); ?></td></tr>
				</tbody>
			</table>
		</details>

		<p>
			<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=custom_css' ) ); ?>" class="button button-primary" target="_blank" rel="noopener">
				<?php esc_html_e( 'Open Customizer → Additional CSS', 'isoft-fm-foundation' ); ?>
			</a>
		</p>

		<?php elseif ( 'security' === $active_tab ) : ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Detected Server', 'isoft-fm-foundation' ); ?></th>
				<td><code><?php echo esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unknown', 'isoft-fm-foundation' ) ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'File Serving Method', 'isoft-fm-foundation' ); ?></th>
				<td>
					<select name="isfm_serve_method">
						<?php
						foreach ( array(
							'auto'      => __( 'Auto-detect', 'isoft-fm-foundation' ),
							'xsendfile' => 'X-Sendfile (Apache)',
							'xaccel'    => 'X-Accel-Redirect (Nginx)',
							'php'       => __( 'PHP streaming', 'isoft-fm-foundation' ),
						) as $v => $l ) :
							?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( get_option( 'isfm_serve_method', 'auto' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="isfm-rate-limit"><?php esc_html_e( 'Rate Limit (per IP/hour)', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<input type="number" name="isfm_rate_limit_per_hour" id="isfm-rate-limit" value="<?php echo esc_attr( get_option( 'isfm_rate_limit_per_hour', 0 ) ); ?>" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( '0 = no limit.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Hotlink Protection', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isfm_hotlink_protection" value="1" <?php checked( get_option( 'isfm_hotlink_protection', 0 ) ); ?> /> <?php esc_html_e( 'Block downloads from external referers', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
		</table>

		<?php elseif ( 'advanced' === $active_tab ) : ?>
		<table class="form-table">
			<tr>
				<th><label for="isfm-archive-slug"><?php esc_html_e( 'Download Archive Slug', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="isfm_archive_slug" id="isfm-archive-slug" value="<?php echo esc_attr( get_option( 'isfm_archive_slug', 'downloads' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="isfm-category-slug"><?php esc_html_e( 'Category Archive Slug', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="isfm_category_slug" id="isfm-category-slug" value="<?php echo esc_attr( get_option( 'isfm_category_slug', 'download-category' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="isfm-tag-slug"><?php esc_html_e( 'Tag Archive Slug', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="isfm_tag_slug" id="isfm-tag-slug" value="<?php echo esc_attr( get_option( 'isfm_tag_slug', 'download-tag' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Flush Rewrite Rules', 'isoft-fm-foundation' ); ?></th>
				<td>
					<button type="submit" name="isfm_flush_rewrite" value="1" class="button"><?php esc_html_e( 'Flush Now', 'isoft-fm-foundation' ); ?></button>
					<p class="description"><?php esc_html_e( 'Run this after changing slug options.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Uninstall Behavior', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="isfm_delete_data_on_uninstall" value="1" <?php checked( get_option( 'isfm_delete_data_on_uninstall', 0 ) ); ?> />
						<?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled', 'isoft-fm-foundation' ); ?>
					</label>
					<p class="description" style="color:#c00;"><?php esc_html_e( 'Warning: this will permanently delete all downloads, files, logs, and settings.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<?php submit_button(); ?>
	</form>
	<?php endif; ?>
</div>
