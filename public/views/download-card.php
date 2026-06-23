<?php
/**
 * Template: Download card.
 *
 * Single-file downloads render the one file as a self-contained card
 * (icon · title · meta · download button). Multi-file downloads render
 * a single summary tile (title link · aggregate meta · ZIP bundle button);
 * the title links to the post permalink where individual files can be
 * inspected and downloaded one at a time.
 *
 * Expected variables:
 *   $post     WP_Post  The isoft_fmf_file post.
 *   $settings array    Plugin settings from isoft_fmf_get_settings().
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$access     = new ISOFT_FMF_Access_Control();
$can_access = $access->can_access_download( $post->ID );
$files      = ( new ISOFT_FMF_File_Manager() )->get_files( $post->ID );

// "External only" — hide local files when the admin wants the external URL
// to be the canonical click target. Local files remain in storage (good for
// archival / backups) but never render on the card.
if ( get_post_meta( $post->ID, '_isoft_fmf_external_only', true ) ) {
	$files = array_values( array_filter( $files, fn( $f ): bool => 'external' === $f->file_type ) );
}

$require_agree = (bool) get_post_meta( $post->ID, '_isoft_fmf_require_agree', true );
// Resolved role (after walking inherit-from-category cascade), not the literal
// meta — the lock-icon check below has to reflect what the visitor actually
// faces. Reading the literal showed a lock on files that resolved to public
// (file set to 'inherit', category had no default, fell through to site-wide
// public) — see fix/access-role-display, 0.10.21.
$access_role       = $access->effective_role_for( $post->ID );
$role_label        = isoft_fmf_access_role_label( $access_role );
$effective_license = ( new ISOFT_FMF_License_Resolver() )->effective_license_row_for( $post->ID );
// HOT = set by nightly cron at 01:00 (top 10 downloads last 7 days), stored in post meta.
$is_hot     = (bool) get_post_meta( $post->ID, '_isoft_fmf_is_hot', true );
$license_id = (int) get_post_meta( $post->ID, '_isoft_fmf_license_id', true );
$license    = ( $require_agree && $license_id ) ? ( new ISOFT_FMF_License_Manager() )->get( $license_id ) : null;
// Cast to string on BOTH branches: the licenses table's full_text
// column is nullable (LONGTEXT, no NOT NULL constraint), and a license
// row with full_text=NULL would hit wp_kses_post(null) — deprecated
// since PHP 8.1, triggers a public-facing warning on frontends with
// display_errors on.
$agree_text = $license ? wp_kses_post( (string) $license->full_text ) : wp_kses_post( (string) get_post_meta( $post->ID, '_isoft_fmf_agree_text', true ) );
$btn_text   = $settings['default_button_text'] ?: __( 'Download', 'isoft-fm-foundation' );

// ZIP-bundle button is opt-in via Settings → Display and only renders
// when there are 2+ local files the user can actually access. External
// files are skipped (can't ZIP a URL).
$bundle_enabled   = $can_access
	&& get_option( 'isoft_fmf_enable_zip_bundle', 0 )
	&& class_exists( 'ZipArchive' );
$bundleable_files = $bundle_enabled
	? array_filter( $files, fn( $f ): bool => 'external' !== $f->file_type && empty( $f->is_missing ) )
	: array();
$show_bundle_btn  = $bundle_enabled && count( $bundleable_files ) >= 2;
$bundle_size      = $show_bundle_btn ? (int) array_sum( array_column( $bundleable_files, 'file_size' ) ) : 0;

// $isoft_fmf_expand_files: set by download-single.php to force per-file
// rendering on the post permalink page. Listings (category grids, download
// lists) leave it unset, so multi-file downloads collapse to a summary
// tile and the title links into this single page for actual file pick.
$expand_files = ! empty( $isoft_fmf_expand_files );
$is_multi     = count( $files ) > 1;
$use_summary  = $is_multi && ! $expand_files;

?>
<article class="isoft-fmf-download-card<?php echo $is_multi ? ' isoft-fmf-download-card--multi' : ''; ?>" id="isoft-fmf-download-<?php echo esc_attr( $post->ID ); ?>">

	<?php
	// Expanded multi-file view (single download page): give users a
	// "Download all" affordance above the file list so the bundle is
	// reachable without going back to the listing.
	if ( $is_multi && $expand_files && $show_bundle_btn ) :
		$bundle_label = sprintf(
			/* translators: %s: total size of bundled files */
			__( 'Download all (%s)', 'isoft-fm-foundation' ),
			size_format( $bundle_size )
		);
		?>
	<div class="isoft-fmf-download-card__bundle-action">
		<a href="<?php echo esc_url( isoft_fmf_get_bundle_url( (int) $post->ID ) ); ?>"
			class="wp-element-button isoft-fmf-download-btn isoft-fmf-download-btn--bundle"
			title="<?php echo esc_attr( $bundle_label ); ?>"
			download
			rel="nofollow">
			<?php echo esc_html( $bundle_label ); ?>
		</a>
	</div>
		<?php
	endif;
	?>

	<?php
	if ( $use_summary ) :
		// Aggregate meta for the summary tile. Total size sums every file
		// (even external — listed for context), distinct extensions feed
		// the type badge, post-level download count is the cached SUM of
		// per-file counters maintained by ISOFT_FMF_File_Manager.
		$file_count  = count( $files );
		$total_size  = (int) array_sum( array_map( static fn( $f ): int => (int) ( $f->file_size ?? 0 ), $files ) );
		$total_count = (int) get_post_meta( $post->ID, '_isoft_fmf_download_count', true );
		$post_date   = get_the_date( $settings['date_format'] ?: get_option( 'date_format' ), $post );

		$exts = array();
		foreach ( $files as $f ) {
			$ext = strtolower( pathinfo( $f->file_name ?? '', PATHINFO_EXTENSION ) );
			if ( '' !== $ext ) {
				$exts[ $ext ] = true;
			}
		}
		$distinct_exts = array_keys( $exts );
		$primary_ext   = $distinct_exts[0] ?? 'file';
		$primary_cls   = isoft_fmf_mime_icon_class( $primary_ext );
		// Mixed-type tiles use the generic-file colour so a red PDF badge
		// can't misrepresent a mostly-DOCX bundle. Single-type tiles use
		// that type's colour for the at-a-glance read.
		$badge_cls   = count( $distinct_exts ) > 1 ? 'file' : $primary_cls;
		$badge_label = sprintf(
			/* translators: %d: file count */
			_n( '%d file', '%d files', $file_count, 'isoft-fm-foundation' ),
			$file_count
		);
		$types_label = implode( ' · ', array_map( 'strtoupper', $distinct_exts ) );

		$item_class = 'isoft-fmf-file-item isoft-fmf-file-item--summary';
		?>
	<div class="<?php echo esc_attr( $item_class ); ?>">

		<div class="isoft-fmf-file-item__icon isoft-fmf-icon--<?php echo esc_attr( $badge_cls ); ?>" aria-hidden="true">
			<?php echo esc_html( (string) $file_count ); ?>
		</div>

		<div class="isoft-fmf-file-item__info">
			<div class="isoft-fmf-file-item__title">
				<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post->ID ) ); ?></a>
				<?php if ( $is_hot ) : ?>
					<span class="isoft-fmf-badge isoft-fmf-badge--hot">HOT</span>
				<?php endif; ?>
			</div>

			<div class="isoft-fmf-file-item__meta">
				<span class="isoft-fmf-meta isoft-fmf-meta--type isoft-fmf-type--<?php echo esc_attr( $badge_cls ); ?>">
					<?php echo esc_html( $badge_label ); ?>
				</span>

				<?php if ( $types_label ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--types">
					<?php echo esc_html( $types_label ); ?>
				</span>
				<?php endif; ?>

				<?php if ( $settings['show_date'] ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--date">
					<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					<?php echo esc_html( $post_date ); ?>
				</span>
				<?php endif; ?>

				<?php if ( $settings['show_file_size'] && $total_size ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--size">
					<span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
					<?php echo esc_html( size_format( $total_size ) ); ?>
				</span>
				<?php endif; ?>

				<?php if ( $settings['show_download_count'] ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--count">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php echo esc_html( number_format_i18n( $total_count ) ); ?>
				</span>
				<?php endif; ?>

				<?php isoft_fmf_render_card_lock_and_license( $access_role, $effective_license ); ?>
				<?php do_action( 'isoft_fmf_card_meta_extras', $post ); ?>
			</div>
		</div>

		<div class="isoft-fmf-file-item__action">
			<?php if ( ! $can_access && ! is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( wp_login_url( get_permalink( $post->ID ) ) ); ?>"
					class="wp-element-button isoft-fmf-download-btn isoft-fmf-download-btn--login">
					<?php esc_html_e( 'Login', 'isoft-fm-foundation' ); ?>
				</a>
			<?php elseif ( ! $can_access ) : ?>
				<span class="isoft-fmf-download-btn isoft-fmf-download-btn--restricted">
					<?php esc_html_e( 'Restricted', 'isoft-fm-foundation' ); ?>
				</span>
			<?php elseif ( $show_bundle_btn ) : ?>
				<?php
				$bundle_label = sprintf(
					/* translators: %s: total size of bundled files */
					__( 'Download all (%s)', 'isoft-fm-foundation' ),
					size_format( $bundle_size )
				);
				?>
				<a href="<?php echo esc_url( isoft_fmf_get_bundle_url( (int) $post->ID ) ); ?>"
					class="wp-element-button isoft-fmf-download-btn isoft-fmf-download-btn--bundle"
					title="<?php echo esc_attr( $bundle_label ); ?>"
					download
					rel="nofollow">
					<?php echo esc_html( $bundle_label ); ?>
				</a>
			<?php else : ?>
				<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"
					class="wp-element-button isoft-fmf-download-btn">
					<?php
					printf(
						/* translators: %d: file count */
						esc_html( _n( 'View %d file', 'View %d files', $file_count, 'isoft-fm-foundation' ) ),
						(int) $file_count
					);
					?>
				</a>
			<?php endif; ?>
		</div>

	</div>

</article>
		<?php
		return;
	endif;
	?>

	<?php
	foreach ( $files as $i => $file ) :
		$ext        = strtolower( pathinfo( $file->file_name ?? '', PATHINFO_EXTENSION ) );
		$icon_cls   = isoft_fmf_mime_icon_class( $ext );
		$title      = $file->title ?: $file->file_name ?: $file->external_url ?: get_the_title( $post->ID );
		$date       = $file->created_at ?? '';
		$hidden_id  = 'isoft-fmf-agree-content-' . (int) $file->id;
		$is_missing = ! empty( $file->is_missing );
		$item_class = 'isoft-fmf-file-item' . ( $is_missing ? ' isoft-fmf-file-item--missing' : '' );
		?>
	<div class="<?php echo esc_attr( $item_class ); ?>">

		<div class="isoft-fmf-file-item__icon isoft-fmf-icon--<?php echo esc_attr( $icon_cls ); ?>" aria-hidden="true">
			<?php echo esc_html( strtoupper( $ext ) ?: '?' ); ?>
		</div>

		<div class="isoft-fmf-file-item__info">
			<div class="isoft-fmf-file-item__title">
				<?php if ( $is_multi ) : ?>
					<?php echo esc_html( $title ); ?>
				<?php else : ?>
					<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post->ID ) ); ?></a>
					<?php if ( $is_hot ) : ?>
						<span class="isoft-fmf-badge isoft-fmf-badge--hot">HOT</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="isoft-fmf-file-item__meta">
				<span class="isoft-fmf-meta isoft-fmf-meta--type isoft-fmf-type--<?php echo esc_attr( $icon_cls ); ?>">
					<?php echo esc_html( strtoupper( $ext ) ?: '?' ); ?>
				</span>

				<?php if ( $settings['show_date'] && $date ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--date">
					<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					<?php echo esc_html( wp_date( $settings['date_format'] ?: get_option( 'date_format' ), strtotime( $date ) ) ); ?>
				</span>
				<?php endif; ?>

				<?php if ( $settings['show_file_size'] && $file->file_size ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--size">
					<span class="dashicons dashicons-media-archive" aria-hidden="true"></span>
					<?php echo esc_html( size_format( $file->file_size ) ); ?>
				</span>
				<?php endif; ?>

				<?php if ( $settings['show_download_count'] ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--count">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php echo esc_html( number_format_i18n( (int) $file->download_count ) ); ?>
				</span>
				<?php endif; ?>

				<?php isoft_fmf_render_card_lock_and_license( $access_role, $effective_license ); ?>
				<?php do_action( 'isoft_fmf_card_meta_extras', $post ); ?>
			</div>
		</div>

		<div class="isoft-fmf-file-item__action">
			<?php if ( $is_missing ) : ?>
				<span class="isoft-fmf-file-missing-label">
					<?php esc_html_e( 'Temporarily unavailable', 'isoft-fm-foundation' ); ?>
				</span>
			<?php elseif ( $can_access ) : ?>
				<?php if ( $require_agree ) : ?>
				<div id="<?php echo esc_attr( $hidden_id ); ?>" class="isoft-fmf-agree-content" hidden>
					<?php echo wp_kses_post( $agree_text ); ?>
				</div>
				<a href="<?php echo esc_url( isoft_fmf_get_download_url( (int) $file->id ) ); ?>"
					class="wp-element-button isoft-fmf-download-btn isoft-fmf-requires-agree"
					data-agree-content="#<?php echo esc_attr( $hidden_id ); ?>"
					data-agree-title="<?php echo $license ? esc_attr( $license->title ) : esc_attr( get_the_title( $post->ID ) ); ?>">
					<?php echo esc_html( $btn_text ); ?>
				</a>
					<?php
				else :
					// Local files get HTML5 `download` so click-interceptors back off
					// and the browser's download manager handles them. External
					// links are cross-origin from the user's POV (point at Drive,
					// Dropbox, etc.) — `download` is a no-op there, and admin
					// chooses whether they open in the same tab or a new one.
					$is_external_file = 'external' === $file->file_type;
					if ( $is_external_file ) {
						$ext_target = get_option( 'isoft_fmf_external_link_target', '_blank' );
						$ext_attrs  = '_blank' === $ext_target
							? ' target="_blank" rel="noopener nofollow"'
							: ' rel="nofollow"';
					} else {
						$ext_attrs = ' download rel="nofollow"';
					}
					?>
				<a href="<?php echo esc_url( isoft_fmf_get_download_url( (int) $file->id ) ); ?>"
					class="wp-element-button isoft-fmf-download-btn"<?php echo $ext_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static whitelisted attribute set. ?>>
					<?php echo esc_html( $btn_text ); ?>
				</a>
				<?php endif; ?>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( wp_login_url( get_permalink( $post->ID ) ) ); ?>"
					class="wp-element-button isoft-fmf-download-btn isoft-fmf-download-btn--login">
					<?php esc_html_e( 'Login', 'isoft-fm-foundation' ); ?>
				</a>
			<?php else : ?>
				<span class="isoft-fmf-download-btn isoft-fmf-download-btn--restricted">
					<?php esc_html_e( 'Restricted', 'isoft-fm-foundation' ); ?>
				</span>
			<?php endif; ?>
		</div>

	</div>
	<?php endforeach; ?>

	<?php if ( empty( $files ) ) : ?>
	<div class="isoft-fmf-file-item isoft-fmf-file-item--empty">
		<em><?php esc_html_e( 'No files available.', 'isoft-fm-foundation' ); ?></em>
	</div>
	<?php endif; ?>

</article>
