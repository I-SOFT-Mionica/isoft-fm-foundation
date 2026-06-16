<?php defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals. ?>
<table class="form-table">
	<tr>
		<th><label for="isoft-fmf-version"><?php esc_html_e( 'Version', 'isoft-fm-foundation' ); ?></label></th>
		<td><input type="text" name="_isoft_fmf_version" id="isoft-fmf-version" value="<?php echo esc_attr( $version ); ?>" class="regular-text" placeholder="1.0.0" /></td>
	</tr>
	<tr>
		<th><label for="isoft-fmf-changelog"><?php esc_html_e( 'Changelog', 'isoft-fm-foundation' ); ?></label></th>
		<td>
			<textarea name="_isoft_fmf_changelog" id="isoft-fmf-changelog" class="widefat" rows="5"><?php echo esc_textarea( $changelog ); ?></textarea>
			<p class="description"><?php esc_html_e( "What's new in this version.", 'isoft-fm-foundation' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><?php esc_html_e( 'Listing Flags', 'isoft-fm-foundation' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="_isoft_fmf_featured" value="1" <?php checked( $featured ); ?> />
				<span class="dashicons dashicons-star-filled" style="color:#f0b849;vertical-align:middle;"></span>
				<?php esc_html_e( 'Featured — pin to the top of every download listing', 'isoft-fm-foundation' ); ?>
			</label>
			<br>
			<label>
				<input type="checkbox" name="_isoft_fmf_external_only" value="1" <?php checked( $external_only ); ?> />
				<?php esc_html_e( 'External only — hide local files on the download card; show external links only', 'isoft-fm-foundation' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Use "External only" when local files exist as backups but the external URL should be the canonical one users click.', 'isoft-fm-foundation' ); ?>
			</p>
		</td>
	</tr>
	<tr>
		<th><label for="isoft-fmf-license"><?php esc_html_e( 'License', 'isoft-fm-foundation' ); ?></label></th>
		<td>
			<select name="_isoft_fmf_license_id" id="isoft-fmf-license">
				<option value="0"><?php esc_html_e( '— None —', 'isoft-fm-foundation' ); ?></option>
				<?php foreach ( $licenses as $lic ) : ?>
					<option value="<?php echo esc_attr( $lic->id ); ?>" <?php selected( $license_id, $lic->id ); ?>>
						<?php echo esc_html( $lic->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<!-- TODO v1.0: Rework agreement UX — conditional display, better relationship with license -->
	<tr>
		<th><?php esc_html_e( 'Require Agreement', 'isoft-fm-foundation' ); ?></th>
		<td>
			<label>
				<input type="checkbox" name="_isoft_fmf_require_agree" value="1" <?php checked( $require_agree ); ?> />
				<?php esc_html_e( 'Require user to agree before downloading', 'isoft-fm-foundation' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Uses the assigned license full text, or the custom text below.', 'isoft-fm-foundation' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label for="isoft-fmf-agree-text"><?php esc_html_e( 'Agreement Text', 'isoft-fm-foundation' ); ?></label></th>
		<td>
			<textarea name="_isoft_fmf_agree_text" id="isoft-fmf-agree-text" class="widefat" rows="4"><?php echo esc_textarea( $agree_text ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Shown in the agreement modal if no license is assigned.', 'isoft-fm-foundation' ); ?></p>
		</td>
	</tr>
	<tr>
		<th><label for="isoft-fmf-author-name"><?php esc_html_e( 'Author Name', 'isoft-fm-foundation' ); ?></label></th>
		<td><input type="text" name="_isoft_fmf_author_name" id="isoft-fmf-author-name" value="<?php echo esc_attr( $author_name ); ?>" class="regular-text" /></td>
	</tr>
	<tr>
		<th><label for="isoft-fmf-author-url"><?php esc_html_e( 'Author URL', 'isoft-fm-foundation' ); ?></label></th>
		<td><input type="url" name="_isoft_fmf_author_url" id="isoft-fmf-author-url" value="<?php echo esc_attr( $author_url ); ?>" class="regular-text" placeholder="https://…" /></td>
	</tr>
	<tr>
		<th><label for="isoft-fmf-date-published"><?php esc_html_e( 'Date Published', 'isoft-fm-foundation' ); ?></label></th>
		<td>
			<input type="date" name="_isoft_fmf_date_published" id="isoft-fmf-date-published" value="<?php echo esc_attr( $date_published ); ?>" />
			<p class="description"><?php esc_html_e( 'Original publication date of the document (may differ from the WordPress post date).', 'isoft-fm-foundation' ); ?></p>
		</td>
	</tr>
</table>
