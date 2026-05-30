<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Access_Control {

	/** Role hierarchy from lowest to highest. */
	private const HIERARCHY = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

	/** Stashed between pre_get_posts and posts_clauses for the active query. */
	private array $current_accessible = array();

	public function register_hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'filter_frontend_queries' ) );
	}

	/**
	 * Check if the current (or given) user may access a download.
	 */
	public function can_access_download( int $download_id, int $user_id = 0 ): bool {
		$required = get_post_meta( $download_id, '_isfm_access_role', true )
			?: get_option( 'isfm_default_access_role', 'public' );
		$allowed  = $this->user_meets_role( $required, $user_id );

		return (bool) apply_filters( 'isfm_access_check', $allowed, $download_id, $user_id );
	}

	/**
	 * Check whether a user meets a minimum role requirement.
	 */
	public function user_meets_role( string $required_role, int $user_id = 0 ): bool {
		if ( 'public' === $required_role ) {
			return true;
		}

		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$required_index = array_search( $required_role, self::HIERARCHY, true );
		if ( false === $required_index ) {
			return false;
		}

		foreach ( $user->roles as $role ) {
			$role_index = array_search( $role, self::HIERARCHY, true );
			if ( false !== $role_index && $role_index >= $required_index ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the list of _isfm_access_role values the user qualifies for.
	 *
	 * Anonymous  → ['public']
	 * Subscriber → ['public','subscriber']
	 * Editor     → ['public','subscriber','contributor','author','editor']
	 * Admin      → all values (caller should skip filtering entirely)
	 */
	public function get_accessible_role_values( int $user_id = 0 ): array {
		$accessible = array( 'public' );

		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return $accessible;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $accessible;
		}

		$max_index = -1;
		foreach ( $user->roles as $role ) {
			$index = array_search( $role, self::HIERARCHY, true );
			if ( false !== $index && $index > $max_index ) {
				$max_index = $index;
			}
		}

		if ( $max_index >= 0 ) {
			$accessible = array_merge( $accessible, array_slice( self::HIERARCHY, 0, $max_index + 1 ) );
		}

		return $accessible;
	}

	// -------------------------------------------------------------------------
	// Query-level frontend filtering
	// -------------------------------------------------------------------------

	/**
	 * Filter all frontend isfm_file queries so restricted downloads are excluded
	 * from listings, archives, search results, and shortcode output.
	 */
	public function filter_frontend_queries( WP_Query $query ): void {
		if ( is_admin() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'isfm_file' !== $post_type ) {
			if ( ! is_array( $post_type ) || ! in_array( 'isfm_file', $post_type, true ) ) {
				if ( ! $query->is_tax( array( 'isfm_category', 'isfm_tag' ) ) && ! $query->is_post_type_archive( 'isfm_file' ) ) {
					return;
				}
			}
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->current_accessible = $this->get_accessible_role_values();
		add_filter( 'posts_clauses', array( $this, 'add_access_clauses' ), 10, 2 );
	}

	/**
	 * SQL-level access filter: LEFT JOIN on _isfm_access_role postmeta and
	 * restrict to rows whose value is in the user's accessible set.
	 *
	 * Downloads without the meta key (pre-v0.5.1) inherit the global default.
	 */
	public function add_access_clauses( array $clauses, WP_Query $query ): array {
		$post_type    = $query->get( 'post_type' );
		$is_isfm_file = 'isfm_file' === $post_type
			|| ( is_array( $post_type ) && in_array( 'isfm_file', $post_type, true ) )
			|| $query->is_tax( array( 'isfm_category', 'isfm_tag' ) )
			|| $query->is_post_type_archive( 'isfm_file' );

		if ( ! $is_isfm_file ) {
			return $clauses;
		}

		remove_filter( 'posts_clauses', array( $this, 'add_access_clauses' ), 10 );

		global $wpdb;

		$accessible = $this->current_accessible;

		$clauses['join'] .= $wpdb->prepare(
			' LEFT JOIN %i AS isfm_ar ON (%i.ID = isfm_ar.post_id AND isfm_ar.meta_key = %s)',
			$wpdb->postmeta,
			$wpdb->posts,
			'_isfm_access_role'
		);

		// Build the IN clause with proper escaping.
		$in_placeholders = implode( ',', array_fill( 0, count( $accessible ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder string is generated from count(), not user input.
		$in_clause = $wpdb->prepare( $in_placeholders, ...$accessible );

		// Downloads with NULL or empty meta inherit the global default.
		$default_role       = get_option( 'isfm_default_access_role', 'public' );
		$default_accessible = in_array( $default_role, $accessible, true );
		$null_branch        = $default_accessible
			? ' OR isfm_ar.meta_value IS NULL OR isfm_ar.meta_value = \'\''
			: '';

		$clauses['where'] .= " AND (isfm_ar.meta_value IN ({$in_clause}){$null_branch})";

		return $clauses;
	}
}
