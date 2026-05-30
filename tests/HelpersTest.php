<?php
/**
 * Tests for public helper functions in includes/functions.php.
 */

class HelpersTest extends WP_UnitTestCase {

	public function test_create_draft_download_requires_title(): void {
		$this->assertFalse( isfm_create_draft_download( array() ) );
		$this->assertFalse( isfm_create_draft_download( array( 'title' => '' ) ) );
	}

	public function test_create_draft_download_sets_type_and_status(): void {
		$id = isfm_create_draft_download( array( 'title' => 'Sample document' ) );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$post = get_post( $id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'isfm_file', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Sample document', $post->post_title );
	}

	public function test_create_draft_download_assigns_category_and_meta(): void {
		$term = wp_insert_term( 'Reports', 'isfm_category' );
		$this->assertIsArray( $term );

		$id = isfm_create_draft_download(
			array(
				'title'       => 'Q1 Report',
				'category_id' => $term['term_id'],
				'access_role' => 'subscriber',
				'license_id'  => 7,
			)
		);

		$this->assertIsInt( $id );
		$terms = wp_get_object_terms( $id, 'isfm_category', array( 'fields' => 'ids' ) );
		$this->assertContains( (int) $term['term_id'], array_map( 'intval', $terms ) );
		$this->assertSame( 'subscriber', get_post_meta( $id, '_isfm_access_role', true ) );
		$this->assertSame( '7', (string) get_post_meta( $id, '_isfm_license_id', true ) );
	}

	public function test_cyrillic_to_latin_basic_transliteration(): void {
		// Serbian Cyrillic → Latin.
		$this->assertSame( 'Mionica', isfm_cyrillic_to_latin( 'Мионица' ) );
		$this->assertSame( 'opština', isfm_cyrillic_to_latin( 'општина' ) );
	}

	public function test_cyrillic_to_latin_passes_through_ascii(): void {
		$this->assertSame( 'already-latin', isfm_cyrillic_to_latin( 'already-latin' ) );
	}

	/**
	 * End-to-end check that a Cyrillic category name lands on disk as an
	 * ASCII folder path. The raw transliterator preserves š/č/ž/đ/ć, but
	 * the full slug pipeline runs sanitize_title() which strips those via
	 * remove_accents() — so filesystem paths stay 7-bit safe.
	 */
	public function test_cyrillic_category_produces_ascii_folder_path(): void {
		$term = wp_insert_term( 'Општина Мионица', 'isfm_category' );
		$this->assertIsArray( $term );

		$slug = get_term( $term['term_id'], 'isfm_category' )->slug;
		$this->assertMatchesRegularExpression( '/^[a-z0-9\-]+$/', $slug, "Slug {$slug} must be ASCII-only." );

		$folder = isfm_category_folder_path( $term['term_id'] );
		$this->assertSame( $slug, $folder );
		$this->assertMatchesRegularExpression( '/^[a-z0-9\-\/]+$/', $folder );
	}

	public function test_category_folder_path_walks_ancestors(): void {
		$parent = wp_insert_term( 'Skupstina', 'isfm_category', array( 'slug' => 'skupstina' ) );
		$child  = wp_insert_term(
			'Saziv',
			'isfm_category',
			array(
				'slug'   => 'saziv-2025',
				'parent' => $parent['term_id'],
			)
		);

		$this->assertSame( 'skupstina/saziv-2025', isfm_category_folder_path( $child['term_id'] ) );
	}
}
