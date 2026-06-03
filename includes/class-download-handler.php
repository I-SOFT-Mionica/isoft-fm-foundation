<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Download_Handler {

	public function register_hooks(): void {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'isfm_download';
		return $vars;
	}

	public function handle(): void {
		$file_id = absint( get_query_var( 'isfm_download' ) );
		if ( ! $file_id ) {
			return;
		}

		// Nonce check
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, "isfm_download_{$file_id}" ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh the page and try again.', 'isoft-fm-foundation' ), 403 );
		}

		$file_manager = new ISFM_File_Manager();
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
		$access = new ISFM_Access_Control();
		if ( ! $access->can_access_download( $download_id ) ) {
			do_action( 'isfm_access_denied', $download_id, get_current_user_id(), get_post_meta( $download_id, '_isfm_access_role', true ) );
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( get_permalink( $download_id ) ) );
				exit;
			}
			wp_die( esc_html__( 'You do not have permission to download this file.', 'isoft-fm-foundation' ), 403 );
		}

		// Hotlink protection — block requests whose referer points off-site.
		if ( get_option( 'isfm_hotlink_protection', 0 ) ) {
			$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
			if ( $referer && wp_parse_url( $referer, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				wp_die( esc_html__( 'Direct linking to downloads from external sites is not allowed.', 'isoft-fm-foundation' ), 403 );
			}
		}

		// Rate limit — per-IP throttle using short-lived transients.
		$rate_limit = (int) get_option( 'isfm_rate_limit_per_hour', 0 );
		if ( $rate_limit > 0 ) {
			$ip_hash = 'isfm_rl_' . md5( $this->client_ip() ?? 'unknown' );
			$hits    = (int) get_transient( $ip_hash );
			if ( $hits >= $rate_limit ) {
				do_action( 'isfm_rate_limit_exceeded', $this->client_ip(), $rate_limit );
				wp_die( esc_html__( 'Download limit exceeded. Please try again later.', 'isoft-fm-foundation' ), 429 );
			}
			set_transient( $ip_hash, $hits + 1, HOUR_IN_SECONDS );
		}

		do_action( 'isfm_before_download', $file_id, $download_id, get_current_user_id() );

		// External links: redirect off-site. wp_safe_redirect() would reject
		// any URL whose host isn't in WordPress's allowlist and silently fall
		// back to /wp-admin/ — which is the opposite of what we want here.
		if ( 'external' === $file->file_type ) {
			$target = esc_url_raw( $file->external_url );
			if ( ! $target ) {
				wp_die( esc_html__( 'This external link is invalid.', 'isoft-fm-foundation' ), 400 );
			}
			$log_id = ( new ISFM_Download_Logger() )->log( $download_id, $file_id );
			do_action( 'isfm_after_download', $log_id );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External-link downloads point off-site; wp_safe_redirect() rejects them. $target is validated via esc_url_raw() above. See changelog 0.4.3.
			wp_redirect( $target );
			exit;
		}

		$this->serve_local_file( $file, $download_id, $file_manager );
	}

	private function serve_local_file( object $file, int $download_id, ISFM_File_Manager $manager ): void {
		$file_path = $this->resolve_path( $file );
		if ( ! $file_path || ! is_readable( $file_path ) ) {
			$mode = ISFM_File_Integrity::handle_missing( $file, $download_id );
			ISFM_File_Integrity::render_unavailable_page( $download_id, $mode );
			// render_unavailable_page() exits.
		}

		$file_id = (int) $file->id;
		$log_id  = ( new ISFM_Download_Logger() )->log( $download_id, $file_id );
		if ( isfm_get_settings()['enable_counting'] ) {
			$manager->increment_count( $file_id, $download_id );
		}
		do_action( 'isfm_after_download', $log_id );

		$mime      = $file->file_mime ?: 'application/octet-stream';
		$file_name = $file->file_name ?: basename( $file_path );

		$headers = apply_filters(
			'isfm_download_headers',
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

		$method = get_option( 'isfm_serve_method', 'auto' );
		$server = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) );

		// Tier 1a — Apache X-Sendfile
		if ( 'auto' === $method || 'xsendfile' === $method ) {
			if ( str_contains( $server, 'apache' ) || 'xsendfile' === $method ) {
				$this->send_headers( $headers );
				header( "X-Sendfile: {$file_path}" );
				exit;
			}
		}

		// Tier 1b — Nginx X-Accel-Redirect
		if ( 'auto' === $method || 'xaccel' === $method ) {
			if ( str_contains( $server, 'nginx' ) || 'xaccel' === $method ) {
				$this->send_headers( $headers );
				$basename = basename( $file_path );
				header( "X-Accel-Redirect: /isfm-internal/{$basename}" );
				exit;
			}
		}

		// Tier 2 — PHP streaming (works everywhere)
		$this->php_stream( $file_path, $headers );
	}

	private function php_stream( string $path, array $headers ): void {
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		$this->send_headers( $headers );

		// Direct binary stream to the client. Cannot route through WP_Filesystem
		// (it returns strings, not stream chunks) and cannot be HTML-escaped
		// (the payload is raw file bytes — escaping would corrupt downloads).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- See note above.
		readfile( $path );
		exit;
	}

	private function send_headers( array $headers ): void {
		foreach ( $headers as $name => $value ) {
			header( "{$name}: {$value}" );
		}
	}

	private function client_ip(): ?string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			if ( str_contains( $ip, ',' ) ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return null;
	}

	private function resolve_path( object $file ): ?string {
		if ( empty( $file->file_path ) ) {
			return null;
		}

		$base = realpath( isfm_files_dir() );
		$path = realpath( "{$base}/{$file->file_path}" );

		// Path traversal guard: must stay within the isfm-files directory.
		if ( $path && $base && str_starts_with( $path, $base ) ) {
			return $path;
		}

		return null;
	}
}
