<?php
/**
 * User ↔ category write-side ACL.
 *
 * Rules:
 *   - Admins (`manage_options`) are unrestricted.
 *   - Other users are assigned one or more explicit isoft_fmf_category term ids.
 *   - Assignments inherit downwards: user allowed on "Skupština Opštine"
 *     can write anywhere in its subtree.
 *   - Read-side is unchanged for PUBLISHED downloads — anyone can see them.
 *     Non-published downloads are only visible to users whose allowed set
 *     covers the download's category (enforced separately; this class only
 *     exposes the check).
 *
 * Storage: user meta `_isoft_fmf_allowed_categories` = array<int> of explicit ids.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Category_ACL {

	private const string USER_META_KEY = '_isoft_fmf_allowed_categories';

	/**
	 * Persistent-cache group for the resolved (expanded) allowed-category
	 * sets per user. When Redis / Memcached / APCu backs wp_cache_*, this
	 * survives across requests — the expensive `get_term_children` walk
	 * only runs when a user's assignment or the term hierarchy changes.
	 * Without a persistent backend, the group is per-request and the
	 * in-request static memo below still applies.
	 */
	public const CACHE_GROUP = 'isoft_fmf_acl';

	/** In-request memoization of effective (expanded) allowed sets per user. */
	private static array $effective_cache = array();

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	public function register_hooks(): void {
		// Capability enforcement on CPT edit/delete/create.
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );

		// Reject category-change saves when the target is outside the user's reach.
		add_action( 'save_post_isoft_fmf_file', array( $this, 'enforce_category_on_save' ), 1, 3 );

		// Admin list filter — hide downloads the user can't write.
		add_action( 'pre_get_posts', array( $this, 'filter_admin_list' ) );

		// Frontend filter: hide UNPUBLISHED downloads from users whose allowed
		// category set doesn't cover them. Published downloads stay visible.
		add_action( 'pre_get_posts', array( $this, 'filter_frontend_query' ) );

		// Classic editor category metabox: hide forbidden terms from the
		// picker so users don't even see what they can't write.
		add_filter( 'get_terms_args', array( $this, 'filter_category_metabox_terms' ), 10, 2 );

		// Profile screen: render + save assigned categories.
		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );

		// Enqueue tree CSS on the user profile screen.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_profile_assets' ) );

		// Cache invalidation: any change to a user's allowed_categories
		// meta OR any term-hierarchy change flushes the resolved
		// per-user set. Term hierarchy is a coarse-grained flush
		// (delete the whole group) because a moved child affects every
		// parent's descendant set.
		add_action( 'updated_user_meta', array( __CLASS__, 'on_user_meta_change' ), 10, 3 );
		add_action( 'added_user_meta', array( __CLASS__, 'on_user_meta_change' ), 10, 3 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'on_user_meta_change' ), 10, 3 );
		add_action( 'edited_isoft_fmf_category', array( __CLASS__, 'flush_group' ) );
		add_action( 'created_isoft_fmf_category', array( __CLASS__, 'flush_group' ) );
		add_action( 'delete_isoft_fmf_category', array( __CLASS__, 'flush_group' ) );
	}

	/**
	 * Meta-change hook — flush the user's resolved cache entry when
	 * their allowed_categories assignment changes.
	 *
	 * @param int|int[] $meta_id  Unused.
	 * @param int       $user_id  The affected user.
	 * @param string    $meta_key The meta key that changed.
	 */
	public static function on_user_meta_change( $meta_id, int $user_id, string $meta_key ): void {
		unset( $meta_id );
		if ( self::USER_META_KEY !== $meta_key ) {
			return;
		}
		self::flush_user( $user_id );
	}

	/** Flush a single user's resolved-set cache entry. */
	public static function flush_user( int $user_id ): void {
		unset( self::$effective_cache[ $user_id ] );
		wp_cache_delete( "effective_{$user_id}", self::CACHE_GROUP );
	}

	/**
	 * Flush every resolved set. Called when the term hierarchy shifts,
	 * because child moves change every ancestor's descendant set. Uses
	 * wp_cache_flush_group when available (WP 6.1+); otherwise bumps
	 * an in-group version key so stale entries fall through.
	 */
	public static function flush_group(): void {
		self::$effective_cache = array();
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
			return;
		}
		// Fallback: bump a namespace version; readers include it in
		// their cache key so old entries become inaccessible.
		wp_cache_set( 'version', microtime( true ), self::CACHE_GROUP );
	}

	// -------------------------------------------------------------------------
	// Core checks
	// -------------------------------------------------------------------------

	/** Admins bypass all ACL checks. */
	public static function is_unrestricted( int $user_id ): bool {
		return user_can( $user_id, 'manage_options' );
	}

	/** Raw list of explicitly assigned category ids (no inheritance). */
	public static function get_explicit( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $raw ) ) );
	}

	/**
	 * Expanded allowed set: each explicit id plus every descendant term.
	 * Returned as a set (keys are term ids) for O(1) lookup.
	 *
	 * @return array<int,true>
	 */
	public static function get_effective( int $user_id ): array {
		if ( isset( self::$effective_cache[ $user_id ] ) ) {
			return self::$effective_cache[ $user_id ];
		}

		$cached = wp_cache_get( "effective_{$user_id}", self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			self::$effective_cache[ $user_id ] = $cached;
			return $cached;
		}

		$explicit = self::get_explicit( $user_id );
		$set      = array();
		foreach ( $explicit as $term_id ) {
			$set[ $term_id ] = true;
			$children        = get_term_children( $term_id, 'isoft_fmf_category' );
			if ( ! is_wp_error( $children ) ) {
				foreach ( $children as $child_id ) {
					$set[ (int) $child_id ] = true;
				}
			}
		}

		self::$effective_cache[ $user_id ] = $set;
		wp_cache_set( "effective_{$user_id}", $set, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $set;
	}

	/** True if the user can write in this category (via explicit or inherited assignment). */
	public static function can_write_category( int $user_id, int $term_id ): bool {
		if ( self::is_unrestricted( $user_id ) ) {
			return true;
		}
		if ( ! $term_id ) {
			return false;
		}
		return isset( self::get_effective( $user_id )[ $term_id ] );
	}

	/** True if the user can edit a given download (based on its assigned category). */
	public static function can_edit_download( int $user_id, int $download_id ): bool {
		if ( self::is_unrestricted( $user_id ) ) {
			return true;
		}
		$terms = wp_get_object_terms( $download_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			// No category assigned yet (new draft / auto-draft). Allow access
			// so the edit screen opens; save-time enforcement will reject any
			// attempt to assign a category the user can't write.
			return true;
		}
		foreach ( $terms as $term_id ) {
			if ( self::can_write_category( $user_id, (int) $term_id ) ) {
				return true;
			}
		}
		return false;
	}

	/** True if the user can see unpublished downloads in this category. */
	public static function can_see_unpublished( int $user_id, int $download_id ): bool {
		return self::can_edit_download( $user_id, $download_id );
	}

	// -------------------------------------------------------------------------
	// Capability filter
	// -------------------------------------------------------------------------

	/**
	 * Gate edit_post / delete_post / publish_post on isoft_fmf_file posts by category ACL.
	 * We *add* to the required caps list — so anyone who already failed the
	 * base check still fails. This only restricts further, never loosens.
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		$watch = array( 'edit_post', 'delete_post', 'publish_post' );
		if ( ! in_array( $cap, $watch, true ) ) {
			return $caps;
		}
		if ( empty( $args[0] ) ) {
			return $caps;
		}
		$post_id = (int) $args[0];
		if ( 'isoft_fmf_file' !== get_post_type( $post_id ) ) {
			return $caps;
		}
		if ( self::is_unrestricted( $user_id ) ) {
			return $caps;
		}

		if ( ! self::can_edit_download( $user_id, $post_id ) ) {
			// do_not_allow is WordPress's canonical "deny" cap — no role has it.
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	// -------------------------------------------------------------------------
	// Save-time category target / source check
	// -------------------------------------------------------------------------

	/**
	 * On save_post_isoft_fmf_file: reject the save if the posted isoft_fmf_category assignment
	 * contains a category the user has no write access to.
	 *
	 * Source (pre-save category) enforcement is handled entirely by
	 * map_meta_cap — a user who can't write the current category can't reach
	 * the edit screen / REST endpoint / inline-edit action in the first place.
	 */
	public function enforce_category_on_save( int $post_id, WP_Post $post, bool $update ): void {
		unset( $post, $update );

		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || self::is_unrestricted( $user_id ) ) {
			return;
		}

		// WP core verifies update-post_<id> before save_post fires; re-verify
		// here so static analysis can see it.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'update-post_' . $post_id ) ) {
			return;
		}

		// Only act when the classic editor actually posted a category choice.
		if ( ! isset( $_POST['tax_input']['isoft_fmf_category'] ) ) {
			return;
		}

		// Target: the posted category. Empty strings / zero values mean
		// "no change" in WP's terms UI. absint() applied to each element
		// below is both the unslash (backslashes can't survive int cast)
		// and the sanitization for numeric IDs.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- See note above.
		$raw    = $_POST['tax_input']['isoft_fmf_category'];
		$posted = is_array( $raw ) ? array_map( 'absint', $raw ) : array( absint( $raw ) );
		foreach ( $posted as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}
			if ( ! self::can_write_category( $user_id, $term_id ) ) {
				wp_die(
					esc_html__( 'You do not have permission to save downloads in the target category.', 'isoft-fm-foundation' ),
					esc_html__( 'Permission Denied', 'isoft-fm-foundation' ),
					array(
						'back_link' => true,
						'response'  => 403,
					)
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Admin list filter
	// -------------------------------------------------------------------------

	/**
	 * Restrict the Downloads admin list to categories the user can write.
	 */
	public function filter_admin_list( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'isoft_fmf_file' !== $query->get( 'post_type' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || self::is_unrestricted( $user_id ) ) {
			return;
		}

		$effective = array_keys( self::get_effective( $user_id ) );
		if ( empty( $effective ) ) {
			// User has no categories at all — show nothing.
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = array(
			'taxonomy' => 'isoft_fmf_category',
			'field'    => 'term_id',
			'terms'    => $effective,
			'operator' => 'IN',
		);
		$query->set( 'tax_query', $tax_query );
	}

	// -------------------------------------------------------------------------
	// Frontend unpublished-visibility filter
	// -------------------------------------------------------------------------

	/**
	 * Remove unpublished `isoft_fmf_file` downloads from frontend queries unless the
	 * current user's allowed-category set covers them.
	 *
	 * Published downloads are untouched — they remain world-readable. This
	 * filter only kicks in when a query might include non-published statuses
	 * (typically editors/authors logged in who would otherwise see drafts
	 * across the whole site).
	 */
	public function filter_frontend_query( WP_Query $query ): void {
		if ( is_admin() ) {
			return; // Handled by filter_admin_list().
		}

		// Only care about isoft_fmf_file queries.
		$post_type = $query->get( 'post_type' );
		if ( 'isoft_fmf_file' !== $post_type && ! ( is_array( $post_type ) && in_array( 'isoft_fmf_file', $post_type, true ) ) ) {
			// Untyped query on a taxonomy archive also counts.
			if ( ! $query->is_tax( array( 'isoft_fmf_category', 'isoft_fmf_tag' ) ) && ! $query->is_post_type_archive( 'isoft_fmf_file' ) ) {
				return;
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id && self::is_unrestricted( $user_id ) ) {
			return;
		}

		$effective = $user_id ? array_keys( self::get_effective( $user_id ) ) : array();

		/*
		 * Split into two sub-queries combined with OR:
		 *   (post_status = publish)
		 *     OR
		 *   (post_status IN logged-in-statuses AND category IN effective)
		 *
		 * WP_Query doesn't let us OR across post_status and tax_query in
		 * a single pass, so we drop to a SQL posts_where/posts_join filter
		 * scoped to this one query.
		 */
		$query->set( 'isoft_fmf_acl_effective_categories', $effective );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), 10, 2 );
	}

	/**
	 * SQL-level OR between (published) and (unpublished AND in-user-scope).
	 * Registered per-query by filter_frontend_query() and unhooks itself.
	 */
	public function filter_posts_clauses( array $clauses, WP_Query $query ): array {
		// Only act on the query we flagged.
		$effective = $query->get( 'isoft_fmf_acl_effective_categories' );
		if ( null === $effective || '' === $effective ) {
			return $clauses;
		}
		remove_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), 10 );

		global $wpdb;

		if ( empty( $effective ) ) {
			// No effective categories — only published downloads are visible.
			$clauses['where'] .= " AND {$wpdb->posts}.post_status = 'publish'";
			return $clauses;
		}

		$ids_sql = implode( ',', array_map( 'intval', $effective ) );

		// Sub-select: post IDs that belong to the user's allowed categories.
		$allowed_ids_sql = "
			SELECT tr.object_id
			  FROM {$wpdb->term_relationships} tr
			  JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tt.taxonomy = 'isoft_fmf_category'
			   AND tt.term_id IN ({$ids_sql})
		";

		$clauses['where'] .= " AND (
			{$wpdb->posts}.post_status = 'publish'
			OR {$wpdb->posts}.ID IN ( {$allowed_ids_sql} )
		)";

		return $clauses;
	}

	// -------------------------------------------------------------------------
	// Classic editor category metabox filter
	// -------------------------------------------------------------------------

	/**
	 * Filter get_terms() results to hide isoft_fmf_category terms the user can't
	 * write. This makes the classic editor's Categories metabox only show
	 * terms the user is actually allowed to pick.
	 *
	 * Scope is intentionally narrow:
	 *   - Only fires in admin on post.php / post-new.php for isoft_fmf_file posts.
	 *   - Only isoft_fmf_category queries.
	 *   - Only for restricted users (admins bypass).
	 *
	 * We do NOT filter taxonomy term admin pages (edit-tags.php) because
	 * term-management uses a separate capability (isoft_fmf_manage_categories)
	 * and should remain full-view for anyone who has it.
	 */
	public function filter_category_metabox_terms( array $args, array $taxonomies ): array {
		if ( ! is_admin() ) {
			return $args;
		}
		if ( ! in_array( 'isoft_fmf_category', $taxonomies, true ) ) {
			return $args;
		}

		// Only apply on the download edit screen — not on edit-tags.php or
		// on AJAX calls from other contexts.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'isoft_fmf_file' !== $screen->post_type || 'post' !== $screen->base ) {
			return $args;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || self::is_unrestricted( $user_id ) ) {
			return $args;
		}

		$effective = array_keys( self::get_effective( $user_id ) );
		if ( empty( $effective ) ) {
			// No access at all — force an impossible include so the metabox
			// renders empty instead of showing every term.
			$args['include'] = array( 0 );
			return $args;
		}

		// Merge with any existing include list (rare, but play nice).
		$existing_include = (array) ( $args['include'] ?? array() );
		$args['include']  = $existing_include
			? array_values( array_intersect( $existing_include, $effective ) )
			: $effective;

		return $args;
	}

	// -------------------------------------------------------------------------
	// Profile UI
	// -------------------------------------------------------------------------

	public function enqueue_profile_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'isoft-fmf-admin', ISOFT_FMF_PLUGIN_URL . 'admin/css/admin-style.css', array(), ISOFT_FMF_VERSION );
	}

	public function render_profile_field( WP_User $user ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'I-Soft File Manager: Foundation — Allowed Categories', 'isoft-fm-foundation' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'This user can create, edit and delete downloads in the selected categories and all their descendants. Leave empty to restrict them completely (admins are always unrestricted).', 'isoft-fm-foundation' ); ?>
		</p>

		<?php
		// 0.12.4+: when the React bundle loads, hand the surface over to
		// it via a mount node + data-user-id. The React app reads/writes
		// through the new REST /users/{id}/category-acl endpoint so the
		// save no longer rides on the user-edit form's $_POST. Fallback
		// to the PHP <details> checkbox tree when JS is unavailable.
		if ( wp_script_is( ISOFT_FMF_Profile_ACL_Page::SCRIPT_HANDLE, 'enqueued' ) ) :
			?>
			<div
				id="isoft-fmf-profile-acl-root"
				data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
			></div>
			<noscript>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'The category ACL editor requires JavaScript. Please enable JavaScript in your browser, or revert to the previous plugin version.', 'isoft-fm-foundation' ); ?></p>
				</div>
			</noscript>
			<?php
		else :
			$explicit = self::get_explicit( $user->ID );
			$selected = array_flip( $explicit );
			$tree     = $this->build_category_tree();
			?>
			<div class="isoft-fmf-acl-tree">
				<?php $this->render_tree_nodes( $tree, $selected ); ?>
			</div>
			<?php
		endif;
	}

	public function save_profile_field( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// WP core verifies update-user_<id> before personal_options_update /
		// edit_user_profile_update fire; re-verify so static analysis sees it.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'update-user_' . $user_id ) ) {
			return;
		}

		// absint() on each element below is both the unslash (numeric input
		// can't carry magic-quote backslashes) and the sanitization.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- See note above.
		$raw = isset( $_POST['isoft_fmf_allowed_categories'] ) ? $_POST['isoft_fmf_allowed_categories'] : array();
		$ids = is_array( $raw ) ? array_map( 'absint', $raw ) : array();
		$ids = array_values( array_filter( $ids, fn( int $i ): bool => $i > 0 ) );
		// update_user_meta triggers `updated_user_meta` which our
		// on_user_meta_change() handler consumes to flush both the
		// in-request and persistent cache — no manual unset needed.
		update_user_meta( $user_id, self::USER_META_KEY, $ids );
	}

	// -------------------------------------------------------------------------
	// Tree rendering helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a parent→children adjacency list for isoft_fmf_category.
	 *
	 * @return array<int,array{term:WP_Term, children:int[]}>
	 */
	private function build_category_tree(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $terms as $term ) {
			$nodes[ (int) $term->term_id ] = array(
				'term'     => $term,
				'children' => array(),
			);
		}
		foreach ( $terms as $term ) {
			$parent = (int) $term->parent;
			if ( $parent && isset( $nodes[ $parent ] ) ) {
				$nodes[ $parent ]['children'][] = (int) $term->term_id;
			}
		}
		return $nodes;
	}

	/**
	 * Recursively render the tree starting from root terms (parent = 0).
	 *
	 * @param array<int,array{term:WP_Term, children:int[]}> $nodes
	 * @param array<int,int>                                 $selected  set keyed by term id
	 */
	private function render_tree_nodes( array $nodes, array $selected, int $parent_id = 0 ): void {
		$roots = array_filter( $nodes, fn( array $n ): bool => (int) $n['term']->parent === $parent_id );
		if ( empty( $roots ) ) {
			return;
		}

		echo '<ul class="isoft-fmf-acl-tree__list">';
		foreach ( $roots as $node ) {
			$term       = $node['term'];
			$has_kids   = ! empty( $node['children'] );
			$is_checked = isset( $selected[ (int) $term->term_id ] );
			$field_id   = 'isoft-fmf-acl-' . (int) $term->term_id;

			echo '<li class="isoft-fmf-acl-tree__item">';

			if ( $has_kids ) {
				// <details open> when this branch (or a descendant) is selected.
				$open = $is_checked || $this->subtree_has_selection( $nodes, (int) $term->term_id, $selected );
				echo '<details' . ( $open ? ' open' : '' ) . '>';
				echo '<summary>';
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="isoft_fmf_allowed_categories[]" value="%2$d"%3$s /> %4$s</label>',
					esc_attr( $field_id ),
					(int) $term->term_id,
					checked( $is_checked, true, false ),
					esc_html( $term->name )
				);
				echo '</summary>';
				$this->render_tree_nodes( $nodes, $selected, (int) $term->term_id );
				echo '</details>';
			} else {
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="isoft_fmf_allowed_categories[]" value="%2$d"%3$s /> %4$s</label>',
					esc_attr( $field_id ),
					(int) $term->term_id,
					checked( $is_checked, true, false ),
					esc_html( $term->name )
				);
			}

			echo '</li>';
		}
		echo '</ul>';
	}

	/** Walks down from $parent_id to check whether any descendant is in $selected. */
	private function subtree_has_selection( array $nodes, int $parent_id, array $selected ): bool {
		if ( empty( $nodes[ $parent_id ]['children'] ) ) {
			return false;
		}
		foreach ( $nodes[ $parent_id ]['children'] as $child_id ) {
			if ( isset( $selected[ $child_id ] ) ) {
				return true;
			}
			if ( $this->subtree_has_selection( $nodes, $child_id, $selected ) ) {
				return true;
			}
		}
		return false;
	}
}
