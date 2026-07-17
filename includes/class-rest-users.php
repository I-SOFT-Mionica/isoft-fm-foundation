<?php
/**
 * REST controller for per-user category ACL.
 *
 * Namespace: isoft-fm-foundation/v1
 *
 *   GET    /users/{id}/category-acl   Returns { selected: [int] } — the
 *                                     explicit (not effective) list of
 *                                     category term IDs assigned to the
 *                                     user. Descendants resolve via
 *                                     ISOFT_FMF_Category_ACL::get_effective().
 *   POST   /users/{id}/category-acl   Body: { selected: [int] }. Replaces
 *                                     the stored set. Empty array = no
 *                                     write access anywhere (admins are
 *                                     always unrestricted regardless).
 *
 * Permission: manage_options. Same gate as the legacy profile-screen
 * checkbox tree that this endpoint replaces — only admins can hand out
 * category ACLs.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Users {

	private const NAMESPACE_V1 = 'isoft-fm-foundation/v1';

	/** Mirrors ISOFT_FMF_Category_ACL::USER_META_KEY (private const there). */
	private const USER_META_KEY = '_isoft_fmf_allowed_categories';

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'required'          => true,
				'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/users/(?P<id>\d+)/category-acl',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_acl' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_acl' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'selected' => array(
								'required' => true,
								'type'     => 'array',
								'items'    => array( 'type' => 'integer' ),
							),
						)
					),
				),
			)
		);
	}

	public function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_acl( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'isoft_fmf_user_not_found',
				__( 'User not found.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}
		$selected = ISOFT_FMF_Category_ACL::get_explicit( $user_id );
		return new WP_REST_Response(
			array(
				'selected' => $selected,
			),
			200
		);
	}

	public function set_acl( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request->get_param( 'id' );
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'isoft_fmf_user_not_found',
				__( 'User not found.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}

		$raw = (array) $request->get_param( 'selected' );
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $raw ),
					static fn( int $i ): bool => $i > 0
				)
			)
		);

		update_user_meta( $user_id, self::USER_META_KEY, $ids );

		return new WP_REST_Response(
			array(
				'selected' => $ids,
			),
			200
		);
	}
}
