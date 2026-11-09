<?php
/**
 * Needs attention: one screen for everything waiting on a person.
 *
 * Each list is a source that keeps its own storage and supplies a label, a
 * count and pages of items. Foundation adds its own sources; other plugins add
 * theirs with isoft_fmf_register_attention_source(). There is no shared queue,
 * so a source that goes away takes its items with it.
 *
 * All item text is plain text, because it can hold file names from untrusted
 * folders. A source that throws is reported as unreadable and cannot take the
 * screen down.
 */
defined( 'ABSPATH' ) || exit;

final class ISOFT_FMF_Attention {

	public const PAGE = 'isoft-fmf-attention';

	private const ACTION     = 'isoft_fmf_attention_action';
	private const CAPABILITY = 'isoft_fmf_manage_settings';
	private const PER_PAGE   = 20;
	private const PREVIEW    = 5;

	/** @var array<string, array<string, mixed>> */
	private static array $sources = array();

	public static function register_hooks(): void {
		add_action( 'isoft_fmf_extensions_init', array( self::class, 'register_own_sources' ), 1 );
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_action' ) );
	}

	// -------------------------------------------------------------------------
	// Sources
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $args label, count, items; optional handle_action, capability, in_menu_count.
	 */
	public static function register_source( string $id, array $args ): bool {
		$key = sanitize_key( $id );
		if ( '' === $key || $key !== $id || isset( self::$sources[ $key ] )
			|| empty( $args['label'] )
			|| ! is_callable( $args['count'] ?? null )
			|| ! is_callable( $args['items'] ?? null )
		) {
			_doing_it_wrong( __METHOD__, esc_html__( 'An attention source needs a unique lowercase id, a label, and count and items callbacks.', 'isoft-fm-foundation' ), '0.16.0' );
			return false;
		}

		self::$sources[ $key ] = array(
			'label'         => (string) $args['label'],
			'count'         => $args['count'],
			'items'         => $args['items'],
			'handle_action' => is_callable( $args['handle_action'] ?? null ) ? $args['handle_action'] : null,
			'capability'    => (string) ( $args['capability'] ?? '' ),
			'in_menu_count' => (bool) ( $args['in_menu_count'] ?? true ),
		);
		return true;
	}

	/**
	 * Sources the current user may see.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function visible_sources(): array {
		return array_filter(
			self::$sources,
			static fn( array $source ): bool => '' === $source['capability'] || current_user_can( $source['capability'] )
		);
	}

	/**
	 * Run a source's callback; false when it throws.
	 *
	 * @param array<int, mixed> $args Callback arguments.
	 * @return mixed
	 */
	private static function call( callable $callback, array $args ) {
		try {
			return $callback( ...$args );
		} catch ( Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * How many items a source holds; null when it could not be read.
	 */
	public static function count_for( string $id ): ?int {
		if ( ! isset( self::$sources[ $id ] ) ) {
			return null;
		}
		$count = self::call( self::$sources[ $id ]['count'], array() );
		return is_numeric( $count ) ? max( 0, (int) $count ) : null;
	}

	/**
	 * One page of a source's items, normalised; null when it could not be read.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function items_for( string $id, int $page, int $per_page ): ?array {
		if ( ! isset( self::$sources[ $id ] ) ) {
			return null;
		}
		$raw = self::call( self::$sources[ $id ]['items'], array( max( 1, $page ), $per_page ) );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		return array_slice( array_values( array_filter( array_map( array( self::class, 'normalise_item' ), $raw ) ) ), 0, $per_page );
	}

	/**
	 * What the menu's count shows for the current user.
	 */
	public static function menu_count(): int {
		$total = 0;
		foreach ( self::visible_sources() as $id => $source ) {
			if ( $source['in_menu_count'] ) {
				$total += (int) self::count_for( $id );
			}
		}
		return $total;
	}

	/**
	 * @param mixed $raw An item as supplied by a source.
	 * @return array<string, mixed>|null Null when it has no id or name.
	 */
	private static function normalise_item( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$id   = self::text( $raw['id'] ?? '' );
		$name = self::text( $raw['name'] ?? '' );
		if ( '' === $id || '' === $name ) {
			return null;
		}

		$actions = array();
		foreach ( (array) ( $raw['actions'] ?? array() ) as $action ) {
			$label = is_array( $action ) ? self::text( $action['label'] ?? '' ) : '';
			if ( '' === $label ) {
				continue;
			}
			if ( ! empty( $action['action'] ) ) {
				$actions[] = array(
					'label'  => $label,
					'action' => sanitize_key( self::text( $action['action'] ) ),
				);
			} elseif ( ! empty( $action['url'] ) ) {
				$actions[] = array(
					'label' => $label,
					'url'   => self::text( $action['url'] ),
				);
			}
		}

		return array(
			'id'          => $id,
			'name'        => $name,
			'reason'      => self::text( $raw['reason'] ?? '' ),
			'date'        => (int) ( $raw['date'] ?? 0 ),
			'download_id' => (int) ( $raw['download_id'] ?? 0 ),
			'actions'     => $actions,
		);
	}

	/**
	 * @param mixed $value Possibly not a string.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Hand a named action back to its source. The source checks the item's
	 * current state and any capability of its own.
	 *
	 * @return true|WP_Error
	 */
	public static function run_action( string $id, string $item_id, string $action ) {
		$source = self::visible_sources()[ $id ] ?? null;
		if ( ! $source || ! $source['handle_action'] ) {
			return new WP_Error( 'isoft_fmf_attention_no_action', __( 'This action is not available.', 'isoft-fm-foundation' ) );
		}
		$result = self::call( $source['handle_action'], array( $item_id, $action ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true === $result
			? true
			: new WP_Error( 'isoft_fmf_attention_failed', __( 'That could not be done. The item may have changed; reload and try again.', 'isoft-fm-foundation' ) );
	}

	// -------------------------------------------------------------------------
	// Foundation's own sources
	// -------------------------------------------------------------------------

	public static function register_own_sources(): void {
		self::register_source(
			'missing_files',
			array(
				'label' => __( 'Files missing from the server', 'isoft-fm-foundation' ),
				'count' => array( 'ISOFT_FMF_File_Integrity', 'missing_count' ),
				'items' => array( self::class, 'missing_items' ),
			)
		);
		self::register_source(
			'pending_categories',
			array(
				'label' => __( 'Categories waiting for approval', 'isoft-fm-foundation' ),
				'count' => static fn(): int => count( ISOFT_FMF_Category_Pending::ids() ),
				'items' => array( self::class, 'pending_items' ),
			)
		);
		self::register_source(
			'stopped_tasks',
			array(
				'label'         => __( 'Tasks that stopped early', 'isoft-fm-foundation' ),
				'count'         => static fn(): int => count( ISOFT_FMF_Task_Budget::open_stops() ),
				'items'         => array( self::class, 'stopped_items' ),
				'handle_action' => array( self::class, 'handle_stopped' ),
			)
		);
		// Someone chose to hold these back, so they are listed but do not count
		// towards the menu number: it would otherwise never reach zero.
		self::register_source(
			'unavailable_files',
			array(
				'label'         => __( 'Files marked unavailable', 'isoft-fm-foundation' ),
				'count'         => array( new ISOFT_FMF_File_Manager(), 'count_unavailable' ),
				'items'         => array( self::class, 'unavailable_items' ),
				'in_menu_count' => false,
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function missing_items( int $page, int $per_page ): array {
		$url   = admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-broken-links' );
		$items = array();
		foreach ( ( new ISOFT_FMF_Broken_Links_Service() )->list_broken( $page, $per_page )['items'] as $file ) {
			$items[] = array(
				'id'          => (string) $file['id'],
				'name'        => $file['file_name'],
				'reason'      => __( 'The file is no longer on the server.', 'isoft-fm-foundation' ),
				'date'        => self::site_time( (string) $file['missing_since'] ),
				'download_id' => $file['download_id'],
				'actions'     => array(
					array(
						'label' => __( 'Open Broken Links', 'isoft-fm-foundation' ),
						'url'   => $url,
					),
				),
			);
		}
		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending_items( int $page, int $per_page ): array {
		$can   = current_user_can( 'isoft_fmf_manage_categories' );
		$items = array();
		foreach ( array_slice( ISOFT_FMF_Category_Pending::ids(), ( $page - 1 ) * $per_page, $per_page ) as $term_id ) {
			$term = get_term( $term_id, 'isoft_fmf_category' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$actions = array(
				array(
					'label' => __( 'Edit', 'isoft-fm-foundation' ),
					'url'   => (string) get_edit_term_link( $term_id, 'isoft_fmf_category', 'isoft_fmf_file' ),
				),
			);
			if ( $can ) {
				array_unshift(
					$actions,
					array(
						'label' => __( 'Approve', 'isoft-fm-foundation' ),
						'url'   => ISOFT_FMF_Category_Pending::approve_url( $term_id ),
					)
				);
			}
			$items[] = array(
				'id'      => (string) $term_id,
				'name'    => $term->name,
				'reason'  => __( 'Hidden from visitors until someone approves it.', 'isoft-fm-foundation' ),
				'actions' => $actions,
			);
		}
		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function stopped_items( int $page, int $per_page ): array {
		$items = array();
		foreach ( array_slice( ISOFT_FMF_Task_Budget::open_stops(), ( $page - 1 ) * $per_page, $per_page ) as $stop ) {
			$items[] = array(
				'id'      => ISOFT_FMF_Task_Budget::stop_id( $stop ),
				'name'    => ISOFT_FMF_Task_Budget::describe_stop( $stop ),
				'reason'  => (string) $stop['detail'],
				'date'    => (int) $stop['time'],
				'actions' => array(
					array(
						'label'  => __( 'Dismiss', 'isoft-fm-foundation' ),
						'action' => 'dismiss',
					),
				),
			);
		}
		return $items;
	}

	/**
	 * @return bool
	 */
	public static function handle_stopped( string $item_id, string $action ): bool {
		return 'dismiss' === $action && ISOFT_FMF_Task_Budget::dismiss_stop( $item_id );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function unavailable_items( int $page, int $per_page ): array {
		$items = array();
		foreach ( ( new ISOFT_FMF_File_Manager() )->get_unavailable( $page, $per_page ) as $file ) {
			$items[] = array(
				'id'          => (string) $file->id,
				'name'        => $file->file_name,
				'reason'      => __( 'Listed, but visitors cannot download it.', 'isoft-fm-foundation' ),
				'download_id' => (int) $file->download_id,
			);
		}
		return $items;
	}

	/**
	 * @param string $value A 'Y-m-d H:i:s' value in site time.
	 */
	private static function site_time( string $value ): int {
		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			unset( $e );
			return 0;
		}
	}

	// -------------------------------------------------------------------------
	// Menu and screen
	// -------------------------------------------------------------------------

	public static function register_menu(): void {
		$label = __( 'Needs attention', 'isoft-fm-foundation' );
		$count = current_user_can( self::CAPABILITY ) ? self::menu_count() : 0;
		if ( $count > 0 ) {
			$label .= ' <span class="awaiting-mod">' . esc_html( number_format_i18n( $count ) ) . '</span>';
		}
		add_submenu_page(
			isoft_fmf_admin_parent_slug(),
			__( 'Needs attention', 'isoft-fm-foundation' ),
			$label,
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render_page' ),
			2
		);
	}

	public static function url( string $source = '', int $page = 1 ): string {
		$args = array(
			'post_type' => 'isoft_fmf_file',
			'page'      => self::PAGE,
		);
		if ( '' !== $source ) {
			$args['source'] = $source;
		}
		if ( $page > 1 ) {
			$args['paged'] = $page;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	public static function handle_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- These three name the nonce action verified on the next line.
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$item   = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '';
		$action = isset( $_POST['item_action'] ) ? sanitize_key( wp_unslash( $_POST['item_action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		check_admin_referer( self::nonce_action( $source, $item, $action ) );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'isoft-fm-foundation' ), 403 );
		}

		$result = self::run_action( $source, $item, $action );
		set_transient(
			self::result_key(),
			is_wp_error( $result ) ? $result->get_error_message() : '',
			MINUTE_IN_SECONDS
		);

		$view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : '';
		wp_safe_redirect( add_query_arg( 'done', 1, self::url( $view, isset( $_POST['paged'] ) ? absint( wp_unslash( $_POST['paged'] ) ) : 1 ) ) );
		exit;
	}

	private static function nonce_action( string $source, string $item, string $action ): string {
		return self::ACTION . ':' . $source . ':' . $item . ':' . $action;
	}

	private static function result_key(): string {
		return 'isoft_fmf_attention_result_' . get_current_user_id();
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'isoft-fm-foundation' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$view = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$done = isset( $_GET['done'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$sources = self::visible_sources();
		if ( ! isset( $sources[ $view ] ) ) {
			$view = '';
		}

		$counts = array();
		foreach ( $sources as $id => $source ) {
			$counts[ $id ] = self::count_for( $id );
		}
		$shown = array_filter( $counts, static fn( ?int $count ): bool => 0 !== $count );

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Needs attention', 'isoft-fm-foundation' ) . '</h1><hr class="wp-header-end">';

		self::render_result( $done );

		if ( ! $shown && '' === $view ) {
			echo '<p>' . esc_html__( 'Nothing needs your attention right now.', 'isoft-fm-foundation' ) . '</p></div>';
			return;
		}

		self::render_filters( $sources, $shown, $view );

		if ( '' !== $view ) {
			self::render_source( $view, $sources[ $view ], $counts[ $view ], $page, self::PER_PAGE, $view );
			echo '</div>';
			return;
		}

		foreach ( array_keys( $shown ) as $id ) {
			self::render_source( $id, $sources[ $id ], $counts[ $id ], 1, self::PREVIEW, '' );
		}
		echo '</div>';
	}

	private static function render_result( bool $done ): void {
		if ( ! $done ) {
			return;
		}
		$message = get_transient( self::result_key() );
		delete_transient( self::result_key() );
		if ( is_string( $message ) && '' !== $message ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( false !== $message ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Done.', 'isoft-fm-foundation' ) . '</p></div>';
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $sources Visible sources.
	 * @param array<string, int|null>             $shown   Sources with something to show.
	 */
	private static function render_filters( array $sources, array $shown, string $view ): void {
		echo '<ul class="subsubsub"><li><a href="' . esc_url( self::url() ) . '"' . ( '' === $view ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'isoft-fm-foundation' ) . '</a></li>';
		foreach ( $shown as $id => $count ) {
			printf(
				' | <li><a href="%s"%s>%s <span class="count">(%s)</span></a></li>',
				esc_url( self::url( $id ) ),
				$id === $view ? ' class="current"' : '',
				esc_html( $sources[ $id ]['label'] ),
				esc_html( number_format_i18n( (int) $count ) )
			);
		}
		echo '</ul><div class="clear"></div>';
	}

	/**
	 * @param array<string, mixed> $source Source definition.
	 */
	private static function render_source( string $id, array $source, ?int $count, int $page, int $per_page, string $view ): void {
		echo '<h2>' . esc_html( $source['label'] ) . '</h2>';

		$items = null === $count ? null : self::items_for( $id, $page, $per_page );
		if ( null === $items ) {
			echo '<p>' . esc_html__( 'This list could not be read.', 'isoft-fm-foundation' ) . '</p>';
			return;
		}
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'Nothing here.', 'isoft-fm-foundation' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'isoft-fm-foundation' ) . '</th><th>' . esc_html__( 'Why', 'isoft-fm-foundation' ) . '</th><th>' . esc_html__( 'Since', 'isoft-fm-foundation' ) . '</th><th>' . esc_html__( 'Action', 'isoft-fm-foundation' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			self::render_item( $id, $source, $item, $view, $page );
		}
		echo '</tbody></table>';

		if ( '' === $view ) {
			if ( $count > $per_page ) {
				printf(
					'<p><a href="%s">%s</a></p>',
					esc_url( self::url( $id ) ),
					esc_html(
						sprintf(
							/* translators: %s: number of items */
							__( 'View all %s', 'isoft-fm-foundation' ),
							number_format_i18n( $count )
						)
					)
				);
			}
			return;
		}

		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%', self::url( $id ) ),
				'format'  => '',
				'current' => $page,
				'total'   => (int) ceil( $count / $per_page ),
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * @param array<string, mixed> $source Source definition.
	 * @param array<string, mixed> $item   Normalised item.
	 */
	private static function render_item( string $id, array $source, array $item, string $view, int $page ): void {
		echo '<tr><td><strong>' . esc_html( $item['name'] ) . '</strong>';
		if ( $item['download_id'] && current_user_can( 'edit_post', $item['download_id'] ) ) {
			printf(
				'<br><a href="%s">%s</a>',
				esc_url( (string) get_edit_post_link( $item['download_id'], 'raw' ) ),
				esc_html( get_the_title( $item['download_id'] ) )
			);
		}
		echo '</td><td>' . esc_html( $item['reason'] ) . '</td><td>';
		if ( $item['date'] ) {
			echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['date'] ) );
		}
		echo '</td><td>';

		foreach ( $item['actions'] as $action ) {
			if ( isset( $action['url'] ) ) {
				echo '<a class="button button-small" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a> ';
			} elseif ( $source['handle_action'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( self::nonce_action( $id, $item['id'], $action['action'] ) );
				foreach (
					array(
						'action'      => self::ACTION,
						'source'      => $id,
						'item'        => $item['id'],
						'item_action' => $action['action'],
						'view'        => $view,
						'paged'       => (string) $page,
					) as $name => $value
				) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
				}
				echo '<button type="submit" class="button button-small">' . esc_html( $action['label'] ) . '</button></form> ';
			}
		}
		echo '</td></tr>';
	}
}
