<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_License_Manager {

	private string $table;

	public const CACHE_GROUP = 'isoft_fmf_licenses';

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'isoft_fmf_licenses';
	}

	public static function bust_cache( ?int $id = null ): void {
		wp_cache_delete( 'all_licenses', self::CACHE_GROUP );
		if ( null !== $id && $id > 0 ) {
			wp_cache_delete( "license_{$id}", self::CACHE_GROUP );
		}
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_form_actions' ) );
	}

	/**
	 * Canonical seed list. Single source of truth for both the activator's
	 * first-install seed and the admin "Restore seeded licenses" button —
	 * keeps both code paths in lockstep with one definition.
	 *
	 * Slug is used as the dedup key when restoring on an existing install:
	 * the admin button only ADDS missing slugs, never modifies existing rows.
	 * This is load-bearing for legal defensibility — a restored seed must
	 * not silently rewrite the text or URL of a license that downloads were
	 * already served under (see [[notary-addon]] for the proof chain).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function seed_defaults(): array {
		return array(
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
				'title'       => 'Public Domain — Serbian Law / Јавно власништво (чл. 6 ЗАСП)',
				'slug'        => 'public-domain-sr-art6',
				'description' => 'Official municipal acts and documents — public domain by Art. 6 of the Serbian Copyright Act.',
				'full_text'   => "Овај материјал је у јавном власништву у складу са чланом 6 Закона о ауторском и сродним правима Републике Србије, као званични акт државног органа. Слободан је за коришћење у било коју сврху без потребе за дозволом или навођењем извора.\n\nThis material is in the public domain by operation of Article 6 of the Serbian Copyright and Related Rights Act, as an official act of a state body. Free to use for any purpose without permission or attribution.",
				'url'         => '',
				'is_default'  => 0,
				'sort_order'  => 2,
			),
			array(
				'title'       => 'All Rights Reserved / Сва права задржана',
				'slug'        => 'all-rights-reserved',
				'description' => 'All rights reserved by the author.',
				'full_text'   => 'All rights reserved. No part of this work may be reproduced, distributed, or transmitted in any form or by any means without the prior written permission of the copyright holder.',
				'url'         => '',
				'is_default'  => 0,
				'sort_order'  => 3,
			),
			array(
				'title'       => 'Creative Commons BY 4.0',
				'slug'        => 'cc-by-4',
				'description' => 'Free to use with attribution.',
				'full_text'   => 'This work is licensed under the Creative Commons Attribution 4.0 International License. You are free to share and adapt the material for any purpose, even commercially, as long as you give appropriate credit, provide a link to the license, and indicate if changes were made.',
				'url'         => 'https://creativecommons.org/licenses/by/4.0/',
				'is_default'  => 0,
				'sort_order'  => 4,
			),
			array(
				'title'       => 'Creative Commons BY-SA 4.0 / CC Ауторство-Делити под истим условима 4.0',
				'slug'        => 'cc-by-sa-4',
				'description' => 'Free to use with attribution; derivatives share-alike.',
				'full_text'   => "This work is licensed under the Creative Commons Attribution-ShareAlike 4.0 International License. You are free to share and adapt the material for any purpose, even commercially, as long as you give appropriate credit, provide a link to the license, indicate if changes were made, and distribute your contributions under the same license.\n\nОвај материјал је доступан под лиценцом Creative Commons Ауторство-Делити под истим условима 4.0 International. Слободно делите и адаптирајте материјал у било коју сврху, укључујући комерцијалну, под условом да наведете извор, ставите везу до лиценце, означите ако сте направили измене, и своје деривате доставите под истом лиценцом.",
				'url'         => 'https://creativecommons.org/licenses/by-sa/4.0/',
				'is_default'  => 0,
				'sort_order'  => 5,
			),
			array(
				'title'       => 'Official Use Only / Службена употреба',
				'slug'        => 'official-use-only',
				'description' => 'Restricted to official government use only.',
				'full_text'   => 'This document is intended for official use only. Unauthorized distribution, reproduction, or disclosure of this document is prohibited.',
				'url'         => '',
				'is_default'  => 0,
				'sort_order'  => 6,
			),
		);
	}

	/**
	 * Insert seeded licenses that are missing by slug on the current install.
	 * Idempotent: existing slugs are skipped, never overwritten. Returns the
	 * number of rows actually inserted.
	 *
	 * Called from the activator on first install (when the licenses table is
	 * empty) and from the admin "Restore seeded licenses" button on existing
	 * installs (to pick up new seeds shipped in subsequent plugin versions).
	 */
	public function install_missing_seeds(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Seed install; cache busted on success.
		$existing = $wpdb->get_col( $wpdb->prepare( 'SELECT slug FROM %i', $this->table ) );
		$existing = array_flip( $existing ?: array() );

		$inserted = 0;
		foreach ( self::seed_defaults() as $license ) {
			if ( isset( $existing[ $license['slug'] ] ) ) {
				continue;
			}
			$ok = $wpdb->insert(
				$this->table,
				$license,
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
			);
			if ( false !== $ok ) {
				++$inserted;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $inserted > 0 ) {
			self::bust_cache();
		}

		return $inserted;
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Licenses', 'isoft-fm-foundation' ),
			__( 'Licenses', 'isoft-fm-foundation' ),
			'isoft_fmf_manage_settings',
			'isoft-fmf-licenses',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/licenses-page.php';
	}

	public function handle_form_actions(): void {
		if ( empty( $_POST['isoft_fmf_license_action'] ) ) {
			return;
		}
		check_admin_referer( 'isoft_fmf_license_action' );
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'isoft-fm-foundation' ) );
		}

		$action = sanitize_text_field( wp_unslash( $_POST['isoft_fmf_license_action'] ) );
		if ( 'save' === $action ) {
			$this->save();
		} elseif ( 'delete' === $action ) {
			$this->delete( absint( $_POST['license_id'] ?? 0 ) );
		} elseif ( 'restore_seeds' === $action ) {
			$this->restore_seeds();
		}
	}

	/**
	 * Admin action: install any seeded licenses missing by slug on this
	 * install. Doubles as the "mass-update" channel for plugin releases that
	 * add new seeds — admins click once per release to pick up any new
	 * canonical entries. Existing rows are never touched (add-only by slug).
	 */
	private function restore_seeds(): void {
		$added = $this->install_missing_seeds();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'isoft-fmf-licenses',
					'post_type' => 'isoft_fmf_file',
					'restored'  => (int) $added,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/** @return object[] */
	public function get_all(): array {
		$cached = wp_cache_get( 'all_licenses', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table read; cached below via wp_cache_set().
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', $this->table )
		) ?: array();
		wp_cache_set( 'all_licenses', $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $rows;
	}

	public function get( int $id ): ?object {
		$key    = "license_{$id}";
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table read; cached below via wp_cache_set().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table,
				$id
			)
		) ?: null;
		wp_cache_set( $key, $row, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $row;
	}

	private function save(): void {
		global $wpdb;

		// Nonce verified by caller (handle_form_actions).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id   = absint( $_POST['license_id'] ?? 0 );
		$data = array(
			'title'       => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'slug'        => sanitize_title( wp_unslash( $_POST['slug'] ?? $_POST['title'] ?? '' ) ),
			'description' => sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'full_text'   => wp_kses_post( wp_unslash( $_POST['full_text'] ?? '' ) ),
			'url'         => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
			'is_default'  => (int) ! empty( $_POST['is_default'] ),
			'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
		);
		$fmt  = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' );

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
			$wpdb->update( $this->table, $data, array( 'id' => $id ), $fmt, array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
			$wpdb->insert( $this->table, $data, $fmt );
			$id = (int) $wpdb->insert_id;
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Only one default allowed
		if ( $data['is_default'] && $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; full-group cache flush below.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET is_default = 0 WHERE id != %d',
					$this->table,
					$id
				)
			);
		}

		self::bust_cache( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'isoft-fmf-licenses',
					'post_type' => 'isoft_fmf_file',
					'saved'     => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	private function delete( int $id ): void {
		global $wpdb;
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
			$wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
			self::bust_cache( $id );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'isoft-fmf-licenses',
					'post_type' => 'isoft_fmf_file',
					'deleted'   => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
