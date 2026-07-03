<?php
/**
 * Settings page — thin adapter that mounts the admin shell.
 *
 * As of 0.12.8, the Maintenance and Extensions sub-tabs render inside
 * the shell (their PHP HTML is captured into the bootstrap payload
 * and injected into the React tree). The pre-0.12.8 "jump to a PHP
 * page" branch for those two tabs is gone.
 */

defined( 'ABSPATH' ) || exit;

$isoft_fmf_section = 'settings';
require __DIR__ . '/admin-shell-mount.php';
