<?php
/**
 * Server-side render for isoft-fm-foundation/category-grid block.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$atts = array(
	'parent'           => absint( $attributes['parent'] ?? 0 ),
	'columns'          => absint( $attributes['columns'] ?? 3 ),
	'show_count'       => ! empty( $attributes['showCount'] ) ? '1' : '0',
	'show_description' => ! empty( $attributes['showDescription'] ) ? '1' : '0',
);

echo wp_kses( do_shortcode( '[isoft_fmf_categories' . isoft_fmf_atts_to_string( $atts ) . ']' ), isoft_fmf_allowed_html() );
