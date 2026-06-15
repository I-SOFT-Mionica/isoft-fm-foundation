<?php
defined( 'ABSPATH' ) || exit;

/**
 * Serves all files attached to a download as a single ZIP archive.
 *
 * Off by default; toggled via `isoft_fmf_enable_zip_bundle` in Settings →
 * Display. Requires the PHP `zip` extension at runtime; the front-end
 * button is hidden when ZipArchive is unavailable, and direct hits to
 * the endpoint return a 500 with an explanatory error.
 */
class ISOFT_FMF_Bundle_Handler {

	public function register_hooks(): void {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'isoft_fmf_bundle';
		return $vars;
	}

	public function handle(): void {
		$download_id = absint( get_query_var( 'isoft_fmf_bundle' ) );
		if ( ! $download_id ) {
			return;
		}

		if ( ! get_option( 'isoft_fmf_enable_zip_bundle', 0 ) ) {
			wp_die( esc_html__( 'ZIP bundle downloads are not enabled.', 'isoft-fm-foundation' ), 404 );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'ZIP bundle support requires the PHP zip extension, which is not installed on this server.', 'isoft-fm-foundation' ), 500 );
		}

		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, "isoft_fmf_bundle_{$download_id}" ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh the page and try again.', 'isoft-fm-foundation' ), 403 );
		}

		if ( 'publish' !== get_post_status( $download_id ) ) {
			wp_die( esc_html__( 'This download is not currently available.', 'isoft-fm-foundation' ), 404 );
		}

		if ( post_password_required( $download_id ) ) {
			wp_die( esc_html__( 'This download is password-protected. Please visit the download page and enter the password first.', 'isoft-fm-foundation' ), 403 );
		}

		$access = new ISOFT_FMF_Access_Control();
		if ( ! $access->can_access_download( $download_id ) ) {
			do_action( 'isoft_fmf_access_denied', $download_id, get_current_user_id(), get_post_meta( $download_id, '_isoft_fmf_access_role', true ) );
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( get_permalink( $download_id ) ) );
				exit;
			}
			wp_die( esc_html__( 'You do not have permission to download these files.', 'isoft-fm-foundation' ), 403 );
		}

		if ( get_option( 'isoft_fmf_hotlink_protection', 0 ) ) {
			$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
			if ( $referer && wp_parse_url( $referer, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				wp_die( esc_html__( 'Direct linking to downloads from external sites is not allowed.', 'isoft-fm-foundation' ), 403 );
			}
		}

		// One bundle = one rate-limit hit, regardless of how many files it contains.
		$rate_limit = (int) get_option( 'isoft_fmf_rate_limit_per_hour', 0 );
		if ( $rate_limit > 0 ) {
			$ip_hash = 'isoft_fmf_rl_' . md5( isoft_fmf_client_ip() ?? 'unknown' );
			$hits    = (int) get_transient( $ip_hash );
			if ( $hits >= $rate_limit ) {
				do_action( 'isoft_fmf_rate_limit_exceeded', isoft_fmf_client_ip(), $rate_limit );
				wp_die( esc_html__( 'Download limit exceeded. Please try again later.', 'isoft-fm-foundation' ), 429 );
			}
			set_transient( $ip_hash, $hits + 1, HOUR_IN_SECONDS );
		}

		$files       = ( new ISOFT_FMF_File_Manager() )->get_files( $download_id );
		$local_files = array_filter( $files, fn( $f ): bool => 'external' !== $f->file_type );
		if ( count( $local_files ) < 1 ) {
			wp_die( esc_html__( 'This download has no files that can be bundled into a ZIP.', 'isoft-fm-foundation' ), 404 );
		}

		do_action( 'isoft_fmf_before_bundle_download', $download_id, get_current_user_id() );
		$this->stream_bundle( $download_id, $local_files );
	}

	/**
	 * @param object[] $files Local-only file rows from ISOFT_FMF_File_Manager.
	 */
	private function stream_bundle( int $download_id, array $files ): void {
		$tmp = wp_tempnam( 'isoft_fmf_bundle_' );
		if ( ! $tmp ) {
			wp_die( esc_html__( 'Could not create a temporary file for the ZIP bundle.', 'isoft-fm-foundation' ), 500 );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Could not open the ZIP archive for writing.', 'isoft-fm-foundation' ), 500 );
		}

		$base       = realpath( isoft_fmf_files_dir() );
		$added      = 0;
		$seen_names = array();

		foreach ( $files as $file ) {
			if ( empty( $file->file_path ) ) {
				continue;
			}
			$abs = realpath( "{$base}/{$file->file_path}" );
			if ( ! $abs || ! str_starts_with( $abs, $base ) || ! is_readable( $abs ) ) {
				continue;
			}

			// Collision avoidance — if two file rows share a basename, prefix the second with the file ID.
			$name = $file->file_name ?: basename( $abs );
			if ( isset( $seen_names[ $name ] ) ) {
				$name = $file->id . '-' . $name;
			}
			$seen_names[ $name ] = true;

			if ( $zip->addFile( $abs, $name ) ) {
				++$added;
			}
		}

		$zip->close();

		if ( ! $added ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'No files were available to bundle.', 'isoft-fm-foundation' ), 404 );
		}

		// Single audit-log entry for the whole bundle. file_id = 0 sentinel.
		$log_id = ( new ISOFT_FMF_Download_Logger() )->log( $download_id, 0 );
		do_action( 'isoft_fmf_after_bundle_download', $log_id, $download_id, $added );

		$slug      = get_post_field( 'post_name', $download_id ) ?: "download-{$download_id}";
		$file_name = "{$slug}.zip";
		$headers   = apply_filters(
			'isoft_fmf_bundle_headers',
			array(
				'Content-Type'           => 'application/zip',
				'Content-Disposition'    => "attachment; filename=\"{$file_name}\"",
				'Content-Length'         => (string) filesize( $tmp ),
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control'          => 'no-store, no-cache, must-revalidate',
				'Pragma'                 => 'no-cache',
			),
			$download_id,
			$added
		);

		if ( ob_get_level() ) {
			ob_end_clean();
		}
		foreach ( $headers as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Binary ZIP payload; cannot route through WP_Filesystem (returns strings) or HTML-escape.
		readfile( $tmp );
		wp_delete_file( $tmp );
		exit;
	}
}
