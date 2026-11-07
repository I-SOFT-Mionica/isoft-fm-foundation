<?php
/**
 * IN-1: queued notices keep their links; IN-2: the nightly integrity check
 * is unscheduled on deactivation.
 *
 * @package isoft-fm-foundation
 */

class NoticeAndCronCleanupTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'isoft_fmf_admin_notices' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( 'isoft_fmf_admin_notices' );
		parent::tear_down();
	}

	private function rendered_notices(): string {
		ob_start();
		( new ISOFT_FMF_Extension_Api() )->render_admin_notices();
		return (string) ob_get_clean();
	}

	public function test_notice_links_render_as_links_and_nothing_else_does(): void {
		isoft_fmf_notify_admin( 'Missing. <a href="https://example.test/broken-links">Review</a>. <script>alert(1)</script><b onclick="x()">bold</b>', 'warning' );

		$html = $this->rendered_notices();

		$this->assertStringContainsString( '<a href="https://example.test/broken-links">Review</a>', $html );
		$this->assertStringNotContainsString( '&lt;a href', $html, 'no raw HTML shown as text' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( '<b', $html );
	}

	public function test_deactivation_unschedules_the_nightly_check(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'isoft_fmf_integrity_check' );
		$this->assertNotFalse( wp_next_scheduled( 'isoft_fmf_integrity_check' ) );

		( new ISOFT_FMF_File_Integrity() )->register_hooks();
		do_action( 'isoft_fmf_deactivate' );

		$this->assertFalse( wp_next_scheduled( 'isoft_fmf_integrity_check' ) );
	}
}
