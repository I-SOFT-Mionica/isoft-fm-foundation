<?php
/**
 * CSV / JSON export for the download log — Phase 4.
 *
 * Hooks:
 *   admin_post_isoft_fmf_export_csv   → streams a CSV file
 *   admin_post_isoft_fmf_export_json  → streams a JSON file
 *   admin_post_isoft_fmf_purge_logs   → deletes old log entries, redirects back
 *
 * All actions require the isoft_fmf_export_logs capability (Admins only).
 * Purge requires isoft_fmf_manage_settings.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Export {

	public function register_hooks(): void {
		add_action( 'admin_post_isoft_fmf_export_csv', array( $this, 'export_csv' ) );
		add_action( 'admin_post_isoft_fmf_export_json', array( $this, 'export_json' ) );
		add_action( 'admin_post_isoft_fmf_purge_logs', array( $this, 'purge_logs' ) );

		// Handle inline export links from the log viewer (not admin-post, GET-based with nonce).
		add_action( 'admin_init', array( $this, 'handle_inline_export' ) );
	}

	// -------------------------------------------------------------------------
	// Inline export (GET links in log-viewer.php)
	// -------------------------------------------------------------------------

	public function handle_inline_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Capability-gated GET action; nonce verified below for destructive ops.
		if ( empty( $_GET['isoft_fmf_action'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'isoft_fmf_export_logs' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( $_GET['isoft_fmf_action'] );
		if ( $action === 'export_csv' ) {
			$this->stream_csv( $this->fetch_rows() );
		} elseif ( $action === 'export_json' ) {
			$this->stream_json( $this->fetch_rows() );
		}
	}

	// -------------------------------------------------------------------------
	// admin-post handlers (POST-based, with nonce)
	// -------------------------------------------------------------------------

	public function export_csv(): void {
		check_admin_referer( 'isoft_fmf_export' );
		if ( ! current_user_can( 'isoft_fmf_export_logs' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'isoft-fm-foundation' ) );
		}
		$this->stream_csv( $this->fetch_rows() );
	}

	public function export_json(): void {
		check_admin_referer( 'isoft_fmf_export' );
		if ( ! current_user_can( 'isoft_fmf_export_logs' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'isoft-fm-foundation' ) );
		}
		$this->stream_json( $this->fetch_rows() );
	}

	public function purge_logs(): void {
		check_admin_referer( 'isoft_fmf_purge_logs' );
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'isoft-fm-foundation' ) );
		}

		$deleted = ( new ISOFT_FMF_Download_Logger() )->purge_old_logs();

		$redirect = add_query_arg(
			array(
				'post_type' => 'isoft_fmf_file',
				'page'      => 'isoft-fmf-log',
				'purged'    => $deleted,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// -------------------------------------------------------------------------
	// Query
	// -------------------------------------------------------------------------

	/**
	 * Fetch log rows with optional filters from the current request.
	 *
	 * @return array<object>
	 */
	private function fetch_rows(): array {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters are read-only; capability already checked.
		$search          = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$filter_download = isset( $_REQUEST['filter_download'] ) ? absint( $_REQUEST['filter_download'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$like = $search !== '' ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		// Two-branch literal-prepare: each branch passes a single string literal as the
		// first arg of prepare(), so sniffs can verify the SQL without tracing variables.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export endpoint on custom log table; result streamed, not reused.
		if ( $search !== '' && $filter_download > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login,
					        l.ip_address, l.user_agent, l.referer, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR l.user_login LIKE %s OR l.ip_address LIKE %s )
					    AND l.download_id = %d
					  ORDER BY l.downloaded_at DESC
					  LIMIT 50000",
					$like,
					$like,
					$like,
					$filter_download
				)
			) ?? array();
		}
		if ( $search !== '' ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login,
					        l.ip_address, l.user_agent, l.referer, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR l.user_login LIKE %s OR l.ip_address LIKE %s )
					  ORDER BY l.downloaded_at DESC
					  LIMIT 50000",
					$like,
					$like,
					$like
				)
			) ?? array();
		}
		if ( $filter_download > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login,
					        l.ip_address, l.user_agent, l.referer, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE l.download_id = %d
					  ORDER BY l.downloaded_at DESC
					  LIMIT 50000",
					$filter_download
				)
			) ?? array();
		}
		return $wpdb->get_results(
			"SELECT l.id, l.download_id, p.post_title AS download_title,
			        l.file_id, f.file_name, l.user_id, l.user_login,
			        l.ip_address, l.user_agent, l.referer, l.downloaded_at
			   FROM {$wpdb->prefix}isoft_fmf_download_log l
			   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
			   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
			  ORDER BY l.downloaded_at DESC
			  LIMIT 50000"
		) ?? array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// -------------------------------------------------------------------------
	// Streamers
	// -------------------------------------------------------------------------

	/**
	 * @param array<object> $rows
	 */
	private function stream_csv( array $rows ): void {
		$filename = 'isoft-fmf-log-' . gmdate( 'Y-m-d' ) . '.csv';

		// Disable output buffering.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output stream, WP_Filesystem not applicable.
		$out = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel opens it correctly.
		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Header row.
		fputcsv(
			$out,
			array(
				'ID',
				'Download ID',
				'Download Title',
				'File ID',
				'File Name',
				'User ID',
				'User Login',
				'IP Address',
				'User Agent',
				'Referer',
				'Downloaded At',
			)
		);

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row->id,
					$row->download_id,
					$row->download_title ?? '',
					$row->file_id,
					$row->file_name ?? '',
					$row->user_id ?? '',
					$row->user_login ?? '',
					$row->ip_address ?? '',
					$row->user_agent ?? '',
					$row->referer ?? '',
					$row->downloaded_at,
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream, WP_Filesystem not applicable.
		fclose( $out );
		exit;
	}

	/**
	 * @param array<object> $rows
	 */
	private function stream_json( array $rows ): void {
		$filename = 'isoft-fmf-log-' . gmdate( 'Y-m-d' ) . '.json';

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Cast all rows to arrays for cleaner JSON.
		$data = array_map( fn( object $row ) => (array) $row, $rows );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
