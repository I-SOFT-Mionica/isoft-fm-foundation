<?php
/**
 * TinyMCE / Classic Editor integration.
 *
 * Adds a "Insert Download [iD]" toolbar button that opens a search modal
 * and inserts [isfm_download id=X] into the post content.
 */
defined( 'ABSPATH' ) || exit;

class ISFM_Tinymce {

	public function register_hooks(): void {
		// Only load in the admin for users who can edit posts.
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'mce_external_plugins', array( $this, 'add_plugin' ) );
		add_filter( 'mce_buttons', array( $this, 'add_button' ) );
		add_action( 'admin_footer', array( $this, 'render_modal' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_isfm_tmce_search', array( $this, 'ajax_search' ) );
	}

	public function add_plugin( array $plugins ): array {
		$plugins['isfm_insert'] = ISFM_PLUGIN_URL . 'admin/js/tinymce-plugin.js?v=' . ISFM_VERSION;
		return $plugins;
	}

	public function add_button( array $buttons ): array {
		$buttons[] = 'isfm_insert';
		return $buttons;
	}

	public function enqueue_assets(): void {
		$screen = get_current_screen();
		// Load only on post-editing screens.
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
			return;
		}
		wp_enqueue_style(
			'isfm-tinymce-modal',
			ISFM_PLUGIN_URL . 'admin/css/tinymce-modal.css',
			array(),
			ISFM_VERSION
		);

		// Data-only script handle: src=false tells WP this is a placeholder we
		// only use to attach wp_localize_script() data — no JS file fetched.
		// The TinyMCE plugin (loaded via mce_external_plugins) reads window.ISFMTmce
		// at init time.
		wp_register_script( 'isfm-tinymce-config', false, array(), ISFM_VERSION, true );
		wp_enqueue_script( 'isfm-tinymce-config' );
		wp_localize_script(
			'isfm-tinymce-config',
			'ISFMTmce',
			array(
				'nonce'   => wp_create_nonce( 'isfm_tmce_search' ),
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
				'taxonomy'   => 'isfm_category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'fields'     => 'id=>name',
			)
		);
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		?>
		<div id="isfm-tmce-modal" class="isfm-tmce-modal" hidden>
			<div class="isfm-tmce-modal__backdrop"></div>
			<div class="isfm-tmce-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Insert Download', 'isoft-fm-foundation' ); ?>">

				<div class="isfm-tmce-modal__header">
					<h2 class="isfm-tmce-modal__title">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Insert Download [iD]', 'isoft-fm-foundation' ); ?>
					</h2>
					<button type="button" class="isfm-tmce-modal__close" aria-label="<?php esc_attr_e( 'Close', 'isoft-fm-foundation' ); ?>">&#x2715;</button>
				</div>

				<div class="isfm-tmce-modal__filters">
					<input
						type="search"
						id="isfm-tmce-search"
						class="isfm-tmce-modal__search"
						placeholder="<?php esc_attr_e( 'Search downloads…', 'isoft-fm-foundation' ); ?>"
						autocomplete="off"
					/>
					<select id="isfm-tmce-category" class="isfm-tmce-modal__category">
						<option value="0"><?php esc_html_e( 'All categories', 'isoft-fm-foundation' ); ?></option>
						<?php foreach ( $categories as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div id="isfm-tmce-results" class="isfm-tmce-modal__results">
					<p class="isfm-tmce-modal__hint"><?php esc_html_e( 'Loading…', 'isoft-fm-foundation' ); ?></p>
				</div>

				<div class="isfm-tmce-modal__footer">
					<span class="isfm-tmce-modal__hint"><?php esc_html_e( 'Click a download to insert it as a card.', 'isoft-fm-foundation' ); ?></span>
					<button type="button" class="button isfm-tmce-modal__cancel">
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
		check_ajax_referer( 'isfm_tmce_search', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( '', 403 );
		}

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;

		$args = array(
			'post_type'      => 'isfm_file',
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
					'taxonomy' => 'isfm_category',
					'field'    => 'term_id',
					'terms'    => $category,
				),
			);
		}

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			wp_send_json_success( array( 'html' => '<p class="isfm-tmce-modal__empty">' . esc_html__( 'No downloads found.', 'isoft-fm-foundation' ) . '</p>' ) );
		}

		ob_start();
		echo '<ul class="isfm-tmce-modal__list">';
		foreach ( $posts as $post ) {
			$cats = wp_get_post_terms( $post->ID, 'isfm_category', array( 'fields' => 'names' ) );
			$cat  = ! is_wp_error( $cats ) && ! empty( $cats ) ? $cats[0] : '';
			echo '<li>';
			echo '<button type="button" class="isfm-tmce-modal__item" data-id="' . esc_attr( $post->ID ) . '" data-title="' . esc_attr( $post->post_title ) . '">';
			echo '<span class="dashicons dashicons-media-default"></span>';
			echo '<span class="isfm-tmce-modal__item-title">' . esc_html( $post->post_title ) . '</span>';
			if ( $cat ) {
				echo '<span class="isfm-tmce-modal__item-cat">' . esc_html( $cat ) . '</span>';
			}
			echo '</button>';
			echo '</li>';
		}
		echo '</ul>';

		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}
}
