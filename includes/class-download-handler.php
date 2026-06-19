<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Download_Handler {

	public function register_hooks(): void {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'isoft_fmf_download';
		return $vars;
	}

	public function handle(): void {
		$file_id = absint( get_query_var( 'isoft_fmf_download' ) );
		if ( ! $file_id ) {
			return;
		}

		// Nonce check
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, "isoft_fmf_download_{$file_id}" ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh the page and try again.', 'isoft-fm-foundation' ), 403 );
		}

		$file_manager = new ISOFT_FMF_File_Manager();
		$file         = $file_manager->get_file( $file_id );

		if ( ! $file ) {
			wp_die( esc_html__( 'File not found.', 'isoft-fm-foundation' ), 404 );
		}

		$download_id = (int) $file->download_id;

		if ( 'publish' !== get_post_status( $download_id ) ) {
			wp_die( esc_html__( 'This download is not currently available.', 'isoft-fm-foundation' ), 404 );
		}

		if ( post_password_required( $download_id ) ) {
			wp_die( esc_html__( 'This download is password-protected. Please visit the download page and enter the password first.', 'isoft-fm-foundation' ), 403 );
		}

		// Access check
		$access = new ISOFT_FMF_Access_Control();
		if ( ! $access->can_access_download( $download_id ) ) {
			do_action( 'isoft_fmf_access_denied', $download_id, get_current_user_id(), get_post_meta( $download_id, '_isoft_fmf_access_role', true ) );
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( get_permalink( $download_id ) ) );
				exit;
			}
			wp_die( esc_html__( 'You do not have permission to download this file.', 'isoft-fm-foundation' ), 403 );
		}

		// Hotlink protection — block requests whose referer points off-site.
		if ( get_option( 'isoft_fmf_hotlink_protection', 0 ) ) {
			$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
			if ( $referer && wp_parse_url( $referer, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				wp_die( esc_html__( 'Direct linking to downloads from external sites is not allowed.', 'isoft-fm-foundation' ), 403 );
			}
		}

		// User-agent blocklist (scrapers, known bad bots).
		if ( isoft_fmf_user_agent_blocked() ) {
			do_action( 'isoft_fmf_user_agent_blocked', isoft_fmf_client_ip(), $download_id );
			wp_die( esc_html__( 'Your client is not permitted to download files from this site.', 'isoft-fm-foundation' ), 403 );
		}

		// Rate limit — per-IP throttle using short-lived transients.
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

		do_action( 'isoft_fmf_before_download', $file_id, $download_id, get_current_user_id() );

		// External links: redirect off-site. wp_safe_redirect() would reject
		// any URL whose host isn't in WordPress's allowlist and silently fall
		// back to /wp-admin/ — which is the opposite of what we want here.
		if ( 'external' === $file->file_type ) {
			$target = esc_url_raw( $file->external_url );
			if ( ! $target ) {
				wp_die( esc_html__( 'This external link is invalid.', 'isoft-fm-foundation' ), 400 );
			}
			$log_id = ( new ISOFT_FMF_Download_Logger() )->log( $download_id, $file_id );
			do_action( 'isoft_fmf_after_download', $log_id );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External-link downloads point off-site; wp_safe_redirect() rejects them. $target is validated via esc_url_raw() above. See changelog 0.4.3.
			wp_redirect( $target );
			exit;
		}

		$this->serve_local_file( $file, $download_id, $file_manager );
	}

	private function serve_local_file( object $file, int $download_id, ISOFT_FMF_File_Manager $manager ): void {
		$file_path = $this->resolve_path( $file );
		if ( ! $file_path || ! is_readable( $file_path ) ) {
			$mode = ISOFT_FMF_File_Integrity::handle_missing( $file, $download_id );
			ISOFT_FMF_File_Integrity::render_unavailable_page( $download_id, $mode );
			// render_unavailable_page() exits.
		}

		$file_id = (int) $file->id;
		$log_id  = ( new ISOFT_FMF_Download_Logger() )->log( $download_id, $file_id );
		if ( isoft_fmf_get_settings()['enable_counting'] ) {
			$manager->increment_count( $file_id, $download_id );
		}
		do_action( 'isoft_fmf_after_download', $log_id );

		$mime      = $file->file_mime ?: 'application/octet-stream';
		$file_name = $file->file_name ?: basename( $file_path );

		$headers = apply_filters(
			'isoft_fmf_download_headers',
			array(
				'Content-Type'           => $mime,
				'Content-Disposition'    => "attachment; filename=\"{$file_name}\"",
				'Content-Length'         => (string) filesize( $file_path ),
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control'          => 'no-store, no-cache, must-revalidate',
				'Pragma'                 => 'no-cache',
			),
			$file
		);

		$method = $this->resolve_serve_method();

		// Tier 1a — Apache X-Sendfile. Reaches here only when admin explicitly
		// selected 'xsendfile'; auto-mode never picks this until the planned
		// serve-method probe lands (see project_serve_method_probe memory).
		if ( 'xsendfile' === $method ) {
			$this->send_headers( $headers );
			header( "X-Sendfile: {$file_path}" );
			exit;
		}

		// Tier 1b — Nginx X-Accel-Redirect. Same caveat: requires manual opt-in
		// because the `location /isoft-fmf-internal/` alias has to exist in
		// nginx config and we have no way to detect that yet.
		if ( 'xaccel' === $method ) {
			$this->send_headers( $headers );
			$basename = basename( $file_path );
			header( "X-Accel-Redirect: /isoft-fmf-internal/{$basename}" );
			exit;
		}

		// Tier 2 — PHP streaming (works everywhere).
		$this->php_stream( $file_path, $headers );
	}

	/**
	 * Resolve the actual serve method from the user's setting.
	 *
	 * Auto-mode used to dispatch X-Sendfile / X-Accel-Redirect based on
	 * SERVER_SOFTWARE alone — but the server string says nothing about whether
	 * mod_xsendfile is loaded or the /isoft-fmf-internal/ alias is configured.
	 * On the very first real install (kc.mionica.rs, 2026-06-19) every download
	 * silently failed because the host advertised nginx but had no alias set.
	 *
	 * Until a real capability probe ships, auto-mode resolves to 'php' — the
	 * one tier that works everywhere. Admins who actually have X-Sendfile or
	 * X-Accel set up can opt in via Settings → Security.
	 */
	private function resolve_serve_method(): string {
		$setting = get_option( 'isoft_fmf_serve_method', 'auto' );
		return 'auto' === $setting ? 'php' : $setting;
	}

	private function php_stream( string $path, array $headers ): void {
		// Drain every level of output buffering. Caching plugins, theme
		// buffers, and mu-plugins routinely stack multiple buffers; a single
		// ob_end_clean() leaves the rest in place and the response sits in
		// PHP memory until the script exits — which on a slow connection
		// looks like a dead download.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		// Disable PHP-level gzip. If zlib.output_compression is on, the wire
		// bytes won't match the Content-Length we promised, and the browser
		// hangs waiting for the missing tail.
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.IniSet.Risky -- Streaming raw binary download; gzip would break Content-Length, and the @ guards against hosts where ini_set is on a runtime deny-list.

		// Force identity transfer-encoding so server-level gzip (Apache
		// mod_deflate, nginx gzip) doesn't recompress and skew Content-Length.
		// Most servers respect Content-Encoding set by upstream.
		$headers['Content-Encoding'] = 'identity';

		$this->send_headers( $headers );

		// Chunked stream with explicit flush so bytes hit the client as they
		// come off disk instead of being held by FPM / nginx fastcgi buffer
		// until the script exits.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming binary download; WP_Filesystem returns strings, not chunks.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			// Fallback: try readfile if fopen failed for whatever reason.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Last-resort fallback when fopen() returned false.
			readfile( $path );
			exit;
		}
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming raw binary file content; WP_Filesystem returns strings (not chunks) and escaping would corrupt the download.
			flush();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the fopen handle from above; matched pair.
		exit;
	}

	private function send_headers( array $headers ): void {
		foreach ( $headers as $name => $value ) {
			header( "{$name}: {$value}" );
		}
	}

	private function resolve_path( object $file ): ?string {
		if ( empty( $file->file_path ) ) {
			return null;
		}

		$base = realpath( isoft_fmf_files_dir() );
		$path = realpath( "{$base}/{$file->file_path}" );

		// Path traversal guard: must stay within the isoft-fmf-files directory.
		if ( $path && $base && str_starts_with( $path, $base ) ) {
			return $path;
		}

		return null;
	}
}
