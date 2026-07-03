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

	public function enqueue( string $hook_suffix ): void {
		if ( ! array_key_exists( $hook_suffix, self::section_map() ) ) {
			return;
		}

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
	 * Compute the per-section bootstrap blob that admin-shell-mount.php
	 * emits as `data-bootstrap` on the mount div.
	 *
	 * Every section's bootstrap ships on every page load, so a user
	 * landing on Statistics gets the Log's initial count already
	 * hydrated — the tab strip's Broken Links badge is accurate before
	 * the section itself mounts.
	 *
	 * As of the 0.12.6 perf pass, each section also ships its full
	 * first-paint data (log rows, broken-links list, settings values,
	 * license table, stats overview) so the initial render does zero
	 * REST calls. The old classic-admin property — "one PHP request
	 * renders everything visible" — is restored while React interactive
	 * behaviour (search, filter, pagination, CRUD) still routes through
	 * REST after mount.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function bootstrap_payload(): array {
		global $wpdb;

		$per_page = 25;

		// -----------------------------------------------------------------
		// Log — replicates the default view of GET /logs (no filter,
		// no search, page 1, ORDER BY downloaded_at DESC, LIMIT 25).
		// -----------------------------------------------------------------
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot inline-bootstrap read; freshness > cache.
		$log_initial_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}isoft_fmf_download_log" );
		$log_rows          = $wpdb->get_results(
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

		// The Log section's filter dropdown lists every download; the
		// pre-inline shape fetched /downloads?per_page=100. Same shape
		// here so React can drop the extra fetch.
		$log_downloads       = get_posts(
			array(
				'post_type'        => 'isoft_fmf_file',
				'post_status'      => 'any',
				'posts_per_page'   => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$log_downloads_shape = array_map(
			static function ( WP_Post $p ): array {
				return array(
					'id'    => $p->ID,
					'title' => $p->post_title,
				);
			},
			$log_downloads
		);

		// -----------------------------------------------------------------
		// Broken links — first 25 rows via the existing service. It
		// already returns { items, total } and shapes each item exactly
		// as the REST endpoint does.
		// -----------------------------------------------------------------
		$broken_service = new ISOFT_FMF_Broken_Links_Service();
		$broken_page    = $broken_service->list_broken( 1, $per_page );

		// -----------------------------------------------------------------
		// Licenses — full table (small; 5-20 rows typical).
		// -----------------------------------------------------------------
		$license_items = ( new ISOFT_FMF_License_Service() )->list();

		// -----------------------------------------------------------------
		// Settings — full options map for the 4 schema tabs.
		// -----------------------------------------------------------------
		$settings_values = ( new ISOFT_FMF_Settings_Service() )->get_all();

		// -----------------------------------------------------------------
		// Stats overview — same payload the /stats/overview REST route
		// returns. isoft_fmf_get_stats_overview() is cached for 5
		// minutes so inlining here is free.
		// -----------------------------------------------------------------
		$stats_overview = isoft_fmf_get_stats_overview();

		// -----------------------------------------------------------------
		// Log config + chrome.
		// -----------------------------------------------------------------
		$log_export_base    = admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-log' );
		$log_purge_url      = admin_url( 'admin-post.php' );
		$log_retention_days = (int) get_option( 'isoft_fmf_log_retention_days', 365 );
		$log_logging_on     = (bool) get_option( 'isoft_fmf_enable_logging', true );
		$log_can_export     = current_user_can( 'isoft_fmf_export_logs' );
		$log_can_purge      = current_user_can( 'isoft_fmf_manage_settings' );
		$log_purge_nonce    = $log_can_purge ? wp_create_nonce( 'isoft_fmf_purge_logs' ) : '';

		$settings_tabs     = array( 'general', 'display', 'security', 'advanced', 'maintenance', 'extensions' );
		$settings_tab_urls = array();
		foreach ( $settings_tabs as $tab ) {
			$settings_tab_urls[ $tab ] = add_query_arg(
				array(
					'page'      => 'isoft-fmf-settings',
					'post_type' => 'isoft_fmf_file',
					'tab'       => $tab,
				),
				admin_url( 'edit.php' )
			);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector; nonce belongs on form submit, not nav.
		$settings_initial_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		return array(
			'licenses'     => array(
				'initialItems' => $license_items,
			),
			'stats'        => array(
				'initialOverview' => $stats_overview,
			),
			'log'          => array(
				'exportBaseUrl'    => $log_export_base,
				'purgeUrl'         => $log_purge_url,
				'purgeNonce'       => $log_purge_nonce,
				'retentionDays'    => $log_retention_days,
				'loggingEnabled'   => $log_logging_on,
				'canExport'        => $log_can_export,
				'canPurge'         => $log_can_purge,
				'initialTotal'     => $log_initial_total,
				'initialPages'     => (int) ceil( $log_initial_total / $per_page ),
				'initialItems'     => $log_rows,
				'initialDownloads' => $log_downloads_shape,
			),
			'broken-links' => array(
				'initialTotal' => (int) $broken_page['total'],
				'initialPages' => (int) ceil( $broken_page['total'] / $per_page ),
				'initialItems' => $broken_page['items'],
			),
			'settings'     => array(
				'initialTab'    => in_array( $settings_initial_tab, array( 'general', 'display', 'security', 'advanced' ), true ) ? $settings_initial_tab : 'general',
				'phpTabUrls'    => array(
					'maintenance' => $settings_tab_urls['maintenance'],
					'extensions'  => $settings_tab_urls['extensions'],
				),
				'initialValues' => $settings_values,
			),
		);
	}
}
