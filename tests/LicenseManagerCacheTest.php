<?php
/**
 * ISOFT_FMF_License_Manager object-cache behavior.
 *
 * save() and delete() are private and read $_POST; we test the public cache
 * surface: get_all / get prime the cache, bust_cache() invalidates.
 */

class LicenseManagerCacheTest extends WP_UnitTestCase {

	private ISOFT_FMF_License_Manager $manager;

	public function set_up(): void {
		parent::set_up();
		wp_cache_flush();
		$this->manager = new ISOFT_FMF_License_Manager();
	}

	private function seed( string $title = 'Test License' ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'isoft_fmf_licenses',
			array(
				'title'       => $title,
				'slug'        => sanitize_title( $title ),
				'description' => '',
				'full_text'   => '',
				'url'         => '',
				'is_default'  => 0,
				'sort_order'  => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	private function cache_has_all(): bool {
		return false !== wp_cache_get( 'all_licenses', ISOFT_FMF_License_Manager::CACHE_GROUP );
	}

	private function cache_has( int $id ): bool {
		return false !== wp_cache_get( "license_{$id}", ISOFT_FMF_License_Manager::CACHE_GROUP );
	}

	public function test_get_all_primes_cache(): void {
		$this->seed( 'A' );
		$this->assertFalse( $this->cache_has_all() );
		$this->manager->get_all();
		$this->assertTrue( $this->cache_has_all() );
	}

	public function test_get_primes_cache(): void {
		$id = $this->seed( 'B' );
		$this->assertFalse( $this->cache_has( $id ) );
		$this->manager->get( $id );
		$this->assertTrue( $this->cache_has( $id ) );
	}

	public function test_bust_cache_clears_all_and_id(): void {
		$id = $this->seed( 'C' );
		$this->manager->get_all();
		$this->manager->get( $id );
		$this->assertTrue( $this->cache_has_all() );
		$this->assertTrue( $this->cache_has( $id ) );

		ISOFT_FMF_License_Manager::bust_cache( $id );

		$this->assertFalse( $this->cache_has_all() );
		$this->assertFalse( $this->cache_has( $id ) );
	}

	public function test_bust_cache_without_id_clears_all_only(): void {
		$id = $this->seed( 'D' );
		$this->manager->get_all();
		$this->manager->get( $id );

		ISOFT_FMF_License_Manager::bust_cache();

		$this->assertFalse( $this->cache_has_all() );
		$this->assertTrue( $this->cache_has( $id ) );
	}
}
