<?php defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals. ?>
<div class="wrap">
	<h1><?php esc_html_e( 'I-Soft File Manager: Foundation Settings', 'isoft-fm-foundation' ); ?></h1>

	<?php settings_errors( 'isoft_fmf_settings' ); ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cosmetic post-action banner; the action itself is nonce-protected.
	if ( isset( $_GET['isoft_fmf_cache_cleared'] ) ) :
		$cleared = (int) $_GET['isoft_fmf_cache_cleared']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ( $cleared > 0 ) {
					printf(
						/* translators: %d: number of cache files deleted */
						esc_html( _n( 'Bundle cache cleared. %d file removed.', 'Bundle cache cleared. %d files removed.', $cleared, 'isoft-fm-foundation' ) ),
						(int) $cleared
					);
				} else {
					esc_html_e( 'Bundle cache was already empty.', 'isoft-fm-foundation' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

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
						'page'      => 'isoft-fmf-settings',
						'post_type' => 'isoft_fmf_file',
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

	<?php
	// 0.12.3+: the 4 option-driven tabs (General, Display, Security,
	// Advanced) render as a React app. Maintenance + Extensions stay
	// PHP-rendered — they're action/marketing surfaces with stateful
	// lock displays, integrity-check controls, marketing copy. No
	// benefit from a React port.
	$react_tabs = array( 'general', 'display', 'security', 'advanced' );
	if ( in_array( $active_tab, $react_tabs, true )
		&& wp_script_is( ISOFT_FMF_Settings_Page::SCRIPT_HANDLE, 'enqueued' ) ) :
		?>
		<div
			id="isoft-fmf-settings-root"
			data-tab="<?php echo esc_attr( $active_tab ); ?>"
		></div>
		<noscript>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'The Settings page requires JavaScript. Please enable JavaScript in your browser, or revert to the previous plugin version.', 'isoft-fm-foundation' ); ?></p>
			</div>
		</noscript>

		<?php if ( 'general' === $active_tab ) : ?>
			<p class="description" style="margin-top: 24px; max-width: 720px;">
				<strong><?php esc_html_e( 'Storage Location:', 'isoft-fm-foundation' ); ?></strong>
				<code><?php echo esc_html( isoft_fmf_files_dir() ); ?></code><br>
				<?php esc_html_e( 'All files are stored here, organised by category. The folder is protected by .htaccess and served through the plugin\'s secure download handler.', 'isoft-fm-foundation' ); ?>
			</p>
		<?php endif; ?>
		<?php
		return;
	endif;
	?>

	<?php if ( 'extensions' === $active_tab ) : ?>
		<?php require __DIR__ . '/extensions-tab.php'; ?>
	<?php elseif ( 'maintenance' === $active_tab ) : ?>
		<?php require __DIR__ . '/maintenance-tab.php'; ?>
	<?php elseif ( 'general' === $active_tab ) : ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'isoft_fmf_general' ); ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Storage Location', 'isoft-fm-foundation' ); ?></th>
				<td>
					<code><?php echo esc_html( isoft_fmf_files_dir() ); ?></code>
					<p class="description"><?php esc_html_e( 'All files are stored here, organised by category. The folder is protected by .htaccess and served through the plugin\'s secure download handler.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default Access Role', 'isoft-fm-foundation' ); ?></th>
				<td>
					<select name="isoft_fmf_default_access_role">
						<?php
						foreach ( array(
							'public'        => __( 'Public', 'isoft-fm-foundation' ),
							'subscriber'    => __( 'Subscriber+', 'isoft-fm-foundation' ),
							'editor'        => __( 'Editor+', 'isoft-fm-foundation' ),
							'administrator' => __( 'Administrator only', 'isoft-fm-foundation' ),
						) as $v => $l ) :
							?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( get_option( 'isoft_fmf_default_access_role', 'public' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Download Counting', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isoft_fmf_enable_counting" value="1" <?php checked( get_option( 'isoft_fmf_enable_counting', 1 ) ); ?> /> <?php esc_html_e( 'Count downloads', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Basic Logging', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isoft_fmf_enable_logging" value="1" <?php checked( get_option( 'isoft_fmf_enable_logging', 1 ) ); ?> /> <?php esc_html_e( 'Log downloads (timestamp, file, user)', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Detailed Logging', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label><input type="checkbox" name="isoft_fmf_enable_detailed_logging" value="1" <?php checked( get_option( 'isoft_fmf_enable_detailed_logging', 0 ) ); ?> /> <?php esc_html_e( 'Also log IP address, user agent, and referer', 'isoft-fm-foundation' ); ?></label>
					<p class="description"><?php esc_html_e( 'Collects personally identifiable information (PII). Enable only when needed for security investigation.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-log-retention"><?php esc_html_e( 'Log Retention (days)', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<input type="number" name="isoft_fmf_log_retention_days" id="isoft-fmf-log-retention" value="<?php echo esc_attr( get_option( 'isoft_fmf_log_retention_days', 365 ) ); ?>" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( '0 = keep forever.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'PDF Thumbnails', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isoft_fmf_enable_pdf_thumbnails" value="1" <?php checked( get_option( 'isoft_fmf_enable_pdf_thumbnails', 1 ) ); ?> /> <?php esc_html_e( 'Auto-generate thumbnail from PDF first page', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-allowed-extensions"><?php esc_html_e( 'Allowed File Extensions', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<textarea name="isoft_fmf_allowed_extensions" id="isoft-fmf-allowed-extensions" class="regular-text" rows="3"><?php echo esc_textarea( get_option( 'isoft_fmf_allowed_extensions', 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,zip,rar,7z,jpg,jpeg,png,gif,webp,mp4,mp3,wav' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Comma-separated list of permitted extensions. Uploads with unlisted extensions are blocked.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Cyrillic Titles', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label><input type="checkbox" name="isoft_fmf_cyrillic_titles" value="1" <?php checked( get_option( 'isoft_fmf_cyrillic_titles', 0 ) ); ?> /> <?php esc_html_e( 'Auto-convert upload title to Serbian Cyrillic', 'isoft-fm-foundation' ); ?></label>
					<p class="description"><?php esc_html_e( 'When enabled, the title field is pre-filled with a Cyrillic transliteration of the filename.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<?php elseif ( 'display' === $active_tab ) : ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'isoft_fmf_display' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="isoft-fmf-default-button-text"><?php esc_html_e( 'Default Button Text', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<input type="text" name="isoft_fmf_default_button_text" id="isoft-fmf-default-button-text"
						value="<?php echo esc_attr( get_option( 'isoft_fmf_default_button_text', '' ) ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Download', 'isoft-fm-foundation' ); ?>" />
					<p class="description"><?php esc_html_e( 'Text shown on download buttons site-wide. Leave empty to use "Download".', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default Layout', 'isoft-fm-foundation' ); ?></th>
				<td>
					<select name="isoft_fmf_listing_layout">
						<?php
						foreach ( array(
							'list'  => __( 'List', 'isoft-fm-foundation' ),
							'grid'  => __( 'Grid', 'isoft-fm-foundation' ),
							'table' => __( 'Table', 'isoft-fm-foundation' ),
						) as $v => $l ) :
							?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( get_option( 'isoft_fmf_listing_layout', 'list' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-items-per-page"><?php esc_html_e( 'Items Per Page', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="number" name="isoft_fmf_items_per_page" id="isoft-fmf-items-per-page" value="<?php echo esc_attr( get_option( 'isoft_fmf_items_per_page', 10 ) ); ?>" min="1" max="100" class="small-text" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Show in Listings', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label><input type="checkbox" name="isoft_fmf_show_file_size" value="1" <?php checked( get_option( 'isoft_fmf_show_file_size', 1 ) ); ?> /> <?php esc_html_e( 'File size', 'isoft-fm-foundation' ); ?></label><br>
					<label><input type="checkbox" name="isoft_fmf_show_download_count" value="1" <?php checked( get_option( 'isoft_fmf_show_download_count', 1 ) ); ?> /> <?php esc_html_e( 'Download count', 'isoft-fm-foundation' ); ?></label><br>
					<label><input type="checkbox" name="isoft_fmf_show_date" value="1" <?php checked( get_option( 'isoft_fmf_show_date', 1 ) ); ?> /> <?php esc_html_e( 'Date', 'isoft-fm-foundation' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'External Link Target', 'isoft-fm-foundation' ); ?></th>
				<td>
					<?php $target = get_option( 'isoft_fmf_external_link_target', '_blank' ); ?>
					<select name="isoft_fmf_external_link_target">
						<option value="_blank" <?php selected( $target, '_blank' ); ?>><?php esc_html_e( 'Open in new tab', 'isoft-fm-foundation' ); ?></option>
						<option value="_self" <?php selected( $target, '_self' ); ?>><?php esc_html_e( 'Open in same window', 'isoft-fm-foundation' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Where external-URL download buttons open the linked page (e.g. Google Drive, Dropbox). Only affects external links — local files always download in place.', 'isoft-fm-foundation' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'ZIP Bundle', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="isoft_fmf_enable_zip_bundle" value="1" <?php checked( get_option( 'isoft_fmf_enable_zip_bundle', 0 ) ); ?>
							<?php disabled( ! class_exists( 'ZipArchive' ) ); ?> />
						<?php esc_html_e( 'Show a "Download all as ZIP" button on multi-file downloads', 'isoft-fm-foundation' ); ?>
					</label>
					<?php if ( ! class_exists( 'ZipArchive' ) ) : ?>
						<p class="description" style="color:#b32d2e;">
							<?php esc_html_e( 'Requires the PHP zip extension. Ask your host to install php-zip (or rebuild PHP with --enable-zip) to use this feature.', 'isoft-fm-foundation' ); ?>
						</p>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Only local files are bundled — external-URL files are skipped. Each bundle counts as one rate-limit hit.', 'isoft-fm-foundation' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'ZIP Bundle Cache', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="isoft_fmf_enable_zip_cache" value="1" <?php checked( get_option( 'isoft_fmf_enable_zip_cache', 0 ) ); ?> />
						<?php esc_html_e( 'Cache generated ZIP bundles so repeated downloads serve the same file', 'isoft-fm-foundation' ); ?>
					</label>
					<br>
					<label style="display:inline-block;margin-top:.5em;">
						<?php esc_html_e( 'Cache duration:', 'isoft-fm-foundation' ); ?>
						<input type="number" name="isoft_fmf_zip_cache_days" value="<?php echo esc_attr( get_option( 'isoft_fmf_zip_cache_days', 7 ) ); ?>" min="1" max="365" class="small-text" />
						<?php esc_html_e( 'days', 'isoft-fm-foundation' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Cache is invalidated automatically when files are added, removed, or modified. The duration counts idle time — a bundle that keeps getting downloaded stays cached as long as people use it. A hard ceiling at 3× the duration above forces a rebuild eventually no matter what. Cached bundles live under wp-content/uploads/isoft-fmf-files/.bundle-cache/.', 'isoft-fm-foundation' ); ?>
					</p>
					<p class="description" style="margin-top:.6em;">
						<?php esc_html_e( 'A daily cleanup also runs after the integrity check (or at midnight if integrity is disabled) and removes cache files past 2× the duration above plus any cache for deleted downloads.', 'isoft-fm-foundation' ); ?>
					</p>
					<?php
					// Manual nuke — goes through admin-post.php (NOT this form's
					// options.php submission), so it's a sibling link, not a form
					// control. Safe to render inside the cell.
					$clear_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=isoft_fmf_clear_bundle_cache' ),
						'isoft_fmf_clear_bundle_cache'
					);
					?>
					<p style="margin-top:.8em;">
						<a href="<?php echo esc_url( $clear_url ); ?>"
							class="button"
							onclick="return confirm('<?php echo esc_js( __( 'Delete every cached bundle ZIP right now?', 'isoft-fm-foundation' ) ); ?>');">
							<?php esc_html_e( 'Clear bundle cache now', 'isoft-fm-foundation' ); ?>
						</a>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Theming', 'isoft-fm-foundation' ); ?></h2>
		<p><?php esc_html_e( 'Visual styling is exposed via CSS custom properties on :root. Override any value via WordPress Customizer — no plugin file edits, no selector knowledge needed.', 'isoft-fm-foundation' ); ?></p>

		<details class="isoft-fmf-theming-details" open>
			<summary><strong><?php esc_html_e( 'Example: recolor PDF icons + soften card borders', 'isoft-fm-foundation' ); ?></strong></summary>
			<pre class="isoft-fmf-theming-snippet"><code><?php echo esc_html( ":root {\n    --isoft-fmf-icon-pdf-bg: #1a73e8;\n    --isoft-fmf-card-border: #ddd;\n}" ); ?></code></pre>
		</details>

		<details class="isoft-fmf-theming-details">
			<summary><strong><?php esc_html_e( 'All CSS variables (18)', 'isoft-fm-foundation' ); ?></strong></summary>
			<table class="widefat striped isoft-fmf-theming-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Variable', 'isoft-fm-foundation' ); ?></th>
						<th><?php esc_html_e( 'Default', 'isoft-fm-foundation' ); ?></th>
						<th><?php esc_html_e( 'Controls', 'isoft-fm-foundation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>--isoft-fmf-card-bg</code></td><td><code>#fff</code></td><td><?php esc_html_e( 'Card background', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-card-border</code></td><td><code>#e5e5e5</code></td><td><?php esc_html_e( 'Card and grid borders', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-row-border</code></td><td><code>#f0f0f0</code></td><td><?php esc_html_e( 'Per-file row separator', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-title-band-bg</code></td><td><code>#f6f7f9</code></td><td><?php esc_html_e( 'Grid-mode title band background', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-meta-color</code></td><td><code>#666</code></td><td><?php esc_html_e( 'Date / size / count text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-empty-color</code></td><td><code>#888</code></td><td><?php esc_html_e( '"No files available" text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-badge-hot-bg</code></td><td><code>#e74c3c</code></td><td><?php esc_html_e( 'HOT badge background', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-badge-hot-color</code></td><td><code>#fff</code></td><td><?php esc_html_e( 'HOT badge text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-color</code></td><td><code>#fff</code></td><td><?php esc_html_e( 'File-type icon/badge text', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-pdf-bg</code></td><td><code>#c0392b</code></td><td><?php esc_html_e( 'PDF file color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-doc-bg</code></td><td><code>#2980b9</code></td><td><?php esc_html_e( 'DOC / DOCX color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-xls-bg</code></td><td><code>#27ae60</code></td><td><?php esc_html_e( 'XLS / XLSX color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-ppt-bg</code></td><td><code>#e67e22</code></td><td><?php esc_html_e( 'PPT / PPTX color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-zip-bg</code></td><td><code>#8e44ad</code></td><td><?php esc_html_e( 'Archive (ZIP / RAR / 7Z) color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-img-bg</code></td><td><code>#16a085</code></td><td><?php esc_html_e( 'Image color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-vid-bg</code></td><td><code>#2c3e50</code></td><td><?php esc_html_e( 'Video color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-aud-bg</code></td><td><code>#d35400</code></td><td><?php esc_html_e( 'Audio color', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>--isoft-fmf-icon-file-bg</code></td><td><code>#7f8c8d</code></td><td><?php esc_html_e( 'Generic / unknown file color', 'isoft-fm-foundation' ); ?></td></tr>
				</tbody>
			</table>
		</details>

		<details class="isoft-fmf-theming-details">
			<summary><strong><?php esc_html_e( 'Public CSS classes (advanced)', 'isoft-fm-foundation' ); ?></strong></summary>
			<p class="description"><?php esc_html_e( 'For deeper changes (layout, spacing, typography), target these classes directly. All public classes use the .isoft-fmf- prefix with BEM naming.', 'isoft-fm-foundation' ); ?></p>
			<table class="widefat striped isoft-fmf-theming-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Class', 'isoft-fm-foundation' ); ?></th>
						<th><?php esc_html_e( 'Element', 'isoft-fm-foundation' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>.isoft-fmf-download-card</code></td><td><?php esc_html_e( 'Outer wrapper around one download', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-download-card__title</code></td><td><?php esc_html_e( 'Multi-file card heading', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-file-item</code></td><td><?php esc_html_e( 'Per-file row', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-file-item__icon</code></td><td><?php esc_html_e( 'Large file-type tile (list mode only)', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-file-item__title</code></td><td><?php esc_html_e( 'File or download title link', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-file-item__meta</code></td><td><?php esc_html_e( 'Date / size / count meta block', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-file-item__action</code></td><td><?php esc_html_e( 'Action column (button or status label)', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-download-btn</code></td><td><?php esc_html_e( 'The action button itself', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-meta--type</code></td><td><?php esc_html_e( 'Inline file-type badge (grid mode only)', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-badge--hot</code></td><td><?php esc_html_e( 'HOT marker', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-grid</code></td><td><?php esc_html_e( 'Grid wrapper', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-list-wrap</code></td><td><?php esc_html_e( 'List wrapper', 'isoft-fm-foundation' ); ?></td></tr>
					<tr><td><code>.isoft-fmf-category-grid</code></td><td><?php esc_html_e( 'Category grid wrapper', 'isoft-fm-foundation' ); ?></td></tr>
				</tbody>
			</table>
		</details>

		<p>
			<a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=custom_css' ) ); ?>" class="button button-primary" target="_blank" rel="noopener">
				<?php esc_html_e( 'Open Customizer → Additional CSS', 'isoft-fm-foundation' ); ?>
			</a>
		</p>

	<?php elseif ( 'security' === $active_tab ) : ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'isoft_fmf_security' ); ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Detected Server', 'isoft-fm-foundation' ); ?></th>
				<td><code><?php echo esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unknown', 'isoft-fm-foundation' ) ); ?></code></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'File Serving Method', 'isoft-fm-foundation' ); ?></th>
				<td>
					<select name="isoft_fmf_serve_method">
						<?php
						foreach ( array(
							'auto'      => __( 'Auto-detect', 'isoft-fm-foundation' ),
							'xsendfile' => 'X-Sendfile (Apache)',
							'xaccel'    => 'X-Accel-Redirect (Nginx)',
							'php'       => __( 'PHP streaming', 'isoft-fm-foundation' ),
						) as $v => $l ) :
							?>
							<option value="<?php echo esc_attr( $v ); ?>" <?php selected( get_option( 'isoft_fmf_serve_method', 'auto' ), $v ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-rate-limit"><?php esc_html_e( 'Rate Limit (per IP/hour)', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<input type="number" name="isoft_fmf_rate_limit_per_hour" id="isoft-fmf-rate-limit" value="<?php echo esc_attr( get_option( 'isoft_fmf_rate_limit_per_hour', 0 ) ); ?>" min="0" class="small-text" />
					<p class="description"><?php esc_html_e( '0 = no limit.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Hotlink Protection', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="isoft_fmf_hotlink_protection" value="1" <?php checked( get_option( 'isoft_fmf_hotlink_protection', 0 ) ); ?> /> <?php esc_html_e( 'Block downloads from external referers', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-ua-blocklist"><?php esc_html_e( 'User-Agent Blocklist', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<textarea name="isoft_fmf_block_user_agents" id="isoft-fmf-ua-blocklist" rows="6" class="large-text code" placeholder="curl&#10;wget&#10;HeadlessChrome&#10;SemrushBot"><?php echo esc_textarea( get_option( 'isoft_fmf_block_user_agents', '' ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One pattern per line. Each line matches as a case-insensitive substring against the request User-Agent header — e.g. "curl" blocks "curl/7.88.1". Empty lines and requests with no User-Agent header are not blocked.', 'isoft-fm-foundation' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<?php elseif ( 'advanced' === $active_tab ) : ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'isoft_fmf_advanced' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="isoft-fmf-archive-slug"><?php esc_html_e( 'Download Archive Slug', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="isoft_fmf_archive_slug" id="isoft-fmf-archive-slug" value="<?php echo esc_attr( get_option( 'isoft_fmf_archive_slug', 'downloads' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-category-slug"><?php esc_html_e( 'Category Archive Slug', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="isoft_fmf_category_slug" id="isoft-fmf-category-slug" value="<?php echo esc_attr( get_option( 'isoft_fmf_category_slug', 'download-category' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-tag-slug"><?php esc_html_e( 'Tag Archive Slug', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="isoft_fmf_tag_slug" id="isoft-fmf-tag-slug" value="<?php echo esc_attr( get_option( 'isoft_fmf_tag_slug', 'download-tag' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Flush Rewrite Rules', 'isoft-fm-foundation' ); ?></th>
				<td>
					<button type="submit" name="isoft_fmf_flush_rewrite" value="1" class="button"><?php esc_html_e( 'Flush Now', 'isoft-fm-foundation' ); ?></button>
					<p class="description"><?php esc_html_e( 'Run this after changing slug options.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Uninstall Behavior', 'isoft-fm-foundation' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="isoft_fmf_delete_data_on_uninstall" value="1" <?php checked( get_option( 'isoft_fmf_delete_data_on_uninstall', 0 ) ); ?> />
						<?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled', 'isoft-fm-foundation' ); ?>
					</label>
					<p class="description" style="color:#c00;"><?php esc_html_e( 'Warning: this will permanently delete all downloads, files, logs, and settings.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php endif; ?>
</div>
