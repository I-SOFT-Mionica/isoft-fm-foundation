<?php
/**
 * Makes isoft_fmf_content_changed the one hook a static build, page cache or
 * CDN needs. Foundation's own writes (files, licenses, pending state) already
 * announce themselves; this adds the WordPress-level writes: downloads saved,
 * trashed, restored or deleted, categories and tags created, edited or
 * deleted (including their Foundation term meta), and category or tag
 * assignments.
 *
 * Events can repeat within one request (saving a download also sets its
 * terms); listeners should be idempotent. Bulk work can still be wrapped in
 * isoft_fmf_suspend_content_changed() / isoft_fmf_resume_content_changed().
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Change_Announcer {

	private const TAXONOMIES = array(
		'isoft_fmf_category' => 'category',
		'isoft_fmf_tag'      => 'tag',
	);

	public function register_hooks(): void {
		add_action( 'save_post_isoft_fmf_file', array( $this, 'on_download_saved' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'on_post_deleted' ), 10, 2 );
		add_action( 'set_object_terms', array( $this, 'on_terms_assigned' ), 20, 4 );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( $this, 'on_term_changed' ), 20, 3 );
		}
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'on_term_meta_changed' ), 20, 3 );
		}
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_download_saved( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === ( $post->post_status ?? '' ) ) {
			return;
		}
		isoft_fmf_content_changed( 'download', (int) $post_id );
	}

	/**
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post (WordPress 5.5+).
	 */
	public function on_post_deleted( $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && 'isoft_fmf_file' === $post->post_type ) {
			isoft_fmf_content_changed( 'download', (int) $post_id );
		}
	}

	/**
	 * @param int    $object_id Object ID.
	 * @param array  $terms     Terms.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 */
	public function on_terms_assigned( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( isset( self::TAXONOMIES[ $taxonomy ] ) && 'isoft_fmf_file' === get_post_type( (int) $object_id ) ) {
			isoft_fmf_content_changed( 'download', (int) $object_id );
		}
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_changed( $term_id, $tt_id, $taxonomy ): void {
		if ( isset( self::TAXONOMIES[ $taxonomy ] ) ) {
			isoft_fmf_content_changed( self::TAXONOMIES[ $taxonomy ], (int) $term_id );
		}
	}

	/**
	 * Foundation's category settings (icon, order, access role) live in term
	 * meta and change without edited_term. The pending flag announces itself.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $term_id   Term ID.
	 * @param string    $meta_key  Meta key.
	 */
	public function on_term_meta_changed( $meta_ids, $term_id, $meta_key ): void {
		if ( ! is_string( $meta_key ) || ! str_starts_with( $meta_key, '_isoft_fmf_' ) || ISOFT_FMF_Category_Pending::META === $meta_key ) {
			return;
		}
		$term = get_term( (int) $term_id );
		if ( $term instanceof WP_Term && isset( self::TAXONOMIES[ $term->taxonomy ] ) ) {
			isoft_fmf_content_changed( self::TAXONOMIES[ $term->taxonomy ], (int) $term_id );
		}
	}
}
