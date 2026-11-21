<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Meta_Fields {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Values the Access field may hold. Anything else is stored as
	 * 'administrator': an unknown role already let no visitor in, so it never
	 * becomes more open. Empty (never set) stays empty.
	 */
	public const ACCESS_ROLES = array( 'inherit', 'public', 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

	public function register(): void {
		$own  = 'isoft_fmf_edit_own_downloads';
		$text = 'sanitize_text_field';
		$html = 'wp_kses_post';

		// Key => type, sanitizer (null: the type is enough), capability to write it.
		$fields = array(
			'_isoft_fmf_version'        => array( 'string', $text, $own ),
			'_isoft_fmf_changelog'      => array( 'string', $html, $own ),
			'_isoft_fmf_license_id'     => array( 'integer', array( self::class, 'sanitize_license_id' ), $own ),
			'_isoft_fmf_author_name'    => array( 'string', $text, $own ),
			'_isoft_fmf_author_url'     => array( 'string', array( self::class, 'sanitize_url' ), $own ),
			'_isoft_fmf_date_published' => array( 'string', $text, $own ),
			// Kept by Foundation; only an administrator corrects them.
			'_isoft_fmf_download_count' => array( 'integer', 'absint', 'isoft_fmf_manage_settings' ),
			'_isoft_fmf_access_role'    => array( 'string', array( self::class, 'sanitize_access_role' ), $own ),
			'_isoft_fmf_require_agree'  => array( 'boolean', null, $own ),
			'_isoft_fmf_agree_text'     => array( 'string', $html, $own ),
			'_isoft_fmf_is_hot'         => array( 'boolean', null, 'isoft_fmf_manage_settings' ),
			'_isoft_fmf_featured'       => array( 'boolean', null, $own ),
			'_isoft_fmf_external_only'  => array( 'boolean', null, $own ),
		);

		foreach ( $fields as $key => $field ) {
			list( $type, $sanitize, $cap ) = $field;

			$args = array(
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static fn( $allowed, $meta_key, $object_id ): bool => current_user_can( $cap ) && current_user_can( 'edit_post', (int) $object_id ),
			);
			if ( null !== $sanitize ) {
				$args['sanitize_callback'] = $sanitize;
			}
			register_post_meta( 'isoft_fmf_file', $key, $args );
		}
	}

	/** WordPress passes the meta key as the second argument; esc_url_raw() would read it as the protocols. */
	public static function sanitize_url( $value ): string {
		return esc_url_raw( (string) $value );
	}

	/** A license ID, -1 (inherit from the category) or 0 (none). */
	public static function sanitize_license_id( $value ): int {
		$value = (int) $value;
		return $value < ISOFT_FMF_License_Resolver::INHERIT ? 0 : $value;
	}

	public static function sanitize_access_role( $value ): string {
		$value = sanitize_key( (string) $value );
		if ( '' === $value || in_array( $value, self::ACCESS_ROLES, true ) ) {
			return $value;
		}
		return 'administrator';
	}
}
