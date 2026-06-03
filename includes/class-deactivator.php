<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Deactivator {

	public static function deactivate(): void {
		// Unschedule cron jobs.
		do_action( 'isoft_fmf_deactivate' );

		// Clear all plugin transients.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deactivator: one-shot transient cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_isoft_fmf_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_isoft_fmf_' ) . '%'
			)
		);
		flush_rewrite_rules();
	}
}
