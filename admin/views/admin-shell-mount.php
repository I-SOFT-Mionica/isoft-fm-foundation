<?php
/**
 * Shared admin-shell mount partial.
 *
 * Emits one <div id="isoft-fmf-admin-root"> per admin surface, carrying:
 *   - data-section: which of the 5 React sections should render first
 *   - data-bootstrap: JSON blob of every section's server-computed
 *     hints (initial counts, retention days, purge nonce, ...)
 *
 * The React shell in blocks/admin-shell/index.js reads both and mounts
 * the section; subsequent nav between the 5 sections is client-side.
 *
 * The five per-page views (licenses-page.php, stats-dashboard.php,
 * log-viewer.php, broken-links-page.php, settings-page.php) all
 * reduce to a one-line require of this partial with `$isoft_fmf_section`
 * set to the section slug.
 *
 * Broken Links keeps its PHP integrity-check panel above the mount
 * (server-side lock state + admin-post.php trigger, not a REST/list
 * surface). Section is inlined here rather than in a separate file
 * because it's small enough and only conditionally rendered.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $isoft_fmf_section is set by the including view (already prefixed).

if ( ! isset( $isoft_fmf_section ) || ! in_array( $isoft_fmf_section, array( 'licenses', 'stats', 'log', 'broken-links', 'settings' ), true ) ) {
	return;
}

$isoft_fmf_bootstrap = ISOFT_FMF_Admin_Shell::bootstrap_payload();
?>
<div class="wrap isoft-fmf-shell-wrap">
	<?php if ( 'broken-links' === $isoft_fmf_section ) : ?>
		<?php require __DIR__ . '/broken-links-integrity-panel.php'; ?>
	<?php endif; ?>

	<div
		id="isoft-fmf-admin-root"
		data-section="<?php echo esc_attr( $isoft_fmf_section ); ?>"
		data-bootstrap="<?php echo esc_attr( wp_json_encode( $isoft_fmf_bootstrap ) ); ?>"
	></div>

	<noscript>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'This page requires JavaScript. Please enable JavaScript in your browser.', 'isoft-fm-foundation' ); ?>
			</p>
		</div>
	</noscript>
</div>
