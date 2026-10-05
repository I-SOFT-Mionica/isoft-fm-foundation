<?php
/**
 * Admin notice for sites that have not chosen a "Client IP source" yet.
 *
 * Until the owner chooses, an existing site keeps the legacy behaviour
 * (believe every forwarding header), which lets a visitor dodge the per-IP
 * download limit by sending a made-up header. The notice explains that in
 * plain words, recommends the setting that matches what this very request
 * shows (Cloudflare, direct, or an unlisted proxy), and offers a one-click
 * switch. Choosing any mode, or dismissing, ends it.
 */
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Client_Ip_Notice {

	private const ACTION    = 'isoft_fmf_client_ip_notice';
	private const DISMISSED = 'isoft_fmf_client_ip_notice_dismissed';

	public function register_hooks(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	private static function settings_url(): string {
		return admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-settings&tab=security' );
	}

	/**
	 * Whether the notice should show to the current user on the current screen.
	 */
	private function should_show(): bool {
		if ( false !== get_option( ISOFT_FMF_Client_Ip::OPTION_MODE, false ) || get_option( self::DISMISSED ) ) {
			return false;
		}
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || 'isoft_fmf_file' === $screen->post_type;
	}

	public function render(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		$detected = ISOFT_FMF_Client_Ip::detect();
		if ( ISOFT_FMF_Client_Ip::MODE_CLOUDFLARE === $detected ) {
			$message = __( 'Your site is behind Cloudflare. The download limit and log currently believe any address header a visitor sends, so the limit can be dodged. Switch to Cloudflare mode so each visitor is counted by their real address and the header is only trusted from Cloudflare.', 'isoft-fm-foundation' );
			$mode    = ISOFT_FMF_Client_Ip::MODE_CLOUDFLARE;
			$label   = __( 'Use Cloudflare mode', 'isoft-fm-foundation' );
		} elseif ( ISOFT_FMF_Client_Ip::looks_proxied() ) {
			$message = __( 'Requests to your site arrive through a proxy or load balancer. The download limit and log currently believe any address header a visitor sends, so the limit can be dodged. Choose Other reverse proxy and list your proxy addresses so only they are trusted.', 'isoft-fm-foundation' );
			$mode    = '';
			$label   = '';
		} else {
			$message = __( 'The download limit and log currently believe any address header a visitor sends, so the limit can be dodged. Your site does not appear to sit behind a proxy, so the direct connection address is the safe choice.', 'isoft-fm-foundation' );
			$mode    = ISOFT_FMF_Client_Ip::MODE_DIRECT;
			$label   = __( 'Use the direct connection', 'isoft-fm-foundation' );
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'I-Soft File Manager: Foundation', 'isoft-fm-foundation' ) . '</strong> ' . esc_html( $message ) . '</p><p>';
		if ( '' !== $mode ) {
			printf(
				'<a class="button button-primary" href="%s">%s</a> ',
				esc_url( $this->action_url( 'set', $mode ) ),
				esc_html( $label )
			);
		}
		printf(
			'<a class="button" href="%s">%s</a> <a href="%s">%s</a>',
			esc_url( self::settings_url() ),
			esc_html__( 'Choose in Settings', 'isoft-fm-foundation' ),
			esc_url( $this->action_url( 'dismiss' ) ),
			esc_html__( 'Dismiss', 'isoft-fm-foundation' )
		);
		echo '</p></div>';
	}

	private function action_url( string $do, string $mode = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'do'     => $do,
					'mode'   => $mode,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	public function handle(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'isoft-fm-foundation' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		if ( 'set' === $do ) {
			$mode = isset( $_GET['mode'] ) ? ISOFT_FMF_Client_Ip::sanitize_mode( sanitize_key( wp_unslash( $_GET['mode'] ) ) ) : ISOFT_FMF_Client_Ip::MODE_DIRECT;
			update_option( ISOFT_FMF_Client_Ip::OPTION_MODE, $mode );
		} elseif ( 'dismiss' === $do ) {
			update_option( self::DISMISSED, 1, false );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'plugins.php' ) );
		exit;
	}
}
