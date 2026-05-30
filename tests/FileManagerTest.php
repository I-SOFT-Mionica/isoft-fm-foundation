<?php
/**
 * ISFM_File_Manager CRUD smoke tests.
 *
 * These exercise the DB layer — add/get/update/delete/increment — without
 * actually serving files. add_local_file() is skipped because it requires
 * a real uploaded file on disk; a dedicated integration test can cover it.
 */

class FileManagerTest extends WP_UnitTestCase {

	private ISFM_File_Manager $manager;
	private int $download_id;

	public function set_up(): void {
		parent::set_up();
		$this->manager     = new ISFM_File_Manager();
		$this->download_id = (int) isfm_create_draft_download( array( 'title' => 'Host' ) );
	}

	public function test_add_external_link_returns_id_and_is_retrievable(): void {
		$file_id = $this->manager->add_external_link(
			$this->download_id,
			'https://example.org/file.pdf',
			array(
				'title'       => 'Example PDF',
				'description' => 'An external link',
			)
		);

		$this->assertIsInt( $file_id );
		$this->assertGreaterThan( 0, $file_id );

		$file = $this->manager->get_file( $file_id );
		$this->assertNotNull( $file );
		$this->assertSame( 'external', $file->file_type );
		$this->assertSame( 'https://example.org/file.pdf', $file->external_url );
		$this->assertSame( (int) $this->download_id, (int) $file->download_id );
	}

	public function test_get_files_returns_all_files_for_download(): void {
		$this->manager->add_external_link( $this->download_id, 'https://example.org/a.pdf', array( 'title' => 'A' ) );
		$this->manager->add_external_link( $this->download_id, 'https://example.org/b.pdf', array( 'title' => 'B' ) );

		$files = $this->manager->get_files( $this->download_id );
		$this->assertCount( 2, $files );
	}

	public function test_update_meta_changes_title_and_description(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/c.pdf', array( 'title' => 'Old' ) );

		$ok = $this->manager->update_meta( $file_id, 'New title', 'New description' );
		$this->assertTrue( (bool) $ok );

		$file = $this->manager->get_file( $file_id );
		$this->assertSame( 'New title', $file->title );
		$this->assertSame( 'New description', $file->description );
	}

	public function test_increment_count_updates_total(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/d.pdf', array( 'title' => 'D' ) );

		$this->manager->increment_count( $file_id, $this->download_id );
		$this->manager->increment_count( $file_id, $this->download_id );

		$file = $this->manager->get_file( $file_id );
		$this->assertGreaterThanOrEqual( 2, (int) $file->download_count );
	}

	public function test_delete_file_removes_row(): void {
		$file_id = $this->manager->add_external_link( $this->download_id, 'https://example.org/e.pdf', array( 'title' => 'E' ) );
		$this->assertNotNull( $this->manager->get_file( $file_id ) );

		$this->assertTrue( $this->manager->delete_file( $file_id ) );
		$this->assertNull( $this->manager->get_file( $file_id ) );
	}
}
