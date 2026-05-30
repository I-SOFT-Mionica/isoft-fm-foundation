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

class ISFM_File_Integrity {

	private const CRON_HOOK  = 'isfm_integrity_check';
	private const CHUNK_SIZE = 200;

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_check' ) );
		add_action( 'update_option_isfm_integrity_check_enabled', array( $this, 'reschedule' ), 10, 0 );
		add_action( 'update_option_isfm_integrity_check_time', array( $this, 'reschedule' ), 10, 0 );
		add_action( 'admin_post_isfm_integrity_check_now', array( $this, 'handle_run_now' ) );
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	public function maybe_schedule(): void {
		$enabled = (bool) get_option( 'isfm_integrity_check_enabled', 0 );
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
		$raw = (string) get_option( 'isfm_integrity_check_time', '02:30' );
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
				"{$wpdb->prefix}isfm_files",
				array(
					'is_missing'    => 1,
					'missing_since' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $file->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			ISFM_File_Manager::bust_cache_for( $download_id, (int) $file->id );
			delete_transient( 'isfm_missing_count' );
		}

		// Count remaining non-missing local files on this download.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count immediately after write; freshness required.
		$healthy = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}isfm_files
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
				update_post_meta( $download_id, '_isfm_auto_unpublished_at', time() );
			}
			$mode = 'unpublished';
		}

		// Idempotent notice: don't spam if we already queued one for this file.
		if ( empty( $file->is_missing ) ) {
			$url     = add_query_arg(
				array(
					'post_type' => 'isfm_file',
					'page'      => 'isfm-broken-links',
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
			isfm_notify_admin( $message, 'warning' );
		}

		do_action( 'isfm_file_missing', (int) $file->id, $download_id, 'serve' );

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

		$template = ISFM_PLUGIN_DIR . 'templates/file-unavailable.php';
		if ( file_exists( $template ) ) {
			$isfm_unavailable_post_id = $download_id;
			$isfm_unavailable_mode    = $mode;
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
		$summary = array(
			'checked'     => 0,
			'healed'      => 0,
			'relinked'    => 0,
			'still_gone'  => 0,
			'started_at'  => current_time( 'mysql' ),
			'finished_at' => null,
		);

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled scan of file rows; rows touched once per run, cache layer would never be hit.
		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}isfm_files
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
		update_option( 'isfm_integrity_last_run', $summary, false );

		if ( $summary['checked'] > 0 ) {
			isfm_notify_admin(
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

		delete_transient( 'isfm_missing_count' );
		do_action( 'isfm_integrity_check_complete', $summary );

		return $summary;
	}

	/**
	 * @return 'healed'|'relinked'|'still_gone'|'skipped'
	 */
	private function check_one( object $file ): string {
		if ( empty( $file->file_path ) ) {
			return 'skipped';
		}

		$abs = isfm_files_dir() . '/' . $file->file_path;

		if ( file_exists( $abs ) ) {
			if ( ! empty( $file->is_missing ) ) {
				$this->mark_healthy( $file );
				return 'healed';
			}
			return 'skipped';
		}

		// File is not at the expected path. Try inode-based rename recovery.
		$autorelink = (bool) get_option( 'isfm_integrity_autorelink', 1 );
		if ( $autorelink && $this->try_relink_by_inode( $file ) ) {
			$this->mark_healthy( $file );
			return 'relinked';
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
		if ( ! (bool) get_option( 'isfm_integrity_use_inode', 1 ) ) {
			return false;
		}
		if ( empty( $file->inode ) || empty( $file->file_hash ) ) {
			return false;
		}

		$term_id = $this->get_download_category_id( (int) $file->download_id );
		if ( ! $term_id ) {
			return false;
		}

		$category_fs = isfm_category_fs_path( $term_id );
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
				str_replace( '\\', '/', substr( $candidate, strlen( isfm_files_dir() ) ) ),
				'/'
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rename-recovery relink; cache invalidated below.
			$wpdb->update(
				"{$wpdb->prefix}isfm_files",
				array(
					'file_path' => $new_rel,
					'file_name' => basename( $candidate ),
				),
				array( 'id' => (int) $file->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			ISFM_File_Manager::bust_cache_for( (int) $file->download_id, (int) $file->id );
			return true;
		}

		return false;
	}

	private function mark_healthy( object $file ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$wpdb->update(
			"{$wpdb->prefix}isfm_files",
			array(
				'is_missing'    => 0,
				'missing_since' => null,
			),
			array( 'id' => (int) $file->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$download_id = (int) $file->download_id;
		ISFM_File_Manager::bust_cache_for( $download_id, (int) $file->id );
		delete_transient( 'isfm_missing_count' );
		$this->maybe_republish( $download_id );
	}

	/**
	 * If the integrity system was the one that unpublished this post, and no files
	 * remain flagged as missing, flip it back to 'publish' and clear the flag.
	 */
	private function maybe_republish( int $download_id ): void {
		$auto = get_post_meta( $download_id, '_isfm_auto_unpublished_at', true );
		if ( ! $auto ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count immediately after mark_healthy write; freshness required.
		$still_broken = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}isfm_files
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
		delete_post_meta( $download_id, '_isfm_auto_unpublished_at' );
	}

	private function get_download_category_id( int $download_id ): int {
		$terms = get_the_terms( $download_id, 'isfm_category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return 0;
		}
		return (int) $terms[0]->term_id;
	}

	// -------------------------------------------------------------------------
	// Admin: Run Now
	// -------------------------------------------------------------------------

	public function handle_run_now(): void {
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the integrity check.', 'isoft-fm-foundation' ) );
		}
		check_admin_referer( 'isfm_integrity_check_now' );

		$this->run_scheduled_check();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => 'isfm_file',
					'page'      => 'isfm-settings',
					'tab'       => 'maintenance',
					'isfm_ran'  => 1,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Utilities for Broken Links screen
	// -------------------------------------------------------------------------

	/**
	 * Cross-category inode hunt — scan the entire isfm-files tree for a candidate
	 * whose inode matches $file->inode and whose SHA-256 matches $file->file_hash.
	 * Returns the absolute path, or null.
	 */
	public static function find_by_inode_anywhere( object $file ): ?string {
		if ( ! (bool) get_option( 'isfm_integrity_use_inode', 1 ) ) {
			return null;
		}
		if ( empty( $file->inode ) || empty( $file->file_hash ) ) {
			return null;
		}

		$root = isfm_files_dir();
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
	 * Count of rows currently flagged as missing. Used for the menu badge.
	 */
	public static function missing_count(): int {
		$cached = get_transient( 'isfm_missing_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Badge counter on custom table; cached as isfm_missing_count transient.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isfm_files WHERE is_missing = 1" );
		set_transient( 'isfm_missing_count', $count, 5 * MINUTE_IN_SECONDS );
		return $count;
	}
}
