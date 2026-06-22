<?php
/**
 * Maintenance service — install/remove demo content, clear bundle cache,
 * trigger integrity scans, purge logs, render exports. Composes the
 * existing per-feature classes (Demo_Content, Bundle_Handler,
 * File_Integrity, Download_Logger) into a single surface the REST
 * controller and the legacy admin-post handlers both call into.
 *
 * Stream-friendly export methods return strings; the AJAX/REST adapter
 * is responsible for setting headers and emitting bytes.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Maintenance_Service {

	// ---------------------------------------------------------------------
	// Demo content
	// ---------------------------------------------------------------------

	/**
	 * Install demo categories + downloads + landing page. Refuses if any
	 * downloads already exist (legacy or otherwise) so admins can't
	 * accidentally double-seed a live install.
	 *
	 * @return array{installed: bool, reason?: string}
	 */
	public function install_demo(): array {
		if ( ISOFT_FMF_Demo_Content::has_content() ) {
			return array(
				'installed' => false,
				'reason'    => __( 'Demo content cannot be installed — downloads already exist.', 'isoft-fm-foundation' ),
			);
		}
		( new ISOFT_FMF_Demo_Content() )->install_cli();
		return array( 'installed' => true );
	}

	public function remove_demo(): array {
		( new ISOFT_FMF_Demo_Content() )->remove_silent();
		return array( 'removed' => true );
	}

	// ---------------------------------------------------------------------
	// Bundle cache
	// ---------------------------------------------------------------------

	/**
	 * Wipe everything under wp-content/uploads/isoft-fmf-files/.bundle-cache.
	 * Returns the count of files actually deleted.
	 */
	public function clear_bundle_cache(): array {
		$dir     = isoft_fmf_files_dir() . '/.bundle-cache';
		$deleted = 0;
		if ( is_dir( $dir ) ) {
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Tolerate permission glitches; deleted count is the truth.
			if ( $entries ) {
				foreach ( $entries as $entry ) {
					if ( '.' === $entry || '..' === $entry ) {
						continue;
					}
					$path = $dir . '/' . $entry;
					wp_delete_file( $path );
					if ( ! file_exists( $path ) ) {
						++$deleted;
					}
				}
			}
		}
		return array( 'deleted' => $deleted );
	}

	// ---------------------------------------------------------------------
	// Integrity scan
	// ---------------------------------------------------------------------

	/**
	 * Trigger the scheduled integrity scan immediately. If a scan is
	 * already running, returns skipped=true; the caller can surface that
	 * to the admin instead of pretending it ran.
	 *
	 * @return array{skipped: bool, ...}
	 */
	public function run_integrity_scan(): array {
		$result = ( new ISOFT_FMF_File_Integrity() )->run_scheduled_check();
		if ( ! is_array( $result ) ) {
			$result = array();
		}
		$result['skipped'] = ! empty( $result['skipped'] );
		return $result;
	}

	// ---------------------------------------------------------------------
	// Log purge
	// ---------------------------------------------------------------------

	public function purge_logs(): array {
		$deleted = (int) ( new ISOFT_FMF_Download_Logger() )->purge_old_logs();
		return array( 'deleted' => $deleted );
	}

	// ---------------------------------------------------------------------
	// Exports
	// ---------------------------------------------------------------------

	/**
	 * Render the download log as CSV. Returns the file body as a string —
	 * caller sets Content-Type / Content-Disposition headers and emits.
	 */
	public function export_csv(): string {
		$rows = $this->fetch_log_rows();
		$out  = fopen( 'php://temp', 'w+' );
		fputcsv(
			$out,
			array( 'download_id', 'file_id', 'license_id_at_download', 'downloaded_at', 'ip_address', 'user_id', 'user_login', 'user_agent' )
		);
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row->download_id ?? '',
					$row->file_id ?? '',
					$row->license_id_at_download ?? '',
					$row->downloaded_at ?? '',
					$row->ip_address ?? '',
					$row->user_id ?? '',
					$row->user_login ?? '',
					$row->user_agent ?? '',
				)
			);
		}
		rewind( $out );
		$body = (string) stream_get_contents( $out );
		fclose( $out );
		return $body;
	}

	public function export_json(): string {
		$payload = array(
			'exported_at' => current_time( 'mysql' ),
			'rows'        => $this->fetch_log_rows(),
		);
		return (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Suggested filename for a streamed export. Used by both AJAX and REST.
	 */
	public function export_filename( string $format ): string {
		$ext = 'json' === $format ? 'json' : 'csv';
		return 'isoft-fmf-log-' . gmdate( 'Y-m-d-His' ) . '.' . $ext;
	}

	/**
	 * @return array<object>
	 */
	private function fetch_log_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export reads the full log table; not cacheable.
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}isoft_fmf_download_log ORDER BY downloaded_at DESC"
		) ?: array();
	}
}
