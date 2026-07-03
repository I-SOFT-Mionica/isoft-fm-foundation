<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Download_Logger {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'isoft_fmf_download_log';
	}

	/**
	 * Write a download log entry.
	 *
	 * @return int|null  Inserted log ID, or null if logging is disabled or insert failed.
	 */
	public function log( int $download_id, int $file_id ): ?int {
		global $wpdb;

		$settings = isoft_fmf_get_settings();
		if ( ! $settings['enable_logging'] ) {
			return null;
		}

		$user = wp_get_current_user();

		$now   = current_time( 'mysql' );
		$today = current_time( 'Y-m-d' );

		// Stamp the resolved license id at the moment of download. This is the
		// load-bearing legal trail — a license change later doesn't strip
		// what governed THIS specific download. Resolver returns 0 when there
		// is no effective license (download has none and category has none).
		$license_id_at_download = ( new ISOFT_FMF_License_Resolver() )->effective_license_for( $download_id );

		$data   = array(
			'download_id'            => $download_id,
			'file_id'                => $file_id,
			'user_id'                => $user->ID ?: null,
			'user_login'             => $user->ID ? $user->user_login : null,
			'license_id_at_download' => $license_id_at_download > 0 ? $license_id_at_download : null,
			'downloaded_at'          => $now,
			'log_date'               => $today,
		);
		$format = array( '%d', '%d', '%d', '%s', '%d', '%s', '%s' );

		// PII fields — only when detailed logging is explicitly enabled
		if ( $settings['enable_detailed_logging'] ) {
			$data['ip_address'] = $this->client_ip();
			$data['user_agent'] = isset( $_SERVER['HTTP_USER_AGENT'] )
				? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
				: null;
			$data['referer']    = isset( $_SERVER['HTTP_REFERER'] )
				? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
				: null;
			array_push( $format, '%s', '%s', '%s' );
		}

		$data = apply_filters( 'isoft_fmf_log_entry_data', $data );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write-path logger on custom log tables; never cached.
		if ( false === $wpdb->insert( $this->table, $data, $format ) ) {
			return null;
		}

		$log_id = (int) $wpdb->insert_id;

		// Increment daily bucket — used by HOT cron, avoids full log scans.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}isoft_fmf_download_daily (download_id, log_date, count)
				 VALUES (%d, %s, 1)
				 ON DUPLICATE KEY UPDATE count = count + 1",
				$download_id,
				$today
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Bust the dashboard's stats-overview transient so the next admin
		// view reflects this click. Without this the dashboard reads up to
		// 5 minutes stale even though log + daily tables were just written.
		delete_transient( 'isoft_fmf_stats_overview' );

		/**
		 * Extension hook: fires after a download has been logged. Notary
		 * uses this to write the matching download-receipt row (see
		 * [[notary-addon]] memory). Foundation core does nothing with this.
		 *
		 * @param int      $log_id                 Inserted log row id.
		 * @param int      $download_id            Download post id.
		 * @param int      $file_id                File id within the download.
		 * @param int      $user_id                Acting user id (0 for guest).
		 * @param int|null $license_id_at_download License id resolved at log time, null if no effective license.
		 */
		do_action( 'isoft_fmf_download_logged', $log_id, $download_id, $file_id, (int) $user->ID, $license_id_at_download > 0 ? $license_id_at_download : null );

		return $log_id;
	}

	/**
	 * Delete log entries older than the configured retention period.
	 *
	 * Runs in batches of {@see BATCH_SIZE} rows so a large purge (10k+
	 * rows on a busy site) doesn't hold a single long transaction that
	 * blocks concurrent writes. The idx_downloaded_at index makes each
	 * batch's WHERE-scan cheap.
	 *
	 * A per-call ceiling caps the loop so a manual "Purge old log
	 * entries" click on the admin page can't stall the request through
	 * the PHP max_execution_time — the cron then finishes any remainder
	 * on its next daily fire.
	 *
	 * @param int $max_batches Ceiling on batches per call. 0 = no cap.
	 *                         Cron passes 0; admin-post handler passes
	 *                         a small value (see Export::purge_logs).
	 * @return int             Total rows deleted across all batches.
	 */
	public function purge_old_logs( int $max_batches = 0 ): int {
		global $wpdb;

		$days = (int) isoft_fmf_get_settings()['log_retention_days'];
		if ( 0 === $days ) {
			return 0;
		}

		$total    = 0;
		$batches  = 0;
		$per_call = self::BATCH_SIZE;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup on custom log table; batched to avoid long-running transaction.
			$affected = (int) $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE downloaded_at < DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT %d',
					$this->table,
					$days,
					$per_call
				)
			);
			$total   += $affected;
			++$batches;
			if ( $max_batches > 0 && $batches >= $max_batches ) {
				break;
			}
		} while ( $affected === $per_call );

		if ( $total > 0 ) {
			do_action( 'isoft_fmf_log_purged', $total );
		}

		return $total;
	}

	/**
	 * How many rows the retention purge deletes per DELETE call. Small
	 * enough to keep any single query under a few hundred ms on
	 * commodity MySQL, large enough that a full purge finishes in a
	 * reasonable number of iterations.
	 */
	private const BATCH_SIZE = 5000;

	private function client_ip(): ?string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			// X-Forwarded-For may be a comma-separated list; take the first.
			if ( str_contains( $ip, ',' ) ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return null;
	}
}
