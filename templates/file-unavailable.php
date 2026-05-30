<?php
/**
 * End-user page shown when a requested download file is missing from disk.
 *
 * Set by ISFM_File_Integrity::render_unavailable_page():
 *   $isfm_unavailable_post_id  int
 *   $isfm_unavailable_mode     'unpublished'|'partial'
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$post_id = isset( $isfm_unavailable_post_id ) ? (int) $isfm_unavailable_post_id : 0;
$mode    = isset( $isfm_unavailable_mode ) ? (string) $isfm_unavailable_mode : 'partial';

$post_title   = $post_id ? get_the_title( $post_id ) : '';
$admin_email  = (string) get_option( 'admin_email' );
$archive_url  = get_post_type_archive_link( 'isfm_file' ) ?: home_url( '/' );
$mail_subject = sprintf(
	/* translators: 1: post title, 2: post id */
	__( 'I-Soft File Manager: Foundation: file unavailable — %1$s (#%2$d)', 'isoft-fm-foundation' ),
	$post_title,
	$post_id
);
$mail_body = sprintf(
	/* translators: %s: post title */
	__( "Hello,\n\nI was trying to download a file from \"%s\" but it appears to be temporarily unavailable. I wanted to let you know so it can be restored.\n\nThank you!", 'isoft-fm-foundation' ),
	$post_title
);
$mailto = 'mailto:' . rawurlencode( $admin_email )
	. '?subject=' . rawurlencode( $mail_subject )
	. '&body=' . rawurlencode( $mail_body );

get_header();
?>
<main id="primary" class="site-main isfm-unavailable">
	<div class="isfm-unavailable__inner" style="max-width:640px;margin:3em auto;padding:2em;text-align:center;">
		<h1 style="font-size:1.6em;margin-bottom:.6em;">
			<?php esc_html_e( 'This file is temporarily unavailable', 'isoft-fm-foundation' ); ?>
		</h1>

		<p style="font-size:1.05em;line-height:1.6;color:#444;">
			<?php
			if ( 'unpublished' === $mode ) {
				esc_html_e( 'We\'re restoring this download. The site administrator has been notified and is looking into it — please check back later.', 'isoft-fm-foundation' );
			} else {
				esc_html_e( 'This particular file is temporarily unavailable while we restore it. Other files on this page may still work.', 'isoft-fm-foundation' );
			}
			?>
		</p>

		<?php if ( $post_title ) : ?>
		<p style="color:#666;">
			<?php
			printf(
				/* translators: %s: download title */
				esc_html__( 'Download: %s', 'isoft-fm-foundation' ),
				'<strong>' . esc_html( $post_title ) . '</strong>'
			);
			?>
		</p>
		<?php endif; ?>

		<p style="margin-top:1.8em;">
			<a href="<?php echo esc_url( $mailto ); ?>"
				class="wp-element-button"
				style="margin-right:.6em;">
				<?php esc_html_e( 'Contact site administrator', 'isoft-fm-foundation' ); ?>
			</a>
			<a href="<?php echo esc_url( $archive_url ); ?>" class="wp-element-button is-style-outline">
				<?php esc_html_e( 'Back to downloads', 'isoft-fm-foundation' ); ?>
			</a>
		</p>

		<p style="margin-top:1.5em;font-size:.9em;color:#888;">
			<?php esc_html_e( 'Letting us know helps us fix it faster. Thanks for your patience.', 'isoft-fm-foundation' ); ?>
		</p>
	</div>
</main>
<?php
get_footer();
