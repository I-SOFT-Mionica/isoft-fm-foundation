<?php
/**
 * Public API for importers: setting a download's license, including
 * "inherit from the category".
 */

class DownloadLicenseApiTest extends WP_UnitTestCase {

	private int $parent_license;
	private int $own_license;
	private int $child;

	public function set_up(): void {
		parent::set_up();
		ISOFT_FMF_License_Service::bust_cache();
		$this->parent_license = (int) isoft_fmf_create_license( array( 'title' => 'Parent Licence ' . wp_generate_password( 4, false ) ) );
		$this->own_license    = (int) isoft_fmf_create_license( array( 'title' => 'Own Licence ' . wp_generate_password( 4, false ) ) );

		$top         = isoft_fmf_create_category(
			array(
				'name'       => 'Licensed top ' . wp_generate_password( 4, false ),
				'license_id' => $this->parent_license,
			)
		);
		$child       = isoft_fmf_create_category(
			array(
				'name'   => 'Child without licence',
				'parent' => $top['term_id'],
			)
		);
		$this->child = (int) $child['term_id'];
	}

	private function download( array $args = array() ): int {
		return (int) isoft_fmf_create_draft_download(
			array_merge(
				array(
					'title'       => 'Imported',
					'category_id' => $this->child,
				),
				$args
			)
		);
	}

	private function effective( int $download ): int {
		return ( new ISOFT_FMF_License_Resolver() )->effective_license_for( $download );
	}

	public function test_a_download_never_set_has_no_license(): void {
		$this->assertSame( 0, $this->effective( $this->download() ) );
	}

	public function test_inherit_follows_the_category_and_its_parents(): void {
		$download = $this->download();

		$this->assertSame( $this->parent_license, isoft_fmf_set_download_license( $download, ISOFT_FMF_License_Resolver::INHERIT ) );
		$this->assertSame( $this->parent_license, $this->effective( $download ) );
	}

	public function test_an_own_license_and_none(): void {
		$download = $this->download();

		$this->assertSame( $this->own_license, isoft_fmf_set_download_license( $download, $this->own_license ) );
		$this->assertSame( $this->own_license, $this->effective( $download ) );

		$this->assertSame( 0, isoft_fmf_set_download_license( $download, 0 ) );
		$this->assertSame( 0, $this->effective( $download ) );
	}

	public function test_it_announces_the_change(): void {
		$download = $this->download();
		$seen     = array();
		$listener = static function ( $type, $id ) use ( &$seen ): void {
			$seen[] = array( $type, $id );
		};
		add_action( 'isoft_fmf_content_changed', $listener, 10, 2 );

		isoft_fmf_set_download_license( $download, ISOFT_FMF_License_Resolver::INHERIT );
		remove_action( 'isoft_fmf_content_changed', $listener, 10 );

		$this->assertContains( array( 'download', $download ), $seen );
	}

	public function test_unknown_downloads_and_licenses_are_refused(): void {
		$download = $this->download();
		$post     = self::factory()->post->create();

		$this->assertSame( 'isoft_fmf_invalid_download', isoft_fmf_set_download_license( $post, 0 )->get_error_code() );
		$this->assertSame( 'isoft_fmf_invalid_download', isoft_fmf_set_download_license( 999999, 0 )->get_error_code() );
		$this->assertSame( 'isoft_fmf_invalid_license', isoft_fmf_set_download_license( $download, 999999 )->get_error_code() );
		$this->assertSame( 'isoft_fmf_invalid_license', isoft_fmf_set_download_license( $download, -2 )->get_error_code() );
		$this->assertSame( 0, $this->effective( $download ) );
	}

	public function test_a_draft_created_to_inherit_resolves_at_once(): void {
		$download = $this->download( array( 'license_id' => ISOFT_FMF_License_Resolver::INHERIT ) );

		$this->assertSame( (string) ISOFT_FMF_License_Resolver::INHERIT, get_post_meta( $download, '_isoft_fmf_license_id', true ) );
		$this->assertSame( $this->parent_license, $this->effective( $download ) );
	}

	public function test_a_draft_created_with_its_own_license_resolves_at_once(): void {
		$download = $this->download( array( 'license_id' => $this->own_license ) );

		$this->assertSame( $this->own_license, $this->effective( $download ) );
	}

	public function test_the_feature_is_announced(): void {
		$this->assertTrue( isoft_fmf_has_feature( 'download_license' ) );
	}
}
