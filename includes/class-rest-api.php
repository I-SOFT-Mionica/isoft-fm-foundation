<?php
/**
 * REST API endpoints — Phase 3 (Gutenberg block support).
 *
 * Namespace: isoft-fm-foundation/v1
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Api {

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$ns = 'isoft-fm-foundation/v1';

		// GET /isoft-fm-foundation/v1/downloads
		register_rest_route(
			$ns,
			'/downloads',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_downloads' ),
				'permission_callback' => array( $this, 'editor_permission' ),
				'args'                => array(
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'tag'      => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'orderby'  => array(
						'default'           => 'date',
						'sanitize_callback' => 'sanitize_key',
					),
					'order'    => array(
						'default'           => 'DESC',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// GET /isoft-fm-foundation/v1/downloads/{id}/files
		register_rest_route(
			$ns,
			'/downloads/(?P<id>\d+)/files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_download_files' ),
				'permission_callback' => array( $this, 'editor_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /isoft-fm-foundation/v1/categories
		register_rest_route(
			$ns,
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_categories' ),
				'permission_callback' => array( $this, 'editor_permission' ),
				'args'                => array(
					'parent' => array(
						'default'           => null,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /isoft-fm-foundation/v1/stats/overview
		register_rest_route(
			$ns,
			'/stats/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats_overview' ),
				'permission_callback' => array( $this, 'logs_permission' ),
			)
		);

		// GET /isoft-fm-foundation/v1/logs
		register_rest_route(
			$ns,
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'logs_permission' ),
				'args'                => array(
					'per_page'    => array(
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
					'page'        => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'download_id' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'search'      => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Require edit_posts capability (Editors, Admins, Contributors with custom cap).
	 */
	public function editor_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Require the audit-log viewing capability — used for /stats/overview and
	 * /logs which expose download history (who downloaded what, when, from where).
	 */
	public function logs_permission(): bool {
		return current_user_can( 'isoft_fmf_view_logs' );
	}

	// -------------------------------------------------------------------------
	// Endpoint handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /isoft-fm-foundation/v1/downloads
	 *
	 * Returns published downloads for the block editor download picker.
	 * Supports: search, category (term_id), tag (term_id), orderby, order.
	 * Default: 20 most recent; search uses WP relevance ordering automatically.
	 */
	public function get_downloads( WP_REST_Request $request ): WP_REST_Response {
		$search   = (string) $request->get_param( 'search' );
		$category = (int) $request->get_param( 'category' );
		$tag      = (int) $request->get_param( 'tag' );

		$allowed_orderby = array( 'date', 'title', 'modified' );
		$orderby         = (string) $request->get_param( 'orderby' );
		$order           = 'ASC' === strtoupper( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'      => 'isoft_fmf_file',
			'post_status'    => 'publish',
			'posts_per_page' => min( (int) $request->get_param( 'per_page' ), 100 ),
			'no_found_rows'  => true,
		);

		if ( $search !== '' ) {
			// Let WP sort by relevance when a search term is provided.
			$args['s'] = $search;
		} else {
			$args['orderby'] = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'date';
			$args['order']   = $order;
		}

		if ( $category > 0 ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'isoft_fmf_category',
				'field'    => 'term_id',
				'terms'    => $category,
			);
		}

		if ( $tag > 0 ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'isoft_fmf_tag',
				'field'    => 'term_id',
				'terms'    => $tag,
			);
		}

		$posts = get_posts( $args );

		$data = array_map(
			function ( WP_Post $p ) {
				$cats = wp_get_post_terms( $p->ID, 'isoft_fmf_category', array( 'fields' => 'names' ) );
				return array(
					'id'         => $p->ID,
					'title'      => $p->post_title,
					'date'       => $p->post_date,
					'categories' => is_array( $cats ) ? $cats : array(),
				);
			},
			$posts
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /isoft-fm-foundation/v1/downloads/{id}/files
	 *
	 * Returns all files attached to a download — used by the download-button block.
	 */
	public function get_download_files( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$download_id = (int) $request->get_param( 'id' );

		$post = get_post( $download_id );
		if ( ! $post || $post->post_type !== 'isoft_fmf_file' ) {
			return new WP_Error(
				'isoft_fmf_not_found',
				__( 'Download not found.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $download_id ) ) {
			return new WP_Error(
				'isoft_fmf_forbidden',
				__( 'You do not have permission to view this download\'s files.', 'isoft-fm-foundation' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;

		// download_count + is_missing added in 0.12.1 so the editor-sidebar
		// Stats panel can render per-file counts and the Files panel can
		// show a "X broken" badge — one fetch, two consumers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$files = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, file_name, file_type, file_size, file_mime, external_url, sort_order, download_count, is_missing
				   FROM {$wpdb->prefix}isoft_fmf_files
				  WHERE download_id = %d
				  ORDER BY sort_order ASC, id ASC",
				$download_id
			)
		);

		if ( $files === null ) {
			return new WP_Error(
				'isoft_fmf_db_error',
				__( 'Database error.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		$data = array_map(
			function ( object $f ): array {
				$label = $f->title ?: $f->file_name ?: $f->external_url ?: sprintf(
				/* translators: %d: file record id */
					__( 'File #%d', 'isoft-fm-foundation' ),
					(int) $f->id
				);
				return array(
					'id'             => (int) $f->id,
					'title'          => $label,
					'file_name'      => $f->file_name,
					'file_type'      => $f->file_type,
					'file_size'      => (int) $f->file_size,
					'file_mime'      => $f->file_mime,
					'external_url'   => $f->external_url,
					'download_count' => (int) $f->download_count,
					'is_missing'     => (bool) $f->is_missing,
				);
			},
			$files
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /isoft-fm-foundation/v1/categories
	 *
	 * Returns all isoft_fmf_category terms, optionally filtered by parent.
	 */
	public function get_categories( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'taxonomy'   => 'isoft_fmf_category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$parent = $request->get_param( 'parent' );
		if ( $parent !== null ) {
			$args['parent'] = (int) $parent;
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		$data = array_map(
			fn( WP_Term $t ) => array(
				'id'     => $t->term_id,
				'name'   => $t->name,
				'slug'   => $t->slug,
				'parent' => $t->parent,
				'count'  => $t->count,
			),
			$terms
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /isoft-fm-foundation/v1/stats/overview
	 *
	 * Returns aggregate statistics for the dashboard widget.
	 */
	public function get_stats_overview( WP_REST_Request $request ): WP_REST_Response {
		$stats = isoft_fmf_get_stats_overview();

		// Daily 30d series is returned by the helper as wpdb rows (day,count);
		// flatten to a YYYY-MM-DD => int map for the React chart, which is
		// the shape the PHP view (admin/views/stats-dashboard.php) also
		// reduced to before rendering its bars.
		$daily_map = array();
		foreach ( $stats['daily_30d'] as $row ) {
			$daily_map[ $row->day ] = (int) $row->count;
		}

		// `top_downloads_30d` (limited to 5) is the pre-0.12.3 shape kept
		// for any external consumer; `top_30d` is the full 10-row list the
		// dashboard renders. Both populated from the same data so there's
		// no extra query cost.
		return new WP_REST_Response(
			array(
				'total_downloads'   => $stats['total_downloads'],
				'total_files'       => $stats['total_files'],
				'total_log_entries' => $stats['total_log_entries'],
				'total_size_bytes'  => $stats['total_size_bytes'],
				'top_downloads_30d' => array_slice( $stats['top_30d'], 0, 5 ),
				'top_alltime'       => $stats['top_alltime'],
				'top_30d'           => $stats['top_30d'],
				'top_30d_window'    => $stats['top_30d_window'] ?? '30d',
				'daily_30d'         => $daily_map,
				'logging_enabled'   => (bool) get_option( 'isoft_fmf_enable_logging', true ),
			),
			200
		);
	}

	/**
	 * GET /isoft-fm-foundation/v1/logs
	 *
	 * Returns paginated download log entries for the Phase 4 log viewer.
	 */
	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$per_page    = min( (int) $request->get_param( 'per_page' ), 200 );
		$page        = max( 1, (int) $request->get_param( 'page' ) );
		$download_id = (int) $request->get_param( 'download_id' );
		$search      = trim( (string) $request->get_param( 'search' ) );
		$offset      = ( $page - 1 ) * $per_page;

		// Literal-prepare pattern: each branch passes a single string literal
		// as the first arg of $wpdb->prepare(), so sniffs can verify safety
		// without tracing concatenated $where/$base_sql variables. Search adds
		// a second dimension to the existing download_id filter, so four
		// explicit branches cover {none, search, download, download+search}.
		// Search matches on download title, file name, or user IP — the
		// three columns admins look up to chase down a specific event.
		$like = $search !== '' ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		if ( $download_id > 0 && $search !== '' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; pagination prevents query-cache benefit.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login, l.ip_address AS user_ip,
					        l.user_agent, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE l.download_id = %d
					    AND ( p.post_title LIKE %s OR f.file_name LIKE %s OR l.ip_address LIKE %s )
					  ORDER BY l.downloaded_at DESC
					  LIMIT %d OFFSET %d",
					$download_id,
					$like,
					$like,
					$like,
					$per_page,
					$offset
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; count cannot be cached due to live filter.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE l.download_id = %d
					    AND ( p.post_title LIKE %s OR f.file_name LIKE %s OR l.ip_address LIKE %s )",
					$download_id,
					$like,
					$like,
					$like
				)
			);
		} elseif ( $download_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; pagination prevents query-cache benefit.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login, l.ip_address AS user_ip,
					        l.user_agent, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE l.download_id = %d
					  ORDER BY l.downloaded_at DESC
					  LIMIT %d OFFSET %d",
					$download_id,
					$per_page,
					$offset
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; count cannot be cached due to live filter.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log l WHERE l.download_id = %d",
					$download_id
				)
			);
		} elseif ( $search !== '' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; pagination prevents query-cache benefit.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login, l.ip_address AS user_ip,
					        l.user_agent, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR f.file_name LIKE %s OR l.ip_address LIKE %s )
					  ORDER BY l.downloaded_at DESC
					  LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					$per_page,
					$offset
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; count cannot be cached due to live filter.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR f.file_name LIKE %s OR l.ip_address LIKE %s )",
					$like,
					$like,
					$like
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; pagination prevents query-cache benefit.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login, l.ip_address AS user_ip,
					        l.user_agent, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  ORDER BY l.downloaded_at DESC
					  LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REST endpoint on custom log table; full-table count, no cache layer.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" );
		}

		// Wrap items + pagination metadata in a single response object so
		// the React client can consume one parsed payload. The original
		// X-WP-Total / X-WP-TotalPages header pattern fought with
		// @wordpress/api-fetch's middleware chain — switching to a body
		// envelope sidesteps that and matches what we'd do for any new
		// paginated endpoint going forward.
		return new WP_REST_Response(
			array(
				'items'      => $rows ?? array(),
				'totalItems' => $total,
				'totalPages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			),
			200
		);
	}
}
