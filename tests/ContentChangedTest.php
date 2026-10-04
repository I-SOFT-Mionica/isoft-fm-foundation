<?php
/**
 * isoft_fmf_content_changed fires from every file / license write path that
 * WordPress's own post and term hooks don't cover, so static builds and
 * caches can refresh. Download counters must NOT fire it.
 */
class ContentChangedTest extends WP_UnitTestCase {

	/** @var list<array{0: string, 1: int}> */
	private array $events = array();

	private int $download_id;
	private ISOFT_FMF_File_Manager $files;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );

		$this->download_id = self::factory()->post->create( array( 'post_type' => 'isoft_fmf_file' ) );
		$this->files       = new ISOFT_FMF_File_Manager();

		$this->events = array();
		add_action(
			'isoft_fmf_content_changed',
			function ( string $type, int $id ): void {
				$this->events[] = array( $type, $id );
			},
			10,
			2
		);
	}

	private function add_link(): int {
		return (int) $this->files->add_external_link( $this->download_id, 'https://example.org/a' );
	}

	public function test_adding_files_fires(): void {
		$this->add_link();
		$this->files->add_local_file(
			$this->download_id,
			array(
				'file_name' => 'a.pdf',
				'file_path' => 'x/a.pdf',
			)
		);

		$this->assertSame( array( array( 'download', $this->download_id ), array( 'download', $this->download_id ) ), $this->events );
	}

	public function test_editing_reordering_and_removing_files_fire(): void {
		$file_id      = $this->add_link();
		$this->events = array();

		$this->files->update_meta( $file_id, 'New title', '' );
		$this->files->update_sort_order( array( $file_id => 3 ) );
		$this->files->delete_file( $file_id );

		$this->assertSame( array_fill( 0, 3, array( 'download', $this->download_id ) ), $this->events );
	}

	public function test_download_counters_do_not_fire(): void {
		$file_id      = $this->add_link();
		$this->events = array();

		$this->files->increment_count( $file_id, $this->download_id );

		$this->assertSame( array(), $this->events );
	}

	public function test_license_writes_fire(): void {
		$licenses = new ISOFT_FMF_License_Service();

		$id = $licenses->create( array( 'title' => 'Test License' ) );
		$licenses->update( $id, array( 'title' => 'Renamed' ) );
		$licenses->delete( $id );

		$this->assertSame( array_fill( 0, 3, array( 'license', $id ) ), $this->events );
	}
}
