<?php
/**
 * Resource limits for heavy tasks: the shared budget, the integrity scan in
 * slices, PDF thumbnails in the background, ZIP bundle caps and the search
 * for a missing file.
 */

class TaskBudgetTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $cleanup = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( 'isoft_fmf_task_stops' );
		delete_option( 'isoft_fmf_admin_notices' );
		delete_option( 'isoft_fmf_integrity_progress' );
		delete_option( 'isoft_fmf_integrity_running' );
		delete_option( ISOFT_FMF_Pdf_Thumbnail::RUNNING_OPTION );
		wp_unschedule_hook( ISOFT_FMF_File_Integrity::CONTINUE_HOOK );
		wp_unschedule_hook( ISOFT_FMF_Pdf_Thumbnail::CRON_HOOK );
	}

	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		remove_all_filters( 'isoft_fmf_task_budget' );
		remove_all_filters( 'isoft_fmf_bundle_limits' );
		remove_all_filters( 'isoft_fmf_pdf_thumbnail_backend' );
		remove_all_filters( 'isoft_fmf_pdf_thumbnail_async' );
		remove_all_filters( 'isoft_fmf_pdf_thumbnail_limits' );
		update_option( 'isoft_fmf_enable_pdf_thumbnails', 0 );
		isoft_fmf_get_settings( true );
		parent::tear_down();
	}

	private function budget_for( string $only_task, float $seconds ): void {
		add_filter(
			'isoft_fmf_task_budget',
			static function ( array $limits, string $task ) use ( $only_task, $seconds ): array {
				if ( $task === $only_task ) {
					$limits['seconds'] = $seconds;
				}
				return $limits;
			},
			10,
			2
		);
	}

	private function download(): int {
		$term = wp_insert_term( 'Budget ' . wp_generate_password( 5, false ), 'isoft_fmf_category' );
		$id   = (int) isoft_fmf_create_draft_download( array( 'title' => 'Budget host' ) );
		wp_set_object_terms( $id, array( (int) $term['term_id'] ), 'isoft_fmf_category' );
		return $id;
	}

	private function file( int $download_id, string $name, string $content ): int {
		$src = sys_get_temp_dir() . '/' . wp_generate_password( 6, false ) . '-' . $name;
		file_put_contents( $src, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$id = isoft_fmf_attach_file( $download_id, $src, array( 'mode' => 'move' ) );
		$this->assertIsInt( $id );
		$this->cleanup[] = isoft_fmf_files_dir() . '/' . ( new ISOFT_FMF_File_Manager() )->get_file( $id )->file_path;
		return $id;
	}

	// -------------------------------------------------------------------------
	// The budget
	// -------------------------------------------------------------------------

	public function test_budget_runs_out_of_time(): void {
		$budget = new ISOFT_FMF_Task_Budget( 'test', 0.0 );

		$this->assertTrue( $budget->exhausted() );
		$this->assertSame( 'time', $budget->reason() );
	}

	public function test_budget_is_filterable_per_task(): void {
		$this->budget_for( 'integrity', 0.0 );

		$this->assertTrue( ( new ISOFT_FMF_Task_Budget( 'integrity' ) )->exhausted() );
		$this->assertFalse( ( new ISOFT_FMF_Task_Budget( 'bundle' ) )->exhausted() );
	}

	public function test_guard_contains_errors_and_reports_them_once(): void {
		$stops = array();
		add_action(
			'isoft_fmf_task_stopped',
			static function ( string $task, string $reason ) use ( &$stops ): void {
				$stops[] = "$task:$reason";
			},
			10,
			2
		);

		foreach ( array( 1, 2 ) as $attempt ) {
			$result = ISOFT_FMF_Task_Budget::guard(
				'pdf_thumbnail',
				static function () use ( $attempt ): void {
					throw new RuntimeException( "boom $attempt" );
				},
				'fallback'
			);
			$this->assertSame( 'fallback', $result );
		}

		$this->assertSame( array( 'pdf_thumbnail:error', 'pdf_thumbnail:error' ), $stops );
		$this->assertCount( 2, ISOFT_FMF_Task_Budget::recent_stops() );
		$this->assertSame( 'boom 2', ISOFT_FMF_Task_Budget::recent_stops()[0]['detail'] );
		$this->assertCount( 1, get_option( 'isoft_fmf_admin_notices' ), 'one notice per task and reason' );
		$notice = get_option( 'isoft_fmf_admin_notices' )[0]['message'];
		$this->assertStringContainsString( 'boom 2', $notice, 'the latest detail' );
		$this->assertStringContainsString( '(2 times since your last visit.)', $notice );
	}

	public function test_stops_list_and_notice_queue_are_capped(): void {
		for ( $i = 0; $i < 30; $i++ ) {
			ISOFT_FMF_Task_Budget::report( 'test', 'reason' . $i );
		}

		$this->assertCount( 20, ISOFT_FMF_Task_Budget::recent_stops() );
		$this->assertCount( 20, get_option( 'isoft_fmf_admin_notices' ) );
	}

	public function test_stops_show_in_site_health_info(): void {
		ISOFT_FMF_Task_Budget::report( 'bundle', 'disk', 'needs 2 GB' );

		$info = apply_filters( 'debug_information', array() );

		$this->assertArrayHasKey( 'isoft-fm-foundation-tasks', $info );
		$row = $info['isoft-fm-foundation-tasks']['fields']['stop_0'];
		$this->assertSame( 'ZIP bundle stopped: not enough free disk space. needs 2 GB', $row['value'], 'readable labels' );
		$this->assertStringContainsString( 'bundle / disk', $row['debug'], 'raw keys in the copied site info' );
	}

	// -------------------------------------------------------------------------
	// Integrity scan in slices
	// -------------------------------------------------------------------------

	public function test_integrity_scan_pauses_and_continues_where_it_stopped(): void {
		$download = $this->download();
		$this->file( $download, 'a.txt', 'a' );
		$this->file( $download, 'b.txt', 'b' );
		$this->file( $download, 'c.txt', 'c' );
		$integrity = new ISOFT_FMF_File_Integrity();

		$this->budget_for( 'integrity', 0.0 );
		$first = $integrity->run_scheduled_check();

		$this->assertTrue( $first['partial'] );
		$this->assertSame( 1, $first['checked'], 'each slice makes progress' );
		$this->assertNotFalse( wp_next_scheduled( ISOFT_FMF_File_Integrity::CONTINUE_HOOK ) );
		$this->assertSame( 1, ISOFT_FMF_File_Integrity::progress()['checked'] );
		$this->assertFalse( get_option( 'isoft_fmf_integrity_running', false ), 'lock released between slices' );

		remove_all_filters( 'isoft_fmf_task_budget' );
		$last = $integrity->run_scheduled_check();

		$this->assertArrayNotHasKey( 'partial', $last );
		$this->assertGreaterThanOrEqual( 3, $last['checked'] );
		$this->assertNotEmpty( $last['finished_at'] );
		$this->assertNull( ISOFT_FMF_File_Integrity::progress() );
	}

	public function test_integrity_scan_within_budget_finishes_in_one_run(): void {
		$this->file( $this->download(), 'one.txt', 'x' );

		$summary = ( new ISOFT_FMF_File_Integrity() )->run_scheduled_check();

		$this->assertArrayNotHasKey( 'partial', $summary );
		$this->assertFalse( wp_next_scheduled( ISOFT_FMF_File_Integrity::CONTINUE_HOOK ) );
	}

	// -------------------------------------------------------------------------
	// PDF thumbnails
	// -------------------------------------------------------------------------

	private function enable_thumbnails(): void {
		update_option( 'isoft_fmf_enable_pdf_thumbnails', 1 );
		isoft_fmf_get_settings( true );
		add_filter(
			'isoft_fmf_pdf_thumbnail_backend',
			static function (): string {
				return 'imagick';
			}
		);
	}

	public function test_pdf_upload_schedules_thumbnail_instead_of_rendering(): void {
		$this->enable_thumbnails();
		$download = $this->download();

		$file_id = $this->file( $download, 'report.pdf', "%PDF-1.4\n%%EOF\n" );

		$this->assertNotFalse( wp_next_scheduled( ISOFT_FMF_Pdf_Thumbnail::CRON_HOOK, array( $file_id, $download ) ) );
		$this->assertFalse( has_post_thumbnail( $download ) );
	}

	public function test_thumbnail_is_not_scheduled_when_disabled(): void {
		$download = $this->download();

		$this->file( $download, 'report.pdf', "%PDF-1.4\n%%EOF\n" );

		$this->assertFalse( wp_next_scheduled( ISOFT_FMF_Pdf_Thumbnail::CRON_HOOK ) );
	}

	public function test_thumbnail_too_large_is_refused_and_reported(): void {
		$this->enable_thumbnails();
		add_filter( 'isoft_fmf_pdf_thumbnail_async', '__return_false' );
		add_filter(
			'isoft_fmf_pdf_thumbnail_limits',
			static function ( array $limits ): array {
				$limits['max_file_bytes'] = 4;
				return $limits;
			}
		);

		$this->file( $this->download(), 'big.pdf', "%PDF-1.4\n%%EOF\n" );

		$this->assertSame( 'too_large', ISOFT_FMF_Task_Budget::recent_stops()[0]['reason'] );
	}

	public function test_failed_render_is_reported_and_upload_still_succeeds(): void {
		$this->enable_thumbnails();
		add_filter( 'isoft_fmf_pdf_thumbnail_async', '__return_false' );
		$download = $this->download();

		// Detected as a PDF, but not renderable: Imagick (or its absence) fails the render.
		$file_id = $this->file( $download, 'broken.pdf', "%PDF-1.4
1 0 obj << /Type /Catalog /Pages 9 0 R >> endobj
garbage
" );

		$this->assertNotNull( ( new ISOFT_FMF_File_Manager() )->get_file( $file_id ) );
		$this->assertFalse( has_post_thumbnail( $download ) );
		$this->assertSame( 'pdf_thumbnail', ISOFT_FMF_Task_Budget::recent_stops()[0]['task'] );
		$this->assertSame( 'error', ISOFT_FMF_Task_Budget::recent_stops()[0]['reason'] );
	}

	public function test_render_the_server_killed_is_reported_once_its_mark_is_stale(): void {
		update_option(
			ISOFT_FMF_Pdf_Thumbnail::RUNNING_OPTION,
			array(
				'1:2:old' => array(
					'file_id'     => 1,
					'download_id' => 2,
					'name'        => 'heavy.pdf',
					'started'     => time() - ISOFT_FMF_Pdf_Thumbnail::STALE_AFTER - 1,
				),
				'3:4:new' => array(
					'file_id'     => 3,
					'download_id' => 4,
					'name'        => 'busy.pdf',
					'started'     => time() - 10,
				),
			)
		);
		$thumbs = new ISOFT_FMF_Pdf_Thumbnail();

		$thumbs->report_unfinished();
		$thumbs->report_unfinished();

		$stops = ISOFT_FMF_Task_Budget::recent_stops();
		$this->assertCount( 1, $stops, 'reported once' );
		$this->assertSame( 'killed', $stops[0]['reason'] );
		$this->assertStringContainsString( 'heavy.pdf', $stops[0]['detail'] );
		$this->assertSame( array( '3:4:new' ), array_keys( get_option( ISOFT_FMF_Pdf_Thumbnail::RUNNING_OPTION ) ), 'a render still in its time is left alone' );
		$this->assertStringContainsString( 'was stopped by the server before it finished', get_option( 'isoft_fmf_admin_notices' )[0]['message'] );
	}

	public function test_fatal_error_during_a_render_is_reported_as_time_or_memory(): void {
		$marker = array(
			'file_id'     => 1,
			'download_id' => 2,
			'name'        => 'heavy.pdf',
			'started'     => time(),
		);
		update_option( ISOFT_FMF_Pdf_Thumbnail::RUNNING_OPTION, array( 'a' => $marker, 'b' => $marker ) );
		$thumbs = new ISOFT_FMF_Pdf_Thumbnail();

		$thumbs->report_unfinished_render( 'a', array( 'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)' ) );
		$thumbs->report_unfinished_render( 'b', array( 'message' => 'Maximum execution time of 30 seconds exceeded' ) );

		$reasons = wp_list_pluck( ISOFT_FMF_Task_Budget::recent_stops(), 'reason' );
		$this->assertSame( array( 'time', 'memory' ), $reasons );
		$this->assertSame( array(), get_option( ISOFT_FMF_Pdf_Thumbnail::RUNNING_OPTION ) );
	}

	public function test_finished_render_leaves_no_mark(): void {
		$this->enable_thumbnails();
		add_filter( 'isoft_fmf_pdf_thumbnail_async', '__return_false' );

		$this->file( $this->download(), 'broken.pdf', "%PDF-1.4\n%%EOF\n" );

		$this->assertSame( array(), get_option( ISOFT_FMF_Pdf_Thumbnail::RUNNING_OPTION ) );
	}

	public function test_render_restores_unlimited_imagick_limits(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick is not available.' );
		}
		$types  = array( Imagick::RESOURCETYPE_TIME, Imagick::RESOURCETYPE_MEMORY, Imagick::RESOURCETYPE_DISK );
		$before = array_map( array( Imagick::class, 'getResourceLimit' ), $types );

		$render = new ReflectionMethod( ISOFT_FMF_Pdf_Thumbnail::class, 'render' );
		$render->setAccessible( true );
		$render->invoke(
			new ISOFT_FMF_Pdf_Thumbnail(),
			sys_get_temp_dir() . '/no-such-file.pdf',
			array(
				'width'   => 10,
				'height'  => 10,
				'quality' => 50,
			),
			ISOFT_FMF_Pdf_Thumbnail::limits()
		);

		foreach ( $types as $i => $type ) {
			$after = Imagick::getResourceLimit( $type );
			$this->assertGreaterThan( 0, $after, "limit $type is not left at 0" );
			$this->assertGreaterThanOrEqual( min( (float) $before[ $i ], (float) PHP_INT_MAX ), (float) $after, "limit $type is restored" );
		}
	}

	/**
	 * A PDF that does not paint its page background (most office exports)
	 * must give a white thumbnail, not a black one.
	 */
	public function test_page_without_a_painted_background_renders_white(): void {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick is not available.' );
		}
		$pdf             = sys_get_temp_dir() . '/' . wp_generate_password( 6, false ) . '-text-only.pdf';
		$this->cleanup[] = $pdf;
		file_put_contents( $pdf, $this->text_only_pdf() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		try {
			$probe = new Imagick();
			$probe->readImage( $pdf . '[0]' );
			$probe->clear();
		} catch ( Throwable $e ) {
			$this->markTestSkipped( 'This ImageMagick cannot read PDFs: ' . $e->getMessage() . ' (time limit ' . Imagick::getResourceLimit( Imagick::RESOURCETYPE_TIME ) . ')' );
		}

		$render = new ReflectionMethod( ISOFT_FMF_Pdf_Thumbnail::class, 'render' );
		$render->setAccessible( true );
		$out = $render->invoke(
			new ISOFT_FMF_Pdf_Thumbnail(),
			$pdf,
			array(
				'width'   => 100,
				'height'  => 100,
				'quality' => 90,
			),
			ISOFT_FMF_Pdf_Thumbnail::limits()
		);
		$this->assertIsString( $out );
		$this->cleanup[] = $out;

		$image  = new Imagick( $out );
		$corner = $image->getImagePixelColor( 2, 2 )->getColor();
		$image->clear();
		$this->assertGreaterThan( 240, $corner['r'], 'the empty page is white' );
		$this->assertGreaterThan( 240, $corner['g'] );
		$this->assertGreaterThan( 240, $corner['b'] );
	}

	/** One page of text and no background, with a valid cross-reference table. */
	private function text_only_pdf(): string {
		$stream  = "BT /F1 24 Tf 20 100 Td (Hello) Tj ET";
		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		);
		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $i => $body ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		return $pdf . 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	}

	// -------------------------------------------------------------------------
	// ZIP bundles
	// -------------------------------------------------------------------------

	public function test_bundle_limits_default_and_filter(): void {
		$this->assertTrue( isoft_fmf_bundle_fits( 100, 512 * MB_IN_BYTES ) );
		$this->assertFalse( isoft_fmf_bundle_fits( 101, 1 ) );
		$this->assertFalse( isoft_fmf_bundle_fits( 2, 512 * MB_IN_BYTES + 1 ) );

		add_filter(
			'isoft_fmf_bundle_limits',
			static function (): array {
				return array(
					'max_files' => 0,
					'max_bytes' => 0,
				);
			}
		);
		$this->assertTrue( isoft_fmf_bundle_fits( 100000, PHP_INT_MAX ), '0 means no limit' );
	}

	public function test_public_api_offers_no_bundle_over_the_limit(): void {
		global $wp_rest_server;
		update_option( 'isoft_fmf_enable_zip_bundle', 1 );
		update_option( 'isoft_fmf_public_api_enabled', 1 );
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$download = $this->download();
		wp_update_post(
			array(
				'ID'          => $download,
				'post_status' => 'publish',
			)
		);
		( new ISOFT_FMF_Access_Control() )->recompute_effective_role( $download );
		$this->file( $download, 'one.txt', 'one' );
		$this->file( $download, 'two.txt', 'two' );

		$bundle_url = static function () use ( $download, $wp_rest_server ) {
			$response = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/isoft-fm-foundation/v1/public/downloads/' . $download ) );
			$data = $response->get_data();
			return array_key_exists( 'bundle_url', $data ) ? $data['bundle_url'] : 'missing';
		};

		$this->assertIsString( $bundle_url() );
		$this->assertNotSame( 'missing', $bundle_url() );

		add_filter(
			'isoft_fmf_bundle_limits',
			static function (): array {
				return array(
					'max_files' => 1,
					'max_bytes' => 0,
				);
			}
		);
		$this->assertNull( $bundle_url() );

		$wp_rest_server = null;
		delete_option( 'isoft_fmf_public_api_enabled' );
		update_option( 'isoft_fmf_enable_zip_bundle', 0 );
	}

	// -------------------------------------------------------------------------
	// Search for a missing file
	// -------------------------------------------------------------------------

	public function test_search_for_a_missing_file_stops_on_budget(): void {
		$download = $this->download();
		$file     = ( new ISOFT_FMF_File_Manager() )->get_file( $this->file( $download, 'lost.txt', 'lost content' ) );

		$this->budget_for( 'file_search', 0.0 );

		$this->assertNull( ISOFT_FMF_File_Integrity::find_by_hash_anywhere( $file ) );
		$this->assertSame( 'file_search', ISOFT_FMF_Task_Budget::recent_stops()[0]['task'] );

		remove_all_filters( 'isoft_fmf_task_budget' );
		$this->assertNotNull( ISOFT_FMF_File_Integrity::find_by_hash_anywhere( $file ), 'found with a normal budget' );
	}
}
