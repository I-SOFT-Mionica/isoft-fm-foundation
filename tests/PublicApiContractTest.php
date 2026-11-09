<?php
/**
 * docs/PUBLIC-API.md is a promise to other plugins. Every isoft_fmf_ name in it
 * must still exist in the code, so the list and the code cannot drift apart.
 */

class PublicApiContractTest extends WP_UnitTestCase {

	public function test_every_documented_name_exists_in_the_code(): void {
		$root = dirname( __DIR__ );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads a repository file.
		$doc = (string) file_get_contents( $root . '/docs/PUBLIC-API.md' );
		preg_match_all( '/\bisoft_fmf_[a-z0-9_]*[a-z0-9]/', $doc, $matches );
		$names = array_values( array_unique( $matches[0] ) );
		$this->assertGreaterThan( 50, count( $names ), 'the document was found and read' );

		$source = '';
		foreach ( array_merge( glob( $root . '/includes/*.php' ), array( $root . '/isoft-fm-foundation.php' ) ) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads a repository file.
			$source .= file_get_contents( $path );
		}

		$missing = array_values( array_filter( $names, static fn( string $name ): bool => ! str_contains( $source, $name ) ) );
		$this->assertSame( array(), $missing, 'documented but gone from the code' );
	}
}
