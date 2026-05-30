<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Shortcodes {

	public function register_hooks(): void {
		add_shortcode( 'isfm_list', array( $this, 'list_shortcode' ) );
		add_shortcode( 'isfm_categories', array( $this, 'categories_shortcode' ) );
		add_shortcode( 'isfm_download', array( $this, 'download_shortcode' ) );
		add_shortcode( 'isfm_button', array( $this, 'button_shortcode' ) );
		add_shortcode( 'isfm_count', array( $this, 'count_shortcode' ) );
		add_shortcode( 'isfm_search', array( $this, 'search_shortcode' ) );
		add_shortcode( 'isfm_recent', array( $this, 'recent_shortcode' ) );
		add_shortcode( 'isfm_popular', array( $this, 'popular_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_agree_modal' ) );
	}

	// -------------------------------------------------------------------------
	// Asset enqueue
	// -------------------------------------------------------------------------

	public function enqueue_assets(): void {
		wp_enqueue_style(
			'isfm-public',
			ISFM_PLUGIN_URL . 'public/css/public-style.css',
			array(),
			ISFM_VERSION
		);
		wp_enqueue_script(
			'isfm-public',
			ISFM_PLUGIN_URL . 'public/js/public-script.js',
			array(),
			ISFM_VERSION,
			true
		);
		wp_localize_script(
			'isfm-public',
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

	// -------------------------------------------------------------------------
	// Agreement modal shell — output once in footer
	// -------------------------------------------------------------------------

	public function render_agree_modal(): void {
		?>
		<div id="isfm-agree-overlay" class="isfm-modal-overlay" hidden aria-modal="true" role="dialog">
			<div class="isfm-modal">
				<h3 id="isfm-agree-title" class="isfm-modal__title"></h3>
				<div id="isfm-agree-body" class="isfm-modal__license-text"></div>
				<p>
					<label>
						<input type="checkbox" id="isfm-agree-checkbox" />
						<?php esc_html_e( 'I have read and agree to the terms', 'isoft-fm-foundation' ); ?>
					</label>
				</p>
				<p class="isfm-modal__actions">
					<a id="isfm-agree-proceed" href="#" class="wp-element-button isfm-download-btn" aria-disabled="true">
						<?php esc_html_e( 'Download', 'isoft-fm-foundation' ); ?>
					</a>
					<button type="button" id="isfm-agree-cancel" class="button">
						<?php esc_html_e( 'Cancel', 'isoft-fm-foundation' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// [isfm_list category="" tag="" limit="10" orderby="date" order="DESC" layout="" show_search="0"]
	// -------------------------------------------------------------------------

	public function list_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'              => '',
				'include_subcategories' => '1',
				'tag'                   => '',
				'limit'                 => isfm_get_settings()['items_per_page'],
				'orderby'               => 'date',
				'order'                 => 'DESC',
				'layout'                => '',
				'show_search'           => '0',
			),
			$atts,
			'isfm_list'
		);

		$settings = isfm_get_settings();
		$layout   = $atts['layout'] ?: $settings['listing_layout'];

		$query_args = array(
			'post_type'      => 'isfm_file',
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
				'taxonomy'         => 'isfm_category',
				'field'            => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
				'terms'            => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_text_field( $atts['category'] ),
				'include_children' => filter_var( $atts['include_subcategories'], FILTER_VALIDATE_BOOLEAN ),
			);
		}

		// Tag filter
		if ( $atts['tag'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for tag filtering; term_relationships index covers this access pattern.
			$query_args['tax_query'][] = array(
				'taxonomy' => 'isfm_tag',
				'field'    => is_numeric( $atts['tag'] ) ? 'term_id' : 'slug',
				'terms'    => is_numeric( $atts['tag'] ) ? absint( $atts['tag'] ) : sanitize_text_field( $atts['tag'] ),
			);
		}

		// Order by download count (stored in post meta)
		if ( 'download_count' === $atts['orderby'] ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for popularity ordering; postmeta index covers this access pattern.
			$query_args['meta_key'] = '_isfm_download_count';
			$query_args['orderby']  = 'meta_value_num';
		}

		$query_args = apply_filters( 'isfm_listing_query_args', $query_args, $atts );
		$query      = new WP_Query( $query_args );

		ob_start();

		if ( filter_var( $atts['show_search'], FILTER_VALIDATE_BOOLEAN ) ) {
			echo $this->search_shortcode( array( 'category' => $atts['category'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! $query->have_posts() ) {
			echo '<p class="isfm-no-downloads">' . esc_html__( 'No downloads found.', 'isoft-fm-foundation' ) . '</p>';
		} else {
			$grid_class = 'grid' === $layout ? 'isfm-grid isfm-grid--cols-3' : 'isfm-download-list';
			echo '<div class="isfm-list-wrap isfm-layout--' . esc_attr( $layout ) . '">';

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

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns safe HTML.
			echo paginate_links(
				array(
					'total'   => $query->max_num_pages,
					'current' => max( 1, get_query_var( 'paged' ) ),
				)
			);

			echo '</div>';
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [isfm_categories parent="0" columns="3" show_count="1" show_description="1"]
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
			'isfm_categories'
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'isfm_category',
				'parent'     => absint( $atts['parent'] ),
				'hide_empty' => false,
				'orderby'    => 'meta_value_num',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for custom category ordering; termmeta index covers this access pattern.
				'meta_key'   => '_isfm_cat_sort_order',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '<p class="isfm-no-categories">' . esc_html__( 'No categories found.', 'isoft-fm-foundation' ) . '</p>';
		}

		$columns    = min( 4, max( 1, absint( $atts['columns'] ) ) );
		$show_count = filter_var( $atts['show_count'], FILTER_VALIDATE_BOOLEAN );
		$show_desc  = filter_var( $atts['show_description'], FILTER_VALIDATE_BOOLEAN );

		ob_start();
		echo '<div class="isfm-category-grid isfm-grid isfm-grid--cols-' . esc_attr( $columns ) . '">';
		foreach ( $terms as $term ) {
			$this->render_category_card( $term, $show_count, $show_desc );
		}
		echo '</div>';
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [isfm_download id="" show_description="1" show_files="1" style="card"]
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
			'isfm_download'
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post || 'isfm_file' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$access = new ISFM_Access_Control();
		if ( ! $access->can_access_download( $post_id ) ) {
			if ( ! is_user_logged_in() ) {
				return '';
			}
			return '<p class="isfm-restricted">' . esc_html__( 'You do not have permission to view this download.', 'isoft-fm-foundation' ) . '</p>';
		}

		$settings = isfm_get_settings();
		$files    = ( new ISFM_File_Manager() )->get_files( $post_id );

		if ( 'button-only' === $atts['style'] ) {
			$first_file = $files[0] ?? null;
			if ( ! $first_file ) {
				return '';
			}
			return $this->render_download_button( $first_file, $post_id );
		}

		ob_start();

		if ( 'compact' === $atts['style'] ) {
			echo '<div class="isfm-download-embed isfm-download-embed--compact">';
			echo '<strong>' . esc_html( get_the_title( $post_id ) ) . '</strong>';
			if ( ! empty( $files ) ) {
				echo ' <span class="isfm-meta">' . esc_html(
					sprintf(
						/* translators: %d: number of files attached to the download */
						_n( '%d file', '%d files', count( $files ), 'isoft-fm-foundation' ),
						count( $files )
					)
				) . '</span>';
			}
			$first_file = $files[0] ?? null;
			if ( $first_file && $access->can_access_download( $post_id ) ) {
				echo ' ' . $this->render_download_button( $first_file, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		} else {
			// Full card
			$this->render_template( 'download-card', compact( 'post', 'settings' ) );
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [isfm_button file_id="" text="Download" class="" style=""]
	// -------------------------------------------------------------------------

	public function button_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'file_id' => 0,
				'text'    => __( 'Download', 'isoft-fm-foundation' ),
				'class'   => '',
			),
			$atts,
			'isfm_button'
		);

		$file_id = absint( $atts['file_id'] );
		if ( ! $file_id ) {
			return '';
		}

		$file = ( new ISFM_File_Manager() )->get_file( $file_id );
		if ( ! $file ) {
			return '';
		}

		$access = new ISFM_Access_Control();
		if ( ! $access->can_access_download( (int) $file->download_id ) ) {
			if ( ! is_user_logged_in() ) {
				return '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="wp-element-button isfm-download-btn">'
					. esc_html__( 'Login to Download', 'isoft-fm-foundation' ) . '</a>';
			}
			return '<span class="isfm-download-btn isfm-download-btn--restricted">'
				. esc_html__( 'Restricted', 'isoft-fm-foundation' ) . '</span>';
		}

		return $this->render_download_button( $file, (int) $file->download_id, sanitize_text_field( $atts['text'] ), sanitize_html_class( $atts['class'] ) );
	}

	// -------------------------------------------------------------------------
	// [isfm_count id="" file_id="" format="%s downloads"]
	// -------------------------------------------------------------------------

	public function count_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => 0,   // download post ID
				'file_id' => 0,   // specific file ID
				'format'  => '%s',
			),
			$atts,
			'isfm_count'
		);

		$count       = 0;
		$access      = new ISFM_Access_Control();
		$download_id = 0;

		if ( absint( $atts['file_id'] ) ) {
			$file        = ( new ISFM_File_Manager() )->get_file( absint( $atts['file_id'] ) );
			$count       = $file ? (int) $file->download_count : 0;
			$download_id = $file ? (int) $file->download_id : 0;
		} elseif ( absint( $atts['id'] ) ) {
			$download_id = absint( $atts['id'] );
			$count       = (int) get_post_meta( $download_id, '_isfm_download_count', true );
		}

		if ( $download_id && ! $access->can_access_download( $download_id ) ) {
			return '';
		}

		return esc_html( sprintf( $atts['format'], number_format_i18n( $count ) ) );
	}

	// -------------------------------------------------------------------------
	// [isfm_search category="" placeholder="Search downloads…"]
	// -------------------------------------------------------------------------

	public function search_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'    => '',
				'placeholder' => __( 'Search downloads…', 'isoft-fm-foundation' ),
			),
			$atts,
			'isfm_search'
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public search form, no state change.
		$search_term = isset( $_GET['isfm_s'] ) ? sanitize_text_field( wp_unslash( $_GET['isfm_s'] ) ) : '';

		ob_start();
		?>
		<div class="isfm-search-wrap">
			<form class="isfm-search-form" method="get" action="<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>">
				<?php if ( $atts['category'] ) : ?>
					<input type="hidden" name="isfm_cat" value="<?php echo esc_attr( $atts['category'] ); ?>" />
				<?php endif; ?>
				<label class="screen-reader-text" for="isfm-search-input"><?php esc_html_e( 'Search downloads', 'isoft-fm-foundation' ); ?></label>
				<input
					type="search"
					id="isfm-search-input"
					name="isfm_s"
					class="isfm-search-input"
					value="<?php echo esc_attr( $search_term ); ?>"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
				/>
				<button type="submit" class="button isfm-search-btn">
					<span class="dashicons dashicons-search"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Search', 'isoft-fm-foundation' ); ?></span>
				</button>
			</form>

			<?php if ( $search_term ) : ?>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public search form, no state change.
				$cat_filter = isset( $_GET['isfm_cat'] ) ? sanitize_text_field( wp_unslash( $_GET['isfm_cat'] ) ) : $atts['category'];
				$query_args = array(
					'post_type'      => 'isfm_file',
					'post_status'    => 'publish',
					'posts_per_page' => (int) isfm_get_settings()['items_per_page'],
					's'              => $search_term,
				);
				if ( $cat_filter ) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for category filtering; term_relationships index covers this access pattern.
					$query_args['tax_query'] = array(
						array(
							'taxonomy' => 'isfm_category',
							'field'    => is_numeric( $cat_filter ) ? 'term_id' : 'slug',
							'terms'    => is_numeric( $cat_filter ) ? absint( $cat_filter ) : $cat_filter,
						),
					);
				}
				$query    = new WP_Query( $query_args );
				$settings = isfm_get_settings();
				?>
				<div class="isfm-search-results">
					<p class="isfm-search-count">
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
					<div class="isfm-grid isfm-grid--cols-1">
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
	// [isfm_recent limit="5" days="30" category=""]
	// -------------------------------------------------------------------------

	public function recent_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'    => 5,
				'days'     => 0,
				'category' => '',
			),
			$atts,
			'isfm_recent'
		);

		$query_args = array(
			'post_type'      => 'isfm_file',
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
					'taxonomy' => 'isfm_category',
					'field'    => is_numeric( $atts['category'] ) ? 'term_id' : 'slug',
					'terms'    => is_numeric( $atts['category'] ) ? absint( $atts['category'] ) : sanitize_text_field( $atts['category'] ),
				),
			);
		}

		return $this->render_query_as_cards( $query_args );
	}

	// -------------------------------------------------------------------------
	// [isfm_popular limit="5" period="all" category=""]
	// -------------------------------------------------------------------------

	public function popular_shortcode( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'limit'    => 5,
				'period'   => 'all', // all | 30d | 7d
				'category' => '',
			),
			$atts,
			'isfm_popular'
		);

		$query_args = array(
			'post_type'      => 'isfm_file',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $atts['limit'] ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for popularity ordering; postmeta index covers this access pattern.
			'meta_key'       => '_isfm_download_count',
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
					'taxonomy' => 'isfm_category',
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
		$settings = isfm_get_settings();

		if ( ! $query->have_posts() ) {
			return '<p class="isfm-no-downloads">' . esc_html__( 'No downloads found.', 'isoft-fm-foundation' ) . '</p>';
		}

		ob_start();
		echo '<div class="isfm-grid isfm-grid--cols-1">';
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
		$path = ISFM_PLUGIN_DIR . 'public/views/' . $name . '.php';
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
		$default_text  = isfm_get_settings()['default_button_text'] ?: __( 'Download', 'isoft-fm-foundation' );
		$text          = $text ?: $default_text;
		$require_agree = (bool) get_post_meta( $download_id, '_isfm_require_agree', true );
		$url           = isfm_get_download_url( (int) $file->id );
		$class         = trim( 'wp-element-button isfm-download-btn ' . $extra_class );

		if ( 'external' === $file->file_type ) {
			$url = esc_url( $file->external_url );
		}

		if ( $require_agree ) {
			$license_id  = (int) get_post_meta( $download_id, '_isfm_license_id', true );
			$license     = $license_id ? ( new ISFM_License_Manager() )->get( $license_id ) : null;
			$agree_text  = $license ? wp_kses_post( $license->full_text ) : wp_kses_post( (string) get_post_meta( $download_id, '_isfm_agree_text', true ) );
			$agree_title = $license ? esc_html( $license->title ) : esc_html( get_the_title( $download_id ) );

			// Hidden div holds the agreement content for the modal JS to pick up
			$hidden_id = 'isfm-agree-content-' . (int) $file->id;

			return '<div class="isfm-agree-wrap">'
				. '<div id="' . esc_attr( $hidden_id ) . '" class="isfm-agree-content" hidden>'
				. $agree_text
				. '</div>'
				. '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . ' isfm-requires-agree"'
				. ' data-agree-content="' . esc_attr( '#' . $hidden_id ) . '"'
				. ' data-agree-title="' . $agree_title . '">'
				. '<span class="dashicons dashicons-download"></span>'
				. esc_html( $text )
				. '</a></div>';
		}

		return '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">'
			. '<span class="dashicons dashicons-download"></span>'
			. esc_html( $text )
			. '</a>';
	}

	private function render_category_card( WP_Term $term, bool $show_count, bool $show_desc ): void {
		$icon = get_term_meta( $term->term_id, '_isfm_cat_icon', true );
		echo '<div class="isfm-category-card">';

		if ( $icon ) {
			echo '<div class="isfm-category-card__icon">';
			if ( filter_var( $icon, FILTER_VALIDATE_URL ) ) {
				echo '<img src="' . esc_url( $icon ) . '" alt="" />';
			} else {
				echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
			}
			echo '</div>';
		}

		echo '<h3 class="isfm-category-card__title"><a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a></h3>';

		if ( $show_desc && $term->description ) {
			echo '<div class="isfm-category-card__desc">' . esc_html( wp_trim_words( $term->description, 20 ) ) . '</div>';
		}

		if ( $show_count ) {
			echo '<div class="isfm-category-card__meta"><span class="isfm-meta">';
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
		echo '<table class="isfm-file-list"><thead><tr>';
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
			$files   = ( new ISFM_File_Manager() )->get_files( $post_id );
			$access  = new ISFM_Access_Control();

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></td>';

			if ( $settings['show_file_size'] ) {
				$size = array_sum( array_column( (array) $files, 'file_size' ) );
				echo '<td>' . ( $size ? esc_html( size_format( $size ) ) : '—' ) . '</td>';
			}
			if ( $settings['show_download_count'] ) {
				echo '<td>' . esc_html( number_format_i18n( (int) get_post_meta( $post_id, '_isfm_download_count', true ) ) ) . '</td>';
			}
			if ( $settings['show_date'] ) {
				echo '<td>' . esc_html( get_the_date( $settings['date_format'] ) ) . '</td>';
			}

			echo '<td>';
			$first = $files[0] ?? null;
			if ( $first && $access->can_access_download( $post_id ) ) {
				echo $this->render_download_button( $first, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( ! is_user_logged_in() ) {
				echo '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="wp-element-button isfm-download-btn">' . esc_html__( 'Login', 'isoft-fm-foundation' ) . '</a>';
			} else {
				echo '<span class="isfm-download-btn isfm-download-btn--restricted">' . esc_html__( 'Restricted', 'isoft-fm-foundation' ) . '</span>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
