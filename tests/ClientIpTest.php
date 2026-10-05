<?php
/**
 * Client IP resolution: forwarding headers are believed only when the
 * connection comes from a proxy the site trusts, so a visitor cannot fake
 * their address to dodge or spend someone else's rate limit.
 */
class ClientIpTest extends WP_UnitTestCase {

	private const CF_EDGE   = '104.16.5.9';
	private const CF_EDGE_6 = '2606:4700:10::1';
	private const VISITOR   = '203.0.113.50';
	private const OTHER     = '198.51.100.20';

	public function set_up(): void {
		parent::set_up();
		delete_option( ISOFT_FMF_Client_Ip::OPTION_MODE );
		delete_option( ISOFT_FMF_Client_Ip::OPTION_PROXIES );
		delete_option( ISOFT_FMF_Client_Ip::OPTION_REFRESH );
		delete_option( 'isoft_fmf_cloudflare_ranges' );
		delete_option( 'isoft_fmf_cloudflare_ranges_fetched' );
	}

	private function resolve( array $server, string $mode ): ?string {
		return ISOFT_FMF_Client_Ip::resolve( $server, $mode );
	}

	// --- modes ----------------------------------------------------------

	public function test_direct_ignores_every_forwarding_header(): void {
		$ip = $this->resolve(
			array(
				'REMOTE_ADDR'           => self::VISITOR,
				'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
				'HTTP_X_FORWARDED_FOR'  => '5.6.7.8',
				'HTTP_X_REAL_IP'        => '9.9.9.9',
			),
			'direct'
		);

		$this->assertSame( self::VISITOR, $ip );
	}

	public function test_cloudflare_trusts_the_header_only_from_a_cloudflare_peer(): void {
		$this->assertSame(
			self::VISITOR,
			$this->resolve(
				array(
					'REMOTE_ADDR'           => self::CF_EDGE,
					'HTTP_CF_CONNECTING_IP' => self::VISITOR,
				),
				'cloudflare'
			)
		);

		// Same header, but the peer is an ordinary visitor: spoofed, ignored.
		$this->assertSame(
			self::OTHER,
			$this->resolve(
				array(
					'REMOTE_ADDR'           => self::OTHER,
					'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
				),
				'cloudflare'
			)
		);
	}

	public function test_cloudflare_peer_without_header_or_with_a_bad_one_falls_back_to_the_peer(): void {
		$this->assertSame( self::CF_EDGE, $this->resolve( array( 'REMOTE_ADDR' => self::CF_EDGE ), 'cloudflare' ) );
		$this->assertSame(
			self::CF_EDGE,
			$this->resolve(
				array(
					'REMOTE_ADDR'           => self::CF_EDGE,
					'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
				),
				'cloudflare'
			)
		);
	}

	public function test_cloudflare_over_ipv6_peer(): void {
		$this->assertSame(
			self::VISITOR,
			$this->resolve(
				array(
					'REMOTE_ADDR'           => self::CF_EDGE_6,
					'HTTP_CF_CONNECTING_IP' => self::VISITOR,
				),
				'cloudflare'
			)
		);
	}

	public function test_proxy_mode_walks_forwarded_for_past_trusted_hops(): void {
		update_option( ISOFT_FMF_Client_Ip::OPTION_PROXIES, "10.0.0.0/8\n192.0.2.7" );

		$ip = $this->resolve(
			array(
				'REMOTE_ADDR'          => '10.1.1.1',
				'HTTP_X_FORWARDED_FOR' => '9.9.9.9, ' . self::VISITOR . ', 192.0.2.7',
			),
			'proxy'
		);

		// The right-most untrusted entry is the visitor; "9.9.9.9" was prepended by the client.
		$this->assertSame( self::VISITOR, $ip );
	}

	public function test_proxy_mode_ignores_forwarded_for_from_an_untrusted_peer(): void {
		update_option( ISOFT_FMF_Client_Ip::OPTION_PROXIES, '10.0.0.0/8' );

		$ip = $this->resolve(
			array(
				'REMOTE_ADDR'          => self::OTHER,
				'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
			),
			'proxy'
		);

		$this->assertSame( self::OTHER, $ip );
	}

	public function test_proxy_mode_stops_at_a_malformed_hop(): void {
		update_option( ISOFT_FMF_Client_Ip::OPTION_PROXIES, '10.0.0.0/8' );

		$ip = $this->resolve(
			array(
				'REMOTE_ADDR'          => '10.1.1.1',
				'HTTP_X_FORWARDED_FOR' => 'garbage',
			),
			'proxy'
		);

		$this->assertSame( '10.1.1.1', $ip );
	}

	public function test_proxy_mode_with_no_trusted_list_is_the_direct_connection(): void {
		$ip = $this->resolve(
			array(
				'REMOTE_ADDR'          => self::OTHER,
				'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
			),
			'proxy'
		);

		$this->assertSame( self::OTHER, $ip );
	}

	public function test_legacy_keeps_the_old_trust_everything_order(): void {
		$server = array(
			'REMOTE_ADDR'          => self::OTHER,
			'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8',
		);

		$this->assertSame( '1.2.3.4', $this->resolve( $server, 'legacy' ) );
	}

	public function test_unset_option_means_legacy_and_unknown_values_too(): void {
		$this->assertSame( 'legacy', ISOFT_FMF_Client_Ip::mode() );

		update_option( ISOFT_FMF_Client_Ip::OPTION_MODE, 'bogus' );
		$this->assertSame( 'legacy', ISOFT_FMF_Client_Ip::mode() );

		update_option( ISOFT_FMF_Client_Ip::OPTION_MODE, 'direct' );
		$this->assertSame( 'direct', ISOFT_FMF_Client_Ip::mode() );
	}

	public function test_no_valid_address_resolves_to_null(): void {
		$this->assertNull( $this->resolve( array( 'REMOTE_ADDR' => 'nope' ), 'direct' ) );
		$this->assertNull( $this->resolve( array(), 'direct' ) );
	}

	public function test_filter_can_override_the_result_but_not_return_garbage(): void {
		add_filter( 'isoft_fmf_client_ip', fn() => '192.0.2.99' );
		$this->assertSame( '192.0.2.99', $this->resolve( array( 'REMOTE_ADDR' => self::VISITOR ), 'direct' ) );

		remove_all_filters( 'isoft_fmf_client_ip' );
		add_filter( 'isoft_fmf_client_ip', fn() => 'rubbish' );
		$this->assertNull( $this->resolve( array( 'REMOTE_ADDR' => self::VISITOR ), 'direct' ) );
	}

	// --- ranges ---------------------------------------------------------

	public function test_in_ranges_handles_cidr_boundaries_and_families(): void {
		$this->assertTrue( ISOFT_FMF_Client_Ip::in_ranges( '10.0.0.1', array( '10.0.0.0/8' ) ) );
		$this->assertTrue( ISOFT_FMF_Client_Ip::in_ranges( '10.255.255.255', array( '10.0.0.0/8' ) ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::in_ranges( '11.0.0.0', array( '10.0.0.0/8' ) ) );
		$this->assertTrue( ISOFT_FMF_Client_Ip::in_ranges( '172.67.1.1', array( '172.64.0.0/13' ) ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::in_ranges( '172.72.0.1', array( '172.64.0.0/13' ) ) );
		$this->assertTrue( ISOFT_FMF_Client_Ip::in_ranges( '192.0.2.7', array( '192.0.2.7' ) ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::in_ranges( '192.0.2.8', array( '192.0.2.7' ) ) );
		$this->assertTrue( ISOFT_FMF_Client_Ip::in_ranges( '2a06:98c0:1::1', array( '2a06:98c0::/29' ) ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::in_ranges( '2a06:98c8::1', array( '2a06:98c0::/29' ) ) );
		// An IPv4 address never matches an IPv6 range and vice versa.
		$this->assertFalse( ISOFT_FMF_Client_Ip::in_ranges( '10.0.0.1', array( '2606:4700::/32' ) ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::in_ranges( '2606:4700::1', array( '10.0.0.0/8' ) ) );
	}

	public function test_ipv4_mapped_ipv6_matches_ipv4_ranges(): void {
		$this->assertTrue( ISOFT_FMF_Client_Ip::in_ranges( '::ffff:104.16.0.1', array( '104.16.0.0/13' ) ) );
	}

	public function test_bundled_cloudflare_list_covers_both_families(): void {
		$ranges = ISOFT_FMF_Client_Ip::cloudflare_ranges();

		$this->assertContains( '173.245.48.0/20', $ranges );
		$this->assertContains( '2400:cb00::/32', $ranges );
		foreach ( $ranges as $range ) {
			$this->assertTrue( ISOFT_FMF_Client_Ip::valid_range( $range ), $range );
		}
	}

	public function test_valid_range_rejects_junk(): void {
		$this->assertTrue( ISOFT_FMF_Client_Ip::valid_range( '10.0.0.0/8' ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::valid_range( '10.0.0.0/33' ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::valid_range( '10.0.0.0/x' ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::valid_range( 'example.com' ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::valid_range( 12 ) );
	}

	// --- rate-limit bucket ---------------------------------------------

	public function test_ipv6_is_bucketed_per_slash_64_and_ipv4_by_address(): void {
		$a = ISOFT_FMF_Client_Ip::bucket( '2001:db8:1:2:aaaa::1' );
		$b = ISOFT_FMF_Client_Ip::bucket( '2001:db8:1:2:bbbb::9' );
		$c = ISOFT_FMF_Client_Ip::bucket( '2001:db8:1:3::1' );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertSame( '203.0.113.50', ISOFT_FMF_Client_Ip::bucket( '203.0.113.50' ) );
		$this->assertSame( 'unknown', ISOFT_FMF_Client_Ip::bucket( null ) );
		$this->assertSame( 'unknown', ISOFT_FMF_Client_Ip::bucket( 'garbage' ) );
	}

	// --- detection ------------------------------------------------------

	public function test_detect_suggests_cloudflare_only_when_the_request_came_through_it(): void {
		$this->assertSame(
			'cloudflare',
			ISOFT_FMF_Client_Ip::detect(
				array(
					'REMOTE_ADDR'           => self::CF_EDGE,
					'HTTP_CF_CONNECTING_IP' => self::VISITOR,
				)
			)
		);
		// A spoofed header from an ordinary peer must not look like Cloudflare.
		$this->assertSame(
			'direct',
			ISOFT_FMF_Client_Ip::detect(
				array(
					'REMOTE_ADDR'           => self::OTHER,
					'HTTP_CF_CONNECTING_IP' => self::VISITOR,
				)
			)
		);
		$this->assertSame( 'direct', ISOFT_FMF_Client_Ip::detect( array( 'REMOTE_ADDR' => self::OTHER ) ) );
	}

	public function test_looks_proxied_flags_forwarding_headers_from_non_cloudflare_peers(): void {
		$this->assertTrue( ISOFT_FMF_Client_Ip::looks_proxied( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4' ) ) );
		$this->assertFalse( ISOFT_FMF_Client_Ip::looks_proxied( array( 'REMOTE_ADDR' => '10.0.0.1' ) ) );
		$this->assertFalse(
			ISOFT_FMF_Client_Ip::looks_proxied(
				array(
					'REMOTE_ADDR'           => self::CF_EDGE,
					'HTTP_CF_CONNECTING_IP' => self::VISITOR,
				)
			)
		);
	}

	// --- sanitisers -----------------------------------------------------

	public function test_mode_sanitiser_falls_back_to_the_safe_choice(): void {
		$this->assertSame( 'cloudflare', ISOFT_FMF_Client_Ip::sanitize_mode( 'Cloudflare' ) );
		$this->assertSame( 'direct', ISOFT_FMF_Client_Ip::sanitize_mode( 'bogus' ) );
		$this->assertSame( 'direct', ISOFT_FMF_Client_Ip::sanitize_mode( array() ) );
	}

	public function test_proxy_list_sanitiser_keeps_valid_unique_entries(): void {
		$clean = ISOFT_FMF_Client_Ip::sanitize_proxy_list( "10.0.0.0/8, evil.example\n10.0.0.0/8  192.0.2.7 \n300.1.1.1" );

		$this->assertSame( "10.0.0.0/8\n192.0.2.7", $clean );
	}

	// --- optional refresh ----------------------------------------------

	public function test_refresh_makes_no_request_unless_enabled(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return new WP_Error( 'blocked' );
			}
		);

		ISOFT_FMF_Client_Ip::maybe_refresh_ranges();

		$this->assertSame( 0, $calls );
	}

	public function test_refresh_stores_a_clean_list_and_keeps_the_old_one_on_bad_data(): void {
		update_option( ISOFT_FMF_Client_Ip::OPTION_REFRESH, 1 );
		$body = array(
			'ips-v4' => "1.1.1.0/24\n2.2.0.0/16\n3.0.0.0/8\n4.4.4.0/24",
			'ips-v6' => "2606:4700::/32\n2400:cb00::/32",
		);
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$body ) {
				$key = str_ends_with( $url, 'ips-v6' ) ? 'ips-v6' : 'ips-v4';
				return array(
					'headers'  => array(),
					'body'     => $body[ $key ],
					'response' => array( 'code' => 200 ),
				);
			},
			10,
			3
		);

		ISOFT_FMF_Client_Ip::maybe_refresh_ranges();
		$this->assertContains( '1.1.1.0/24', ISOFT_FMF_Client_Ip::cloudflare_ranges() );

		// A malformed response a week later must not replace the good list.
		update_option( 'isoft_fmf_cloudflare_ranges_fetched', time() - 8 * DAY_IN_SECONDS );
		$body['ips-v4'] = "<html>error</html>\n";
		ISOFT_FMF_Client_Ip::maybe_refresh_ranges();
		$this->assertContains( '1.1.1.0/24', ISOFT_FMF_Client_Ip::cloudflare_ranges() );
	}

	public function test_refresh_is_throttled_to_about_once_a_week(): void {
		update_option( ISOFT_FMF_Client_Ip::OPTION_REFRESH, 1 );
		update_option( 'isoft_fmf_cloudflare_ranges_fetched', time() - DAY_IN_SECONDS );
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return new WP_Error( 'blocked' );
			}
		);

		ISOFT_FMF_Client_Ip::maybe_refresh_ranges();

		$this->assertSame( 0, $calls );
	}

	// --- integration ----------------------------------------------------

	public function test_logger_and_helper_use_the_same_resolver(): void {
		update_option( ISOFT_FMF_Client_Ip::OPTION_MODE, 'direct' );
		$_SERVER['REMOTE_ADDR']          = self::VISITOR;
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$this->assertSame( self::VISITOR, isoft_fmf_client_ip() );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_new_install_default_is_direct_when_not_behind_cloudflare(): void {
		$this->assertSame( 'direct', ISOFT_FMF_Client_Ip::detect( array( 'REMOTE_ADDR' => self::OTHER ) ) );
	}

	public function test_settings_schema_knows_the_new_options_and_sanitises_them(): void {
		$this->assertContains( 'isoft_fmf_client_ip_mode', ISOFT_FMF_Settings_Service::known_keys() );
		$this->assertContains( 'isoft_fmf_trusted_proxies', ISOFT_FMF_Settings_Service::known_keys() );
		$this->assertContains( 'isoft_fmf_cloudflare_ranges_refresh', ISOFT_FMF_Settings_Service::known_keys() );

		$sanitizer = ISOFT_FMF_Settings_Service::sanitizer_for( 'isoft_fmf_client_ip_mode' );
		$this->assertSame( 'direct', call_user_func( $sanitizer, 'bogus' ) );
	}
}
