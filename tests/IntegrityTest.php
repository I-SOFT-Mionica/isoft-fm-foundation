<?php
/**
 * ISFM_File_Integrity tests — missing detection, inode relink, auto-republish.
 *
 * These tests create real files under isfm_files_dir() so inode/hash behavior is
 * exercised end-to-end. POSIX inode recovery is skipped on Windows runners.
 */

class IntegrityTest extends WP_UnitTestCase {

	private ISFM_File_Integrity $integrity;
	private int $download_id;
	private int $term_id;
	private string $category_dir;

	public function set_up(): void {
		parent::set_up();
		$this->integrity = new ISFM_File_Integrity();

		// Create category + its folder.
		$term           = wp_insert_term( 'Integrity Cat', 'isfm_category', array( 'slug' => 'integrity-cat' ) );
		$this->term_id  = (int) $term['term_id'];
		$this->category_dir = isfm_category_fs_path( $this->term_id );
		if ( ! is_dir( $this->category_dir ) ) {
			wp_mkdir_p( $this->category_dir );
		}

		$this->download_id = (int) isfm_create_draft_download( array( 'title' => 'Integrity Host' ) );
		wp_set_object_terms( $this->download_id, array( $this->term_id ), 'isfm_category' );
		wp_update_post(
			array(
				'ID'          => $this->download_id,
				'post_status' => 'publish',
			)
		);

		// Ensure inode option is on for these tests — turned off individually where needed.
		update_option( 'isfm_integrity_use_inode', 1 );
		update_option( 'isfm_integrity_autorelink', 1 );
	}

	public function tear_down(): void {
		// Remove leftover files from the category folder.
		if ( is_dir( $this->category_dir ) ) {
			foreach ( (array) @scandir( $this->category_dir ) as $e ) {
				if ( '.' === $e || '..' === $e ) {
					continue;
				}
				@unlink( $this->category_dir . '/' . $e );
			}
		}
		parent::tear_down();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/** Create a real file on disk and a matching wp_isfm_files row; returns row id. */
	private function make_local_file( string $name, string $contents ): int {
		global $wpdb;
		$abs      = $this->category_dir . '/' . $name;
		file_put_contents( $abs, $contents );
		$rel      = ltrim( str_replace( isfm_files_dir(), '', $abs ), '/\\' );
		$rel      = str_replace( '\\', '/', $rel );
		$hash     = hash_file( 'sha256', $abs );
		$inode    = (int) @fileinode( $abs );

		$wpdb->insert(
			$wpdb->prefix . 'isfm_files',
			array(
				'download_id' => $this->download_id,
				'file_type'   => 'local',
				'file_name'   => $name,
				'file_path'   => $rel,
				'file_size'   => filesize( $abs ),
				'file_hash'   => $hash,
				'inode'       => $inode ?: null,
				'title'       => $name,
				'is_missing'  => 0,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	private function get_row( int $id ): object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}isfm_files WHERE id = %d", $id ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Tests
	// ─────────────────────────────────────────────────────────────────────────

	public function test_is_missing_flag_defaults_zero_on_insert(): void {
		$id  = $this->make_local_file( 'a.txt', 'alpha' );
		$row = $this->get_row( $id );
		$this->assertSame( 0, (int) $row->is_missing );
		$this->assertNull( $row->missing_since );
	}

	public function test_handle_missing_marks_row_and_sets_timestamp(): void {
		$id  = $this->make_local_file( 'b.txt', 'beta' );
		$row = $this->get_row( $id );
		unlink( $this->category_dir . '/b.txt' );

		$mode = ISFM_File_Integrity::handle_missing( $row, $this->download_id );
		$this->assertSame( 'unpublished', $mode );

		$row = $this->get_row( $id );
		$this->assertSame( 1, (int) $row->is_missing );
		$this->assertNotNull( $row->missing_since );
	}

	public function test_handle_missing_is_idempotent(): void {
		$id  = $this->make_local_file( 'c.txt', 'gamma' );
		$row = $this->get_row( $id );
		unlink( $this->category_dir . '/c.txt' );

		ISFM_File_Integrity::handle_missing( $row, $this->download_id );
		$first_notices = count( (array) get_option( 'isfm_admin_notices', array() ) );

		// Second call must not re-queue (pass the post-update row, which now has is_missing=1).
		$row2 = $this->get_row( $id );
		ISFM_File_Integrity::handle_missing( $row2, $this->download_id );
		$second_notices = count( (array) get_option( 'isfm_admin_notices', array() ) );

		$this->assertSame( $first_notices, $second_notices );
	}

	public function test_handle_missing_unpublishes_when_all_files_missing(): void {
		$id  = $this->make_local_file( 'd.txt', 'delta' );
		$row = $this->get_row( $id );
		unlink( $this->category_dir . '/d.txt' );

		ISFM_File_Integrity::handle_missing( $row, $this->download_id );
		$this->assertSame( 'draft', get_post_status( $this->download_id ) );
		$this->assertNotEmpty( get_post_meta( $this->download_id, '_isfm_auto_unpublished_at', true ) );
	}

	public function test_handle_missing_does_not_unpublish_when_other_files_healthy(): void {
		$id1 = $this->make_local_file( 'e1.txt', 'e1' );
		$id2 = $this->make_local_file( 'e2.txt', 'e2' );
		$row1 = $this->get_row( $id1 );
		unlink( $this->category_dir . '/e1.txt' );

		$mode = ISFM_File_Integrity::handle_missing( $row1, $this->download_id );
		$this->assertSame( 'partial', $mode );
		$this->assertSame( 'publish', get_post_status( $this->download_id ) );
		$this->assertEmpty( get_post_meta( $this->download_id, '_isfm_auto_unpublished_at', true ) );
	}

	public function test_try_relink_by_inode_updates_path(): void {
		if ( 0 === (int) @fileinode( __FILE__ ) ) {
			$this->markTestSkipped( 'Filesystem does not expose POSIX inodes.' );
		}

		$id = $this->make_local_file( 'f.txt', 'foxtrot' );
		// Rename in place — inode stable on POSIX.
		rename( $this->category_dir . '/f.txt', $this->category_dir . '/f-renamed.txt' );

		$row = $this->get_row( $id );
		$ok  = $this->integrity->try_relink_by_inode( $row );
		$this->assertTrue( $ok );

		$row = $this->get_row( $id );
		$this->assertSame( 'f-renamed.txt', $row->file_name );
		$this->assertStringContainsString( 'f-renamed.txt', (string) $row->file_path );
	}

	public function test_try_relink_returns_false_when_inode_absent(): void {
		$id  = $this->make_local_file( 'g.txt', 'golf' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'isfm_files', array( 'inode' => null ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		unlink( $this->category_dir . '/g.txt' );

		$row = $this->get_row( $id );
		$this->assertFalse( $this->integrity->try_relink_by_inode( $row ) );
	}

	public function test_integrity_scan_heals_previously_missing_file_when_restored(): void {
		$id  = $this->make_local_file( 'h.txt', 'hotel' );
		global $wpdb;
		// Pretend the row was flagged missing previously.
		$wpdb->update(
			$wpdb->prefix . 'isfm_files',
			array(
				'is_missing'    => 1,
				'missing_since' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		// File is present at its expected path, so the scan should heal it.
		$summary = $this->integrity->run_scheduled_check();
		$this->assertGreaterThanOrEqual( 1, $summary['healed'] );

		$row = $this->get_row( $id );
		$this->assertSame( 0, (int) $row->is_missing );
		$this->assertNull( $row->missing_since );
	}

	public function test_scheduled_check_reschedules_on_option_change(): void {
		update_option( 'isfm_integrity_check_enabled', 1 );
		update_option( 'isfm_integrity_check_time', '03:15' );
		$this->integrity->maybe_schedule();
		$this->assertNotFalse( wp_next_scheduled( 'isfm_integrity_check' ) );

		update_option( 'isfm_integrity_check_enabled', 0 );
		$this->integrity->reschedule();
		$this->assertFalse( wp_next_scheduled( 'isfm_integrity_check' ) );
	}

	public function test_auto_republish_only_fires_when_auto_unpublished_flag_set(): void {
		$id  = $this->make_local_file( 'i.txt', 'india' );
		$row = $this->get_row( $id );
		unlink( $this->category_dir . '/i.txt' );
		ISFM_File_Integrity::handle_missing( $row, $this->download_id );
		$this->assertSame( 'draft', get_post_status( $this->download_id ) );

		// Restore the file contents and run the scan — should heal + auto-republish.
		file_put_contents( $this->category_dir . '/i.txt', 'india' );
		$this->integrity->run_scheduled_check();
		$this->assertSame( 'publish', get_post_status( $this->download_id ) );
		$this->assertEmpty( get_post_meta( $this->download_id, '_isfm_auto_unpublished_at', true ) );

		// Separately: a manually drafted post must NOT be auto-republished.
		$manual = (int) isfm_create_draft_download( array( 'title' => 'Manual Draft' ) );
		wp_set_object_terms( $manual, array( $this->term_id ), 'isfm_category' );
		$id2 = $this->make_local_file( 'j.txt', 'juliet' );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'isfm_files',
			array(
				'download_id' => $manual,
				'is_missing'  => 1,
			),
			array( 'id' => $id2 ),
			array( '%d', '%d' ),
			array( '%d' )
		);
		// No _isfm_auto_unpublished_at meta — run scan, should heal but not publish.
		$this->integrity->run_scheduled_check();
		$this->assertSame( 'draft', get_post_status( $manual ) );
	}
}
