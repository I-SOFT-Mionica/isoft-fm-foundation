<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Extension_Api {

	/** @var array<string,array> Registered extensions keyed by slug. */
	private static array $extensions = array();

	public function register_hooks(): void {
		// Fire after init (priority 20) so extensions' plugins_loaded callbacks have run.
		add_action( 'init', array( $this, 'fire_init' ), 20 );
		// Display queued admin notices from extensions
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	public function fire_init(): void {
		do_action( 'isoft_fmf_extensions_init' );
	}

	public function render_admin_notices(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			return;
		}
		$notices = get_option( 'isoft_fmf_admin_notices', array() );
		if ( ! $notices ) {
			return;
		}
		foreach ( $notices as $n ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $n['type'] ),
				esc_html( $n['message'] )
			);
		}
		delete_option( 'isoft_fmf_admin_notices' );
	}

	/**
	 * Register an extension. Called by Sentinel/Orbit inside 'isoft_fmf_extensions_init'.
	 *
	 * @param array{slug:string,name:string,version:string} $args
	 */
	public static function register( array $args ): bool {
		foreach ( array( 'slug', 'name', 'version' ) as $key ) {
			if ( empty( $args[ $key ] ) ) {
				return false;
			}
		}
		self::$extensions[ $args['slug'] ] = $args;
		return true;
	}

	/** @return array<string,array> */
	public static function get_all(): array {
		return self::$extensions;
	}

	public static function get( string $slug ): ?array {
		return self::$extensions[ $slug ] ?? null;
	}
}
