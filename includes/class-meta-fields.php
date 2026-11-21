<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Meta_Fields {

	/** Fields Foundation keeps itself; writing a new value needs this capability. */
	public const ADMIN_FIELDS = array( '_isoft_fmf_download_count', '_isoft_fmf_is_hot' );
	public const ADMIN_CAP    = 'isoft_fmf_manage_settings';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'guard_rest_meta' ), 10, 3 );
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
			'_isoft_fmf_download_count' => array( 'integer', 'absint', self::ADMIN_CAP ),
			'_isoft_fmf_access_role'    => array( 'string', array( self::class, 'sanitize_access_role' ), $own ),
			'_isoft_fmf_require_agree'  => array( 'boolean', null, $own ),
			'_isoft_fmf_agree_text'     => array( 'string', $html, $own ),
			'_isoft_fmf_is_hot'         => array( 'boolean', null, self::ADMIN_CAP ),
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

	/**
	 * Before a download is saved over REST.
	 *
	 * The block editor sends every registered field on each save, not only the
	 * ones changed. WordPress skips the permission check for a field only when a
	 * stored row holds the same value, so a download never downloaded (no count
	 * row) failed every save by a user without ADMIN_CAP, and the after-save
	 * step that applies access and license never ran. An unchanged
	 * ADMIN_FIELDS value is dropped from the request; a changed one still
	 * reaches WordPress and is refused.
	 *
	 * A license ID that does not exist is refused when it is a change (a stored
	 * one, say of a deleted license, does not block other edits).
	 *
	 * @param mixed           $response Result so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public static function guard_rest_meta( $response, $handler, $request ) {
		if ( null !== $response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}
		$base = rest_get_route_for_post_type_items( 'isoft_fmf_file' );
		if ( '' === $base || ! preg_match( '#^' . preg_quote( $base, '#' ) . '(?:/(\d+))?$#', $request->get_route(), $match ) ) {
			return $response;
		}
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return $response;
		}
		$post_id = isset( $match[1] ) ? (int) $match[1] : 0;
		$stored  = static fn( string $key ) => $post_id > 0 ? get_post_meta( $post_id, $key, true ) : '';

		if ( ! current_user_can( self::ADMIN_CAP ) ) {
			$changed = false;
			foreach ( self::ADMIN_FIELDS as $key ) {
				if ( ! array_key_exists( $key, $meta ) ) {
					continue;
				}
				$same = '_isoft_fmf_is_hot' === $key
					? rest_sanitize_boolean( $meta[ $key ] ) === (bool) $stored( $key )
					: absint( $meta[ $key ] ) === absint( $stored( $key ) );
				if ( $same ) {
					unset( $meta[ $key ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				$request->set_param( 'meta', $meta );
			}
		}

		if ( array_key_exists( '_isoft_fmf_license_id', $meta ) ) {
			$license = (int) $meta['_isoft_fmf_license_id'];
			if ( $license > 0 && (int) $stored( '_isoft_fmf_license_id' ) !== $license && ! ( new ISOFT_FMF_License_Service() )->get( $license ) ) {
				return new WP_Error(
					'rest_invalid_param',
					__( 'That license does not exist.', 'isoft-fm-foundation' ),
					array( 'status' => 400 )
				);
			}
		}

		return $response;
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
