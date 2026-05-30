<?php
/**
 * Archive template for the 'isfm_file' post type.
 * Theme-overridable: place a copy at {theme}/isoft-fm-foundation/archive-isfm_file.php
 */
defined( 'ABSPATH' ) || exit;

get_header();

$settings = isfm_get_settings();
?>
<div class="isfm-archive">
	<?php if ( have_posts() ) : ?>
		<header class="isfm-archive__header">
			<h1 class="page-title"><?php esc_html_e( 'Downloads', 'isoft-fm-foundation' ); ?></h1>
		</header>

		<div class="isfm-archive__content isfm-grid isfm-grid--cols-3">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<?php
				$post = get_post();
				require ISFM_PLUGIN_DIR . 'public/views/download-card.php';
				?>
			<?php endwhile; ?>
		</div>

		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<p><?php esc_html_e( 'No downloads available.', 'isoft-fm-foundation' ); ?></p>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
