<?php
/**
 * Public read-only REST API (/public/...): what an anonymous visitor, a
 * static site build or an app may see — and, more importantly, may not.
 */
class RestPublicTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private ISOFT_FMF_Access_Control $access;
	private const NS = '/isoft-fm-foundation/v1/public';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server, $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->access = new ISOFT_FMF_Access_Control();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function make_download( string $title, array $args = array(), string $role = '' ): int {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'isoft_fmf_file',
					'post_status' => 'publish',
					'post_title'  => $title,
				),
				$args
			)
		);
		if ( '' !== $role ) {
			update_post_meta( $id, '_isoft_fmf_access_role', $role );
		}
		$this->access->recompute_effective_role( $id );
		return $id;
	}

	private function get( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::NS . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	private function titles( WP_REST_Response $response ): array {
		$titles = array_column( $response->get_data(), 'title' );
		sort( $titles );
		return $titles;
	}

	public function test_anonymous_list_shows_public_downloads_only(): void {
		$this->make_download( 'Public' );
		$this->make_download( 'Members', array(), 'subscriber' );
		$this->make_download( 'Draft', array( 'post_status' => 'draft' ) );
		$this->make_download( 'Locked', array( 'post_password' => 'secret' ) );

		$response = $this->get( '/downloads' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'Public' ), $this->titles( $response ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	public function test_subscriber_also_sees_subscriber_downloads(): void {
		$this->make_download( 'Public' );
		$this->make_download( 'Members', array(), 'subscriber' );
		$this->make_download( 'Editors', array(), 'editor' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array( 'Members', 'Public' ), $this->titles( $this->get( '/downloads' ) ) );
	}

	public function test_restricted_single_is_indistinguishable_from_missing(): void {
		$restricted = $this->make_download( 'Members', array(), 'subscriber' );

		$this->assertSame( 404, $this->get( "/downloads/{$restricted}" )->get_status() );
		$this->assertSame( 404, $this->get( '/downloads/999999' )->get_status() );
	}

	public function test_single_exposes_files_without_server_paths(): void {
		$id      = $this->make_download( 'Guide' );
		$manager = new ISOFT_FMF_File_Manager();
		$local   = (int) $manager->add_local_file(
			$id,
			array(
				'title'     => 'PDF',
				'file_name' => 'guide.pdf',
				'file_path' => 'secret/folder/guide.pdf',
				'file_size' => 2048,
				'file_mime' => 'application/pdf',
			)
		);
		$manager->add_external_link( $id, 'https://example.org/mirror.pdf', array( 'title' => 'Mirror' ) );

		$response = $this->get( "/downloads/{$id}" );
		$data     = $response->get_data();
		$json     = wp_json_encode( $data );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'secret/folder', $json );
		$this->assertArrayNotHasKey( 'file_path', $data['files'][0] );

		$by_type = array_column( $data['files'], null, 'type' );
		$this->assertSame( 'guide.pdf', $by_type['local']['name'] );
		$this->assertSame( 2048, $by_type['local']['size'] );
		$this->assertNull( $by_type['local']['url'] );
		$this->assertStringContainsString( "isoft_fmf_download={$local}", $by_type['local']['download_url'] );
		$this->assertStringNotContainsString( 'nonce=', $by_type['local']['download_url'] );
		$this->assertSame( 'https://example.org/mirror.pdf', $by_type['external']['url'] );
		$this->assertSame( 'public', $data['access'] );
	}

	public function test_category_filter_includes_subcategories_unless_disabled(): void {
		$parent = (int) wp_insert_term( 'Reports', 'isoft_fmf_category' )['term_id'];
		$child  = (int) wp_insert_term( '2026', 'isoft_fmf_category', array( 'parent' => $parent ) )['term_id'];

		$top    = $this->make_download( 'Top' );
		$nested = $this->make_download( 'Nested' );
		$this->make_download( 'Elsewhere' );
		wp_set_object_terms( $top, array( $parent ), 'isoft_fmf_category' );
		wp_set_object_terms( $nested, array( $child ), 'isoft_fmf_category' );

		$this->assertSame( array( 'Nested', 'Top' ), $this->titles( $this->get( '/downloads', array( 'category' => $parent ) ) ) );
		$this->assertSame(
			array( 'Top' ),
			$this->titles(
				$this->get(
					'/downloads',
					array(
						'category'              => $parent,
						'include_subcategories' => false,
					)
				)
			)
		);
	}

	public function test_category_restriction_hides_its_downloads(): void {
		$cat = (int) wp_insert_term( 'Internal', 'isoft_fmf_category' )['term_id'];
		update_term_meta( $cat, '_isoft_fmf_cat_access_role', 'editor' );
		$id = $this->make_download( 'Inherited' );
		wp_set_object_terms( $id, array( $cat ), 'isoft_fmf_category' );
		$this->access->recompute_effective_role( $id );

		$this->assertSame( array(), $this->titles( $this->get( '/downloads' ) ) );
	}

	public function test_categories_hide_restricted_ones_from_anonymous(): void {
		wp_insert_term( 'Public docs', 'isoft_fmf_category' );
		$internal = (int) wp_insert_term( 'Internal', 'isoft_fmf_category' )['term_id'];
		update_term_meta( $internal, '_isoft_fmf_cat_access_role', 'editor' );

		$names = array_column( $this->get( '/categories' )->get_data(), 'name' );
		$this->assertContains( 'Public docs', $names );
		$this->assertNotContains( 'Internal', $names );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertContains( 'Internal', array_column( $this->get( '/categories' )->get_data(), 'name' ) );
	}

	public function test_categories_do_not_leak_post_counts(): void {
		wp_insert_term( 'Docs', 'isoft_fmf_category' );

		foreach ( $this->get( '/categories' )->get_data() as $category ) {
			$this->assertArrayNotHasKey( 'count', $category );
		}
	}

	public function test_per_page_is_capped(): void {
		$this->assertSame( 400, $this->get( '/downloads', array( 'per_page' => 500 ) )->get_status() );
	}
}
