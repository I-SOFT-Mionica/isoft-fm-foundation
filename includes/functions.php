<?php
/**
 * Global helper functions available to Core and all extensions.
 *
 * Loaded directly (not via autoloader) so they are always available.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register an extension with Core. Called by Sentinel, Orbit, or third-party plugins
 * inside the 'isfm_extensions_init' action.
 *
 * @param array{slug:string, name:string, version:string, description?:string, author?:string, url?:string, settings_cb?:callable, admin_menu?:callable} $args
 */
function isfm_register_extension( array $args ): bool {
	return ISFM_Extension_Api::register( $args );
}

/**
 * Create a draft download entry. Used by Sentinel and Orbit importers.
 *
 * @param array{title:string, description?:string, category_id?:int, access_role?:string, license_id?:int} $args
 * @return int|false  New post ID, or false on failure.
 */
function isfm_create_draft_download( array $args ): int|false {
	$title = sanitize_text_field( $args['title'] ?? '' );
	if ( ! $title ) {
		return false;
	}

	$post_id = wp_insert_post(
		array(
			'post_title'   => $title,
			'post_status'  => 'draft',
			'post_type'    => 'isfm_file',
			'post_content' => wp_kses_post( $args['description'] ?? '' ),
		)
	);

	if ( is_wp_error( $post_id ) ) {
		return false;
	}

	if ( ! empty( $args['category_id'] ) ) {
		wp_set_object_terms( $post_id, (int) $args['category_id'], 'isfm_category' );
	}
	if ( ! empty( $args['access_role'] ) ) {
		update_post_meta( $post_id, '_isfm_access_role', sanitize_text_field( $args['access_role'] ) );
	}
	if ( ! empty( $args['license_id'] ) ) {
		update_post_meta( $post_id, '_isfm_license_id', absint( $args['license_id'] ) );
	}

	return $post_id;
}

/**
 * Read Core settings as a flat array.
 *
 * @return array<string,mixed>
 */
function isfm_get_settings(): array {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}
	$cached = array(
		'default_access_role'      => get_option( 'isfm_default_access_role', 'public' ),
		'enable_counting'          => (bool) get_option( 'isfm_enable_counting', true ),
		'enable_logging'           => (bool) get_option( 'isfm_enable_logging', true ),
		'enable_detailed_logging'  => (bool) get_option( 'isfm_enable_detailed_logging', false ),
		'log_retention_days'       => (int) get_option( 'isfm_log_retention_days', 365 ),
		'enable_pdf_thumbnails'    => (bool) get_option( 'isfm_enable_pdf_thumbnails', true ),
		'pdf_thumb_width'          => (int) get_option( 'isfm_pdf_thumb_width', 300 ),
		'pdf_thumb_height'         => (int) get_option( 'isfm_pdf_thumb_height', 424 ),
		'pdf_thumb_quality'        => (int) get_option( 'isfm_pdf_thumb_quality', 85 ),
		'overwrite_pdf_thumbnail'  => (bool) get_option( 'isfm_overwrite_pdf_thumbnail', false ),
		'default_button_text'      => get_option( 'isfm_default_button_text', '' ),
		'allowed_extensions'       => array_values( array_filter( array_map( 'trim', explode( ',', get_option( 'isfm_allowed_extensions', 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt,csv,zip,rar,7z,jpg,jpeg,png,gif,webp,mp4,mp3,wav' ) ) ) ) ),
		'cyrillic_titles'          => (bool) get_option( 'isfm_cyrillic_titles', false ),
		'listing_layout'           => get_option( 'isfm_listing_layout', 'list' ),
		'items_per_page'           => (int) get_option( 'isfm_items_per_page', 10 ),
		'show_file_size'           => (bool) get_option( 'isfm_show_file_size', true ),
		'show_download_count'      => (bool) get_option( 'isfm_show_download_count', true ),
		'show_date'                => (bool) get_option( 'isfm_show_date', true ),
		'date_format'              => get_option( 'isfm_date_format', get_option( 'date_format' ) ),
		'serve_method'             => get_option( 'isfm_serve_method', 'auto' ),
		'rate_limit_per_hour'      => (int) get_option( 'isfm_rate_limit_per_hour', 0 ),
		'hotlink_protection'       => (bool) get_option( 'isfm_hotlink_protection', false ),
		'archive_slug'             => get_option( 'isfm_archive_slug', 'downloads' ),
		'category_slug'            => get_option( 'isfm_category_slug', 'download-category' ),
		'tag_slug'                 => get_option( 'isfm_tag_slug', 'download-tag' ),
		'delete_data_on_uninstall' => (bool) get_option( 'isfm_delete_data_on_uninstall', false ),
	);
	return $cached;
}

/**
 * Queue an admin dashboard notice for the current user.
 *
 * @param string $message  Plain text message.
 * @param string $type     'info' | 'success' | 'warning' | 'error'
 */
function isfm_notify_admin( string $message, string $type = 'info' ): void {
	$notices   = get_option( 'isfm_admin_notices', array() );
	$notices[] = array(
		'message' => $message,
		'type'    => in_array( $type, array( 'info', 'success', 'warning', 'error' ), true ) ? $type : 'info',
		'time'    => time(),
	);
	update_option( 'isfm_admin_notices', $notices );
}

/**
 * Convert a flat key=>value array into a shortcode attribute string.
 * e.g. ['limit' => 5, 'layout' => 'grid'] → ' limit="5" layout="grid"'
 *
 * @param array<string,scalar> $atts
 */
function isfm_atts_to_string( array $atts ): string {
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
 * Absolute filesystem path to the isfm-files/ storage root.
 */
function isfm_files_dir(): string {
	static $dir = null;
	if ( null === $dir ) {
		$dir = wp_upload_dir()['basedir'] . '/isfm-files';
	}
	return $dir;
}

/**
 * Build the relative folder path for a category by walking its ancestor chain.
 * e.g. "skupstina-opstine/saziv-2025-2029/iv-sednica"
 */
function isfm_category_folder_path( int $term_id ): string {
	$parts = array();
	$id    = $term_id;
	while ( $id ) {
		$term = get_term( $id, 'isfm_category' );
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
function isfm_category_fs_path( int $term_id ): string {
	return isfm_files_dir() . '/' . isfm_category_folder_path( $term_id );
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
function isfm_sanitize_filename( string $original_name ): array {
	// 1. Strip duplicate extension
	$name = isfm_strip_double_extension( $original_name );

	// 2. Split
	$ext           = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$original_stem = pathinfo( $name, PATHINFO_FILENAME );

	// 3. Extension allow-list
	$allowed = isfm_get_settings()['allowed_extensions'];
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
	$slug_stem = isfm_cyrillic_to_latin( $original_stem );
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
function isfm_strip_double_extension( string $filename ): string {
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
function isfm_filename_collision( string $slug, int $category_id ): bool {
	$path = isfm_category_fs_path( $category_id ) . '/' . $slug;
	return file_exists( $path );
}

// ─────────────────────────────────────────────────────────────────────────────
// Serbian transliteration
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Transliterate Serbian Cyrillic → Serbian Latin.
 * Digraphs (Љ Њ Џ) are in the map as multi-char keys; PHP's strtr() tries
 * longer keys first, so they are matched before their component characters.
 */
function isfm_cyrillic_to_latin( string $text ): string {
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
function isfm_latin_to_cyrillic( string $text ): string {
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
function isfm_autofill_title( string $original_stem ): string {
	$title = trim( $original_stem );
	if ( isfm_get_settings()['cyrillic_titles'] ) {
		$title = isfm_latin_to_cyrillic( $title );
	}
	return $title;
}

// ─────────────────────────────────────────────────────────────────────────────
// Existing helpers continue below
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Map a file extension to a CSS icon class used by the download card.
 */
function isfm_mime_icon_class( string $ext ): string {
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
 *     daily_30d:array<object>
 * }
 */
function isfm_get_stats_overview(): array {
	$cached = get_transient( 'isfm_stats_overview' );
	if ( false !== $cached ) {
		return $cached;
	}

	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate dashboard query; result cached as 'isfm_stats_overview' transient for 5 minutes (acceptable freshness for stats).
	$data = array(
		'total_downloads'   => (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'isfm_file' AND post_status = 'publish'"
		),
		'total_files'       => (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}isfm_files"
		),
		'total_size_bytes'  => (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(file_size),0) FROM {$wpdb->prefix}isfm_files"
		),
		'total_log_entries' => (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}isfm_download_log"
		),
		'top_alltime'       => $wpdb->get_results(
			"SELECT p.ID, p.post_title, COALESCE(SUM(f.download_count),0) AS total_count
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->prefix}isfm_files f ON f.download_id = p.ID
			  WHERE p.post_type = 'isfm_file' AND p.post_status = 'publish'
			  GROUP BY p.ID, p.post_title
			  ORDER BY total_count DESC
			  LIMIT 10"
		) ?: array(),
		'top_30d'           => $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.download_id, p.post_title, COUNT(*) AS count
				   FROM {$wpdb->prefix}isfm_download_log l
				   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
				  WHERE l.downloaded_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  GROUP BY l.download_id, p.post_title
				  ORDER BY count DESC
				  LIMIT 10",
				30
			)
		) ?: array(),
		'daily_30d'         => $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(downloaded_at) AS day, COUNT(*) AS count
				   FROM {$wpdb->prefix}isfm_download_log
				  WHERE downloaded_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				  GROUP BY DATE(downloaded_at)
				  ORDER BY day ASC",
				30
			)
		) ?: array(),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

	set_transient( 'isfm_stats_overview', $data, 5 * MINUTE_IN_SECONDS );
	return $data;
}

/**
 * KSES allowlist for HTML emitted by our shortcodes / block render callbacks.
 * Built on top of wp_kses_allowed_html('post') with the extra attributes our
 * card / list / grid templates use (data-*, hidden, role, aria-*).
 *
 * @return array<string,array<string,bool>>
 */
function isfm_allowed_html(): array {
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
 * Build a secure, nonce-protected download URL for a file.
 */
function isfm_get_download_url( int $file_id ): string {
	return add_query_arg(
		array(
			'isfm_download' => $file_id,
			'nonce'         => wp_create_nonce( 'isfm_download_' . $file_id ),
		),
		home_url( '/' )
	);
}
