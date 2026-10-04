<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public, read-only REST API for headless frontends, static site builds and
 * apps. Everything is filtered for the *requesting* user by the same rules
 * as the frontend (ISOFT_FMF_Access_Control): anonymous requests see public
 * downloads only.
 *
 *   GET /isoft-fm-foundation/v1/public/downloads
 *   GET /isoft-fm-foundation/v1/public/downloads/{id}
 *   GET /isoft-fm-foundation/v1/public/categories
 *
 * Server paths (file_path) are never exposed. Local files carry a
 * download_url pointing at the regular download handler; external links
 * carry their URL.
 */
class ISOFT_FMF_Rest_Public {

	private const NS = 'isoft-fm-foundation/v1';

	private const MAX_PER_PAGE = 100;

	/** Seconds anonymous responses may be cached by browsers, CDNs and page caches. */
	private const CACHE_TTL = 300;

	private ?ISOFT_FMF_License_Resolver $resolver     = null;
	private ?ISOFT_FMF_Access_Control $access_control = null;
	private ?ISOFT_FMF_File_Manager $file_manager     = null;

	/**
	 * Whether the public API is switched on (Settings -> Advanced). Off by
	 * default so installs that update to this version gain no new surface.
	 */
	public static function is_enabled(): bool {
		/**
		 * Filters whether the public read-only REST API is available.
		 *
		 * @param bool $enabled Value of the "Enable the public read-only REST API" setting.
		 */
		return (bool) apply_filters( 'isoft_fmf_public_api_enabled', (bool) get_option( 'isoft_fmf_public_api_enabled', 0 ) );
	}

	private function access(): ISOFT_FMF_Access_Control {
		return $this->access_control ??= new ISOFT_FMF_Access_Control();
	}

	private function files(): ISOFT_FMF_File_Manager {
		return $this->file_manager ??= new ISOFT_FMF_File_Manager();
	}

	private function resolver(): ISOFT_FMF_License_Resolver {
		return $this->resolver ??= new ISOFT_FMF_License_Resolver();
	}

	private const ORDERBY = array( 'date', 'modified', 'title', 'menu_order', 'downloads' );

	public function register_routes(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		/**
		 * Filters the largest page size the public downloads endpoint accepts.
		 *
		 * @param int $max Default 100.
		 */
		$max_per_page = max( 1, (int) apply_filters( 'isoft_fmf_public_api_max_per_page', self::MAX_PER_PAGE ) );

		register_rest_route(
			self::NS,
			'/public/downloads',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_downloads' ),
				'permission_callback' => '__return_true',
				'schema'              => array( $this, 'download_collection_schema' ),
				'args'                => array(
					'category'              => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'include_subcategories' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'tag'                   => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'search'                => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'                  => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'              => array(
						'type'    => 'integer',
						'default' => min( 20, $max_per_page ),
						'minimum' => 1,
						'maximum' => $max_per_page,
					),
					'orderby'               => array(
						'type'    => 'string',
						'default' => 'date',
						'enum'    => self::ORDERBY,
					),
					'order'                 => array(
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => array( 'asc', 'desc' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/public/downloads/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_download' ),
				'permission_callback' => '__return_true',
				'schema'              => array( $this, 'download_schema' ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/public/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_categories' ),
				'permission_callback' => '__return_true',
				'schema'              => array( $this, 'category_collection_schema' ),
			)
		);
	}

	public function list_downloads( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'post_type'           => 'isoft_fmf_file',
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => (int) $request['per_page'],
			'paged'               => (int) $request['page'],
			'order'               => strtoupper( (string) $request['order'] ),
			'ignore_sticky_posts' => true,
		);

		$orderby = (string) $request['orderby'];
		if ( 'downloads' === $orderby ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by the denormalised download-count meta; same query the listing shortcode runs.
			$args['meta_key'] = '_isoft_fmf_download_count';
			$args['orderby']  = 'meta_value_num';
		} else {
			$args['orderby'] = $orderby;
		}

		$tax_query = array();
		if ( (int) $request['category'] > 0 ) {
			$tax_query[] = array(
				'taxonomy'         => 'isoft_fmf_category',
				'field'            => 'term_id',
				'terms'            => (int) $request['category'],
				'include_children' => (bool) $request['include_subcategories'],
			);
		}
		if ( (int) $request['tag'] > 0 ) {
			$tax_query[] = array(
				'taxonomy' => 'isoft_fmf_tag',
				'field'    => 'term_id',
				'terms'    => (int) $request['tag'],
			);
		}
		if ( $tax_query ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Category / tag filter is the point of the endpoint; paginated.
			$args['tax_query'] = $tax_query;
		}
		if ( '' !== (string) $request['search'] ) {
			$args['s'] = (string) $request['search'];
		}

		/*
		 * Access filtering happens in SQL via ISOFT_FMF_Access_Control's
		 * pre_get_posts hook (REST is a frontend context), so totals and
		 * pagination are right. The per-item check below is defence in depth.
		 */
		$query = new WP_Query( $args );

		// One query for every file row on the page instead of one per download.
		$this->files()->prime_files( wp_list_pluck( $query->posts, 'ID' ) );

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $this->access()->can_access_download( (int) $post->ID ) ) {
				$items[] = $this->shape_download( $post );
			}
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );
		return $this->with_cache_headers( $response );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_download( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post instanceof WP_Post
			|| 'isoft_fmf_file' !== $post->post_type
			|| 'publish' !== $post->post_status
			|| post_password_required( $post )
			|| ! $this->access()->can_access_download( (int) $post->ID )
		) {
			// Same answer for "missing" and "not allowed": nothing to probe.
			return new WP_Error( 'isoft_fmf_not_found', __( 'Download not found.', 'isoft-fm-foundation' ), array( 'status' => 404 ) );
		}

		return $this->with_cache_headers( new WP_REST_Response( $this->shape_download( $post ) ), get_post_modified_time( 'U', true, $post ) );
	}

	/**
	 * Cache policy. What a visitor may see depends on who they are, so only
	 * anonymous responses are shareable; anything tied to a login is private.
	 *
	 * @param WP_REST_Response $response      Response to annotate.
	 * @param int|false        $last_modified Unix time of the newest content in the response, if known.
	 */
	private function with_cache_headers( WP_REST_Response $response, $last_modified = false ): WP_REST_Response {
		/**
		 * Filters how long (seconds) anonymous public-API responses may be cached. 0 disables caching.
		 *
		 * @param int $ttl Default 300.
		 */
		$ttl = max( 0, (int) apply_filters( 'isoft_fmf_public_api_cache_ttl', self::CACHE_TTL ) );

		if ( is_user_logged_in() || 0 === $ttl ) {
			$response->header( 'Cache-Control', 'private, no-store' );
		} else {
			$response->header( 'Cache-Control', 'public, max-age=' . $ttl );
			if ( $last_modified ) {
				$response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', (int) $last_modified ) . ' GMT' );
			}
		}
		$response->header( 'Vary', 'Cookie, Authorization' );
		return $response;
	}

	public function list_categories(): WP_REST_Response {
		$terms = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return new WP_REST_Response( array() );
		}

		$by_id = array();
		foreach ( $terms as $term ) {
			$by_id[ (int) $term->term_id ] = $term;
		}

		/**
		 * Filters whether the public API lists a folder path (slug chain) per category.
		 * Off by default: it describes how the site organises its files.
		 *
		 * @param bool $include Default false.
		 */
		$include_folder = (bool) apply_filters( 'isoft_fmf_public_api_include_folder', false );

		$items = array();
		foreach ( $terms as $term ) {
			if ( ! $this->category_visible( $term, $by_id ) ) {
				continue;
			}
			$item = array(
				'id'          => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => (int) $term->parent,
				'description' => $term->description,
				'icon'        => (string) get_term_meta( $term->term_id, '_isoft_fmf_cat_icon', true ),
				'sort_order'  => (int) get_term_meta( $term->term_id, '_isoft_fmf_cat_sort_order', true ),
			);
			if ( $include_folder ) {
				$item['folder'] = isoft_fmf_category_folder_path( (int) $term->term_id );
			}
			$items[] = $item;
		}

		/*
		 * Post counts are left out on purpose: WordPress counts include
		 * restricted downloads, which would leak their existence.
		 */
		return $this->with_cache_headers( new WP_REST_Response( $items ) );
	}

	/**
	 * A category is listed when the requesting user meets its access role and
	 * so does every ancestor; a child of a hidden parent would otherwise
	 * point at a category the client cannot see.
	 *
	 * @param WP_Term             $term  Category.
	 * @param array<int, WP_Term> $by_id All categories keyed by ID.
	 */
	private function category_visible( WP_Term $term, array $by_id ): bool {
		$visible = true;
		$node    = $term;
		$guard   = 0;
		while ( $node && $guard++ < 50 ) {
			$role = (string) get_term_meta( $node->term_id, '_isoft_fmf_cat_access_role', true );
			if ( '' !== $role && ! $this->access()->user_meets_role( $role ) ) {
				$visible = false;
				break;
			}
			$node = $node->parent ? ( $by_id[ (int) $node->parent ] ?? null ) : null;
		}

		/**
		 * Filters whether a category is listed by the public API.
		 *
		 * @param bool    $visible Result of the built-in access-role check.
		 * @param WP_Term $term    Category.
		 */
		return (bool) apply_filters( 'isoft_fmf_public_category_visible', $visible, $term );
	}

	/**
	 * Public shape of a download. Never includes server paths.
	 *
	 * @return array<string, mixed>
	 */
	private function shape_download( WP_Post $post ): array {
		$id      = (int) $post->ID;
		$license = $this->resolver()->effective_license_row_for( $id );

		$files = array();
		foreach ( $this->files()->get_files( $id ) as $file ) {
			$external = 'external' === $file->file_type;
			$files[]  = array(
				'id'           => (int) $file->id,
				'title'        => (string) $file->title,
				'description'  => wp_kses_post( (string) $file->description ),
				'type'         => $external ? 'external' : 'local',
				'name'         => $external ? null : (string) $file->file_name,
				'size'         => $external ? null : (int) $file->file_size,
				'mime'         => $external ? null : (string) $file->file_mime,
				'sha256'       => $external || '' === (string) $file->file_hash ? null : (string) $file->file_hash,
				'url'          => $external ? esc_url_raw( (string) $file->external_url ) : null,
				'download_url' => isoft_fmf_get_download_url( (int) $file->id, $id ),
				'mirror'       => (bool) $file->is_mirror,
				'missing'      => (bool) $file->is_missing,
				'count'        => (int) $file->download_count,
				'order'        => (int) $file->sort_order,
			);
		}

		$thumbnail = null;
		$thumb_id  = (int) get_post_thumbnail_id( $post );
		$src       = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'full' ) : false;
		if ( $src ) {
			$thumbnail = array(
				'id'     => $thumb_id,
				'url'    => $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
				'alt'    => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
			);
		}

		$terms = static function ( string $taxonomy ) use ( $post ): array {
			$found = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $found ) ) {
				return array();
			}
			return array_map(
				static fn( WP_Term $term ): array => array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				),
				$found
			);
		};

		return array(
			'id'             => $id,
			'title'          => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'slug'           => $post->post_name,
			'excerpt'        => html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'content'        => apply_filters( 'the_content', $post->post_content ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, same as the core REST posts controller.
			'date'           => get_post_time( 'c', true, $post ),
			'modified'       => get_post_modified_time( 'c', true, $post ),
			'link'           => get_permalink( $post ),
			'categories'     => $terms( 'isoft_fmf_category' ),
			'tags'           => $terms( 'isoft_fmf_tag' ),
			'featured'       => (bool) get_post_meta( $id, '_isoft_fmf_featured', true ),
			'hot'            => (bool) get_post_meta( $id, '_isoft_fmf_is_hot', true ),
			'version'        => (string) get_post_meta( $id, '_isoft_fmf_version', true ),
			'author_name'    => (string) get_post_meta( $id, '_isoft_fmf_author_name', true ),
			'author_url'     => esc_url_raw( (string) get_post_meta( $id, '_isoft_fmf_author_url', true ) ),
			'date_published' => (string) get_post_meta( $id, '_isoft_fmf_date_published', true ),
			'download_count' => (int) get_post_meta( $id, '_isoft_fmf_download_count', true ),
			'access'         => $this->access()->effective_role_for( $id ),
			'license'        => $license ? array(
				'id'          => (int) $license->id,
				'title'       => (string) $license->title,
				'slug'        => (string) $license->slug,
				'description' => (string) $license->description,
				'url'         => (string) $license->url,
			) : null,
			'thumbnail'      => $thumbnail,
			'files'          => $files,
			'bundle_url'     => count( array_filter( $files, static fn( array $f ): bool => 'local' === $f['type'] ) ) > 1
				&& get_option( 'isoft_fmf_enable_zip_bundle', 0 )
				? isoft_fmf_get_bundle_url( $id )
				: null,
		);
	}

	/**
	 * JSON Schema of one download as returned by this API.
	 *
	 * @return array<string, mixed>
	 */
	public function download_schema(): array {
		$terms = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'integer' ),
					'name' => array( 'type' => 'string' ),
					'slug' => array( 'type' => 'string' ),
				),
			),
		);
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'isoft-fmf-download',
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'title'          => array( 'type' => 'string' ),
				'slug'           => array( 'type' => 'string' ),
				'excerpt'        => array( 'type' => 'string' ),
				'content'        => array(
					'type'        => 'string',
					'description' => 'Rendered HTML.',
				),
				'date'           => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'modified'       => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'link'           => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'categories'     => $terms,
				'tags'           => $terms,
				'featured'       => array( 'type' => 'boolean' ),
				'hot'            => array( 'type' => 'boolean' ),
				'version'        => array( 'type' => 'string' ),
				'author_name'    => array( 'type' => 'string' ),
				'author_url'     => array( 'type' => 'string' ),
				'date_published' => array( 'type' => 'string' ),
				'download_count' => array( 'type' => 'integer' ),
				'access'         => array(
					'type'        => 'string',
					'description' => 'Role required to download.',
				),
				'license'        => array( 'type' => array( 'object', 'null' ) ),
				'thumbnail'      => array( 'type' => array( 'object', 'null' ) ),
				'files'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'bundle_url'     => array( 'type' => array( 'string', 'null' ) ),
			),
		);
	}

	/**
	 * JSON Schema of the downloads collection.
	 *
	 * @return array<string, mixed>
	 */
	public function download_collection_schema(): array {
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => 'isoft-fmf-downloads',
			'type'    => 'array',
			'items'   => $this->download_schema(),
		);
	}

	/**
	 * JSON Schema of the categories collection.
	 *
	 * @return array<string, mixed>
	 */
	public function category_collection_schema(): array {
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => 'isoft-fmf-categories',
			'type'    => 'array',
			'items'   => array(
				'type'       => 'object',
				'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
					'description' => array( 'type' => 'string' ),
					'icon'        => array( 'type' => 'string' ),
					'sort_order'  => array( 'type' => 'integer' ),
				),
			),
		);
	}
}
