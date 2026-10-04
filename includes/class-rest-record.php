<?php
/**
 * REST endpoint for reporting downloads that were served somewhere else
 * (an edge server, a CDN, a static mirror), so counts, HOT badges and the
 * per-download license trail stay correct.
 *
 * Namespace: isoft-fm-foundation/v1
 *
 *   POST /files/{id}/record
 *        {time?, user_id?, ip?, user_agent?, referer?, source?, license_id?, idempotency_key?}
 *
 * Authenticated (application password, cookie + nonce): this writes audit
 * rows, so it needs isoft_fmf_manage_settings, the same capability that
 * guards the other write endpoints. Pass an idempotency_key so a retried
 * report is counted once.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Record {

	private const NAMESPACE_V1 = 'isoft-fm-foundation/v1';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/files/(?P<id>\d+)/record',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id'              => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'time'            => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Unix timestamp of the download. Default: now.',
					),
					'user_id'         => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'ip'              => array( 'type' => array( 'string', 'null' ) ),
					'user_agent'      => array( 'type' => array( 'string', 'null' ) ),
					'referer'         => array( 'type' => array( 'string', 'null' ) ),
					'source'          => array(
						'type'    => 'string',
						'default' => 'api',
					),
					'license_id'      => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'idempotency_key' => array(
						'type'      => 'string',
						'maxLength' => 64,
						'pattern'   => '^[A-Za-z0-9._:-]+$',
					),
				),
			)
		);
	}

	public function permission(): bool {
		return current_user_can( 'isoft_fmf_manage_settings' );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function record( WP_REST_Request $request ) {
		$file_id = (int) $request['id'];
		if ( ! ( new ISOFT_FMF_File_Manager() )->get_file( $file_id ) ) {
			return new WP_Error( 'isoft_fmf_file_not_found', __( 'File not found.', 'isoft-fm-foundation' ), array( 'status' => 404 ) );
		}

		// Only keys the caller actually sent: an absent key means "use this
		// request's value", a present one (even null) overrides it. The
		// caller's own IP and user must never stand in for the visitor's.
		$context = array(
			'ip'         => null,
			'user_agent' => null,
			'referer'    => null,
			'user_id'    => 0,
		);
		foreach ( array( 'time', 'user_id', 'ip', 'user_agent', 'referer', 'source', 'license_id', 'idempotency_key' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$context[ $key ] = $request[ $key ];
			}
		}

		$key = ISOFT_FMF_Download_Logger::clean_key( $context['idempotency_key'] ?? null );
		if ( null !== $key ) {
			$existing = ( new ISOFT_FMF_Download_Logger() )->find_by_key( $file_id, $key );
			if ( null !== $existing ) {
				return new WP_REST_Response(
					array(
						'log_id'    => $existing,
						'duplicate' => true,
					),
					200
				);
			}
		}

		$log_id = isoft_fmf_record_download( $file_id, $context );

		return new WP_REST_Response(
			array(
				'log_id'    => $log_id,
				'duplicate' => false,
			),
			201
		);
	}
}
