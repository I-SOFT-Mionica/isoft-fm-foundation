<?php
/**
 * Shared admin-shell mount partial.
 *
 * Emits `<div id="isoft-fmf-admin-root">` with:
 *   - data-bootstrap: JSON blob carrying `active = { top, sub }` +
 *     the active sub-section's slice (+ badge count, always inlined).
 *
 * As of 0.12.8 the shell has 3 top-level sections (Licenses / Tools /
 * Settings). Tools houses Statistics / Download Log / Broken Links as
 * sub-tabs; Settings houses General / Display / Security / Advanced /
 * Maintenance / Extensions. The React shell reads bootstrap.active and
 * mounts the right combo; subsequent nav is client-side.
 *
 * The Broken Links integrity-check panel is a PHP fragment (server-
 * side lock state + admin-post.php trigger) that only renders when
 * the shell landed on that sub-tab.
 *
 * The 6 per-URL views (licenses-page.php, tools-page.php,
 * stats-dashboard.php, log-viewer.php, broken-links-page.php,
 * settings-page.php) each reduce to a one-line require of this
 * partial with `$isoft_fmf_section` set. The shell's PHP side reads
 * the WP hook_suffix (not $isoft_fmf_section) to derive top+sub.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $isoft_fmf_section is set by the including view (already prefixed).

if ( ! isset( $isoft_fmf_section ) || ! in_array( $isoft_fmf_section, array( 'licenses', 'tools', 'stats', 'log', 'broken-links', 'settings' ), true ) ) {
	return;
}

$isoft_fmf_bootstrap            = ISOFT_FMF_Admin_Shell::bootstrap_payload();
$isoft_fmf_show_integrity_panel = 'tools' === $isoft_fmf_bootstrap['active']['top']
	&& 'broken-links' === ( $isoft_fmf_bootstrap['active']['sub'] ?? '' );
?>
<div class="wrap isoft-fmf-shell-wrap">
	<?php if ( $isoft_fmf_show_integrity_panel ) : ?>
		<?php require __DIR__ . '/broken-links-integrity-panel.php'; ?>
	<?php endif; ?>

	<div
		id="isoft-fmf-admin-root"
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
