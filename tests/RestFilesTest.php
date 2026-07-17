<?php
/**
 * Integration tests for the /downloads/{id}/files REST routes.
 *
 * The upload route is excluded — multipart $_FILES + wp_handle_upload
 * is the territory of manual smoke tests. The other six routes are
 * covered here.
 */

class RestFilesTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private const NS = '/isoft-fm-foundation/v1';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server, $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function login_as_admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	private function make_download(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
				'post_title'  => 'REST Test Download',
			)
		);
	}

	private function seed_external_file( int $download_id, string $url = 'https://example.org/x' ): int {
		return (int) ( new ISOFT_FMF_Files_Service() )->add_external( $download_id, $url );
	}

	public function test_add_external_creates_row(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();

		$req = new WP_REST_Request( 'POST', self::NS . "/downloads/{$download_id}/files/external" );
		$req->set_param( 'url', 'https://example.org/lic' );
		$req->set_param( 'title', 'External' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'External', $response->get_data()['file']->title );
	}

	public function test_add_external_requires_url(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();

		$req = new WP_REST_Request( 'POST', self::NS . "/downloads/{$download_id}/files/external" );
		// No url param — the schema marks it required, REST short-circuits to 400.
		$response = $this->server->dispatch( $req );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_update_changes_title(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$file_id     = $this->seed_external_file( $download_id );

		$req = new WP_REST_Request( 'PUT', self::NS . "/downloads/{$download_id}/files/{$file_id}" );
		$req->set_param( 'title', 'New Title' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'New Title', $response->get_data()['file']->title );
	}

	public function test_update_returns_404_for_missing_file(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();

		$req = new WP_REST_Request( 'PUT', self::NS . "/downloads/{$download_id}/files/99999" );
		$req->set_param( 'title', 'x' );

		$this->assertSame( 404, $this->server->dispatch( $req )->get_status() );
	}

	public function test_delete_removes_file(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$file_id     = $this->seed_external_file( $download_id );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', self::NS . "/downloads/{$download_id}/files/{$file_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( ( new ISOFT_FMF_Files_Service() )->get( $file_id ) );
	}

	public function test_delete_returns_404_for_missing(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();

		$this->assertSame(
			404,
			$this->server->dispatch( new WP_REST_Request( 'DELETE', self::NS . "/downloads/{$download_id}/files/99999" ) )->get_status()
		);
	}

	public function test_reorder_writes_positions(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$a           = $this->seed_external_file( $download_id, 'https://example.org/a' );
		$b           = $this->seed_external_file( $download_id, 'https://example.org/b' );

		$req = new WP_REST_Request( 'POST', self::NS . "/downloads/{$download_id}/files/order" );
		$req->set_body_params(
			array(
				'order' => array(
					(string) $a => 5,
					(string) $b => 1,
				),
			)
		);

		$response = $this->server->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$rows = ( new ISOFT_FMF_Files_Service() )->list_for_download( $download_id );
		$this->assertSame( $b, (int) $rows[0]->id );
	}

	public function test_browse_category_returns_empty_shape(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . "/downloads/{$download_id}/files/browse-category" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array(), $data['files'] );
		$this->assertNull( $data['category'] );
	}

	public function test_import_rejects_path_traversal(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();

		$req = new WP_REST_Request( 'POST', self::NS . "/downloads/{$download_id}/files/import" );
		$req->set_param( 'rel_path', '../../../etc/passwd' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'isoft_fmf_file_not_found', $response->as_error()->get_error_code() );
	}

	public function test_permission_denied_without_edit_post(): void {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );
		$download_id = $this->make_download();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . "/downloads/{$download_id}/files/browse-category" ) );

		$this->assertSame( 403, $response->get_status() );
	}
}
