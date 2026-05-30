<?php
/**
 * Category folder manager.
 *
 * Maps every isfm_category term to a physical folder under isfm_files_dir().
 * The folder name is the category slug; nesting mirrors the category tree.
 *
 * Folder lifecycle:
 *   created  → folder created on disk
 *   edited   → if slug or parent changed, old folder is renamed, all
 *               isfm_files.file_path rows with the old prefix are updated
 *   deleted  → folder is LEFT on disk (files are the source of truth;
 *               a notice is shown if the folder is non-empty)
 */
defined( 'ABSPATH' ) || exit;

class ISFM_Category_Folders {

	/** Captured before term edit so we can detect slug / parent changes. */
	private static array $pre_edit_path = array();

	private static function wp_fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	public function register_hooks(): void {
		// Intercept the slug inside wp_insert_term() before it is stored.
		add_filter( 'pre_term_slug', array( $this, 'filter_pre_term_slug' ), 10, 2 );

		// Priority PHP_INT_MAX so we are the *last* callback — nothing else
		// can overwrite the slug after we rewrite it.
		add_action( 'created_isfm_category', array( $this, 'on_created' ), PHP_INT_MAX, 2 );
		add_action( 'edit_term', array( $this, 'before_edit' ), 10, 3 );
		add_action( 'edited_isfm_category', array( $this, 'on_edited' ), PHP_INT_MAX, 2 );
		add_action( 'pre_delete_term', array( $this, 'on_pre_delete' ), 10, 2 );
		// 'delete-tag' is WP core's own AJAX action for term deletion in the
		// admin (not our prefix). We hook in at priority 0 to short-circuit the
		// request when the category still has downloads attached.
		add_action( 'wp_ajax_delete-tag', array( $this, 'ajax_guard_delete' ), 0 );

		// Move files on disk when a download's category assignment changes.
		add_action( 'set_object_terms', array( $this, 'on_object_terms_set' ), 10, 6 );
	}

	/**
	 * Force-latinize the slug at the moment wp_insert_term() sanitizes it.
	 * If the user left the slug blank, wp_insert_term() derives it from the
	 * term name via sanitize_title(), which passes Cyrillic through unchanged.
	 */
	public function filter_pre_term_slug( string $slug, string $taxonomy ): string {
		if ( 'isfm_category' !== $taxonomy ) {
			return $slug;
		}

		// Empty slug → derive from the posted term name so we can transliterate
		// the raw Cyrillic *before* sanitize_title() URL-encodes it.
		if ( '' === $slug ) {
			$raw_name = '';
			// Nonce verified by WP core term creation/edit form.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			if ( ! empty( $_POST['tag-name'] ) ) {
				$raw_name = sanitize_text_field( wp_unslash( $_POST['tag-name'] ) );
			} elseif ( ! empty( $_POST['name'] ) ) {
				$raw_name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( '' !== $raw_name ) {
				$slug = sanitize_title( isfm_cyrillic_to_latin( $raw_name ) );
			}
		} else {
			// Non-empty slug — may already be URL-encoded Cyrillic.
			$decoded = urldecode( $slug );
			if ( preg_match( '/\p{Cyrillic}/u', $decoded ) ) {
				$slug = sanitize_title( isfm_cyrillic_to_latin( $decoded ) );
			}
		}

		return $slug;
	}

	/**
	 * Force a term's slug to ASCII-safe Latin by transliterating Cyrillic
	 * directly in the DB. Handles uniqueness by appending -2, -3, … on
	 * collision. Returns true when the slug was changed.
	 *
	 * This runs at the database level so it is immune to filter chains
	 * being disrupted by caching plugins, object-cache layers, or other
	 * code that replaces the slug after wp_insert_term_data fires.
	 */
	private static function force_latin_slug( int $term_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Slug inspection on wp_terms; WP core caches are busted via clean_term_cache() below.
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d", $term_id )
		);
		if ( ! $current ) {
			return false;
		}

		// sanitize_title() URL-encodes non-ASCII characters, so Cyrillic lands
		// in the DB as %d0%bf%d1%80… sequences. Decode before inspecting.
		$decoded = urldecode( $current );

		if ( ! preg_match( '/\p{Cyrillic}/u', $decoded ) ) {
			return false; // Nothing to do.
		}

		$latin = sanitize_title( isfm_cyrillic_to_latin( $decoded ) );

		if ( ! $latin || $latin === $current || $latin === $decoded ) {
			return false;
		}

		// Ensure uniqueness across all terms (WP enforces unique slugs globally).
		$unique = $latin;
		$i      = 2;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Slug uniqueness loop + direct update on wp_terms; WP core term cache flushed via clean_term_cache() below.
		while ( $wpdb->get_var(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->terms} WHERE slug = %s AND term_id <> %d LIMIT 1",
				$unique,
				$term_id
			)
		) ) {
			$unique = "{$latin}-{$i}";
			++$i;
		}

		$result = $wpdb->update(
			$wpdb->terms,
			array( 'slug' => $unique ),
			array( 'term_id' => $term_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_term_cache( $term_id, 'isfm_category' );
		return true;
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Create folder when a new category is saved for the first time.
	 * Unconditionally rewrites any Cyrillic slug to Latin at the DB level.
	 */
	public function on_created( int $term_id, int $tt_id ): void {
		unset( $tt_id );
		self::force_latin_slug( $term_id );
		self::ensure( $term_id );
	}

	/**
	 * Capture the current relative path BEFORE WordPress writes the update.
	 * At this point get_term() still returns the old slug / parent.
	 */
	public function before_edit( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );
		if ( 'isfm_category' !== $taxonomy ) {
			return;
		}
		self::$pre_edit_path[ $term_id ] = isfm_category_folder_path( $term_id );
	}

	/**
	 * After a category is updated: rewrite slug if Cyrillic, rename folder
	 * and update DB paths if the slug or parent changed.
	 */
	public function on_edited( int $term_id, int $tt_id ): void {
		unset( $tt_id );

		// Re-apply Latin slug enforcement on every edit.
		self::force_latin_slug( $term_id );

		if ( ! isset( self::$pre_edit_path[ $term_id ] ) ) {
			return;
		}

		$old_rel = self::$pre_edit_path[ $term_id ];
		$new_rel = isfm_category_folder_path( $term_id );

		unset( self::$pre_edit_path[ $term_id ] );

		// Always ensure the target folder exists (covers categories created
		// before this version that never got a folder).
		$new_fs = isfm_files_dir() . '/' . $new_rel;

		if ( $old_rel === $new_rel ) {
			if ( ! file_exists( $new_fs ) ) {
				wp_mkdir_p( $new_fs );
			}
			return;
		}

		$old_fs = isfm_files_dir() . '/' . $old_rel;

		if ( file_exists( $old_fs ) ) {
			wp_mkdir_p( dirname( $new_fs ) );

			if ( ! self::wp_fs()->move( $old_fs, $new_fs, false ) ) {
				add_action(
					'admin_notices',
					function () use ( $old_rel, $new_rel ): void {
						echo '<div class="notice notice-error"><p>' . sprintf(
						/* translators: 1: old folder path  2: new folder path */
							esc_html__( 'I-Soft File Manager: Foundation: could not rename folder "%1$s" to "%2$s". Please rename it manually, then re-save the category.', 'isoft-fm-foundation' ),
							esc_html( $old_rel ),
							esc_html( $new_rel )
						) . '</p></div>';
					}
				);
				return; // Don't update DB — paths still point to old location
			}
		} else {
			// Old folder never existed; create the new one.
			wp_mkdir_p( $new_fs );
		}

		// Update file_path records: replace old prefix with new prefix.
		global $wpdb;
		$old_prefix = "{$old_rel}/";
		$new_prefix = "{$new_rel}/";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk path rewrite on custom isfm_files table after folder rename; cache flushed below.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}isfm_files
				    SET file_path = CONCAT( %s, SUBSTRING( file_path, %d ) )
				  WHERE file_path LIKE %s",
				$new_prefix,
				strlen( $old_prefix ) + 1,
				$wpdb->esc_like( $old_prefix ) . '%'
			)
		);
		// Bulk update touched an unknown number of rows across multiple downloads — flush
		// the whole cache group so every download_id key is re-read on next hit.
		wp_cache_flush_group( ISFM_File_Manager::CACHE_GROUP );

		do_action( 'isfm_category_folder_renamed', $term_id, $old_rel, $new_rel );
	}

	/**
	 * AJAX guard: intercept delete-tag requests for isfm_category before WP core
	 * processes them. Sends a JSON error that the list table JS will display.
	 */
	public function ajax_guard_delete(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WP core verifies the nonce in its own delete-tag handler; we only read taxonomy + tag_ID to decide whether to block.
		$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		if ( 'isfm_category' !== $taxonomy ) {
			return;
		}

		$term_id = absint( $_POST['tag_ID'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $term_id ) {
			return;
		}

		$count = $this->count_downloads_in_category( $term_id );
		if ( $count < 1 ) {
			return;
		}

		$term = get_term( $term_id, 'isfm_category' );
		$name = $term ? $term->name : "#{$term_id}";

		$message = sprintf(
			/* translators: 1: category name, 2: number of downloads */
			_n(
				'Cannot delete "%1$s": %2$d download is still in this category. Move or delete it first.',
				'Cannot delete "%1$s": %2$d downloads are still in this category. Move or delete them first.',
				$count,
				'isoft-fm-foundation'
			),
			$name,
			$count
		);

		isfm_notify_admin( $message, 'error' );
		wp_die( '0' );
	}

	/**
	 * Before a term is deleted: block if any downloads are still assigned to it
	 * (would become orphaned), otherwise remove the folder if it is empty.
	 */
	public function on_pre_delete( int $term_id, string $taxonomy ): void {
		if ( 'isfm_category' !== $taxonomy ) {
			return;
		}

		$count = $this->count_downloads_in_category( $term_id );

		if ( $count > 0 ) {
			$term = get_term( $term_id, 'isfm_category' );
			$name = $term ? $term->name : "#{$term_id}";

			wp_die(
				sprintf(
					esc_html(
						/* translators: 1: category name, 2: number of downloads */
						_n(
							'Cannot delete "%1$s": %2$d download is still in this category. Move or delete it first.',
							'Cannot delete "%1$s": %2$d downloads are still in this category. Move or delete them first.',
							$count,
							'isoft-fm-foundation'
						)
					),
					esc_html( $name ),
					(int) $count
				),
				esc_html__( 'Category Not Empty', 'isoft-fm-foundation' ),
				array(
					'back_link' => true,
					'response'  => 409,
				)
			);
		}

		// No downloads — remove the folder if it is also empty on disk.
		$fs_path = isfm_category_fs_path( $term_id );
		if ( ! file_exists( $fs_path ) ) {
			return;
		}

		$contents = array_filter(
			(array) glob( "{$fs_path}/{,.}*", GLOB_BRACE ),
			fn ( string $p ): bool => ! in_array( basename( $p ), array( '.', '..' ), true )
		);

		if ( empty( $contents ) ) {
			self::wp_fs()->rmdir( $fs_path, false );
		} else {
			// Folder has leftover files not tracked in DB — leave it, warn admin.
			add_action(
				'admin_notices',
				function () use ( $fs_path ): void {
					echo '<div class="notice notice-warning"><p>' . sprintf(
					/* translators: %s: folder path */
						esc_html__( 'I-Soft File Manager: Foundation: the folder "%s" was not deleted because it still contains untracked files. Remove them manually.', 'isoft-fm-foundation' ),
						esc_html( $fs_path )
					) . '</p></div>';
				}
			);
		}
	}

	/**
	 * When a download's isfm_category assignment changes, move its files from
	 * the old category folder to the new one.
	 *
	 * Signature: set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids )
	 */
	public function on_object_terms_set(
		int $object_id,
		array $terms,
		array $tt_ids,
		string $taxonomy,
		bool $append,
		array $old_tt_ids
	): void {
		unset( $terms, $append );
		if ( 'isfm_category' !== $taxonomy ) {
			return;
		}
		if ( 'isfm_file' !== get_post_type( $object_id ) ) {
			return;
		}

		// Compare term_taxonomy_ids — if no change, nothing to do.
		sort( $tt_ids );
		sort( $old_tt_ids );
		if ( $tt_ids === $old_tt_ids ) {
			return;
		}

		// A download lives in exactly one category — take the first new one.
		if ( empty( $tt_ids ) ) {
			return;
		}

		$new_tt_id = (int) $tt_ids[0];
		$new_term  = get_term_by( 'term_taxonomy_id', $new_tt_id, 'isfm_category' );
		if ( ! $new_term || is_wp_error( $new_term ) ) {
			return;
		}

		$result = self::move_download_files( $object_id, (int) $new_term->term_id );

		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			add_action(
				'admin_notices',
				function () use ( $message ): void {
					echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
				}
			);
		}
	}

	private function count_downloads_in_category( int $term_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Pre-delete guard on core taxonomy tables; freshness required to block orphaning.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->term_relationships} tr
				   JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				  WHERE tt.term_id = %d
				    AND tt.taxonomy = 'isfm_category'",
				$term_id
			)
		);
	}

	// -------------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------------

	/**
	 * Ensure a category folder exists on disk.
	 * Call this before any upload to handle categories created before v0.2.1.
	 *
	 * @return bool  True if the folder exists (or was just created).
	 */
	public static function ensure( int $term_id ): bool {
		$path = isfm_category_fs_path( $term_id );
		if ( file_exists( $path ) ) {
			return true;
		}
		return wp_mkdir_p( $path );
	}

	/**
	 * Move all files belonging to a download from one category folder to another.
	 * Called when a download's category assignment changes.
	 *
	 * @return true|WP_Error
	 */
	public static function move_download_files( int $download_id, int $new_category_id ): true|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Category reassign walks files of one download; cache busted at end of loop.
		$files = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, file_path, file_name FROM {$wpdb->prefix}isfm_files WHERE download_id = %d",
				$download_id
			)
		);

		if ( empty( $files ) ) {
			return true;
		}

		self::ensure( $new_category_id );
		$new_folder = isfm_category_fs_path( $new_category_id );
		$base       = isfm_files_dir();

		foreach ( $files as $file ) {
			if ( empty( $file->file_path ) ) {
				continue;
			}

			$old_abs = "{$base}/{$file->file_path}";
			$new_abs = "{$new_folder}/{$file->file_name}";

			if ( ! file_exists( $old_abs ) ) {
				continue; // Already missing — skip, don't block
			}

			// Guard against overwriting an existing file in the target folder.
			if ( file_exists( $new_abs ) && $old_abs !== $new_abs ) {
				return new WP_Error(
					'isfm_collision',
					sprintf(
						/* translators: %s: filename */
						__( 'Cannot move "%s" — a file with that name already exists in the target category folder.', 'isoft-fm-foundation' ),
						esc_html( $file->file_name )
					)
				);
			}

			if ( ! self::wp_fs()->move( $old_abs, $new_abs, false ) ) {
				return new WP_Error(
					'isfm_rename_failed',
					sprintf(
						/* translators: %s: filename */
						__( 'Failed to move "%s" to the new category folder. Check folder permissions.', 'isoft-fm-foundation' ),
						esc_html( $file->file_name )
					)
				);
			}

			// Update the stored path
			$new_rel = isfm_category_folder_path( $new_category_id ) . '/' . $file->file_name;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Path rewrite on custom isfm_files table; cache busted below.
			$wpdb->update(
				"{$wpdb->prefix}isfm_files",
				array( 'file_path' => $new_rel ),
				array( 'id' => (int) $file->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		ISFM_File_Manager::bust_cache_for( $download_id );
		return true;
	}
}
