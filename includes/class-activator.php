<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Activator {

	public static function activate(): void {
		self::create_tables();
		self::drop_legacy_columns();
		self::register_capabilities();
		self::seed_licenses();
		self::create_file_storage();
		// Can't flush here — the 'isfm_file' CPT isn't registered yet at activation-hook time
		// (plugins_loaded hasn't fired). Set a flag; ISFM_Post_Type::register() will flush
		// on the very next request after the CPT is in place.
		update_option( 'isfm_flush_rewrite_rules', 1 );
	}

	/**
	 * Create the isfm-files/ storage directory and write an .htaccess that
	 * blocks all direct web access. Files must go through ISFM_Download_Handler.
	 */
	private static function create_file_storage(): void {
		$dir = isfm_files_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = "{$dir}/.htaccess";
		if ( ! file_exists( $htaccess ) ) {
			$rules = <<<'HTACCESS'
# I-Soft File Manager: Foundation: block all direct web access.
# Files are served exclusively through the plugin's secure download handler.
Options -Indexes
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs during activation before WP_Filesystem is bootstrapped.
		}
	}

	/**
	 * Hooked to 'init' — ensures caps are registered even if the activation hook
	 * fired before WP_Roles was fully initialised (common in some environments).
	 */
	public static function maybe_register_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'isfm_manage_settings' ) ) {
			self::register_capabilities();

			// The current user object cached its caps before we added ours.
			// Force a refresh so current_user_can() works for the rest of this request.
			$user = wp_get_current_user();
			if ( $user->ID ) {
				$user->get_role_caps();
			}
		}
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}isfm_files (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			download_id     BIGINT UNSIGNED NOT NULL,
			file_type       ENUM('local','external') NOT NULL DEFAULT 'local',
			title           VARCHAR(255) NOT NULL DEFAULT '',
			description     TEXT,
			file_path       VARCHAR(500) DEFAULT NULL,
			file_name       VARCHAR(255) DEFAULT NULL,
			file_size       BIGINT UNSIGNED DEFAULT 0,
			file_mime       VARCHAR(100) DEFAULT NULL,
			file_hash       VARCHAR(64) DEFAULT NULL,
			external_url    VARCHAR(2048) DEFAULT NULL,
			is_mirror       TINYINT(1) NOT NULL DEFAULT 0,
			is_missing      TINYINT(1) NOT NULL DEFAULT 0,
			missing_since   DATETIME NULL DEFAULT NULL,
			inode           BIGINT UNSIGNED NULL DEFAULT NULL,
			download_count  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sort_order      INT NOT NULL DEFAULT 0,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			INDEX idx_download_id (download_id),
			INDEX idx_file_type (file_type),
			INDEX idx_file_hash (file_hash),
			INDEX idx_is_missing (is_missing)
		) $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}isfm_download_log (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			download_id     BIGINT UNSIGNED NOT NULL,
			file_id         BIGINT UNSIGNED NOT NULL,
			user_id         BIGINT UNSIGNED DEFAULT NULL,
			user_login      VARCHAR(60) DEFAULT NULL,
			ip_address      VARCHAR(45) DEFAULT NULL,
			user_agent      VARCHAR(500) DEFAULT NULL,
			referer         VARCHAR(2048) DEFAULT NULL,
			downloaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			log_date        DATE NOT NULL DEFAULT '0000-00-00',
			PRIMARY KEY (id),
			INDEX idx_download_id (download_id),
			INDEX idx_file_id (file_id),
			INDEX idx_user_id (user_id),
			INDEX idx_downloaded_at (downloaded_at),
			INDEX idx_log_date (log_date)
		) $charset_collate;"
		);

		// Daily download counts — one row per download per day, updated on each download.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}isfm_download_daily (
			download_id     BIGINT UNSIGNED NOT NULL,
			log_date        DATE NOT NULL,
			count           BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (download_id, log_date),
			INDEX idx_log_date (log_date)
		) $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}isfm_licenses (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title       VARCHAR(255) NOT NULL,
			slug        VARCHAR(255) NOT NULL,
			description TEXT,
			full_text   LONGTEXT,
			url         VARCHAR(2048) DEFAULT NULL,
			is_default  TINYINT(1) NOT NULL DEFAULT 0,
			sort_order  INT NOT NULL DEFAULT 0,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE INDEX idx_slug (slug)
		) $charset_collate;"
		);

		update_option( 'isfm_db_version', ISFM_VERSION );
	}

	/**
	 * Remove columns/options that are no longer used (dead weight from the
	 * media-library era). dbDelta() never drops columns on its own, so this
	 * runs explicitly on every activation / version bump.
	 */
	private static function drop_legacy_columns(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Activator: one-shot schema/upgrade cleanup; dbDelta does not drop columns.
		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE()
			    AND TABLE_NAME   = %s
			    AND COLUMN_NAME  = 'attachment_id'",
				"{$wpdb->prefix}isfm_files"
			)
		);
		if ( $has_column ) {
			$wpdb->query( "ALTER TABLE {$wpdb->prefix}isfm_files DROP COLUMN attachment_id" );
		}

		// Obsolete options from the media-library mode.
		delete_option( 'isfm_storage_mode' );
		delete_option( 'isfm_custom_folder' );

		// Removed in 0.6.1: arbitrary CSS injection is disallowed by the WP.org
		// plugin guidelines.
		delete_option( 'isfm_custom_css' );

		// Obsolete per-download storage-mode post meta.
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_isfm_storage_mode'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	private static function register_capabilities(): void {
		$role_caps = array(
			'subscriber'    => array( 'isfm_view_downloads' ),
			'contributor'   => array( 'isfm_view_downloads' ),
			'author'        => array( 'isfm_view_downloads', 'isfm_create_downloads', 'isfm_edit_own_downloads' ),
			'editor'        => array( 'isfm_view_downloads', 'isfm_create_downloads', 'isfm_edit_own_downloads', 'isfm_edit_all_downloads', 'isfm_delete_downloads', 'isfm_manage_categories', 'isfm_view_logs' ),
			'administrator' => array( 'isfm_view_downloads', 'isfm_create_downloads', 'isfm_edit_own_downloads', 'isfm_edit_all_downloads', 'isfm_delete_downloads', 'isfm_manage_categories', 'isfm_view_logs', 'isfm_export_logs', 'isfm_manage_settings' ),
		);

		foreach ( $role_caps as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	private static function seed_licenses(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Activator: one-shot seed on fresh install.
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isfm_licenses" ) > 0 ) {
			return;
		}

		$licenses = array(
			array(
				'title'       => 'Public Domain / Јавно власништво',
				'slug'        => 'public-domain',
				'description' => 'No rights reserved. Free for any use.',
				'full_text'   => 'This work has been released into the public domain. Anyone is free to copy, modify, publish, use, compile, sell, or distribute this work, in any medium or format, for any purpose, commercial or non-commercial, without asking permission.',
				'url'         => 'https://creativecommons.org/publicdomain/zero/1.0/',
				'is_default'  => 0,
				'sort_order'  => 1,
			),
			array(
				'title'       => 'All Rights Reserved / Сва права задржана',
				'slug'        => 'all-rights-reserved',
				'description' => 'All rights reserved by the author.',
				'full_text'   => 'All rights reserved. No part of this work may be reproduced, distributed, or transmitted in any form or by any means without the prior written permission of the copyright holder.',
				'url'         => '',
				'is_default'  => 0,
				'sort_order'  => 2,
			),
			array(
				'title'       => 'Creative Commons BY 4.0',
				'slug'        => 'cc-by-4',
				'description' => 'Free to use with attribution.',
				'full_text'   => 'This work is licensed under the Creative Commons Attribution 4.0 International License. You are free to share and adapt the material for any purpose, even commercially, as long as you give appropriate credit, provide a link to the license, and indicate if changes were made.',
				'url'         => 'https://creativecommons.org/licenses/by/4.0/',
				'is_default'  => 0,
				'sort_order'  => 3,
			),
			array(
				'title'       => 'Official Use Only / Службена употреба',
				'slug'        => 'official-use-only',
				'description' => 'Restricted to official government use only.',
				'full_text'   => 'This document is intended for official use only. Unauthorized distribution, reproduction, or disclosure of this document is prohibited.',
				'url'         => '',
				'is_default'  => 0,
				'sort_order'  => 4,
			),
		);

		foreach ( $licenses as $license ) {
			$wpdb->insert( "{$wpdb->prefix}isfm_licenses", $license, array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
