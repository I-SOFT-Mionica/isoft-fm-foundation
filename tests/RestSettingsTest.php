<?php
/**
 * Integration tests for the /settings REST routes.
 */

class RestSettingsTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private const NS = '/isoft-fm-foundation/v1';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		foreach ( ISOFT_FMF_Settings_Service::known_keys() as $key ) {
			delete_option( $key );
		}
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

	public function test_get_returns_every_known_key(): void {
		$this->login_as_admin();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			ISOFT_FMF_Settings_Service::known_keys(),
			array_keys( $response->get_data() )
		);
	}

	public function test_get_schema_returns_group_to_keys_map(): void {
		$this->login_as_admin();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/settings/schema' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'isoft_fmf_general', $data );
		$this->assertContains( 'isoft_fmf_enable_logging', $data['isoft_fmf_general'] );
	}

	public function test_update_persists_partial_body(): void {
		$this->login_as_admin();
		$req = new WP_REST_Request( 'POST', self::NS . '/settings' );
		$req->set_body_params(
			array(
				'isoft_fmf_enable_counting'    => 1,
				'isoft_fmf_log_retention_days' => 45,
			)
		);

		$response = $this->server->dispatch( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', (string) get_option( 'isoft_fmf_enable_counting' ) );
		$this->assertSame( '45', (string) get_option( 'isoft_fmf_log_retention_days' ) );
	}

	public function test_update_rejects_unknown_keys_with_400(): void {
		$this->login_as_admin();
		$req = new WP_REST_Request( 'POST', self::NS . '/settings' );
		$req->set_body_params(
			array(
				'isoft_fmf_enable_counting' => 1,
				'isoft_fmf_not_a_setting'   => 'oops',
			)
		);

		$response = $this->server->dispatch( $req );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'isoft_fmf_settings_unknown_keys', $response->as_error()->get_error_code() );
	}

	public function test_update_returns_400_on_empty_body(): void {
		$this->login_as_admin();
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', self::NS . '/settings' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_update_runs_sanitizer(): void {
		$this->login_as_admin();
		$req = new WP_REST_Request( 'POST', self::NS . '/settings' );
		$req->set_body_params( array( 'isoft_fmf_integrity_check_time' => 'garbage' ) );

		$this->server->dispatch( $req );

		$this->assertSame( '02:30', get_option( 'isoft_fmf_integrity_check_time' ) );
	}

	public function test_flush_rewrite_returns_ok(): void {
		$this->login_as_admin();
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', self::NS . '/settings/flush-rewrite' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['flushed'] );
	}

	public function test_permission_denied_without_cap(): void {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $id );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/settings' ) );
		$this->assertSame( 401, $response->get_status() );
	}
}
