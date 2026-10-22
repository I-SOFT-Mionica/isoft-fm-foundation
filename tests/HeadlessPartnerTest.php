<?php
/**
 * What the headless partner project relies on, so Foundation breaks it in
 * this suite rather than on their sites: one public-visibility rule, the
 * public API honouring pending categories and unavailable files, the fields
 * their SDK reads, GET /public/info, and isoft_fmf_content_changed as the one
 * change hook.
 */
class HeadlessPartnerTest extends WP_UnitTestCase {

	private ISOFT_FMF_Access_Control $access;
	/** @var string[] */
	private array $cleanup = array();
	/** @var list<array{0:string,1:int}> */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$this->access = new ISOFT_FMF_Access_Control();
		update_option( 'isoft_fmf_public_api_enabled', 1 );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( 'isoft_fmf_public_api_enabled' );
		parent::tear_down();
	}

	private function category( string $name, int $parent = 0, string $role = '' ): int {
		$id = (int) wp_insert_term( $name . ' ' . wp_generate_password( 5, false ), 'isoft_fmf_category', array( 'parent' => $parent ) )['term_id'];
		ISOFT_FMF_Category_Folders::ensure( $id );
		if ( '' !== $role ) {
			update_term_meta( $id, '_isoft_fmf_cat_access_role', $role );
		}
		return $id;
	}

	private function download( int $category_id, array $args = array() ): int {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'isoft_fmf_file',
					'post_status' => 'publish',
					'post_title'  => 'Doc',
				),
				$args
			)
		);
		if ( $category_id ) {
			wp_set_object_terms( $id, array( $category_id ), 'isoft_fmf_category' );
		}
		$this->access->recompute_effective_role( $id );
		return $id;
	}

	private function file( int $download_id, string $content = 'bytes' ): int {
		$src = sys_get_temp_dir() . '/isoft-fmf-headless-' . wp_generate_password( 8, false ) . '.txt';
		file_put_contents( $src, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$id = isoft_fmf_attach_file( $download_id, $src, array( 'mode' => 'move' ) );
		$this->assertIsInt( $id );
		$this->cleanup[] = isoft_fmf_files_dir() . '/' . ( new ISOFT_FMF_File_Manager() )->get_file( $id )->file_path;
		return $id;
	}

	private function rest( string $route ): WP_REST_Response {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		return $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/isoft-fm-foundation/v1/public' . $route ) );
	}

	private function listen(): void {
		$this->events = array();
		add_action(
			'isoft_fmf_content_changed',
			function ( string $type, int $id ): void {
				$this->events[] = array( $type, $id );
			},
			10,
			2
		);
	}

	// --- one rule for "public" -------------------------------------------

	public function test_download_is_public_applies_status_password_role_and_pending(): void {
		$open = $this->category( 'Open' );

		$this->assertTrue( isoft_fmf_download_is_public( $this->download( $open ) ) );
		$this->assertFalse( isoft_fmf_download_is_public( $this->download( $open, array( 'post_status' => 'draft' ) ) ) );
		$this->assertFalse( isoft_fmf_download_is_public( $this->download( $open, array( 'post_password' => 'x' ) ) ) );
		$this->assertFalse( isoft_fmf_download_is_public( $this->download( $this->category( 'Members', 0, 'subscriber' ) ) ) );
		$this->assertFalse( isoft_fmf_download_is_public( self::factory()->post->create() ), 'not a download' );

		$held = $this->category( 'Held' );
		$in   = $this->download( $this->category( 'Inside', $held ) );
		isoft_fmf_set_category_pending( $held, true );
		$this->assertFalse( isoft_fmf_download_is_public( $in ), 'under a pending category' );
	}

	public function test_download_is_public_is_filterable(): void {
		$id = $this->download( $this->category( 'Open' ) );
		add_filter( 'isoft_fmf_download_is_public', '__return_false' );

		$this->assertFalse( isoft_fmf_download_is_public( $id ) );
	}

	public function test_category_is_public_checks_ancestors_and_pending(): void {
		$open    = $this->category( 'Open' );
		$members = $this->category( 'Members', 0, 'editor' );
		$child   = $this->category( 'Child', $members );
		$held    = $this->category( 'Held' );
		isoft_fmf_set_category_pending( $held, true );

		$this->assertTrue( isoft_fmf_category_is_public( $open ) );
		$this->assertFalse( isoft_fmf_category_is_public( $members ) );
		$this->assertFalse( isoft_fmf_category_is_public( $child ), 'inherits the parent restriction' );
		$this->assertFalse( isoft_fmf_category_is_public( $held ) );
		$this->assertFalse( isoft_fmf_category_is_public( 999999 ) );
	}

	public function test_public_file_returns_location_only_for_servable_files(): void {
		$open = $this->download( $this->category( 'Open' ) );
		$id   = $this->file( $open, 'servable' );

		$file = isoft_fmf_public_file( $id );
		$this->assertSame( $open, $file['download_id'] );
		$this->assertSame( hash( 'sha256', 'servable' ), $file['sha256'] );
		$this->assertFileExists( $file['abs_path'] );
		$this->assertSame( array( 'id', 'download_id', 'abs_path', 'rel_path', 'name', 'size', 'mime', 'sha256' ), array_keys( $file ) );

		isoft_fmf_set_file_unavailable( $id, true );
		$this->assertNull( isoft_fmf_public_file( $id ), 'unavailable' );
		isoft_fmf_set_file_unavailable( $id, false );

		update_post_meta( $open, '_isoft_fmf_external_only', 1 );
		$this->assertNull( isoft_fmf_public_file( $id ), 'external links only' );
		delete_post_meta( $open, '_isoft_fmf_external_only' );

		$ext = (int) ( new ISOFT_FMF_File_Manager() )->add_external_link( $open, 'https://example.org/x' );
		$this->assertNull( isoft_fmf_public_file( $ext ), 'external links are not local files' );

		$restricted = $this->download( $this->category( 'Members', 0, 'subscriber' ) );
		$this->assertNull( isoft_fmf_public_file( $this->file( $restricted ) ) );

		$this->assertNull( isoft_fmf_public_file( 999999 ) );
	}

	// --- public API honours the same rule --------------------------------

	public function test_public_api_leaves_out_downloads_under_a_pending_category(): void {
		$open    = $this->download( $this->category( 'Open' ), array( 'post_title' => 'Visible' ) );
		$held    = $this->category( 'Held' );
		$pending = $this->download( $this->category( 'Inside', $held ), array( 'post_title' => 'Hidden' ) );
		isoft_fmf_set_category_pending( $held, true );

		$list = $this->rest( '/downloads' );
		$this->assertSame( array( 'Visible' ), array_column( $list->get_data(), 'title' ) );
		$this->assertSame( '1', $list->get_headers()['X-WP-Total'], 'totals stay right' );
		$this->assertSame( 404, $this->rest( "/downloads/{$pending}" )->get_status() );
		$this->assertSame( 200, $this->rest( "/downloads/{$open}" )->get_status() );

		isoft_fmf_set_category_pending( $held, false );
		$this->assertSame( 200, $this->rest( "/downloads/{$pending}" )->get_status() );
	}

	public function test_categories_carry_their_path(): void {
		$parent = $this->category( 'Reports' );
		$child  = $this->category( 'Year', $parent );

		$by_id = array_column( $this->rest( '/categories' )->get_data(), null, 'id' );

		$this->assertSame( isoft_fmf_category_folder_path( $child ), $by_id[ $child ]['path'] );
		$this->assertStringContainsString( '/', $by_id[ $child ]['path'] );
		$this->assertSame( $by_id[ $parent ]['path'] . '/' . get_term( $child )->slug, $by_id[ $child ]['path'] );
	}

	public function test_content_is_rendered_with_the_download_as_the_global_post(): void {
		add_shortcode( 'isoft_test_current_id', static fn(): string => 'ID=' . get_the_ID() );
		$id = $this->download( $this->category( 'Open' ), array( 'post_content' => '[isoft_test_current_id]' ) );

		$data = $this->rest( "/downloads/{$id}" )->get_data();

		$this->assertStringContainsString( 'ID=' . $id, $data['content'] );
		remove_shortcode( 'isoft_test_current_id' );
	}

	// --- feature detection -------------------------------------------------

	public function test_info_reports_version_and_features_while_the_api_is_on(): void {
		$info = $this->rest( '/info' );

		$this->assertSame( 200, $info->get_status() );
		$data = $info->get_data();
		$this->assertSame( ISOFT_FMF_VERSION, $data['version'] );
		$this->assertSame( 1, $data['api'] );
		foreach ( array( 'public_api', 'gates', 'record_downloads', 'record_idempotency', 'content_changed', 'pending_categories', 'unavailable_files', 'category_path' ) as $feature ) {
			$this->assertContains( $feature, $data['features'] );
		}
		$this->assertArrayHasKey( 'cache_friendly_links', $data['settings'] );
	}

	public function test_info_does_not_exist_while_the_api_is_off(): void {
		delete_option( 'isoft_fmf_public_api_enabled' );

		$this->assertSame( 404, $this->rest( '/info' )->get_status() );
	}

	// --- the one change hook ---------------------------------------------

	public function test_saving_and_deleting_a_download_is_announced(): void {
		$cat = $this->category( 'Open' );
		$this->listen();

		$id = $this->download( $cat );
		$this->assertContains( array( 'download', $id ), $this->events );

		$this->events = array();
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Renamed',
			)
		);
		$this->assertContains( array( 'download', $id ), $this->events );

		$this->events = array();
		wp_delete_post( $id, true );
		$this->assertContains( array( 'download', $id ), $this->events );
	}

	public function test_category_and_tag_edits_are_announced(): void {
		$this->listen();

		$cat = (int) wp_insert_term( 'Fresh ' . wp_generate_password( 5, false ), 'isoft_fmf_category' )['term_id'];
		$this->assertContains( array( 'category', $cat ), $this->events );

		$this->events = array();
		wp_update_term( $cat, 'isoft_fmf_category', array( 'description' => 'New' ) );
		$this->assertContains( array( 'category', $cat ), $this->events );

		$this->events = array();
		update_term_meta( $cat, '_isoft_fmf_cat_icon', 'dashicons-book' );
		$this->assertSame( array( array( 'category', $cat ) ), $this->events, 'Foundation term meta counts' );

		$this->events = array();
		update_term_meta( $cat, 'someone_elses_meta', 'x' );
		$this->assertSame( array(), $this->events, 'foreign meta does not' );

		$tag = (int) wp_insert_term( 'Tag ' . wp_generate_password( 5, false ), 'isoft_fmf_tag' )['term_id'];
		$this->assertContains( array( 'tag', $tag ), $this->events );

		$this->events = array();
		wp_delete_term( $cat, 'isoft_fmf_category' );
		$this->assertContains( array( 'category', $cat ), $this->events );
	}

	public function test_other_post_types_and_taxonomies_are_ignored(): void {
		$this->listen();

		self::factory()->post->create();
		wp_insert_term( 'Plain ' . wp_generate_password( 5, false ), 'category' );

		$this->assertSame( array(), $this->events );
	}

	public function test_suspend_still_collapses_everything_into_one_bulk_event(): void {
		$cat = $this->category( 'Bulk' );
		$this->listen();

		isoft_fmf_suspend_content_changed();
		$this->download( $cat );
		$this->download( $cat );
		wp_update_term( $cat, 'isoft_fmf_category', array( 'description' => 'x' ) );
		isoft_fmf_resume_content_changed();

		$this->assertSame( array( array( 'bulk', 0 ) ), $this->events );
	}

	// --- fields the headless SDK reads -----------------------------------

	public function test_public_api_has_every_field_the_headless_module_uses(): void {
		$id = $this->download( $this->category( 'Open' ) );
		$this->file( $id );
		( new ISOFT_FMF_File_Manager() )->add_external_link( $id, 'https://example.org/mirror' );

		$download = $this->rest( "/downloads/{$id}" )->get_data();
		foreach ( array( 'id', 'title', 'slug', 'excerpt', 'content', 'date', 'modified', 'categories', 'tags', 'featured', 'hot', 'version', 'changelog', 'author_name', 'author_url', 'date_published', 'download_count', 'external_only', 'license', 'gates', 'thumbnail', 'files', 'access' ) as $field ) {
			$this->assertArrayHasKey( $field, $download, "download.{$field}" );
		}
		foreach ( $download['files'] as $file ) {
			foreach ( array( 'id', 'title', 'description', 'type', 'name', 'size', 'mime', 'sha256', 'url', 'mirror', 'count', 'order', 'unavailable', 'missing', 'download_url' ) as $field ) {
				$this->assertArrayHasKey( $field, $file, "file.{$field}" );
			}
		}
		foreach ( $this->rest( '/categories' )->get_data() as $category ) {
			foreach ( array( 'id', 'name', 'slug', 'path', 'parent', 'description', 'icon', 'sort_order' ) as $field ) {
				$this->assertArrayHasKey( $field, $category, "category.{$field}" );
			}
		}
	}
}
