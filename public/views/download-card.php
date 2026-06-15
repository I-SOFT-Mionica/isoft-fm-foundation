<?php
/**
 * Template: Download card — JDownloads-style compact layout.
 *
 * Each file gets its own row: icon · title · HOT badge · date · size · count · button.
 *
 * Expected variables:
 *   $post     WP_Post  The isoft_fmf_file post.
 *   $settings array    Plugin settings from isoft_fmf_get_settings().
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$access        = new ISOFT_FMF_Access_Control();
$can_access    = $access->can_access_download( $post->ID );
$files         = ( new ISOFT_FMF_File_Manager() )->get_files( $post->ID );
$require_agree = (bool) get_post_meta( $post->ID, '_isoft_fmf_require_agree', true );
$access_role   = get_post_meta( $post->ID, '_isoft_fmf_access_role', true ) ?: 'public';
// HOT = set by nightly cron at 01:00 (top 10 downloads last 7 days), stored in post meta.
$is_hot     = (bool) get_post_meta( $post->ID, '_isoft_fmf_is_hot', true );
$license_id = (int) get_post_meta( $post->ID, '_isoft_fmf_license_id', true );
$license    = ( $require_agree && $license_id ) ? ( new ISOFT_FMF_License_Manager() )->get( $license_id ) : null;
$agree_text = $license ? wp_kses_post( $license->full_text ) : wp_kses_post( (string) get_post_meta( $post->ID, '_isoft_fmf_agree_text', true ) );
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
$bundle_size      = $show_bundle_btn ? array_sum( array_column( $bundleable_files, 'file_size' ) ) : 0;

?>
<article class="isoft-fmf-download-card" id="isoft-fmf-download-<?php echo esc_attr( $post->ID ); ?>">

	<?php if ( count( $files ) > 1 ) : ?>
	<h3 class="isoft-fmf-download-card__title">
		<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post->ID ) ); ?></a>
		<?php if ( $is_hot ) : ?>
			<span class="isoft-fmf-badge isoft-fmf-badge--hot">HOT</span>
		<?php endif; ?>
	</h3>
	<?php endif; ?>

	<?php if ( $show_bundle_btn ) : ?>
	<div class="isoft-fmf-bundle-btn-wrap">
		<a href="<?php echo esc_url( isoft_fmf_get_bundle_url( (int) $post->ID ) ); ?>"
			class="wp-element-button isoft-fmf-bundle-btn">
			<span class="dashicons dashicons-download" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: 1: number of files, 2: total size of those files */
				esc_html__( 'Download all (%1$d files, %2$s) as ZIP', 'isoft-fm-foundation' ),
				count( $bundleable_files ),
				esc_html( size_format( $bundle_size ) )
			);
			?>
		</a>
	</div>
	<?php endif; ?>

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
				<?php if ( count( $files ) === 1 ) : ?>
					<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post->ID ) ); ?></a>
					<?php if ( $is_hot ) : ?>
						<span class="isoft-fmf-badge isoft-fmf-badge--hot">HOT</span>
					<?php endif; ?>
				<?php else : ?>
					<?php echo esc_html( $title ); ?>
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

				<?php if ( 'public' !== $access_role ) : ?>
				<span class="isoft-fmf-meta isoft-fmf-meta--lock">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				</span>
				<?php endif; ?>
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
				<?php else : ?>
				<a href="<?php echo esc_url( isoft_fmf_get_download_url( (int) $file->id ) ); ?>"
					class="wp-element-button isoft-fmf-download-btn">
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
