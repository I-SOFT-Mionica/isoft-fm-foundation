<?php
/**
 * Tests for the category-level access role with per-download 'inherit'
 * semantics added in 0.10.0.
 *
 * Resolution order under test:
 *   1. Literal per-download role (public/subscriber/.../administrator)
 *   2. Most-restrictive category role when per-download = 'inherit' or empty
 *   3. Global isoft_fmf_default_access_role
 */

class CategoryAclTest extends WP_UnitTestCase {

	private ISOFT_FMF_Access_Control $access;

	public function set_up(): void {
		parent::set_up();
		$this->access = new ISOFT_FMF_Access_Control();
		update_option( 'isoft_fmf_default_access_role', 'public' );
	}

	private function make_download( ?string $role, array $cat_term_ids = array() ): int {
		$id = (int) isoft_fmf_create_draft_download( array( 'title' => 'D' . wp_generate_uuid4() ) );
		if ( null !== $role ) {
			update_post_meta( $id, '_isoft_fmf_access_role', $role );
		}
		if ( ! empty( $cat_term_ids ) ) {
			wp_set_object_terms( $id, $cat_term_ids, 'isoft_fmf_category' );
		}
		// Trigger the hook so the effective-role meta is populated.
		$this->access->recompute_effective_role( $id );
		return $id;
	}

	private function make_category( string $name, ?string $role = null ): int {
		$term = wp_insert_term( $name, 'isoft_fmf_category' );
		$id   = (int) $term['term_id'];
		if ( null !== $role ) {
			update_term_meta( $id, '_isoft_fmf_cat_access_role', $role );
		}
		return $id;
	}

	// ---------------------------------------------------------------------
	// Resolution
	// ---------------------------------------------------------------------

	public function test_literal_download_role_wins_over_category_role(): void {
		$cat = $this->make_category( 'Confidential', 'editor' );
		$id  = $this->make_download( 'public', array( $cat ) );

		$this->assertSame( 'public', $this->access->effective_role_for( $id ) );
	}

	public function test_inherit_falls_through_to_category_role(): void {
		$cat = $this->make_category( 'Subscribers', 'subscriber' );
		$id  = $this->make_download( 'inherit', array( $cat ) );

		$this->assertSame( 'subscriber', $this->access->effective_role_for( $id ) );
	}

	public function test_empty_download_role_is_treated_as_inherit(): void {
		$cat = $this->make_category( 'Editors', 'editor' );
		$id  = $this->make_download( null, array( $cat ) );

		$this->assertSame( 'editor', $this->access->effective_role_for( $id ) );
	}

	public function test_inherit_with_no_category_falls_through_to_global_default(): void {
		update_option( 'isoft_fmf_default_access_role', 'subscriber' );
		$id = $this->make_download( 'inherit' );

		$this->assertSame( 'subscriber', $this->access->effective_role_for( $id ) );
	}

	public function test_inherit_with_multiple_categories_picks_most_restrictive(): void {
		$cat_open    = $this->make_category( 'Open',    'public' );
		$cat_editors = $this->make_category( 'Editors', 'editor' );
		$cat_subs    = $this->make_category( 'Subs',    'subscriber' );
		$id          = $this->make_download( 'inherit', array( $cat_open, $cat_editors, $cat_subs ) );

		// Editor is highest in the hierarchy → most restrictive wins.
		$this->assertSame( 'editor', $this->access->effective_role_for( $id ) );
	}

	public function test_inherit_skips_categories_with_empty_role(): void {
		$cat_unset = $this->make_category( 'Unset', null );        // no cat role
		$cat_sub   = $this->make_category( 'Subs',  'subscriber' );
		$id        = $this->make_download( 'inherit', array( $cat_unset, $cat_sub ) );

		// Unset categories don't pull subscriber down to nothing.
		$this->assertSame( 'subscriber', $this->access->effective_role_for( $id ) );
	}

	// ---------------------------------------------------------------------
	// Denormalisation hooks
	// ---------------------------------------------------------------------

	public function test_changing_category_role_fans_out_to_downloads(): void {
		$cat = $this->make_category( 'Initial', 'subscriber' );
		$id  = $this->make_download( 'inherit', array( $cat ) );
		$this->assertSame( 'subscriber', $this->access->effective_role_for( $id ) );

		// Tighten the category — every download inheriting from it should
		// move with it. Calling the access-control hook directly avoids
		// firing the unrelated Category_Folders::on_edited callback that
		// shares the same WP action and expects two arguments.
		update_term_meta( $cat, '_isoft_fmf_cat_access_role', 'editor' );
		$this->access->on_category_edited( $cat );

		$this->assertSame( 'editor', $this->access->effective_role_for( $id ) );
	}

	public function test_changing_categories_on_a_download_recomputes_effective_role(): void {
		$cat_loose = $this->make_category( 'Loose',  'subscriber' );
		$cat_tight = $this->make_category( 'Tight',  'editor' );

		$id = $this->make_download( 'inherit', array( $cat_loose ) );
		$this->assertSame( 'subscriber', $this->access->effective_role_for( $id ) );

		// Move the download to a stricter category — the set_object_terms hook
		// re-runs the resolver.
		wp_set_object_terms( $id, array( $cat_tight ), 'isoft_fmf_category' );

		$this->assertSame( 'editor', $this->access->effective_role_for( $id ) );
	}

	// ---------------------------------------------------------------------
	// can_access_download() user-facing semantics
	// ---------------------------------------------------------------------

	public function test_anonymous_user_cannot_access_inherited_subscriber_download(): void {
		$cat = $this->make_category( 'Subs', 'subscriber' );
		$id  = $this->make_download( 'inherit', array( $cat ) );

		wp_set_current_user( 0 );
		$this->assertFalse( $this->access->can_access_download( $id ) );
	}

	public function test_subscriber_can_access_inherited_subscriber_download(): void {
		$cat  = $this->make_category( 'Subs', 'subscriber' );
		$id   = $this->make_download( 'inherit', array( $cat ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $user );
		$this->assertTrue( $this->access->can_access_download( $id ) );
	}

	public function test_subscriber_cannot_access_inherited_editor_download(): void {
		$cat  = $this->make_category( 'Editors', 'editor' );
		$id   = $this->make_download( 'inherit', array( $cat ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $user );
		$this->assertFalse( $this->access->can_access_download( $id ) );
	}
}
