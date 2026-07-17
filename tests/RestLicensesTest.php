<?php
/**
 * Integration tests for the /licenses REST routes.
 *
 * Covers happy paths and the most important sad paths (404, 400, perms).
 * The full role x route permission matrix lives in RestPermissionsTest.
 */

class RestLicensesTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private const NS = '/isoft-fm-foundation/v1';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server, $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_licenses" );
		wp_cache_flush();

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

	private function login_as_editor(): int {
		$id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $id );
		return $id;
	}

	private function seed_license( string $title = 'Seed License' ): int {
		return ( new ISOFT_FMF_License_Service() )->create( array( 'title' => $title ) );
	}

	public function test_list_returns_array(): void {
		$this->login_as_editor();
		$this->seed_license( 'One' );
		$this->seed_license( 'Two' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/licenses' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 2, $data );
		$this->assertSame( 'One', $data[0]['title'] );
	}

	public function test_get_returns_license(): void {
		$this->login_as_editor();
		$id = $this->seed_license( 'GetTest' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . "/licenses/{$id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'GetTest', $response->get_data()['title'] );
	}

	public function test_get_returns_404_for_missing(): void {
		$this->login_as_editor();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/licenses/99999' ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_create_persists_row(): void {
		$this->login_as_admin();
		$req = new WP_REST_Request( 'POST', self::NS . '/licenses' );
		$req->set_param( 'title', 'Created License' );
		$req->set_param( 'url', 'https://example.org/lic' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Created License', $data['title'] );
		$this->assertGreaterThan( 0, $data['id'] );
	}

	public function test_create_returns_400_without_title(): void {
		$this->login_as_admin();
		$req = new WP_REST_Request( 'POST', self::NS . '/licenses' );
		$response = $this->server->dispatch( $req );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_update_changes_row(): void {
		$this->login_as_admin();
		$id = $this->seed_license( 'Before' );

		$req = new WP_REST_Request( 'PUT', self::NS . "/licenses/{$id}" );
		$req->set_param( 'title', 'After' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'After', $response->get_data()['title'] );
	}

	public function test_update_returns_404_for_missing(): void {
		$this->login_as_admin();
		$req = new WP_REST_Request( 'PUT', self::NS . '/licenses/99999' );
		$req->set_param( 'title', 'X' );
		$this->assertSame( 404, $this->server->dispatch( $req )->get_status() );
	}

	public function test_delete_removes_row(): void {
		$this->login_as_admin();
		$id = $this->seed_license( 'Doomed' );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', self::NS . "/licenses/{$id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['id'] );
		$this->assertNull( ( new ISOFT_FMF_License_Service() )->get( $id ) );
	}

	public function test_delete_returns_404_for_missing(): void {
		$this->login_as_admin();
		$this->assertSame(
			404,
			$this->server->dispatch( new WP_REST_Request( 'DELETE', self::NS . '/licenses/99999' ) )->get_status()
		);
	}

	public function test_restore_seeds_installs_missing(): void {
		$this->login_as_admin();
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', self::NS . '/licenses/restore-seeds' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			count( ISOFT_FMF_License_Service::seed_defaults() ),
			$response->get_data()['restored']
		);
	}

	public function test_restore_seeds_is_idempotent_on_second_call(): void {
		$this->login_as_admin();
		$this->server->dispatch( new WP_REST_Request( 'POST', self::NS . '/licenses/restore-seeds' ) );
		$second = $this->server->dispatch( new WP_REST_Request( 'POST', self::NS . '/licenses/restore-seeds' ) );

		$this->assertSame( 0, $second->get_data()['restored'] );
	}
}
