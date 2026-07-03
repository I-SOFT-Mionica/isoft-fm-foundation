<?php
/**
 * Broken Links — thin adapter that mounts the admin shell. The shared
 * partial (admin-shell-mount.php) inlines the integrity-check panel
 * PHP fragment above the mount div for this section.
 */

defined( 'ABSPATH' ) || exit;

$isoft_fmf_section = 'broken-links';
require __DIR__ . '/admin-shell-mount.php';
