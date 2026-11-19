<?php
/**
 * Public API for importers, part two: licenses, external links, and category
 * names longer than the database can hold.
 */

class LicenseLinkApiTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		ISOFT_FMF_License_Service::bust_cache();
	}

	private function download(): int {
		return (int) isoft_fmf_create_draft_download( array( 'title' => 'Link holder' ) );
	}

	// Licenses.

	public function test_a_license_is_created_and_found_again(): void {
		$id = isoft_fmf_create_license(
			array(
				'title'       => 'Open Data Licence Test',
				'url'         => 'https://example.org/odl/',
				'description' => 'For the test.',
			)
		);

		$this->assertIsInt( $id );
		$this->assertSame( $id, isoft_fmf_find_license( array( 'title' => 'open data licence TEST' ) ) );
		$this->assertSame( $id, isoft_fmf_find_license( array( 'url' => 'https://example.org/odl' ) ) );
		$this->assertSame( $id, isoft_fmf_find_license( array( 'slug' => 'open-data-licence-test' ) ) );
		$this->assertSame( 'For the test.', ( new ISOFT_FMF_License_Service() )->get( $id )->description );
	}

	public function test_every_key_given_must_match(): void {
		$id = isoft_fmf_create_license(
			array(
				'title' => 'Both Keys',
				'url'   => 'https://example.org/both',
			)
		);

		$this->assertSame(
			0,
			isoft_fmf_find_license(
				array(
					'title' => 'Both Keys',
					'url'   => 'https://example.org/other',
				)
			)
		);
		$this->assertSame(
			$id,
			isoft_fmf_find_license(
				array(
					'title' => 'Both Keys',
					'url'   => 'https://example.org/both/',
				)
			)
		);
	}

	public function test_nothing_to_look_for_finds_nothing(): void {
		$this->assertSame( 0, isoft_fmf_find_license( array() ) );
		$this->assertSame( 0, isoft_fmf_find_license( array( 'title' => '' ) ) );
		$this->assertSame( 0, isoft_fmf_find_license( array( 'title' => 'No such license anywhere' ) ) );
	}

	public function test_a_taken_slug_returns_the_existing_id(): void {
		$first  = isoft_fmf_create_license( array( 'title' => 'Twice' ) );
		$second = isoft_fmf_create_license( array( 'title' => 'Twice' ) );

		$this->assertWPError( $second );
		$this->assertSame( 'isoft_fmf_license_exists', $second->get_error_code() );
		$this->assertSame( $first, $second->get_error_data() );
	}

	public function test_a_license_needs_a_title(): void {
		$this->assertWPError( isoft_fmf_create_license( array( 'title' => '  ' ) ) );
	}

	public function test_an_imported_license_never_becomes_the_default(): void {
		$id = isoft_fmf_create_license(
			array(
				'title'      => 'Not Default',
				'is_default' => true,
			)
		);

		$this->assertSame( 0, (int) ( new ISOFT_FMF_License_Service() )->get( $id )->is_default );
	}

	public function test_text_with_quotes_is_stored_as_given(): void {
		$id = isoft_fmf_create_license( array( 'title' => "O'Reilly \"Open\" Licence" ) );

		$this->assertSame( "O'Reilly \"Open\" Licence", ( new ISOFT_FMF_License_Service() )->get( $id )->title );
	}

	// External links.

	public function test_an_external_link_is_added(): void {
		$download = $this->download();

		$id = isoft_fmf_add_external_link(
			$download,
			'https://docs.example.org/file?id=1&x=2',
			array(
				'title'      => 'Plan',
				'is_mirror'  => true,
				'sort_order' => 3,
			)
		);

		$row = ( new ISOFT_FMF_File_Manager() )->get_file( $id );
		$this->assertSame( 'external', $row->file_type );
		$this->assertSame( 'https://docs.example.org/file?id=1&x=2', $row->external_url );
		$this->assertSame( 'Plan', $row->title );
		$this->assertSame( 1, (int) $row->is_mirror );
		$this->assertSame( 3, (int) $row->sort_order );
	}

	public function test_the_title_defaults_to_the_address(): void {
		$id = isoft_fmf_add_external_link( $this->download(), 'https://example.org/a' );

		$this->assertSame( 'https://example.org/a', ( new ISOFT_FMF_File_Manager() )->get_file( $id )->title );
	}

	/**
	 * @dataProvider refused_addresses
	 */
	public function test_only_web_addresses_are_accepted( string $url ): void {
		$result = isoft_fmf_add_external_link( $this->download(), $url );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_invalid_link', $result->get_error_code() );
	}

	public static function refused_addresses(): array {
		return array(
			'empty'      => array( '' ),
			'script'     => array( 'javascript:alert(1)' ),
			'data'       => array( 'data:text/html,hi' ),
			'file'       => array( 'file:///etc/passwd' ),
			'ftp'        => array( 'ftp://example.org/x' ),
			'no host'    => array( 'https://' ),
			'relative'   => array( '/wp-admin/' ),
		);
	}

	public function test_a_link_needs_a_real_download(): void {
		$post = self::factory()->post->create();

		$this->assertWPError( isoft_fmf_add_external_link( $post, 'https://example.org/' ) );
		$this->assertWPError( isoft_fmf_add_external_link( 999999, 'https://example.org/' ) );
	}

	// Category names.

	public function test_a_name_up_to_the_limit_is_created(): void {
		$result = isoft_fmf_create_category( array( 'name' => str_repeat( 'ж', ISOFT_FMF_Taxonomy::NAME_MAX ) ) );

		$this->assertIsArray( $result );
	}

	public function test_a_longer_name_is_refused_with_a_clear_reason(): void {
		$result = isoft_fmf_create_category( array( 'name' => str_repeat( 'a', ISOFT_FMF_Taxonomy::NAME_MAX + 1 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_invalid_category', $result->get_error_code() );
		$this->assertStringContainsString( '200', $result->get_error_message() );
	}

	public function test_the_limit_counts_the_name_as_stored(): void {
		// An & is stored as &amp; (5 characters): 195 + 5 fits, 196 + 5 does not.
		$this->assertIsArray( isoft_fmf_create_category( array( 'name' => str_repeat( 'a', 195 ) . '&' ) ) );
		$this->assertWPError( isoft_fmf_create_category( array( 'name' => str_repeat( 'd', 196 ) . '&' ) ) );
	}

	public function test_the_admin_screen_gets_the_same_reason(): void {
		$result = wp_insert_term( str_repeat( 'b', 250 ), 'isoft_fmf_category' );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_invalid_category', $result->get_error_code() );
	}

	public function test_other_taxonomies_are_left_alone(): void {
		$result = wp_insert_term( str_repeat( 'c', 150 ), 'category' );

		$this->assertIsArray( $result );
	}

	public function test_the_features_are_announced(): void {
		foreach ( array( 'license_api', 'external_link' ) as $feature ) {
			$this->assertTrue( isoft_fmf_has_feature( $feature ), $feature );
		}
	}
}
