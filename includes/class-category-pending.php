<?php
/**
 * Pending categories: a category that exists (its folder exists, files can
 * be filed in it) but is held back from the public until someone approves it.
 *
 * WordPress terms have no draft status, so the state is term meta
 * (_isoft_fmf_cat_pending = 1). A pending category, and everything beneath
 * it, is hidden from anyone who cannot manage categories:
 *
 *   - get_terms() results (category grids, shortcodes, blocks, widgets,
 *     the public REST API) via the get_terms_args filter;
 *   - the categories listed on a download (get_the_terms);
 *   - the category archive (404);
 *   - core sitemaps.
 *
 * Users who can manage categories, background jobs (cron, WP-CLI) and
 * callers that pass 'isoft_fmf_include_pending' => true see everything, so
 * integrity checks, folder lookups and importers keep working. Admin
 * category lists mark pending categories and offer an Approve action.
 *
 * Approving a category does not publish its downloads. Existing categories
 * have no flag and behave exactly as before.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Category_Pending {

	public const META      = '_isoft_fmf_cat_pending';
	private const TAXONOMY = 'isoft_fmf_category';
	private const ACTION   = 'isoft_fmf_approve_category';

	/** @var int[]|null Request cache of pending term IDs. */
	private static ?array $ids = null;

	/** @var int[]|null Request cache of pending term IDs plus all their descendants. */
	private static ?array $hidden = null;

	public function register_hooks(): void {
		add_filter( 'get_terms_args', array( $this, 'hide_in_term_queries' ), 20, 2 );
		add_filter( 'get_the_terms', array( $this, 'hide_on_downloads' ), 20, 3 );
		add_action( 'template_redirect', array( $this, 'block_archive' ), 5 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'hide_in_sitemaps' ), 20, 2 );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_filter( self::TAXONOMY . '_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_approve' ) );
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	public static function is_pending( int $term_id ): bool {
		return $term_id > 0 && (bool) get_term_meta( $term_id, self::META, true );
	}

	/**
	 * Set or clear the pending state. False when the term is not a Foundation category.
	 */
	public static function set( int $term_id, bool $pending ): bool {
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return false;
		}
		if ( self::is_pending( $term_id ) === $pending ) {
			return true;
		}

		if ( $pending ) {
			update_term_meta( $term_id, self::META, 1 );
		} else {
			delete_term_meta( $term_id, self::META );
		}
		self::$ids    = null;
		self::$hidden = null;

		/**
		 * Fires when a category enters or leaves the pending state.
		 *
		 * @param int  $term_id Category term ID.
		 * @param bool $pending True when it became pending, false when approved.
		 */
		do_action( 'isoft_fmf_category_pending_changed', $term_id, $pending );
		isoft_fmf_content_changed( 'category', $term_id );
		return true;
	}

	/**
	 * IDs of every pending category.
	 *
	 * @return int[]
	 */
	public static function ids(): array {
		if ( null === self::$ids ) {
			$found     = get_terms(
				array(
					'taxonomy'                  => self::TAXONOMY,
					'hide_empty'                => false,
					'fields'                    => 'ids',
					'meta_key'                  => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small set; the flag is only ever set on a few terms.
					'meta_value'                => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same.
					'isoft_fmf_include_pending' => true,
				)
			);
			self::$ids = is_wp_error( $found ) ? array() : array_map( 'intval', $found );
		}
		return self::$ids;
	}

	/**
	 * Pending categories and everything beneath them, as a plain ID list.
	 * Used with get_terms()' 'exclude'. Not 'exclude_tree': WordPress expands
	 * that with a nested get_terms() call, which would pass through this
	 * filter again and never end.
	 *
	 * @return int[]
	 */
	public static function hidden_ids(): array {
		if ( null === self::$hidden ) {
			$all = self::ids();
			foreach ( self::ids() as $id ) {
				$children = get_term_children( $id, self::TAXONOMY );
				if ( is_array( $children ) ) {
					$all = array_merge( $all, array_map( 'intval', $children ) );
				}
			}
			self::$hidden = array_values( array_unique( $all ) );
		}
		return self::$hidden;
	}

	/**
	 * Whether this category or any ancestor is pending.
	 */
	public static function is_hidden( int $term_id ): bool {
		$pending = self::ids();
		if ( ! $pending ) {
			return false;
		}
		$guard = 0;
		while ( $term_id > 0 && $guard++ < 50 ) {
			if ( in_array( $term_id, $pending, true ) ) {
				return true;
			}
			$term    = get_term( $term_id, self::TAXONOMY );
			$term_id = $term instanceof WP_Term ? (int) $term->parent : 0;
		}
		return false;
	}

	/**
	 * Whether the current request must not see pending categories. Managers
	 * and background jobs are exempt.
	 */
	private static function enforcing(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return ! current_user_can( 'isoft_fmf_manage_categories' );
	}

	// -------------------------------------------------------------------------
	// Public surfaces
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $args       get_terms() arguments.
	 * @param string[]            $taxonomies Taxonomies queried.
	 * @return array<string,mixed>
	 */
	public function hide_in_term_queries( array $args, array $taxonomies ): array {
		// Per-object lookups (wp_get_object_terms) are Foundation's own plumbing:
		// access roles, licenses, folders. Only the public display of a download's
		// categories is filtered, by hide_on_downloads().
		if ( ! in_array( self::TAXONOMY, $taxonomies, true ) || ! empty( $args['object_ids'] ) || ! empty( $args['isoft_fmf_include_pending'] ) || ! self::enforcing() ) {
			return $args;
		}
		$pending = self::ids();
		if ( ! $pending ) {
			return $args;
		}

		$hidden = self::hidden_ids();

		if ( ! empty( $args['include'] ) ) {
			// WordPress ignores 'exclude' next to 'include', so subtract here.
			$args['include'] = array_values( array_diff( wp_parse_id_list( $args['include'] ), $hidden ) );
			if ( ! $args['include'] ) {
				$args['include'] = array( 0 );
			}
			return $args;
		}

		$args['exclude'] = array_values( array_unique( array_merge( wp_parse_id_list( $args['exclude'] ?? array() ), $hidden ) ) );
		return $args;
	}

	/**
	 * @param WP_Term[]|false|WP_Error $terms    Terms of the object.
	 * @param int                      $post_id  Object ID.
	 * @param string                   $taxonomy Taxonomy.
	 * @return WP_Term[]|false|WP_Error
	 */
	public function hide_on_downloads( $terms, $post_id, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy || ! is_array( $terms ) || ! self::enforcing() || ! self::ids() ) {
			return $terms;
		}
		return array_values(
			array_filter(
				$terms,
				static fn( $term ): bool => ! ( $term instanceof WP_Term ) || ! self::is_hidden( (int) $term->term_id )
			)
		);
	}

	public function block_archive(): void {
		if ( ! is_tax( self::TAXONOMY ) || ! self::enforcing() ) {
			return;
		}
		$term = get_queried_object();
		if ( $term instanceof WP_Term && self::is_hidden( (int) $term->term_id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * @param array<string,mixed> $args     get_terms() arguments for the sitemap.
	 * @param string              $taxonomy Taxonomy.
	 * @return array<string,mixed>
	 */
	public function hide_in_sitemaps( array $args, string $taxonomy ): array {
		if ( self::TAXONOMY !== $taxonomy || ! self::ids() ) {
			return $args;
		}
		$args['exclude'] = array_values( array_unique( array_merge( wp_parse_id_list( $args['exclude'] ?? array() ), self::hidden_ids() ) ) );
		return $args;
	}

	// -------------------------------------------------------------------------
	// Admin
	// -------------------------------------------------------------------------

	/**
	 * @param array<string,string> $columns Term list columns.
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$columns['isoft_fmf_pending'] = __( 'Status', 'isoft-fm-foundation' );
		return $columns;
	}

	/**
	 * @param string $content     Column content so far.
	 * @param string $column_name Column key.
	 * @param int    $term_id     Term ID.
	 */
	public function render_column( $content, $column_name, $term_id ): string {
		if ( 'isoft_fmf_pending' !== $column_name ) {
			return (string) $content;
		}
		return self::is_pending( (int) $term_id )
			? '<strong>' . esc_html__( 'Pending approval', 'isoft-fm-foundation' ) . '</strong>'
			: esc_html__( 'Approved', 'isoft-fm-foundation' );
	}

	/**
	 * @param array<string,string> $actions Row actions.
	 * @param WP_Term              $term    The category.
	 * @return array<string,string>
	 */
	public function row_actions( array $actions, $term ): array {
		if ( $term instanceof WP_Term && self::is_pending( (int) $term->term_id ) && current_user_can( 'isoft_fmf_manage_categories' ) ) {
			$url                = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => self::ACTION,
						'term_id' => (int) $term->term_id,
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION . '_' . (int) $term->term_id
			);
			$actions['approve'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Approve', 'isoft-fm-foundation' ) . '</a>';
		}
		return $actions;
	}

	public function handle_approve(): void {
		$term_id = isset( $_GET['term_id'] ) ? absint( wp_unslash( $_GET['term_id'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $term_id );
		if ( ! current_user_can( 'isoft_fmf_manage_categories' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'isoft-fm-foundation' ), 403 );
		}
		self::set( $term_id, false );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=isoft_fmf_file' ) );
		exit;
	}
}
