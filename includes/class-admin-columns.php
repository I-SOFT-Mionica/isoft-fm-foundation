<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Admin_Columns {

	public function register_hooks(): void {
		add_filter( 'manage_isfm_file_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_isfm_file_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-isfm_sortable_columns', array( $this, 'sortable_columns' ) );
	}

	public function add_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['isfm_thumbnail']      = __( 'Thumb', 'isoft-fm-foundation' );
				$new['isfm_category']       = __( 'Category', 'isoft-fm-foundation' );
				$new['isfm_files_count']    = __( 'Files', 'isoft-fm-foundation' );
				$new['isfm_download_count'] = __( 'Downloads', 'isoft-fm-foundation' );
				$new['isfm_access_role']    = __( 'Access', 'isoft-fm-foundation' );
				$new['isfm_featured']       = '★';
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'isfm_thumbnail':
				echo has_post_thumbnail( $post_id )
					? get_the_post_thumbnail( $post_id, array( 40, 40 ) )
					: '<span class="dashicons dashicons-media-default" style="font-size:32px;color:#ccc;line-height:40px;"></span>';
				break;

			case 'isfm_category':
				$terms = get_the_terms( $post_id, 'isfm_category' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$links = array_map(
						fn( $t ) => sprintf(
							'<a href="%s">%s</a>',
							esc_url(
								add_query_arg(
									array(
										'post_type'    => 'isfm_file',
										'isfm_category' => $t->slug,
									),
									admin_url( 'edit.php' )
								)
							),
							esc_html( $t->name )
						),
						$terms
					);
					echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '—';
				}
				break;

			case 'isfm_files_count':
				echo esc_html( count( ( new ISFM_File_Manager() )->get_files( $post_id ) ) );
				break;

			case 'isfm_download_count':
				echo esc_html( number_format_i18n( (int) get_post_meta( $post_id, '_isfm_download_count', true ) ) );
				break;

			case 'isfm_access_role':
				$labels = array(
					'public'        => __( 'Public', 'isoft-fm-foundation' ),
					'subscriber'    => __( 'Subscriber+', 'isoft-fm-foundation' ),
					'contributor'   => __( 'Contributor+', 'isoft-fm-foundation' ),
					'author'        => __( 'Author+', 'isoft-fm-foundation' ),
					'editor'        => __( 'Editor+', 'isoft-fm-foundation' ),
					'administrator' => __( 'Admin only', 'isoft-fm-foundation' ),
				);
				$role   = get_post_meta( $post_id, '_isfm_access_role', true ) ?: 'public';
				echo esc_html( $labels[ $role ] ?? $role );
				break;

			case 'isfm_featured':
				if ( get_post_meta( $post_id, '_isfm_featured', true ) ) {
					echo '<span class="dashicons dashicons-star-filled" style="color:#f0b849;" title="' . esc_attr__( 'Featured', 'isoft-fm-foundation' ) . '"></span>';
				}
				break;
		}
	}

	public function sortable_columns( array $columns ): array {
		$columns['isfm_download_count'] = 'isfm_download_count';
		return $columns;
	}
}
