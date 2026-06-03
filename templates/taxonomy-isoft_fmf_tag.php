<?php
/**
 * Tag archive template for 'isoft_fmf_tag'.
 * Theme-overridable: place a copy at {theme}/isoft-fm-foundation/taxonomy-isoft_fmf_tag.php
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

get_header();

$settings = isoft_fmf_get_settings();
$tag      = get_queried_object();
?>
<div class="isoft-fmf-tag-archive">
	<header class="isoft-fmf-tag-archive__header">
		<h1 class="page-title">
			<?php
			printf(
				/* translators: %s: tag name */
				esc_html__( 'Downloads tagged: %s', 'isoft-fm-foundation' ),
				'<span>' . esc_html( single_term_title( '', false ) ) . '</span>'
			);
			?>
		</h1>
		<?php if ( $tag->description ) : ?>
		<div class="taxonomy-description"><?php echo wp_kses_post( term_description() ); ?></div>
		<?php endif; ?>
	</header>

	<?php if ( have_posts() ) : ?>
	<div class="isoft-fmf-tag-archive__downloads isoft-fmf-grid isoft-fmf-grid--cols-3">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<?php
			$post = get_post();
			require ISOFT_FMF_PLUGIN_DIR . 'public/views/download-card.php';
			?>
		<?php endwhile; ?>
	</div>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
	<p><?php esc_html_e( 'No downloads found with this tag.', 'isoft-fm-foundation' ); ?></p>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
