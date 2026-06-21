<?php
/**
 * License inheritance resolver for downloads.
 *
 * A download's effective license is the first non-zero value across:
 *
 *   1. The download's literal _isoft_fmf_license_id meta — unless the literal
 *      is the INHERIT sentinel (-1), in which case skip to step 2.
 *   2. The first non-zero _isoft_fmf_cat_license_id across the categories the
 *      download is assigned to, walked in category-assignment order. License
 *      isn't a gradient (CC BY isn't "more restrictive" than CC0), so the
 *      most-restrictive logic used for access roles doesn't apply — first-found
 *      is deterministic and admins can reorder assignments to control which
 *      wins for downloads in multiple license-bearing categories.
 *   3. No license (0) if neither step yielded one.
 *
 * Resolved value is cached in `_isoft_fmf_effective_license_id` post meta so
 * read paths stay flat. Cache is busted on:
 *   - download save (literal license meta changes)
 *   - category-term reassignment for a download
 *   - category edit (cat license meta changes — busts every download in that
 *     category since all of them potentially inherited)
 *
 * Mirrors [[ISOFT_FMF_Access_Control]] for access roles.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_License_Resolver {

	/** Sentinel value on _isoft_fmf_license_id meaning "inherit from category". */
	public const INHERIT = -1;

	/** Post meta where the effective (already-resolved) license id is cached. */
	public const EFFECTIVE_META_KEY = '_isoft_fmf_effective_license_id';

	public function register_hooks(): void {
		add_action( 'save_post_isoft_fmf_file', array( $this, 'on_download_saved' ), 20, 1 );
		add_action( 'set_object_terms', array( $this, 'on_terms_set' ), 20, 4 );
		// Category-edit cache-bust is fired directly from class-taxonomy.php's
		// save_term_fields(), not via a generic hook, because we want to scope
		// the bust to a single term (avoid scanning every download on any
		// unrelated category edit).
	}

	/**
	 * Cached lookup of a download's effective license id. `0` means
	 * "no license assigned, even after inheritance." Returns int.
	 */
	public function effective_license_for( int $download_id ): int {
		$cached = get_post_meta( $download_id, self::EFFECTIVE_META_KEY, true );
		if ( '' !== $cached ) {
			return (int) $cached;
		}
		return $this->recompute_effective_license( $download_id );
	}

	/**
	 * Same as effective_license_for() but returns the hydrated license row
	 * (with title, url, full_text, etc.) instead of just the id. Returns
	 * null when there's no effective license.
	 */
	public function effective_license_row_for( int $download_id ): ?object {
		$id = $this->effective_license_for( $download_id );
		if ( $id <= 0 ) {
			return null;
		}
		return ( new ISOFT_FMF_License_Manager() )->get( $id );
	}

	/**
	 * Resolve, persist, and return the effective license id for a single
	 * download. Public so write paths (admin save, term reassignment) can
	 * force a refresh.
	 */
	public function recompute_effective_license( int $download_id ): int {
		$literal = (int) get_post_meta( $download_id, '_isoft_fmf_license_id', true );

		// Concrete per-download license wins.
		if ( $literal > 0 ) {
			update_post_meta( $download_id, self::EFFECTIVE_META_KEY, $literal );
			return $literal;
		}

		// `0` (= no license set on the download) without explicit inherit
		// resolves to "no license." Only the INHERIT sentinel walks the
		// category cascade — keeps backwards-compatible behaviour for
		// existing downloads that have license_id = 0.
		if ( self::INHERIT !== $literal ) {
			update_post_meta( $download_id, self::EFFECTIVE_META_KEY, 0 );
			return 0;
		}

		$terms = wp_get_object_terms( $download_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		foreach ( $terms as $term_id ) {
			$cat_license = (int) get_term_meta( (int) $term_id, '_isoft_fmf_cat_license_id', true );
			if ( $cat_license > 0 ) {
				update_post_meta( $download_id, self::EFFECTIVE_META_KEY, $cat_license );
				return $cat_license;
			}
		}

		// Inherit-mode but no category supplies a license — falls through to
		// no license. Future v1.x could add a site-wide default license here;
		// for v1.0 we keep it tight to category → download.
		update_post_meta( $download_id, self::EFFECTIVE_META_KEY, 0 );
		return 0;
	}

	// ---------------------------------------------------------------------
	// Cache busters
	// ---------------------------------------------------------------------

	public function on_download_saved( int $download_id ): void {
		if ( wp_is_post_revision( $download_id ) ) {
			return;
		}
		$this->recompute_effective_license( $download_id );
	}

	/**
	 * @param int    $object_id
	 * @param array  $terms
	 * @param array  $tt_ids
	 * @param string $taxonomy
	 */
	public function on_terms_set( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
		if ( 'isoft_fmf_category' !== $taxonomy ) {
			return;
		}
		$this->recompute_effective_license( $object_id );
	}

	/**
	 * Called from class-taxonomy.php when a category's license meta is saved.
	 * Recompute every download in that category — any of them may have been
	 * inheriting and now resolves differently. Public + static so the
	 * taxonomy class can call without instantiating the resolver.
	 */
	public static function on_category_edited( int $term_id ): void {
		$download_ids = get_objects_in_term( $term_id, 'isoft_fmf_category' );
		if ( is_wp_error( $download_ids ) || empty( $download_ids ) ) {
			return;
		}
		$resolver = new self();
		foreach ( $download_ids as $download_id ) {
			$resolver->recompute_effective_license( (int) $download_id );
		}
	}
}
