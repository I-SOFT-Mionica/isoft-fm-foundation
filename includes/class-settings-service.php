<?php
/**
 * Pure data layer for plugin settings. Owns the canonical option schema —
 * the (group, key, sanitizer) map that drives both legacy register_setting()
 * calls (via [[ISOFT_FMF_Settings]]) and the REST controller
 * (ISOFT_FMF_Rest_Settings). No superglobals, no menu registration, no
 * rendering.
 *
 * Grouping is preserved because WP's Settings API saves per-group: the
 * "one group per tab" pattern in class-settings.php matters for the
 * legacy form flow (saving the Display tab can't wipe Security
 * checkboxes). REST writes are key-scoped so the grouping is informational
 * only on that path.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Settings_Service {

	/**
	 * Canonical option schema. group → (option_key → sanitizer callable).
	 *
	 * One sanitizer per option. Custom sanitizers live as static methods on
	 * this class so the schema is self-contained and serializable for tests
	 * and documentation.
	 *
	 * @return array<string,array<string,callable|string|array{0:class-string,1:string}>>
	 */
	public static function groups(): array {
		return array(
			'isoft_fmf_general'     => array(
				'isoft_fmf_default_access_role'     => 'sanitize_text_field',
				'isoft_fmf_enable_counting'         => 'absint',
				'isoft_fmf_enable_logging'          => 'absint',
				'isoft_fmf_enable_detailed_logging' => 'absint',
				'isoft_fmf_log_retention_days'      => 'absint',
				'isoft_fmf_enable_pdf_thumbnails'   => 'absint',
				'isoft_fmf_pdf_thumb_width'         => 'absint',
				'isoft_fmf_pdf_thumb_height'        => 'absint',
				'isoft_fmf_pdf_thumb_quality'       => 'absint',
				'isoft_fmf_overwrite_pdf_thumbnail' => 'absint',
				'isoft_fmf_allowed_extensions'      => 'sanitize_textarea_field',
				'isoft_fmf_cyrillic_titles'         => 'absint',
			),
			'isoft_fmf_display'     => array(
				'isoft_fmf_default_button_text'  => 'sanitize_text_field',
				'isoft_fmf_listing_layout'       => 'sanitize_text_field',
				'isoft_fmf_items_per_page'       => 'absint',
				'isoft_fmf_show_file_size'       => 'absint',
				'isoft_fmf_show_download_count'  => 'absint',
				'isoft_fmf_show_date'            => 'absint',
				'isoft_fmf_date_format'          => 'sanitize_text_field',
				'isoft_fmf_enable_zip_bundle'    => 'absint',
				'isoft_fmf_enable_zip_cache'     => 'absint',
				'isoft_fmf_zip_cache_days'       => 'absint',
				'isoft_fmf_external_link_target' => array( self::class, 'sanitize_link_target' ),
			),
			'isoft_fmf_security'    => array(
				'isoft_fmf_serve_method'           => 'sanitize_text_field',
				'isoft_fmf_nginx_config_confirmed' => 'absint',
				'isoft_fmf_rate_limit_per_hour'    => 'absint',
				'isoft_fmf_block_user_agents'      => 'sanitize_textarea_field',
				'isoft_fmf_hotlink_protection'     => 'absint',
			),
			'isoft_fmf_advanced'    => array(
				'isoft_fmf_archive_slug'             => 'sanitize_title',
				'isoft_fmf_category_slug'            => 'sanitize_title',
				'isoft_fmf_tag_slug'                 => 'sanitize_title',
				'isoft_fmf_delete_data_on_uninstall' => 'absint',
			),
			'isoft_fmf_maintenance' => array(
				'isoft_fmf_integrity_check_enabled' => 'absint',
				'isoft_fmf_integrity_check_time'    => array( self::class, 'sanitize_time' ),
				'isoft_fmf_integrity_autorelink'    => 'absint',
				'isoft_fmf_integrity_use_inode'     => 'absint',
			),
		);
	}

	/**
	 * Flat list of every known option key, in stable order.
	 *
	 * @return string[]
	 */
	public static function known_keys(): array {
		$keys = array();
		foreach ( self::groups() as $options ) {
			foreach ( $options as $key => $_sanitizer ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Return the sanitizer registered for a given key, or null if the key
	 * isn't in the schema. Used by both the REST controller (to sanitize on
	 * write) and the legacy register_setting() call.
	 *
	 * @return callable|string|array{0:class-string,1:string}|null
	 */
	public static function sanitizer_for( string $key ) {
		foreach ( self::groups() as $options ) {
			if ( array_key_exists( $key, $options ) ) {
				return $options[ $key ];
			}
		}
		return null;
	}

	/**
	 * Return all settings as a flat assoc array of key => current value.
	 * Unset options return null in the response (caller can apply defaults).
	 *
	 * @return array<string,mixed>
	 */
	public function get_all(): array {
		$out = array();
		foreach ( self::known_keys() as $key ) {
			$value         = get_option( $key, null );
			$out[ $key ] = null === $value || false === $value ? null : $value;
		}
		return $out;
	}

	/**
	 * Read one option. Returns null when unset.
	 *
	 * @return mixed
	 */
	public function get( string $key ) {
		if ( null === self::sanitizer_for( $key ) ) {
			return null;
		}
		$value = get_option( $key, null );
		return null === $value || false === $value ? null : $value;
	}

	/**
	 * Write one option after running it through the registered sanitizer.
	 * Returns true on success (including no-op write of the same value).
	 * Returns false if the key isn't in the schema (silently ignores
	 * unknown keys — the REST layer surfaces this as a 400 if needed).
	 *
	 * @param mixed $value
	 */
	public function set( string $key, $value ): bool {
		$sanitizer = self::sanitizer_for( $key );
		if ( null === $sanitizer ) {
			return false;
		}
		$clean = is_callable( $sanitizer ) ? call_user_func( $sanitizer, $value ) : $value;
		update_option( $key, $clean );
		return true;
	}

	/**
	 * Partial update — only the keys present in $assoc are written. Returns
	 * the keys that were rejected (not in the schema); empty array means
	 * every key was accepted.
	 *
	 * @param  array<string,mixed> $assoc
	 * @return string[] rejected keys
	 */
	public function update_many( array $assoc ): array {
		$rejected = array();
		foreach ( $assoc as $key => $value ) {
			if ( ! $this->set( (string) $key, $value ) ) {
				$rejected[] = (string) $key;
			}
		}
		return $rejected;
	}

	public function flush_rewrite(): void {
		flush_rewrite_rules();
	}

	// ---------------------------------------------------------------------
	// Custom sanitizers (referenced by the schema and by tests).
	// ---------------------------------------------------------------------

	/**
	 * Accept HH:MM (24-hour), fall back to 02:30. Used for the integrity-check
	 * scheduled time.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_time( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
			$h = max( 0, min( 23, (int) $m[1] ) );
			$i = max( 0, min( 59, (int) $m[2] ) );
			return sprintf( '%02d:%02d', $h, $i );
		}
		return '02:30';
	}

	/**
	 * Whitelist HTML link target attribute values.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_link_target( $value ): string {
		return in_array( $value, array( '_self', '_blank' ), true ) ? $value : '_blank';
	}
}
