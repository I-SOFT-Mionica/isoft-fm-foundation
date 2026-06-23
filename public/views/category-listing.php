<?php
/**
 * Template: Category grid/list — Phase 2.
 *
 * Expected variables: $terms WP_Term[], $settings array
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.
?>
<?php if ( empty( $terms ) ) : ?>
	<p class="isoft-fmf-no-categories"><?php esc_html_e( 'No categories found.', 'isoft-fm-foundation' ); ?></p>
<?php else : ?>
	<div class="isoft-fmf-category-grid isoft-fmf-grid isoft-fmf-grid--cols-3">
		<?php foreach ( $terms as $term ) : ?>
		<div class="isoft-fmf-category-card">
			<div class="isoft-fmf-category-card__icon">
				<?php isoft_fmf_render_category_icon( (int) $term->term_id ); ?>
			</div>

			<h3 class="isoft-fmf-category-card__title">
				<a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
			</h3>

			<?php if ( $term->description ) : ?>
			<div class="isoft-fmf-category-card__desc"><?php echo esc_html( wp_trim_words( $term->description, 20 ) ); ?></div>
			<?php endif; ?>

			<div class="isoft-fmf-category-card__meta">
				<span class="isoft-fmf-meta">
					<?php
					printf(
						/* translators: %d: number of downloads in the category */
						esc_html( _n( '%d download', '%d downloads', $term->count, 'isoft-fm-foundation' ) ),
						(int) $term->count
					);
					?>
				</span>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
