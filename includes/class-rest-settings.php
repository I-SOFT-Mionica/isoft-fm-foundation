<?php
/**
 * REST controller for plugin settings.
 *
 * Namespace: isoft-fm-foundation/v1
 *
 * - GET    /settings                  read every known option (null when unset)
 * - POST   /settings                  partial update — only keys in body
 *                                     are written; unknown keys 400
 * - POST   /settings/flush-rewrite    replaces the Advanced-tab button
 * - GET    /settings/schema           the (group → key → type) map so the
 *                                     React admin can render tab labels
 *                                     and group fields without hard-coding
 *
 * Permission parity: read and write both gated on
 * `isoft_fmf_manage_settings`, matching every existing render_page() and
 * handle_*() check in class-settings.php and class-license-manager.php.
 * Read needs the cap too because settings can reveal infrastructure
 * details (rate limits, serve method, allowed file extensions).
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Settings {

	private const NAMESPACE_V1 = 'isoft-fm-foundation/v1';

	private ISOFT_FMF_Settings_Service $service;

	public function __construct() {
		$this->service = new ISOFT_FMF_Settings_Service();
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_all' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/settings/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/settings/flush-rewrite',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'flush_rewrite' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission(): bool {
		return current_user_can( 'isoft_fmf_manage_settings' );
	}

	// ---------------------------------------------------------------------
	// Endpoint handlers
	// ---------------------------------------------------------------------

	public function get_all(): WP_REST_Response {
		return new WP_REST_Response( $this->service->get_all(), 200 );
	}

	/**
	 * Partial update. Request body is a flat assoc { option_key: value }.
	 * Unknown keys are rejected en-masse with a 400 so the React admin
	 * doesn't quietly drop a typo.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			// Fall back to form-encoded for curl/manual testing.
			$body = $request->get_body_params();
		}
		if ( empty( $body ) ) {
			return new WP_Error(
				'isoft_fmf_settings_empty_body',
				__( 'No settings provided.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		$rejected = $this->service->update_many( $body );
		if ( ! empty( $rejected ) ) {
			return new WP_Error(
				'isoft_fmf_settings_unknown_keys',
				/* translators: %s: comma-separated list of unknown option keys */
				sprintf( __( 'Unknown setting keys: %s', 'isoft-fm-foundation' ), implode( ', ', $rejected ) ),
				array(
					'status'   => 400,
					'rejected' => $rejected,
				)
			);
		}

		return new WP_REST_Response( $this->service->get_all(), 200 );
	}

	public function get_schema(): WP_REST_Response {
		$out = array();
		foreach ( ISOFT_FMF_Settings_Service::groups() as $group => $options ) {
			$out[ $group ] = array_keys( $options );
		}
		return new WP_REST_Response( $out, 200 );
	}

	public function flush_rewrite(): WP_REST_Response {
		$this->service->flush_rewrite();
		return new WP_REST_Response( array( 'flushed' => true ), 200 );
	}
}
