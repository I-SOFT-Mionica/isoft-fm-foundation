<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Access_Control {

	/** Role hierarchy from lowest to highest. */
	private const HIERARCHY = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

	/** Post meta where the effective (already-resolved) role is cached. */
	public const EFFECTIVE_META_KEY = '_isoft_fmf_effective_access_role';

	/** Stashed between pre_get_posts and posts_clauses for the active query. */
	private array $current_accessible = array();

	public function register_hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'filter_frontend_queries' ) );

		// Keep the denormalised effective role in sync with the three things
		// that determine it: the per-download role meta, the download's
		// category assignment, and the category's own role meta.
		add_action( 'save_post_isoft_fmf_file', array( $this, 'on_download_saved' ), 20, 1 );
		add_action( 'set_object_terms', array( $this, 'on_terms_set' ), 20, 4 );
		add_action( 'edited_isoft_fmf_category', array( $this, 'on_category_edited' ), 20, 1 );
	}

	/**
	 * Check if the current (or given) user may access a download.
	 *
	 * Reads the denormalised effective-role meta first (kept in sync by
	 * on_download_saved / on_terms_set / on_category_edited). Falls back to
	 * computing on the fly when the cache is missing, e.g. for pre-0.10.0
	 * data that hasn't been re-saved since the upgrade.
	 */
	public function can_access_download( int $download_id, int $user_id = 0 ): bool {
		$required = $this->effective_role_for( $download_id );
		$allowed  = $this->user_meets_role( $required, $user_id );

		return (bool) apply_filters( 'isoft_fmf_access_check', $allowed, $download_id, $user_id );
	}

	/**
	 * Cached lookup of a download's effective access role. Use this for read
	 * paths; write paths should call recompute_effective_role() then read.
	 */
	public function effective_role_for( int $download_id ): string {
		$cached = get_post_meta( $download_id, self::EFFECTIVE_META_KEY, true );
		if ( '' !== $cached ) {
			return (string) $cached;
		}
		// Backfill on-demand for posts that pre-date the migration.
		return $this->recompute_effective_role( $download_id );
	}

	/**
	 * Resolve, persist, and return the effective role for a single download.
	 *
	 * Resolution order:
	 *   1. The download's literal _isoft_fmf_access_role meta, when it's a
	 *      concrete role (not 'inherit' and not empty).
	 *   2. The most-restrictive _isoft_fmf_cat_access_role across every
	 *      isoft_fmf_category term the download is assigned to (most
	 *      restrictive = highest index in HIERARCHY).
	 *   3. The site-wide isoft_fmf_default_access_role option.
	 *
	 * Empty category roles ('' = "no category default") are skipped during
	 * step 2 — a category that opts out of the cascade doesn't pull other
	 * categories' roles down with it.
	 */
	public function recompute_effective_role( int $download_id ): string {
		$literal = (string) get_post_meta( $download_id, '_isoft_fmf_access_role', true );
		if ( '' !== $literal && 'inherit' !== $literal ) {
			update_post_meta( $download_id, self::EFFECTIVE_META_KEY, $literal );
			return $literal;
		}

		$terms = wp_get_object_terms( $download_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$most_restrictive = null;
		$max_index        = -1;
		foreach ( $terms as $term_id ) {
			$cat_role = (string) get_term_meta( (int) $term_id, '_isoft_fmf_cat_access_role', true );
			if ( '' === $cat_role ) {
				continue;
			}
			if ( 'public' === $cat_role ) {
				$index = 0;
			} else {
				$index = array_search( $cat_role, self::HIERARCHY, true );
				if ( false === $index ) {
					continue;
				}
				$index = (int) $index + 1; // shift past 'public'
			}
			if ( $index > $max_index ) {
				$max_index        = $index;
				$most_restrictive = $cat_role;
			}
		}

		$resolved = $most_restrictive ?? (string) get_option( 'isoft_fmf_default_access_role', 'public' );
		if ( '' === $resolved ) {
			$resolved = 'public';
		}

		update_post_meta( $download_id, self::EFFECTIVE_META_KEY, $resolved );
		return $resolved;
	}

	// -------------------------------------------------------------------------
	// Effective-role denormalisation hooks
	// -------------------------------------------------------------------------

	public function on_download_saved( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->recompute_effective_role( $post_id );
	}

	public function on_terms_set( int $object_id, $terms, $tt_ids, string $taxonomy ): void {
		unset( $terms, $tt_ids );
		if ( 'isoft_fmf_category' !== $taxonomy ) {
			return;
		}
		if ( 'isoft_fmf_file' !== get_post_type( $object_id ) ) {
			return;
		}
		$this->recompute_effective_role( $object_id );
	}

	public function on_category_edited( int $term_id ): void {
		// A category role change fan-outs to every download in that
		// category. Bounded by the term's post count — acceptable for
		// a one-shot admin save.
		$post_ids = get_posts(
			array(
				'post_type'      => 'isoft_fmf_file',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One-shot admin action triggered by category edit; bounded by term post count.
				'tax_query'      => array(
					array(
						'taxonomy' => 'isoft_fmf_category',
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);
		foreach ( $post_ids as $post_id ) {
			$this->recompute_effective_role( (int) $post_id );
		}
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
	 * Return the list of _isoft_fmf_access_role values the user qualifies for.
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
	 * Filter all frontend isoft_fmf_file queries so restricted downloads are excluded
	 * from listings, archives, search results, and shortcode output.
	 */
	public function filter_frontend_queries( WP_Query $query ): void {
		if ( is_admin() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'isoft_fmf_file' !== $post_type ) {
			if ( ! is_array( $post_type ) || ! in_array( 'isoft_fmf_file', $post_type, true ) ) {
				if ( ! $query->is_tax( array( 'isoft_fmf_category', 'isoft_fmf_tag' ) ) && ! $query->is_post_type_archive( 'isoft_fmf_file' ) ) {
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
	 * SQL-level access filter: LEFT JOIN on the effective-role postmeta and
	 * restrict to rows whose value is in the user's accessible set.
	 *
	 * Joins the denormalised `_isoft_fmf_effective_access_role` cache (kept
	 * fresh by save/term/category hooks plus the 0.10.0 backfill migration),
	 * not the literal `_isoft_fmf_access_role`. That keeps the SQL flat —
	 * no term-relationships JOIN, no cat-role lookup — even when a download
	 * has its per-download role set to 'inherit'.
	 *
	 * Posts with no effective-role meta yet (very old data, or a brand-new
	 * post the hook hasn't run on) fall through the NULL branch below and
	 * are evaluated against the global default.
	 */
	public function add_access_clauses( array $clauses, WP_Query $query ): array {
		$post_type         = $query->get( 'post_type' );
		$is_isoft_fmf_file = 'isoft_fmf_file' === $post_type
			|| ( is_array( $post_type ) && in_array( 'isoft_fmf_file', $post_type, true ) )
			|| $query->is_tax( array( 'isoft_fmf_category', 'isoft_fmf_tag' ) )
			|| $query->is_post_type_archive( 'isoft_fmf_file' );

		if ( ! $is_isoft_fmf_file ) {
			return $clauses;
		}

		remove_filter( 'posts_clauses', array( $this, 'add_access_clauses' ), 10 );

		global $wpdb;

		$accessible = $this->current_accessible;

		$clauses['join'] .= $wpdb->prepare(
			' LEFT JOIN %i AS isoft_fmf_ar ON (%i.ID = isoft_fmf_ar.post_id AND isoft_fmf_ar.meta_key = %s)',
			$wpdb->postmeta,
			$wpdb->posts,
			self::EFFECTIVE_META_KEY
		);

		// Build the IN clause with proper escaping.
		$in_placeholders = implode( ',', array_fill( 0, count( $accessible ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder string is generated from count(), not user input.
		$in_clause = $wpdb->prepare( $in_placeholders, ...$accessible );

		// Downloads with NULL or empty meta inherit the global default.
		$default_role       = get_option( 'isoft_fmf_default_access_role', 'public' );
		$default_accessible = in_array( $default_role, $accessible, true );
		$null_branch        = $default_accessible
			? ' OR isoft_fmf_ar.meta_value IS NULL OR isoft_fmf_ar.meta_value = \'\''
			: '';

		$clauses['where'] .= " AND (isoft_fmf_ar.meta_value IN ({$in_clause}){$null_branch})";

		return $clauses;
	}
}
