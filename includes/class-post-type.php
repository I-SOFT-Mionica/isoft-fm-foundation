<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Post_Type {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 999 );
		add_filter( 'the_content', array( $this, 'append_download_content' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'latinize_slug' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'cleanup_stats_on_delete' ), 10, 2 );
	}

	/**
	 * Sweep daily-aggregate + per-click log rows tied to a download whose
	 * post is being permanently deleted. Prevents the dashboard from
	 * surfacing orphaned counts as "(deleted)" rows after individual
	 * post deletions through the WP admin (or wp_delete_post calls from
	 * other code paths). Skipped for non-download post types so deletes
	 * on unrelated posts don't touch our tables.
	 *
	 * The download_count column on isoft_fmf_files is collateral here too:
	 * the file rows themselves are owned by the post's lifecycle but live
	 * in our table, so a future post delete that bypasses our file
	 * cleanup would leak file rows too. They're not addressed here — the
	 * existing file delete path through class-file-manager handles that
	 * on the meta-box flow; before_delete_post for the file rows is a
	 * separate concern that belongs to that class if needed.
	 */
	public function cleanup_stats_on_delete( int $post_id, WP_Post $post ): void {
		if ( 'isoft_fmf_file' !== $post->post_type ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot post-deletion cleanup; bypassing the manager keeps the hook side-effect cheap.
		$wpdb->delete( $wpdb->prefix . 'isoft_fmf_download_daily', array( 'download_id' => $post_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same; the per-click audit log entries are also discarded since the post they audited is being permanently removed.
		$wpdb->delete( $wpdb->prefix . 'isoft_fmf_download_log', array( 'download_id' => $post_id ), array( '%d' ) );
		delete_transient( 'isoft_fmf_stats_overview' );
	}

	/**
	 * Transliterate Cyrillic in isoft_fmf_file post slugs to Latin.
	 *
	 * Sanitize_title() URL-percent-encodes non-ASCII, so by the time we see
	 * post_name it may be either raw Cyrillic or %XX sequences — urldecode
	 * first before inspecting.
	 *
	 * Three cases to handle:
	 *
	 *   A. post_name itself contains Cyrillic (raw or %XX). Transliterate.
	 *
	 *   B. post_title is Cyrillic AND post_name is empty. Derive from title.
	 *
	 *   C. post_title is Cyrillic AND post_name is the auto-draft default
	 *      carried over from when WordPress created the placeholder draft
	 *      (e.g. "automatski-nacrt" under sr-RS Latin, "auto-draft" under
	 *      en_US — sanitize_title( __( 'Auto Draft' ) ) varies by locale).
	 *      Without this case, publishing a Cyrillic-titled draft for the
	 *      first time keeps the locale-specific auto-draft slug forever
	 *      because the original logic preferred a non-empty post_name and
	 *      the auto-draft default contains no Cyrillic to trigger Case A.
	 *
	 * User-customised slugs (post already exists, user edited the permalink)
	 * are preserved — only auto-draft transitions trigger Case C.
	 */
	public function latinize_slug( array $data, array $postarr ): array {
		if ( 'isoft_fmf_file' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}

		$title = $data['post_title'] ?? '';
		$name  = $data['post_name'] ?? '';

		// Case A: post_name itself is Cyrillic.
		$name_decoded = urldecode( $name );
		if ( '' !== $name_decoded && preg_match( '/\p{Cyrillic}/u', $name_decoded ) ) {
			$data['post_name'] = sanitize_title( isoft_fmf_cyrillic_to_latin( $name_decoded ) );
			return $data;
		}

		// Cases B and C require a Cyrillic title.
		if ( '' === $title || ! preg_match( '/\p{Cyrillic}/u', $title ) ) {
			return $data;
		}

		// Case B: empty post_name. Derive from title.
		if ( '' === $name ) {
			$data['post_name'] = sanitize_title( isoft_fmf_cyrillic_to_latin( urldecode( $title ) ) );
			return $data;
		}

		// Case C: transitioning out of auto-draft. Replace the locale-specific
		// auto-draft default with a slug derived from the real title.
		$post_id    = (int) ( $postarr['ID'] ?? 0 );
		$old_status = $post_id ? get_post_field( 'post_status', $post_id ) : '';
		if ( 'auto-draft' === $old_status ) {
			$data['post_name'] = sanitize_title( isoft_fmf_cyrillic_to_latin( urldecode( $title ) ) );
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
			'isoft_fmf_file',
			array(
				'labels'             => $labels,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'query_var'          => true,
				'rewrite'            => array( 'slug' => get_option( 'isoft_fmf_archive_slug', 'downloads' ) ),
				'capability_type'    => 'post',  // Standard WP caps — no custom mapping.
				'map_meta_cap'       => true,    // Custom caps used only for settings/logs/export.
				'has_archive'        => get_option( 'isoft_fmf_archive_slug', 'downloads' ),
				'hierarchical'       => false,
				'menu_position'      => 26,
				'menu_icon'          => 'dashicons-download',
				// 'editor' added in 0.12.1 so use_block_editor_for_post_type()
				// doesn't short-circuit to false. Without 'editor' support,
				// the block editor never loads regardless of what our filter
				// below returns — the supports check runs first in WP core.
				// The previous Description meta box (textarea name="content")
				// is retired in this phase; post_content is now edited via
				// the block canvas. Legacy classic-editor content renders as
				// a single "Classic" block on first open.
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author' ),
				'show_in_rest'       => true,
				'rest_base'          => 'isoft-fmf-downloads',
			)
		);

		// Block editor enabled as of 0.12.1 so the editor-sidebar plugin
		// (ISOFT_FMF_Editor_Sidebar) can mount its Document panels.
		// Existing meta boxes still render below the editor canvas (WP
		// degrades them as collapsible "Additional fields" panels in the
		// block editor) until 0.12.5 demolition swaps each one out for
		// its sidebar equivalent.
		//
		// Legacy classic-editor post_content renders fine as a single
		// "Classic" block — no auto-conversion required, opt-in per post.
		//
		// Revert path: change the literal `true` below back to `false`.
		// The filter signature is preserved so other plugins' override
		// chains still work.
		add_filter(
			'use_block_editor_for_post_type',
			function ( bool $use, string $post_type ): bool {
				return $post_type === 'isoft_fmf_file' ? true : $use;
			},
			10,
			2
		);
	}

	/**
	 * Flush rewrite rules once after activation (or whenever the flag is set).
	 * Runs at init priority 999 — after the CPT is already registered — so the
	 * 'isoft_fmf_file' rewrite rules are included in the flushed set.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'isoft_fmf_flush_rewrite_rules' ) ) {
			delete_option( 'isoft_fmf_flush_rewrite_rules' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Append file listing and metadata to the post content on single download pages.
	 * Works with both FSE block templates (via <!-- wp:post-content /-->) and
	 * classic PHP templates.
	 */
	public function append_download_content( string $content ): string {
		if ( ! is_singular( 'isoft_fmf_file' ) ) {
			return $content;
		}

		// In FSE themes the loop context differs — get_post() is reliable here.
		$post = get_post();
		if ( ! $post || $post->post_type !== 'isoft_fmf_file' ) {
			return $content;
		}

		// Prevent double-injection if the filter runs more than once.
		static $appended = array();
		if ( isset( $appended[ $post->ID ] ) ) {
			return $content;
		}
		$appended[ $post->ID ] = true;

		$files    = ( new ISOFT_FMF_File_Manager() )->get_files( $post->ID );
		$settings = isoft_fmf_get_settings();

		ob_start();
		require ISOFT_FMF_PLUGIN_DIR . 'public/views/download-single.php';
		return $content . ob_get_clean();
	}
}
