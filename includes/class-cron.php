<?php
/**
 * Scheduled tasks for I-Soft File Manager: Foundation.
 *
 * Jobs:
 *   isoft_fmf_daily_cron — runs at 01:00 site time:
 *     1. Recalculates HOT flag with 7d -> 30d -> all-time fallback so new or
 *        low-traffic sites still surface meaningful top downloads.
 *     2. Purges daily rows older than 32 days (keeps the 30-day stats
 *        dashboard window honest, plus a 2-day buffer).
 *     3. Purges log entries beyond the configured retention period.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Cron {

	private const HOOK = 'isoft_fmf_daily_cron';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'isoft_fmf_deactivate', array( $this, 'unschedule' ) );
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	public function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		// First run: next 01:00 in site timezone.
		$timezone  = wp_timezone();
		$next_1am  = new DateTimeImmutable( 'tomorrow 01:00:00', $timezone );
		$timestamp = $next_1am->getTimestamp();

		wp_schedule_event( $timestamp, 'daily', self::HOOK );
	}

	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Main job
	// -------------------------------------------------------------------------

	public function run(): void {
		$this->recalculate_hot();
		$this->purge_daily_old();
		( new ISOFT_FMF_Download_Logger() )->purge_old_logs();

		do_action( 'isoft_fmf_daily_cron_complete' );
	}

	// -------------------------------------------------------------------------
	// HOT recalculation
	// -------------------------------------------------------------------------

	/**
	 * Mark the top 10 downloads as HOT and clear the flag on everyone else.
	 *
	 * Tries a 7-day window first (recency wins on busy sites). If that yields
	 * nothing, widens to 30 days. If even 30 days has no activity (new or
	 * very low-traffic install), falls back to the all-time per-file counter
	 * so the HOT feature still surfaces something meaningful instead of going
	 * silently dark. The window used is recorded so the dashboard can label
	 * the HOT panel honestly.
	 */
	private function recalculate_hot(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily cron on custom daily-counts table; cache layer would never be hit.

		$window      = '7d';
		$hot_results = $this->top_downloads_in_last_days( 7 );

		if ( empty( $hot_results ) ) {
			$window      = '30d';
			$hot_results = $this->top_downloads_in_last_days( 30 );
		}

		if ( empty( $hot_results ) ) {
			$window      = 'alltime';
			$hot_results = $this->top_downloads_alltime();
		}

		$hot_ids = array_map(
			static fn( $row ) => (int) $row->download_id,
			$hot_results
		);

		// Clear HOT flag on all isoft_fmf_file posts.
		$wpdb->query(
			"DELETE FROM {$wpdb->postmeta}
			  WHERE meta_key = '_isoft_fmf_is_hot'"
		);

		// Set HOT flag on the winners.
		foreach ( $hot_ids as $post_id ) {
			update_post_meta( $post_id, '_isoft_fmf_is_hot', 1 );
		}

		update_option( 'isoft_fmf_hot_downloads', $hot_results, false );
		update_option( 'isoft_fmf_hot_window', $window, false );
		update_option( 'isoft_fmf_hot_calculated_at', current_time( 'mysql' ), false );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		do_action( 'isoft_fmf_hot_recalculated', $hot_ids, $window );
	}

	/**
	 * Top 10 download IDs + summed count over the trailing N days from the
	 * daily aggregate table. Returns an array of stdClass with
	 * download_id + weekly_count properties (column kept for backward
	 * compatibility with the existing isoft_fmf_hot_downloads consumers).
	 *
	 * @param int $days Window size in days.
	 * @return array<object>
	 */
	private function top_downloads_in_last_days( int $days ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal cron helper; see caller comment.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT download_id, SUM(count) AS weekly_count
				   FROM {$wpdb->prefix}isoft_fmf_download_daily
				  WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  GROUP BY download_id
				  ORDER BY weekly_count DESC
				  LIMIT 10",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows ?: array();
	}

	/**
	 * All-time top 10 downloads from the per-file aggregate counter table.
	 * Last-resort fallback when neither the 7-day nor the 30-day window has
	 * any rows in the daily aggregate.
	 *
	 * @return array<object>
	 */
	private function top_downloads_alltime(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal cron helper; see caller comment.
		$rows = $wpdb->get_results(
			"SELECT f.download_id, SUM(f.download_count) AS weekly_count
			   FROM {$wpdb->prefix}isoft_fmf_files f
			   JOIN {$wpdb->posts} p ON p.ID = f.download_id
			  WHERE p.post_type = 'isoft_fmf_file' AND p.post_status = 'publish'
			  GROUP BY f.download_id
			 HAVING weekly_count > 0
			  ORDER BY weekly_count DESC
			  LIMIT 10"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows ?: array();
	}

	// -------------------------------------------------------------------------
	// Daily table cleanup
	// -------------------------------------------------------------------------

	private function purge_daily_old(): void {
		global $wpdb;

		// Keep 32 days so the 30-day stats window has full data plus a 2-day
		// buffer (covers a missed cron run + the HOT fallback's 30-day tier).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily cron cleanup on custom daily-counts table.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}isoft_fmf_download_daily
				  WHERE log_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				32
			)
		);
	}
}
