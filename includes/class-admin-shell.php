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
	 * Map of admin-page hook suffix -> React top+sub section tuple.
	 *
	 * As of 0.12.8, the shell has 3 top-level sections (Licenses,
	 * Tools, Settings). Tools houses Statistics / Download Log /
	 * Broken Links as sub-tabs; Settings houses General / Display /
	 * Security / Advanced / Maintenance / Extensions. The legacy
	 * per-URL slugs (?page=isoft-fmf-stats etc.) still resolve — they
	 * enter the shell with the correct top+sub pre-selected — but
	 * they're hidden from the WP admin sidebar (see
	 * ISOFT_FMF_Settings::consolidate_tools_menu).
	 *
	 * @return array<string,array{top:string,sub:?string}>
	 */
	public static function section_map(): array {
		return array(
			'isoft_fmf_file_page_isoft-fmf-licenses'     => array(
				'top' => 'licenses',
				'sub' => null,
			),
			'isoft_fmf_file_page_isoft-fmf-tools'        => array(
				'top' => 'tools',
				'sub' => 'stats',
			),
			'isoft_fmf_file_page_isoft-fmf-stats'        => array(
				'top' => 'tools',
				'sub' => 'stats',
			),
			'isoft_fmf_file_page_isoft-fmf-log'          => array(
				'top' => 'tools',
				'sub' => 'log',
			),
			'isoft_fmf_file_page_isoft-fmf-broken-links' => array(
				'top' => 'tools',
				'sub' => 'broken-links',
			),
			'isoft_fmf_file_page_isoft-fmf-settings'     => array(
				'top' => 'settings',
				'sub' => null,
			),
		);
	}

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Stashes the active {top, sub} pair derived from hook_suffix so
	 * bootstrap_payload() knows which slice to compute. The alternative
	 * — re-deriving from $_GET['page'] inside bootstrap_payload — is
	 * fragile because the mount partial runs from inside a submenu
	 * callback, not the admin_enqueue_scripts hook, and $_GET is the
	 * same but there's no coupling guarantee.
	 *
	 * @var array{top:string,sub:?string}|null
	 */
	private static ?array $active = null;

	/** @return array{top:string,sub:?string}|null */
	public static function active_section(): ?array {
		return self::$active;
	}

	/**
	 * Derive the {top, sub} route from $_GET['page']. Returns null when
	 * the URL doesn't match any known shell page — the caller falls
	 * back to whatever enqueue() stashed, or the licenses default.
	 *
	 * Mirrors the JS router's routeFromUrl so client and server agree
	 * on which page a URL resolves to.
	 *
	 * @return array{top:string,sub:?string}|null
	 */
	private static function route_from_query_string(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL routing; page slug is validated below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$tools_pages = array(
			'isoft-fmf-tools'        => 'stats',
			'isoft-fmf-stats'        => 'stats',
			'isoft-fmf-log'          => 'log',
			'isoft-fmf-broken-links' => 'broken-links',
		);

		if ( 'isoft-fmf-licenses' === $page ) {
			return array(
				'top' => 'licenses',
				'sub' => null,
			);
		}
		if ( 'isoft-fmf-settings' === $page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
			$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
			$valid = array( 'general', 'display', 'security', 'advanced', 'maintenance', 'extensions' );
			return array(
				'top' => 'settings',
				'sub' => in_array( $tab, $valid, true ) ? $tab : 'general',
			);
		}
		if ( isset( $tools_pages[ $page ] ) ) {
			return array(
				'top' => 'tools',
				'sub' => $tools_pages[ $page ],
			);
		}

		return null;
	}

	public function enqueue( string $hook_suffix ): void {
		$section = self::section_map()[ $hook_suffix ] ?? null;
		if ( null === $section ) {
			return;
		}
		self::$active = $section;

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
	 * The shape carries the initial {top, sub} routing state plus one
	 * slice — the data the active sub-section needs to first-paint
	 * without any REST round-trip. Other sub-sections mount lazily on
	 * first visit; the SPA router keeps visited ones alive, so each
	 * pays its query cost at most once per session.
	 *
	 * The broken-links badge count is always inlined — it drives the
	 * WP admin menu badge AND the sub-nav badge inside Tools.
	 *
	 * When the active top is Settings, `settingsHtml` also carries
	 * server-rendered HTML for the Maintenance and Extensions
	 * sub-tabs — they're PHP forms with admin-post handlers, so the
	 * shell injects their existing markup into the Settings body
	 * instead of forcing a full navigation the way the pre-0.12.8
	 * settings-page.php did.
	 *
	 * @return array<string,mixed>
	 */
	public static function bootstrap_payload(): array {
		// $_GET is the authoritative source: the URL is the page.
		// self::$active (set by enqueue()) is used only when $_GET
		// doesn't resolve to a known shell page — that can happen if
		// this method is invoked from a context outside the normal
		// submenu render pipeline (unlikely, but harmless to defend
		// against). If both fail, default to licenses.
		$route  = self::route_from_query_string();
		$active = $route ?? self::$active ?? array(
			'top' => 'licenses',
			'sub' => null,
		);

		$payload = array(
			'active'     => $active,
			'badgeCount' => self::badge_count(),
			'licenses'   => (object) array(),
			'tools'      => (object) array(),
			'settings'   => (object) array(),
		);

		switch ( $active['top'] ) {
			case 'licenses':
				$payload['licenses'] = self::licenses_slice();
				break;
			case 'tools':
				$payload['tools'] = self::tools_slice( $active['sub'] ?? 'stats' );
				break;
			case 'settings':
				$payload['settings'] = self::settings_slice();
				break;
		}

		return $payload;
	}

	/**
	 * Inline slice for Tools — only the active sub-tab's data ships.
	 * Sibling sub-tabs hydrate over REST when the user clicks them.
	 * The badge count sits at the top level (see bootstrap_payload)
	 * so it's available before Tools is visited.
	 */
	private static function tools_slice( string $sub ): array {
		$out = array(
			'initialSub' => in_array( $sub, array( 'stats', 'log', 'broken-links' ), true ) ? $sub : 'stats',
		);
		switch ( $out['initialSub'] ) {
			case 'stats':
				$out['stats'] = self::stats_slice();
				break;
			case 'log':
				$out['log'] = self::log_slice();
				break;
			case 'broken-links':
				$out['brokenLinks'] = self::broken_links_slice();
				break;
		}
		return $out;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector; nonce belongs on form submit, not nav.
		$initial_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( ! in_array( $initial_tab, array( 'general', 'display', 'security', 'advanced', 'maintenance', 'extensions' ), true ) ) {
			$initial_tab = 'general';
		}

		// Note: maintenanceHtml / extensionsHtml are NOT included in
		// the JSON payload. Packing ~10 KB of PHP-rendered HTML
		// (nonce fields, form markup, translated text) through the
		// JSON → esc_attr → JSON.parse round-trip broke on certain
		// characters (verified: 9098-char blob threw
		// SyntaxError at column 1786 on a real install).
		//
		// admin-shell-mount.php emits them separately as
		// <script type="text/html" id="isoft-fmf-tab-maintenance">…</script>
		// blocks, which React reads directly by id — no JSON layer.
		return array(
			'initialTab'    => $initial_tab,
			'initialValues' => ( new ISOFT_FMF_Settings_Service() )->get_all(),
		);
	}

	/**
	 * Public accessor for admin-shell-mount.php — captures a PHP tab
	 * view (maintenance-tab.php / extensions-tab.php) into an HTML
	 * string. Emitted into a separate <script type="text/html"> block
	 * alongside the mount div (see settings_slice for rationale).
	 */
	public static function settings_php_tab_html( string $tab ): string {
		if ( 'maintenance' === $tab ) {
			return self::capture_view( 'maintenance-tab.php' );
		}
		if ( 'extensions' === $tab ) {
			return self::capture_view( 'extensions-tab.php' );
		}
		return '';
	}

	/**
	 * Render a PHP view file into an HTML string. Used to inline the
	 * Maintenance and Extensions sub-tabs into the shell so they
	 * appear in the same sidebar UX as the 4 schema tabs — no full
	 * navigation, no jumping between shell and legacy PHP.
	 *
	 * The captured HTML is emitted into the React tree via
	 * dangerouslySetInnerHTML. The forms inside still POST to
	 * options.php / admin-post.php — those go through their normal
	 * WordPress handlers, then the browser reloads and re-enters the
	 * shell. No React state to manage on either sub-tab.
	 */
	private static function capture_view( string $file ): string {
		$path = ISOFT_FMF_PLUGIN_DIR . 'admin/views/' . $file;
		if ( ! file_exists( $path ) ) {
			return '';
		}
		ob_start();
		require $path;
		return (string) ob_get_clean();
	}
}
