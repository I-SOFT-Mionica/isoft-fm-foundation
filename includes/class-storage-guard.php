<?php
/**
 * Keeps Foundation's file storage (wp-content/uploads/isoft-fmf-files/) out
 * of direct web reach on every web server, and checks that it really is.
 *
 * Files must only ever be served through the download handler, which applies
 * access rules. The storage folder sits inside the uploads directory, so the
 * guard depends on the web server:
 *
 *   - Apache and LiteSpeed obey the .htaccess written on activation.
 *   - IIS obeys the web.config written next to it.
 *   - nginx, Caddy, FrankenPHP and others obey neither; the site owner has to
 *     add one rule to the server configuration.
 *
 * Because that last case cannot be fixed from PHP, Site Health runs a real
 * check: it places a small probe file in storage and requests it by URL. If
 * the probe comes back, the folder is exposed and the test is critical, with
 * the rule to add for the common servers.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Storage_Guard {

	public const PROBE = 'isoft-fmf-probe.txt';

	public function register_hooks(): void {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
	}

	/**
	 * Write the guard files into the storage folder. Existing files are left
	 * alone (a site owner may have adjusted them). Safe to call repeatedly.
	 */
	public static function write_guards(): void {
		$dir = isoft_fmf_files_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$files = array(
			'.htaccess'  => "# I-Soft File Manager: Foundation: block all direct web access.\n# Files are served exclusively through the plugin's secure download handler.\nOptions -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- I-Soft File Manager: Foundation: block all direct web access (IIS). -->\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n    <directoryBrowse enabled=\"false\" />\n  </system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $contents ) {
			$path = "{$dir}/{$name}";
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs during activation, before WP_Filesystem is set up.
			}
		}
	}

	/**
	 * Public URL of the storage folder, as a visitor would request it.
	 */
	public static function storage_url(): string {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( (string) $uploads['baseurl'] ) . 'isoft-fmf-files';
	}

	/**
	 * Server rules that close the folder, keyed by server name. Shown in Site
	 * Health and documented in the readme.
	 *
	 * @return array<string,string>
	 */
	public static function server_rules(): array {
		$path = (string) wp_parse_url( self::storage_url(), PHP_URL_PATH );
		return array(
			'nginx' => "location ^~ {$path}/ {\n    deny all;\n    return 403;\n}",
			'Caddy' => "@isoftfmf path {$path}/*\nrespond @isoftfmf 403",
		);
	}

	/**
	 * @param array<string,array> $tests Site Health tests.
	 * @return array<string,array>
	 */
	public function register_test( array $tests ): array {
		$tests['direct']['isoft_fmf_storage_guard'] = array(
			'label' => __( 'Download storage is not directly reachable', 'isoft-fm-foundation' ),
			'test'  => array( $this, 'run_test' ),
		);
		return $tests;
	}

	/**
	 * Request the probe file by URL and report whether storage is exposed.
	 *
	 * @return array{status:string, label:string, badge:array, description:string, actions:string, test:string}
	 */
	public function run_test(): array {
		$result = array(
			'label'       => __( 'Download storage is not directly reachable', 'isoft-fm-foundation' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'isoft-fm-foundation' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Files in the download storage folder can only be fetched through the download handler, which applies access rules.', 'isoft-fm-foundation' ) . '</p>',
			'actions'     => '',
			'test'        => 'isoft_fmf_storage_guard',
		);

		$check = self::check();
		if ( 'exposed' === $check ) {
			$rules = '';
			foreach ( self::server_rules() as $server => $rule ) {
				$rules .= '<p><strong>' . esc_html( $server ) . '</strong></p><pre>' . esc_html( $rule ) . '</pre>';
			}
			$result['status']         = 'critical';
			$result['label']          = __( 'Download storage can be reached directly by URL', 'isoft-fm-foundation' );
			$result['badge']['color'] = 'red';
			$result['description']    = '<p>' . esc_html__( 'The web server serves files from the download storage folder directly, bypassing access rules: restricted files can be fetched by anyone who guesses a path. Apache and IIS are protected automatically; this server ignores those rules. Add the rule for your server to its configuration and reload it, then run this check again.', 'isoft-fm-foundation' ) . '</p>' . $rules;
		} elseif ( 'unknown' === $check ) {
			$result['status']         = 'recommended';
			$result['label']          = __( 'Could not check whether download storage is reachable', 'isoft-fm-foundation' );
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'The site could not request its own storage folder (loopback requests may be blocked). Check by hand: open the address below in a browser. It must be refused or not found.', 'isoft-fm-foundation' ) . '</p><p><code>' . esc_html( self::storage_url() . '/' . self::PROBE ) . '</code></p>';
		}

		return $result;
	}

	/**
	 * 'exposed' when the probe is served by URL, 'safe' when refused or not
	 * found, 'unknown' when the site could not reach itself.
	 */
	public static function check(): string {
		$token = 'isoft-fmf-probe-' . substr( wp_hash( 'isoft_fmf_storage_probe' ), 0, 16 );
		$probe = isoft_fmf_files_dir() . '/' . self::PROBE;
		if ( ! file_exists( $probe ) || trim( (string) file_get_contents( $probe ) ) !== $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local probe file.
			wp_mkdir_p( isoft_fmf_files_dir() );
			file_put_contents( $probe, $token ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small local probe file.
		}

		$response = wp_remote_get(
			self::storage_url() . '/' . self::PROBE,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests.
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code && str_contains( (string) wp_remote_retrieve_body( $response ), $token ) ) {
			return 'exposed';
		}
		return $code > 0 ? 'safe' : 'unknown';
	}
}
