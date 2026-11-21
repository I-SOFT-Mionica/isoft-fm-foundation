<?php
/**
 * The block editor's sidebar reads and saves a download's fields as post meta
 * over REST. Without 'custom-fields' support WordPress left them out (ED-1).
 */

class EditorMetaRestTest extends WP_UnitTestCase {

	private int $admin;
	private int $download;

	public function set_up(): void {
		parent::set_up();
		ISOFT_FMF_License_Service::bust_cache();
		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->download = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'draft',
				'post_author' => $this->admin,
			)
		);
		wp_set_current_user( $this->admin );
	}

	private function get(): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wp/v2/isoft-fmf-downloads/' . $this->download );
		$request->set_param( 'context', 'edit' );
		return rest_do_request( $request );
	}

	private function save( array $meta ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/isoft-fmf-downloads/' . $this->download );
		$request->set_body_params( array( 'meta' => $meta ) );
		return rest_do_request( $request );
	}

	public function test_the_fields_are_in_the_rest_response(): void {
		update_post_meta( $this->download, '_isoft_fmf_license_id', 8 );
		update_post_meta( $this->download, '_isoft_fmf_version', '2.1' );

		$data = $this->get()->get_data();

		$this->assertArrayHasKey( 'meta', $data );
		$this->assertSame( 8, $data['meta']['_isoft_fmf_license_id'] );
		$this->assertSame( '2.1', $data['meta']['_isoft_fmf_version'] );
	}

	public function test_saving_stores_the_fields(): void {
		$response = $this->save(
			array(
				'_isoft_fmf_version'       => '3.0',
				'_isoft_fmf_featured'      => true,
				'_isoft_fmf_external_only' => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '3.0', get_post_meta( $this->download, '_isoft_fmf_version', true ) );
		$this->assertSame( '1', get_post_meta( $this->download, '_isoft_fmf_featured', true ) );
		$this->assertSame( '1', get_post_meta( $this->download, '_isoft_fmf_external_only', true ) );
	}

	public function test_inheriting_a_license_resolves_after_the_save(): void {
		$license  = (int) isoft_fmf_create_license( array( 'title' => 'Editor Licence ' . wp_generate_password( 4, false ) ) );
		$category = isoft_fmf_create_category(
			array(
				'name'       => 'Editor licence category ' . wp_generate_password( 4, false ),
				'license_id' => $license,
			)
		);
		wp_set_object_terms( $this->download, (int) $category['term_id'], 'isoft_fmf_category' );

		$this->save( array( '_isoft_fmf_license_id' => ISOFT_FMF_License_Resolver::INHERIT ) );

		$this->assertSame( '-1', get_post_meta( $this->download, '_isoft_fmf_license_id', true ) );
		$this->assertSame( $license, ( new ISOFT_FMF_License_Resolver() )->effective_license_for( $this->download ) );
	}

	public function test_an_access_role_resolves_after_the_save(): void {
		$this->save( array( '_isoft_fmf_access_role' => 'editor' ) );

		$this->assertSame( 'editor', ( new ISOFT_FMF_Access_Control() )->effective_role_for( $this->download ) );
	}

	public function test_saving_announces_the_change_after_the_meta(): void {
		$seen     = array();
		$listener = function ( $type, $id ) use ( &$seen ): void {
			if ( 'download' === $type && $this->download === $id ) {
				$seen[] = get_post_meta( $id, '_isoft_fmf_version', true );
			}
		};
		add_action( 'isoft_fmf_content_changed', $listener, 10, 2 );

		$this->save( array( '_isoft_fmf_version' => '4.0' ) );
		remove_action( 'isoft_fmf_content_changed', $listener, 10 );

		$this->assertContains( '4.0', $seen );
	}

	public function test_values_are_cleaned(): void {
		$this->save(
			array(
				'_isoft_fmf_author_url' => 'javascript:alert(1)',
				'_isoft_fmf_changelog'  => '<p>Fixed</p><script>alert(1)</script>',
			)
		);

		$this->assertSame( '', get_post_meta( $this->download, '_isoft_fmf_author_url', true ) );
		$this->assertSame( '<p>Fixed</p>alert(1)', get_post_meta( $this->download, '_isoft_fmf_changelog', true ) );

		update_post_meta( $this->download, '_isoft_fmf_author_url', 'https://example.org/a' );
		$this->assertSame( 'https://example.org/a', get_post_meta( $this->download, '_isoft_fmf_author_url', true ) );
	}

	public function test_an_unknown_access_role_is_never_more_open(): void {
		update_post_meta( $this->download, '_isoft_fmf_access_role', 'no-such-role' );
		$this->assertSame( 'administrator', get_post_meta( $this->download, '_isoft_fmf_access_role', true ) );

		update_post_meta( $this->download, '_isoft_fmf_access_role', 'public' );
		$this->assertSame( 'public', get_post_meta( $this->download, '_isoft_fmf_access_role', true ) );
	}

	public function test_a_license_below_inherit_is_none(): void {
		update_post_meta( $this->download, '_isoft_fmf_license_id', -5 );

		$this->assertSame( '0', get_post_meta( $this->download, '_isoft_fmf_license_id', true ) );
	}

	public function test_only_an_administrator_writes_the_counter_over_rest(): void {
		update_post_meta( $this->download, '_isoft_fmf_download_count', 7 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->save( array( '_isoft_fmf_download_count' => 999 ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '7', get_post_meta( $this->download, '_isoft_fmf_download_count', true ) );
	}

	public function test_an_editor_saves_the_ordinary_fields(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 200, $this->save( array( '_isoft_fmf_version' => '5.0' ) )->get_status() );
		$this->assertSame( '5.0', get_post_meta( $this->download, '_isoft_fmf_version', true ) );
	}
}
