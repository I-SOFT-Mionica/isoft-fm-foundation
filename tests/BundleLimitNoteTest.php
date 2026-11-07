<?php
/**
 * Editor note when a download is over the ZIP bundle limit.
 *
 * @package isoft-fm-foundation
 */

class BundleLimitNoteTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'isoft_fmf_enable_zip_bundle', 1 );
	}

	public function tear_down(): void {
		delete_option( 'isoft_fmf_enable_zip_bundle' );
		remove_all_filters( 'isoft_fmf_bundle_limits' );
		parent::tear_down();
	}

	/**
	 * File rows as get_files() returns them, reduced to what the note reads.
	 *
	 * @param int[]  $sizes Sizes in bytes.
	 * @param string $type  File type.
	 * @return object[]
	 */
	private function rows( array $sizes, string $type = 'local' ): array {
		return array_map(
			static function ( int $size ) use ( $type ) {
				return (object) array(
					'file_type'      => $type,
					'file_size'      => $size,
					'is_missing'     => 0,
					'is_unavailable' => 0,
				);
			},
			$sizes
		);
	}

	public function test_no_note_when_the_files_fit(): void {
		$this->assertSame( '', isoft_fmf_bundle_limit_note( $this->rows( array( MB_IN_BYTES, MB_IN_BYTES ) ) ) );
	}

	public function test_note_when_the_files_are_too_big_together(): void {
		$note = isoft_fmf_bundle_limit_note( $this->rows( array( 300 * MB_IN_BYTES, 300 * MB_IN_BYTES ) ) );

		$this->assertStringContainsString( 'Visitors are not offered "Download all"', $note );
		$this->assertStringContainsString( 'its 2 files (600 MB)', $note );
		$this->assertStringContainsString( 'ZIP limit of 100 files and 512 MB', $note );
	}

	public function test_note_when_there_are_too_many_files(): void {
		add_filter(
			'isoft_fmf_bundle_limits',
			static function () {
				return array(
					'max_files' => 3,
					'max_bytes' => 0,
				);
			}
		);
		$note = isoft_fmf_bundle_limit_note( $this->rows( array( 1, 1, 1, 1 ) ) );

		$this->assertStringContainsString( 'its 4 files', $note );
		$this->assertStringContainsString( 'ZIP limit of 3 files.', $note, 'a limit of 0 is not listed' );
	}

	public function test_no_note_when_bundles_are_off_or_nothing_to_bundle(): void {
		$big = $this->rows( array( 300 * MB_IN_BYTES, 300 * MB_IN_BYTES ) );

		// One local file: there is never a "Download all".
		$this->assertSame( '', isoft_fmf_bundle_limit_note( array_slice( $big, 0, 1 ) ) );

		// External links are not bundled.
		$this->assertSame( '', isoft_fmf_bundle_limit_note( $this->rows( array( 300 * MB_IN_BYTES, 300 * MB_IN_BYTES ), 'external' ) ) );

		update_option( 'isoft_fmf_enable_zip_bundle', 0 );
		$this->assertSame( '', isoft_fmf_bundle_limit_note( $big ) );
	}
}
