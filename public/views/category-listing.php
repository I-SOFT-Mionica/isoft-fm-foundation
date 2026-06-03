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
			<?php $icon = get_term_meta( $term->term_id, '_isoft_fmf_cat_icon', true ); ?>
			<?php if ( $icon ) : ?>
			<div class="isoft-fmf-category-card__icon">
				<?php if ( filter_var( $icon, FILTER_VALIDATE_URL ) ) : ?>
					<img src="<?php echo esc_url( $icon ); ?>" alt="" />
				<?php else : ?>
					<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

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
