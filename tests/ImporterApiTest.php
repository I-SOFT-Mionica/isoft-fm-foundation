<?php
/**
 * The public API an importer builds on: creating a category, a slug on a
 * draft download, and setting a download counter.
 */

class ImporterApiTest extends WP_UnitTestCase {

	private function name(): string {
		return 'Imp ' . wp_generate_password( 8, false );
	}

	public function test_creates_a_category_with_its_folder(): void {
		$top   = isoft_fmf_create_category( array( 'name' => $this->name() ) );
		$child = isoft_fmf_create_category(
			array(
				'name'        => $this->name(),
				'parent'      => $top['term_id'],
				'slug'        => 'chosen-' . strtolower( wp_generate_password( 6, false ) ),
				'description' => 'About this',
			)
		);

		$this->assertIsArray( $child );
		$this->assertStringStartsWith( 'chosen-', $child['slug'] );
		$this->assertSame( $top['slug'] . '/' . $child['slug'], $child['path'] );
		$this->assertDirectoryExists( isoft_fmf_files_dir() . '/' . $child['path'] );
		$this->assertSame( 'About this', get_term( $child['term_id'], 'isoft_fmf_category' )->description );
	}

	public function test_saves_access_role_license_and_pending(): void {
		$license = ( new ISOFT_FMF_License_Service() )->create( array( 'title' => 'L ' . wp_generate_password( 6, false ) ) );
		$cat     = isoft_fmf_create_category(
			array(
				'name'        => $this->name(),
				'access_role' => 'editor',
				'license_id'  => $license,
				'pending'     => true,
			)
		);

		$this->assertSame( 'editor', get_term_meta( $cat['term_id'], '_isoft_fmf_cat_access_role', true ) );
		$this->assertSame( $license, (int) get_term_meta( $cat['term_id'], '_isoft_fmf_cat_license_id', true ) );
		$this->assertTrue( isoft_fmf_category_is_pending( $cat['term_id'] ) );
	}

	public function test_a_category_is_not_pending_unless_asked(): void {
		$cat = isoft_fmf_create_category( array( 'name' => $this->name() ) );
		$this->assertFalse( isoft_fmf_category_is_pending( $cat['term_id'] ) );
	}

	public function test_cyrillic_becomes_a_latin_slug(): void {
		$suffix = strtolower( wp_generate_password( 6, false, false ) );
		$named  = isoft_fmf_create_category( array( 'name' => 'Шабац ' . $suffix ) );
		$sluged = isoft_fmf_create_category(
			array(
				'name' => $this->name(),
				'slug' => 'Чачак-' . $suffix,
			)
		);

		$this->assertSame( 'sabac-' . $suffix, $named['slug'] );
		$this->assertSame( 'cacak-' . $suffix, $sluged['slug'] );
		$this->assertDirectoryExists( isoft_fmf_files_dir() . '/' . $named['path'] );
	}

	public function test_a_taken_slug_gets_a_suffix_not_an_error(): void {
		$slug   = 'taken-' . strtolower( wp_generate_password( 6, false ) );
		$parent = isoft_fmf_create_category( array( 'name' => $this->name() ) );
		$first  = isoft_fmf_create_category(
			array(
				'name'   => $this->name(),
				'parent' => $parent['term_id'],
				'slug'   => $slug,
			)
		);
		$second = isoft_fmf_create_category(
			array(
				'name'   => $this->name(),
				'parent' => $parent['term_id'],
				'slug'   => $slug,
			)
		);

		$this->assertIsArray( $second );
		$this->assertSame( $slug, $first['slug'] );
		$this->assertNotSame( $first['slug'], $second['slug'] );
		$this->assertNotSame( $first['path'], $second['path'] );
		$this->assertDirectoryExists( isoft_fmf_files_dir() . '/' . $second['path'] );
	}

	public function test_the_same_name_in_the_same_place_reports_the_existing_category(): void {
		$name  = $this->name();
		$first = isoft_fmf_create_category( array( 'name' => $name ) );
		$again = isoft_fmf_create_category( array( 'name' => $name ) );

		$this->assertWPError( $again );
		$this->assertSame( 'term_exists', $again->get_error_code() );
		$this->assertSame( $first['term_id'], (int) $again->get_error_data() );
	}

	public function test_a_name_with_quotes_and_backslashes_is_kept(): void {
		$name = "O'Brien \\ " . wp_generate_password( 6, false );
		$cat  = isoft_fmf_create_category( array( 'name' => $name ) );

		$this->assertSame( $name, get_term( $cat['term_id'], 'isoft_fmf_category' )->name );
	}

	/**
	 * @dataProvider refused_arguments
	 */
	public function test_bad_arguments_create_nothing( array $args ): void {
		$before = (int) wp_count_terms( array( 'taxonomy' => 'isoft_fmf_category', 'hide_empty' => false ) );

		$this->assertWPError( isoft_fmf_create_category( $args ) );

		$this->assertSame( $before, (int) wp_count_terms( array( 'taxonomy' => 'isoft_fmf_category', 'hide_empty' => false ) ) );
	}

	public static function refused_arguments(): array {
		return array(
			'no name'         => array( array( 'name' => '  ' ) ),
			'unknown parent'  => array( array( 'name' => 'x-parent', 'parent' => 999999 ) ),
			'unknown role'    => array( array( 'name' => 'x-role', 'access_role' => 'editors' ) ),
			'unknown license' => array( array( 'name' => 'x-license', 'license_id' => 999999 ) ),
		);
	}

	public function test_a_folder_climbing_slug_stays_inside_storage(): void {
		$cat = isoft_fmf_create_category(
			array(
				'name' => $this->name(),
				'slug' => '../../escape-' . strtolower( wp_generate_password( 6, false ) ),
			)
		);

		$this->assertStringNotContainsString( '/', $cat['path'] );
		$this->assertStringNotContainsString( '..', $cat['path'] );
	}

	public function test_a_draft_keeps_the_slug_it_was_given(): void {
		$slug = 'old-url-' . strtolower( wp_generate_password( 6, false ) );
		$id   = isoft_fmf_create_draft_download(
			array(
				'title' => 'Any title',
				'slug'  => $slug,
			)
		);

		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( $slug, get_post_field( 'post_name', $id ) );
	}

	public function test_a_draft_slug_is_unique_at_once_and_survives_publishing(): void {
		$slug  = 'same-' . strtolower( wp_generate_password( 6, false ) );
		$first = isoft_fmf_create_draft_download(
			array(
				'title' => 'One',
				'slug'  => $slug,
			)
		);
		$other = isoft_fmf_create_draft_download(
			array(
				'title' => 'Two',
				'slug'  => $slug,
			)
		);

		$final = get_post_field( 'post_name', $other );
		$this->assertNotSame( $slug, $final );

		wp_publish_post( $first );
		wp_publish_post( $other );
		$this->assertSame( $slug, get_post_field( 'post_name', $first ) );
		$this->assertSame( $final, get_post_field( 'post_name', $other ) );
	}

	public function test_a_cyrillic_draft_slug_becomes_latin(): void {
		$suffix = strtolower( wp_generate_password( 6, false, false ) );
		$id     = isoft_fmf_create_draft_download(
			array(
				'title' => 'Any title',
				'slug'  => 'Шабац-' . $suffix,
			)
		);

		$this->assertSame( 'sabac-' . $suffix, get_post_field( 'post_name', $id ) );
	}

	public function test_a_drafts_access_role_takes_effect_at_once(): void {
		$cat = isoft_fmf_create_category( array( 'name' => $this->name() ) );
		$id  = isoft_fmf_create_draft_download(
			array(
				'title'       => 'Restricted',
				'category_id' => $cat['term_id'],
				'access_role' => 'editor',
			)
		);

		$this->assertSame( 'editor', ( new ISOFT_FMF_Access_Control() )->effective_role_for( $id ) );
	}

	public function test_a_draft_without_a_slug_is_unchanged(): void {
		$id = isoft_fmf_create_draft_download( array( 'title' => 'Plain title' ) );
		$this->assertSame( '', get_post_field( 'post_name', $id ) );
	}

	/** Two file rows on one download; returns [download, file a, file b]. */
	private function two_files(): array {
		$download = (int) isoft_fmf_create_draft_download( array( 'title' => 'Counted' ) );
		$service  = new ISOFT_FMF_Files_Service();
		return array(
			$download,
			(int) $service->add_external( $download, 'https://example.org/a' ),
			(int) $service->add_external( $download, 'https://example.org/b' ),
		);
	}

	private function log_rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
	}

	public function test_setting_a_count_refreshes_the_total_and_logs_nothing(): void {
		list( $download, $a, $b ) = $this->two_files();
		$logged                   = $this->log_rows();

		$this->assertTrue( isoft_fmf_set_download_count( $a, 7000 ) );
		$this->assertTrue( isoft_fmf_set_download_count( $b, 52 ) );

		$manager = new ISOFT_FMF_File_Manager();
		$this->assertSame( 7000, (int) $manager->get_file( $a )->download_count );
		$this->assertSame( 7052, (int) get_post_meta( $download, '_isoft_fmf_download_count', true ) );
		$this->assertSame( $logged, $this->log_rows() );
	}

	public function test_setting_the_same_count_again_changes_nothing(): void {
		list( $download, $a ) = $this->two_files();

		isoft_fmf_set_download_count( $a, 12 );

		$this->assertTrue( isoft_fmf_set_download_count( $a, 12 ) );
		$this->assertSame( 12, (int) get_post_meta( $download, '_isoft_fmf_download_count', true ) );
	}

	public function test_a_real_download_counts_on_from_the_set_value(): void {
		list( $download, $a ) = $this->two_files();
		isoft_fmf_set_download_count( $a, 100 );

		( new ISOFT_FMF_File_Manager() )->increment_count( $a, $download );

		$this->assertSame( 101, (int) get_post_meta( $download, '_isoft_fmf_download_count', true ) );
	}

	public function test_a_negative_count_or_unknown_file_is_refused(): void {
		list( $download, $a ) = $this->two_files();
		isoft_fmf_set_download_count( $a, 5 );

		$this->assertFalse( isoft_fmf_set_download_count( $a, -1 ) );
		$this->assertFalse( isoft_fmf_set_download_count( 999999, 5 ) );
		$this->assertSame( 5, (int) get_post_meta( $download, '_isoft_fmf_download_count', true ) );
	}

	public function test_the_features_are_announced(): void {
		foreach ( array( 'create_category', 'draft_slug', 'set_download_count' ) as $feature ) {
			$this->assertTrue( isoft_fmf_has_feature( $feature ), $feature );
		}
	}
}
