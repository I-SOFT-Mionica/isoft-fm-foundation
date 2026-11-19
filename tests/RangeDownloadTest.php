<?php
/**
 * Resuming a download: one byte range served by PHP, counted only when it
 * starts at the beginning, and never spliced onto a file that has changed.
 */

/** The real handler, without the parts that end the process or touch PHP's output. */
class Range_Test_Handler extends ISOFT_FMF_Download_Handler {
	public array $sent = array();
	public int $status = 200;

	protected function send_headers( array $headers ): void {
		$this->sent = array_merge( $this->sent, $headers );
	}

	protected function send_status( int $status ): void {
		$this->status = $status;
	}

	protected function prepare_stream(): void {}

	protected function finish(): void {}
}

class RangeDownloadTest extends WP_UnitTestCase {

	private const BODY = '0123456789abcdefghij'; // 20 bytes.

	private int $download;
	private int $file;
	private string $abs;
	private Range_Test_Handler $handler;

	public function set_up(): void {
		parent::set_up();
		$this->download = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		$dir = isoft_fmf_files_dir() . '/range-test';
		wp_mkdir_p( $dir );
		$this->abs = $dir . '/part-' . wp_generate_password( 6, false ) . '.txt';
		file_put_contents( $this->abs, self::BODY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->file    = (int) ( new ISOFT_FMF_File_Manager() )->add_local_file(
			$this->download,
			array(
				'file_name' => basename( $this->abs ),
				'file_path' => 'range-test/' . basename( $this->abs ),
				'file_size' => strlen( self::BODY ),
				'file_mime' => 'text/plain',
			)
		);
		$this->handler = new Range_Test_Handler();
		set_query_var( 'isoft_fmf_download', $this->file );
		$_GET['nonce'] = wp_create_nonce( 'isoft_fmf_download_' . $this->file );
		delete_option( 'isoft_fmf_serve_method' );
	}

	public function tear_down(): void {
		unset( $_GET['nonce'], $_SERVER['HTTP_RANGE'], $_SERVER['HTTP_IF_RANGE'] );
		set_query_var( 'isoft_fmf_download', 0 );
		delete_option( 'isoft_fmf_serve_method' );
		wp_delete_file( $this->abs );
		parent::tear_down();
	}

	private function fetch( string $range = '', string $if_range = '' ): string {
		if ( '' !== $range ) {
			$_SERVER['HTTP_RANGE'] = $range;
		}
		if ( '' !== $if_range ) {
			$_SERVER['HTTP_IF_RANGE'] = $if_range;
		}
		ob_start();
		try {
			$this->handler->handle();
		} finally {
			$out = (string) ob_get_clean();
		}
		return $out;
	}

	private function times_counted(): int {
		return (int) ( new ISOFT_FMF_File_Manager() )->get_file( $this->file )->download_count;
	}

	/**
	 * @dataProvider ranges
	 */
	public function test_parse_range( string $header, int $size, $expected ): void {
		$this->assertSame( $expected, ISOFT_FMF_Download_Handler::parse_range( $header, $size ) );
	}

	public static function ranges(): array {
		return array(
			'from a byte'          => array( 'bytes=5-', 20, array( 5, 19 ) ),
			'closed'               => array( 'bytes=5-9', 20, array( 5, 9 ) ),
			'end past the size'    => array( 'bytes=5-999', 20, array( 5, 19 ) ),
			'last bytes'           => array( 'bytes=-4', 20, array( 16, 19 ) ),
			'more last than size'  => array( 'bytes=-50', 20, array( 0, 19 ) ),
			'spaces and case'      => array( ' Bytes = 0 - 0 ', 20, array( 0, 0 ) ),
			'start past the end'   => array( 'bytes=20-', 20, false ),
			'zero last bytes'      => array( 'bytes=-0', 20, false ),
			'end before start'     => array( 'bytes=9-5', 20, null ),
			'several ranges'       => array( 'bytes=0-1,5-6', 20, null ),
			'no numbers'           => array( 'bytes=-', 20, null ),
			'other unit'           => array( 'items=0-5', 20, null ),
			'garbage'              => array( 'bytes=a-b', 20, null ),
			'too many digits'      => array( 'bytes=1234567890123456789-', 20, null ),
			'empty file'           => array( 'bytes=0-', 0, null ),
		);
	}

	public function test_a_whole_download_says_it_can_resume_and_counts(): void {
		$body = $this->fetch();

		$this->assertSame( self::BODY, $body );
		$this->assertSame( 200, $this->handler->status );
		$this->assertSame( 'bytes', $this->handler->sent['Accept-Ranges'] );
		$this->assertSame( '20', $this->handler->sent['Content-Length'] );
		$this->assertArrayHasKey( 'Last-Modified', $this->handler->sent );
		$this->assertSame( 1, $this->times_counted() );
	}

	public function test_a_resume_gets_the_rest_and_is_not_counted(): void {
		$body = $this->fetch( 'bytes=10-' );

		$this->assertSame( 'abcdefghij', $body );
		$this->assertSame( 206, $this->handler->status );
		$this->assertSame( 'bytes 10-19/20', $this->handler->sent['Content-Range'] );
		$this->assertSame( '10', $this->handler->sent['Content-Length'] );
		$this->assertSame( 0, $this->times_counted() );
	}

	public function test_a_closed_range_stops_at_its_end(): void {
		$this->assertSame( '345', $this->fetch( 'bytes=3-5' ) );
		$this->assertSame( '3', $this->handler->sent['Content-Length'] );
	}

	public function test_a_range_from_the_start_counts_once(): void {
		$this->assertSame( '01234', $this->fetch( 'bytes=0-4' ) );
		$this->assertSame( 206, $this->handler->status );
		$this->assertSame( 1, $this->times_counted() );
	}

	public function test_a_range_past_the_end_is_416_with_nothing_sent_or_counted(): void {
		$body = $this->fetch( 'bytes=20-' );

		$this->assertSame( '', $body );
		$this->assertSame( 416, $this->handler->status );
		$this->assertSame( 'bytes */20', $this->handler->sent['Content-Range'] );
		$this->assertSame( 0, $this->times_counted() );
	}

	public function test_several_ranges_get_the_whole_file(): void {
		$this->assertSame( self::BODY, $this->fetch( 'bytes=0-1,5-6' ) );
		$this->assertSame( 200, $this->handler->status );
		$this->assertArrayNotHasKey( 'Content-Range', $this->handler->sent );
		$this->assertSame( 1, $this->times_counted() );
	}

	public function test_a_changed_file_is_sent_whole_not_spliced(): void {
		$this->assertSame( self::BODY, $this->fetch( 'bytes=10-', 'Mon, 01 Jan 2001 00:00:00 GMT' ) );
		$this->assertSame( 200, $this->handler->status );
		$this->assertSame( 1, $this->times_counted() );
	}

	public function test_an_unchanged_file_resumes(): void {
		$modified = gmdate( 'D, d M Y H:i:s', (int) filemtime( $this->abs ) ) . ' GMT';

		$this->assertSame( 'abcdefghij', $this->fetch( 'bytes=10-', $modified ) );
		$this->assertSame( 206, $this->handler->status );
	}

	public function test_a_refused_visitor_gets_no_part(): void {
		update_post_meta( $this->download, '_isoft_fmf_access_role', 'administrator' );
		( new ISOFT_FMF_Access_Control() )->recompute_effective_role( $this->download );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		$this->fetch( 'bytes=10-' );
	}

	public function test_the_feature_is_announced(): void {
		$this->assertTrue( isoft_fmf_has_feature( 'range_requests' ) );
	}
}
