<?php
/**
 * Global helper functions available to Core and all extensions.
 *
 * Loaded directly (not via autoloader) so they are always available.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register an extension with Core. Called by Sentinel, Orbit, or third-party plugins
 * inside the 'isoft_fmf_extensions_init' action.
 *
 * @param array{slug:string, name:string, version:string, description?:string, author?:string, url?:string, settings_cb?:callable, admin_menu?:callable} $args
 */
function isoft_fmf_register_extension( array $args ): bool {
	return ISOFT_FMF_Extension_Api::register( $args );
}

/**
 * Create a draft download entry. Used by Sentinel and Orbit importers.
 *
 * @param array{title:string, description?:string, category_id?:int, access_role?:string, license_id?:int} $args
 * @return int|false  New post ID, or false on failure.
 */
function isoft_fmf_create_draft_download( array $args ): int|false {
	$title = sanitize_text_field( $args['title'] ?? '' );
	if ( ! $title ) {
		return false;
	}

	$postarr = array(
		'post_title'   => $title,
		'post_status'  => 'draft',
		'post_type'    => 'isoft_fmf_file',
		'post_content' => wp_kses_post( $args['description'] ?? '' ),
	);

	// Optional publication date, site time 'Y-m-d H:i:s'. Anything that is not
	// a real date in that format is ignored rather than guessed at.
	if ( ! empty( $args['post_date'] ) && is_string( $args['post_date'] ) ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $args['post_date'], wp_timezone() );
		if ( $date && $date->format( 'Y-m-d H:i:s' ) === $args['post_date'] ) {
			$postarr['post_date']     = $args['post_date'];
			$postarr['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
		}
	}

	$post_id = wp_insert_post( $postarr );

	if ( is_wp_error( $post_id ) ) {
		return false;
	}

	if ( ! empty( $args['category_id'] ) ) {
		wp_set_object_terms( $post_id, (int) $args['category_id'], 'isoft_fmf_category' );
	}
	if ( ! empty( $args['access_role'] ) ) {
		update_post_meta( $post_id, '_isoft_fmf_access_role', sanitize_text_field( $args['access_role'] ) );
	}
	if ( ! empty( $args['license_id'] ) ) {
		update_post_meta( $post_id, '_isoft_fmf_license_id', absint( $args['license_id'] ) );
	}

	return $post_id;
}

/**
 * Read Core settings as a flat array.
 *
 * @return array<string,mixed>
 */
/**
 * Render the icon for a category term, with a default-cat fallback.
 *
 * Stored value (`_isoft_fmf_cat_icon` term meta) is one of:
 *   - Empty → bundled default-category-icon.svg (cat-4 from svgrepo, CC0)
 *   - URL   → <img src="…">
 *   - Shortcode (starts with `[`) → do_shortcode(); lets admins reference
 *     a third-party icon plugin's output (e.g. [iconify icon="lucide:folder"])
 *     without us bundling an icon library
 *   - Dashicon class name → <span class="dashicons …">
 *
 * Output is echoed inside the existing .isoft-fmf-category-card__icon
 * wrapper at the call site. Caller is responsible for that wrapper.
 */
function isoft_fmf_render_category_icon( int $term_id ): void {
	$icon = (string) get_term_meta( $term_id, '_isoft_fmf_cat_icon', true );

	if ( '' === $icon ) {
		printf(
			'<img src="%s" alt="" class="isoft-fmf-category-card__icon-img isoft-fmf-category-card__icon-img--default" />',
			esc_url( ISOFT_FMF_PLUGIN_URL . 'public/images/default-category-icon.svg' )
		);
		return;
	}

	if ( filter_var( $icon, FILTER_VALIDATE_URL ) ) {
		printf(
			'<img src="%s" alt="" class="isoft-fmf-category-card__icon-img" />',
			esc_url( $icon )
		);
		return;
	}

	if ( str_starts_with( $icon, '[' ) && str_ends_with( $icon, ']' ) ) {
		// Third-party shortcode (Iconify, Better Font Awesome, etc.).
		// Output trusted to the shortcode plugin's own sanitisation.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_shortcode is the explicit hand-off to the icon plugin's escaping.
		echo do_shortcode( $icon );
		return;
	}

	printf( '<span class="dashicons %s"></span>', esc_attr( $icon ) );
}

/**
 * Plugin settings, read once per request.
 *
 * @param bool $refresh Re-read the options (after a save in the same request, or in tests).
 */
function isoft_fmf_get_settings( bool $refresh = false ): array {
	static $cached = null;
	if ( null !== $cached && ! $refresh ) {
		return $cached;
	}
	$cached = array(
		'default_access_role'       => get_option( 'isoft_fmf_default_access_role', 'public' ),
		'enable_counting'           => (bool) get_option( 'isoft_fmf_enable_counting', true ),
		'enable_logging'            => (bool) get_option( 'isoft_fmf_enable_logging', true ),
		'enable_detailed_logging'   => (bool) get_option( 'isoft_fmf_enable_detailed_logging', false ),
		'log_retention_days'        => (int) get_option( 'isoft_fmf_log_retention_days', 365 ),
		'enable_pdf_thumbnails'     => (bool) get_option( 'isoft_fmf_enable_pdf_thumbnails', false ),
		'pdf_thumb_width'           => (int) get_option( 'isoft_fmf_pdf_thumb_width', 300 ),
		'pdf_thumb_height'          => (int) get_option( 'isoft_fmf_pdf_thumb_height', 424 ),
		'pdf_thumb_quality'         => (int) get_option( 'isoft_fmf_pdf_thumb_quality', 85 ),
		'overwrite_pdf_thumbnail'   => (bool) get_option( 'isoft_fmf_overwrite_pdf_thumbnail', false ),
		'default_button_text'       => get_option( 'isoft_fmf_default_button_text', '' ),
		'allowed_extensions'        => array_values( array_filter( array_map( 'trim', explode( ',', get_option( 'isoft_fmf_allowed_extensions', 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,zip,rar,7z,jpg,jpeg,png,gif,webp,mp4,mp3,wav' ) ) ) ) ),
		'cyrillic_titles'           => (bool) get_option( 'isoft_fmf_cyrillic_titles', false ),
		'listing_layout'            => get_option( 'isoft_fmf_listing_layout', 'list' ),
		'items_per_page'            => (int) get_option( 'isoft_fmf_items_per_page', 10 ),
		'show_file_size'            => (bool) get_option( 'isoft_fmf_show_file_size', true ),
		'show_download_count'       => (bool) get_option( 'isoft_fmf_show_download_count', true ),
		'show_date'                 => (bool) get_option( 'isoft_fmf_show_date', true ),
		'date_format'               => get_option( 'isoft_fmf_date_format', get_option( 'date_format' ) ),
		'serve_method'              => get_option( 'isoft_fmf_serve_method', 'auto' ),
		'rate_limit_per_hour'       => (int) get_option( 'isoft_fmf_rate_limit_per_hour', 0 ),
		'hotlink_protection'        => (bool) get_option( 'isoft_fmf_hotlink_protection', false ),
		'cache_friendly_links'      => (bool) get_option( 'isoft_fmf_cache_friendly_links', false ),
		'public_api_enabled'        => (bool) get_option( 'isoft_fmf_public_api_enabled', false ),
		'public_api_cache_ttl'      => (int) get_option( 'isoft_fmf_public_api_cache_ttl', 300 ),
		'cache_friendly_rate_limit' => (int) get_option( 'isoft_fmf_cache_friendly_rate_limit', 120 ),
		'archive_slug'              => get_option( 'isoft_fmf_archive_slug', 'downloads' ),
		'category_slug'             => get_option( 'isoft_fmf_category_slug', 'download-category' ),
		'tag_slug'                  => get_option( 'isoft_fmf_tag_slug', 'download-tag' ),
		'delete_data_on_uninstall'  => (bool) get_option( 'isoft_fmf_delete_data_on_uninstall', false ),
	);
	return $cached;
}

/**
 * Render the lock + license chips that appear at the end of a download
 * card's meta row. Lock chip carries the human-readable role label
 * ("Subscriber+", etc.); license chip carries the resolved license title
 * (linking to its URL if one is set). Either or both can be empty —
 * each is rendered only when its underlying data is present, so a
 * public-no-license card produces nothing.
 *
 * Echoes HTML. Safe-by-construction (esc_html / esc_url / esc_attr).
 */
function isoft_fmf_render_card_lock_and_license( string $access_role, ?object $effective_license ): void {
	$role_label = isoft_fmf_access_role_label( $access_role );

	if ( 'public' !== $access_role ) :
		?>
		<span class="isoft-fmf-meta isoft-fmf-meta--lock" title="<?php echo esc_attr( $role_label ); ?>">
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			<?php if ( $role_label ) : ?>
				<span class="isoft-fmf-meta__label"><?php echo esc_html( $role_label ); ?></span>
			<?php endif; ?>
		</span>
		<?php
	endif;

	if ( $effective_license ) :
		?>
		<span class="isoft-fmf-meta isoft-fmf-meta--license" title="<?php echo esc_attr( $effective_license->title ); ?>">
			<span class="dashicons dashicons-media-document" aria-hidden="true"></span>
			<?php if ( ! empty( $effective_license->url ) ) : ?>
				<a href="<?php echo esc_url( $effective_license->url ); ?>" target="_blank" rel="noopener nofollow license"><?php echo esc_html( $effective_license->title ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $effective_license->title ); ?>
			<?php endif; ?>
		</span>
		<?php
	endif;
}

/**
 * Human-readable label for an access role key. Used on the front-end card
 * next to the lock chip so visitors know what minimum role is required
 * ("Subscriber+" rather than a bare lock icon). Mirrors the labels in
 * ISOFT_FMF_Taxonomy::render_access_role_select() — single source of truth
 * would be nicer but the taxonomy method is private and only renders, so
 * duplicating four short strings here is the lighter touch.
 *
 * Returns an empty string for unknown / public so callers can null-check.
 */
function isoft_fmf_access_role_label( string $role ): string {
	$labels = array(
		'subscriber'    => __( 'Subscriber+', 'isoft-fm-foundation' ),
		'contributor'   => __( 'Contributor+', 'isoft-fm-foundation' ),
		'author'        => __( 'Author+', 'isoft-fm-foundation' ),
		'editor'        => __( 'Editor+', 'isoft-fm-foundation' ),
		'administrator' => __( 'Administrator only', 'isoft-fm-foundation' ),
	);
	return $labels[ $role ] ?? '';
}

/**
 * Queue an admin dashboard notice for the current user.
 *
 * @param string $message  Plain text message.
 * @param string $type     'info' | 'success' | 'warning' | 'error'
 */
function isoft_fmf_notify_admin( string $message, string $type = 'info' ): void {
	$notices   = get_option( 'isoft_fmf_admin_notices', array() );
	$notices[] = array(
		'message' => $message,
		'type'    => in_array( $type, array( 'info', 'success', 'warning', 'error' ), true ) ? $type : 'info',
		'time'    => time(),
	);
	update_option( 'isoft_fmf_admin_notices', $notices );
}

/**
 * Convert a flat key=>value array into a shortcode attribute string.
 * e.g. ['limit' => 5, 'layout' => 'grid'] → ' limit="5" layout="grid"'
 *
 * @param array<string,scalar> $atts
 */
function isoft_fmf_atts_to_string( array $atts ): string {
	$parts = array();
	foreach ( $atts as $key => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		$parts[] = sanitize_key( $key ) . '="' . esc_attr( $value ) . '"';
	}
	return $parts ? ' ' . implode( ' ', $parts ) : '';
}

// ─────────────────────────────────────────────────────────────────────────────
// File storage paths
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Absolute filesystem path to the isoft-fmf-files/ storage root.
 */
function isoft_fmf_files_dir(): string {
	static $dir = null;
	if ( null === $dir ) {
		$dir = wp_upload_dir()['basedir'] . '/isoft-fmf-files';
	}
	return $dir;
}

/**
 * Build the relative folder path for a category by walking its ancestor chain.
 * e.g. "skupstina-opstine/saziv-2025-2029/iv-sednica"
 */
function isoft_fmf_category_folder_path( int $term_id ): string {
	$parts = array();
	$id    = $term_id;
	while ( $id ) {
		$term = get_term( $id, 'isoft_fmf_category' );
		if ( ! $term || is_wp_error( $term ) ) {
			break;
		}
		array_unshift( $parts, $term->slug );
		$id = (int) $term->parent;
	}
	return implode( '/', $parts );
}

/**
 * Absolute filesystem path for a category's storage folder.
 */
function isoft_fmf_category_fs_path( int $term_id ): string {
	return isoft_fmf_files_dir() . '/' . isoft_fmf_category_folder_path( $term_id );
}

// ─────────────────────────────────────────────────────────────────────────────
// Filename sanitization pipeline
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Sanitize an uploaded filename for safe disk storage.
 *
 * Pipeline:
 *   1. Strip duplicate extension  (file.pdf.pdf → file.pdf)
 *   2. Split stem + extension, lowercase extension
 *   3. Check extension against the allow-list in settings
 *   4. Transliterate Cyrillic → Latin on the stem
 *   5. remove_accents() for Latin diacritics (š→s, ž→z, ć→c …)
 *   6. Slugify (lowercase, non-alphanumeric → dash, collapse dashes)
 *   7. Check slug length (max 80 chars)
 *
 * @return array{slug:string, ext:string, original_title:string, error:string|null}
 *   slug           — safe disk filename   (e.g. "odluka-o-budzetu-2026.pdf")
 *   ext            — lowercase extension without dot
 *   original_title — original stem kept for title autofill
 *   error          — human-readable error string, or null on success
 */
function isoft_fmf_sanitize_filename( string $original_name ): array {
	// 1. Strip duplicate extension
	$name = isoft_fmf_strip_double_extension( $original_name );

	// 2. Split
	$ext           = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$original_stem = pathinfo( $name, PATHINFO_FILENAME );

	// 3. Extension allow-list
	$allowed = isoft_fmf_get_settings()['allowed_extensions'];
	if ( ! empty( $allowed ) && ! in_array( $ext, $allowed, true ) ) {
		return array(
			'slug'           => '',
			'ext'            => $ext,
			'original_title' => $original_stem,
			'error'          => sprintf(
				/* translators: 1: extension, 2: comma-separated allowed list */
				__( 'File type ".%1$s" is not allowed. Permitted types: %2$s', 'isoft-fm-foundation' ),
				$ext,
				implode( ', ', array_map( fn( $e ) => ".{$e}", $allowed ) )
			),
		);
	}

	// 4–6. Transliterate + diacritics + slugify
	$slug_stem = isoft_fmf_cyrillic_to_latin( $original_stem );
	$slug_stem = remove_accents( $slug_stem );
	$slug_stem = strtolower( $slug_stem );
	$slug_stem = preg_replace( '/[^a-z0-9]+/', '-', $slug_stem );
	$slug_stem = trim( $slug_stem, '-' );

	if ( '' === $slug_stem ) {
		$slug_stem = 'file';
	}

	// 7. Length check (stem only)
	if ( mb_strlen( $slug_stem ) > 80 ) {
		return array(
			'slug'           => '',
			'ext'            => $ext,
			'original_title' => $original_stem,
			'error'          => sprintf(
				/* translators: %d: character count */
				__( 'Filename is too long (%d characters after sanitization). Please shorten it to 80 characters or fewer before uploading.', 'isoft-fm-foundation' ),
				mb_strlen( $slug_stem )
			),
		);
	}

	return array(
		'slug'           => $ext ? "{$slug_stem}.{$ext}" : $slug_stem,
		'ext'            => $ext,
		'original_title' => $original_stem,
		'error'          => null,
	);
}

/**
 * Strip a duplicate final extension.
 * "file.pdf.pdf" → "file.pdf"   Only fires when the two trailing extensions match.
 */
function isoft_fmf_strip_double_extension( string $filename ): string {
	$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$stem = pathinfo( $filename, PATHINFO_FILENAME );
	if ( $ext !== '' && strtolower( pathinfo( $stem, PATHINFO_EXTENSION ) ) === $ext ) {
		return pathinfo( $stem, PATHINFO_FILENAME ) . '.' . $ext;
	}
	return $filename;
}

/**
 * Check whether a slug already exists in a category's folder on disk.
 * Used to give a blocking error before writing the file.
 */
function isoft_fmf_filename_collision( string $slug, int $category_id ): bool {
	$path = isoft_fmf_category_fs_path( $category_id ) . '/' . $slug;
	return file_exists( $path );
}

/**
 * Whether $path lies inside $base, compared on a directory boundary. Both
 * should already be realpath()-resolved. A plain str_starts_with() would
 * accept a sibling such as ".../isoft-fmf-files-backup" for the base
 * ".../isoft-fmf-files".
 */
function isoft_fmf_path_within( string $path, string $base ): bool {
	$base = rtrim( $base, '/\\' );
	if ( '' === $base ) {
		return false;
	}
	return $path === $base || str_starts_with( $path, $base . '/' ) || str_starts_with( $path, $base . DIRECTORY_SEPARATOR );
}

// ─────────────────────────────────────────────────────────────────────────────
// Public API for extensions (see docs/PUBLIC-API.md). Signatures are stable.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * ID of a stored local file with this SHA-256, or 0 (also for input that is
 * not 64 hex characters). Lets a caller skip re-uploading identical content.
 */
function isoft_fmf_file_exists_by_hash( string $sha256 ): int {
	$sha256 = strtolower( trim( $sha256 ) );
	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
		return 0;
	}
	return ( new ISOFT_FMF_File_Manager() )->find_id_by_hash( $sha256 );
}

/**
 * Place a file that already exists on the server into the download's
 * category folder and register it. PHP only: not reachable over REST or AJAX.
 *
 * @param int    $download_id Download post ID; it needs a category.
 * @param string $source_abs  Absolute path of a regular file (symlinks are refused).
 * @param array  $args {
 *     @type string $mode          'copy' (default), 'move' (source removed only after the row is saved)
 *                                 or 'in_place' (source already sits directly in the download's category
 *                                 folder; renamed to its sanitized name if needed, then registered).
 *     @type string $original_name Name to sanitize. Default: basename of the source.
 *     @type string $title         Default: isoft_fmf_autofill_title() of the name.
 *     @type array  $context       Passed to the isoft_fmf_pre_attach_file filter, e.g. array( 'origin' => 'sentinel' ).
 * }
 * @return int|WP_Error New file ID. A name clash is the isoft_fmf_filename_collision error; nothing is renamed silently.
 */
function isoft_fmf_attach_file( int $download_id, string $source_abs, array $args = array() ): int|WP_Error {
	return ( new ISOFT_FMF_Files_Service() )->attach( $download_id, $source_abs, $args );
}

/**
 * Local file rows in ID order, for extensions that sweep storage in batches.
 * Keep your own cursor (the last ID you saw).
 *
 * @param int $after_id Return rows with an ID greater than this.
 * @param int $limit    1 to 1000, default 200.
 * @return array<int, array{id:int, download_id:int, abs_path:string, file_hash:string, file_size:int, is_missing:bool}>
 */
function isoft_fmf_get_local_files( int $after_id = 0, int $limit = 200 ): array {
	$rows = ( new ISOFT_FMF_File_Manager() )->list_local_after( max( 0, $after_id ), max( 1, min( 1000, $limit ) ) );
	$base = isoft_fmf_files_dir();
	$out  = array();
	foreach ( $rows as $row ) {
		$out[] = array(
			'id'          => (int) $row->id,
			'download_id' => (int) $row->download_id,
			'abs_path'    => $base . '/' . ltrim( (string) $row->file_path, '/' ),
			'file_hash'   => (string) $row->file_hash,
			'file_size'   => (int) $row->file_size,
			'is_missing'  => (bool) $row->is_missing,
		);
	}
	return $out;
}

/**
 * Category term ID that owns a storage folder, or 0. The reverse of
 * isoft_fmf_category_folder_path(): walks the slug chain from the top.
 * Pending categories count; this is a lookup, not a visibility check.
 *
 * @param string $rel_folder Folder relative to isoft_fmf_files_dir(), no leading slash.
 */
function isoft_fmf_category_for_folder( string $rel_folder ): int {
	$rel_folder = trim( str_replace( '\\', '/', $rel_folder ), '/' );
	if ( '' === $rel_folder ) {
		return 0;
	}
	$parent = 0;
	foreach ( explode( '/', $rel_folder ) as $segment ) {
		if ( '' === $segment || '.' === $segment || '..' === $segment ) {
			return 0;
		}
		$ids = get_terms(
			array(
				'taxonomy'                  => 'isoft_fmf_category',
				'slug'                      => $segment,
				'parent'                    => $parent,
				'hide_empty'                => false,
				'number'                    => 1,
				'fields'                    => 'ids',
				'isoft_fmf_include_pending' => true,
			)
		);
		if ( is_wp_error( $ids ) || empty( $ids ) ) {
			return 0;
		}
		$parent = (int) $ids[0];
	}
	return $parent;
}

/**
 * ID of the file row registered at this path, or 0.
 *
 * @param string $rel_path Path relative to isoft_fmf_files_dir().
 */
function isoft_fmf_file_for_path( string $rel_path ): int {
	$rel_path = ltrim( str_replace( '\\', '/', $rel_path ), '/' );
	if ( '' === $rel_path ) {
		return 0;
	}
	return ( new ISOFT_FMF_File_Manager() )->find_id_by_path( $rel_path );
}

/**
 * Accept a stored file's current content as its new baseline: re-hash the
 * file on disk and save the hash and size. Use after content changed on disk
 * on purpose (an approved replacement) so integrity checks stop flagging it.
 * Runs the isoft_fmf_pre_attach_file veto first (origin 'accept').
 *
 * @return array{changed:bool, file_hash:string, file_size:int}|WP_Error
 */
function isoft_fmf_accept_file_content( int $file_id ): array|WP_Error {
	$manager = new ISOFT_FMF_File_Manager();
	$file    = $manager->get_file( $file_id );
	if ( ! $file || 'local' !== $file->file_type || empty( $file->file_path ) ) {
		return new WP_Error( 'isoft_fmf_file_not_found', __( 'File not found.', 'isoft-fm-foundation' ), array( 'status' => 404 ) );
	}

	$base = realpath( isoft_fmf_files_dir() );
	$raw  = isoft_fmf_files_dir() . '/' . $file->file_path;
	$abs  = realpath( $raw );
	if ( ! $base || ! $abs || is_link( $raw ) || ! isoft_fmf_path_within( $abs, $base ) || ! is_file( $abs ) ) {
		return new WP_Error( 'isoft_fmf_file_not_on_disk', __( 'The stored file is not on disk.', 'isoft-fm-foundation' ), array( 'status' => 404 ) );
	}

	$refused = ISOFT_FMF_Files_Service::check_attach_allowed(
		$abs,
		array(
			'download_id'   => (int) $file->download_id,
			'file_id'       => $file_id,
			'origin'        => 'accept',
			'original_name' => basename( $abs ),
		)
	);
	if ( $refused ) {
		return $refused;
	}

	$hash = (string) hash_file( 'sha256', $abs );
	$size = (int) filesize( $abs );
	if ( $hash === (string) $file->file_hash && $size === (int) $file->file_size ) {
		return array(
			'changed'   => false,
			'file_hash' => $hash,
			'file_size' => $size,
		);
	}

	$manager->update_content( $file_id, $hash, $size );

	/** This action is documented in includes/class-broken-links-service.php */
	do_action( 'isoft_fmf_file_replaced', $file_id, (int) $file->download_id );
	isoft_fmf_content_changed( 'download', (int) $file->download_id );

	return array(
		'changed'   => true,
		'file_hash' => $hash,
		'file_size' => $size,
	);
}

/**
 * Point a file row at the place its file was moved to inside storage. A move
 * within the same category folder updates the row; a move into another
 * category's folder also moves the download (and its other files) to that
 * category, exactly as the Broken Links "reassign" recovery does.
 *
 * @param int    $file_id      File row ID.
 * @param string $new_rel_path New location, relative to isoft_fmf_files_dir().
 * @return true|WP_Error
 */
function isoft_fmf_relink_file( int $file_id, string $new_rel_path ): bool|WP_Error {
	return ( new ISOFT_FMF_Broken_Links_Service() )->relink( $file_id, $new_rel_path );
}

/**
 * Make a file unavailable (kept and listed, but its button is passive and the
 * download handler refuses it) or available again. For holding a file whose
 * content is in question without deleting it.
 */
function isoft_fmf_set_file_unavailable( int $file_id, bool $unavailable ): bool {
	return ( new ISOFT_FMF_File_Manager() )->set_unavailable( $file_id, $unavailable );
}

/**
 * Whether a file is marked unavailable.
 */
function isoft_fmf_file_is_unavailable( int $file_id ): bool {
	$file = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
	return $file && ! empty( $file->is_unavailable );
}

/**
 * Text shown in place of the download button for a file that cannot be
 * downloaded right now (missing on disk or marked unavailable). Site owners
 * set it under Settings > Display.
 */
function isoft_fmf_unavailable_label(): string {
	$label = trim( (string) get_option( 'isoft_fmf_unavailable_label', '' ) );
	return '' !== $label ? $label : __( 'Temporarily unavailable', 'isoft-fm-foundation' );
}

/**
 * Whether a category is visible to an anonymous visitor: neither it nor any
 * ancestor requires a role, and it is not pending (or beneath a pending one).
 */
function isoft_fmf_category_is_public( int $term_id ): bool {
	$term = get_term( $term_id, 'isoft_fmf_category' );
	if ( ! $term instanceof WP_Term || ISOFT_FMF_Category_Pending::is_hidden( $term_id ) ) {
		return false;
	}
	$guard = 0;
	while ( $term instanceof WP_Term && $guard++ < 50 ) {
		$role = (string) get_term_meta( $term->term_id, '_isoft_fmf_cat_access_role', true );
		if ( '' !== $role && 'public' !== $role ) {
			return false;
		}
		$term = $term->parent ? get_term( (int) $term->parent, 'isoft_fmf_category' ) : null;
	}
	return true;
}

/**
 * Whether an anonymous visitor may see and download a download: published,
 * no password, effective access role public, and not filed under a pending
 * category. The single rule for "public"; extensions and headless front ends
 * call this instead of re-implementing it.
 */
function isoft_fmf_download_is_public( int $download_id ): bool {
	$post   = get_post( $download_id );
	$public = $post instanceof WP_Post
		&& 'isoft_fmf_file' === $post->post_type
		&& 'publish' === $post->post_status
		&& '' === $post->post_password
		&& 'public' === ( new ISOFT_FMF_Access_Control() )->effective_role_for( $download_id );

	if ( $public && ISOFT_FMF_Category_Pending::ids() ) {
		$terms = wp_get_object_terms( $download_id, 'isoft_fmf_category', array( 'fields' => 'ids' ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
			if ( ISOFT_FMF_Category_Pending::is_hidden( (int) $term_id ) ) {
				$public = false;
				break;
			}
		}
	}

	/**
	 * Filters whether a download counts as public (visible and downloadable
	 * by an anonymous visitor).
	 *
	 * @param bool $public      Result of Foundation's rule.
	 * @param int  $download_id Download post ID.
	 */
	return (bool) apply_filters( 'isoft_fmf_download_is_public', $public, $download_id );
}

/**
 * A local file an anonymous visitor may download, with where it lives, or
 * null. Applies the whole rule: the download is public, the file is local,
 * not missing, not marked unavailable, not hidden by "external links only",
 * and its path resolves to a regular file inside storage.
 *
 * @return array{id:int, download_id:int, abs_path:string, rel_path:string, name:string, size:int, mime:string, sha256:string}|null
 */
function isoft_fmf_public_file( int $file_id ): ?array {
	$file = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
	if ( ! $file || 'local' !== $file->file_type || empty( $file->file_path ) || ! empty( $file->is_missing ) || ! empty( $file->is_unavailable ) ) {
		return null;
	}
	$download_id = (int) $file->download_id;
	if ( get_post_meta( $download_id, '_isoft_fmf_external_only', true ) || ! isoft_fmf_download_is_public( $download_id ) ) {
		return null;
	}

	$base = realpath( isoft_fmf_files_dir() );
	$raw  = isoft_fmf_files_dir() . '/' . $file->file_path;
	$abs  = realpath( $raw );
	if ( ! $base || ! $abs || is_link( $raw ) || ! isoft_fmf_path_within( $abs, $base ) || ! is_file( $abs ) ) {
		return null;
	}

	return array(
		'id'          => (int) $file->id,
		'download_id' => $download_id,
		'abs_path'    => $abs,
		'rel_path'    => (string) $file->file_path,
		'name'        => (string) $file->file_name,
		'size'        => (int) $file->file_size,
		'mime'        => (string) $file->file_mime,
		'sha256'      => (string) $file->file_hash,
	);
}

/**
 * What this Foundation can do, as stable feature identifiers. Code running in
 * the same WordPress (a headless module, an extension) checks these instead
 * of function_exists() or version numbers; clients outside WordPress read the
 * same list from GET /public/info.
 *
 * Identifiers are only ever added, never renamed or removed without a
 * deprecation release.
 *
 * @return string[]
 */
function isoft_fmf_features(): array {
	$features = array(
		'public_api',
		'gates',
		'record_downloads',
		'record_idempotency',
		'content_changed',
		'pending_categories',
		'unavailable_files',
		'category_path',
		'public_rule',
		'attach_file',
		'pre_attach_veto',
		'file_state',
		'thumbnail_sizes',
	);

	/**
	 * Filters the feature list (isoft_fmf_features() and GET /public/info).
	 * Extensions may add their own identifiers, prefixed with their slug.
	 *
	 * @param string[] $features Feature identifiers.
	 */
	$features = (array) apply_filters( 'isoft_fmf_features', $features );
	return array_values( array_unique( array_map( 'strval', $features ) ) );
}

/**
 * Whether this Foundation has a feature (see isoft_fmf_features()).
 */
function isoft_fmf_has_feature( string $feature ): bool {
	return in_array( $feature, isoft_fmf_features(), true );
}

/**
 * Slug of Foundation's top-level admin menu (the Downloads menu). Extensions
 * add their screens as submenus of it.
 */
function isoft_fmf_admin_parent_slug(): string {
	return 'edit.php?post_type=isoft_fmf_file';
}

/**
 * Mark a category as pending (hidden from the public until approved) or
 * approve it. See ISOFT_FMF_Category_Pending.
 */
function isoft_fmf_set_category_pending( int $term_id, bool $pending ): bool {
	return ISOFT_FMF_Category_Pending::set( $term_id, $pending );
}

/**
 * Whether a category is pending approval.
 */
function isoft_fmf_category_is_pending( int $term_id ): bool {
	return ISOFT_FMF_Category_Pending::is_pending( $term_id );
}

// ─────────────────────────────────────────────────────────────────────────────
// Serbian transliteration
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Transliterate Serbian Cyrillic → Serbian Latin.
 * Digraphs (Љ Њ Џ) are in the map as multi-char keys; PHP's strtr() tries
 * longer keys first, so they are matched before their component characters.
 */
function isoft_fmf_cyrillic_to_latin( string $text ): string {
	static $map = null;
	if ( null === $map ) {
		$map = array(
			// Digraphs — uppercase
			'Љ' => 'Lj',
			'Њ' => 'Nj',
			'Џ' => 'Dž',
			// Digraphs — lowercase
			'љ' => 'lj',
			'њ' => 'nj',
			'џ' => 'dž',
			// Singles — uppercase
			'А' => 'A',
			'Б' => 'B',
			'В' => 'V',
			'Г' => 'G',
			'Д' => 'D',
			'Ђ' => 'Đ',
			'Е' => 'E',
			'Ж' => 'Ž',
			'З' => 'Z',
			'И' => 'I',
			'Ј' => 'J',
			'К' => 'K',
			'Л' => 'L',
			'М' => 'M',
			'Н' => 'N',
			'О' => 'O',
			'П' => 'P',
			'Р' => 'R',
			'С' => 'S',
			'Т' => 'T',
			'Ћ' => 'Ć',
			'У' => 'U',
			'Ф' => 'F',
			'Х' => 'H',
			'Ц' => 'C',
			'Ч' => 'Č',
			'Ш' => 'Š',
			// Singles — lowercase
			'а' => 'a',
			'б' => 'b',
			'в' => 'v',
			'г' => 'g',
			'д' => 'd',
			'ђ' => 'đ',
			'е' => 'e',
			'ж' => 'ž',
			'з' => 'z',
			'и' => 'i',
			'ј' => 'j',
			'к' => 'k',
			'л' => 'l',
			'м' => 'm',
			'н' => 'n',
			'о' => 'o',
			'п' => 'p',
			'р' => 'r',
			'с' => 's',
			'т' => 't',
			'ћ' => 'ć',
			'у' => 'u',
			'ф' => 'f',
			'х' => 'h',
			'ц' => 'c',
			'ч' => 'č',
			'ш' => 'š',
		);
	}
	return strtr( $text, $map );
}

/**
 * Convert Serbian Latin → Serbian Cyrillic.
 * Used for title autofill when the "Cyrillic titles" setting is on.
 * Handles both plain-ASCII Latin and pre-diacritic forms (š, ž, ć, č, đ, dž).
 * Result is always editable — this is a best-effort autofill, not a translation.
 */
function isoft_fmf_latin_to_cyrillic( string $text ): string {
	static $map = null;
	if ( null === $map ) {
		$map = array(
			// Digraphs — uppercase (longest first so strtr matches before singles)
			'Lj' => 'Љ',
			'LJ' => 'Љ',
			'Nj' => 'Њ',
			'NJ' => 'Њ',
			'Dž' => 'Џ',
			'DŽ' => 'Џ',
			'Dz' => 'Џ',
			'DZ' => 'Џ',
			// Digraphs — lowercase
			'lj' => 'љ',
			'nj' => 'њ',
			'dž' => 'џ',
			'dz' => 'џ',
			// Singles — uppercase
			'A'  => 'А',
			'B'  => 'Б',
			'V'  => 'В',
			'G'  => 'Г',
			'D'  => 'Д',
			'Đ'  => 'Ђ',
			'E'  => 'Е',
			'Ž'  => 'Ж',
			'Z'  => 'З',
			'I'  => 'И',
			'J'  => 'Ј',
			'K'  => 'К',
			'L'  => 'Л',
			'M'  => 'М',
			'N'  => 'Н',
			'O'  => 'О',
			'P'  => 'П',
			'R'  => 'Р',
			'S'  => 'С',
			'T'  => 'Т',
			'Ć'  => 'Ћ',
			'U'  => 'У',
			'F'  => 'Ф',
			'H'  => 'Х',
			'C'  => 'Ц',
			'Č'  => 'Ч',
			'Š'  => 'Ш',
			// Singles — lowercase
			'a'  => 'а',
			'b'  => 'б',
			'v'  => 'в',
			'g'  => 'г',
			'd'  => 'д',
			'đ'  => 'ђ',
			'e'  => 'е',
			'ž'  => 'ж',
			'z'  => 'з',
			'i'  => 'и',
			'j'  => 'ј',
			'k'  => 'к',
			'l'  => 'л',
			'm'  => 'м',
			'n'  => 'н',
			'o'  => 'о',
			'p'  => 'п',
			'r'  => 'р',
			's'  => 'с',
			't'  => 'т',
			'ć'  => 'ћ',
			'u'  => 'у',
			'f'  => 'ф',
			'h'  => 'х',
			'c'  => 'ц',
			'č'  => 'ч',
			'š'  => 'ш',
		);
	}
	return strtr( $text, $map );
}

/**
 * Autofill a download title from an original filename stem.
 * If the "Cyrillic titles" setting is on, attempts Latin → Cyrillic conversion.
 * Numbers, parentheses, hyphens, and non-Latin characters pass through unchanged.
 */
function isoft_fmf_autofill_title( string $original_stem ): string {
	$title = trim( $original_stem );
	if ( isoft_fmf_get_settings()['cyrillic_titles'] ) {
		$title = isoft_fmf_latin_to_cyrillic( $title );
	}
	return $title;
}

// ─────────────────────────────────────────────────────────────────────────────
// Existing helpers continue below
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Map a file extension to a CSS icon class used by the download card.
 */
function isoft_fmf_mime_icon_class( string $ext ): string {
	$map = array(
		'pdf'  => 'pdf',
		'doc'  => 'doc',
		'docx' => 'doc',
		'xls'  => 'xls',
		'xlsx' => 'xls',
		'ppt'  => 'ppt',
		'pptx' => 'ppt',
		'zip'  => 'zip',
		'rar'  => 'zip',
		'7z'   => 'zip',
		'jpg'  => 'img',
		'jpeg' => 'img',
		'png'  => 'img',
		'gif'  => 'img',
		'webp' => 'img',
		'mp4'  => 'vid',
		'avi'  => 'vid',
		'mov'  => 'vid',
		'mp3'  => 'aud',
		'wav'  => 'aud',
	);
	return $map[ strtolower( $ext ) ] ?? 'file';
}

/**
 * Aggregate stats for the admin dashboard widget and the REST overview endpoint.
 * Cached for 5 minutes — second-precision freshness is not a requirement here.
 *
 * @return array{
 *     total_downloads:int,
 *     total_files:int,
 *     total_size_bytes:int,
 *     total_log_entries:int,
 *     top_alltime:array<object>,
 *     top_30d:array<object>,
 *     top_30d_window:string,
 *     daily_30d:array<object>
 * }
 */
function isoft_fmf_get_stats_overview(): array {
	$cached = get_transient( 'isoft_fmf_stats_overview' );
	if ( false !== $cached ) {
		return $cached;
	}

	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate dashboard query; result cached as 'isoft_fmf_stats_overview' transient for 5 minutes (acceptable freshness for stats).

	$top_alltime = $wpdb->get_results(
		"SELECT p.ID, p.post_title, COALESCE(SUM(f.download_count),0) AS total_count
		   FROM {$wpdb->posts} p
		   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.download_id = p.ID
		  WHERE p.post_type = 'isoft_fmf_file' AND p.post_status = 'publish'
		  GROUP BY p.ID, p.post_title
		  ORDER BY total_count DESC
		  LIMIT 10"
	) ?: array();

	// Top 30d and the daily chart both read from isoft_fmf_download_daily
	// (the aggregate table the logger maintains alongside the per-click
	// log). It's the canonical source for time-bucketed counts — faster
	// than scanning the full log, and the only table the HOT cron uses
	// for the same reason. The per-click isoft_fmf_download_log table
	// remains the source for the Log viewer (audit trail per event).
	// INNER JOIN (not LEFT JOIN) so orphan daily rows whose post has been
	// deleted are excluded — without this, the dashboard surfaced them as
	// "(deleted)" lines with their original counts intact. Future-proofing:
	// the before_delete_post cleanup in class-post-type.php sweeps daily
	// entries as posts are deleted going forward, but the INNER JOIN keeps
	// the display honest even if a row escapes that cleanup path (direct
	// SQL deletion, restored-from-backup state, etc.).
	$top_30d = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT d.download_id, p.post_title, SUM(d.count) AS count
			   FROM {$wpdb->prefix}isoft_fmf_download_daily d
			   INNER JOIN {$wpdb->posts} p ON p.ID = d.download_id
			  WHERE d.log_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			  GROUP BY d.download_id, p.post_title
			  ORDER BY count DESC
			  LIMIT 10",
			30
		)
	) ?: array();

	// New / low-traffic install: 30-day daily aggregate has nothing. Surface
	// the all-time list under the 30-day panel so the dashboard isn't blank,
	// and record which window was actually used so the view can label it
	// honestly ("Top Downloads (all-time)" instead of pretending it's 30d).
	$top_30d_window = '30d';
	if ( empty( $top_30d ) && ! empty( $top_alltime ) ) {
		$top_30d        = array_map(
			static function ( $row ) {
				return (object) array(
					'download_id' => (int) $row->ID,
					'post_title'  => $row->post_title,
					'count'       => (int) $row->total_count,
				);
			},
			$top_alltime
		);
		$top_30d_window = 'alltime';
	}

	$data = array(
		'total_downloads'   => (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'isoft_fmf_file' AND post_status = 'publish'"
		),
		'total_files'       => (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_files"
		),
		'total_size_bytes'  => (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(file_size),0) FROM {$wpdb->prefix}isoft_fmf_files"
		),
		'total_log_entries' => (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log"
		),
		'top_alltime'       => $top_alltime,
		'top_30d'           => $top_30d,
		'top_30d_window'    => $top_30d_window,
		'daily_30d'         => $wpdb->get_results(
			$wpdb->prepare(
				"SELECT log_date AS day, SUM(count) AS count
				   FROM {$wpdb->prefix}isoft_fmf_download_daily
				  WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  GROUP BY log_date
				  ORDER BY log_date ASC",
				30
			)
		) ?: array(),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

	set_transient( 'isoft_fmf_stats_overview', $data, 5 * MINUTE_IN_SECONDS );
	return $data;
}

/**
 * KSES allowlist for HTML emitted by our shortcodes / block render callbacks.
 * Built on top of wp_kses_allowed_html('post') with the extra attributes our
 * card / list / grid templates use (data-*, hidden, role, aria-*).
 *
 * @return array<string,array<string,bool>>
 */
function isoft_fmf_allowed_html(): array {
	static $allowed = null;
	if ( null !== $allowed ) {
		return $allowed;
	}

	$allowed = wp_kses_allowed_html( 'post' );

	$extra_attrs = array(
		'class'              => true,
		'id'                 => true,
		'role'               => true,
		'hidden'             => true,
		'aria-hidden'        => true,
		'aria-label'         => true,
		'aria-modal'         => true,
		'aria-disabled'      => true,
		'data-id'            => true,
		'data-title'         => true,
		'data-agree-content' => true,
		'data-agree-title'   => true,
	);

	foreach ( array( 'a', 'article', 'aside', 'button', 'div', 'figure', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'input', 'label', 'li', 'nav', 'ol', 'p', 'section', 'select', 'span', 'svg', 'ul' ) as $tag ) {
		if ( ! isset( $allowed[ $tag ] ) ) {
			$allowed[ $tag ] = array();
		}
		$allowed[ $tag ] = array_merge( $allowed[ $tag ], $extra_attrs );
	}

	// Inputs we use for filter/search bars.
	$input_attrs  = array(
		'type'         => true,
		'name'         => true,
		'value'        => true,
		'placeholder'  => true,
		'autocomplete' => true,
	);
	$option_attrs = array(
		'value'    => true,
		'selected' => true,
	);

	$allowed['input']  = array_merge( $allowed['input'] ?? array(), $input_attrs );
	$allowed['select'] = $allowed['select'] ?? array();
	$allowed['option'] = array_merge( $allowed['option'] ?? array(), $option_attrs );

	return $allowed;
}

/**
 * Whether download links for a download must carry a nonce.
 *
 * Always, unless the site owner turned on "Cache-friendly download links"
 * (isoft_fmf_cache_friendly_links). With it on, public downloads drop the
 * nonce: anyone can load the page and get a fresh one, so it protects
 * nothing, while it breaks every cached copy of the page (page-cache
 * plugins, CDNs, static exports) once it expires. Restricted and
 * password-protected downloads always keep the nonce, and unknown ones
 * fail closed; the access check stays the real protection either way.
 */
function isoft_fmf_download_requires_nonce( int $download_id ): bool {
	$skip = false;
	if ( get_option( 'isoft_fmf_cache_friendly_links', 0 ) && $download_id > 0 ) {
		$skip = 'isoft_fmf_file' === get_post_type( $download_id )
			&& 'publish' === get_post_status( $download_id )
			&& ! post_password_required( $download_id )
			&& 'public' === ( new ISOFT_FMF_Access_Control() )->effective_role_for( $download_id );
	}

	/**
	 * Filters whether download / bundle links for a download need a nonce.
	 *
	 * @param bool $required    Default: true, false only for public downloads while cache-friendly links are on.
	 * @param int  $download_id Download post ID (0 when unknown).
	 */
	return (bool) apply_filters( 'isoft_fmf_download_requires_nonce', ! $skip, $download_id );
}

/**
 * Per-IP downloads allowed per hour. The configured limit wins; while
 * cache-friendly links are on and no limit is set, a conservative default
 * applies so nonce-less links are not an open tap.
 */
function isoft_fmf_effective_rate_limit(): int {
	$limit = (int) get_option( 'isoft_fmf_rate_limit_per_hour', 0 );
	if ( $limit <= 0 && get_option( 'isoft_fmf_cache_friendly_links', 0 ) ) {
		/**
		 * Filters the default hourly per-IP limit used while cache-friendly links are on.
		 *
		 * @param int $limit Downloads per IP per hour; 0 disables the default.
		 *                   Default: the "Default limit for cache-friendly links" setting (120).
		 */
		$limit = (int) apply_filters( 'isoft_fmf_cache_friendly_default_rate_limit', (int) get_option( 'isoft_fmf_cache_friendly_rate_limit', 120 ) );
	}
	return max( 0, $limit );
}

/**
 * Build the download URL for a file. Nonce-protected unless cache-friendly
 * links are on and the download is public (see isoft_fmf_download_requires_nonce()).
 *
 * @param int $file_id     File ID.
 * @param int $download_id Parent download ID when the caller already knows it; saves a lookup.
 */
function isoft_fmf_get_download_url( int $file_id, int $download_id = 0 ): string {
	if ( $download_id <= 0 && get_option( 'isoft_fmf_cache_friendly_links', 0 ) ) {
		$file        = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
		$download_id = $file ? (int) $file->download_id : 0;
	}

	$args = array( 'isoft_fmf_download' => $file_id );
	if ( isoft_fmf_download_requires_nonce( $download_id ) ) {
		$args['nonce'] = wp_create_nonce( 'isoft_fmf_download_' . $file_id );
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Build the URL that streams every local file attached to the given
 * download as a single ZIP archive. Nonce-protected unless cache-friendly
 * links are on and the download is public (see isoft_fmf_download_requires_nonce()).
 */
function isoft_fmf_get_bundle_url( int $download_id ): string {
	$args = array( 'isoft_fmf_bundle' => $download_id );
	if ( isoft_fmf_download_requires_nonce( $download_id ) ) {
		$args['nonce'] = wp_create_nonce( 'isoft_fmf_bundle_' . $download_id );
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Record a download that has been served — by the built-in handler or by
 * something else (an edge server, a CDN, a static mirror) reported after
 * the fact. Writes the log row (with the license in force, stamped at log
 * time), the daily bucket for the download's own date, and the file and
 * download counters when counting is enabled.
 *
 * Pass an `idempotency_key` in $context to make retries safe: a repeat
 * report of the same file and key writes nothing, counts nothing and
 * returns the original log row id. The key lives on the log row, so this
 * holds only while logging is enabled and until the retention purge
 * removes the row: a retry arriving after that counts again, as does every
 * call without a key or with logging off. Reporters should retry well
 * inside the log retention period.
 *
 * @param int                  $file_id File ID (isoft_fmf_files row).
 * @param array<string, mixed> $context Optional: time, user_id, ip, user_agent, referer, source,
 *                                      license_id, idempotency_key. See ISOFT_FMF_Download_Logger::log().
 * @return int|null Log row ID; null when the file is unknown, logging is
 *                  disabled or the insert failed (counters still update).
 */
function isoft_fmf_record_download( int $file_id, array $context = array() ): ?int {
	$manager = new ISOFT_FMF_File_Manager();
	$file    = $manager->get_file( $file_id );
	if ( ! $file ) {
		return null;
	}

	$download_id = (int) $file->download_id;
	$logger      = new ISOFT_FMF_Download_Logger();
	$key         = ISOFT_FMF_Download_Logger::clean_key( $context['idempotency_key'] ?? null );

	if ( null !== $key ) {
		$existing = $logger->find_by_key( $file_id, $key );
		if ( null !== $existing ) {
			return $existing;
		}
	}

	$log_id = $logger->log( $download_id, $file_id, $context );

	// Lost a race against a concurrent report of the same key: the other call counted it.
	if ( null === $log_id && null !== $key && null !== $logger->find_by_key( $file_id, $key ) ) {
		return $logger->find_by_key( $file_id, $key );
	}

	if ( isoft_fmf_get_settings()['enable_counting'] ) {
		$manager->increment_count( $file_id, $download_id );
	}

	return $log_id;
}

/**
 * Announce that public-facing FMF content changed. This is the one hook a
 * static site build, page cache or CDN needs: it fires for file rows (added,
 * edited, reordered, removed, replaced, held back), licenses, pending state,
 * and, through ISOFT_FMF_Change_Announcer, for downloads saved or deleted,
 * category and tag edits (including their Foundation term meta) and term
 * assignments.
 *
 * Download counters are deliberately not announced — they change on every
 * download and would turn into a rebuild per click.
 *
 * Listeners should be cheap or debounce on their side: this can still fire
 * for many objects in one request. Importers can wrap their work in
 * isoft_fmf_suspend_content_changed() / isoft_fmf_resume_content_changed().
 *
 * @param string $object_type 'download', 'license', 'category' or 'tag'.
 * @param int    $object_id   Download post ID, license ID or term ID.
 */
function isoft_fmf_content_changed( string $object_type, int $object_id ): void {
	$state = &isoft_fmf_content_changed_state();

	if ( $state['suspended'] > 0 ) {
		$state['pending'] = true;
		return;
	}

	/**
	 * Fires when FMF content changed outside post / term saves.
	 *
	 * @param string $object_type 'download', 'license', 'category', 'tag', or 'bulk' (object id 0) after a suspended batch.
	 * @param int    $object_id   Download post ID or license ID.
	 */
	do_action( 'isoft_fmf_content_changed', $object_type, $object_id );
}

/**
 * Request-scoped bookkeeping for isoft_fmf_content_changed().
 *
 * @return array{suspended:int,pending:bool}
 */
function &isoft_fmf_content_changed_state(): array {
	static $state = array(
		'suspended' => 0,
		'pending'   => false,
	);
	return $state;
}

/**
 * Hold back isoft_fmf_content_changed while a bulk operation runs (an
 * import, a migration). Pair with isoft_fmf_resume_content_changed(), which
 * fires a single ( 'bulk', 0 ) announcement if anything changed meanwhile,
 * so a static-build or cache-purge listener reacts once instead of
 * thousands of times. Calls nest.
 */
function isoft_fmf_suspend_content_changed(): void {
	$state = &isoft_fmf_content_changed_state();
	++$state['suspended'];
}

/**
 * End a suspension started by isoft_fmf_suspend_content_changed().
 */
function isoft_fmf_resume_content_changed(): void {
	$state = &isoft_fmf_content_changed_state();
	if ( $state['suspended'] > 0 && 0 === --$state['suspended'] && $state['pending'] ) {
		$state['pending'] = false;
		/** This action is documented in isoft_fmf_content_changed(). */
		do_action( 'isoft_fmf_content_changed', 'bulk', 0 );
	}
}

/**
 * The agreement a visitor must accept before downloading, or null when the
 * download has no agreement gate. Single source for the download card, the
 * download button shortcode and the public API.
 *
 * Text: the full text of the license set directly on the download, else the
 * download's custom agreement text. (An inherited category license does not
 * feed the agreement — unchanged behaviour.)
 *
 * @return array{title: string, text: string, license_id: int|null}|null
 *         text is wp_kses_post()-filtered HTML; title is raw (escape at output).
 */
function isoft_fmf_agreement_for( int $download_id ): ?array {
	if ( ! get_post_meta( $download_id, '_isoft_fmf_require_agree', true ) ) {
		return null;
	}

	$license_id = (int) get_post_meta( $download_id, '_isoft_fmf_license_id', true );
	$license    = $license_id > 0 ? ( new ISOFT_FMF_License_Manager() )->get( $license_id ) : null;

	// Cast to string: full_text is a nullable LONGTEXT; wp_kses_post( null ) is deprecated since PHP 8.1.
	return array(
		'title'      => $license ? (string) $license->title : get_the_title( $download_id ),
		'text'       => wp_kses_post( $license ? (string) $license->full_text : (string) get_post_meta( $download_id, '_isoft_fmf_agree_text', true ) ),
		'license_id' => $license ? (int) $license->id : null,
	);
}

/**
 * Content-Disposition value that survives non-ASCII file names (RFC 6266):
 * an ASCII fallback plus the UTF-8 name.
 */
function isoft_fmf_content_disposition( string $file_name ): string {
	$ascii = preg_replace( '/[^A-Za-z0-9._-]+/', '_', remove_accents( isoft_fmf_cyrillic_to_latin( $file_name ) ) );
	$ascii = trim( (string) $ascii, '_' );
	if ( '' === $ascii ) {
		$ascii = 'download';
	}
	return sprintf( 'attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode( $file_name ) );
}

/**
 * Returns true when the requesting client's User-Agent matches any
 * line in the `isoft_fmf_block_user_agents` option (newline-separated,
 * case-insensitive substring match). Empty lines and the empty
 * User-Agent always return false — the admin can't accidentally block
 * every visitor by saving a blank textarea.
 */
function isoft_fmf_user_agent_blocked(): bool {
	$blocklist = get_option( 'isoft_fmf_block_user_agents', '' );
	if ( '' === trim( (string) $blocklist ) ) {
		return false;
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( '' === $ua ) {
		return false;
	}
	$ua_lower = strtolower( $ua );
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $blocklist ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( str_contains( $ua_lower, strtolower( $line ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The visitor's IP address. Forwarding headers (Cloudflare, X-Forwarded-For)
 * are believed only when the connection comes from a proxy the site trusts;
 * see ISOFT_FMF_Client_Ip and Settings > Security > Client IP source.
 * Returns null if no valid address is found.
 */
function isoft_fmf_client_ip(): ?string {
	return ISOFT_FMF_Client_Ip::resolve();
}
