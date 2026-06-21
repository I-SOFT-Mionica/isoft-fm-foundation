<?php
/**
 * ISOFT_FMF_License_Service unit tests.
 *
 * Pure data-layer coverage: CRUD, single-default invariant, seed install
 * idempotency, sanitization. Cache behaviour is still covered by the
 * legacy LicenseManagerCacheTest which exercises the same store via the
 * delegator class.
 */

class LicenseServiceTest extends WP_UnitTestCase {

	private ISOFT_FMF_License_Service $service;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// Start every test with an empty table so seed-install assertions
		// don't fight with the activator's first-install seed run.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_licenses" );
		wp_cache_flush();
		$this->service = new ISOFT_FMF_License_Service();
	}

	public function test_create_returns_new_id(): void {
		$id = $this->service->create(
			array(
				'title' => 'Test License',
				'url'   => 'https://example.org/license',
			)
		);
		$this->assertGreaterThan( 0, $id );
		$row = $this->service->get( $id );
		$this->assertSame( 'Test License', $row->title );
		$this->assertSame( 'test-license', $row->slug );
	}

	public function test_create_derives_slug_from_title_when_blank(): void {
		$id  = $this->service->create( array( 'title' => 'Привремена лиценца' ) );
		$row = $this->service->get( $id );
		$this->assertNotSame( '', $row->slug );
	}

	public function test_update_changes_row_and_returns_true(): void {
		$id = $this->service->create( array( 'title' => 'Old Title' ) );
		$ok = $this->service->update( $id, array( 'title' => 'New Title' ) );
		$this->assertTrue( $ok );
		$this->assertSame( 'New Title', $this->service->get( $id )->title );
	}

	public function test_update_returns_false_for_missing_row(): void {
		$this->assertFalse( $this->service->update( 99999, array( 'title' => 'Nope' ) ) );
	}

	public function test_delete_removes_row(): void {
		$id = $this->service->create( array( 'title' => 'Doomed' ) );
		$this->assertTrue( $this->service->delete( $id ) );
		$this->assertNull( $this->service->get( $id ) );
	}

	public function test_delete_returns_false_for_invalid_id(): void {
		$this->assertFalse( $this->service->delete( 0 ) );
		$this->assertFalse( $this->service->delete( -1 ) );
	}

	public function test_only_one_default_after_create(): void {
		$a = $this->service->create( array( 'title' => 'A', 'is_default' => true ) );
		$b = $this->service->create( array( 'title' => 'B', 'is_default' => true ) );

		$this->assertFalse( (bool) $this->service->get( $a )->is_default );
		$this->assertTrue( (bool) $this->service->get( $b )->is_default );
	}

	public function test_only_one_default_after_update(): void {
		$a = $this->service->create( array( 'title' => 'A', 'is_default' => true ) );
		$b = $this->service->create( array( 'title' => 'B' ) );

		$this->service->update( $b, array( 'title' => 'B', 'is_default' => true ) );

		$this->assertFalse( (bool) $this->service->get( $a )->is_default );
		$this->assertTrue( (bool) $this->service->get( $b )->is_default );
	}

	public function test_install_missing_seeds_populates_empty_table(): void {
		$inserted = $this->service->install_missing_seeds();
		$this->assertSame( count( ISOFT_FMF_License_Service::seed_defaults() ), $inserted );
	}

	public function test_install_missing_seeds_is_idempotent(): void {
		$this->service->install_missing_seeds();
		$second = $this->service->install_missing_seeds();
		$this->assertSame( 0, $second );
	}

	public function test_install_missing_seeds_skips_existing_slugs(): void {
		// Pre-create a row with a seed slug; install should NOT overwrite it.
		$id = $this->service->create(
			array(
				'title' => 'CUSTOM TITLE',
				'slug'  => 'public-domain',
				'url'   => 'https://custom.example/',
			)
		);
		$this->service->install_missing_seeds();

		$row = $this->service->get( $id );
		$this->assertSame( 'CUSTOM TITLE', $row->title, 'Existing slug must not be overwritten by seed install (legal defensibility).' );
		$this->assertSame( 'https://custom.example/', $row->url );
	}

	public function test_list_returns_rows_in_sort_order(): void {
		$this->service->create( array( 'title' => 'Z', 'sort_order' => 5 ) );
		$this->service->create( array( 'title' => 'A', 'sort_order' => 1 ) );
		$this->service->create( array( 'title' => 'M', 'sort_order' => 3 ) );

		$titles = array_map( fn( $r ) => $r->title, $this->service->list() );
		$this->assertSame( array( 'A', 'M', 'Z' ), $titles );
	}

	public function test_get_by_slug_finds_row(): void {
		$this->service->create( array( 'title' => 'Slug Test', 'slug' => 'slug-test' ) );
		$row = $this->service->get_by_slug( 'slug-test' );
		$this->assertNotNull( $row );
		$this->assertSame( 'Slug Test', $row->title );
	}

	public function test_full_text_sanitization_strips_unsafe_tags(): void {
		$id  = $this->service->create(
			array(
				'title'     => 'Sanitize Test',
				'full_text' => 'OK text <script>alert(1)</script> and <strong>bold</strong>',
			)
		);
		$row = $this->service->get( $id );
		$this->assertStringNotContainsString( '<script>', $row->full_text );
		$this->assertStringContainsString( '<strong>bold</strong>', $row->full_text );
	}

	public function test_url_sanitization_rejects_javascript_scheme(): void {
		$id  = $this->service->create(
			array(
				'title' => 'Bad URL',
				'url'   => 'javascript:alert(1)',
			)
		);
		$row = $this->service->get( $id );
		$this->assertSame( '', $row->url );
	}
}
