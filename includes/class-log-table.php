<?php
/**
 * WP_List_Table subclass for the download log viewer.
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ISOFT_FMF_Log_Table extends WP_List_Table {

	/** @var bool Whether detailed logging (IP / user-agent) is on. */
	private bool $detailed;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'log_entry',
				'plural'   => 'log_entries',
				'ajax'     => false,
			)
		);
		$this->detailed = (bool) get_option( 'isoft_fmf_enable_detailed_logging', false );
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		$cols = array(
			'cb'             => '<input type="checkbox" />',
			'download_title' => __( 'Download', 'isoft-fm-foundation' ),
			'file_name'      => __( 'File', 'isoft-fm-foundation' ),
			'user_login'     => __( 'User', 'isoft-fm-foundation' ),
		);

		if ( $this->detailed ) {
			$cols['ip_address'] = __( 'IP Address', 'isoft-fm-foundation' );
		}

		$cols['downloaded_at'] = __( 'Date', 'isoft-fm-foundation' );

		return $cols;
	}

	public function get_sortable_columns(): array {
		return array(
			'download_title' => array( 'download_title', false ),
			'downloaded_at'  => array( 'downloaded_at', true ),  // default sort
		);
	}

	protected function get_bulk_actions(): array {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			return array();
		}
		return array( 'delete' => __( 'Delete', 'isoft-fm-foundation' ) );
	}

	// -------------------------------------------------------------------------
	// Row rendering
	// -------------------------------------------------------------------------

	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="log_ids[]" value="' . absint( $item->id ) . '" />';
	}

	protected function column_download_title( object $item ): string {
		$title = $item->download_title
			? esc_html( $item->download_title )
			: '<em>' . esc_html__( '(deleted)', 'isoft-fm-foundation' ) . '</em>';

		if ( $item->download_id && get_post( $item->download_id ) ) {
			$title = '<a href="' . esc_url( get_edit_post_link( $item->download_id ) ) . '">' . $title . '</a>';
		}

		return $title;
	}

	protected function column_file_name( object $item ): string {
		return $item->file_name
			? esc_html( $item->file_name )
			: '<em>' . esc_html__( '(deleted)', 'isoft-fm-foundation' ) . '</em>';
	}

	protected function column_user_login( object $item ): string {
		if ( ! $item->user_id ) {
			return '<em>' . esc_html__( 'Guest', 'isoft-fm-foundation' ) . '</em>';
		}
		$user = get_userdata( (int) $item->user_id );
		if ( ! $user ) {
			return esc_html( $item->user_login ?: __( '(deleted user)', 'isoft-fm-foundation' ) );
		}
		return '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->user_login ) . '</a>';
	}

	protected function column_ip_address( object $item ): string {
		return $item->ip_address ? esc_html( $item->ip_address ) : '—';
	}

	protected function column_downloaded_at( object $item ): string {
		if ( ! $item->downloaded_at ) {
			return '—';
		}
		$timestamp = strtotime( $item->downloaded_at );
		return '<span title="' . esc_attr( $item->downloaded_at ) . '">'
			. esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) )
			. '</span>';
	}

	protected function column_default( $item, $column_name ): string {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	// -------------------------------------------------------------------------
	// Data loading
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		global $wpdb;

		$per_page     = 25;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WP_List_Table uses $_REQUEST for sorting/filtering.

		// Search
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		// Filter by download
		$filter_download = isset( $_REQUEST['filter_download'] ) ? absint( $_REQUEST['filter_download'] ) : 0;

		// Sorting
		$orderby_whitelist = array( 'download_title', 'downloaded_at' );
		$orderby           = isset( $_REQUEST['orderby'] ) && in_array( sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ), $orderby_whitelist, true )
			? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) )
			: 'downloaded_at';
		$order             = isset( $_REQUEST['order'] ) && strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) === 'ASC' ? 'ASC' : 'DESC';

		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Each branch passes a single literal SQL to prepare(). $orderby is allowlisted at
		// lines 137-140 and passed via %i. $order is hardcoded ASC|DESC at line 141.
		$like = $search !== '' ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- WP_List_Table on custom log table; $order is hardcoded ASC|DESC at line 141, not an identifier so %i does not apply.
		if ( $search !== '' && $filter_download > 0 ) {
			$total       = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR l.user_login LIKE %s OR l.ip_address LIKE %s )
					    AND l.download_id = %d",
					$like,
					$like,
					$like,
					$filter_download
				)
			);
			$this->items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login,
					        l.ip_address, l.user_agent, l.referer, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR l.user_login LIKE %s OR l.ip_address LIKE %s )
					    AND l.download_id = %d
					  ORDER BY %i {$order}
					  LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					$filter_download,
					$orderby,
					$per_page,
					$offset
				)
			) ?? array();
		} elseif ( $search !== '' ) {
			$total       = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR l.user_login LIKE %s OR l.ip_address LIKE %s )",
					$like,
					$like,
					$like
				)
			);
			$this->items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login,
					        l.ip_address, l.user_agent, l.referer, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE ( p.post_title LIKE %s OR l.user_login LIKE %s OR l.ip_address LIKE %s )
					  ORDER BY %i {$order}
					  LIMIT %d OFFSET %d",
					$like,
					$like,
					$like,
					$orderby,
					$per_page,
					$offset
				)
			) ?? array();
		} elseif ( $filter_download > 0 ) {
			$total       = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE l.download_id = %d",
					$filter_download
				)
			);
			$this->items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login,
					        l.ip_address, l.user_agent, l.referer, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  WHERE l.download_id = %d
					  ORDER BY %i {$order}
					  LIMIT %d OFFSET %d",
					$filter_download,
					$orderby,
					$per_page,
					$offset
				)
			) ?? array();
		} else {
			$total       = (int) $wpdb->get_var(
				"SELECT COUNT(*)
				   FROM {$wpdb->prefix}isoft_fmf_download_log l
				   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
				   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id"
			);
			$this->items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.id, l.download_id, p.post_title AS download_title,
					        l.file_id, f.file_name, l.user_id, l.user_login,
					        l.ip_address, l.user_agent, l.referer, l.downloaded_at
					   FROM {$wpdb->prefix}isoft_fmf_download_log l
					   LEFT JOIN {$wpdb->posts} p ON p.ID = l.download_id
					   LEFT JOIN {$wpdb->prefix}isoft_fmf_files f ON f.id = l.file_id
					  ORDER BY %i {$order}
					  LIMIT %d OFFSET %d",
					$orderby,
					$per_page,
					$offset
				)
			) ?? array();
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	// -------------------------------------------------------------------------
	// Bulk action processing
	// -------------------------------------------------------------------------

	/**
	 * Call this from the view after prepare_items().
	 */
	public function process_bulk_action(): void {
		if ( $this->current_action() !== 'delete' ) {
			return;
		}
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			return;
		}
		check_admin_referer( 'bulk-log_entries' );

		$ids = isset( $_REQUEST['log_ids'] ) ? array_map( 'absint', (array) $_REQUEST['log_ids'] ) : array();
		if ( ! $ids ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic IN() placeholder count; $placeholders is pure %d tokens from array_fill. One-shot admin bulk action.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}isoft_fmf_download_log WHERE id IN ($placeholders)", ...$ids ) );
	}
}
