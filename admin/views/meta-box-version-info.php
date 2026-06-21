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
			<select name="_isoft_fmf_license_id" id="isoft-fmf-license" data-original-license-id="<?php echo esc_attr( $license_id ); ?>" data-download-count="<?php echo esc_attr( (int) $download_count ); ?>">
				<option value="0" <?php selected( $license_id, 0 ); ?>><?php esc_html_e( '— None —', 'isoft-fm-foundation' ); ?></option>
				<option value="-1" <?php selected( $license_id, -1 ); ?>><?php esc_html_e( '— Inherit from category —', 'isoft-fm-foundation' ); ?></option>
				<?php foreach ( $licenses as $lic ) : ?>
					<option value="<?php echo esc_attr( $lic->id ); ?>" <?php selected( $license_id, $lic->id ); ?>>
						<?php echo esc_html( $lic->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $effective_license_id > 0 && $license_id <= 0 ) :
				$effective_license = ( new ISOFT_FMF_License_Manager() )->get( $effective_license_id );
				if ( $effective_license ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: license title currently inherited from category */
							esc_html__( 'Currently resolves to %s (inherited from category).', 'isoft-fm-foundation' ),
							'<strong>' . esc_html( $effective_license->title ) . '</strong>'
						);
						?>
					</p>
				<?php endif;
			endif; ?>
			<?php if ( $download_count > 0 ) : ?>
				<p class="description isoft-fmf-license-change-warning" style="display:none; color:#b32d2e;">
					<strong><?php esc_html_e( 'Heads up:', 'isoft-fm-foundation' ); ?></strong>
					<?php
					printf(
						/* translators: %d: total downloads served under the current license */
						esc_html( _n(
							'This file has been downloaded %d time under the current license. Changing the license affects new downloads only — recipients who already downloaded keep the original license terms perpetually (CC and most permissive licenses are irrevocable for distributed copies).',
							'This file has been downloaded %d times under the current license. Changing the license affects new downloads only — recipients who already downloaded keep the original license terms perpetually (CC and most permissive licenses are irrevocable for distributed copies).',
							(int) $download_count,
							'isoft-fm-foundation'
						) ),
						(int) $download_count
					);
					?>
				</p>
				<script>
				(function(){
					var sel = document.getElementById('isoft-fmf-license');
					if (!sel) return;
					var warning = sel.parentNode.querySelector('.isoft-fmf-license-change-warning');
					if (!warning) return;
					var originalId = sel.getAttribute('data-original-license-id');
					sel.addEventListener('change', function () {
						warning.style.display = (sel.value !== originalId) ? '' : 'none';
					});
				})();
				</script>
			<?php endif; ?>
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
