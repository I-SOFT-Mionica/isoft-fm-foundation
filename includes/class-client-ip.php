<?php
/**
 * Resolves the visitor's IP address, trusting forwarding headers only when
 * the connection really comes from a proxy the site owner trusts.
 *
 * A header such as CF-Connecting-IP or X-Forwarded-For is just text any
 * client can send. It can be believed only when the TCP peer (REMOTE_ADDR)
 * is a proxy we know, because a visitor cannot make their connection come
 * from a Cloudflare address. Per-IP rate limits and the download log both
 * depend on this; trusting headers blindly lets anyone dodge a limit or
 * spend another visitor's allowance.
 *
 * Modes (option isoft_fmf_client_ip_mode):
 *   direct      REMOTE_ADDR only.
 *   cloudflare  CF-Connecting-IP, when REMOTE_ADDR is a Cloudflare address.
 *   proxy       X-Forwarded-For walked right to left past the proxies listed
 *               in isoft_fmf_trusted_proxies.
 *   legacy      Trust every header (the behaviour before this setting existed). Used while
 *               the option is unset so existing sites do not change under
 *               their owners; an admin notice invites a decision.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Client_Ip {

	public const MODE_LEGACY     = 'legacy';
	public const MODE_DIRECT     = 'direct';
	public const MODE_CLOUDFLARE = 'cloudflare';
	public const MODE_PROXY      = 'proxy';

	public const OPTION_MODE            = 'isoft_fmf_client_ip_mode';
	public const OPTION_PROXIES         = 'isoft_fmf_trusted_proxies';
	public const OPTION_REFRESH         = 'isoft_fmf_cloudflare_ranges_refresh';
	private const OPTION_RANGES         = 'isoft_fmf_cloudflare_ranges';
	private const OPTION_RANGES_FETCHED = 'isoft_fmf_cloudflare_ranges_fetched';

	/**
	 * Cloudflare's published ranges (https://www.cloudflare.com/ips/), bundled
	 * so no request is made at runtime. The optional refresh replaces them.
	 */
	private const CLOUDFLARE_V4 = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

	private const CLOUDFLARE_V6 = array(
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * @return string[]
	 */
	public static function modes(): array {
		return array( self::MODE_LEGACY, self::MODE_DIRECT, self::MODE_CLOUDFLARE, self::MODE_PROXY );
	}

	/**
	 * Active mode. An unset option means an existing site that has not chosen
	 * yet: legacy, so nothing changes until the owner decides.
	 */
	public static function mode(): string {
		$mode = (string) get_option( self::OPTION_MODE, self::MODE_LEGACY );
		return in_array( $mode, self::modes(), true ) ? $mode : self::MODE_LEGACY;
	}

	/**
	 * The visitor's IP address under the active mode, or null when none is valid.
	 *
	 * @param array<string, mixed>|null $server Server variables; defaults to $_SERVER.
	 * @param string|null               $mode   Force a mode (tests, previews).
	 */
	public static function resolve( ?array $server = null, ?string $mode = null ): ?string {
		$server = self::server_values( $server );
		$mode   = $mode && in_array( $mode, self::modes(), true ) ? $mode : self::mode();
		$remote = self::valid( $server['REMOTE_ADDR'] ?? '' );

		switch ( $mode ) {
			case self::MODE_DIRECT:
				$ip = $remote;
				break;

			case self::MODE_CLOUDFLARE:
				$ip     = $remote;
				$header = self::valid( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
				if ( $remote && $header && self::in_ranges( $remote, self::cloudflare_ranges() ) ) {
					$ip = $header;
				}
				break;

			case self::MODE_PROXY:
				$ip = self::walk_forwarded_for( $remote, (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' ), self::trusted_proxies() );
				break;

			default:
				$ip = self::legacy( $server );
		}

		/**
		 * Filters the resolved client IP, e.g. for hosts with another proxy setup.
		 *
		 * @param string|null $ip   Resolved address, or null.
		 * @param string      $mode Active mode.
		 */
		$ip = apply_filters( 'isoft_fmf_client_ip', $ip, $mode );
		return is_string( $ip ) && self::valid( $ip ) ? $ip : null;
	}

	/**
	 * Key to rate-limit by. IPv4 is the address itself; an IPv6 visitor
	 * controls a whole /64, so it is bucketed per /64 and rotating through
	 * addresses does not dodge the limit.
	 */
	public static function bucket( ?string $ip ): string {
		if ( null === $ip || '' === $ip ) {
			return 'unknown';
		}
		$packed = self::pack( $ip );
		if ( null === $packed ) {
			return 'unknown';
		}
		if ( 4 === strlen( $packed ) ) {
			return implode( '.', array_map( 'ord', str_split( $packed ) ) );
		}
		return bin2hex( substr( $packed, 0, 8 ) ) . '/64';
	}

	/**
	 * Mode this request suggests: cloudflare when it demonstrably came
	 * through Cloudflare, otherwise direct.
	 *
	 * @param array<string, mixed>|null $server Server variables; defaults to $_SERVER.
	 */
	public static function detect( ?array $server = null ): string {
		$server = self::server_values( $server );
		$remote = self::valid( $server['REMOTE_ADDR'] ?? '' );
		if ( $remote && self::valid( $server['HTTP_CF_CONNECTING_IP'] ?? '' ) && self::in_ranges( $remote, self::cloudflare_ranges() ) ) {
			return self::MODE_CLOUDFLARE;
		}
		return self::MODE_DIRECT;
	}

	/**
	 * Whether this request carries forwarding headers from a peer that is not
	 * Cloudflare, which suggests an unlisted reverse proxy.
	 *
	 * @param array<string, mixed>|null $server Server variables; defaults to $_SERVER.
	 */
	public static function looks_proxied( ?array $server = null ): bool {
		$server = self::server_values( $server );
		return self::MODE_CLOUDFLARE !== self::detect( $server )
			&& ( ! empty( $server['HTTP_X_FORWARDED_FOR'] ) || ! empty( $server['HTTP_X_REAL_IP'] ) || ! empty( $server['HTTP_CF_CONNECTING_IP'] ) );
	}

	// -------------------------------------------------------------------------
	// Ranges
	// -------------------------------------------------------------------------

	/**
	 * @return string[] Cloudflare CIDR ranges (IPv4 and IPv6).
	 */
	public static function cloudflare_ranges(): array {
		$stored = get_option( self::OPTION_RANGES, array() );
		if ( is_array( $stored ) && $stored ) {
			return $stored;
		}
		return array_merge( self::CLOUDFLARE_V4, self::CLOUDFLARE_V6 );
	}

	/**
	 * @return string[] Trusted proxy addresses / CIDR ranges the admin listed.
	 */
	public static function trusted_proxies(): array {
		$lines = preg_split( '/[\s,]+/', (string) get_option( self::OPTION_PROXIES, '' ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( (array) $lines, array( self::class, 'valid_range' ) ) );
	}

	/**
	 * Whether an address falls inside any of the given CIDR ranges (a bare
	 * address counts as a single-address range).
	 *
	 * @param string   $ip     Address.
	 * @param string[] $ranges CIDR ranges or addresses.
	 */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$packed = self::pack( $ip );
		if ( null === $packed ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$parts = explode( '/', (string) $range, 2 );
			$base  = self::pack( $parts[0] );
			if ( null === $base || strlen( $base ) !== strlen( $packed ) ) {
				continue;
			}
			$max  = strlen( $base ) * 8;
			$bits = isset( $parts[1] ) ? (int) $parts[1] : $max;
			if ( $bits < 0 || $bits > $max ) {
				continue;
			}
			$bytes = intdiv( $bits, 8 );
			if ( 0 !== $bytes && substr( $packed, 0, $bytes ) !== substr( $base, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $base[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a string is an IP address or a valid CIDR range.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function valid_range( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$parts = explode( '/', $value, 2 );
		$base  = self::pack( $parts[0] );
		if ( null === $base ) {
			return false;
		}
		if ( ! isset( $parts[1] ) ) {
			return true;
		}
		return ctype_digit( $parts[1] ) && (int) $parts[1] <= strlen( $base ) * 8;
	}

	// -------------------------------------------------------------------------
	// Settings sanitisers
	// -------------------------------------------------------------------------

	/**
	 * @param mixed $value Raw mode.
	 */
	public static function sanitize_mode( $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $value, self::modes(), true ) ? $value : self::MODE_DIRECT;
	}

	/**
	 * Keep only valid addresses and CIDR ranges, one per line.
	 *
	 * @param mixed $value Raw textarea value.
	 */
	public static function sanitize_proxy_list( $value ): string {
		$items = preg_split( '/[\s,]+/', is_string( $value ) ? $value : '', -1, PREG_SPLIT_NO_EMPTY );
		$items = array_unique( array_filter( (array) $items, array( self::class, 'valid_range' ) ) );
		return implode( "\n", $items );
	}

	// -------------------------------------------------------------------------
	// Optional range refresh (off by default; no outbound request otherwise)
	// -------------------------------------------------------------------------

	/**
	 * Re-fetch Cloudflare's ranges when the owner opted in and the stored
	 * copy is older than six days. Called from the daily cron. A response
	 * that does not parse cleanly is discarded and the previous list stays.
	 */
	public static function maybe_refresh_ranges(): void {
		if ( ! get_option( self::OPTION_REFRESH, 0 ) ) {
			return;
		}
		if ( time() - (int) get_option( self::OPTION_RANGES_FETCHED, 0 ) < 6 * DAY_IN_SECONDS ) {
			return;
		}

		$ranges = array();
		foreach ( array( 'https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6' ) as $url ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return;
			}
			$lines = preg_split( '/\s+/', trim( (string) wp_remote_retrieve_body( $response ) ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( (array) $lines as $line ) {
				if ( ! self::valid_range( $line ) ) {
					return;
				}
				$ranges[] = $line;
			}
		}
		if ( count( $ranges ) < 5 ) {
			return;
		}

		update_option( self::OPTION_RANGES, $ranges, false );
		update_option( self::OPTION_RANGES_FETCHED, time(), false );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * @param array<int, string> $trusted Trusted proxy ranges.
	 */
	private static function walk_forwarded_for( ?string $remote, string $forwarded, array $trusted ): ?string {
		if ( null === $remote ) {
			return null;
		}
		$ip   = $remote;
		$hops = array_reverse( array_map( 'trim', explode( ',', $forwarded ) ) );
		foreach ( $hops as $hop ) {
			if ( ! self::in_ranges( $ip, $trusted ) ) {
				break;
			}
			$clean = self::valid( $hop );
			if ( null === $clean ) {
				break;
			}
			$ip = $clean;
		}
		return $ip;
	}

	/**
	 * The original behaviour: first valid of several client-controlled headers.
	 *
	 * @param array<string, string> $server Server variables.
	 */
	private static function legacy( array $server ): ?string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $header ) {
			if ( empty( $server[ $header ] ) ) {
				continue;
			}
			$ip = $server[ $header ];
			if ( str_contains( $ip, ',' ) ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			$ip = self::valid( $ip );
			if ( null !== $ip ) {
				return $ip;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed>|null $server Supplied server variables, or null for $_SERVER.
	 * @return array<string, string>
	 */
	private static function server_values( ?array $server ): array {
		$keys   = array( 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' );
		$source = $server ?? $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitised and validated below.
		$values = array();
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) ) {
				$values[ $key ] = null === $server ? sanitize_text_field( wp_unslash( $source[ $key ] ) ) : $source[ $key ];
			}
		}
		return $values;
	}

	private static function valid( $ip ): ?string {
		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	/**
	 * Packed address (4 or 16 bytes). IPv4-mapped IPv6 is folded to IPv4 so
	 * "::ffff:1.2.3.4" compares equal to "1.2.3.4".
	 */
	private static function pack( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return substr( $packed, 12 );
		}
		return $packed;
	}
}
