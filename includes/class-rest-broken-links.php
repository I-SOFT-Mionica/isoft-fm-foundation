<?php
/**
 * REST controller for the Broken Links recovery surface.
 *
 * Namespace: isoft-fm-foundation/v1
 *
 * Read:
 *   GET    /broken-links                       paginated list of currently broken files
 *   GET    /broken-links/{file_id}/probe       data the recovery dialog needs
 *
 * Write:
 *   POST   /broken-links/{file_id}/recover     JSON body {action: 'move_back'|'reassign'|'split'|'detach'}
 *   POST   /broken-links/{file_id}/reupload    multipart replacement
 *
 * Permission parity: every route checks `isoft_fmf_manage_settings`,
 * matching $this->guard() in class-broken-links-ajax.php.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Broken_Links {

	private const NAMESPACE_V1 = 'isoft-fm-foundation/v1';

	/** Recover-actions supported by POST /broken-links/{id}/recover. */
	private const ACTIONS = array( 'move_back', 'reassign', 'split', 'detach' );

	private ISOFT_FMF_Broken_Links_Service $service;

	public function __construct() {
		$this->service = new ISOFT_FMF_Broken_Links_Service();
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/broken-links',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		$file_id_arg = array(
			'file_id' => array(
				'required'          => true,
				'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/broken-links/(?P<file_id>\d+)/probe',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'probe' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $file_id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/broken-links/(?P<file_id>\d+)/recover',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'recover' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array_merge(
					$file_id_arg,
					array(
						'action' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => self::ACTIONS,
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/broken-links/(?P<file_id>\d+)/reupload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reupload' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $file_id_arg,
			)
		);
	}

	public function permission(): bool {
		return current_user_can( 'isoft_fmf_manage_settings' );
	}

	// ---------------------------------------------------------------------
	// Endpoint handlers
	// ---------------------------------------------------------------------

	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = $this->service->list_broken(
			(int) $request->get_param( 'page' ),
			$per_page
		);
		// Envelope shape (items + totalItems + totalPages) for the React
		// client. Matches /logs from sub-PR 2. Sidesteps the body-stream
		// consumption issues we hit trying to read X-WP-Total via the
		// parse:false + response.json() pattern.
		return new WP_REST_Response(
			array(
				'items'      => $result['items'],
				'totalItems' => (int) $result['total'],
				'totalPages' => $per_page > 0
					? (int) ceil( $result['total'] / $per_page )
					: 0,
			),
			200
		);
	}

	public function probe( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file_id = (int) $request->get_param( 'file_id' );
		$result  = $this->service->probe( $file_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	public function recover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file_id = (int) $request->get_param( 'file_id' );
		$action  = (string) $request->get_param( 'action' );

		// Schema enum guarantees $action is one of self::ACTIONS, but be
		// defensive — also makes the test for unknown-action easy to write.
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error(
				'isoft_fmf_unknown_action',
				/* translators: %s: action name */
				sprintf( __( 'Unknown recovery action: %s', 'isoft-fm-foundation' ), $action ),
				array( 'status' => 400 )
			);
		}

		$result = $this->service->{$action}( $file_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	public function reupload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file_id = (int) $request->get_param( 'file_id' );
		$files   = $request->get_file_params();
		if ( empty( $files['replacement'] ) ) {
			return new WP_Error(
				'isoft_fmf_no_file',
				__( 'No file uploaded.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}
		$result = $this->service->reupload( $file_id, $files['replacement'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
