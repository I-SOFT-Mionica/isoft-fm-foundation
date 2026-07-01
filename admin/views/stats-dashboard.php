<?php
/**
 * Statistics dashboard — React mount.
 *
 * Rendered by [[ISOFT_FMF_Settings::render_stats]]. The React app in
 * `blocks/stats-page/` reads from [[ISOFT_FMF_Rest_Api]]'s
 * `/stats/overview`. The PHP fallback dashboard was removed in 0.12.5
 * demolition.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<div id="isoft-fmf-stats-root"></div>
	<noscript>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The Statistics page requires JavaScript. Please enable JavaScript in your browser.', 'isoft-fm-foundation' ); ?></p>
		</div>
	</noscript>
</div>
