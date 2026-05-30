<?php
/**
 * Single download template — classic theme fallback.
 *
 * FSE (block) themes use the block template registered via register_block_template().
 * This file is used only by classic themes. The download content (files, metadata)
 * is injected via the_content filter in ISFM_Post_Type::append_download_content().
 *
 * Theme-overridable: place a copy at {theme}/isoft-fm-foundation/single-isfm_file.php
 */
defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="isfm-single-wrap" style="max-width:var(--wp--style--global--content-size,860px);margin:2em auto;padding:0 1em">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'isfm-single' ); ?>>
			<header class="isfm-single__header">
				<h1 class="entry-title"><?php the_title(); ?></h1>
			</header>
			<div class="entry-content">
				<?php the_content(); ?>
			</div>
		</article>
	<?php endwhile; ?>
</div>
<?php get_footer(); ?>
