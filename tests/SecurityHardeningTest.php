<?php
/**
 * File routes act only on files of the download the permission check
 * covered; import registers only untracked files from the download's own
 * category folder; dangerous extensions are refused whatever the settings
 * say; the storage folder carries guards for every common web server and
 * Site Health reports when it is reachable anyway.
 */
class SecurityHardeningTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private const NS = '/isoft-fm-foundation/v1';
	/** @var string[] */
	private array $cleanup = array();

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server, $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}isoft_fmf_files" );
		update_option( 'isoft_fmf_default_access_role', 'public' );
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( 'isoft_fmf_allowed_extensions' );
		isoft_fmf_get_settings( true );
		parent::tear_down();
	}

	private function category( string $name ): int {
		$id = (int) wp_insert_term( $name . ' ' . wp_generate_password( 6, false ), 'isoft_fmf_category' )['term_id'];
		ISOFT_FMF_Category_Folders::ensure( $id );
		return $id;
	}

	private function download_in( int $category_id ): int {
		$id = (int) isoft_fmf_create_draft_download( array( 'title' => 'D ' . wp_generate_password( 6, false ) ) );
		wp_set_object_terms( $id, array( $category_id ), 'isoft_fmf_category' );
		( new ISOFT_FMF_Access_Control() )->recompute_effective_role( $id );
		return $id;
	}

	private function external_file( int $download_id ): int {
		return (int) ( new ISOFT_FMF_Files_Service() )->add_external( $download_id, 'https://example.org/secret' );
	}

	/** A non-admin editor allowed to write only $allowed categories. */
	private function restricted_editor( array $allowed ): int {
		$id = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $id, '_isoft_fmf_allowed_categories', $allowed );
		ISOFT_FMF_Category_ACL::flush_user( $id );
		return $id;
	}

	private function dispatch( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::NS . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->server->dispatch( $request );
	}

	/** @return array{0:int,1:int,2:int} own download, other download, other file */
	private function cross_category_setup(): array {
		$own_cat   = $this->category( 'Own' );
		$other_cat = $this->category( 'Other' );
		$own       = $this->download_in( $own_cat );
		$other     = $this->download_in( $other_cat );
		$file      = $this->external_file( $other );

		wp_set_current_user( $this->restricted_editor( array( $own_cat ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $own ), 'the user may edit their own download' );
		$this->assertFalse( ISOFT_FMF_Category_ACL::can_edit_download( get_current_user_id(), $other ), 'but not the other category' );

		return array( $own, $other, $file );
	}

	// --- file routes stay inside the download in the URL -----------------

	public function test_delete_cannot_reach_a_file_of_another_download(): void {
		[ $own, , $file ] = $this->cross_category_setup();

		$this->assertSame( 404, $this->dispatch( 'DELETE', "/downloads/{$own}/files/{$file}" )->get_status() );
		$this->assertNotNull( ( new ISOFT_FMF_Files_Service() )->get( $file ) );
	}

	public function test_update_cannot_reach_a_file_of_another_download(): void {
		[ $own, , $file ] = $this->cross_category_setup();
		$before           = ( new ISOFT_FMF_Files_Service() )->get( $file )->title;

		$this->assertSame( 404, $this->dispatch( 'PUT', "/downloads/{$own}/files/{$file}", array( 'title' => 'Hijacked' ) )->get_status() );
		$this->assertSame( $before, ( new ISOFT_FMF_Files_Service() )->get( $file )->title );
	}

	public function test_reorder_cannot_include_a_file_of_another_download(): void {
		[ $own, , $file ] = $this->cross_category_setup();
		$mine             = $this->external_file( $own );
		$before           = (int) ( new ISOFT_FMF_Files_Service() )->get( $file )->sort_order;

		$response = $this->dispatch(
			'POST',
			"/downloads/{$own}/files/order",
			array(
				'order' => array(
					$mine => 1,
					$file => 99,
				),
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( $before, (int) ( new ISOFT_FMF_Files_Service() )->get( $file )->sort_order );
	}

	public function test_own_files_still_work_for_the_restricted_editor(): void {
		$own_cat = $this->category( 'Own' );
		$own     = $this->download_in( $own_cat );
		$a       = $this->external_file( $own );
		$b       = $this->external_file( $own );
		wp_set_current_user( $this->restricted_editor( array( $own_cat ) ) );

		$this->assertSame( 200, $this->dispatch( 'PUT', "/downloads/{$own}/files/{$a}", array( 'title' => 'Renamed' ) )->get_status() );
		$this->assertSame(
			200,
			$this->dispatch(
				'POST',
				"/downloads/{$own}/files/order",
				array(
					'order' => array(
						$a => 2,
						$b => 1,
					),
				)
			)->get_status()
		);
		$this->assertSame( 200, $this->dispatch( 'DELETE', "/downloads/{$own}/files/{$b}" )->get_status() );
	}

	// --- import registers only untracked files from its own folder -------

	public function test_import_refuses_a_file_from_another_category_folder(): void {
		$own_cat   = $this->category( 'Own' );
		$other_cat = $this->category( 'Other' );
		$own       = $this->download_in( $own_cat );
		$path      = isoft_fmf_category_fs_path( $other_cat ) . '/restricted.pdf';
		file_put_contents( $path, 'secret' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $path;

		$result = ( new ISOFT_FMF_Files_Service() )->import_from_disk( $own, isoft_fmf_category_folder_path( $other_cat ) . '/restricted.pdf' );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_file_not_found', $result->get_error_code() );
	}

	public function test_import_refuses_the_bundle_cache_and_storage_root(): void {
		$own = $this->download_in( $this->category( 'Own' ) );
		wp_mkdir_p( isoft_fmf_files_dir() . '/.bundle-cache' );
		$cached = isoft_fmf_files_dir() . '/.bundle-cache/bundle.zip';
		file_put_contents( $cached, 'zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $cached;
		$root = isoft_fmf_files_dir() . '/loose.pdf';
		file_put_contents( $root, 'loose' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $root;

		$service = new ISOFT_FMF_Files_Service();
		$this->assertSame( 'isoft_fmf_file_not_found', $service->import_from_disk( $own, '.bundle-cache/bundle.zip' )->get_error_code() );
		$this->assertSame( 'isoft_fmf_file_not_found', $service->import_from_disk( $own, 'loose.pdf' )->get_error_code() );
		$this->assertSame( 'isoft_fmf_file_not_found', $service->import_from_disk( $own, '.htaccess' )->get_error_code() );
	}

	public function test_import_refuses_a_file_that_is_already_registered(): void {
		$cat   = $this->category( 'Own' );
		$first = $this->download_in( $cat );
		$other = $this->download_in( $cat );
		$path  = isoft_fmf_category_fs_path( $cat ) . '/shared.pdf';
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $path;
		$rel             = isoft_fmf_category_folder_path( $cat ) . '/shared.pdf';
		$service         = new ISOFT_FMF_Files_Service();

		$this->assertIsInt( $service->import_from_disk( $first, $rel ) );
		$again = $service->import_from_disk( $other, $rel );

		$this->assertWPError( $again );
		$this->assertSame( 'isoft_fmf_already_registered', $again->get_error_code() );
	}

	public function test_import_of_an_untracked_file_in_its_own_folder_still_works(): void {
		$cat  = $this->category( 'Own' );
		$own  = $this->download_in( $cat );
		$path = isoft_fmf_category_fs_path( $cat ) . '/dropped-by-sftp.pdf';
		file_put_contents( $path, 'ok' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $path;

		$id = ( new ISOFT_FMF_Files_Service() )->import_from_disk( $own, isoft_fmf_category_folder_path( $cat ) . '/dropped-by-sftp.pdf' );

		$this->assertIsInt( $id );
	}

	public function test_import_refuses_a_script_even_in_its_own_folder(): void {
		$cat  = $this->category( 'Own' );
		$own  = $this->download_in( $cat );
		$path = isoft_fmf_category_fs_path( $cat ) . '/shell.php';
		file_put_contents( $path, '<?php echo 1;' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->cleanup[] = $path;

		$result = ( new ISOFT_FMF_Files_Service() )->import_from_disk( $own, isoft_fmf_category_folder_path( $cat ) . '/shell.php' );

		$this->assertWPError( $result );
		$this->assertSame( 'isoft_fmf_bad_filename', $result->get_error_code() );
	}

	// --- extension floor ---------------------------------------------------

	public function test_dangerous_extensions_are_refused_even_with_an_empty_allow_list(): void {
		update_option( 'isoft_fmf_allowed_extensions', '' );
		isoft_fmf_get_settings( true );

		foreach ( array( 'shell.php', 'x.phtml', 'x.phar', 'x.PHP5', '.htaccess', '.user.ini', 'x.cgi', 'x.aspx' ) as $name ) {
			$this->assertNotNull( isoft_fmf_sanitize_filename( $name )['error'], $name );
		}
		$this->assertNull( isoft_fmf_sanitize_filename( 'report.pdf' )['error'] );
		$this->assertNull( isoft_fmf_sanitize_filename( 'installer.exe' )['error'], 'an empty list still allows everything outside the floor' );
	}

	public function test_the_floor_wins_over_an_allow_list_that_names_a_script(): void {
		update_option( 'isoft_fmf_allowed_extensions', 'pdf,php' );
		isoft_fmf_get_settings( true );

		$this->assertNotNull( isoft_fmf_sanitize_filename( 'shell.php' )['error'] );
		$this->assertNull( isoft_fmf_sanitize_filename( 'report.pdf' )['error'] );
	}

	public function test_double_extensions_do_not_slip_through(): void {
		$result = isoft_fmf_sanitize_filename( 'shell.php.pdf' );

		$this->assertNull( $result['error'] );
		$this->assertStringNotContainsString( '.php', $result['slug'] );
		$this->assertStringEndsWith( '.pdf', $result['slug'] );
	}

	// --- storage guard -------------------------------------------------------

	public function test_guard_files_are_written_for_apache_iis_and_listing(): void {
		$dir = isoft_fmf_files_dir();
		foreach ( array( 'web.config', 'index.php' ) as $name ) {
			if ( file_exists( "{$dir}/{$name}" ) ) {
				unlink( "{$dir}/{$name}" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Reset before the check.
			}
		}

		ISOFT_FMF_Storage_Guard::write_guards();

		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( "{$dir}/.htaccess" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$this->assertStringContainsString( '<deny users="*" />', (string) file_get_contents( "{$dir}/web.config" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$this->assertFileExists( "{$dir}/index.php" );
	}

	private function fake_http( int $code, string $body ): void {
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array( 'code' => $code ),
				'cookies'  => array(),
				'filename' => null,
			)
		);
	}

	public function test_site_health_is_critical_when_storage_is_served_by_url(): void {
		ISOFT_FMF_Storage_Guard::check(); // writes the probe
		$token = trim( (string) file_get_contents( isoft_fmf_files_dir() . '/' . ISOFT_FMF_Storage_Guard::PROBE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local probe.
		$this->fake_http( 200, $token );

		$result = ( new ISOFT_FMF_Storage_Guard() )->run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'deny all', $result['description'] );
		$this->assertStringContainsString( '/isoft-fmf-files/', $result['description'] );
	}

	public function test_site_health_is_good_when_storage_is_refused(): void {
		$this->fake_http( 403, 'Forbidden' );

		$this->assertSame( 'good', ( new ISOFT_FMF_Storage_Guard() )->run_test()['status'] );
	}

	public function test_a_200_without_the_probe_is_not_mistaken_for_exposure(): void {
		$this->fake_http( 200, '<html>Custom 404 page</html>' );

		$this->assertSame( 'safe', ISOFT_FMF_Storage_Guard::check() );
	}

	public function test_site_health_says_unknown_when_the_site_cannot_reach_itself(): void {
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'Loopback blocked' ) );

		$result = ( new ISOFT_FMF_Storage_Guard() )->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( ISOFT_FMF_Storage_Guard::PROBE, $result['description'] );
	}

	public function test_the_test_is_registered_with_site_health(): void {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey( 'isoft_fmf_storage_guard', $tests['direct'] );
	}
}
