<?php
/**
 * Template: Single download detail — reuses the card view for each file,
 * then shows version info, author, changelog below.
 *
 * Expected variables: $post WP_Post, $files object[], $settings array
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$version     = get_post_meta( $post->ID, '_isoft_fmf_version', true );
$changelog   = get_post_meta( $post->ID, '_isoft_fmf_changelog', true );
$author_name = get_post_meta( $post->ID, '_isoft_fmf_author_name', true );
$author_url  = get_post_meta( $post->ID, '_isoft_fmf_author_url', true );
$date_pub    = get_post_meta( $post->ID, '_isoft_fmf_date_published', true );
$license_id  = (int) get_post_meta( $post->ID, '_isoft_fmf_license_id', true );
$license     = $license_id ? ( new ISOFT_FMF_License_Manager() )->get( $license_id ) : null;
?>
<div class="isoft-fmf-single-download">

	<?php
	// Render the card — same layout as list view, all files expanded.
	require __DIR__ . '/download-card.php';
	?>

	<?php if ( $version || $author_name || $date_pub || $license ) : ?>
	<div class="isoft-fmf-single-download__details">
		<?php if ( $version ) : ?>
		<p class="isoft-fmf-meta isoft-fmf-meta--version">
			<strong><?php esc_html_e( 'Version:', 'isoft-fm-foundation' ); ?></strong> <?php echo esc_html( $version ); ?>
		</p>
		<?php endif; ?>

		<?php if ( $date_pub ) : ?>
		<p class="isoft-fmf-meta isoft-fmf-meta--date">
			<strong><?php esc_html_e( 'Published:', 'isoft-fm-foundation' ); ?></strong>
			<?php echo esc_html( wp_date( $settings['date_format'], strtotime( $date_pub ) ) ); ?>
		</p>
		<?php endif; ?>

		<?php if ( $author_name ) : ?>
		<p class="isoft-fmf-meta isoft-fmf-meta--author">
			<strong><?php esc_html_e( 'Publisher:', 'isoft-fm-foundation' ); ?></strong>
			<?php if ( $author_url ) : ?>
				<a href="<?php echo esc_url( $author_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $author_name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $author_name ); ?>
			<?php endif; ?>
		</p>
		<?php endif; ?>

		<?php if ( $license ) : ?>
		<p class="isoft-fmf-meta isoft-fmf-meta--license">
			<strong><?php esc_html_e( 'License:', 'isoft-fm-foundation' ); ?></strong>
			<?php if ( $license->url ) : ?>
				<a href="<?php echo esc_url( $license->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $license->title ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $license->title ); ?>
			<?php endif; ?>
		</p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( $changelog ) : ?>
	<div class="isoft-fmf-single-download__changelog">
		<h4><?php esc_html_e( "What's New", 'isoft-fm-foundation' ); ?></h4>
		<?php echo wp_kses_post( wpautop( $changelog ) ); ?>
	</div>
	<?php endif; ?>

</div>
