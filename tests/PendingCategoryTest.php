<?php
/**
 * Pending categories: held back from the public until approved, visible to
 * category managers and background jobs, and inert for sites that never use them.
 */
class PendingCategoryTest extends WP_UnitTestCase {

	private int $open;
	private int $held;
	private int $held_child;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );

		$this->open       = (int) wp_insert_term( 'Open', 'isoft_fmf_category' )['term_id'];
		$this->held       = (int) wp_insert_term( 'Held', 'isoft_fmf_category' )['term_id'];
		$this->held_child = (int) wp_insert_term( 'Held child', 'isoft_fmf_category', array( 'parent' => $this->held ) )['term_id'];
		isoft_fmf_set_category_pending( $this->held, true );
	}

	private function listed(): array {
		$ids = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		sort( $ids );
		return array_map( 'intval', $ids );
	}

	private function become( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		ISOFT_FMF_Activator::maybe_register_capabilities();
		wp_get_current_user()->get_role_caps();
	}

	public function test_state_round_trips_and_validates_the_term(): void {
		$this->assertTrue( isoft_fmf_category_is_pending( $this->held ) );
		$this->assertFalse( isoft_fmf_category_is_pending( $this->open ) );
		$this->assertFalse( isoft_fmf_category_is_pending( 0 ) );
		$this->assertFalse( isoft_fmf_category_is_pending( 999999 ) );
		$this->assertFalse( isoft_fmf_set_category_pending( 999999, true ) );

		$tag = (int) wp_insert_term( 'A tag', 'isoft_fmf_tag' )['term_id'];
		$this->assertFalse( isoft_fmf_set_category_pending( $tag, true ), 'only categories can be pending' );

		$this->assertTrue( isoft_fmf_set_category_pending( $this->held, false ) );
		$this->assertFalse( isoft_fmf_category_is_pending( $this->held ) );
		$this->assertTrue( isoft_fmf_set_category_pending( $this->held, false ), 'idempotent' );
	}

	public function test_visitors_do_not_see_a_pending_category_or_its_children(): void {
		$this->assertSame( array( $this->open ), $this->listed() );
	}

	public function test_approving_shows_them_again(): void {
		isoft_fmf_set_category_pending( $this->held, false );

		$expected = array( $this->open, $this->held, $this->held_child );
		sort( $expected );
		$this->assertSame( $expected, $this->listed() );
	}

	public function test_include_lists_are_filtered_too(): void {
		$ids = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'hide_empty' => false,
				'fields'     => 'ids',
				'include'    => array( $this->open, $this->held, $this->held_child ),
			)
		);

		$this->assertSame( array( $this->open ), array_map( 'intval', $ids ) );
	}

	public function test_category_managers_see_everything(): void {
		$this->become( 'editor' );

		$expected = array( $this->open, $this->held, $this->held_child );
		sort( $expected );
		$this->assertSame( $expected, $this->listed() );
	}

	public function test_an_author_without_category_management_does_not(): void {
		$this->become( 'author' );

		$this->assertSame( array( $this->open ), $this->listed() );
	}

	public function test_background_jobs_see_everything(): void {
		add_filter( 'wp_doing_cron', '__return_true' );

		$expected = array( $this->open, $this->held, $this->held_child );
		sort( $expected );
		$this->assertSame( $expected, $this->listed() );
	}

	public function test_explicit_include_flag_sees_pending(): void {
		$ids = get_terms(
			array(
				'taxonomy'                  => 'isoft_fmf_category',
				'hide_empty'                => false,
				'fields'                    => 'ids',
				'isoft_fmf_include_pending' => true,
			)
		);

		$this->assertContains( $this->held, array_map( 'intval', $ids ) );
	}

	public function test_folder_lookup_still_finds_a_pending_category_for_anyone(): void {
		$this->assertSame( $this->held_child, isoft_fmf_category_for_folder( isoft_fmf_category_folder_path( $this->held_child ) ) );
	}

	public function test_sites_without_pending_categories_are_untouched(): void {
		isoft_fmf_set_category_pending( $this->held, false );
		$args = array( 'taxonomy' => array( 'isoft_fmf_category' ) );

		$this->assertSame( $args, ( new ISOFT_FMF_Category_Pending() )->hide_in_term_queries( $args, array( 'isoft_fmf_category' ) ) );
	}

	public function test_other_taxonomies_are_untouched(): void {
		$args = array( 'taxonomy' => array( 'category' ) );

		$this->assertSame( $args, ( new ISOFT_FMF_Category_Pending() )->hide_in_term_queries( $args, array( 'category' ) ) );
	}

	public function test_a_downloads_category_list_hides_a_pending_category_from_visitors(): void {
		$download = self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $download, array( $this->held ), 'isoft_fmf_category' );

		$this->assertSame( array(), get_the_terms( $download, 'isoft_fmf_category' ) );

		$this->become( 'editor' );
		$this->assertCount( 1, get_the_terms( $download, 'isoft_fmf_category' ) );
	}

	public function test_the_archive_of_a_pending_category_is_a_404_until_approved(): void {
		$this->go_to( get_term_link( $this->held, 'isoft_fmf_category' ) );
		do_action( 'template_redirect' );
		$this->assertTrue( is_404() );

		$this->go_to( get_term_link( $this->held_child, 'isoft_fmf_category' ) );
		do_action( 'template_redirect' );
		$this->assertTrue( is_404(), 'children of a pending category are hidden too' );

		isoft_fmf_set_category_pending( $this->held, false );
		$this->go_to( get_term_link( $this->held, 'isoft_fmf_category' ) );
		do_action( 'template_redirect' );
		$this->assertFalse( is_404() );
	}

	public function test_sitemaps_exclude_pending_categories(): void {
		$args = apply_filters( 'wp_sitemaps_taxonomies_query_args', array(), 'isoft_fmf_category' );

		$this->assertContains( $this->held, $args['exclude_tree'] );
		$this->assertSame( array(), apply_filters( 'wp_sitemaps_taxonomies_query_args', array(), 'category' ) );
	}

	public function test_changes_are_announced(): void {
		$changes = array();
		$events  = array();
		add_action(
			'isoft_fmf_category_pending_changed',
			function ( $term_id, $pending ) use ( &$changes ): void {
				$changes[] = array( $term_id, $pending );
			},
			10,
			2
		);
		add_action(
			'isoft_fmf_content_changed',
			function ( $type, $id ) use ( &$events ): void {
				$events[] = array( $type, $id );
			},
			10,
			2
		);

		isoft_fmf_set_category_pending( $this->open, true );
		isoft_fmf_set_category_pending( $this->open, true );
		isoft_fmf_set_category_pending( $this->open, false );

		$this->assertSame( array( array( $this->open, true ), array( $this->open, false ) ), $changes );
		$this->assertSame( array( array( 'category', $this->open ), array( 'category', $this->open ) ), $events );
	}

	public function test_admin_list_marks_pending_and_offers_approve_to_managers_only(): void {
		$pending = new ISOFT_FMF_Category_Pending();
		$this->assertStringContainsString( 'Pending', $pending->render_column( '', 'isoft_fmf_pending', $this->held ) );
		$this->assertStringNotContainsString( 'Pending', $pending->render_column( '', 'isoft_fmf_pending', $this->open ) );
		$this->assertSame( 'kept', $pending->render_column( 'kept', 'other', $this->held ) );

		$term = get_term( $this->held, 'isoft_fmf_category' );
		$this->assertArrayNotHasKey( 'approve', $pending->row_actions( array(), $term ), 'visitors get no action' );

		$this->become( 'editor' );
		$actions = $pending->row_actions( array(), $term );
		$this->assertArrayHasKey( 'approve', $actions );
		$this->assertStringContainsString( 'isoft_fmf_approve_category', $actions['approve'] );
		$this->assertArrayNotHasKey( 'approve', $pending->row_actions( array(), get_term( $this->open, 'isoft_fmf_category' ) ) );
	}
}
