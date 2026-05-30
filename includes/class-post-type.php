<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Post_Type {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 999 );
		add_filter( 'the_content', array( $this, 'append_download_content' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'latinize_slug' ), 10, 2 );
	}

	/**
	 * Transliterate Cyrillic in isfm_file post slugs to Latin.
	 * sanitize_title() URL-percent-encodes non-ASCII, so by the time we see
	 * post_name it may be either raw Cyrillic or %XX sequences — urldecode
	 * first before inspecting.
	 */
	public function latinize_slug( array $data, array $postarr ): array {
		unset( $postarr );
		if ( 'isfm_file' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$source = $data['post_name'] !== '' ? $data['post_name'] : $data['post_title'];
		if ( '' === $source ) {
			return $data;
		}

		$decoded = urldecode( $source );
		if ( preg_match( '/\p{Cyrillic}/u', $decoded ) ) {
			$data['post_name'] = sanitize_title( isfm_cyrillic_to_latin( $decoded ) );
		}
		return $data;
	}

	public function register(): void {
		$labels = array(
			'name'               => _x( 'Downloads', 'post type general name', 'isoft-fm-foundation' ),
			'singular_name'      => _x( 'Download', 'post type singular name', 'isoft-fm-foundation' ),
			'menu_name'          => _x( 'I-Soft File Manager: Foundation', 'admin menu', 'isoft-fm-foundation' ),
			'name_admin_bar'     => _x( 'Download', 'add new on admin bar', 'isoft-fm-foundation' ),
			'add_new'            => __( 'Add New', 'isoft-fm-foundation' ),
			'add_new_item'       => __( 'Add New Download', 'isoft-fm-foundation' ),
			'new_item'           => __( 'New Download', 'isoft-fm-foundation' ),
			'edit_item'          => __( 'Edit Download', 'isoft-fm-foundation' ),
			'view_item'          => __( 'View Download', 'isoft-fm-foundation' ),
			'all_items'          => __( 'All Downloads', 'isoft-fm-foundation' ),
			'search_items'       => __( 'Search Downloads', 'isoft-fm-foundation' ),
			'not_found'          => __( 'No downloads found.', 'isoft-fm-foundation' ),
			'not_found_in_trash' => __( 'No downloads found in Trash.', 'isoft-fm-foundation' ),
		);

		register_post_type(
			'isfm_file',
			array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array( 'slug' => get_option( 'isfm_archive_slug', 'downloads' ) ),
				'capability_type'    => 'post',  // Standard WP caps — no custom mapping.
				'map_meta_cap'       => true,    // Custom caps used only for settings/logs/export.
				'has_archive'        => get_option( 'isfm_archive_slug', 'downloads' ),
				'hierarchical'       => false,
				'menu_position'      => 26,
				'menu_icon'          => 'dashicons-download',
				'supports'           => array( 'title', 'thumbnail', 'excerpt', 'revisions', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'isfm-downloads',
			)
		);

		// Disable block editor for downloads — files are the primary content, not prose.
		add_filter(
			'use_block_editor_for_post_type',
			function ( bool $use, string $post_type ): bool {
				return $post_type === 'isfm_file' ? false : $use;
			},
			10,
			2
		);
	}

	/**
	 * Flush rewrite rules once after activation (or whenever the flag is set).
	 * Runs at init priority 999 — after the CPT is already registered — so the
	 * 'isfm_file' rewrite rules are included in the flushed set.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'isfm_flush_rewrite_rules' ) ) {
			delete_option( 'isfm_flush_rewrite_rules' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Append file listing and metadata to the post content on single download pages.
	 * Works with both FSE block templates (via <!-- wp:post-content /-->) and
	 * classic PHP templates.
	 */
	public function append_download_content( string $content ): string {
		if ( ! is_singular( 'isfm_file' ) ) {
			return $content;
		}

		// In FSE themes the loop context differs — get_post() is reliable here.
		$post = get_post();
		if ( ! $post || $post->post_type !== 'isfm_file' ) {
			return $content;
		}

		// Prevent double-injection if the filter runs more than once.
		static $appended = array();
		if ( isset( $appended[ $post->ID ] ) ) {
			return $content;
		}
		$appended[ $post->ID ] = true;

		$files    = ( new ISFM_File_Manager() )->get_files( $post->ID );
		$settings = isfm_get_settings();

		ob_start();
		require ISFM_PLUGIN_DIR . 'public/views/download-single.php';
		return $content . ob_get_clean();
	}
}
