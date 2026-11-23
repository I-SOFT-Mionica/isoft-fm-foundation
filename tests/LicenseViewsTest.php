<?php
/**
 * Where licenses are shown outside REST: a download's own page (classic
 * themes) and the Licenses screen's first, inlined list.
 */

class LicenseViewsTest extends WP_UnitTestCase {

	private int $category_license;
	private int $own_license;
	private int $category;

	public function set_up(): void {
		parent::set_up();
		ISOFT_FMF_License_Service::bust_cache();
		$this->category_license = (int) isoft_fmf_create_license( array( 'title' => 'Category Licence ' . wp_generate_password( 4, false ) ) );
		$this->own_license      = (int) isoft_fmf_create_license( array( 'title' => 'Own Licence ' . wp_generate_password( 4, false ) ) );

		$category       = isoft_fmf_create_category(
			array(
				'name'       => 'Licensed ' . wp_generate_password( 4, false ),
				'license_id' => $this->category_license,
			)
		);
		$this->category = (int) $category['term_id'];
	}

	private function published_download( int $license_id ): int {
		$id = (int) isoft_fmf_create_draft_download(
			array(
				'title'       => 'Single view ' . wp_generate_password( 4, false ),
				'category_id' => $this->category,
			)
		);
		isoft_fmf_set_download_license( $id, $license_id );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		return $id;
	}

	private function single_page_html( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );
		return ( new ISOFT_FMF_Post_Type() )->append_download_content( '' );
	}

	public function test_single_page_shows_the_downloads_own_license(): void {
		$id   = $this->published_download( $this->own_license );
		$html = $this->single_page_html( $id );

		$this->assertStringContainsString( 'isoft-fmf-meta--license', $html );
		$this->assertStringContainsString( ( new ISOFT_FMF_License_Service() )->get( $this->own_license )->title, $html );
	}

	public function test_single_page_shows_an_inherited_license(): void {
		$id   = $this->published_download( ISOFT_FMF_License_Resolver::INHERIT );
		$html = $this->single_page_html( $id );

		$this->assertStringContainsString( ( new ISOFT_FMF_License_Service() )->get( $this->category_license )->title, $html );
	}

	public function test_licenses_screen_first_list_has_booleans(): void {
		$slice = new ReflectionMethod( 'ISOFT_FMF_Admin_Shell', 'licenses_slice' );
		$items = $slice->invoke( null )['initialItems'];

		$this->assertNotEmpty( $items );
		foreach ( $items as $item ) {
			$this->assertIsBool( $item['is_default'] );
			$this->assertIsInt( $item['id'] );
		}
		$own = array_values( array_filter( $items, fn( $i ) => $this->own_license === $i['id'] ) );
		$this->assertFalse( $own[0]['is_default'] );
	}
}
