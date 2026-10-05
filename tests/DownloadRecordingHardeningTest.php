<?php
/**
 * Hardening of the "record a download served elsewhere" API: untrusted
 * context values are validated, retries are idempotent, and the license
 * that governed the download can be supplied by the caller.
 */
class DownloadRecordingHardeningTest extends WP_UnitTestCase {

	private int $download_id;
	private int $file_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_download_log" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_download_daily" );

		$this->download_id = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		$this->file_id     = (int) ( new ISOFT_FMF_File_Manager() )->add_local_file(
			$this->download_id,
			array(
				'file_name' => 'a.pdf',
				'file_path' => 'x/a.pdf',
			)
		);
	}

	private function row( int $log_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}isoft_fmf_download_log WHERE id = %d", $log_id ) );
	}

	private function log_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" );
	}

	private function file_count(): int {
		return (int) ( new ISOFT_FMF_File_Manager() )->get_file( $this->file_id )->download_count;
	}

	public function test_invalid_times_fall_back_to_now(): void {
		foreach ( array( 0, -5, 'yesterday', null, time() + DAY_IN_SECONDS ) as $bad ) {
			$log_id = isoft_fmf_record_download( $this->file_id, array( 'time' => $bad ) );
			$row    = $this->row( (int) $log_id );

			$this->assertEqualsWithDelta( time(), strtotime( get_gmt_from_date( $row->downloaded_at ) . ' UTC' ), 5, 'bad time: ' . wp_json_encode( $bad ) );
		}
	}

	public function test_valid_past_time_is_kept(): void {
		$time   = time() - 3 * DAY_IN_SECONDS;
		$log_id = isoft_fmf_record_download( $this->file_id, array( 'time' => (string) $time ) );

		$this->assertSame( wp_date( 'Y-m-d H:i:s', $time ), $this->row( (int) $log_id )->downloaded_at );
	}

	public function test_source_is_sanitised_for_hooks(): void {
		$seen = array();
		add_action(
			'isoft_fmf_download_logged',
			function ( $log_id, $download_id, $file_id, $user_id, $license, $context ) use ( &$seen ): void {
				$seen[] = $context['source'];
			},
			10,
			6
		);

		isoft_fmf_record_download( $this->file_id, array( 'source' => 'Edge Server <b>!' ) );
		isoft_fmf_record_download( $this->file_id, array( 'source' => '' ) );
		isoft_fmf_record_download( $this->file_id, array( 'source' => str_repeat( 'a', 80 ) ) );

		$this->assertSame( array( 'edgeserverb', 'handler', str_repeat( 'a', 32 ) ), $seen );
	}

	public function test_idempotency_key_counts_a_download_once(): void {
		$context = array( 'idempotency_key' => 'edge:req-1' );

		$first  = isoft_fmf_record_download( $this->file_id, $context );
		$second = isoft_fmf_record_download( $this->file_id, $context );

		$this->assertNotNull( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->log_count() );
		$this->assertSame( 1, $this->file_count() );
	}

	public function test_different_keys_or_no_key_each_count(): void {
		isoft_fmf_record_download( $this->file_id, array( 'idempotency_key' => 'a' ) );
		isoft_fmf_record_download( $this->file_id, array( 'idempotency_key' => 'b' ) );
		isoft_fmf_record_download( $this->file_id );
		isoft_fmf_record_download( $this->file_id );

		$this->assertSame( 4, $this->log_count() );
		$this->assertSame( 4, $this->file_count() );
	}

	public function test_same_key_on_another_file_is_a_different_download(): void {
		$other = (int) ( new ISOFT_FMF_File_Manager() )->add_local_file(
			$this->download_id,
			array(
				'file_name' => 'b.pdf',
				'file_path' => 'x/b.pdf',
			)
		);

		isoft_fmf_record_download( $this->file_id, array( 'idempotency_key' => 'k' ) );
		isoft_fmf_record_download( $other, array( 'idempotency_key' => 'k' ) );

		$this->assertSame( 2, $this->log_count() );
	}

	public function test_malformed_idempotency_key_is_ignored(): void {
		isoft_fmf_record_download( $this->file_id, array( 'idempotency_key' => 'bad key!' ) );
		isoft_fmf_record_download( $this->file_id, array( 'idempotency_key' => 'bad key!' ) );

		$this->assertSame( 2, $this->log_count() );
	}

	public function test_idempotency_key_is_stored_on_the_row(): void {
		$log_id = isoft_fmf_record_download( $this->file_id, array( 'idempotency_key' => 'abc.123' ) );

		$this->assertSame( 'abc.123', $this->row( (int) $log_id )->idempotency_key );
	}

	public function test_caller_can_supply_the_license_that_governed_the_download(): void {
		$license_id = ( new ISOFT_FMF_License_Service() )->create(
			array(
				'title' => 'Old licence',
				'slug'  => 'old-licence',
			)
		);

		$log_id = isoft_fmf_record_download( $this->file_id, array( 'license_id' => $license_id ) );

		$this->assertSame( (string) $license_id, $this->row( (int) $log_id )->license_id_at_download );
	}

	public function test_settings_can_be_refreshed_within_a_request(): void {
		update_option( 'isoft_fmf_enable_detailed_logging', 0 );
		$this->assertFalse( isoft_fmf_get_settings( true )['enable_detailed_logging'] );

		update_option( 'isoft_fmf_enable_detailed_logging', 1 );
		$this->assertTrue( isoft_fmf_get_settings( true )['enable_detailed_logging'] );

		$log_id = isoft_fmf_record_download(
			$this->file_id,
			array(
				'ip'         => '198.51.100.7',
				'user_agent' => 'UA',
				'referer'    => null,
			)
		);
		$row    = $this->row( (int) $log_id );
		$this->assertSame( '198.51.100.7', $row->ip_address );
		$this->assertSame( 'UA', $row->user_agent );
		$this->assertNull( $row->referer );

		delete_option( 'isoft_fmf_enable_detailed_logging' );
		isoft_fmf_get_settings( true );
	}

	public function test_invalid_ip_is_stored_as_null(): void {
		update_option( 'isoft_fmf_enable_detailed_logging', 1 );
		isoft_fmf_get_settings( true );

		$log_id = isoft_fmf_record_download( $this->file_id, array( 'ip' => 'not-an-ip' ) );

		$this->assertNull( $this->row( (int) $log_id )->ip_address );

		delete_option( 'isoft_fmf_enable_detailed_logging' );
		isoft_fmf_get_settings( true );
	}
}
