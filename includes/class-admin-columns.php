<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Admin_Columns {

	/** List-screen URL parameter: a file format (extension), e.g. "pdf". */
	public const FORMAT_PARAM = 'isoft_fmf_format';

	/** List-screen URL parameter: a file kind, one of KINDS. */
	public const KIND_PARAM = 'isoft_fmf_kind';

	/** Query var carrying the file filter from pre_get_posts to posts_where. */
	public const FILE_FILTER_VAR = 'isoft_fmf_file_filter';

	/** The file_type values of the files table. */
	public const KINDS = array( 'local', 'external', 'remote' );

	private const CACHE_GROUP = 'isoft_fmf_admin_list';

	public function register_hooks(): void {
		add_filter( 'manage_isoft_fmf_file_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_isoft_fmf_file_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-isoft_fmf_file_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply_list_query' ) );
		add_filter( 'posts_where', array( $this, 'filter_by_files' ), 10, 2 );
	}

	public function add_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['isoft_fmf_thumbnail']      = __( 'Thumb', 'isoft-fm-foundation' );
				$new['isoft_fmf_category']       = __( 'Category', 'isoft-fm-foundation' );
				$new['isoft_fmf_files_count']    = __( 'Files', 'isoft-fm-foundation' );
				$new['isoft_fmf_download_count'] = __( 'Downloads', 'isoft-fm-foundation' );
				$new['isoft_fmf_access_role']    = __( 'Access', 'isoft-fm-foundation' );
				$new['isoft_fmf_featured']       = '★';
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'isoft_fmf_thumbnail':
				echo has_post_thumbnail( $post_id )
					? get_the_post_thumbnail( $post_id, array( 40, 40 ) )
					: '<span class="dashicons dashicons-media-default" style="font-size:32px;color:#ccc;line-height:40px;"></span>';
				break;

			case 'isoft_fmf_category':
				$terms = get_the_terms( $post_id, 'isoft_fmf_category' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$links = array_map(
						fn( $t ) => sprintf(
							'<a href="%s">%s</a>',
							esc_url(
								add_query_arg(
									array(
										'post_type' => 'isoft_fmf_file',
										'isoft_fmf_category' => $t->slug,
									),
									admin_url( 'edit.php' )
								)
							),
							esc_html( $t->name )
						),
						$terms
					);
					echo wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => true ) ) );
				} else {
					echo '—';
				}
				break;

			case 'isoft_fmf_files_count':
				echo esc_html( count( ( new ISOFT_FMF_File_Manager() )->get_files( $post_id ) ) );
				break;

			case 'isoft_fmf_download_count':
				echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, '_isoft_fmf_download_count', true ) ) );
				break;

			case 'isoft_fmf_access_role':
				$labels = array(
					'public'        => __( 'Public', 'isoft-fm-foundation' ),
					'subscriber'    => __( 'Subscriber+', 'isoft-fm-foundation' ),
					'contributor'   => __( 'Contributor+', 'isoft-fm-foundation' ),
					'author'        => __( 'Author+', 'isoft-fm-foundation' ),
					'editor'        => __( 'Editor+', 'isoft-fm-foundation' ),
					'administrator' => __( 'Admin only', 'isoft-fm-foundation' ),
				);
				$role   = get_post_meta( $post_id, '_isoft_fmf_access_role', true ) ?: 'public';
				echo esc_html( $labels[ $role ] ?? $role );
				break;

			case 'isoft_fmf_featured':
				if ( get_post_meta( $post_id, '_isoft_fmf_featured', true ) ) {
					echo '<span class="dashicons dashicons-star-filled" style="color:#f0b849;" title="' . esc_attr__( 'Featured', 'isoft-fm-foundation' ) . '"></span>';
				}
				break;
		}
	}

	public function sortable_columns( array $columns ): array {
		$columns['isoft_fmf_download_count'] = 'isoft_fmf_download_count';
		return $columns;
	}

	/**
	 * Filter dropdowns above the Downloads list: category, file format and
	 * file kind. A restricted user is offered only the categories they can
	 * reach (the list itself is already limited by ISOFT_FMF_Category_Acl).
	 *
	 * @param string $post_type The list's post type.
	 * @param string $which     'top' or 'bottom'.
	 */
	public function render_filters( string $post_type, string $which = 'top' ): void {
		if ( 'isoft_fmf_file' !== $post_type || 'top' !== $which ) {
			return;
		}

		$category_args = array(
			'taxonomy'        => 'isoft_fmf_category',
			'name'            => 'isoft_fmf_category',
			'id'              => 'isoft-fmf-filter-category',
			'value_field'     => 'slug',
			'show_option_all' => __( 'All categories', 'isoft-fm-foundation' ),
			'hierarchical'    => true,
			'hide_empty'      => false,
			'orderby'         => 'name',
			'selected'        => (string) get_query_var( 'isoft_fmf_category' ),
		);
		$user_id       = get_current_user_id();
		if ( $user_id && ! ISOFT_FMF_Category_Acl::is_unrestricted( $user_id ) ) {
			$effective                = array_keys( ISOFT_FMF_Category_Acl::get_effective( $user_id ) );
			$category_args['include'] = $effective ? $effective : array( 0 );
		}
		echo '<label class="screen-reader-text" for="isoft-fmf-filter-category">' . esc_html__( 'Filter by category', 'isoft-fm-foundation' ) . '</label>';
		wp_dropdown_categories( $category_args );

		$format = self::requested_format();
		echo '<label class="screen-reader-text" for="isoft-fmf-filter-format">' . esc_html__( 'Filter by file format', 'isoft-fm-foundation' ) . '</label>';
		echo '<select name="' . esc_attr( self::FORMAT_PARAM ) . '" id="isoft-fmf-filter-format">';
		echo '<option value="">' . esc_html__( 'All formats', 'isoft-fm-foundation' ) . '</option>';
		foreach ( self::formats() as $ext ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $ext ), selected( $format, $ext, false ), esc_html( strtoupper( $ext ) ) );
		}
		echo '</select>';

		$kind   = self::requested_kind();
		$labels = array(
			'local'    => __( 'Uploaded files', 'isoft-fm-foundation' ),
			'external' => __( 'External links', 'isoft-fm-foundation' ),
			'remote'   => __( 'Remote files', 'isoft-fm-foundation' ),
		);
		echo '<label class="screen-reader-text" for="isoft-fmf-filter-kind">' . esc_html__( 'Filter by file kind', 'isoft-fm-foundation' ) . '</label>';
		echo '<select name="' . esc_attr( self::KIND_PARAM ) . '" id="isoft-fmf-filter-kind">';
		echo '<option value="">' . esc_html__( 'All kinds', 'isoft-fm-foundation' ) . '</option>';
		foreach ( $labels as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $kind, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * The Downloads list query: sort by the download count, and carry the
	 * format / kind filter to posts_where.
	 */
	public function apply_list_query( WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || ! $query->is_main_query() || 'edit.php' !== $pagenow ) {
			return;
		}
		if ( 'isoft_fmf_file' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'isoft_fmf_download_count' === $query->get( 'orderby' ) ) {
			// Downloads without the meta row sort as 0 instead of disappearing.
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array(
				'relation'                 => 'OR',
				'isoft_fmf_download_count' => array(
					'key'     => '_isoft_fmf_download_count',
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => '_isoft_fmf_download_count',
					'compare' => 'NOT EXISTS',
				),
			);
			$query->set( 'meta_query', $meta_query );
			// A sort the editor chose: featured downloads are not pinned on top.
			$query->set( 'isoft_fmf_featured_first', false );
		}

		$format = self::requested_format();
		$kind   = self::requested_kind();
		if ( '' !== $format || '' !== $kind ) {
			$query->set(
				self::FILE_FILTER_VAR,
				array(
					'format' => $format,
					'kind'   => $kind,
				)
			);
		}
	}

	/**
	 * Limits a query carrying FILE_FILTER_VAR to downloads with at least one
	 * file of that format and kind (both on the same file).
	 *
	 * @param string   $where The WHERE clause.
	 * @param WP_Query $query The query.
	 */
	public function filter_by_files( string $where, WP_Query $query ): string {
		$filter = $query->get( self::FILE_FILTER_VAR );
		if ( ! is_array( $filter ) ) {
			return $where;
		}
		$format = self::sanitize_format( (string) ( $filter['format'] ?? '' ) );
		$kind   = self::sanitize_kind( (string) ( $filter['kind'] ?? '' ) );
		if ( '' === $format && '' === $kind ) {
			return $where;
		}

		global $wpdb;
		$conditions = array();
		if ( '' !== $kind ) {
			$conditions[] = $wpdb->prepare( 'file_type = %s', $kind );
		}
		if ( '' !== $format ) {
			$conditions[] = $wpdb->prepare( 'LOWER(file_name) LIKE %s', '%.' . $wpdb->esc_like( $format ) );
		}
		return $where . " AND {$wpdb->posts}.ID IN ( SELECT download_id FROM {$wpdb->prefix}isoft_fmf_files WHERE " . implode( ' AND ', $conditions ) . ' )';
	}

	/**
	 * File formats (lower-case extensions) present in the library, sorted.
	 *
	 * @return string[]
	 */
	public static function formats(): array {
		$cached = wp_cache_get( 'formats', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; cached above for five minutes.
		$rows    = $wpdb->get_col( "SELECT DISTINCT LOWER(SUBSTRING_INDEX(file_name, '.', -1)) FROM {$wpdb->prefix}isoft_fmf_files WHERE file_name LIKE '%.%'" );
		$formats = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_format' ), (array) $rows ) ) ) );
		sort( $formats );
		wp_cache_set( 'formats', $formats, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $formats;
	}

	/** A file extension, or '' when it is not a plain short extension. */
	public static function sanitize_format( string $value ): string {
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^[a-z0-9]{1,10}$/', $value ) ? $value : '';
	}

	/** One of KINDS, or ''. */
	public static function sanitize_kind( string $value ): string {
		return in_array( $value, self::KINDS, true ) ? $value : '';
	}

	private static function requested_format(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; validated against a strict pattern.
		return isset( $_GET[ self::FORMAT_PARAM ] ) ? self::sanitize_format( sanitize_text_field( wp_unslash( $_GET[ self::FORMAT_PARAM ] ) ) ) : '';
	}

	private static function requested_kind(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; checked against a fixed list.
		return isset( $_GET[ self::KIND_PARAM ] ) ? self::sanitize_kind( sanitize_text_field( wp_unslash( $_GET[ self::KIND_PARAM ] ) ) ) : '';
	}
}
