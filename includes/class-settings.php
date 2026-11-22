<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Settings {

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		// Hide the 3 legacy Tools sub-URLs from the WP admin sidebar
		// after the menu is built. They still resolve — the shell
		// renders on them with the correct sub-tab pre-selected — so
		// bookmarks and inbound links keep working.
		add_action( 'admin_menu', array( $this, 'consolidate_tools_menu' ), 999 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		$isoft_fmf_pages = array(
			'isoft_fmf_file_page_isoft-fmf-tools',
			'isoft_fmf_file_page_isoft-fmf-stats',
			'isoft_fmf_file_page_isoft-fmf-log',
			'isoft_fmf_file_page_isoft-fmf-settings',
			'isoft_fmf_file_page_isoft-fmf-broken-links',
		);
		if ( ! in_array( $hook, $isoft_fmf_pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'isoft-fmf-admin', ISOFT_FMF_PLUGIN_URL . 'admin/css/admin-style.css', array(), ISOFT_FMF_VERSION );
	}

	public function register_menu(): void {
		// Tools — the single sidebar entry that houses Statistics /
		// Download Log / Broken Links as sub-tabs inside the shell.
		// Position 17 places it right after Licenses (position 16),
		// which sits right after Tags (WordPress-native position 15).
		$missing_count = ISOFT_FMF_File_Integrity::missing_count();
		$tools_label   = __( 'Tools', 'isoft-fm-foundation' );
		// People who can open Needs attention get this number there instead.
		if ( $missing_count > 0 && ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			$tools_label .= ' <span class="awaiting-mod isoft-fmf-broken-badge">' . number_format_i18n( $missing_count ) . '</span>';
		}
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Tools', 'isoft-fm-foundation' ),
			$tools_label,
			'isoft_fmf_view_logs',
			'isoft-fmf-tools',
			array( $this, 'render_tools' ),
			17
		);

		// Legacy Tools sub-URLs — still registered so admin.php?page=…
		// resolves for existing bookmarks and for the shell's own
		// intra-Tools client-side URLs (pushState uses these). They
		// get hidden from the sidebar by consolidate_tools_menu().
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Statistics', 'isoft-fm-foundation' ),
			__( 'Statistics', 'isoft-fm-foundation' ),
			'isoft_fmf_view_logs',
			'isoft-fmf-stats',
			array( $this, 'render_stats' )
		);
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Download Log', 'isoft-fm-foundation' ),
			__( 'Download Log', 'isoft-fm-foundation' ),
			'isoft_fmf_view_logs',
			'isoft-fmf-log',
			array( $this, 'render_log' )
		);
		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Broken Links', 'isoft-fm-foundation' ),
			__( 'Broken Links', 'isoft-fm-foundation' ),
			'isoft_fmf_manage_settings',
			'isoft-fmf-broken-links',
			array( $this, 'render_broken_links' )
		);

		add_submenu_page(
			'edit.php?post_type=isoft_fmf_file',
			__( 'Settings', 'isoft-fm-foundation' ),
			__( 'Settings', 'isoft-fm-foundation' ),
			'isoft_fmf_manage_settings',
			'isoft-fmf-settings',
			array( $this, 'render_page' ),
			18
		);
	}

	/**
	 * Runs after all admin_menu handlers finish (priority 999). Removes
	 * the three legacy Tools sub-URLs from the sidebar so users see one
	 * "Tools" entry instead of Statistics + Download Log + Broken
	 * Links. `remove_submenu_page` only drops the sidebar row — the
	 * `$_registered_pages` entry survives, so the URLs still load.
	 *
	 * Without the row, get_admin_page_title() finds no title and the admin
	 * header passes null to strip_tags() (a deprecation per visit), so each
	 * hidden page gets its title back on its load- hook.
	 */
	public function consolidate_tools_menu(): void {
		$parent = 'edit.php?post_type=isoft_fmf_file';
		foreach ( array( 'isoft-fmf-stats', 'isoft-fmf-log', 'isoft-fmf-broken-links' ) as $slug ) {
			$item = remove_submenu_page( $parent, $slug );
			if ( ! is_array( $item ) || ! isset( $item[3] ) ) {
				continue;
			}
			$page_title = (string) $item[3];
			add_action(
				'load-' . get_plugin_page_hookname( $slug, $parent ),
				static function () use ( $page_title ): void {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The admin header reads the page title from this global.
					$GLOBALS['title'] = $page_title;
				}
			);
		}
	}

	public function render_tools(): void {
		if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
			wp_die( esc_html__( 'You do not have permission to view Tools.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/tools-page.php';
	}

	public function render_broken_links(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view broken links.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/broken-links-page.php';
	}

	public function render_stats(): void {
		if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
			wp_die( esc_html__( 'You do not have permission to view statistics.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/stats-dashboard.php';
	}

	public function render_log(): void {
		if ( ! current_user_can( 'isoft_fmf_view_logs' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the download log.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/log-viewer.php';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'isoft-fm-foundation' ) );
		}
		require ISOFT_FMF_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * One option group per tab — so saving one tab cannot wipe checkboxes
	 * on another. WP's Settings API iterates every option registered to
	 * the submitted group and reads $_POST[option]; unchecked checkboxes
	 * are absent from POST and would absint('') → 0, silently unsetting
	 * them. Grouping per tab keeps each save scoped to its tab's fields.
	 *
	 * Schema lives on [[ISOFT_FMF_Settings_Service]] so both this legacy
	 * register_setting() path and the REST controller share one source.
	 */
	public function register_settings(): void {
		foreach ( ISOFT_FMF_Settings_Service::groups() as $group => $options ) {
			foreach ( $options as $option => $sanitize ) {
				register_setting( $group, $option, array( 'sanitize_callback' => $sanitize ) );
			}
		}
	}
}
