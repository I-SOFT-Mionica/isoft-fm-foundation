<?php
/**
 * TinyMCE / Classic Editor integration.
 *
 * Adds a "Insert Download [iD]" toolbar button that opens a search modal
 * and inserts [isoft_fmf_download id=X] into the post content.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Tinymce {

	public function register_hooks(): void {
		// Only load in the admin for users who can edit posts.
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'mce_external_plugins', array( $this, 'add_plugin' ) );
		add_filter( 'mce_buttons', array( $this, 'add_button' ) );
		add_action( 'admin_footer', array( $this, 'render_modal' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_isoft_fmf_tmce_search', array( $this, 'ajax_search' ) );
	}

	public function add_plugin( array $plugins ): array {
		$plugins['isoft_fmf_insert'] = ISOFT_FMF_PLUGIN_URL . 'admin/js/tinymce-plugin.js?v=' . ISOFT_FMF_VERSION;
		return $plugins;
	}

	public function add_button( array $buttons ): array {
		$buttons[] = 'isoft_fmf_insert';
		return $buttons;
	}

	public function enqueue_assets(): void {
		$screen = get_current_screen();
		// Load only on post-editing screens.
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
			return;
		}
		wp_enqueue_style(
			'isoft-fmf-tinymce-modal',
			ISOFT_FMF_PLUGIN_URL . 'admin/css/tinymce-modal.css',
			array(),
			ISOFT_FMF_VERSION
		);

		// Data-only script handle: src=false tells WP this is a placeholder we
		// only use to attach wp_localize_script() data — no JS file fetched.
		// The TinyMCE plugin (loaded via mce_external_plugins) reads window.ISFMTmce
		// at init time.
		wp_register_script( 'isoft-fmf-tinymce-config', false, array(), ISOFT_FMF_VERSION, true );
		wp_enqueue_script( 'isoft-fmf-tinymce-config' );
		wp_localize_script(
			'isoft-fmf-tinymce-config',
			'ISFMTmce',
			array(
				'nonce'   => wp_create_nonce( 'isoft_fmf_tmce_search' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'insertDownload' => __( 'Insert Download [iD]', 'isoft-fm-foundation' ),
					'loading'        => __( 'Loading…', 'isoft-fm-foundation' ),
					'loadError'      => __( 'Error loading results.', 'isoft-fm-foundation' ),
				),
			)
		);
	}

	/**
	 * Render the hidden search modal into the admin footer.
	 * JS shows/hides it; results are fetched via AJAX.
	 */
	public function render_modal(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
			return;
		}

		$categories = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'fields'     => 'id=>name',
			)
		);
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		?>
		<div id="isoft-fmf-tmce-modal" class="isoft-fmf-tmce-modal" hidden>
			<div class="isoft-fmf-tmce-modal__backdrop"></div>
			<div class="isoft-fmf-tmce-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Insert Download', 'isoft-fm-foundation' ); ?>">

				<div class="isoft-fmf-tmce-modal__header">
					<h2 class="isoft-fmf-tmce-modal__title">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Insert Download [iD]', 'isoft-fm-foundation' ); ?>
					</h2>
					<button type="button" class="isoft-fmf-tmce-modal__close" aria-label="<?php esc_attr_e( 'Close', 'isoft-fm-foundation' ); ?>">&#x2715;</button>
				</div>

				<div class="isoft-fmf-tmce-modal__filters">
					<input
						type="search"
						id="isoft-fmf-tmce-search"
						class="isoft-fmf-tmce-modal__search"
						placeholder="<?php esc_attr_e( 'Search downloads…', 'isoft-fm-foundation' ); ?>"
						autocomplete="off"
					/>
					<select id="isoft-fmf-tmce-category" class="isoft-fmf-tmce-modal__category">
						<option value="0"><?php esc_html_e( 'All categories', 'isoft-fm-foundation' ); ?></option>
						<?php foreach ( $categories as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div id="isoft-fmf-tmce-results" class="isoft-fmf-tmce-modal__results">
					<p class="isoft-fmf-tmce-modal__hint"><?php esc_html_e( 'Loading…', 'isoft-fm-foundation' ); ?></p>
				</div>

				<div class="isoft-fmf-tmce-modal__footer">
					<span class="isoft-fmf-tmce-modal__hint"><?php esc_html_e( 'Click a download to insert it as a card.', 'isoft-fm-foundation' ); ?></span>
					<button type="button" class="button isoft-fmf-tmce-modal__cancel">
						<?php esc_html_e( 'Cancel', 'isoft-fm-foundation' ); ?>
					</button>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: search downloads and return HTML rows.
	 */
	public function ajax_search(): void {
		check_ajax_referer( 'isoft_fmf_tmce_search', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( '', 403 );
		}

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;

		$args = array(
			'post_type'      => 'isoft_fmf_file',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'no_found_rows'  => true,
		);

		if ( $search ) {
			$args['s'] = $search;
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		if ( $category > 0 ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for category filtering; term_relationships index covers this access pattern.
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'isoft_fmf_category',
					'field'    => 'term_id',
					'terms'    => $category,
				),
			);
		}

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			wp_send_json_success( array( 'html' => '<p class="isoft-fmf-tmce-modal__empty">' . esc_html__( 'No downloads found.', 'isoft-fm-foundation' ) . '</p>' ) );
		}

		ob_start();
		echo '<ul class="isoft-fmf-tmce-modal__list">';
		foreach ( $posts as $post ) {
			$cats = wp_get_post_terms( $post->ID, 'isoft_fmf_category', array( 'fields' => 'names' ) );
			$cat  = ! is_wp_error( $cats ) && ! empty( $cats ) ? $cats[0] : '';
			echo '<li>';
			echo '<button type="button" class="isoft-fmf-tmce-modal__item" data-id="' . esc_attr( $post->ID ) . '" data-title="' . esc_attr( $post->post_title ) . '">';
			echo '<span class="dashicons dashicons-media-default"></span>';
			echo '<span class="isoft-fmf-tmce-modal__item-title">' . esc_html( $post->post_title ) . '</span>';
			if ( $cat ) {
				echo '<span class="isoft-fmf-tmce-modal__item-cat">' . esc_html( $cat ) . '</span>';
			}
			echo '</button>';
			echo '</li>';
		}
		echo '</ul>';

		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}
}
