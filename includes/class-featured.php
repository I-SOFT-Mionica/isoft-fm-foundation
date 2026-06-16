<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pins downloads flagged with `_isoft_fmf_featured = 1` to the top of
 * every isoft_fmf_file query (archive pages, taxonomy archives, shortcode
 * + block listings) by prepending a featured-DESC sort to the ORDER BY
 * clause via posts_clauses.
 *
 * Always-on by default. Opt out globally via:
 *
 *     add_filter( 'isoft_fmf_featured_first_enabled', '__return_false' );
 *
 * Or per-query by setting query var `isoft_fmf_featured_first` to false / "0".
 */
class ISOFT_FMF_Featured {

	public function register_hooks(): void {
		add_filter( 'posts_clauses', array( $this, 'prepend_featured_sort' ), 10, 2 );
	}

	public function prepend_featured_sort( array $clauses, WP_Query $query ): array {
		if ( ! $this->should_apply( $query ) ) {
			return $clauses;
		}

		global $wpdb;

		// LEFT JOIN so non-featured posts (no meta row) still appear and
		// sort as 0. Aliased uniquely to avoid colliding with anything WP
		// or another plugin's posts_clauses callback added.
		$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS isoft_fmf_featured_meta"
			. " ON ({$wpdb->posts}.ID = isoft_fmf_featured_meta.post_id"
			. " AND isoft_fmf_featured_meta.meta_key = '_isoft_fmf_featured')";

		$prefix = 'COALESCE(isoft_fmf_featured_meta.meta_value+0, 0) DESC';
		if ( ! empty( $clauses['orderby'] ) ) {
			$clauses['orderby'] = $prefix . ', ' . $clauses['orderby'];
		} else {
			$clauses['orderby'] = $prefix;
		}

		return $clauses;
	}

	private function should_apply( WP_Query $query ): bool {
		$post_type    = $query->get( 'post_type' );
		$is_isoft_fmf = 'isoft_fmf_file' === $post_type
			|| ( is_array( $post_type ) && in_array( 'isoft_fmf_file', $post_type, true ) )
			|| $query->is_tax( array( 'isoft_fmf_category', 'isoft_fmf_tag' ) )
			|| $query->is_post_type_archive( 'isoft_fmf_file' );

		if ( ! $is_isoft_fmf ) {
			return false;
		}

		// Don't break random / explicit-none ordering — featured-first would
		// be visible-but-jarring when the rest is rand().
		$orderby = $query->get( 'orderby' );
		if ( 'rand' === $orderby || 'none' === $orderby ) {
			return false;
		}

		// Per-query opt-out (block / shortcode can set this).
		$opt_out = $query->get( 'isoft_fmf_featured_first' );
		if ( false === $opt_out || '0' === (string) $opt_out ) {
			return false;
		}

		// Site-wide opt-out filter.
		return (bool) apply_filters( 'isoft_fmf_featured_first_enabled', true, $query );
	}
}
