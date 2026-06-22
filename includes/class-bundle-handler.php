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

	private const SWEEP_HOOK = 'isoft_fmf_bundle_cache_sweep';

	public function register_hooks(): void {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );

		/*
		 * Cache cleanup hooks:
		 * - integrity-check-complete is the primary trigger so the sweep
		 *   runs immediately after the daily missing-files scan finishes.
		 * - A daily fallback cron at midnight covers the case where the
		 *   integrity check is disabled, so the cache still gets cleaned.
		 * - before_delete_post fires immediate cleanup when an admin
		 *   permanently deletes a download (cache for trashed posts is
		 *   intentionally kept in case of untrash).
		 * - admin-post action backs the "Clear bundle cache" button.
		 */
		add_action( 'isoft_fmf_integrity_check_complete', array( $this, 'sweep_cache' ) );
		add_action( 'init', array( $this, 'maybe_schedule_sweep' ) );
		add_action( self::SWEEP_HOOK, array( $this, 'sweep_cache' ) );
		add_action( 'before_delete_post', array( $this, 'delete_cache_for_post' ), 10, 1 );
		add_action( 'admin_post_isoft_fmf_clear_bundle_cache', array( $this, 'handle_manual_clear' ) );
		add_action( 'isoft_fmf_deactivate', array( $this, 'unschedule_sweep' ) );
	}

	public function unschedule_sweep(): void {
		$timestamp = wp_next_scheduled( self::SWEEP_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::SWEEP_HOOK );
		}
	}

	public function maybe_schedule_sweep(): void {
		if ( wp_next_scheduled( self::SWEEP_HOOK ) ) {
			return;
		}
		// Schedule for midnight site time. The integrity-check-complete
		// hook is the primary cleanup trigger; this is the fallback.
		$tz       = wp_timezone();
		$midnight = ( new DateTimeImmutable( 'tomorrow midnight', $tz ) )->getTimestamp();
		wp_schedule_event( $midnight, 'daily', self::SWEEP_HOOK );
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

		// User-agent blocklist (same gate as the per-file handler).
		if ( isoft_fmf_user_agent_blocked() ) {
			do_action( 'isoft_fmf_user_agent_blocked', isoft_fmf_client_ip(), $download_id );
			wp_die( esc_html__( 'Your client is not permitted to download files from this site.', 'isoft-fm-foundation' ), 403 );
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

		// Cache fast path: if a fresh cached bundle exists for this download,
		// serve it instead of rebuilding. Exits on hit.
		$this->try_serve_from_cache( $download_id, $local_files );

		$this->stream_bundle( $download_id, $local_files );
	}

	/**
	 * If the user enabled caching AND a valid cache file exists for this
	 * download (same file set, same max mtime, within the duration window),
	 * stream it and exit. Returns silently on miss so the caller can fall
	 * through to a fresh build.
	 *
	 * Two TTL layers:
	 *   - idle TTL ($duration) — `time() - last_served_at`. Popular bundles
	 *     stay cached as long as people keep clicking; idle bundles expire.
	 *   - hard ceiling (3× $duration) — `time() - generated_at`. Paranoia
	 *     floor that forces a rebuild eventually no matter how often the
	 *     bundle is served. Catches the hypothetical case where
	 *     signatures_match() returns a false positive (says the cache is
	 *     valid when contents really did change). With duration=7 days the
	 *     ceiling kicks in at 21 days.
	 *
	 * Content-signature check (file_ids + max_mtime) runs separately and
	 * catches any real content change on every hit, independent of either TTL.
	 *
	 * @param object[] $files Local-only file rows from ISOFT_FMF_File_Manager.
	 */
	private function try_serve_from_cache( int $download_id, array $files ): void {
		if ( ! get_option( 'isoft_fmf_enable_zip_cache', 0 ) ) {
			return;
		}

		$paths = $this->cache_paths( $download_id );
		if ( ! file_exists( $paths['zip'] ) || ! file_exists( $paths['meta'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading internal cache metadata sidecar under our storage dir.
		$meta_raw = file_get_contents( $paths['meta'] );
		$meta     = $meta_raw ? json_decode( $meta_raw, true ) : null;
		if ( ! is_array( $meta ) ) {
			return;
		}

		$duration_days    = max( 1, (int) get_option( 'isoft_fmf_zip_cache_days', 7 ) );
		$duration_seconds = $duration_days * DAY_IN_SECONDS;
		$ceiling_seconds  = $duration_seconds * 3;

		$now            = time();
		$generated_at   = (int) ( $meta['generated_at'] ?? 0 );
		$last_served_at = (int) ( $meta['last_served_at'] ?? $generated_at );

		if ( ( $now - $last_served_at ) > $duration_seconds ) {
			return; // Idle too long — let the rebuild path overwrite.
		}
		if ( ( $now - $generated_at ) > $ceiling_seconds ) {
			return; // Paranoia ceiling: even popular bundles get a forced rebuild eventually.
		}

		if ( ! $this->signatures_match( $this->current_file_signature( $files ), $meta ) ) {
			return; // File set or content changed since cache was written.
		}

		// Touch the sidecar so the idle TTL renews. Failure to write is
		// non-fatal — the cache will just expire on schedule from
		// generated_at rather than rolling forward, which is acceptable
		// degradation (worst case: behaves like the old build-only TTL).
		$meta['last_served_at'] = $now;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Internal cache metadata sidecar under our storage dir; silent best-effort touch.
		@file_put_contents( $paths['meta'], wp_json_encode( $meta ), LOCK_EX );

		$this->dispatch_zip( $download_id, $files, $paths['zip'], count( $files ), false );
	}

	/**
	 * @param object[] $files Local-only file rows from ISOFT_FMF_File_Manager.
	 */
	private function stream_bundle( int $download_id, array $files ): void {
		// wp_tempnam() lives in wp-admin/includes/file.php and isn't loaded on
		// the front-end, where this handler runs (template_redirect). PHP's
		// built-in tempnam() has the same semantics — atomic create with a
		// unique suffix under the OS temp dir — and is always available.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- See note above; wp_tempnam() not available here.
		$tmp = tempnam( sys_get_temp_dir(), 'isoft_fmf_bundle_' );
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

		// If caching is on, atomically rename the temp into the cache dir so
		// subsequent requests get served by try_serve_from_cache. If the
		// rename fails (permissions, cross-filesystem, etc.) we still serve
		// the tempfile and just skip caching this round.
		$served_path = $this->write_to_cache( $download_id, $files, $tmp );
		$is_temp     = ( null === $served_path );
		if ( null === $served_path ) {
			$served_path = $tmp;
		}

		$this->dispatch_zip( $download_id, $files, $served_path, $added, $is_temp );
	}

	/**
	 * Post-build housekeeping (audit log, counters, after-action hook) plus
	 * the binary stream. Shared by the cache-hit path and the fresh-build
	 * path so the user-visible effects are identical regardless of source.
	 *
	 * @param bool $is_temp If true, the file at $zip_path is a one-shot
	 *                      temp that gets deleted after streaming.
	 */
	private function dispatch_zip( int $download_id, array $files, string $zip_path, int $file_count, bool $is_temp ): void {
		// Single audit-log entry for the whole bundle. file_id = 0 sentinel.
		$log_id = ( new ISOFT_FMF_Download_Logger() )->log( $download_id, 0 );

		// Per-file counter increment, gated on Settings → General → Count downloads.
		// Each file in the bundle gets +1; the post-level cached total is the
		// SUM of all per-file counters (see ISOFT_FMF_File_Manager::increment_count)
		// so the parent-level counter goes up by the file count automatically.
		if ( isoft_fmf_get_settings()['enable_counting'] ) {
			$manager = new ISOFT_FMF_File_Manager();
			foreach ( $files as $file ) {
				$manager->increment_count( (int) $file->id, $download_id );
			}
		}

		do_action( 'isoft_fmf_after_bundle_download', $log_id, $download_id, $file_count );

		$slug      = get_post_field( 'post_name', $download_id ) ?: "download-{$download_id}";
		$file_name = "{$slug}.zip";
		$headers   = apply_filters(
			'isoft_fmf_bundle_headers',
			array(
				'Content-Type'           => 'application/zip',
				'Content-Disposition'    => "attachment; filename=\"{$file_name}\"",
				'Content-Length'         => (string) filesize( $zip_path ),
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control'          => 'no-store, no-cache, must-revalidate',
				'Pragma'                 => 'no-cache',
			),
			$download_id,
			$file_count
		);

		if ( ob_get_level() ) {
			ob_end_clean();
		}
		foreach ( $headers as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Binary ZIP payload; cannot route through WP_Filesystem (returns strings) or HTML-escape.
		readfile( $zip_path );

		if ( $is_temp ) {
			wp_delete_file( $zip_path );
		}

		exit;
	}

	/**
	 * Move the freshly-built tempfile into the cache dir and write a
	 * metadata sidecar describing the file set + max mtime + timestamp.
	 * Returns the new cache path on success, or null if caching is off
	 * or the move failed (permissions, cross-filesystem rename, etc.) —
	 * caller should fall back to serving the tempfile directly.
	 *
	 * @param object[] $files Local-only file rows from ISOFT_FMF_File_Manager.
	 */
	private function write_to_cache( int $download_id, array $files, string $tmp_zip ): ?string {
		if ( ! get_option( 'isoft_fmf_enable_zip_cache', 0 ) ) {
			return null;
		}

		$paths = $this->cache_paths( $download_id );
		$dir   = dirname( $paths['zip'] );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move into our internal cache dir; WP_Filesystem returns booleans only for full-content writes, not atomic rename semantics.
		if ( ! @rename( $tmp_zip, $paths['zip'] ) ) {
			return null;
		}

		$sig  = $this->current_file_signature( $files );
		$now  = time();
		$meta = array(
			'file_ids'       => $sig['file_ids'],
			'max_mtime'      => $sig['max_mtime'],
			'generated_at'   => $now,
			// Initialised to generated_at so a fresh cache that's never
			// served won't be considered "idle" the moment it's written.
			'last_served_at' => $now,
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Internal cache metadata sidecar under our storage dir.
		file_put_contents( $paths['meta'], wp_json_encode( $meta ) );
		return $paths['zip'];
	}

	/**
	 * Snapshot of the current file set: sorted list of file IDs and the
	 * max filemtime across all readable files. Compared against the cached
	 * sidecar to detect file additions, deletions, and replacements.
	 *
	 * @param object[] $files Local-only file rows.
	 * @return array{ file_ids: int[], max_mtime: int }
	 */
	private function current_file_signature( array $files ): array {
		$base      = realpath( isoft_fmf_files_dir() );
		$ids       = array();
		$max_mtime = 0;
		foreach ( $files as $file ) {
			$ids[] = (int) $file->id;
			if ( empty( $file->file_path ) ) {
				continue;
			}
			$abs = realpath( "{$base}/{$file->file_path}" );
			if ( $abs && str_starts_with( $abs, $base ) ) {
				$mtime = @filemtime( $abs );
				if ( $mtime && $mtime > $max_mtime ) {
					$max_mtime = $mtime;
				}
			}
		}
		sort( $ids );
		return array(
			'file_ids'  => $ids,
			'max_mtime' => $max_mtime,
		);
	}

	/**
	 * @param array{ file_ids: int[], max_mtime: int } $current
	 * @param array                                    $cached_meta Decoded sidecar JSON.
	 */
	private function signatures_match( array $current, array $cached_meta ): bool {
		$cached_ids = isset( $cached_meta['file_ids'] )
			? array_map( 'intval', (array) $cached_meta['file_ids'] )
			: array();
		sort( $cached_ids );
		if ( $cached_ids !== $current['file_ids'] ) {
			return false;
		}
		return (int) ( $cached_meta['max_mtime'] ?? 0 ) === $current['max_mtime'];
	}

	/**
	 * @return array{ zip: string, meta: string }
	 */
	private function cache_paths( int $download_id ): array {
		$dir = isoft_fmf_files_dir() . '/.bundle-cache';
		return array(
			'zip'  => "{$dir}/bundle-{$download_id}.zip",
			'meta' => "{$dir}/bundle-{$download_id}.json",
		);
	}

	// -------------------------------------------------------------------------
	// Cache lifecycle — proactive cleanup
	//
	// The hit-path try_serve_from_cache() only checks the TTL when a bundle is
	// requested. Bundles that are never requested again — or whose download
	// has been deleted — would otherwise sit on disk forever. These methods
	// run from the integrity-check-complete action (primary) and a daily
	// fallback cron (secondary, covers the integrity-disabled case).
	// -------------------------------------------------------------------------

	/**
	 * Walk .bundle-cache/ and delete files that are:
	 *   - past 2× the configured cache duration (grace window past TTL — a
	 *     request inside that window would have rebuilt in-place anyway),
	 *   - or orphaned (their download_id no longer maps to a post),
	 *   - or have a missing pair member (.zip without .json or vice versa).
	 */
	public function sweep_cache(): int {
		$dir = isoft_fmf_files_dir() . '/.bundle-cache';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$duration_days    = max( 1, (int) get_option( 'isoft_fmf_zip_cache_days', 7 ) );
		$duration_seconds = $duration_days * DAY_IN_SECONDS;
		$ceiling_seconds  = $duration_seconds * 3;
		$now              = time();
		$deleted          = 0;

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- silent failure: nothing to clean if dir can't be read.
		if ( ! $entries ) {
			return 0;
		}

		// First pass: catalog .zip + .json pairs by download_id.
		$pairs = array();
		foreach ( $entries as $entry ) {
			if ( ! preg_match( '/^bundle-(\d+)\.(zip|json)$/', $entry, $m ) ) {
				continue;
			}
			$id                   = (int) $m[1];
			$ext                  = $m[2];
			$pairs[ $id ][ $ext ] = $dir . '/' . $entry;
		}

		foreach ( $pairs as $download_id => $files ) {
			$reason = null;

			// Missing pair member — incomplete cache, delete what's left.
			if ( ! isset( $files['zip'], $files['json'] ) ) {
				$reason = 'incomplete';
			} elseif ( null === get_post( $download_id ) || 'isoft_fmf_file' !== get_post_type( $download_id ) ) {
				$reason = 'orphan';
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Internal cache sidecar under our storage dir.
				$meta_raw = file_get_contents( $files['json'] );
				$meta     = $meta_raw ? json_decode( $meta_raw, true ) : null;
				if ( ! is_array( $meta ) ) {
					$reason = 'expired';
				} else {
					$gen_at  = (int) ( $meta['generated_at'] ?? 0 );
					$last_at = (int) ( $meta['last_served_at'] ?? $gen_at );
					// Same dual-TTL rule as the request path: expired if
					// idle past the user setting OR past the hard ceiling.
					$idle_too_long = ( $now - $last_at ) > $duration_seconds;
					$past_ceiling  = ( $now - $gen_at ) > $ceiling_seconds;
					if ( $gen_at <= 0 || $idle_too_long || $past_ceiling ) {
						$reason = 'expired';
					}
				}
			}

			if ( null === $reason ) {
				continue;
			}

			foreach ( $files as $path ) {
				wp_delete_file( $path );
				if ( ! file_exists( $path ) ) {
					++$deleted;
				}
			}
			do_action( 'isoft_fmf_bundle_cache_deleted', (int) $download_id, $reason );
		}

		return $deleted;
	}

	/**
	 * Delete the cache pair for one download. Fires on before_delete_post
	 * (permanent delete only — trashed downloads keep their cache in case
	 * of untrash).
	 */
	public function delete_cache_for_post( int $post_id ): void {
		if ( 'isoft_fmf_file' !== get_post_type( $post_id ) ) {
			return;
		}
		$paths = $this->cache_paths( $post_id );
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Admin-post handler for the "Clear bundle cache" button in
	 * Settings → Display. Nukes the entire .bundle-cache/ directory
	 * regardless of age or orphan status.
	 */
	public function handle_manual_clear(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear the bundle cache.', 'isoft-fm-foundation' ) );
		}
		check_admin_referer( 'isoft_fmf_clear_bundle_cache' );

		$result = ( new ISOFT_FMF_Maintenance_Service() )->clear_bundle_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'               => 'isoft_fmf_file',
					'page'                    => 'isoft-fmf-settings',
					'tab'                     => 'display',
					'isoft_fmf_cache_cleared' => (int) $result['deleted'],
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
