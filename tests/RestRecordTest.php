<?php
/**
 * POST /files/{id}/record: report a download served elsewhere. Authenticated,
 * idempotent, and never stamps the caller's own identity on the row.
 */
class RestRecordTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $file_id;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server, $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_download_log" );

		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$download_id   = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		$this->file_id = (int) ( new ISOFT_FMF_File_Manager() )->add_local_file(
			$download_id,
			array(
				'file_name' => 'a.pdf',
				'file_path' => 'x/a.pdf',
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function post( int $file_id, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/isoft-fm-foundation/v1/files/{$file_id}/record" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	private function become_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ISOFT_FMF_Activator::maybe_register_capabilities();
	}

	public function test_anonymous_and_unprivileged_users_are_refused(): void {
		wp_set_current_user( 0 );
		$this->assertContains( $this->post( $this->file_id )->get_status(), array( 401, 403 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->post( $this->file_id )->get_status() );
	}

	public function test_a_reporter_with_only_the_record_capability_can_record_but_not_change_settings(): void {
		$reporter = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $reporter )->add_cap( 'isoft_fmf_record_downloads' );
		wp_set_current_user( $reporter );

		$this->assertSame( 201, $this->post( $this->file_id )->get_status() );
		$this->assertFalse( current_user_can( 'isoft_fmf_manage_settings' ) );
	}

	public function test_settings_capability_alone_is_not_enough(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $user )->add_cap( 'isoft_fmf_manage_settings' );
		wp_set_current_user( $user );

		$this->assertSame( 403, $this->post( $this->file_id )->get_status() );
	}

	public function test_administrators_hold_the_record_capability(): void {
		$this->become_admin();

		$this->assertTrue( current_user_can( 'isoft_fmf_record_downloads' ) );
	}

	public function test_admin_can_record_and_the_row_is_not_stamped_with_their_identity(): void {
		global $wpdb;
		$this->become_admin();

		$response = $this->post( $this->file_id, array( 'source' => 'edge' ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse( $response->get_data()['duplicate'] );
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}isoft_fmf_download_log ORDER BY id DESC LIMIT 1" );
		$this->assertNull( $row->user_id );
	}

	public function test_retry_with_the_same_key_is_a_duplicate(): void {
		global $wpdb;
		$this->become_admin();

		$first  = $this->post( $this->file_id, array( 'idempotency_key' => 'req-1' ) );
		$second = $this->post( $this->file_id, array( 'idempotency_key' => 'req-1' ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['duplicate'] );
		$this->assertSame( $first->get_data()['log_id'], $second->get_data()['log_id'] );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" ) );
	}

	public function test_unknown_file_is_404(): void {
		$this->become_admin();

		$this->assertSame( 404, $this->post( 999999 )->get_status() );
	}

	public function test_bad_idempotency_key_is_rejected(): void {
		$this->become_admin();

		$this->assertSame( 400, $this->post( $this->file_id, array( 'idempotency_key' => 'has space' ) )->get_status() );
	}
}
