<?php
/**
 * Download links. By default every link carries a nonce. With "Cache-friendly
 * download links" on, public downloads drop it (so cached pages keep working
 * links); restricted, password-protected, unpublished and unknown downloads
 * keep it either way.
 */
class DownloadLinksTest extends WP_UnitTestCase {

	private ISOFT_FMF_Access_Control $access;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		delete_option( 'isoft_fmf_cache_friendly_links' );
		delete_option( 'isoft_fmf_rate_limit_per_hour' );
		$this->access = new ISOFT_FMF_Access_Control();
	}

	/**
	 * @return array{0: int, 1: int} download ID, file ID
	 */
	private function make_download( string $role = '', string $password = '', string $status = 'publish' ): array {
		$download_id = self::factory()->post->create(
			array(
				'post_type'     => 'isoft_fmf_file',
				'post_status'   => $status,
				'post_password' => $password,
			)
		);
		if ( '' !== $role ) {
			update_post_meta( $download_id, '_isoft_fmf_access_role', $role );
		}
		$this->access->recompute_effective_role( $download_id );

		$file_id = (int) ( new ISOFT_FMF_File_Manager() )->add_external_link( $download_id, 'https://example.org/file.pdf' );
		return array( $download_id, $file_id );
	}

	private function nonce_of( string $url ): ?string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args['nonce'] ?? null;
	}

	private function enable_cache_friendly_links(): void {
		update_option( 'isoft_fmf_cache_friendly_links', 1 );
	}

	public function test_default_keeps_the_nonce_on_public_downloads(): void {
		[ $download_id, $file_id ] = $this->make_download();

		$this->assertTrue( isoft_fmf_download_requires_nonce( $download_id ) );
		$nonce = $this->nonce_of( isoft_fmf_get_download_url( $file_id, $download_id ) );
		$this->assertNotNull( $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce, "isoft_fmf_download_{$file_id}" ) );
	}

	public function test_default_keeps_the_nonce_on_bundle_links(): void {
		[ $download_id ] = $this->make_download();

		$this->assertNotNull( $this->nonce_of( isoft_fmf_get_bundle_url( $download_id ) ) );
	}

	public function test_cache_friendly_public_download_url_has_no_nonce(): void {
		$this->enable_cache_friendly_links();
		[ , $file_id ] = $this->make_download();

		$url = isoft_fmf_get_download_url( $file_id );

		$this->assertStringContainsString( "isoft_fmf_download={$file_id}", $url );
		$this->assertNull( $this->nonce_of( $url ) );
	}

	public function test_cache_friendly_url_without_download_hint_still_resolves_the_download(): void {
		$this->enable_cache_friendly_links();
		[ $download_id, $file_id ] = $this->make_download();

		$this->assertNull( $this->nonce_of( isoft_fmf_get_download_url( $file_id ) ) );
		$this->assertNull( $this->nonce_of( isoft_fmf_get_download_url( $file_id, $download_id ) ) );
	}

	public function test_cache_friendly_restricted_download_url_keeps_a_valid_nonce(): void {
		$this->enable_cache_friendly_links();
		[ , $file_id ] = $this->make_download( 'subscriber' );

		$nonce = $this->nonce_of( isoft_fmf_get_download_url( $file_id ) );

		$this->assertNotNull( $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce, "isoft_fmf_download_{$file_id}" ) );
	}

	public function test_cache_friendly_password_protected_download_keeps_the_nonce(): void {
		$this->enable_cache_friendly_links();
		[ $download_id, $file_id ] = $this->make_download( '', 'secret' );

		$this->assertTrue( isoft_fmf_download_requires_nonce( $download_id ) );
		$this->assertNotNull( $this->nonce_of( isoft_fmf_get_download_url( $file_id ) ) );
	}

	public function test_cache_friendly_unpublished_download_keeps_the_nonce(): void {
		$this->enable_cache_friendly_links();
		[ $download_id ] = $this->make_download( '', '', 'draft' );

		$this->assertTrue( isoft_fmf_download_requires_nonce( $download_id ) );
	}

	public function test_cache_friendly_unknown_download_requires_a_nonce(): void {
		$this->enable_cache_friendly_links();

		$this->assertTrue( isoft_fmf_download_requires_nonce( 0 ) );
		$this->assertTrue( isoft_fmf_download_requires_nonce( self::factory()->post->create() ) );
	}

	public function test_cache_friendly_bundle_url_follows_the_same_rule(): void {
		$this->enable_cache_friendly_links();
		[ $public ]     = $this->make_download();
		[ $restricted ] = $this->make_download( 'editor' );

		$this->assertNull( $this->nonce_of( isoft_fmf_get_bundle_url( $public ) ) );
		$this->assertSame( 1, wp_verify_nonce( (string) $this->nonce_of( isoft_fmf_get_bundle_url( $restricted ) ), "isoft_fmf_bundle_{$restricted}" ) );
	}

	public function test_filter_can_override_the_decision_either_way(): void {
		[ $download_id ] = $this->make_download();
		add_filter( 'isoft_fmf_download_requires_nonce', '__return_false' );
		$this->assertFalse( isoft_fmf_download_requires_nonce( $download_id ) );
		remove_filter( 'isoft_fmf_download_requires_nonce', '__return_false' );

		$this->enable_cache_friendly_links();
		add_filter( 'isoft_fmf_download_requires_nonce', '__return_true' );
		$this->assertTrue( isoft_fmf_download_requires_nonce( $download_id ) );
		remove_filter( 'isoft_fmf_download_requires_nonce', '__return_true' );
	}

	public function test_rate_limit_is_unlimited_by_default(): void {
		$this->assertSame( 0, isoft_fmf_effective_rate_limit() );
	}

	public function test_cache_friendly_links_apply_a_default_rate_limit(): void {
		$this->enable_cache_friendly_links();

		$this->assertSame( 120, isoft_fmf_effective_rate_limit() );

		add_filter( 'isoft_fmf_cache_friendly_default_rate_limit', fn() => 40 );
		$this->assertSame( 40, isoft_fmf_effective_rate_limit() );
	}

	public function test_default_limit_comes_from_the_setting(): void {
		$this->enable_cache_friendly_links();
		update_option( 'isoft_fmf_cache_friendly_rate_limit', 300 );
		$this->assertSame( 300, isoft_fmf_effective_rate_limit() );

		update_option( 'isoft_fmf_cache_friendly_rate_limit', 0 );
		$this->assertSame( 0, isoft_fmf_effective_rate_limit() );

		delete_option( 'isoft_fmf_cache_friendly_rate_limit' );
	}

	public function test_configured_rate_limit_always_wins(): void {
		$this->enable_cache_friendly_links();
		update_option( 'isoft_fmf_rate_limit_per_hour', 7 );

		$this->assertSame( 7, isoft_fmf_effective_rate_limit() );
	}

	public function test_content_disposition_keeps_utf8_name_with_ascii_fallback(): void {
		$header = isoft_fmf_content_disposition( 'Водич за родитеље.pdf' );

		$this->assertStringStartsWith( 'attachment; filename="Vodic_za_roditelje.pdf"; ', $header );
		$this->assertStringContainsString( "filename*=UTF-8''" . rawurlencode( 'Водич за родитеље.pdf' ), $header );
	}

	public function test_content_disposition_never_emits_quotes_or_an_empty_name(): void {
		$this->assertStringStartsWith( 'attachment; filename="a_b.pdf"', isoft_fmf_content_disposition( 'a"b.pdf' ) );
		$this->assertStringStartsWith( 'attachment; filename="download"', isoft_fmf_content_disposition( '???' ) );
	}

	public function test_content_disposition_cannot_inject_headers(): void {
		$header = isoft_fmf_content_disposition( "evil\r\nSet-Cookie: x=1.pdf" );

		$this->assertStringNotContainsString( "\r", $header );
		$this->assertStringNotContainsString( "\n", $header );
		$this->assertStringContainsString( '%0D%0A', $header );
	}

	public function test_content_disposition_handles_right_to_left_names(): void {
		$header = isoft_fmf_content_disposition( 'تقرير.pdf' );

		$this->assertMatchesRegularExpression( '/^attachment; filename="[A-Za-z0-9._-]+"; filename\*=UTF-8\'\'/', $header );
		$this->assertStringContainsString( rawurlencode( 'تقرير.pdf' ), $header );
	}
}
