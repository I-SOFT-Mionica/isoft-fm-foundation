<?php
/**
 * ISOFT_FMF_Settings_Service unit tests.
 *
 * Covers schema integrity (no duplicate keys across groups, sanitizer
 * presence), CRUD semantics, the partial-update rejected-keys contract,
 * and the two custom sanitizers (time / link target).
 */

class SettingsServiceTest extends WP_UnitTestCase {

	private ISOFT_FMF_Settings_Service $service;

	public function set_up(): void {
		parent::set_up();
		foreach ( ISOFT_FMF_Settings_Service::known_keys() as $key ) {
			delete_option( $key );
		}
		$this->service = new ISOFT_FMF_Settings_Service();
	}

	public function test_known_keys_has_no_duplicates(): void {
		$keys = ISOFT_FMF_Settings_Service::known_keys();
		$this->assertSame(
			count( $keys ),
			count( array_unique( $keys ) ),
			'Each option key must appear in exactly one group.'
		);
	}

	public function test_every_key_has_a_sanitizer(): void {
		foreach ( ISOFT_FMF_Settings_Service::known_keys() as $key ) {
			$this->assertNotNull(
				ISOFT_FMF_Settings_Service::sanitizer_for( $key ),
				"Missing sanitizer for option {$key}."
			);
		}
	}

	public function test_get_returns_null_when_unset(): void {
		$this->assertNull( $this->service->get( 'isoft_fmf_enable_counting' ) );
	}

	public function test_set_persists_with_sanitization(): void {
		$this->assertTrue( $this->service->set( 'isoft_fmf_log_retention_days', '90' ) );
		$this->assertSame( '90', (string) $this->service->get( 'isoft_fmf_log_retention_days' ) );
	}

	public function test_set_rejects_unknown_key(): void {
		$this->assertFalse( $this->service->set( 'isoft_fmf_totally_made_up', 'x' ) );
	}

	public function test_update_many_returns_rejected_keys(): void {
		$rejected = $this->service->update_many(
			array(
				'isoft_fmf_enable_logging' => 1,
				'isoft_fmf_not_a_setting'  => 'oops',
				'isoft_fmf_log_retention_days' => 30,
			)
		);
		$this->assertSame( array( 'isoft_fmf_not_a_setting' ), $rejected );

		// Known keys still got applied (rejections don't roll back the writes).
		$this->assertSame( '1', (string) $this->service->get( 'isoft_fmf_enable_logging' ) );
		$this->assertSame( '30', (string) $this->service->get( 'isoft_fmf_log_retention_days' ) );
	}

	public function test_get_all_returns_every_key(): void {
		$all = $this->service->get_all();
		$this->assertSame(
			ISOFT_FMF_Settings_Service::known_keys(),
			array_keys( $all )
		);
	}

	public function test_sanitize_time_accepts_valid_hhmm(): void {
		$this->assertSame( '14:30', ISOFT_FMF_Settings_Service::sanitize_time( '14:30' ) );
		$this->assertSame( '00:00', ISOFT_FMF_Settings_Service::sanitize_time( '0:00' ) );
	}

	public function test_sanitize_time_clamps_out_of_range(): void {
		$this->assertSame( '23:00', ISOFT_FMF_Settings_Service::sanitize_time( '25:00' ) );
		$this->assertSame( '02:59', ISOFT_FMF_Settings_Service::sanitize_time( '02:99' ) );
	}

	public function test_sanitize_time_falls_back_on_garbage(): void {
		$this->assertSame( '02:30', ISOFT_FMF_Settings_Service::sanitize_time( 'not a time' ) );
		$this->assertSame( '02:30', ISOFT_FMF_Settings_Service::sanitize_time( '' ) );
		$this->assertSame( '02:30', ISOFT_FMF_Settings_Service::sanitize_time( null ) );
	}

	public function test_sanitize_link_target_whitelist(): void {
		$this->assertSame( '_self', ISOFT_FMF_Settings_Service::sanitize_link_target( '_self' ) );
		$this->assertSame( '_blank', ISOFT_FMF_Settings_Service::sanitize_link_target( '_blank' ) );
		$this->assertSame( '_blank', ISOFT_FMF_Settings_Service::sanitize_link_target( 'javascript:alert(1)' ) );
		$this->assertSame( '_blank', ISOFT_FMF_Settings_Service::sanitize_link_target( '_top' ) );
	}

	public function test_set_runs_sanitizer_for_time(): void {
		// 99:99 matches the HH:MM regex (1-2 digits, then 2 digits), so it
		// clamps to 23:59 rather than falling back to 02:30. The fallback
		// only fires when the regex itself doesn't match.
		$this->service->set( 'isoft_fmf_integrity_check_time', '99:99' );
		$this->assertSame( '23:59', $this->service->get( 'isoft_fmf_integrity_check_time' ) );
	}

	public function test_set_falls_back_when_time_regex_fails(): void {
		$this->service->set( 'isoft_fmf_integrity_check_time', 'not a time' );
		$this->assertSame( '02:30', $this->service->get( 'isoft_fmf_integrity_check_time' ) );
	}

	public function test_set_runs_sanitizer_for_absint(): void {
		$this->service->set( 'isoft_fmf_log_retention_days', '-5' );
		$this->assertSame( '5', (string) $this->service->get( 'isoft_fmf_log_retention_days' ) );
	}
}
