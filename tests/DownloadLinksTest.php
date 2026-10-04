<?php
/**
 * Cache-safe download links: public downloads get nonce-free URLs (so cached
 * pages keep working links); restricted, password-protected and unknown
 * downloads keep the nonce.
 */
class DownloadLinksTest extends WP_UnitTestCase {

	private ISOFT_FMF_Access_Control $access;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		$this->access = new ISOFT_FMF_Access_Control();
	}

	/**
	 * @return array{0: int, 1: int} download ID, file ID
	 */
	private function make_download( string $role = '', string $password = '' ): array {
		$download_id = self::factory()->post->create(
			array(
				'post_type'     => 'isoft_fmf_file',
				'post_status'   => 'publish',
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

	public function test_public_download_url_has_no_nonce(): void {
		[ , $file_id ] = $this->make_download();

		$url = isoft_fmf_get_download_url( $file_id );

		$this->assertStringContainsString( "isoft_fmf_download={$file_id}", $url );
		$this->assertNull( $this->nonce_of( $url ) );
	}

	public function test_restricted_download_url_keeps_a_valid_nonce(): void {
		[ , $file_id ] = $this->make_download( 'subscriber' );

		$nonce = $this->nonce_of( isoft_fmf_get_download_url( $file_id ) );

		$this->assertNotNull( $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce, "isoft_fmf_download_{$file_id}" ) );
	}

	public function test_password_protected_download_keeps_the_nonce(): void {
		[ $download_id, $file_id ] = $this->make_download( '', 'secret' );

		$this->assertTrue( isoft_fmf_download_requires_nonce( $download_id ) );
		$this->assertNotNull( $this->nonce_of( isoft_fmf_get_download_url( $file_id ) ) );
	}

	public function test_unknown_download_requires_a_nonce(): void {
		$this->assertTrue( isoft_fmf_download_requires_nonce( 0 ) );
		$this->assertTrue( isoft_fmf_download_requires_nonce( self::factory()->post->create() ) );
	}

	public function test_filter_restores_nonces_everywhere(): void {
		[ $download_id, $file_id ] = $this->make_download();
		add_filter( 'isoft_fmf_download_requires_nonce', '__return_true' );

		$this->assertTrue( isoft_fmf_download_requires_nonce( $download_id ) );
		$this->assertNotNull( $this->nonce_of( isoft_fmf_get_download_url( $file_id ) ) );
	}

	public function test_bundle_url_follows_the_same_rule(): void {
		[ $public ]     = $this->make_download();
		[ $restricted ] = $this->make_download( 'editor' );

		$this->assertNull( $this->nonce_of( isoft_fmf_get_bundle_url( $public ) ) );
		$this->assertSame( 1, wp_verify_nonce( (string) $this->nonce_of( isoft_fmf_get_bundle_url( $restricted ) ), "isoft_fmf_bundle_{$restricted}" ) );
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
}
