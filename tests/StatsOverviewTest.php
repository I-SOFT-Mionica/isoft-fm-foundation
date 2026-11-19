<?php
/**
 * Tools > Statistics: the cached overview follows changes, and counts only
 * files that belong to a download.
 */

class StatsOverviewTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_transient( 'isoft_fmf_stats_overview' );
	}

	private function download( string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => $status,
			)
		);
	}

	private function link( int $download ): int {
		return (int) isoft_fmf_add_external_link( $download, 'https://example.org/' . wp_generate_password( 6, false ) );
	}

	public function test_adding_and_removing_a_file_shows_at_once(): void {
		$download = $this->download();
		$before   = isoft_fmf_get_stats_overview()['total_files'];

		$file = $this->link( $download );
		$this->assertSame( $before + 1, isoft_fmf_get_stats_overview()['total_files'], 'added' );

		( new ISOFT_FMF_Files_Service() )->delete( $file );
		$this->assertSame( $before, isoft_fmf_get_stats_overview()['total_files'], 'removed' );
	}

	public function test_publishing_shows_at_once(): void {
		$download = $this->download( 'draft' );
		$before   = isoft_fmf_get_stats_overview()['total_downloads'];

		wp_publish_post( $download );

		$this->assertSame( $before + 1, isoft_fmf_get_stats_overview()['total_downloads'] );
	}

	public function test_a_row_without_its_download_is_not_counted(): void {
		global $wpdb;
		$before = isoft_fmf_get_stats_overview()['total_files'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture: a row left behind by an old version.
		$wpdb->insert(
			$wpdb->prefix . 'isoft_fmf_files',
			array(
				'download_id' => 999999,
				'file_type'   => 'local',
				'file_name'   => 'left-behind.pdf',
				'file_size'   => 5000,
			)
		);
		delete_transient( 'isoft_fmf_stats_overview' );

		$stats = isoft_fmf_get_stats_overview();
		$this->assertSame( $before, $stats['total_files'] );
	}
}
