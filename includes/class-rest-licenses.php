<?php
/**
 * REST controller for licenses CRUD.
 *
 * Namespace: isoft-fm-foundation/v1
 *
 * - GET    /licenses                  list   (read perm — any editor; the
 *                                             license picker in the editor
 *                                             sidebar needs this)
 * - POST   /licenses                  create (write perm — manage_settings)
 * - GET    /licenses/{id}             get one
 * - PUT    /licenses/{id}             update
 * - DELETE /licenses/{id}             delete
 * - POST   /licenses/restore-seeds    re-install any missing seeded licenses
 *                                     by slug (idempotent)
 *
 * Permission parity: write capability matches the legacy admin form handler
 * (ISOFT_FMF_License_Manager::handle_form_actions checks
 * `isoft_fmf_manage_settings`) — verified by tests/test-rest-permissions.php.
 *
 * Delegates all data work to [[ISOFT_FMF_License_Service]].
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Rest_Licenses {

	private const NAMESPACE_V1 = 'isoft-fm-foundation/v1';

	private ISOFT_FMF_License_Service $service;

	public function __construct() {
		$this->service = new ISOFT_FMF_License_Service();
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/licenses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'write_permission' ),
					'args'                => $this->writable_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/licenses/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'read_permission' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'write_permission' ),
					'args'                => array_merge(
						array( 'id' => $this->id_arg() ),
						$this->writable_args()
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'write_permission' ),
					'args'                => array(
						'id' => $this->id_arg(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/licenses/restore-seeds',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_seeds' ),
				'permission_callback' => array( $this, 'write_permission' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------

	/**
	 * Read perm — any editor with `edit_posts` may list licenses. The
	 * editor-sidebar license picker (0.12.1) needs this for all contributors
	 * who can create downloads, not just admins.
	 */
	public function read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Write perm — locked to `isoft_fmf_manage_settings`, matching the
	 * legacy admin-post handler in class-license-manager.php.
	 */
	public function write_permission(): bool {
		return current_user_can( 'isoft_fmf_manage_settings' );
	}

	// ---------------------------------------------------------------------
	// Endpoint handlers
	// ---------------------------------------------------------------------

	public function list(): WP_REST_Response {
		$rows = $this->service->list();
		$data = array_map( array( $this, 'prepare_item' ), $rows );
		return new WP_REST_Response( $data, 200 );
	}

	public function get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request->get_param( 'id' );
		$row = $this->service->get( $id );
		if ( null === $row ) {
			return $this->not_found( $id );
		}
		return new WP_REST_Response( $this->prepare_item( $row ), 200 );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $this->extract_writable( $request );
		if ( '' === $data['title'] ) {
			return new WP_Error(
				'isoft_fmf_license_title_required',
				__( 'License title is required.', 'isoft-fm-foundation' ),
				array( 'status' => 400 )
			);
		}
		$id = $this->service->create( $data );
		if ( $id <= 0 ) {
			return new WP_Error(
				'isoft_fmf_license_create_failed',
				__( 'Could not create license.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}
		$row = $this->service->get( $id );
		return new WP_REST_Response( $this->prepare_item( $row ), 201 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->service->get( $id ) ) {
			return $this->not_found( $id );
		}
		$data = $this->extract_writable( $request );
		$ok   = $this->service->update( $id, $data );
		if ( ! $ok ) {
			return new WP_Error(
				'isoft_fmf_license_update_failed',
				__( 'Could not update license.', 'isoft-fm-foundation' ),
				array( 'status' => 500 )
			);
		}
		$row = $this->service->get( $id );
		return new WP_REST_Response( $this->prepare_item( $row ), 200 );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->service->get( $id ) ) {
			return $this->not_found( $id );
		}
		$this->service->delete( $id );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	public function restore_seeds(): WP_REST_Response {
		$added = $this->service->install_missing_seeds();
		return new WP_REST_Response( array( 'restored' => $added ), 200 );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @param object $row
	 * @return array<string,mixed>
	 */
	private function prepare_item( $row ): array {
		return array(
			'id'          => (int) $row->id,
			'title'       => (string) $row->title,
			'slug'        => (string) $row->slug,
			'description' => (string) $row->description,
			'full_text'   => (string) $row->full_text,
			'url'         => (string) $row->url,
			'is_default'  => (bool) $row->is_default,
			'sort_order'  => (int) $row->sort_order,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_writable( WP_REST_Request $request ): array {
		return array(
			'title'       => (string) $request->get_param( 'title' ),
			'slug'        => (string) $request->get_param( 'slug' ),
			'description' => (string) $request->get_param( 'description' ),
			'full_text'   => (string) $request->get_param( 'full_text' ),
			'url'         => (string) $request->get_param( 'url' ),
			'is_default'  => (bool) $request->get_param( 'is_default' ),
			'sort_order'  => (int) $request->get_param( 'sort_order' ),
		);
	}

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

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function writable_args(): array {
		return array(
			'title'       => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'        => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
			'description' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'full_text'   => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'url'         => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'is_default'  => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
			'sort_order'  => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			),
		);
	}

	private function not_found( int $id ): WP_Error {
		return new WP_Error(
			'isoft_fmf_license_not_found',
			/* translators: %d: license id */
			sprintf( __( 'License %d not found.', 'isoft-fm-foundation' ), $id ),
			array( 'status' => 404 )
		);
	}
}
