<?php
/**
 * Files whose content lives elsewhere: creating them, delivering them only
 * after every check has passed, and keeping them away from code that expects
 * a file on disk.
 */

/** The real handler, without the parts that end the process or touch PHP's output. */
class Remote_File_Test_Handler extends ISOFT_FMF_Download_Handler {
	public array $sent = array();
	public int $status = 200;
	public ?int $unavailable = null;

	protected function send_headers( array $headers ): void {
		$this->sent = $headers;
	}

	protected function send_status( int $status ): void {
		$this->status = $status;
	}

	protected function prepare_stream(): void {}

	protected function finish(): void {}

	protected function remote_unavailable( int $download_id ): void {
		$this->unavailable = $download_id;
	}
}

class Remote_File_Redirected extends Exception {}

class RemoteFileTest extends WP_UnitTestCase {

	private int $download;
	private int $file;
	private Remote_File_Test_Handler $handler;
	private int $filter_calls = 0;

	public function set_up(): void {
		parent::set_up();
		$this->download = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		$this->file     = (int) isoft_fmf_attach_remote_file(
			$this->download,
			array(
				'file_name' => 'plan.pdf',
				'file_size' => 2048,
				'file_mime' => 'application/pdf',
			)
		);
		$this->handler  = new Remote_File_Test_Handler();
		set_query_var( 'isoft_fmf_download', $this->file );
		$_GET['nonce'] = wp_create_nonce( 'isoft_fmf_download_' . $this->file );
	}

	public function tear_down(): void {
		remove_all_filters( 'isoft_fmf_resolve_remote_file' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'isoft_fmf_bundle_limits' );
		delete_option( 'isoft_fmf_enable_zip_bundle' );
		delete_option( 'isoft_fmf_public_api_enabled' );
		unset( $_GET['nonce'], $_SERVER['HTTP_RANGE'] );
		set_query_var( 'isoft_fmf_download', 0 );
		parent::tear_down();
	}

	private function row( ?int $id = null ): ?object {
		return ( new ISOFT_FMF_File_Manager() )->get_file( $id ?? $this->file );
	}

	private function times_counted(): int {
		return (int) $this->row()->download_count;
	}

	private function answer( $resolution ): void {
		add_filter(
			'isoft_fmf_resolve_remote_file',
			function () use ( $resolution ) {
				++$this->filter_calls;
				return $resolution;
			}
		);
	}

	private function proxy( array $extra = array() ): array {
		return array_merge(
			array(
				'mode'   => 'proxy',
				'stream' => static function (): void {
					echo 'BODY';
				},
			),
			$extra
		);
	}

	/** Run the handler and return what the stream wrote. */
	private function run_handler(): string {
		ob_start();
		try {
			$this->handler->handle();
		} finally {
			$out = (string) ob_get_clean();
		}
		return $out;
	}

	private function redirected_to(): ?string {
		$target = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$target ) {
				$target = $location;
				throw new Remote_File_Redirected();
			}
		);
		try {
			$this->run_handler();
		} catch ( Remote_File_Redirected $e ) {
			unset( $e );
		}
		return $target;
	}

	// Creating.

	public function test_a_remote_file_holds_no_path_or_url(): void {
		$row = $this->row();

		$this->assertSame( 'remote', $row->file_type );
		$this->assertSame( 'plan.pdf', $row->file_name );
		$this->assertSame( 2048, (int) $row->file_size );
		$this->assertEmpty( $row->file_path );
		$this->assertEmpty( $row->external_url );
		$this->assertTrue( isoft_fmf_has_feature( 'remote_files' ) );
	}

	public function test_creating_one_announces_it_like_any_other_file(): void {
		$uploaded = 0;
		$hook     = static function () use ( &$uploaded ): void {
			++$uploaded;
		};
		add_action( 'isoft_fmf_file_uploaded', $hook );

		isoft_fmf_attach_remote_file( $this->download, array( 'file_name' => 'second.pdf' ) );

		remove_action( 'isoft_fmf_file_uploaded', $hook );
		$this->assertSame( 1, $uploaded );
	}

	/**
	 * @dataProvider blocked_names
	 */
	public function test_a_blocked_type_is_refused( string $name ): void {
		$result = isoft_fmf_attach_remote_file( $this->download, array( 'file_name' => $name ) );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_blocked_file_type', $result->get_error_code() );
	}

	public static function blocked_names(): array {
		return array(
			'plain'     => array( 'evil.php' ),
			'uppercase' => array( 'EVIL.PHP' ),
			'phtml'     => array( 'page.phtml' ),
		);
	}

	public function test_renaming_to_a_blocked_type_is_refused(): void {
		$this->assertWPError( isoft_fmf_update_remote_file( $this->file, array( 'file_name' => 'evil.php' ) ) );
		$this->assertSame( 'plan.pdf', $this->row()->file_name );
	}

	public function test_a_hostile_name_loses_its_path(): void {
		$id   = isoft_fmf_attach_remote_file( $this->download, array( 'file_name' => '../../etc/passwd.txt' ) );
		$name = $this->row( $id )->file_name;

		$this->assertStringNotContainsString( '/', $name );
		$this->assertStringNotContainsString( '..', $name );
	}

	public function test_it_needs_a_name_and_a_real_download(): void {
		$this->assertWPError( isoft_fmf_attach_remote_file( $this->download, array( 'file_name' => '' ) ) );
		$this->assertWPError( isoft_fmf_attach_remote_file( 999999, array( 'file_name' => 'a.pdf' ) ) );
	}

	public function test_update_changes_only_what_is_passed(): void {
		$this->assertTrue( isoft_fmf_update_remote_file( $this->file, array( 'file_size' => 9000 ) ) );

		$row = $this->row();
		$this->assertSame( 9000, (int) $row->file_size );
		$this->assertSame( 'plan.pdf', $row->file_name );
	}

	public function test_update_refuses_a_file_that_is_not_remote(): void {
		$link = ( new ISOFT_FMF_Files_Service() )->add_external( $this->download, 'https://example.org/doc' );
		$this->assertWPError( isoft_fmf_update_remote_file( (int) $link, array( 'file_size' => 1 ) ) );
	}

	public function test_deleting_one_leaves_the_owner_a_chance_to_clean_up(): void {
		$seen = 0;
		$hook = static function () use ( &$seen ): void {
			++$seen;
		};
		add_action( 'isoft_fmf_file_deleted', $hook );

		$this->assertTrue( ( new ISOFT_FMF_Files_Service() )->delete( $this->file ) );

		remove_action( 'isoft_fmf_file_deleted', $hook );
		$this->assertSame( 1, $seen );
		$this->assertNull( $this->row() );
	}

	// Delivering.

	public function test_a_redirect_goes_to_the_address_and_counts(): void {
		$this->answer(
			array(
				'mode' => 'redirect',
				'url'  => 'https://cloud.example/signed?x=1',
			)
		);

		$this->assertSame( 'https://cloud.example/signed?x=1', $this->redirected_to() );
		$this->assertSame( 1, $this->times_counted() );
	}

	public function test_a_redirect_to_a_script_address_is_unavailable(): void {
		$this->answer(
			array(
				'mode' => 'redirect',
				'url'  => 'javascript:alert(1)',
			)
		);

		$this->assertNull( $this->redirected_to() );
		$this->assertSame( $this->download, $this->handler->unavailable );
		$this->assertSame( 0, $this->times_counted() );
	}

	public function test_a_proxy_streams_the_body_and_counts(): void {
		$this->answer( $this->proxy() );

		$this->assertSame( 'BODY', $this->run_handler() );
		$this->assertSame( 1, $this->times_counted() );
		$this->assertSame( 'nosniff', $this->handler->sent['X-Content-Type-Options'] );
		$this->assertStringStartsWith( 'attachment;', $this->handler->sent['Content-Disposition'] );
	}

	public function test_a_proxy_can_only_describe_the_body(): void {
		$this->answer(
			$this->proxy(
				array(
					'status'  => 206,
					'headers' => array(
						'Content-Length'         => '10',
						'Content-Range'          => "bytes 0-9/100\r\nSet-Cookie: a=b",
						'Set-Cookie'             => 'a=b',
						'Content-Disposition'    => 'inline',
						'X-Content-Type-Options' => 'off',
						'Location'               => 'https://elsewhere.example/',
					),
				)
			)
		);

		$this->run_handler();

		$sent = $this->handler->sent;
		$this->assertSame( 206, $this->handler->status );
		$this->assertSame( '10', $sent['Content-Length'] );
		$this->assertStringNotContainsString( "\n", $sent['Content-Range'] );
		$this->assertArrayNotHasKey( 'Set-Cookie', $sent );
		$this->assertArrayNotHasKey( 'Location', $sent );
		$this->assertStringStartsWith( 'attachment;', $sent['Content-Disposition'] );
		$this->assertSame( 'nosniff', $sent['X-Content-Type-Options'] );
	}

	public function test_a_stream_that_throws_ends_quietly(): void {
		$this->answer(
			$this->proxy(
				array(
					'stream' => static function (): void {
						throw new RuntimeException( 'cloud went away' );
					},
				)
			)
		);

		$this->assertSame( '', $this->run_handler() );
	}

	/**
	 * @dataProvider no_answers
	 */
	public function test_no_usable_answer_is_unavailable_and_not_counted( $resolution ): void {
		$this->answer( $resolution );

		$this->run_handler();

		$this->assertSame( $this->download, $this->handler->unavailable );
		$this->assertSame( 0, $this->times_counted() );
	}

	public static function no_answers(): array {
		return array(
			'null'            => array( null ),
			'error'           => array( new WP_Error( 'changed', 'Content no longer matches' ) ),
			'unknown mode'    => array( array( 'mode' => 'teleport' ) ),
			'no stream'       => array( array( 'mode' => 'proxy' ) ),
			'bad stream'      => array( array( 'mode' => 'proxy', 'stream' => 'no_such_function_here' ) ),
			'no url'          => array( array( 'mode' => 'redirect' ) ),
			'not an array'    => array( 'https://cloud.example/x' ),
		);
	}

	public function test_with_nothing_answering_it_is_unavailable(): void {
		$this->run_handler();

		$this->assertSame( $this->download, $this->handler->unavailable );
	}

	public function test_a_filter_that_throws_is_unavailable(): void {
		add_filter(
			'isoft_fmf_resolve_remote_file',
			static function (): void {
				throw new RuntimeException( 'boom' );
			}
		);

		$this->run_handler();

		$this->assertSame( $this->download, $this->handler->unavailable );
	}

	public function test_the_filter_gets_the_range(): void {
		$_SERVER['HTTP_RANGE'] = 'bytes=500-';
		$seen                  = null;
		add_filter(
			'isoft_fmf_resolve_remote_file',
			static function ( $resolution, $file, $context ) use ( &$seen ) {
				$seen = $context;
				return $resolution;
			},
			10,
			3
		);

		$this->run_handler();

		$this->assertSame( 'bytes=500-', $seen['range'] );
		$this->assertSame( $this->download, $seen['download_id'] );
	}

	/**
	 * @dataProvider ranges
	 */
	public function test_only_a_request_from_the_start_counts( string $range, int $expected ): void {
		$_SERVER['HTTP_RANGE'] = $range;
		$this->answer( $this->proxy() );

		$this->run_handler();

		$this->assertSame( $expected, $this->times_counted() );
	}

	public static function ranges(): array {
		return array(
			'from the start'      => array( 'bytes=0-', 1 ),
			'first segment'       => array( 'bytes=0-1023', 1 ),
			'resumed'             => array( 'bytes=500-', 0 ),
			'later segment'       => array( 'bytes=1024-2047', 0 ),
			'the last bytes'      => array( 'bytes=-500', 0 ),
			'zero-like offset'    => array( 'bytes=01-', 0 ),
		);
	}

	public function test_a_refused_visitor_never_reaches_the_filter(): void {
		update_post_meta( $this->download, '_isoft_fmf_access_role', 'administrator' );
		( new ISOFT_FMF_Access_Control() )->recompute_effective_role( $this->download );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['nonce'] = wp_create_nonce( 'isoft_fmf_download_' . $this->file );
		$this->answer( $this->proxy() );

		try {
			$this->run_handler();
			$this->fail( 'The visitor should have been refused.' );
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		$this->assertSame( 0, $this->filter_calls );
		$this->assertSame( 0, $this->times_counted() );
	}

	public function test_an_unavailable_file_never_reaches_the_filter(): void {
		isoft_fmf_set_file_unavailable( $this->file, true );
		$this->answer( $this->proxy() );

		$this->expectException( WPDieException::class );
		try {
			$this->run_handler();
		} finally {
			$this->assertSame( 0, $this->filter_calls );
		}
	}

	public function test_a_draft_never_reaches_the_filter(): void {
		wp_update_post(
			array(
				'ID'          => $this->download,
				'post_status' => 'draft',
			)
		);
		$this->answer( $this->proxy() );

		$this->expectException( WPDieException::class );
		try {
			$this->run_handler();
		} finally {
			$this->assertSame( 0, $this->filter_calls );
		}
	}

	// Staying away from code that expects a file on disk.

	public function test_remote_files_do_not_count_towards_a_bundle(): void {
		update_option( 'isoft_fmf_enable_zip_bundle', 1 );
		add_filter(
			'isoft_fmf_bundle_limits',
			static fn(): array => array(
				'max_files' => 1,
				'max_bytes' => 1,
			)
		);
		$rows = array(
			(object) array(
				'file_type' => 'remote',
				'file_size' => 5000000,
			),
			(object) array(
				'file_type' => 'remote',
				'file_size' => 5000000,
			),
		);

		$this->assertSame( '', isoft_fmf_bundle_limit_note( $rows ) );
	}

	public function test_the_public_api_never_describes_it_as_local(): void {
		$rest   = new ISOFT_FMF_Rest_Public();
		$method = new ReflectionMethod( $rest, 'shape_download' );
		$method->setAccessible( true );

		$shape = $method->invoke( $rest, get_post( $this->download ) );
		$file  = $shape['files'][0];

		$this->assertSame( 'remote', $file['type'] );
		$this->assertNull( $file['url'] );
		$this->assertNotEmpty( $file['download_url'] );
		$this->assertSame( 'plan.pdf', $file['name'] );
		$this->assertStringContainsString( (string) $this->file, $file['download_url'] );
	}

	public function test_a_gateway_is_not_handed_a_path(): void {
		$this->assertNull( isoft_fmf_public_file( $this->file ) );
	}

	public function test_the_integrity_check_ignores_it(): void {
		$this->assertSame( array(), array_filter( isoft_fmf_get_local_files(), fn( array $f ): bool => $f['id'] === $this->file ) );
	}

	public function test_a_card_offers_it_through_the_handler(): void {
		$html = do_shortcode( '[isoft_fmf_download id="' . $this->download . '"]' );

		$this->assertStringContainsString( 'isoft_fmf_download=' . $this->file, $html );
		$this->assertStringContainsString( 'download rel="nofollow"', $html );
		$this->assertStringNotContainsString( 'href=""', $html );
	}

	public function test_the_write_check_is_public(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$term  = (int) wp_insert_term( 'Write ' . wp_generate_password( 6, false ), 'isoft_fmf_category' )['term_id'];

		$this->assertTrue( isoft_fmf_user_can_write_category( $admin, $term ) );
	}
}
