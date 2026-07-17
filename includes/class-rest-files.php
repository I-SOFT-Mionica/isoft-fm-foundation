<?php
/**
 * REST controller for download files (the rows in wp_isoft_fmf_files).
 *
 * Namespace: isoft-fm-foundation/v1
 *
 * Read:
 *   GET    /downloads/{id}/files                 list (delegates to existing
 *                                                ISOFT_FMF_Rest_Api endpoint;
 *                                                kept canonical there)
 *   GET    /downloads/{id}/files/browse-category list physical files in the
 *                                                category folder with tracked
 *                                                flag
 *
 * Write:
 *   POST   /downloads/{id}/files                 multipart upload
 *   POST   /downloads/{id}/files/external        add external URL as a file
 *   POST   /downloads/{id}/files/import          import an untracked file from disk
 *   POST   /downloads/{id}/files/order           reorder ({fileId: pos})
 *   PUT    /downloads/{id}/files/{file_id}       update title + description
 *   DELETE /downloads/{id}/files/{file_id}       remove file row
 *
 * Permission parity: every write/destructive route checks `edit_post` for
 * the parent download_id, matching the per-post checks in the AJAX
 * handlers in class-admin-meta-boxes.php. Read is the same — file lists
 * are scoped to a post you can edit. The `permission_for_download()`
 * helper centralises this.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Files {

	private const NAMESPACE_V1 = 'isoft-fm-foundation/v1';

	private ISOFT_FMF_Files_Service $service;

	public function __construct() {
		$this->service = new ISOFT_FMF_Files_Service();
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$id_arg          = $this->id_arg();
		$download_id_arg = array( 'id' => $id_arg );

		register_rest_route(
			self::NAMESPACE_V1,
			'/downloads/(?P<id>\d+)/files',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'permission_for_download' ),
				'args'                => $download_id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/downloads/(?P<id>\d+)/files/external',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_external' ),
				'permission_callback' => array( $this, 'permission_for_download' ),
				'args'                => array_merge(
					$download_id_arg,
					array(
						'url'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'title'     => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'is_mirror' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/downloads/(?P<id>\d+)/files/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permission_for_download' ),
				'args'                => array_merge(
					$download_id_arg,
					array(
						'rel_path' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/downloads/(?P<id>\d+)/files/order',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reorder' ),
				'permission_callback' => array( $this, 'permission_for_download' ),
				'args'                => $download_id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/downloads/(?P<id>\d+)/files/browse-category',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'browse_category' ),
				'permission_callback' => array( $this, 'permission_for_download' ),
				'args'                => $download_id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/downloads/(?P<id>\d+)/files/(?P<file_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'permission_for_download' ),
					'args'                => array_merge(
						$download_id_arg,
						array(
							'file_id'     => $id_arg,
							'title'       => array(
								'required'          => false,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
								'default'           => '',
							),
							'description' => array(
								'required'          => false,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_textarea_field',
								'default'           => '',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'permission_for_download' ),
					'args'                => array_merge(
						$download_id_arg,
						array( 'file_id' => $id_arg )
					),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permission
	// ---------------------------------------------------------------------

	public function permission_for_download( WP_REST_Request $request ): bool {
		$download_id = (int) $request->get_param( 'id' );
		return $download_id > 0 && current_user_can( 'edit_post', $download_id );
	}

	// ---------------------------------------------------------------------
	// Endpoint handlers
	// ---------------------------------------------------------------------

	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$download_id = (int) $request->get_param( 'id' );
		$files       = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'isoft_fmf_no_file',
				__( 'No file received by the server.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->service->upload( $download_id, $files['file'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array( 'file' => $this->service->get( $result ) ),
			201
		);
	}

	public function add_external( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$download_id = (int) $request->get_param( 'id' );
		$url         = (string) $request->get_param( 'url' );
		$title       = (string) $request->get_param( 'title' );
		$is_mirror   = (bool) $request->get_param( 'is_mirror' );

		if ( '' === $url ) {
			return new WP_Error(
				'isoft_fmf_url_required',
				__( 'URL is required.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->service->add_external( $download_id, $url, $title, $is_mirror );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array( 'file' => $this->service->get( $result ) ),
			201
		);
	}

	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$download_id = (int) $request->get_param( 'id' );
		$rel_path    = (string) $request->get_param( 'rel_path' );

		$result = $this->service->import_from_disk( $download_id, $rel_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array( 'file' => $this->service->get( $result ) ),
			201
		);
	}

	public function reorder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		$order = $body['order'] ?? $body;
		if ( ! is_array( $order ) ) {
			return new WP_Error(
				'isoft_fmf_bad_order',
				__( 'Order must be an object of file_id => position.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}

		$this->service->reorder( $order );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function browse_category( WP_REST_Request $request ): WP_REST_Response {
		$download_id = (int) $request->get_param( 'id' );
		return new WP_REST_Response( $this->service->browse_category( $download_id ), 200 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file_id = (int) $request->get_param( 'file_id' );
		$file    = $this->service->get( $file_id );
		if ( null === $file ) {
			return new WP_Error(
				'isoft_fmf_file_not_found',
				__( 'File not found.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}

		$title       = (string) $request->get_param( 'title' );
		$description = (string) $request->get_param( 'description' );

		if ( ! $this->service->update_meta( $file_id, $title, $description ) ) {
			return new WP_Error(
				'isoft_fmf_update_failed',
				__( 'Could not save changes.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array( 'file' => $this->service->get( $file_id ) ),
			200
		);
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file_id = (int) $request->get_param( 'file_id' );
		$file    = $this->service->get( $file_id );
		if ( null === $file ) {
			return new WP_Error(
				'isoft_fmf_file_not_found',
				__( 'File not found.', 'isoft-fm-foundation' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->service->delete( $file_id ) ) {
			return new WP_Error(
				'isoft_fmf_delete_failed',
				__( 'Could not delete file.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'file_id' => $file_id,
			),
			200
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private function id_arg(): array {
		return array(
			'required'          => true,
			'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
			'sanitize_callback' => 'absint',
		);
	}
}
