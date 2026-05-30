<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Deactivator {

	public static function deactivate(): void {
		// Unschedule cron jobs.
		do_action( 'isfm_deactivate' );

		// Clear all plugin transients.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deactivator: one-shot transient cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_isfm_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_isfm_' ) . '%'
			)
		);
		flush_rewrite_rules();
	}
}
