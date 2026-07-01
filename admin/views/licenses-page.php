<?php
/**
 * Licenses page — React mount.
 *
 * Rendered by [[ISOFT_FMF_License_Manager::render_page]]. The React app in
 * `blocks/licenses-page/` owns layout, list, modal, notices, and
 * submission via [[ISOFT_FMF_Rest_Licenses]]. The PHP fallback form was
 * removed in 0.12.5 demolition; if the built bundle isn't enqueued
 * (missing asset, JS disabled), the noscript notice is what admins see.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
	wp_die( esc_html__( 'You do not have permission to manage licenses.', 'isoft-fm-foundation' ), 403 );
}
?>
<div class="wrap">
	<div id="isoft-fmf-licenses-root"></div>
	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The Licenses page requires JavaScript. Please enable JavaScript in your browser.', 'isoft-fm-foundation' ); ?></p>
		</div>
	</noscript>
</div>
