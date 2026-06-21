<?php
/**
 * Pure data layer for the licenses table. No superglobals, no redirects, no
 * admin UI — just CRUD + cache + the seed list. The REST controller
 * (ISOFT_FMF_Rest_Licenses) and the legacy admin form handler
 * (ISOFT_FMF_License_Manager) both call into this. Tests pin behaviour here.
 *
 * Sanitization is intentional and centralised: callers can pass raw arrays
 * and trust the service to wp_kses_post() / esc_url_raw() / sanitize_title()
 * fields appropriately. REST args schemas may sanitize ahead of time; the
 * service's repeat sanitization is idempotent and defensive.
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_License_Service {

	public const CACHE_GROUP = 'isoft_fmf_licenses';

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'isoft_fmf_licenses';
	}

	/**
	 * Canonical seed list. Single source of truth for the activator's
	 * first-install seed AND the admin "Restore seeded licenses" button.
	 * Slug is the dedup key — restored seeds never overwrite existing rows
	 * (legal defensibility per [[notary-addon]]).
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

	public static function bust_cache( ?int $id = null ): void {
		wp_cache_delete( 'all_licenses', self::CACHE_GROUP );
		if ( null !== $id && $id > 0 ) {
			wp_cache_delete( "license_{$id}", self::CACHE_GROUP );
		}
	}

	/** @return object[] */
	public function list(): array {
		$cached = wp_cache_get( 'all_licenses', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table read; cached below.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', $this->table )
		) ?: array();
		wp_cache_set( 'all_licenses', $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $rows;
	}

	public function get( int $id ): ?object {
		if ( $id <= 0 ) {
			return null;
		}
		$key    = "license_{$id}";
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table read; cached below.
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

	public function get_by_slug( string $slug ): ?object {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off lookup; not on a hot path.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s',
				$this->table,
				$slug
			)
		);
		return $row ?: null;
	}

	/**
	 * Insert a license. Returns the new ID or 0 on failure.
	 *
	 * @param array<string,mixed> $data Raw input — service normalizes.
	 */
	public function create( array $data ): int {
		$row = $this->normalize( $data );
		$fmt = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$ok = $wpdb->insert( $this->table, $row, $fmt );
		if ( false === $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		$this->enforce_single_default( $id, (bool) $row['is_default'] );
		self::bust_cache( $id );
		return $id;
	}

	/**
	 * Update a license. Returns true on success (including no-op updates),
	 * false if the row didn't exist.
	 *
	 * @param array<string,mixed> $data Raw input — service normalizes.
	 */
	public function update( int $id, array $data ): bool {
		if ( $id <= 0 || null === $this->get( $id ) ) {
			return false;
		}
		$row = $this->normalize( $data );
		$fmt = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$wpdb->update( $this->table, $row, array( 'id' => $id ), $fmt, array( '%d' ) );
		$this->enforce_single_default( $id, (bool) $row['is_default'] );
		self::bust_cache( $id );
		return true;
	}

	public function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; cache invalidated below.
		$deleted = $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
		if ( $deleted ) {
			self::bust_cache( $id );
			return true;
		}
		return false;
	}

	/**
	 * Insert any seeded licenses missing by slug. Idempotent — existing slugs
	 * are skipped, never overwritten. Returns the count inserted.
	 *
	 * Called from the activator on first install AND from the admin "Restore
	 * seeded licenses" button to pick up new seeds shipped in plugin updates.
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

	/**
	 * Sanitize raw input into the persisted shape. Idempotent.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function normalize( array $data ): array {
		$title = isset( $data['title'] ) ? sanitize_text_field( wp_unslash( $data['title'] ) ) : '';
		$slug  = isset( $data['slug'] ) && '' !== $data['slug']
			? sanitize_title( wp_unslash( $data['slug'] ) )
			: sanitize_title( $title );
		return array(
			'title'       => $title,
			'slug'        => $slug,
			'description' => isset( $data['description'] ) ? sanitize_text_field( wp_unslash( $data['description'] ) ) : '',
			'full_text'   => isset( $data['full_text'] ) ? wp_kses_post( wp_unslash( $data['full_text'] ) ) : '',
			'url'         => isset( $data['url'] ) ? esc_url_raw( wp_unslash( $data['url'] ) ) : '',
			'is_default'  => (int) ! empty( $data['is_default'] ),
			'sort_order'  => isset( $data['sort_order'] ) ? absint( $data['sort_order'] ) : 0,
		);
	}

	/**
	 * Only one license may carry is_default=1. When the row we just wrote is
	 * the default, clear it on every other row. Fires after every create/update.
	 */
	private function enforce_single_default( int $id, bool $is_default ): void {
		if ( ! $is_default || $id <= 0 ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write; full-group cache flush via caller.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET is_default = 0 WHERE id != %d',
				$this->table,
				$id
			)
		);
	}
}
