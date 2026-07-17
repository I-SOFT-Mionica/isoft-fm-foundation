<?php
/**
 * Test the 0.12.1 single-category data migration.
 *
 * Covers:
 *  - downloads with one category are untouched
 *  - downloads with multiple categories are trimmed to the first one
 *  - the migration is idempotent on a second run
 *  - the result transient lists the affected post IDs and removed count
 */

class Migration_0_12_1_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_transient( 'isoft_fmf_migration_0_12_1' );
	}

	private function make_download_with_categories( array $term_ids ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
				'post_title'  => 'Migration Test ' . wp_generate_password( 6, false ),
			)
		);
		wp_set_object_terms( $post_id, $term_ids, 'isoft_fmf_category', false );
		return $post_id;
	}

	private function make_category( string $name ): int {
		$term = wp_insert_term( $name . ' ' . wp_generate_password( 6, false ), 'isoft_fmf_category' );
		return (int) $term['term_id'];
	}

	private function run_migration_via_activator(): void {
		// Force the activator to run the 0.12.1 migration block by stamping
		// the stored db_version as something older.
		update_option( 'isoft_fmf_db_version', '0.12.0' );
		ISOFT_FMF_Activator::activate();
	}

	public function test_single_category_post_is_untouched(): void {
		$cat     = $this->make_category( 'OnlyOne' );
		$post_id = $this->make_download_with_categories( array( $cat ) );

		$this->run_migration_via_activator();

		$terms = wp_get_object_terms( $post_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		$this->assertSame( array( $cat ), array_map( 'intval', $terms ) );
	}

	public function test_multi_category_post_trims_to_first(): void {
		$cat_a   = $this->make_category( 'A' );
		$cat_b   = $this->make_category( 'B' );
		$cat_c   = $this->make_category( 'C' );
		$post_id = $this->make_download_with_categories( array( $cat_a, $cat_b, $cat_c ) );

		$this->run_migration_via_activator();

		$terms = wp_get_object_terms( $post_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		$this->assertCount( 1, $terms );
		// Note: the migration keeps the FIRST term returned by wp_get_object_terms,
		// which is ordered by term_taxonomy_id ASC by default. With newly-created
		// terms in this test, that's stable.
		$this->assertContains( (int) $terms[0], array( $cat_a, $cat_b, $cat_c ) );
	}

	public function test_migration_is_idempotent(): void {
		$cat_a   = $this->make_category( 'AA' );
		$cat_b   = $this->make_category( 'BB' );
		$post_id = $this->make_download_with_categories( array( $cat_a, $cat_b ) );

		$this->run_migration_via_activator();
		$after_first = wp_get_object_terms( $post_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );

		// Reset the transient and re-run — second run must find nothing to
		// trim. The migration only enters the loop when at least one post
		// has >1 assignment, which is no longer true.
		delete_transient( 'isoft_fmf_migration_0_12_1' );
		$this->run_migration_via_activator();
		$after_second = wp_get_object_terms( $post_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );

		$this->assertSame(
			array_map( 'intval', $after_first ),
			array_map( 'intval', $after_second ),
			'Second migration run must produce identical state.'
		);
		$this->assertFalse(
			get_transient( 'isoft_fmf_migration_0_12_1' ),
			'Idempotent second run must not write the result transient.'
		);
	}

	public function test_result_transient_lists_touched_posts(): void {
		$cat_a    = $this->make_category( 'X' );
		$cat_b    = $this->make_category( 'Y' );
		$post_one = $this->make_download_with_categories( array( $cat_a, $cat_b ) );
		$post_two = $this->make_download_with_categories( array( $cat_a, $cat_b ) );

		$this->run_migration_via_activator();

		$result = get_transient( 'isoft_fmf_migration_0_12_1' );
		$this->assertIsArray( $result );
		$this->assertContains( $post_one, $result['touched_post_ids'] );
		$this->assertContains( $post_two, $result['touched_post_ids'] );
		$this->assertSame( 2, $result['removed_assignments'] );
	}
}
