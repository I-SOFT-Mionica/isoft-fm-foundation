<?php
/**
 * File integrity — detection, recovery, and scheduling for missing local files.
 *
 * Scope (free / core):
 *   - Serve-time detection: mark missing, unpublish-if-all-missing, queue admin notice,
 *     render friendly end-user page.
 *   - Scheduled cron: file_exists() check at stored path; on miss, stat-loop the
 *     category folder looking for an inode match (rename recovery), hash-verify the
 *     matched candidate, else mark missing.
 *
 * Out of scope (see Sentinel extension):
 *   - Drift detection when the filename is unchanged (rclone replace, backup restore,
 *     in-place edits). The core scan only answers "is there a file at the expected path?"
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_File_Integrity {

	private const CRON_HOOK              = 'isoft_fmf_integrity_check';
	private const CHUNK_SIZE             = 200;
	private const LOCK_OPTION            = 'isoft_fmf_integrity_running';
	private const LOCK_FALLBACK_TTL      = 600;  // 10 min — used when PHP reports no execution-time limit.
	private const LOCK_CEILING           = 1800; // 30 min — even if PHP reports "unlimited" or a huge limit, never wait longer than this before treating as crashed.
	private const LOCK_BUFFER_SECONDS    = 30;   // grace beyond max_execution_time before we call it stale.

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_check' ) );
		add_action( 'update_option_isoft_fmf_integrity_check_enabled', array( $this, 'reschedule' ), 10, 0 );
		add_action( 'update_option_isoft_fmf_integrity_check_time', array( $this, 'reschedule' ), 10, 0 );
		add_action( 'admin_post_isoft_fmf_integrity_check_now', array( $this, 'handle_run_now' ) );
	}

	// -------------------------------------------------------------------------

	/*
	 * Concurrency lock
	 *
	 * run_scheduled_check() is the same code path for the daily cron AND the
	 * manual "Run check now" button. Two failure modes we guard against:
	 *   1. Double-trigger (admin double-clicks, cron fires mid-manual-run) —
	 *      add_option() is the atomic acquire; competing callers see the
	 *      existing row and bail out before any scan work happens.
	 *   2. PHP exits mid-run via fatal (OOM / max_execution_time / wp_die).
	 *      The lock stays in the DB. After the staleness window — derived
	 *      from PHP's actual max_execution_time at the time the lock was
	 *      acquired — any new run silently takes over. Automatic recovery,
	 *      no human force-restart needed in the common case.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Snapshot of PHP runtime limits relevant to the scan.
	 *
	 * @return array{
	 *     max_execution_time: int,
	 *     memory_limit_bytes: int,
	 *     can_extend_time: bool,
	 * }
	 */
	public static function server_limits(): array {
		$max_exec = (int) ini_get( 'max_execution_time' );

		$mem_raw = (string) ini_get( 'memory_limit' );
		$mem     = wp_convert_hr_to_bytes( $mem_raw );
		if ( $mem < 0 ) {
			$mem = -1;
		}

		$disabled       = explode( ',', (string) ini_get( 'disable_functions' ) );
		$disabled       = array_map( 'trim', $disabled );
		$can_extend     = function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true );

		return array(
			'max_execution_time' => max( 0, $max_exec ),
			'memory_limit_bytes' => $mem,
			'can_extend_time'    => $can_extend,
		);
	}

	/**
	 * How long to wait before treating an in-progress lock as crashed.
	 * Derived from the running PHP's max_execution_time plus a small buffer,
	 * clamped to a reasonable ceiling — we never want a single failed run
	 * to leave the integrity check unrunnable for hours.
	 */
	private static function lock_ttl_seconds(): int {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return self::LOCK_FALLBACK_TTL;
		}
		return min( self::LOCK_CEILING, $max + self::LOCK_BUFFER_SECONDS );
	}

	/**
	 * Current lock state, or null if no run is in progress.
	 *
	 * The staleness threshold is what the PHP-derived TTL was the moment we
	 * check (good enough — admins almost never change max_execution_time
	 * between an acquire and the next check).
	 *
	 * Returns: ['status' => 'active'|'stale', 'started_at' => int, 'age_seconds' => int, 'ttl_seconds' => int]
	 */
	public static function lock_state(): ?array {
		$running = get_option( self::LOCK_OPTION, null );
		if ( ! is_array( $running ) ) {
			return null;
		}
		$started_at = (int) ( $running['started_at'] ?? 0 );
		$age        = max( 0, time() - $started_at );
		$ttl        = self::lock_ttl_seconds();
		return array(
			'status'      => $age >= $ttl ? 'stale' : 'active',
			'started_at'  => $started_at,
			'age_seconds' => $age,
			'ttl_seconds' => $ttl,
		);
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	public function maybe_schedule(): void {
		$enabled = (bool) get_option( 'isoft_fmf_integrity_check_enabled', 0 );
		if ( ! $enabled ) {
			$this->unschedule();
			return;
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( $this->next_run_timestamp(), 'daily', self::CRON_HOOK );
	}

	public function reschedule(): void {
		$this->unschedule();
		$this->maybe_schedule();
	}

	private function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	private function next_run_timestamp(): int {
		$raw = (string) get_option( 'isoft_fmf_integrity_check_time', '02:30' );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $raw, $m ) ) {
			$m = array( '02:30', '2', '30' );
		}
		$hour   = max( 0, min( 23, (int) $m[1] ) );
		$minute = max( 0, min( 59, (int) $m[2] ) );

		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$run = $now->setTime( $hour, $minute, 0 );
		if ( $run <= $now ) {
			$run = $run->modify( '+1 day' );
		}
		return $run->getTimestamp();
	}

	// -------------------------------------------------------------------------
	// Serve-time detection
	// -------------------------------------------------------------------------

	/**
	 * Called from the download handler when a local file is not readable at its stored path.
	 * Marks the file missing, unpublishes the post only if ALL files are missing, and
	 * queues an admin notice.
	 *
	 * @return 'unpublished'|'partial' Render hint for the user-facing page.
	 */
	public static function handle_missing( object $file, int $download_id ): string {
		global $wpdb;

		if ( empty( $file->is_missing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
			$wpdb->update(
				"{$wpdb->prefix}isoft_fmf_files",
				array(
					'is_missing'    => 1,
					'missing_since' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $file->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			ISOFT_FMF_File_Manager::bust_cache_for( $download_id, (int) $file->id );
			delete_transient( 'isoft_fmf_missing_count' );
		}

		// Count remaining non-missing local files on this download.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count immediately after write; freshness required.
		$healthy = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files
				  WHERE download_id = %d
				    AND file_type   = 'local'
				    AND is_missing  = 0",
				$download_id
			)
		);

		$mode = 'partial';
		if ( 0 === $healthy ) {
			// Only unpublish if it's currently published; and only auto-republish later if
			// we were the ones who flipped it.
			if ( 'publish' === get_post_status( $download_id ) ) {
				wp_update_post(
					array(
						'ID'          => $download_id,
						'post_status' => 'draft',
					)
				);
				update_post_meta( $download_id, '_isoft_fmf_auto_unpublished_at', time() );
			}
			$mode = 'unpublished';
		}

		// Idempotent notice: don't spam if we already queued one for this file.
		if ( empty( $file->is_missing ) ) {
			$url     = add_query_arg(
				array(
					'post_type' => 'isoft_fmf_file',
					'page'      => 'isoft-fmf-broken-links',
					'highlight' => (int) $file->id,
				),
				admin_url( 'edit.php' )
			);
			$title   = get_the_title( $download_id ) ?: '#' . $download_id;
			$message = sprintf(
				/* translators: 1: download title, 2: Broken Links URL */
				__( 'A file on "%1$s" is missing from disk. <a href="%2$s">Review on Broken Links screen</a>.', 'isoft-fm-foundation' ),
				esc_html( $title ),
				esc_url( $url )
			);
			isoft_fmf_notify_admin( $message, 'warning' );
		}

		do_action( 'isoft_fmf_file_missing', (int) $file->id, $download_id, 'serve' );

		return $mode;
	}

	/**
	 * Render a friendly end-user page when a file is unavailable. Replaces raw wp_die.
	 */
	public static function render_unavailable_page( int $download_id, string $mode ): void {
		status_header( 503 );
		nocache_headers();

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Template handles its own escaping.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		}

		$template = ISOFT_FMF_PLUGIN_DIR . 'templates/file-unavailable.php';
		if ( file_exists( $template ) ) {
			$isoft_fmf_unavailable_post_id = $download_id;
			$isoft_fmf_unavailable_mode    = $mode;
			include $template;
		} else {
			wp_die( esc_html__( 'This file is temporarily unavailable.', 'isoft-fm-foundation' ), '', 503 );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// -------------------------------------------------------------------------
	// Scheduled / manual scan
	// -------------------------------------------------------------------------

	public function run_scheduled_check(): array {
		// Atomic acquire: add_option only succeeds when the row doesn't
		// exist, so two callers racing to start a scan can't both win.
		// On crash recovery (stale lock past TTL), the loser refuses but
		// the next call after staleness will get a clean slate via the
		// delete_option below.
		$lock = self::lock_state();
		if ( null !== $lock && 'active' === $lock['status'] ) {
			return array(
				'skipped' => true,
				'reason'  => 'already_running',
				'lock'    => $lock,
			);
		}
		if ( null !== $lock && 'stale' === $lock['status'] ) {
			delete_option( self::LOCK_OPTION );
		}
		$acquired = add_option(
			self::LOCK_OPTION,
			array(
				'started_at' => time(),
				'by_user'    => get_current_user_id(),
			),
			'',
			false
		);
		if ( ! $acquired ) {
			// Another request slipped between our check and the add_option;
			// they got the lock first. Bail without doing scan work.
			return array(
				'skipped' => true,
				'reason'  => 'race_lost',
			);
		}

		// Extend PHP's per-request time budget if the host lets us — many
		// shared hosts disable set_time_limit via disable_functions. If the
		// call no-ops, the lock TTL kicks in for recovery (next manual run
		// auto-recovers past the stale threshold).
		$limits = self::server_limits();
		if ( $limits['can_extend_time'] ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- host may have set_time_limit on a deny-list at runtime; the @ is the WP-canonical guard for that.
		}

		$summary = array(
			'checked'     => 0,
			'healed'      => 0,
			'relinked'    => 0,
			'still_gone'  => 0,
			'started_at'  => current_time( 'mysql' ),
			'finished_at' => null,
		);

		try {
			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled scan of file rows; rows touched once per run, cache layer would never be hit.
			$offset = 0;
			while ( true ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}isoft_fmf_files
						  WHERE file_type = 'local'
						  ORDER BY id ASC
						  LIMIT %d OFFSET %d",
						self::CHUNK_SIZE,
						$offset
					)
				);
				if ( ! $rows ) {
					break;
				}

				foreach ( $rows as $row ) {
					++$summary['checked'];
					$outcome = $this->check_one( $row );
					if ( isset( $summary[ $outcome ] ) ) {
						++$summary[ $outcome ];
					}
				}

				$offset += self::CHUNK_SIZE;
			}

			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$summary['finished_at'] = current_time( 'mysql' );
			update_option( 'isoft_fmf_integrity_last_run', $summary, false );

			if ( $summary['checked'] > 0 ) {
				isoft_fmf_notify_admin(
					sprintf(
						/* translators: 1: healed, 2: relinked, 3: still missing */
						__( 'File integrity check: %1$d healed, %2$d relinked, %3$d still missing.', 'isoft-fm-foundation' ),
						$summary['healed'],
						$summary['relinked'],
						$summary['still_gone']
					),
					$summary['still_gone'] > 0 ? 'warning' : 'info'
				);
			}

			delete_transient( 'isoft_fmf_missing_count' );
			do_action( 'isoft_fmf_integrity_check_complete', $summary );

			return $summary;
		} finally {
			// Release the lock no matter how we exit (normal return, exception,
			// or shutdown after a fatal — finally runs in all three cases). A
			// run that hits OOM hard enough to skip finally still gets
			// recovered by lock_state()'s staleness check on the next attempt.
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * @return 'healed'|'relinked'|'still_gone'|'skipped'
	 */
	private function check_one( object $file ): string {
		if ( empty( $file->file_path ) ) {
			return 'skipped';
		}

		$abs = isoft_fmf_files_dir() . '/' . $file->file_path;

		if ( file_exists( $abs ) ) {
			if ( ! empty( $file->is_missing ) ) {
				$this->mark_healthy( $file );
				return 'healed';
			}
			return 'skipped';
		}

		// File is not at the expected path. Try inode-based rename recovery
		// first (cheap stat-loop; works on Linux/macOS where POSIX inodes are
		// stable), then content-hash recovery (works everywhere including
		// Windows/NTFS where inodes aren't reliable). Both stay inside the
		// download's own category folder — auto-relink never reassigns a
		// download to a different category. Cross-category moves go through
		// the manual recovery dialog on the Broken Links screen.
		$autorelink = (bool) get_option( 'isoft_fmf_integrity_autorelink', 1 );
		if ( $autorelink ) {
			if ( $this->try_relink_by_inode( $file ) ) {
				$this->mark_healthy( $file );
				return 'relinked';
			}
			if ( $this->try_relink_by_hash( $file ) ) {
				$this->mark_healthy( $file );
				return 'relinked';
			}
		}

		// Not recoverable by inode — mark missing (idempotent).
		if ( empty( $file->is_missing ) ) {
			self::handle_missing( $file, (int) $file->download_id );
		}
		return 'still_gone';
	}

	/**
	 * Stat-loop the download's category folder; if any entry has our stored inode
	 * AND hashes match (recycling guard), update file_path to the new relative path.
	 */
	public function try_relink_by_inode( object $file ): bool {
		if ( ! (bool) get_option( 'isoft_fmf_integrity_use_inode', 1 ) ) {
			return false;
		}
		if ( empty( $file->inode ) || empty( $file->file_hash ) ) {
			return false;
		}

		$term_id = $this->get_download_category_id( (int) $file->download_id );
		if ( ! $term_id ) {
			return false;
		}

		$category_fs = isoft_fmf_category_fs_path( $term_id );
		if ( ! is_dir( $category_fs ) ) {
			return false;
		}

		$it = @scandir( $category_fs );
		if ( ! $it ) {
			return false;
		}

		foreach ( $it as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$candidate = $category_fs . '/' . $entry;
			if ( ! is_file( $candidate ) ) {
				continue;
			}
			$ino = @fileinode( $candidate );
			if ( ! $ino || (int) $ino !== (int) $file->inode ) {
				continue;
			}
			// Inode match — verify hash to guard against inode recycling.
			$hash = @hash_file( 'sha256', $candidate );
			if ( ! $hash || ! hash_equals( (string) $file->file_hash, (string) $hash ) ) {
				// Recycled inode — different content. Leave for manual review.
				return false;
			}

			// Commit new relative path.
			global $wpdb;
			$new_rel = ltrim(
				str_replace( '\\', '/', substr( $candidate, strlen( isoft_fmf_files_dir() ) ) ),
				'/'
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rename-recovery relink; cache invalidated below.
			$wpdb->update(
				"{$wpdb->prefix}isoft_fmf_files",
				array(
					'file_path' => $new_rel,
					'file_name' => basename( $candidate ),
				),
				array( 'id' => (int) $file->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			ISOFT_FMF_File_Manager::bust_cache_for( (int) $file->download_id, (int) $file->id );
			return true;
		}

		return false;
	}

	private function mark_healthy( object $file ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$wpdb->update(
			"{$wpdb->prefix}isoft_fmf_files",
			array(
				'is_missing'    => 0,
				'missing_since' => null,
			),
			array( 'id' => (int) $file->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$download_id = (int) $file->download_id;
		ISOFT_FMF_File_Manager::bust_cache_for( $download_id, (int) $file->id );
		delete_transient( 'isoft_fmf_missing_count' );
		$this->maybe_republish( $download_id );
	}

	/**
	 * If the integrity system was the one that unpublished this post, and no files
	 * remain flagged as missing, flip it back to 'publish' and clear the flag.
	 */
	private function maybe_republish( int $download_id ): void {
		$auto = get_post_meta( $download_id, '_isoft_fmf_auto_unpublished_at', true );
		if ( ! $auto ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count immediately after mark_healthy write; freshness required.
		$still_broken = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files
				  WHERE download_id = %d
				    AND file_type   = 'local'
				    AND is_missing  = 1",
				$download_id
			)
		);
		if ( $still_broken > 0 ) {
			return;
		}

		if ( 'draft' === get_post_status( $download_id ) ) {
			wp_update_post(
				array(
					'ID'          => $download_id,
					'post_status' => 'publish',
				)
			);
		}
		delete_post_meta( $download_id, '_isoft_fmf_auto_unpublished_at' );
	}

	private function get_download_category_id( int $download_id ): int {
		$terms = get_the_terms( $download_id, 'isoft_fmf_category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return 0;
		}
		return (int) $terms[0]->term_id;
	}

	// -------------------------------------------------------------------------
	// Admin: Run Now
	// -------------------------------------------------------------------------

	public function handle_run_now(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the integrity check.', 'isoft-fm-foundation' ) );
		}
		check_admin_referer( 'isoft_fmf_integrity_check_now' );

		// "Where do I send the user back to?" — the Broken Links screen
		// and the Maintenance tab both surface the Run-now button. Default
		// to Maintenance for back-compat with existing links.
		$return  = isset( $_GET['return'] ) ? sanitize_key( wp_unslash( $_GET['return'] ) ) : 'maintenance';
		$page    = 'broken-links' === $return ? 'isoft-fmf-broken-links' : 'isoft-fmf-settings';
		$tab     = 'broken-links' === $return ? null : 'maintenance';
		$base    = array(
			'post_type' => 'isoft_fmf_file',
			'page'      => $page,
		);
		if ( $tab ) {
			$base['tab'] = $tab;
		}

		$result = $this->run_scheduled_check();

		$args = $base;
		if ( is_array( $result ) && ! empty( $result['skipped'] ) ) {
			$args['isoft_fmf_running'] = 1;
		} else {
			$args['isoft_fmf_ran'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Utilities for Broken Links screen
	// -------------------------------------------------------------------------

	/**
	 * Hash-based rename recovery within the download's own category folder.
	 * Same shape as try_relink_by_inode but uses SHA-256 content match
	 * instead of POSIX inode — works on Windows / NTFS / any filesystem
	 * where inodes aren't stable. Pre-filters by file_size so we only hash
	 * candidates of the right size; on a typical category folder of
	 * ≤ 30 files this is essentially free.
	 */
	public function try_relink_by_hash( object $file ): bool {
		if ( empty( $file->file_hash ) || empty( $file->file_size ) ) {
			return false;
		}

		$term_id = $this->get_download_category_id( (int) $file->download_id );
		if ( ! $term_id ) {
			return false;
		}

		$category_fs = isoft_fmf_category_fs_path( $term_id );
		if ( ! is_dir( $category_fs ) ) {
			return false;
		}

		$it = @scandir( $category_fs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- silent failure is the recovery posture; nothing to log.
		if ( ! $it ) {
			return false;
		}

		foreach ( $it as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$candidate = $category_fs . '/' . $entry;
			if ( ! is_file( $candidate ) ) {
				continue;
			}
			if ( (int) @filesize( $candidate ) !== (int) $file->file_size ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- silent failure is the recovery posture.
				continue;
			}
			$hash = @hash_file( 'sha256', $candidate ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $hash || ! hash_equals( (string) $file->file_hash, (string) $hash ) ) {
				continue;
			}

			// Match — commit new relative path (and basename in case the
			// filename changed on rename).
			global $wpdb;
			$new_rel = ltrim(
				str_replace( '\\', '/', substr( $candidate, strlen( isoft_fmf_files_dir() ) ) ),
				'/'
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rename-recovery relink; cache invalidated below.
			$wpdb->update(
				"{$wpdb->prefix}isoft_fmf_files",
				array(
					'file_path' => $new_rel,
					'file_name' => basename( $candidate ),
				),
				array( 'id' => (int) $file->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			ISOFT_FMF_File_Manager::bust_cache_for( (int) $file->download_id, (int) $file->id );
			return true;
		}

		return false;
	}

	/**
	 * Cross-category inode hunt — scan the entire isoft-fmf-files tree for a candidate
	 * whose inode matches $file->inode and whose SHA-256 matches $file->file_hash.
	 * Returns the absolute path, or null.
	 */
	public static function find_by_inode_anywhere( object $file ): ?string {
		if ( ! (bool) get_option( 'isoft_fmf_integrity_use_inode', 1 ) ) {
			return null;
		}
		if ( empty( $file->inode ) || empty( $file->file_hash ) ) {
			return null;
		}

		$root = isoft_fmf_files_dir();
		if ( ! is_dir( $root ) ) {
			return null;
		}

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $it as $entry ) {
			if ( ! $entry->isFile() ) {
				continue;
			}
			$path = $entry->getPathname();
			$ino  = @fileinode( $path );
			if ( ! $ino || (int) $ino !== (int) $file->inode ) {
				continue;
			}
			$hash = @hash_file( 'sha256', $path );
			if ( $hash && hash_equals( (string) $file->file_hash, (string) $hash ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Cross-category content-hash hunt — like find_by_inode_anywhere but
	 * uses SHA-256 match instead of inode. Works on every filesystem
	 * including Windows / NTFS. Size pre-filter keeps the hashing cost
	 * proportional to files-of-matching-size, not total file count.
	 */
	public static function find_by_hash_anywhere( object $file ): ?string {
		if ( empty( $file->file_hash ) || empty( $file->file_size ) ) {
			return null;
		}

		$root = isoft_fmf_files_dir();
		if ( ! is_dir( $root ) ) {
			return null;
		}

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $it as $entry ) {
			if ( ! $entry->isFile() ) {
				continue;
			}
			if ( (int) $entry->getSize() !== (int) $file->file_size ) {
				continue;
			}
			$path = $entry->getPathname();
			$hash = @hash_file( 'sha256', $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $hash && hash_equals( (string) $file->file_hash, (string) $hash ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Find a missing file anywhere under the downloads root, regardless of
	 * filesystem. Tries inode first (zero hashing cost when it works), falls
	 * through to content hash. The Broken Links recovery dialog uses this.
	 */
	public static function find_anywhere( object $file ): ?string {
		return self::find_by_inode_anywhere( $file ) ?? self::find_by_hash_anywhere( $file );
	}

	/**
	 * Count of rows currently flagged as missing. Used for the menu badge.
	 */
	public static function missing_count(): int {
		$cached = get_transient( 'isoft_fmf_missing_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Badge counter on custom table; cached as isoft_fmf_missing_count transient.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files WHERE is_missing = 1" );
		set_transient( 'isoft_fmf_missing_count', $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}
}
