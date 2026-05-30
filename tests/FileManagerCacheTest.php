<?php
/**
 * ISFM_File_Manager object-cache behavior.
 *
 * Verifies that read methods hit the cache on the second call and that every
 * write path busts the cache for the affected download/file.
 */

class FileManagerCacheTest extends WP_UnitTestCase {

	private ISFM_File_Manager $manager;
	private int $download_id;

	public function set_up(): void {
		parent::set_up();
		wp_cache_flush();
		$this->manager     = new ISFM_File_Manager();
		$this->download_id = (int) isfm_create_draft_download( array( 'title' => 'CacheHost' ) );
	}

	private function cache_has_files_for_download(): bool {
		return false !== wp_cache_get( "files_for_download_{$this->download_id}", ISFM_File_Manager::CACHE_GROUP );
	}

	private function cache_has_file( int $file_id ): bool {
		return false !== wp_cache_get( "file_{$file_id}", ISFM_File_Manager::CACHE_GROUP );
	}

	public function test_get_files_primes_cache(): void {
		$this->manager->add_external_link( $this->download_id, 'https://example.org/a.pdf', array( 'title' => 'A' ) );
		$this->assertFalse( $this->cache_has_files_for_download() );
		$this->manager->get_files( $this->download_id );
		$this->assertTrue( $this->cache_has_files_for_download() );
	}

	public function test_get_file_primes_cache(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/b.pdf', array( 'title' => 'B' ) );
		wp_cache_delete( "file_{$file_id}", ISFM_File_Manager::CACHE_GROUP );
		$this->assertFalse( $this->cache_has_file( $file_id ) );
		$this->manager->get_file( $file_id );
		$this->assertTrue( $this->cache_has_file( $file_id ) );
	}

	public function test_add_external_link_busts_files_cache(): void {
		$this->manager->get_files( $this->download_id );
		$this->assertTrue( $this->cache_has_files_for_download() );
		$this->manager->add_external_link( $this->download_id, 'https://example.org/c.pdf', array( 'title' => 'C' ) );
		$this->assertFalse( $this->cache_has_files_for_download() );
	}

	public function test_update_meta_busts_caches(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/d.pdf', array( 'title' => 'Old' ) );
		$this->manager->get_files( $this->download_id );
		$this->manager->get_file( $file_id );
		$this->assertTrue( $this->cache_has_files_for_download() );
		$this->assertTrue( $this->cache_has_file( $file_id ) );

		$this->manager->update_meta( $file_id, 'New', 'New desc' );

		$this->assertFalse( $this->cache_has_files_for_download() );
		$this->assertFalse( $this->cache_has_file( $file_id ) );
	}

	public function test_delete_file_busts_caches(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/e.pdf', array( 'title' => 'E' ) );
		$this->manager->get_files( $this->download_id );
		$this->manager->get_file( $file_id );

		$this->manager->delete_file( $file_id );

		$this->assertFalse( $this->cache_has_files_for_download() );
		$this->assertFalse( $this->cache_has_file( $file_id ) );
	}

	public function test_increment_count_busts_caches(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/f.pdf', array( 'title' => 'F' ) );
		$this->manager->get_files( $this->download_id );
		$this->manager->get_file( $file_id );

		$this->manager->increment_count( $file_id, $this->download_id );

		$this->assertFalse( $this->cache_has_files_for_download() );
		$this->assertFalse( $this->cache_has_file( $file_id ) );
	}

	public function test_update_sort_order_busts_caches(): void {
		$id1 = $this->manager->add_external_link( $this->download_id, 'https://example.org/g1.pdf', array( 'title' => 'G1' ) );
		$id2 = $this->manager->add_external_link( $this->download_id, 'https://example.org/g2.pdf', array( 'title' => 'G2' ) );
		$this->manager->get_files( $this->download_id );
		$this->manager->get_file( $id1 );

		$this->manager->update_sort_order( array( $id1 => 5, $id2 => 1 ) );

		$this->assertFalse( $this->cache_has_files_for_download() );
		$this->assertFalse( $this->cache_has_file( $id1 ) );
	}

	public function test_external_bust_cache_for(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/h.pdf', array( 'title' => 'H' ) );
		$this->manager->get_files( $this->download_id );
		$this->manager->get_file( $file_id );

		ISFM_File_Manager::bust_cache_for( $this->download_id, $file_id );

		$this->assertFalse( $this->cache_has_files_for_download() );
		$this->assertFalse( $this->cache_has_file( $file_id ) );
	}

	public function test_get_files_returns_fresh_data_after_write(): void {
		$this->manager->add_external_link( $this->download_id, 'https://example.org/i1.pdf', array( 'title' => 'I1' ) );
		$this->assertCount( 1, $this->manager->get_files( $this->download_id ) );
		$this->manager->add_external_link( $this->download_id, 'https://example.org/i2.pdf', array( 'title' => 'I2' ) );
		$this->assertCount( 2, $this->manager->get_files( $this->download_id ) );
	}
}
