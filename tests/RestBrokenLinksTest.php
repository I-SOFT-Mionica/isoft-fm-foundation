<?php
/**
 * Integration tests for the /broken-links REST routes.
 *
 * Reupload (multipart) is excluded — manual smoke on Local.
 */

class RestBrokenLinksTest extends WP_UnitTestCase {

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
				'post_title'  => 'Broken Links REST Test',
			)
		);
	}

	private function seed_broken_row( int $download_id, string $name = 'gone.pdf' ): int {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'download_id'   => $download_id,
				'file_type'     => 'local',
				'file_name'     => $name,
				'file_path'     => "some-folder/{$name}",
				'is_missing'    => 1,
				'missing_since' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function test_list_returns_total_header_and_items(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$this->seed_broken_row( $download_id, 'a.pdf' );
		$this->seed_broken_row( $download_id, 'b.pdf' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/broken-links' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
	}

	public function test_probe_returns_404_for_missing_file(): void {
		$this->login_as_admin();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/broken-links/99999/probe' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'isoft_fmf_file_not_found', $response->as_error()->get_error_code() );
	}

	public function test_probe_returns_payload_for_existing_row(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$file_id     = $this->seed_broken_row( $download_id );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . "/broken-links/{$file_id}/probe" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $file_id, $data['file_id'] );
		$this->assertSame( $download_id, $data['download_id'] );
		$this->assertFalse( $data['candidate_found'] );
	}

	public function test_recover_detach_drops_row(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$file_id     = $this->seed_broken_row( $download_id );

		$req = new WP_REST_Request( 'POST', self::NS . "/broken-links/{$file_id}/recover" );
		$req->set_param( 'action', 'detach' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( ( new ISOFT_FMF_File_Manager() )->get_file( $file_id ) );
	}

	public function test_recover_rejects_unknown_action(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$file_id     = $this->seed_broken_row( $download_id );

		$req = new WP_REST_Request( 'POST', self::NS . "/broken-links/{$file_id}/recover" );
		$req->set_param( 'action', 'nuke_from_orbit' );

		$response = $this->server->dispatch( $req );

		// Schema enum validation kicks in first; REST returns 400 without
		// reaching our defensive check (which would also 400 if it did).
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_recover_move_back_returns_no_candidate_when_file_gone(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$file_id     = $this->seed_broken_row( $download_id );

		$req = new WP_REST_Request( 'POST', self::NS . "/broken-links/{$file_id}/recover" );
		$req->set_param( 'action', 'move_back' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'isoft_fmf_no_candidate', $response->as_error()->get_error_code() );
	}

	public function test_permission_denied_for_subscriber(): void {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/broken-links' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_reupload_returns_400_without_file(): void {
		$this->login_as_admin();
		$download_id = $this->make_download();
		$file_id     = $this->seed_broken_row( $download_id );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', self::NS . "/broken-links/{$file_id}/reupload" ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'isoft_fmf_no_file', $response->as_error()->get_error_code() );
	}
}
