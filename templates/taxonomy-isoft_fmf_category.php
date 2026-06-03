<?php
/**
 * Taxonomy archive template for 'isoft_fmf_category'.
 * Theme-overridable: place a copy at {theme}/isoft-fm-foundation/taxonomy-isoft_fmf_category.php
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

get_header();

$settings    = isoft_fmf_get_settings();
$queried     = get_queried_object();
$child_terms = get_terms(
	array(
		'taxonomy'   => 'isoft_fmf_category',
		'parent'     => $queried->term_id,
		'hide_empty' => false,
	)
);
?>
<div class="isoft-fmf-category-archive">
	<header class="isoft-fmf-category-archive__header">
		<h1 class="page-title"><?php single_term_title(); ?></h1>
		<?php if ( $queried->description ) : ?>
		<div class="taxonomy-description"><?php echo wp_kses_post( term_description() ); ?></div>
		<?php endif; ?>
	</header>

	<?php if ( $child_terms && ! is_wp_error( $child_terms ) ) : ?>
	<div class="isoft-fmf-category-archive__subcategories">
		<h2><?php esc_html_e( 'Subcategories', 'isoft-fm-foundation' ); ?></h2>
		<?php
		$terms = $child_terms;
		require ISOFT_FMF_PLUGIN_DIR . 'public/views/category-listing.php';
		?>
	</div>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
	<div class="isoft-fmf-category-archive__downloads">
		<?php if ( $child_terms ) : ?>
		<h2><?php esc_html_e( 'Downloads in this Category', 'isoft-fm-foundation' ); ?></h2>
		<?php endif; ?>

		<div class="isoft-fmf-grid isoft-fmf-grid--cols-3">
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
	</div>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
