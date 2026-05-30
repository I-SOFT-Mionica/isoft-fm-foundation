<?php
defined( 'ABSPATH' ) || exit;

class ISFM_License_Manager {

	private string $table;

	public const CACHE_GROUP = 'isfm_licenses';

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'isfm_licenses';
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

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=isfm_file',
			__( 'Licenses', 'isoft-fm-foundation' ),
			__( 'Licenses', 'isoft-fm-foundation' ),
			'isfm_manage_settings',
			'isfm-licenses',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage licenses.', 'isoft-fm-foundation' ) );
		}
		require ISFM_PLUGIN_DIR . 'admin/views/licenses-page.php';
	}

	public function handle_form_actions(): void {
		if ( empty( $_POST['isfm_license_action'] ) ) {
			return;
		}
		check_admin_referer( 'isfm_license_action' );
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'isoft-fm-foundation' ) );
		}

		$action = sanitize_text_field( wp_unslash( $_POST['isfm_license_action'] ) );
		if ( 'save' === $action ) {
			$this->save();
		} elseif ( 'delete' === $action ) {
			$this->delete( absint( $_POST['license_id'] ?? 0 ) );
		}
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
					'page'      => 'isfm-licenses',
					'post_type' => 'isfm_file',
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
					'page'      => 'isfm-licenses',
					'post_type' => 'isfm_file',
					'deleted'   => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
