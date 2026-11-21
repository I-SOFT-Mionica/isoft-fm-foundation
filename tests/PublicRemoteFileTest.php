<?php
/**
 * isoft_fmf_resolve_public_remote_file(): what a headless gateway asks
 * before it serves a remote file to an anonymous visitor.
 */

class PublicRemoteFileTest extends WP_UnitTestCase {

	private int $download;
	private int $file;
	private array $calls = array();

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
		$this->calls    = array();
	}

	public function tear_down(): void {
		remove_all_filters( 'isoft_fmf_resolve_remote_file' );
		parent::tear_down();
	}

	private function answer( $resolution ): void {
		add_filter(
			'isoft_fmf_resolve_remote_file',
			function ( $value, $file, $context ) use ( $resolution ) {
				$this->calls[] = array(
					'user'    => get_current_user_id(),
					'file'    => (int) $file->id,
					'context' => $context,
				);
				return $resolution;
			},
			10,
			3
		);
	}

	private function answer_redirect(): void {
		$this->answer(
			array(
				'mode' => 'redirect',
				'url'  => 'https://cloud.example.org/x',
			)
		);
	}

	private function times_counted(): int {
		return (int) ( new ISOFT_FMF_File_Manager() )->get_file( $this->file )->download_count;
	}

	public function test_a_redirect_is_returned_cleaned_and_not_counted(): void {
		$this->answer(
			array(
				'mode' => 'redirect',
				'url'  => 'https://cloud.example.org/plan.pdf?sig=1',
			)
		);

		$this->assertSame(
			array(
				'mode' => 'redirect',
				'url'  => 'https://cloud.example.org/plan.pdf?sig=1',
			),
			isoft_fmf_resolve_public_remote_file( $this->file )
		);
		$this->assertSame( 0, $this->times_counted() );
	}

	public function test_a_proxy_keeps_only_the_describing_headers(): void {
		$stream = static function (): void {
			echo 'BODY';
		};
		$this->answer(
			array(
				'mode'    => 'proxy',
				'stream'  => $stream,
				'status'  => 206,
				'headers' => array(
					'Content-Range'       => "bytes 10-19/2048\r\nX-Evil: 1",
					'Content-Disposition' => 'inline',
					'Set-Cookie'          => 'a=b',
				),
			)
		);

		$result = isoft_fmf_resolve_public_remote_file( $this->file, 'bytes=10-19' );

		$this->assertSame( 'proxy', $result['mode'] );
		$this->assertSame( $stream, $result['stream'] );
		$this->assertSame( 206, $result['status'] );
		$this->assertSame( array( 'Content-Range' => 'bytes 10-19/2048X-Evil: 1' ), $result['headers'] );
		$this->assertSame( 0, $this->times_counted() );
	}

	public function test_the_filter_runs_as_an_anonymous_visitor_with_range_and_head(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->answer( null );

		isoft_fmf_resolve_public_remote_file( $this->file, 'bytes=0-', true );

		$this->assertSame( 0, $this->calls[0]['user'] );
		$this->assertSame( $this->file, $this->calls[0]['file'] );
		$this->assertSame(
			array(
				'download_id' => $this->download,
				'range'       => 'bytes=0-',
				'head'        => true,
			),
			$this->calls[0]['context']
		);
		$this->assertSame( $admin, get_current_user_id(), 'the caller is restored' );
	}

	/**
	 * @dataProvider unusable
	 */
	public function test_no_usable_answer_is_null( $resolution ): void {
		$this->answer( $resolution );

		$this->assertNull( isoft_fmf_resolve_public_remote_file( $this->file ) );
	}

	public static function unusable(): array {
		return array(
			'nothing'          => array( null ),
			'error'            => array( new WP_Error( 'gone', 'Gone' ) ),
			'unknown mode'     => array( array( 'mode' => 'teleport' ) ),
			'script redirect'  => array(
				array(
					'mode' => 'redirect',
					'url'  => 'javascript:alert(1)',
				),
			),
			'proxy, no stream' => array( array( 'mode' => 'proxy' ) ),
		);
	}

	public function test_a_filter_that_throws_is_null(): void {
		add_filter(
			'isoft_fmf_resolve_remote_file',
			static function (): void {
				throw new RuntimeException( 'cloud down' );
			}
		);

		$this->assertNull( isoft_fmf_resolve_public_remote_file( $this->file ) );
	}

	public function test_a_held_file_never_reaches_the_filter(): void {
		isoft_fmf_set_file_unavailable( $this->file, true );
		$this->answer_redirect();

		$this->assertNull( isoft_fmf_resolve_public_remote_file( $this->file ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_a_members_only_download_never_reaches_the_filter(): void {
		update_post_meta( $this->download, '_isoft_fmf_access_role', 'subscriber' );
		( new ISOFT_FMF_Access_Control() )->recompute_effective_role( $this->download );
		$this->answer_redirect();

		$this->assertNull( isoft_fmf_resolve_public_remote_file( $this->file ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_a_draft_or_external_only_download_is_null(): void {
		$this->answer_redirect();

		update_post_meta( $this->download, '_isoft_fmf_external_only', 1 );
		$this->assertNull( isoft_fmf_resolve_public_remote_file( $this->file ) );
		delete_post_meta( $this->download, '_isoft_fmf_external_only' );

		wp_update_post(
			array(
				'ID'          => $this->download,
				'post_status' => 'draft',
			)
		);
		$this->assertNull( isoft_fmf_resolve_public_remote_file( $this->file ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_only_remote_files_are_answered(): void {
		$this->answer_redirect();
		$link = (int) isoft_fmf_add_external_link( $this->download, 'https://example.org/a' );

		$this->assertNull( isoft_fmf_resolve_public_remote_file( $link ) );
		$this->assertNull( isoft_fmf_resolve_public_remote_file( 999999 ) );
	}

	public function test_range_counts(): void {
		$this->assertTrue( isoft_fmf_range_counts( '' ) );
		$this->assertTrue( isoft_fmf_range_counts( 'bytes=0-99' ) );
		$this->assertFalse( isoft_fmf_range_counts( 'bytes=100-' ) );
	}

	public function test_the_feature_is_announced(): void {
		$this->assertTrue( isoft_fmf_has_feature( 'public_remote_file' ) );
	}
}
