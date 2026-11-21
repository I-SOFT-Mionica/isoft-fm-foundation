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

		$file_manager = new ISOFT_FMF_File_Manager();
		$file         = $file_manager->get_file( $file_id );
		$download_id  = $file ? (int) $file->download_id : 0;

		/*
		 * Nonce check — skipped for public downloads so cached pages keep
		 * working links (see isoft_fmf_download_requires_nonce()). Unknown
		 * files still require one, so probing IDs reveals nothing.
		 */
		if ( isoft_fmf_download_requires_nonce( $download_id ) ) {
			$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, "isoft_fmf_download_{$file_id}" ) ) {
				wp_die( esc_html__( 'Security check failed. Please refresh the page and try again.', 'isoft-fm-foundation' ), 403 );
			}
		}

		if ( ! $file ) {
			wp_die( esc_html__( 'File not found.', 'isoft-fm-foundation' ), 404 );
		}

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
		$rate_limit = isoft_fmf_effective_rate_limit();
		if ( $rate_limit > 0 ) {
			$ip_hash = 'isoft_fmf_rl_' . md5( ISOFT_FMF_Client_Ip::bucket( isoft_fmf_client_ip() ) );
			$hits    = (int) get_transient( $ip_hash );
			if ( $hits >= $rate_limit ) {
				do_action( 'isoft_fmf_rate_limit_exceeded', isoft_fmf_client_ip(), $rate_limit );
				wp_die( esc_html__( 'Download limit exceeded. Please try again later.', 'isoft-fm-foundation' ), 429 );
			}
			set_transient( $ip_hash, $hits + 1, HOUR_IN_SECONDS );
		}

		// Held back by an administrator or an extension: listed, not served.
		// The same "temporarily unavailable" page (503) as a missing file:
		// the visitor may come back, so it is not a 403.
		if ( ! empty( $file->is_unavailable ) ) {
			$this->show_unavailable( $download_id );
			return;
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
			if ( ! self::is_head() ) {
				$log_id = isoft_fmf_record_download( $file_id );
				do_action( 'isoft_fmf_after_download', $log_id );
			}
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External-link downloads point off-site; wp_safe_redirect() rejects them. $target is validated via esc_url_raw() above. See changelog 0.4.3.
			wp_redirect( $target );
			exit;
		}

		if ( 'remote' === $file->file_type ) {
			$this->serve_remote_file( $file, $download_id );
			return;
		}

		$this->serve_local_file( $file, $download_id, $file_manager );
	}

	/**
	 * A file whose content lives elsewhere. Every check above has passed; the
	 * plugin that owns it says how to deliver it. Anything but a usable answer
	 * ends on the unavailable page and is not counted.
	 */
	private function serve_remote_file( object $file, int $download_id ): void {
		$range      = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$resolution = self::resolve_remote( $file, $download_id, $range, self::is_head() );

		if ( null === $resolution ) {
			$this->show_unavailable( $download_id );
			return;
		}

		$this->record_remote( $file, $range );
		if ( 'redirect' === $resolution['mode'] ) {
			// A signed cloud address must not be kept by a cache.
			nocache_headers();
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The cloud host is not in WordPress's allowlist; the URL is validated in resolve_remote().
			wp_redirect( $resolution['url'] );
			$this->finish();
			return;
		}
		$this->proxy_remote( $file, $resolution );
	}

	/**
	 * Ask the plugin that owns a remote file how to deliver it, and keep only
	 * a usable answer. No visitor checks and no counting: the caller does both.
	 * Shared by the download handler and isoft_fmf_resolve_public_remote_file().
	 *
	 * @return array{mode:string, url?:string, stream?:callable, headers?:array<string,string>, status?:int}|null
	 *         redirect: 'url' (http or https); proxy: 'stream', 'headers' (only
	 *         the ones that describe the body), 'status' (200 or 206); null
	 *         when there is no usable answer.
	 */
	public static function resolve_remote( object $file, int $download_id, string $range, bool $head ): ?array {
		try {
			/**
			 * How to deliver a remote file.
			 *
			 * @param null|WP_Error|array $resolution Null or WP_Error: unavailable.
			 *                                        array( 'mode' => 'redirect', 'url' => ... ), or
			 *                                        array( 'mode' => 'proxy', 'stream' => callable, 'headers' => array(), 'status' => 200|206 ).
			 * @param object              $file       The file row.
			 * @param array               $context    'download_id'; 'range': the Range header or '';
			 *                                        'head': true for a HEAD request (headers only,
			 *                                        the stream is not called).
			 */
			$resolution = apply_filters(
				'isoft_fmf_resolve_remote_file',
				null,
				$file,
				array(
					'download_id' => $download_id,
					'range'       => $range,
					'head'        => $head,
				)
			);
		} catch ( Throwable $e ) {
			unset( $e );
			return null;
		}

		$mode = is_array( $resolution ) ? ( $resolution['mode'] ?? '' ) : '';

		if ( 'redirect' === $mode ) {
			$target = esc_url_raw( (string) ( $resolution['url'] ?? '' ), array( 'https', 'http' ) );
			return '' === $target ? null : array(
				'mode' => 'redirect',
				'url'  => $target,
			);
		}

		if ( 'proxy' === $mode && is_callable( $resolution['stream'] ?? null ) ) {
			$describing = array( 'content-length', 'content-range', 'accept-ranges', 'etag', 'last-modified' );
			$headers    = array();
			foreach ( (array) ( $resolution['headers'] ?? array() ) as $name => $value ) {
				if ( in_array( strtolower( (string) $name ), $describing, true ) && is_scalar( $value ) ) {
					$headers[ (string) $name ] = str_replace( array( "\r", "\n" ), '', (string) $value );
				}
			}
			return array(
				'mode'    => 'proxy',
				'stream'  => $resolution['stream'],
				'headers' => $headers,
				'status'  => 206 === (int) ( $resolution['status'] ?? 200 ) ? 206 : 200,
			);
		}

		return null;
	}

	/**
	 * A HEAD request asks for the headers only: link checkers and link
	 * previews send it. It gets no body and is never counted.
	 */
	public static function is_head(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Whether a request with this Range header is a new download. Resumed and
	 * segmented requests start past the first byte.
	 */
	public static function range_counts( string $range ): bool {
		return '' === $range || 1 === preg_match( '/^\s*bytes\s*=\s*0+\s*-/i', $range );
	}

	/**
	 * One byte range from a Range header, for a file of $size bytes.
	 *
	 * @return array{0:int, 1:int}|null|false Start and end (inclusive); null to
	 *                                         send the whole file (no header,
	 *                                         several ranges, or a malformed
	 *                                         one); false when the range starts
	 *                                         past the end (416).
	 */
	public static function parse_range( string $header, int $size ): array|null|false {
		if ( $size <= 0 || 1 !== preg_match( '/^\s*bytes\s*=\s*(\d{0,18})\s*-\s*(\d{0,18})\s*$/i', $header, $m ) ) {
			return null;
		}
		if ( '' === $m[1] ) {
			if ( '' === $m[2] ) {
				return null;
			}
			$suffix = (int) $m[2];
			return 0 === $suffix ? false : array( max( 0, $size - $suffix ), $size - 1 );
		}
		$start = (int) $m[1];
		if ( $start >= $size ) {
			return false;
		}
		$end = ( '' === $m[2] || (int) $m[2] >= $size ) ? $size - 1 : (int) $m[2];
		return $end < $start ? null : array( $start, $end );
	}

	/**
	 * Whether a resumed request may get a part of the file. A client sends
	 * If-Range with the Last-Modified it saw: if the file has changed since,
	 * it gets the whole new file, not a part spliced onto the old one.
	 */
	private function if_range_allows( string $last_modified ): bool {
		$if_range = isset( $_SERVER['HTTP_IF_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_RANGE'] ) ) ) : '';
		return '' === $if_range || $if_range === $last_modified;
	}

	private function record_remote( object $file, string $range ): void {
		if ( ! self::is_head() && self::range_counts( $range ) ) {
			$log_id = isoft_fmf_record_download( (int) $file->id );
			do_action( 'isoft_fmf_after_download', $log_id );
		}
	}

	/**
	 * Stream through this server. The extension's headers can describe the
	 * body but cannot change how the browser treats it.
	 *
	 * @param object               $file       The file row.
	 * @param array<string, mixed> $resolution The filter's answer.
	 */
	private function proxy_remote( object $file, array $resolution ): void {
		$headers = apply_filters(
			'isoft_fmf_download_headers',
			array(
				'Content-Type'           => $file->file_mime ?: 'application/octet-stream',
				'Content-Disposition'    => isoft_fmf_content_disposition( (string) $file->file_name ),
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control'          => 'no-store, no-cache, must-revalidate',
				'Pragma'                 => 'no-cache',
			),
			$file
		);

		// Already reduced to the headers that describe the body (resolve_remote()).
		$headers = array_merge( $headers, $resolution['headers'] );

		$this->prepare_stream();
		$headers['Content-Encoding'] = 'identity';

		// Cloud files can be several gigabytes.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- A long proxied download; the @ guards against hosts that forbid it.
		}

		if ( 206 === $resolution['status'] ) {
			$this->send_status( 206 );
		}
		$this->send_headers( $headers );

		if ( ! self::is_head() ) {
			try {
				call_user_func( $resolution['stream'] );
			} catch ( Throwable $e ) {
				unset( $e ); // The response has started; all that is left is to end it.
			}
		}
		$this->finish();
	}

	protected function show_unavailable( int $download_id ): void {
		ISOFT_FMF_File_Integrity::render_unavailable_page( $download_id, 'partial' );
	}

	protected function send_status( int $status ): void {
		status_header( $status );
	}

	protected function finish(): void {
		exit;
	}

	private function serve_local_file( object $file, int $download_id, ISOFT_FMF_File_Manager $manager ): void {
		$file_path = $this->resolve_path( $file );
		if ( ! $file_path || ! is_readable( $file_path ) ) {
			$mode = ISOFT_FMF_File_Integrity::handle_missing( $file, $download_id );
			ISOFT_FMF_File_Integrity::render_unavailable_page( $download_id, $mode );
			// render_unavailable_page() exits.
		}

		$method        = $this->resolve_serve_method();
		$size          = (int) filesize( $file_path );
		$last_modified = gmdate( 'D, d M Y H:i:s', (int) filemtime( $file_path ) ) . ' GMT';
		$range_header  = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		// PHP serves one byte range itself, so a broken download of a large
		// file resumes. X-Sendfile and X-Accel-Redirect leave ranges to the
		// web server, which handles them.
		$range = null;
		if ( 'php' === $method && '' !== $range_header && $this->if_range_allows( $last_modified ) ) {
			$range = self::parse_range( $range_header, $size );
			if ( false === $range ) {
				$this->send_status( 416 );
				$this->send_headers( array( 'Content-Range' => "bytes */{$size}" ) );
				$this->finish();
				return;
			}
		}

		// A resumed or segmented request is not a new download.
		$counts = 'php' === $method ? ( null === $range || 0 === $range[0] ) : self::range_counts( $range_header );
		if ( $counts && ! self::is_head() ) {
			$log_id = isoft_fmf_record_download( (int) $file->id );
			do_action( 'isoft_fmf_after_download', $log_id );
		}

		$mime      = $file->file_mime ?: 'application/octet-stream';
		$file_name = $file->file_name ?: basename( $file_path );

		$base = array(
			'Content-Type'           => $mime,
			'Content-Disposition'    => isoft_fmf_content_disposition( $file_name ),
			'Content-Length'         => (string) $size,
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'no-store, no-cache, must-revalidate',
			'Pragma'                 => 'no-cache',
		);
		if ( 'php' === $method ) {
			$base['Accept-Ranges'] = 'bytes';
			$base['Last-Modified'] = $last_modified;
		}
		$headers = apply_filters( 'isoft_fmf_download_headers', $base, $file );

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
		$this->php_stream( $file_path, $headers, $range, $size );
	}

	/**
	 * Resolve the actual serve method from the user's setting.
	 *
	 * Auto-mode used to dispatch X-Sendfile / X-Accel-Redirect based on
	 * SERVER_SOFTWARE alone — but the server string says nothing about whether
	 * mod_xsendfile is loaded or the /isoft-fmf-internal/ alias is configured.
	 * On an early production install (2026-06-19) every download
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

	/**
	 * @param array{0:int, 1:int}|null $range One byte range, or null for the whole file.
	 */
	private function php_stream( string $path, array $headers, ?array $range, int $size ): void {
		$this->prepare_stream();

		// Force identity transfer-encoding so server-level gzip (Apache
		// mod_deflate, nginx gzip) doesn't recompress and skew Content-Length.
		// Most servers respect Content-Encoding set by upstream.
		$headers['Content-Encoding'] = 'identity';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming binary download; WP_Filesystem returns strings, not chunks.
		$handle = fopen( $path, 'rb' );
		if ( $handle && null !== $range && 0 !== fseek( $handle, $range[0] ) ) {
			$range = null; // Cannot seek: send the whole file instead.
			rewind( $handle );
		}

		if ( $handle && null !== $range ) {
			$headers['Content-Range']  = "bytes {$range[0]}-{$range[1]}/{$size}";
			$headers['Content-Length'] = (string) ( $range[1] - $range[0] + 1 );
			$this->send_status( 206 );
		}
		$this->send_headers( $headers );

		if ( self::is_head() ) {
			if ( $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs the fopen() above.
			}
			$this->finish();
			return;
		}

		if ( ! $handle ) {
			// Fallback: try readfile if fopen failed for whatever reason. The
			// headers above describe the whole file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Last-resort fallback when fopen() returned false.
			readfile( $path );
			$this->finish();
			return;
		}

		// Chunked stream with explicit flush so bytes hit the client as they
		// come off disk instead of being held by FPM / nginx fastcgi buffer
		// until the script exits.
		$left = null === $range ? PHP_INT_MAX : $range[1] - $range[0] + 1;
		while ( $left > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 8192, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming raw binary file content.
			// fread returns false on read error and '' at EOF; feof() only flips
			// true AFTER an unsuccessful read, so we'd spin forever on either
			// failure mode without an explicit break. Guard against both.
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$left -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file content; escaping would corrupt the download.
			flush();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the fopen handle from above; matched pair.
		$this->finish();
	}

	protected function prepare_stream(): void {
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
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.IniSet.Risky,Squiz.PHP.DiscouragedFunctions.Discouraged -- Streaming raw binary download; gzip would break Content-Length, and the @ guards against hosts where ini_set is on a runtime deny-list.
	}

	protected function send_headers( array $headers ): void {
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
		if ( $path && $base && isoft_fmf_path_within( $path, $base ) ) {
			return $path;
		}

		return null;
	}
}
