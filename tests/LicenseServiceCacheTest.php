<?php
/**
 * ISOFT_FMF_License_Service object-cache behavior.
 *
 * Replaces the pre-0.12.0 LicenseManagerCacheTest — the cache surface
 * lived on License_Manager when it owned the data layer. Now the data
 * layer is License_Service; the Manager is a thin delegator. Tests
 * target the canonical home so they survive the 0.12.5 Manager
 * demolition unchanged.
 */

class LicenseServiceCacheTest extends WP_UnitTestCase {

	private ISOFT_FMF_License_Service $service;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_licenses" );
		wp_cache_flush();
		$this->service = new ISOFT_FMF_License_Service();
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
		return false !== wp_cache_get( 'all_licenses', ISOFT_FMF_License_Service::CACHE_GROUP );
	}

	private function cache_has( int $id ): bool {
		return false !== wp_cache_get( "license_{$id}", ISOFT_FMF_License_Service::CACHE_GROUP );
	}

	public function test_list_primes_cache(): void {
		$this->seed( 'A' );
		$this->assertFalse( $this->cache_has_all() );
		$this->service->list();
		$this->assertTrue( $this->cache_has_all() );
	}

	public function test_get_primes_cache(): void {
		$id = $this->seed( 'B' );
		$this->assertFalse( $this->cache_has( $id ) );
		$this->service->get( $id );
		$this->assertTrue( $this->cache_has( $id ) );
	}

	public function test_bust_cache_clears_all_and_id(): void {
		$id = $this->seed( 'C' );
		$this->service->list();
		$this->service->get( $id );
		$this->assertTrue( $this->cache_has_all() );
		$this->assertTrue( $this->cache_has( $id ) );

		ISOFT_FMF_License_Service::bust_cache( $id );

		$this->assertFalse( $this->cache_has_all() );
		$this->assertFalse( $this->cache_has( $id ) );
	}

	public function test_bust_cache_without_id_clears_all_only(): void {
		$id = $this->seed( 'D' );
		$this->service->list();
		$this->service->get( $id );

		ISOFT_FMF_License_Service::bust_cache();

		$this->assertFalse( $this->cache_has_all() );
		$this->assertTrue( $this->cache_has( $id ) );
	}

	public function test_create_busts_list_cache(): void {
		$this->seed( 'Pre-existing' );
		$this->service->list();
		$this->assertTrue( $this->cache_has_all() );

		$this->service->create( array( 'title' => 'New' ) );

		$this->assertFalse( $this->cache_has_all() );
	}

	public function test_update_busts_caches_for_id(): void {
		$id = $this->service->create( array( 'title' => 'Original' ) );
		$this->service->get( $id );
		$this->service->list();
		$this->assertTrue( $this->cache_has( $id ) );
		$this->assertTrue( $this->cache_has_all() );

		$this->service->update( $id, array( 'title' => 'Updated' ) );

		$this->assertFalse( $this->cache_has( $id ) );
		$this->assertFalse( $this->cache_has_all() );
	}

	public function test_delete_busts_caches_for_id(): void {
		$id = $this->service->create( array( 'title' => 'Doomed' ) );
		$this->service->list();
		$this->service->get( $id );

		$this->service->delete( $id );

		$this->assertFalse( $this->cache_has( $id ) );
		$this->assertFalse( $this->cache_has_all() );
	}
}
