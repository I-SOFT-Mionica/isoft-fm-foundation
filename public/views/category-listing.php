<?php
/**
 * Template: Category grid/list — Phase 2.
 *
 * Expected variables: $terms WP_Term[], $settings array
 */
defined( 'ABSPATH' ) || exit;
?>
<?php if ( empty( $terms ) ) : ?>
	<p class="isfm-no-categories"><?php esc_html_e( 'No categories found.', 'isoft-fm-foundation' ); ?></p>
<?php else : ?>
	<div class="isfm-category-grid isfm-grid isfm-grid--cols-3">
		<?php foreach ( $terms as $term ) : ?>
		<div class="isfm-category-card">
			<?php $icon = get_term_meta( $term->term_id, '_isfm_cat_icon', true ); ?>
			<?php if ( $icon ) : ?>
			<div class="isfm-category-card__icon">
				<?php if ( filter_var( $icon, FILTER_VALIDATE_URL ) ) : ?>
					<img src="<?php echo esc_url( $icon ); ?>" alt="" />
				<?php else : ?>
					<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<h3 class="isfm-category-card__title">
				<a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
			</h3>

			<?php if ( $term->description ) : ?>
			<div class="isfm-category-card__desc"><?php echo esc_html( wp_trim_words( $term->description, 20 ) ); ?></div>
			<?php endif; ?>

			<div class="isfm-category-card__meta">
				<span class="isfm-meta">
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
