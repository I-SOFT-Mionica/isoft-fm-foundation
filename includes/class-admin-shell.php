<?php
/**
 * Enqueues the single React admin-shell bundle on any of the 5 Downloads
 * admin pages, and provides the section-slug map used by both the
 * enqueue callback and admin-shell-mount.php's bootstrap payload.
 *
 * 0.12.6 consolidation: pre-0.12.6, each admin surface had its own
 * enqueue class (class-licenses-page.php, class-stats-page.php,
 * class-log-page.php, class-broken-links-page.php, class-settings-page.php)
 * shipping its own webpack entry. Nav between them re-downloaded ~230 KB
 * of bundled DataViews per click. This class enqueues ONE bundle on all
 * 5 hook suffixes; blocks/admin-shell/index.js reads data-section on
 * the mount div and swaps sections client-side without full reloads.
 *
 * Section slug conventions:
 *   - hook suffix: `isoft_fmf_file_page_isoft-fmf-<section>`
 *   - URL slug:    `isoft-fmf-<section>`
 *   - React key:   `licenses` | `stats` | `log` | `broken-links` | `settings`
 */

defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Admin_Shell {

	public const SCRIPT_HANDLE = 'isoft-fmf-admin-shell';

	/**
	 * Map of admin-page hook suffix -> React section slug.
	 *
	 * @return array<string,string>
	 */
	public static function section_map(): array {
		return array(
			'isoft_fmf_file_page_isoft-fmf-licenses'     => 'licenses',
			'isoft_fmf_file_page_isoft-fmf-stats'        => 'stats',
			'isoft_fmf_file_page_isoft-fmf-log'          => 'log',
			'isoft_fmf_file_page_isoft-fmf-broken-links' => 'broken-links',
			'isoft_fmf_file_page_isoft-fmf-settings'     => 'settings',
		);
	}

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Stashes the active section derived from hook_suffix so
	 * bootstrap_payload() knows which slice to compute. The alternative
	 * — re-deriving from $_GET['page'] inside bootstrap_payload — is
	 * fragile because the mount partial runs from inside a submenu
	 * callback, not the admin_enqueue_scripts hook, and $_GET is the
	 * same but there's no coupling guarantee.
	 */
	private static ?string $active_section = null;

	public static function active_section(): ?string {
		return self::$active_section;
	}

	public function enqueue( string $hook_suffix ): void {
		$section = self::section_map()[ $hook_suffix ] ?? null;
		if ( null === $section ) {
			return;
		}
		self::$active_section = $section;

		$asset_path = ISOFT_FMF_PLUGIN_DIR . 'blocks/build/admin-shell.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			// Built asset missing — local dev where `npm run build`
			// hasn't run, or a malformed deploy. Bail silently; the
			// mount div still renders and admins see the noscript
			// notice.
			return;
		}
		$asset = require $asset_path;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ISOFT_FMF_PLUGIN_URL . 'blocks/build/admin-shell.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? ISOFT_FMF_VERSION,
			true
		);

		// Modal / Notice / ConfirmDialog / DataViews styles ride on
		// wp-components.
		wp_enqueue_style( 'wp-components' );

		// Shared list-table look for the Log + Broken Links DataViews.
		wp_enqueue_style(
			'isoft-fmf-dataviews-table',
			ISOFT_FMF_PLUGIN_URL . 'admin/css/dataviews-table.css',
			array( 'wp-components' ),
			ISOFT_FMF_VERSION
		);

		// Shell-specific chrome (tab strip + section-hide CSS).
		wp_enqueue_style(
			'isoft-fmf-admin-shell',
			ISOFT_FMF_PLUGIN_URL . 'admin/css/admin-shell.css',
			array( 'wp-components' ),
			ISOFT_FMF_VERSION
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'isoft-fm-foundation',
			ISOFT_FMF_PLUGIN_DIR . 'languages'
		);
	}

	/**
	 * Broken-links badge count — cheap and cached (5-min transient in
	 * ISOFT_FMF_File_Integrity::missing_count()), invalidated by
	 * recover / reupload / detach / integrity cron. Always inlined
	 * regardless of active section so the tab strip badge renders
	 * correctly even before that section mounts.
	 */
	private static function badge_count(): int {
		return ISOFT_FMF_File_Integrity::missing_count();
	}

	/**
	 * Compute the bootstrap blob that admin-shell-mount.php emits as
	 * `data-bootstrap` on the mount div.
	 *
	 * As of the 0.12.7 perf pass, this is LAZY: only the active
	 * section's slice is computed on page load. The other four
	 * sections receive an empty placeholder (broken-links keeps the
	 * badge count, which drives the tab strip). When the user
	 * navigates to another section, that section's normal post-mount
	 * REST fetch fires — since the SPA router keeps visited sections
	 * alive in the DOM, each section pays its query cost exactly once
	 * per session instead of all five paying on every page load.
	 *
	 * Pre-0.12.7 shape: ~5-8 uncached DB round-trips per shell page
	 * load (log rows + downloads-list + broken-file rows + license
	 * list + settings + stats), regardless of which tab the user is
	 * actually on. Post-0.12.7: 1-2 (badge + active section's slice),
	 * with the balance amortised across sessions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function bootstrap_payload(): array {
		$section = self::$active_section ?? 'licenses';

		$payload = array(
			'licenses'     => (object) array(),
			'stats'        => (object) array(),
			'log'          => (object) array(),
			'broken-links' => array( 'badgeCount' => self::badge_count() ),
			'settings'     => (object) array(),
		);

		switch ( $section ) {
			case 'licenses':
				$payload['licenses'] = self::licenses_slice();
				break;
			case 'stats':
				$payload['stats'] = self::stats_slice();
				break;
			case 'log':
				$payload['log'] = self::log_slice();
				break;
			case 'broken-links':
				$payload['broken-links'] = array_merge(
					$payload['broken-links'],
					self::broken_links_slice()
				);
				break;
			case 'settings':
				$payload['settings'] = self::settings_slice();
				break;
		}

		return $payload;
	}

	private static function licenses_slice(): array {
		return array(
			'initialItems' => ( new ISOFT_FMF_License_Service() )->list(),
		);
	}

	private static function stats_slice(): array {
		return array(
			'initialOverview' => isoft_fmf_get_stats_overview(),
		);
	}

	private static function log_slice(): array {
		global $wpdb;

		$per_page = 25;

		// Replicates GET /logs default view: no filter, no search,
		// page 1, ORDER BY downloaded_at DESC LIMIT 25.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot inline-bootstrap read; freshness > cache.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.download_id, p.post_title AS download_title,
				        l.file_id, f.file_name, l.user_id, l.user_login, l.ip_address AS user_ip,
				        l.user_agent, l.downloaded_at
				   FROM {$wpdb->prefix}isoft_fmf_download_log l
				   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
				   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
				  ORDER BY l.downloaded_at DESC
				  LIMIT %d",
				$per_page
			)
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Log section's filter-by-download dropdown. get_posts()
		// defaults suppress_filters=true — no explicit key needed.
		$downloads       = get_posts(
			array(
				'post_type'      => 'isoft_fmf_file',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$downloads_shape = array_map(
			static function ( WP_Post $p ): array {
				return array(
					'id'    => $p->ID,
					'title' => $p->post_title,
				);
			},
			$downloads
		);

		$can_purge = current_user_can( 'isoft_fmf_manage_settings' );

		return array(
			'exportBaseUrl'    => admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-log' ),
			'purgeUrl'         => admin_url( 'admin-post.php' ),
			'purgeNonce'       => $can_purge ? wp_create_nonce( 'isoft_fmf_purge_logs' ) : '',
			'retentionDays'    => (int) get_option( 'isoft_fmf_log_retention_days', 365 ),
			'loggingEnabled'   => (bool) get_option( 'isoft_fmf_enable_logging', true ),
			'canExport'        => current_user_can( 'isoft_fmf_export_logs' ),
			'canPurge'         => $can_purge,
			'initialTotal'     => $total,
			'initialPages'     => (int) ceil( $total / $per_page ),
			'initialItems'     => $rows,
			'initialDownloads' => $downloads_shape,
		);
	}

	private static function broken_links_slice(): array {
		$per_page = 25;
		$page     = ( new ISOFT_FMF_Broken_Links_Service() )->list_broken( 1, $per_page );
		return array(
			'initialTotal' => (int) $page['total'],
			'initialPages' => (int) ceil( $page['total'] / $per_page ),
			'initialItems' => $page['items'],
		);
	}

	private static function settings_slice(): array {
		$tabs     = array( 'general', 'display', 'security', 'advanced', 'maintenance', 'extensions' );
		$tab_urls = array();
		foreach ( $tabs as $tab ) {
			$tab_urls[ $tab ] = add_query_arg(
				array(
					'page'      => 'isoft-fmf-settings',
					'post_type' => 'isoft_fmf_file',
					'tab'       => $tab,
				),
				admin_url( 'edit.php' )
			);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector; nonce belongs on form submit, not nav.
		$initial_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		return array(
			'initialTab'    => in_array( $initial_tab, array( 'general', 'display', 'security', 'advanced' ), true ) ? $initial_tab : 'general',
			'phpTabUrls'    => array(
				'maintenance' => $tab_urls['maintenance'],
				'extensions'  => $tab_urls['extensions'],
			),
			'initialValues' => ( new ISOFT_FMF_Settings_Service() )->get_all(),
		);
	}
}
