<?php
/**
 * Tests for ISOFT_FMF_Featured — the posts_clauses filter that pins
 * downloads flagged `_isoft_fmf_featured = 1` to the top of every
 * isoft_fmf_file query.
 */

class FeaturedTest extends WP_UnitTestCase {

	private int $featured_old;
	private int $featured_new;
	private int $plain_old;
	private int $plain_new;

	public function set_up(): void {
		parent::set_up();
		// Create four downloads spanning two ages × two featured states so we
		// can prove featured wins over date and plain falls back to date.
		$this->plain_old    = $this->make_download( 'Plain old',    '-30 days', false );
		$this->plain_new    = $this->make_download( 'Plain new',    '-1 day',   false );
		$this->featured_old = $this->make_download( 'Featured old', '-20 days', true );
		$this->featured_new = $this->make_download( 'Featured new', '-2 days',  true );
	}

	private function make_download( string $title, string $date_offset, bool $featured ): int {
		$id = (int) isoft_fmf_create_draft_download( array( 'title' => $title ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( $date_offset ) ),
			)
		);
		if ( $featured ) {
			update_post_meta( $id, '_isoft_fmf_featured', 1 );
		}
		return $id;
	}

	private function query_ids( array $extra_args = array() ): array {
		$q = new WP_Query(
			array_merge(
				array(
					'post_type'      => 'isoft_fmf_file',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				),
				$extra_args
			)
		);
		return array_map( 'intval', $q->posts );
	}

	public function test_featured_downloads_are_returned_before_non_featured(): void {
		$ids = $this->query_ids();
		$pos = array_flip( $ids );

		$this->assertLessThan( $pos[ $this->plain_new ], $pos[ $this->featured_new ] );
		$this->assertLessThan( $pos[ $this->plain_new ], $pos[ $this->featured_old ] );
	}

	public function test_within_each_group_secondary_sort_is_preserved(): void {
		$ids = $this->query_ids();
		$pos = array_flip( $ids );

		// Featured group, newest first.
		$this->assertLessThan( $pos[ $this->featured_old ], $pos[ $this->featured_new ] );
		// Non-featured group, newest first.
		$this->assertLessThan( $pos[ $this->plain_old ], $pos[ $this->plain_new ] );
	}

	public function test_per_query_opt_out_disables_featured_prefix(): void {
		$ids = $this->query_ids( array( 'isoft_fmf_featured_first' => '0' ) );
		$pos = array_flip( $ids );

		// Without the prefix, pure date DESC: plain_new is newest of all.
		$this->assertSame( $this->plain_new, $ids[0] );
		// featured_old (-20d) is older than plain_new (-1d) so plain_new wins.
		$this->assertLessThan( $pos[ $this->featured_old ], $pos[ $this->plain_new ] );
	}

	public function test_site_wide_filter_disables_featured_prefix(): void {
		add_filter( 'isoft_fmf_featured_first_enabled', '__return_false' );
		$ids = $this->query_ids();
		remove_filter( 'isoft_fmf_featured_first_enabled', '__return_false' );

		$this->assertSame( $this->plain_new, $ids[0] );
	}

	public function test_rand_orderby_is_not_affected(): void {
		// Just verify the filter doesn't blow up on orderby=rand. We can't
		// assert the order of a random query.
		$ids = $this->query_ids( array( 'orderby' => 'rand' ) );
		$this->assertCount( 4, $ids );
	}

	public function test_unrelated_post_types_are_left_alone(): void {
		$other = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$q     = new WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		// The featured JOIN must not run for non-isoft_fmf_file queries, so
		// the only assertion that matters is "query executed and returned our
		// post" — no SQL parse error, no LEFT JOIN injected.
		$this->assertContains( $other, array_map( 'intval', $q->posts ) );
	}
}
