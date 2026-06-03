<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Taxonomy {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'isfm_category_add_form_fields', array( $this, 'add_term_fields' ) );
		add_action( 'isfm_category_edit_form_fields', array( $this, 'edit_term_fields' ) );
		add_action( 'created_isfm_category', array( $this, 'save_term_fields' ) );
		add_action( 'edited_isfm_category', array( $this, 'save_term_fields' ) );
	}

	public function register(): void {
		register_taxonomy(
			'isfm_category',
			'isfm_file',
			array(
				'labels'            => array(
					'name'              => _x( 'Download Categories', 'taxonomy general name', 'isoft-fm-foundation' ),
					'singular_name'     => _x( 'Download Category', 'taxonomy singular name', 'isoft-fm-foundation' ),
					'search_items'      => __( 'Search Categories', 'isoft-fm-foundation' ),
					'all_items'         => __( 'All Categories', 'isoft-fm-foundation' ),
					'parent_item'       => __( 'Parent Category', 'isoft-fm-foundation' ),
					'parent_item_colon' => __( 'Parent Category:', 'isoft-fm-foundation' ),
					'edit_item'         => __( 'Edit Category', 'isoft-fm-foundation' ),
					'update_item'       => __( 'Update Category', 'isoft-fm-foundation' ),
					'add_new_item'      => __( 'Add New Category', 'isoft-fm-foundation' ),
					'new_item_name'     => __( 'New Category Name', 'isoft-fm-foundation' ),
					'menu_name'         => __( 'Categories', 'isoft-fm-foundation' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => get_option( 'isfm_category_slug', 'download-category' ) ),
				'capabilities'      => array(
					'manage_terms' => 'isfm_manage_categories',
					'edit_terms'   => 'isfm_manage_categories',
					'delete_terms' => 'isfm_manage_categories',
					'assign_terms' => 'isfm_create_downloads',
				),
			)
		);

		// Tags — non-hierarchical, multiple per download
		register_taxonomy(
			'isfm_tag',
			'isfm_file',
			array(
				'labels'            => array(
					'name'                       => _x( 'Download Tags', 'taxonomy general name', 'isoft-fm-foundation' ),
					'singular_name'              => _x( 'Download Tag', 'taxonomy singular name', 'isoft-fm-foundation' ),
					'search_items'               => __( 'Search Tags', 'isoft-fm-foundation' ),
					'all_items'                  => __( 'All Tags', 'isoft-fm-foundation' ),
					'edit_item'                  => __( 'Edit Tag', 'isoft-fm-foundation' ),
					'update_item'                => __( 'Update Tag', 'isoft-fm-foundation' ),
					'add_new_item'               => __( 'Add New Tag', 'isoft-fm-foundation' ),
					'new_item_name'              => __( 'New Tag Name', 'isoft-fm-foundation' ),
					'popular_items'              => __( 'Popular Tags', 'isoft-fm-foundation' ),
					'separate_items_with_commas' => __( 'Separate tags with commas', 'isoft-fm-foundation' ),
					'add_or_remove_items'        => __( 'Add or remove tags', 'isoft-fm-foundation' ),
					'choose_from_most_used'      => __( 'Choose from the most used tags', 'isoft-fm-foundation' ),
					'not_found'                  => __( 'No tags found.', 'isoft-fm-foundation' ),
					'menu_name'                  => __( 'Tags', 'isoft-fm-foundation' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => get_option( 'isfm_tag_slug', 'download-tag' ) ),
				'capabilities'      => array(
					'manage_terms' => 'isfm_manage_categories',
					'edit_terms'   => 'isfm_manage_categories',
					'delete_terms' => 'isfm_manage_categories',
					'assign_terms' => 'isfm_create_downloads',
				),
			)
		);

		register_term_meta(
			'isfm_category',
			'_isfm_cat_icon',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			)
		);
		// TODO v1.0: Category-level access role — enforce in ISFM_Access_Control.
		// register_term_meta(
		// 'isfm_category',
		// '_isfm_cat_access_role',
		// [
		// 'type'              => 'string',
		// 'single'            => true,
		// 'sanitize_callback' => 'sanitize_text_field',
		// ]
		// );
		register_term_meta(
			'isfm_category',
			'_isfm_cat_sort_order',
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => 'absint',
			)
		);
	}

	public function add_term_fields(): void {
		?>
		<div class="form-field">
			<label for="isfm-cat-icon"><?php esc_html_e( 'Icon', 'isoft-fm-foundation' ); ?></label>
			<input type="text" name="isfm_cat_icon" id="isfm-cat-icon" value="" />
			<p class="description"><?php esc_html_e( 'Dashicon name (e.g. dashicons-folder) or image URL.', 'isoft-fm-foundation' ); ?></p>
		</div>
		<?php // TODO v1.0: Category-level access role — enforce in ISFM_Access_Control. ?>
		<?php
		/*
		<div class="form-field">
			<label for="isfm-cat-access-role"><?php esc_html_e( 'Access Role', 'isoft-fm-foundation' ); ?></label>
			<?php $this->render_access_role_select( '', 'isfm_cat_access_role', 'isfm-cat-access-role' ); ?>
		</div>
		*/
		?>
		<div class="form-field">
			<label for="isfm-cat-sort-order"><?php esc_html_e( 'Sort Order', 'isoft-fm-foundation' ); ?></label>
			<input type="number" name="isfm_cat_sort_order" id="isfm-cat-sort-order" value="0" min="0" />
		</div>
		<?php
	}

	public function edit_term_fields( WP_Term $term ): void {
		$icon = get_term_meta( $term->term_id, '_isfm_cat_icon', true );
		// TODO v1.0: Category-level access role — enforce in ISFM_Access_Control.
		// $role = get_term_meta( $term->term_id, '_isfm_cat_access_role', true );
		$sort_order = (int) get_term_meta( $term->term_id, '_isfm_cat_sort_order', true );
		?>
		<tr class="form-field">
			<th><label for="isfm-cat-icon"><?php esc_html_e( 'Icon', 'isoft-fm-foundation' ); ?></label></th>
			<td>
				<input type="text" name="isfm_cat_icon" id="isfm-cat-icon" value="<?php echo esc_attr( $icon ); ?>" />
				<p class="description"><?php esc_html_e( 'Dashicon name or image URL.', 'isoft-fm-foundation' ); ?></p>
			</td>
		</tr>
		<?php // TODO v1.0: Category-level access role — enforce in ISFM_Access_Control. ?>
		<?php
		/*
		<tr class="form-field">
			<th><label for="isfm-cat-access-role"><?php esc_html_e( 'Access Role', 'isoft-fm-foundation' ); ?></label></th>
			<td><?php $this->render_access_role_select( $role, 'isfm_cat_access_role', 'isfm-cat-access-role' ); ?></td>
		</tr>
		*/
		?>
		<tr class="form-field">
			<th><label for="isfm-cat-sort-order"><?php esc_html_e( 'Sort Order', 'isoft-fm-foundation' ); ?></label></th>
			<td><input type="number" name="isfm_cat_sort_order" id="isfm-cat-sort-order" value="<?php echo esc_attr( $sort_order ); ?>" min="0" /></td>
		</tr>
		<?php
	}

	public function save_term_fields( int $term_id ): void {
		// WP core verifies the matching 'add-tag' / 'update-tag_<id>' nonce
		// before firing edited_/created_<taxonomy>. Re-verifying here keeps the
		// auditor and Plugin Check happy.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'update-tag_' . $term_id ) && ! wp_verify_nonce( $nonce, 'add-tag' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( isset( $_POST['isfm_cat_icon'] ) ) {
			update_term_meta( $term_id, '_isfm_cat_icon', sanitize_text_field( wp_unslash( $_POST['isfm_cat_icon'] ) ) );
		}
		// TODO v1.0: Category-level access role — enforce in ISFM_Access_Control.
		// if ( isset( $_POST['isfm_cat_access_role'] ) ) {
		// update_term_meta( $term_id, '_isfm_cat_access_role', sanitize_text_field( wp_unslash( $_POST['isfm_cat_access_role'] ) ) );
		// }
		if ( isset( $_POST['isfm_cat_sort_order'] ) ) {
			update_term_meta( $term_id, '_isfm_cat_sort_order', absint( wp_unslash( $_POST['isfm_cat_sort_order'] ) ) );
		}
	}

	// TODO v1.0: Category-level access role — enforce in ISFM_Access_Control.
	// private function render_access_role_select( string $selected, string $name, string $id ): void {
	// $roles = [
	// 'public'        => __( 'Public (everyone)', 'isoft-fm-foundation' ),
	// 'subscriber'    => __( 'Subscriber+', 'isoft-fm-foundation' ),
	// 'contributor'   => __( 'Contributor+', 'isoft-fm-foundation' ),
	// 'author'        => __( 'Author+', 'isoft-fm-foundation' ),
	// 'editor'        => __( 'Editor+', 'isoft-fm-foundation' ),
	// 'administrator' => __( 'Administrator only', 'isoft-fm-foundation' ),
	// ];
	// echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
	// foreach ( $roles as $value => $label ) {
	// printf(
	// '<option value="%s"%s>%s</option>',
	// esc_attr( $value ),
	// selected( $selected, $value, false ),
	// esc_html( $label )
	// );
	// }
	// echo '</select>';
	// }
}
