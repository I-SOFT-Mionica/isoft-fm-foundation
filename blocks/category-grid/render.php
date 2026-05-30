<?php
/**
 * Server-side render for isoft-fm-foundation/category-grid block.
 */
defined( 'ABSPATH' ) || exit;

$atts = array(
	'parent'           => absint( $attributes['parent'] ?? 0 ),
	'columns'          => absint( $attributes['columns'] ?? 3 ),
	'show_count'       => ! empty( $attributes['showCount'] ) ? '1' : '0',
	'show_description' => ! empty( $attributes['showDescription'] ) ? '1' : '0',
);

echo wp_kses( do_shortcode( '[isfm_categories' . isfm_atts_to_string( $atts ) . ']' ), isfm_allowed_html() );
