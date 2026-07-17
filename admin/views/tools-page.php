<?php
/**
 * Tools page — thin adapter that mounts the admin shell. The shell
 * renders Statistics / Download Log / Broken Links as horizontal
 * sub-tabs inside a single Tools card, replacing the pre-0.12.8
 * three-separate-sidebar-entries UX.
 *
 * The legacy per-URL views (log-viewer.php, stats-dashboard.php,
 * broken-links-page.php) still exist and each delegate to the same
 * shell partial with $isoft_fmf_section set — those URLs stay live
 * for bookmarks, but the sidebar only shows the Tools entry.
 */

defined( 'ABSPATH' ) || exit;

$isoft_fmf_section = 'tools';
require __DIR__ . '/admin-shell-mount.php';
