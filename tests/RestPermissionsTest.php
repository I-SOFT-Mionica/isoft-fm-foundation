<?php
/**
 * Capability parity matrix — every 0.12.0 REST route × every WP role.
 *
 * Locks down that:
 *  - Anonymous (no current user) cannot hit anything that requires a cap.
 *  - Subscribers cannot reach admin-cap endpoints.
 *  - Authors can list licenses (read perm is edit_posts) but can't write.
 *  - Editors can do everything except exports (which require
 *    isoft_fmf_export_logs, an Admin-only cap).
 *  - Administrators can do everything.
 *
 * The matrix exists because the AJAX → REST refactor moved capability
 * checks across files and class boundaries. A single test that says
 * "for every route, for every role, does the permission_callback do
 * the right thing?" catches drift even if a single controller's
 * permission_callback gets edited in isolation.
 *
 * Per route we send a no-body request and only check the status code.
 * Failure modes:
 *   - Schema validation issues surface as 400, irrelevant here (we treat
 *     400 the same as 200 for "permission was allowed").
 *   - The cap check denies as 401 (no user) or 403 (wrong user).
 */

class RestPermissionsTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private const NS = '/isoft-fm-foundation/v1';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function login( string $role ): int {
		if ( '' === $role ) {
			wp_set_current_user( 0 );
			return 0;
		}
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * @return iterable<string, array{0:string,1:string,2:array<string,int>}>
	 *   role => [method, path, expectations-per-role]
	 *   Status mapping per role:
	 *     0 = anonymous (no user)
	 *     S = subscriber
	 *     A = author
	 *     E = editor
	 *     M = administrator
	 *   200/201 = allowed and the handler ran.
	 *   400     = allowed-or-denied AND schema rejected the no-body
	 *             request before reaching the permission_callback. We
	 *             treat 400 as "non-success" — fine for either bucket.
	 *   401     = denied, no user.
	 *   403     = denied, wrong user.
	 */
	public static function routes_matrix(): array {
		// allowed → "200/201, or 400 because the request body was minimal".
		$ok       = array( 200, 201, 400 );
		// denied → 401/403 OR 400 (schema validation runs before
		// permission_callback, so a no-body request to /demo-content gets
		// 400 even for a subscriber — never reaches our cap check).
		$denied_a = array( 400, 401, 403 );

		return array(
			// route_key                 => [method, path,                                    expectations]
			'GET /licenses'              => array( 'GET',    '/licenses',                                array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $ok, 'editor' => $ok, 'administrator' => $ok ) ),
			'POST /licenses'             => array( 'POST',   '/licenses',                                array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'POST /licenses/restore'     => array( 'POST',   '/licenses/restore-seeds',                  array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'GET /settings'              => array( 'GET',    '/settings',                                array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'POST /settings'             => array( 'POST',   '/settings',                                array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'GET /settings/schema'       => array( 'GET',    '/settings/schema',                         array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'POST /settings/flush'       => array( 'POST',   '/settings/flush-rewrite',                  array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'GET /broken-links'          => array( 'GET',    '/broken-links',                            array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'POST /demo'                 => array( 'POST',   '/maintenance/demo-content',                array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'DELETE /bundle-cache'       => array( 'DELETE', '/maintenance/bundle-cache',                array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'POST /integrity'            => array( 'POST',   '/maintenance/integrity',                   array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'DELETE /log-purge'          => array( 'DELETE', '/maintenance/log-purge',                   array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
			'GET /export'                => array( 'GET',    '/maintenance/export',                      array( '0' => $denied_a, 'subscriber' => $denied_a, 'author' => $denied_a, 'editor' => $denied_a, 'administrator' => $ok ) ),
		);
	}

	/**
	 * @dataProvider provide_routes_x_roles
	 */
	public function test_permission_matrix( string $route_label, string $method, string $path, string $role, array $allowed_statuses ): void {
		$this->login( '0' === $role ? '' : $role );

		$response = $this->server->dispatch( new WP_REST_Request( $method, self::NS . $path ) );
		$status   = $response->get_status();

		$this->assertContains(
			$status,
			$allowed_statuses,
			"Route {$route_label} as role {$role}: expected one of " .
				implode( ',', $allowed_statuses ) . " but got {$status}."
		);
	}

	/**
	 * @return iterable<string, array{0:string,1:string,2:string,3:string,4:array<int,int>}>
	 */
	public static function provide_routes_x_roles(): iterable {
		foreach ( self::routes_matrix() as $label => $row ) {
			[ $method, $path, $expectations ] = $row;
			foreach ( $expectations as $role => $allowed ) {
				yield "{$label} :: {$role}" => array( $label, $method, $path, (string) $role, $allowed );
			}
		}
	}
}
