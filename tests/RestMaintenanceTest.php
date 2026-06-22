<?php
/**
 * Integration tests for the /maintenance REST routes.
 *
 * The /maintenance/export route emits bytes and calls exit, so it can't
 * be dispatched through WP_REST_Server without killing the test process.
 * The streaming behaviour is manual-smoke; the underlying string
 * generation is covered by MaintenanceServiceTest.
 */

class RestMaintenanceTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private const NS = '/isoft-fm-foundation/v1';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
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

	private function delete_all_downloads(): void {
		$ids = get_posts(
			array(
				'post_type'      => 'isoft_fmf_file',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	public function test_demo_install_on_empty_returns_200(): void {
		$this->login_as_admin();
		$this->delete_all_downloads();

		$req = new WP_REST_Request( 'POST', self::NS . '/maintenance/demo-content' );
		$req->set_param( 'action', 'install' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['installed'] );
	}

	public function test_demo_install_when_content_exists_returns_409(): void {
		$this->login_as_admin();
		self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);

		$req = new WP_REST_Request( 'POST', self::NS . '/maintenance/demo-content' );
		$req->set_param( 'action', 'install' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 409, $response->get_status() );
	}

	public function test_demo_remove_returns_200(): void {
		$this->login_as_admin();

		$req = new WP_REST_Request( 'POST', self::NS . '/maintenance/demo-content' );
		$req->set_param( 'action', 'remove' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['removed'] );
	}

	public function test_demo_rejects_unknown_action_with_400(): void {
		$this->login_as_admin();

		$req = new WP_REST_Request( 'POST', self::NS . '/maintenance/demo-content' );
		$req->set_param( 'action', 'nope' );

		$response = $this->server->dispatch( $req );

		// Schema enum kicks in at REST layer.
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_bundle_cache_clear_returns_count(): void {
		$this->login_as_admin();

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', self::NS . '/maintenance/bundle-cache' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'deleted', $response->get_data() );
	}

	public function test_log_purge_returns_count(): void {
		$this->login_as_admin();

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', self::NS . '/maintenance/log-purge' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'deleted', $response->get_data() );
	}

	public function test_integrity_returns_status_shape(): void {
		$this->login_as_admin();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', self::NS . '/maintenance/integrity' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'skipped', $response->get_data() );
	}

	public function test_permission_denied_for_subscriber(): void {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', self::NS . '/maintenance/bundle-cache' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_export_uses_separate_cap(): void {
		// An editor with view_logs but not export_logs should be denied
		// from /maintenance/export but allowed on bundle-cache.
		// Default WP roles don't have isoft_fmf_export_logs (Admin-only).
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$req = new WP_REST_Request( 'GET', self::NS . '/maintenance/export' );
		$req->set_param( 'format', 'csv' );

		$response = $this->server->dispatch( $req );

		$this->assertSame( 403, $response->get_status() );
	}
}
