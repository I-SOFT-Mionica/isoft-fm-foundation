<?php
/**
 * Server-side render for isoft-fm-foundation/download-list block.
 *
 * Available variables (injected by WordPress):
 *   $attributes  array   Block attributes.
 *   $content     string  Inner blocks content (unused — dynamic block).
 *   $block       WP_Block
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$atts = array(
	'category'              => absint( $attributes['category'] ?? 0 ) ?: '',
	'include_subcategories' => array_key_exists( 'includeSubcategories', $attributes )
		? ( ! empty( $attributes['includeSubcategories'] ) ? '1' : '0' )
		: '1',
	'tag'                   => absint( $attributes['tag'] ?? 0 ) ?: '',
	'limit'                 => absint( $attributes['limit'] ?? 10 ),
	'orderby'               => sanitize_key( $attributes['orderby'] ?? 'date' ),
	'order'                 => sanitize_key( $attributes['order'] ?? 'DESC' ),
	'layout'                => sanitize_key( $attributes['layout'] ?? '' ),
	'show_search'           => ! empty( $attributes['showSearch'] ) ? '1' : '0',
);

// Reuse shortcode output — single source of truth.
// HTML is built by our own shortcode handler (each variable is escaped at the
// template level); wp_kses() here is the explicit boundary escape the WP.org
// reviewers expect at block-callback level.
echo wp_kses( do_shortcode( '[isfm_list' . isfm_atts_to_string( $atts ) . ']' ), isfm_allowed_html() );
