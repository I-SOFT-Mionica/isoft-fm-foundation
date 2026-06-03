<?php
/**
 * Archive template for the 'isoft_fmf_file' post type.
 * Theme-overridable: place a copy at {theme}/isoft-fm-foundation/archive-isoft_fmf_file.php
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

get_header();

$settings = isoft_fmf_get_settings();
?>
<div class="isoft-fmf-archive">
	<?php if ( have_posts() ) : ?>
		<header class="isoft-fmf-archive__header">
			<h1 class="page-title"><?php esc_html_e( 'Downloads', 'isoft-fm-foundation' ); ?></h1>
		</header>

		<div class="isoft-fmf-archive__content isoft-fmf-grid isoft-fmf-grid--cols-3">
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
		<p><?php esc_html_e( 'No downloads available.', 'isoft-fm-foundation' ); ?></p>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
