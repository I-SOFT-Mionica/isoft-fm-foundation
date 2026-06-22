<?php
/**
 * REST controller for plugin maintenance operations.
 *
 * Namespace: isoft-fm-foundation/v1
 *
 *   POST   /maintenance/demo-content       {action: install|remove}
 *   DELETE /maintenance/bundle-cache       wipes the ZIP bundle cache
 *   POST   /maintenance/integrity          runs the integrity scan
 *   DELETE /maintenance/log-purge          purges old log rows
 *   GET    /maintenance/export?format=csv  streams the log as CSV
 *   GET    /maintenance/export?format=json streams the log as JSON
 *
 * Permission parity with the legacy admin-post handlers:
 *   - demo/bundle/integrity: isoft_fmf_manage_settings
 *   - log-purge:             isoft_fmf_manage_settings
 *   - export:                isoft_fmf_export_logs (Admins only by default)
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Maintenance {

	private const NAMESPACE_V1 = 'isoft-fm-foundation/v1';

	private ISOFT_FMF_Maintenance_Service $service;

	public function __construct() {
		$this->service = new ISOFT_FMF_Maintenance_Service();
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/maintenance/demo-content',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'demo_content' ),
				'permission_callback' => array( $this, 'settings_permission' ),
				'args'                => array(
					'action' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'install', 'remove' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/maintenance/bundle-cache',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_bundle_cache' ),
				'permission_callback' => array( $this, 'settings_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/maintenance/integrity',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'integrity' ),
				'permission_callback' => array( $this, 'settings_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/maintenance/log-purge',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'log_purge' ),
				'permission_callback' => array( $this, 'settings_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/maintenance/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'export_permission' ),
				'args'                => array(
					'format' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'csv', 'json' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public function settings_permission(): bool {
		return current_user_can( 'isoft_fmf_manage_settings' );
	}

	public function export_permission(): bool {
		return current_user_can( 'isoft_fmf_export_logs' );
	}

	// ---------------------------------------------------------------------
	// Endpoint handlers
	// ---------------------------------------------------------------------

	public function demo_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action = (string) $request->get_param( 'action' );
		if ( 'install' === $action ) {
			$result = $this->service->install_demo();
			$status = ! empty( $result['installed'] ) ? 200 : 409;
			return new WP_REST_Response( $result, $status );
		}
		if ( 'remove' === $action ) {
			return new WP_REST_Response( $this->service->remove_demo(), 200 );
		}
		// Schema enum is informational only without an explicit
		// validate_callback. Defensive check so unknown actions don't
		// silently fall through to remove.
		return new WP_Error(
			'isoft_fmf_unknown_action',
			/* translators: %s: action name */
			sprintf( __( 'Unknown demo-content action: %s', 'isoft-fm-foundation' ), $action ),
			array( 'status' => 400 )
		);
	}

	public function clear_bundle_cache(): WP_REST_Response {
		return new WP_REST_Response( $this->service->clear_bundle_cache(), 200 );
	}

	public function integrity(): WP_REST_Response {
		return new WP_REST_Response( $this->service->run_integrity_scan(), 200 );
	}

	public function log_purge(): WP_REST_Response {
		return new WP_REST_Response( $this->service->purge_logs(), 200 );
	}

	/**
	 * Streamed export. We can't return a structured WP_REST_Response and
	 * also stream a file body — WP serializes the response to JSON. So
	 * the handler emits headers + body directly and exits, the same way
	 * the legacy admin-post handler does. wp_die_handler is also bypassed
	 * for the same reason.
	 */
	public function export( WP_REST_Request $request ): void {
		$format   = (string) $request->get_param( 'format' );
		$body     = 'json' === $format ? $this->service->export_json() : $this->service->export_csv();
		$filename = $this->service->export_filename( $format );
		$type     = 'json' === $format ? 'application/json' : 'text/csv';

		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file body; escaping would corrupt CSV/JSON.
		echo $body;
		exit;
	}
}
