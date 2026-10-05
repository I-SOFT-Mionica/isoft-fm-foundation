<?php
/**
 * isoft_fmf_record_download() and ISOFT_FMF_Download_Logger::log() context:
 * recording downloads that were served somewhere else (edge, CDN) after the
 * fact, with their own time and without the importing request's identity.
 *
 * Detailed (PII) logging is off by default and isoft_fmf_get_settings() is
 * cached per request, so these tests run with detailed logging off — which
 * is also the case that matters most: no IP may be stored.
 */
class DownloadRecordingTest extends WP_UnitTestCase {

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
				'post_title'  => 'Recorded',
			)
		);
		$this->file_id     = (int) ( new ISOFT_FMF_File_Manager() )->add_local_file(
			$this->download_id,
			array(
				'title'     => 'Guide',
				'file_name' => 'guide.pdf',
				'file_path' => 'guides/guide.pdf',
				'file_size' => 10,
				'file_mime' => 'application/pdf',
			)
		);
	}

	private function last_log_row(): ?object {
		global $wpdb;
		return $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}isoft_fmf_download_log ORDER BY id DESC LIMIT 1" );
	}

	public function test_context_time_sets_row_time_and_daily_bucket_date(): void {
		$time = gmmktime( 10, 30, 0, 3, 15, 2026 );

		isoft_fmf_record_download( $this->file_id, array( 'time' => $time ) );

		$row = $this->last_log_row();
		$this->assertSame( wp_date( 'Y-m-d H:i:s', $time ), $row->downloaded_at );
		$this->assertSame( '2026-03-15', $row->log_date );

		global $wpdb;
		$bucket = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count FROM {$wpdb->prefix}isoft_fmf_download_daily WHERE download_id = %d AND log_date = %s",
				$this->download_id,
				'2026-03-15'
			)
		);
		$this->assertSame( '1', $bucket );
	}

	public function test_context_user_id_overrides_the_importing_user(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		isoft_fmf_record_download( $this->file_id, array( 'user_id' => 0 ) );

		$row = $this->last_log_row();
		$this->assertNull( $row->user_id );
		$this->assertNull( $row->user_login );
	}

	public function test_no_ip_is_stored_without_detailed_logging(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		isoft_fmf_record_download(
			$this->file_id,
			array(
				'ip'         => '198.51.100.7',
				'user_agent' => 'Test',
			)
		);

		$row = $this->last_log_row();
		$this->assertNull( $row->ip_address );
		$this->assertNull( $row->user_agent );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_default_behaviour_without_context_is_unchanged(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		( new ISOFT_FMF_Download_Logger() )->log( $this->download_id, $this->file_id );

		$row = $this->last_log_row();
		$this->assertSame( (string) $user_id, $row->user_id );
		$this->assertSame( current_time( 'Y-m-d' ), $row->log_date );
	}

	public function test_record_download_updates_counters(): void {
		isoft_fmf_record_download( $this->file_id );
		isoft_fmf_record_download( $this->file_id );

		$file = ( new ISOFT_FMF_File_Manager() )->get_file( $this->file_id );
		$this->assertSame( 2, (int) $file->download_count );
		$this->assertSame( 2, (int) get_post_meta( $this->download_id, '_isoft_fmf_download_count', true ) );
	}

	public function test_record_download_returns_log_id(): void {
		$log_id = isoft_fmf_record_download( $this->file_id );

		$this->assertIsInt( $log_id );
		$this->assertSame( $log_id, (int) $this->last_log_row()->id );
	}

	public function test_unknown_file_records_nothing(): void {
		$this->assertNull( isoft_fmf_record_download( 999999 ) );
		$this->assertNull( $this->last_log_row() );
	}

	public function test_hooks_receive_the_context(): void {
		$seen = array();
		$on_filter = static function ( array $data, array $context ) use ( &$seen ): array {
			$seen['filter'] = $context['source'];
			return $data;
		};
		$on_action = static function ( $log_id, $download_id, $file_id, $user_id, $license_id, array $context ) use ( &$seen ): void {
			$seen['action'] = $context['source'];
		};
		add_filter( 'isoft_fmf_log_entry_data', $on_filter, 10, 2 );
		add_action( 'isoft_fmf_download_logged', $on_action, 10, 6 );

		isoft_fmf_record_download( $this->file_id, array( 'source' => 'edge' ) );

		$this->assertSame(
			array(
				'filter' => 'edge',
				'action' => 'edge',
			),
			$seen
		);
	}

	public function test_built_in_downloads_report_the_handler_as_source(): void {
		$source = null;
		add_filter(
			'isoft_fmf_log_entry_data',
			static function ( array $data, array $context ) use ( &$source ): array {
				$source = $context['source'];
				return $data;
			},
			10,
			2
		);

		isoft_fmf_record_download( $this->file_id );

		$this->assertSame( 'handler', $source );
	}
}
