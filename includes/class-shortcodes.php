<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Shortcodes {

	public function register_hooks(): void {
		add_shortcode( 'isoft_fmf_list', array( $this, 'list_shortcode' ) );
		add_shortcode( 'isoft_fmf_categories', array( $this, 'categories_shortcode' ) );
		add_shortcode( 'isoft_fmf_download', array( $this, 'download_shortcode' ) );
		add_shortcode( 'isoft_fmf_button', array( $this, 'button_shortcode' ) );
		add_shortcode( 'isoft_fmf_count', array( $this, 'count_shortcode' ) );
		add_shortcode( 'isoft_fmf_search', array( $this, 'search_shortcode' ) );
		add_shortcode( 'isoft_fmf_recent', array( $this, 'recent_shortcode' ) );
		add_shortcode( 'isoft_fmf_popular', array( $this, 'popular_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_agree_modal' ) );
	}

	// -------------------------------------------------------------------------
	// Asset enqueue
	// -------------------------------------------------------------------------

	public function enqueue_assets(): void {
		if ( ! $this->page_needs_assets() ) {
			return;
		}

		// Dashicons aren't auto-loaded on the frontend; the card template uses
		// them for date / size / count / download glyphs. Without this,
		// dashicon spans render as blank squares on themes that don't
		// happen to enqueue dashicons themselves.
		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'isoft-fmf-public',
			ISOFT_FMF_PLUGIN_URL . 'public/css/public-style.css',
			array( 'dashicons' ),
			ISOFT_FMF_VERSION
		);
		wp_enqueue_script(
			'isoft-fmf-public',
			ISOFT_FMF_PLUGIN_URL . 'public/js/public-script.js',
			array(),
			ISOFT_FMF_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'isoft-fmf-public',
			'ISFMPublic',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'agreeLabel'  => __( 'I have read and agree to the terms', 'isoft-fm-foundation' ),
					'agreeButton' => __( 'Download', 'isoft-fm-foundation' ),
					'cancel'      => __( 'Cancel', 'isoft-fm-foundation' ),
				),
			)
		);
	}

	/**
	 * True when the current page renders any plugin content (shortcode,
	 * block, single download, or download archive/taxonomy). Lets us
	 * skip the public CSS/JS on every other page.
	 */
	private function page_needs_assets(): bool {
		if ( is_singular( 'isoft_fmf_file' )
			|| is_post_type_archive( 'isoft_fmf_file' )
			|| is_tax( array( 'isoft_fmf_category', 'isoft_fmf_tag' ) ) ) {
			return true;
		}

		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post ) {
			return false;
		}

		$shortcodes = array( 'isoft_fmf_list', 'isoft_fmf_categories', 'isoft_fmf_download', 'isoft_fmf_button', 'isoft_fmf_count', 'isoft_fmf_search', 'isoft_fmf_recent', 'isoft_fmf_popular' );
		foreach ( $shortcodes as $tag ) {
			if ( has_shortcode( $post->post_content, $tag ) ) {
				return true;
			}
		}

		$blocks = array( 'isoft-fm-foundation/download-list', 'isoft-fm-foundation/download-button', 'isoft-fm-foundation/category-grid' );
		foreach ( $blocks as $block_name ) {
			if ( has_block( $block_name, $post ) ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Agreement modal shell — output once in footer
	// -------------------------------------------------------------------------

	public function render_agree_modal(): void {
		?>
		<div id="isoft-fmf-agree-overlay" class="isoft-fmf-modal-overlay" hidden aria-modal="true" role="dialog">
			<div class="isoft-fmf-modal">
				<h3 id="isoft-fmf-agree-title" class="isoft-fmf-modal__title"></h3>
				<div id="isoft-fmf-agree-body" class="isoft-fmf-modal__license-text"></div>
				<p>
					<label>
						<input type="checkbox" id="isoft-fmf-agree-checkbox" />
						<?php esc_html_e( 'I have read and agree to the terms', 'isoft-fm-foundation' ); ?>
					</label>
				</p>
				<p class="isoft-fmf-modal__actions">
					<a id="isoft-fmf-agree-proceed" href="#" class="wp-element-button isoft-fmf-download-btn" aria-disabled="true">
						<?php esc_html_e( 'Download', 'isoft-fm-foundation' ); ?>
					</a>
					<button type="button" id="isoft-fmf-agree-cancel" class="button">
						<?php esc_html_e( 'Cancel', 'isoft-fm-foundation' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_list category="" tag="" limit="10" orderby="date" order="DESC" layout="" show_search="0"]
	// -------------------------------------------------------------------------

	public function list_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'              => '',
				'include_subcategories' => '1',
				'tag'                   => '',
				'limit'                 => isoft_fmf_get_settings()['items_per_page'],
				'orderby'               => 'date',
				'order'                 => 'DESC',
				'layout'                => '',
				'show_search'           => '0',
			),
			$atts,
			'isoft_fmf_list'
		);

		$settings = isoft_fmf_get_settings();
		$layout   = $atts['layout'] ?: $settings['listing_layout'];

		$query_args = array(
			'post_type'      => 'isoft_fmf_file',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $atts['limit'] ),
			'orderby'        => sanitize_key( $atts['orderby'] ),
			'order'          => 'ASC' === strtoupper( $atts['order'] ) ? 'ASC' : 'DESC',
			'paged'          => max( 1, get_query_var( 'paged' ) ),
		);

		// Category filter
		if ( $atts['category'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for category filtering; term_relationships index covers this access pattern.
			$query_args['tax_query'][] = array(
				'taxonomy'         => 'isoft_fmf_category',
				'field'            => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
				'terms'            => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_text_field( $atts['category'] ),
				'include_children' => filter_var( $atts['include_subcategories'], FILTER_VALIDATE_BOOLEAN ),
			);
		}

		// Tag filter
		if ( $atts['tag'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for tag filtering; term_relationships index covers this access pattern.
			$query_args['tax_query'][] = array(
				'taxonomy' => 'isoft_fmf_tag',
				'field'    => is_numeric( $atts['tag'] ) ? 'term_id' : 'slug',
				'terms'    => is_numeric( $atts['tag'] ) ? absint( $atts['tag'] ) : sanitize_text_field( $atts['tag'] ),
			);
		}

		// Order by download count (stored in post meta)
		if ( 'download_count' === $atts['orderby'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for popularity ordering; postmeta index covers this access pattern.
			$query_args['meta_key'] = '_isoft_fmf_download_count';
			$query_args['orderby']  = 'meta_value_num';
		}

		$query_args = apply_filters( 'isoft_fmf_listing_query_args', $query_args, $atts );
		$query      = new WP_Query( $query_args );

		ob_start();

		if ( filter_var( $atts['show_search'], FILTER_VALIDATE_BOOLEAN ) ) {
			echo wp_kses( $this->search_shortcode( array( 'category' => $atts['category'] ) ), self::allowed_html() );
		}

		if ( ! $query->have_posts() ) {
			echo '<p class="isoft-fmf-no-downloads">' . esc_html__( 'No downloads found.', 'isoft-fm-foundation' ) . '</p>';
		} else {
			$grid_class = 'grid' === $layout ? 'isoft-fmf-grid isoft-fmf-grid--cols-3' : 'isoft-fmf-download-list';
			echo '<div class="isoft-fmf-list-wrap isoft-fmf-layout--' . esc_attr( $layout ) . '">';

			if ( 'table' === $layout ) {
				$this->render_table_layout( $query, $settings );
			} else {
				echo '<div class="' . esc_attr( $grid_class ) . '">';
				while ( $query->have_posts() ) {
					$query->the_post();
					$post = get_post();
					$this->render_template( 'download-card', compact( 'post', 'settings' ) );
				}
				echo '</div>';
			}

			wp_reset_postdata();

			// paginate_links() returns null when only one page exists; PHP
			// 8.1+ deprecates passing null to wp_kses_post. Cast to string.
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'total'   => $query->max_num_pages,
						'current' => max( 1, get_query_var( 'paged' ) ),
					)
				)
			);

			echo '</div>';
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_categories parent="0" columns="3" show_count="1" show_description="1"]
	// -------------------------------------------------------------------------

	public function categories_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'parent'           => 0,
				'columns'          => 3,
				'show_count'       => '1',
				'show_description' => '1',
			),
			$atts,
			'isoft_fmf_categories'
		);

		// Two native get_terms() calls instead of one with mixed clauses:
		// WP_Term_Query takes a single scalar orderby, and combining
		// `orderby=meta_value_num` + `meta_key` + a meta_query OR clause
		// to coax a LEFT JOIN produces version-dependent behaviour that
		// silently filtered out terms without the sort meta (root cause
		// of "No categories found" on a default Category Grid block).
		// (A) terms with `_isoft_fmf_cat_sort_order` set, ordered by it
		// in MySQL; (B) terms without the meta, ordered by name. Concat.
		$parent = absint( $atts['parent'] );

		$ordered_terms = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'parent'     => $parent,
				'hide_empty' => false,
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom category ordering on termmeta; small per-level result sets.
				'meta_key'   => '_isoft_fmf_cat_sort_order',
			)
		);

		$unordered_terms = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'parent'     => $parent,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Custom category ordering on termmeta; small per-level result sets.
				'meta_query' => array(
					array(
						'key'     => '_isoft_fmf_cat_sort_order',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$terms = array_merge(
			is_array( $ordered_terms ) ? $ordered_terms : array(),
			is_array( $unordered_terms ) ? $unordered_terms : array()
		);

		if ( empty( $terms ) ) {
			return '<p class="isoft-fmf-no-categories">' . esc_html__( 'No categories found.', 'isoft-fm-foundation' ) . '</p>';
		}

		$columns    = min( 4, max( 1, absint( $atts['columns'] ) ) );
		$show_count = filter_var( $atts['show_count'], FILTER_VALIDATE_BOOLEAN );
		$show_desc  = filter_var( $atts['show_description'], FILTER_VALIDATE_BOOLEAN );

		ob_start();
		echo '<div class="isoft-fmf-category-grid isoft-fmf-grid isoft-fmf-grid--cols-' . esc_attr( $columns ) . '">';
		foreach ( $terms as $term ) {
			$this->render_category_card( $term, $show_count, $show_desc );
		}
		echo '</div>';
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_download id="" show_description="1" show_files="1" style="card"]
	// -------------------------------------------------------------------------

	public function download_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'               => 0,
				'show_description' => '1',
				'show_files'       => '1',
				'style'            => 'card', // card | compact | button-only
			),
			$atts,
			'isoft_fmf_download'
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post || 'isoft_fmf_file' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$access = new ISOFT_FMF_Access_Control();
		if ( ! $access->can_access_download( $post_id ) ) {
			if ( ! is_user_logged_in() ) {
				return '';
			}
			return '<p class="isoft-fmf-restricted">' . esc_html__( 'You do not have permission to view this download.', 'isoft-fm-foundation' ) . '</p>';
		}

		$settings = isoft_fmf_get_settings();
		$files    = ( new ISOFT_FMF_File_Manager() )->get_files( $post_id );

		if ( 'button-only' === $atts['style'] ) {
			$first_file = $files[0] ?? null;
			if ( ! $first_file ) {
				return '';
			}
			return $this->render_download_button( $first_file, $post_id );
		}

		ob_start();

		if ( 'compact' === $atts['style'] ) {
			echo '<div class="isoft-fmf-download-embed isoft-fmf-download-embed--compact">';
			echo '<strong>' . esc_html( get_the_title( $post_id ) ) . '</strong>';
			if ( ! empty( $files ) ) {
				echo ' <span class="isoft-fmf-meta">' . esc_html(
					sprintf(
						/* translators: %d: number of files attached to the download */
						_n( '%d file', '%d files', count( $files ), 'isoft-fm-foundation' ),
						count( $files )
					)
				) . '</span>';
			}
			$first_file = $files[0] ?? null;
			if ( $first_file && $access->can_access_download( $post_id ) ) {
				echo ' ' . wp_kses( $this->render_download_button( $first_file, $post_id ), self::allowed_html() );
			}
			echo '</div>';
		} else {
			// Full card
			$this->render_template( 'download-card', compact( 'post', 'settings' ) );
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_button file_id="" text="Download" class="" style=""]
	// -------------------------------------------------------------------------

	public function button_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'file_id' => 0,
				'text'    => __( 'Download', 'isoft-fm-foundation' ),
				'class'   => '',
			),
			$atts,
			'isoft_fmf_button'
		);

		$file_id = absint( $atts['file_id'] );
		if ( ! $file_id ) {
			return '';
		}

		$file = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
		if ( ! $file ) {
			return '';
		}

		$access = new ISOFT_FMF_Access_Control();
		if ( ! $access->can_access_download( (int) $file->download_id ) ) {
			if ( ! is_user_logged_in() ) {
				return '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="wp-element-button isoft-fmf-download-btn">'
					. esc_html__( 'Login to Download', 'isoft-fm-foundation' ) . '</a>';
			}
			return '<span class="isoft-fmf-download-btn isoft-fmf-download-btn--restricted">'
				. esc_html__( 'Restricted', 'isoft-fm-foundation' ) . '</span>';
		}

		return $this->render_download_button( $file, (int) $file->download_id, sanitize_text_field( $atts['text'] ), sanitize_html_class( $atts['class'] ) );
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_count id="" file_id="" format="%s downloads"]
	// -------------------------------------------------------------------------

	public function count_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => 0,   // download post ID
				'file_id' => 0,   // specific file ID
				'format'  => '%s',
			),
			$atts,
			'isoft_fmf_count'
		);

		$count       = 0;
		$access      = new ISOFT_FMF_Access_Control();
		$download_id = 0;

		if ( absint( $atts['file_id'] ) ) {
			$file        = ( new ISOFT_FMF_File_Manager() )->get_file( absint( $atts['file_id'] ) );
			$count       = $file ? (int) $file->download_count : 0;
			$download_id = $file ? (int) $file->download_id : 0;
		} elseif ( absint( $atts['id'] ) ) {
			$download_id = absint( $atts['id'] );
			$count       = (int) get_post_meta( $download_id, '_isoft_fmf_download_count', true );
		}

		if ( $download_id && ! $access->can_access_download( $download_id ) ) {
			return '';
		}

		return esc_html( sprintf( $atts['format'], number_format_i18n( $count ) ) );
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_search category="" placeholder="Search downloads…"]
	// -------------------------------------------------------------------------

	public function search_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'    => '',
				'placeholder' => __( 'Search downloads…', 'isoft-fm-foundation' ),
			),
			$atts,
			'isoft_fmf_search'
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public search form, no state change.
		$search_term = isset( $_GET['isoft_fmf_s'] ) ? sanitize_text_field( wp_unslash( $_GET['isoft_fmf_s'] ) ) : '';

		ob_start();
		?>
		<div class="isoft-fmf-search-wrap">
			<form class="isoft-fmf-search-form" method="get" action="<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>">
				<?php if ( $atts['category'] ) : ?>
					<input type="hidden" name="isoft_fmf_cat" value="<?php echo esc_attr( $atts['category'] ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="isoft-fmf-search-input"><?php esc_html_e( 'Search downloads', 'isoft-fm-foundation' ); ?></label>
				<input
					type="search"
					id="isoft-fmf-search-input"
					name="isoft_fmf_s"
					class="isoft-fmf-search-input"
					value="<?php echo esc_attr( $search_term ); ?>"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
				/>
				<button type="submit" class="button isoft-fmf-search-btn">
					<span class="dashicons dashicons-search"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Search', 'isoft-fm-foundation' ); ?></span>
				</button>
			</form>

			<?php if ( $search_term ) : ?>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public search form, no state change.
				$cat_filter = isset( $_GET['isoft_fmf_cat'] ) ? sanitize_text_field( wp_unslash( $_GET['isoft_fmf_cat'] ) ) : $atts['category'];
				$query_args = array(
					'post_type'      => 'isoft_fmf_file',
					'post_status'    => 'publish',
					'posts_per_page' => (int) isoft_fmf_get_settings()['items_per_page'],
					's'              => $search_term,
				);
				if ( $cat_filter ) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for category filtering; term_relationships index covers this access pattern.
					$query_args['tax_query'] = array(
						array(
							'taxonomy' => 'isoft_fmf_category',
							'field'    => is_numeric( $cat_filter ) ? 'term_id' : 'slug',
							'terms'    => is_numeric( $cat_filter ) ? absint( $cat_filter ) : $cat_filter,
						),
					);
				}
				$query    = new WP_Query( $query_args );
				$settings = isoft_fmf_get_settings();
				?>
				<div class="isoft-fmf-search-results">
					<p class="isoft-fmf-search-count">
						<?php
						printf(
							/* translators: %1$s: search term, %2$d: result count */
							esc_html( _n( '%2$d result for "%1$s"', '%2$d results for "%1$s"', $query->found_posts, 'isoft-fm-foundation' ) ),
							esc_html( $search_term ),
							(int) $query->found_posts
						);
						?>
					</p>
					<?php if ( $query->have_posts() ) : ?>
					<div class="isoft-fmf-grid isoft-fmf-grid--cols-1">
						<?php
						while ( $query->have_posts() ) :
							$query->the_post();
							$post = get_post();
							$this->render_template( 'download-card', compact( 'post', 'settings' ) );
						endwhile;
						?>
					</div>
						<?php wp_reset_postdata(); ?>
					<?php else : ?>
					<p><?php esc_html_e( 'No downloads found.', 'isoft-fm-foundation' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_recent limit="5" days="30" category=""]
	// -------------------------------------------------------------------------

	public function recent_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'    => 5,
				'days'     => 0,
				'category' => '',
			),
			$atts,
			'isoft_fmf_recent'
		);

		$query_args = array(
			'post_type'      => 'isoft_fmf_file',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $atts['limit'] ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( absint( $atts['days'] ) ) {
			$query_args['date_query'] = array(
				array(
					'after'     => absint( $atts['days'] ) . ' days ago',
					'inclusive' => true,
				),
			);
		}

		if ( $atts['category'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for category filtering; term_relationships index covers this access pattern.
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'isoft_fmf_category',
					'field'    => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_text_field( $atts['category'] ),
				),
			);
		}

		return $this->render_query_as_cards( $query_args );
	}

	// -------------------------------------------------------------------------
	// [isoft_fmf_popular limit="5" period="all" category=""]
	// -------------------------------------------------------------------------

	public function popular_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'    => 5,
				'period'   => 'all', // all | 30d | 7d
				'category' => '',
			),
			$atts,
			'isoft_fmf_popular'
		);

		$query_args = array(
			'post_type'      => 'isoft_fmf_file',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $atts['limit'] ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for popularity ordering; postmeta index covers this access pattern.
			'meta_key'       => '_isoft_fmf_download_count',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		);

		// For period filtering we'd need to query the log table directly —
		// for now 'all' is supported; 30d/7d falls back to all-time.
		// TODO: period-based ranking via sub-query in Phase 4.

		if ( $atts['category'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for category filtering; term_relationships index covers this access pattern.
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'isoft_fmf_category',
					'field'    => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_text_field( $atts['category'] ),
				),
			);
		}

		return $this->render_query_as_cards( $query_args );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function render_query_as_cards( array $query_args ): string {
		$query    = new WP_Query( $query_args );
		$settings = isoft_fmf_get_settings();

		if ( ! $query->have_posts() ) {
			return '<p class="isoft-fmf-no-downloads">' . esc_html__( 'No downloads found.', 'isoft-fm-foundation' ) . '</p>';
		}

		ob_start();
		echo '<div class="isoft-fmf-grid isoft-fmf-grid--cols-1">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$post = get_post();
			$this->render_template( 'download-card', compact( 'post', 'settings' ) );
		}
		echo '</div>';
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Render a plugin template, injecting variables into scope.
	 */
	private function render_template( string $name, array $vars = array() ): void {
		$path = ISOFT_FMF_PLUGIN_DIR . 'public/views/' . $name . '.php';
		if ( ! file_exists( $path ) ) {
			return;
		}
		// Inject variables without extract() — assign each explicitly
		foreach ( $vars as $key => $value ) {
			$$key = $value;
		}
		require $path;
	}

	/**
	 * Render a single download button, with agree-modal support.
	 */
	private function render_download_button( object $file, int $download_id, string $text = '', string $extra_class = '' ): string {
		$default_text  = isoft_fmf_get_settings()['default_button_text'] ?: __( 'Download', 'isoft-fm-foundation' );
		$text          = $text ?: $default_text;
		$require_agree = (bool) get_post_meta( $download_id, '_isoft_fmf_require_agree', true );
		$url           = isoft_fmf_get_download_url( (int) $file->id );
		$class         = trim( 'wp-element-button isoft-fmf-download-btn ' . $extra_class );

		if ( 'external' === $file->file_type ) {
			$url = esc_url( $file->external_url );
		}

		if ( $require_agree ) {
			$license_id = (int) get_post_meta( $download_id, '_isoft_fmf_license_id', true );
			$license    = $license_id ? ( new ISOFT_FMF_License_Manager() )->get( $license_id ) : null;
			// Cast to string on BOTH branches — see note in download-card.php at
			// the matching wp_kses_post call. Nullable LONGTEXT in the
			// licenses table; without the cast a NULL full_text triggers a
			// PHP 8.1+ deprecation warning on the frontend.
			$agree_text  = $license ? wp_kses_post( (string) $license->full_text ) : wp_kses_post( (string) get_post_meta( $download_id, '_isoft_fmf_agree_text', true ) );
			$agree_title = $license ? esc_html( $license->title ) : esc_html( get_the_title( $download_id ) );

			// Hidden div holds the agreement content for the modal JS to pick up
			$hidden_id = 'isoft-fmf-agree-content-' . (int) $file->id;

			return '<div class="isoft-fmf-agree-wrap">'
				. '<div id="' . esc_attr( $hidden_id ) . '" class="isoft-fmf-agree-content" hidden>'
				. $agree_text
				. '</div>'
				. '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . ' isoft-fmf-requires-agree"'
				. ' data-agree-content="' . esc_attr( '#' . $hidden_id ) . '"'
				. ' data-agree-title="' . $agree_title . '">'
				. '<span class="dashicons dashicons-download"></span>'
				. esc_html( $text )
				. '</a></div>';
		}

		// `download` + `rel="nofollow"` on direct download links signal to
		// click-interceptors (djax, pjax, swup, hotwire-turbo) that this isn't
		// a page navigation. Without them, themes that ajax-hijack every <a>
		// click feed the binary file body back into jQuery's HTML parser and
		// the download silently fails. External links don't get `download`
		// (browsers ignore it cross-origin) and instead respect the admin's
		// "External link target" preference for same-tab vs new-tab.
		$is_external = 'external' === $file->file_type;
		if ( $is_external ) {
			$ext_target  = get_option( 'isoft_fmf_external_link_target', '_blank' );
			$extra_attrs = '_blank' === $ext_target
				? ' target="_blank" rel="noopener nofollow"'
				: ' rel="nofollow"';
		} else {
			$extra_attrs = ' download rel="nofollow"';
		}

		return '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '"' . $extra_attrs . '>'
			. '<span class="dashicons dashicons-download"></span>'
			. esc_html( $text )
			. '</a>';
	}

	private function render_category_card( WP_Term $term, bool $show_count, bool $show_desc ): void {
		echo '<div class="isoft-fmf-category-card">';
		echo '<div class="isoft-fmf-category-card__icon">';
		isoft_fmf_render_category_icon( (int) $term->term_id );
		echo '</div>';

		echo '<h3 class="isoft-fmf-category-card__title"><a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></h3>';

		if ( $show_desc && $term->description ) {
			echo '<div class="isoft-fmf-category-card__desc">' . esc_html( wp_trim_words( $term->description, 20 ) ) . '</div>';
		}

		if ( $show_count ) {
			echo '<div class="isoft-fmf-category-card__meta"><span class="isoft-fmf-meta">';
			printf(
				/* translators: %d: number of downloads in the category */
				esc_html( _n( '%d download', '%d downloads', $term->count, 'isoft-fm-foundation' ) ),
				(int) $term->count
			);
			echo '</span></div>';
		}

		echo '</div>';
	}

	private function render_table_layout( WP_Query $query, array $settings ): void {
		echo '<table class="isoft-fmf-file-list"><thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'isoft-fm-foundation' ) . '</th>';
		if ( $settings['show_file_size'] ) {
			echo '<th>' . esc_html__( 'Size', 'isoft-fm-foundation' ) . '</th>';
		}
		if ( $settings['show_download_count'] ) {
			echo '<th>' . esc_html__( 'Downloads', 'isoft-fm-foundation' ) . '</th>';
		}
		if ( $settings['show_date'] ) {
			echo '<th>' . esc_html__( 'Date', 'isoft-fm-foundation' ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID();
			$files   = ( new ISOFT_FMF_File_Manager() )->get_files( $post_id );
			$access  = new ISOFT_FMF_Access_Control();

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></td>';

			if ( $settings['show_file_size'] ) {
				$size = array_sum( array_column( (array) $files, 'file_size' ) );
				echo '<td>' . ( $size ? esc_html( size_format( $size ) ) : '—' ) . '</td>';
			}
			if ( $settings['show_download_count'] ) {
				echo '<td>' . esc_html( number_format_i18n( (int) get_post_meta( $post_id, '_isoft_fmf_download_count', true ) ) ) . '</td>';
			}
			if ( $settings['show_date'] ) {
				echo '<td>' . esc_html( get_the_date( $settings['date_format'] ) ) . '</td>';
			}

			echo '<td>';
			$first = $files[0] ?? null;
			if ( $first && $access->can_access_download( $post_id ) ) {
				echo wp_kses( $this->render_download_button( $first, $post_id ), self::allowed_html() );
			} elseif ( ! is_user_logged_in() ) {
				echo '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="wp-element-button isoft-fmf-download-btn">' . esc_html__( 'Login', 'isoft-fm-foundation' ) . '</a>';
			} else {
				echo '<span class="isoft-fmf-download-btn isoft-fmf-download-btn--restricted">' . esc_html__( 'Restricted', 'isoft-fm-foundation' ) . '</span>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * KSES allowlist for the HTML fragments that our shortcode methods
	 * build internally with escaping (search form + download button). Kept
	 * intentionally narrow.
	 */
	private static function allowed_html(): array {
		return array(
			'div'    => array( 'class' => true ),
			'span'   => array( 'class' => true ),
			'p'      => array( 'class' => true ),
			'form'   => array(
				'class'  => true,
				'method' => true,
				'action' => true,
			),
			'label'  => array(
				'class' => true,
				'for'   => true,
			),
			'input'  => array(
				'type'        => true,
				'name'        => true,
				'value'       => true,
				'id'          => true,
				'class'       => true,
				'placeholder' => true,
			),
			'button' => array(
				'type'  => true,
				'class' => true,
			),
			'a'      => array(
				'href'                  => true,
				'class'                 => true,
				'rel'                   => true,
				'target'                => true,
				'data-agree-content'    => true,
				'data-agree-title'      => true,
				'data-license-required' => true,
			),
		);
	}
}
