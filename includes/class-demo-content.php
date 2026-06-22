<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Demo_Content {

	public function register_hooks(): void {
		add_action( 'admin_post_isoft_fmf_install_demo', array( $this, 'handle_install' ) );
		add_action( 'admin_post_isoft_fmf_remove_demo', array( $this, 'handle_remove' ) );
	}

	public static function has_content(): bool {
		$counts = wp_count_posts( 'isoft_fmf_file' );
		return ( (int) $counts->publish + (int) $counts->draft + (int) $counts->pending ) > 0;
	}

	public static function has_demo_content(): bool {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only one-shot check; LIMIT 1 keeps it fast.
		$query = new WP_Query(
			array(
				'post_type'      => 'isoft_fmf_file',
				'post_status'    => 'any',
				'meta_key'       => '_isoft_fmf_demo_content',
				'meta_value'     => '1',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		return $query->post_count > 0;
	}

	public function handle_install(): void {
		check_admin_referer( 'isoft_fmf_install_demo' );
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to install demo content.', 'isoft-fm-foundation' ), 403 );
		}

		$result = ( new ISOFT_FMF_Maintenance_Service() )->install_demo();
		if ( empty( $result['installed'] ) ) {
			isoft_fmf_notify_admin( $result['reason'] ?? __( 'Demo install failed.', 'isoft-fm-foundation' ), 'error' );
			wp_safe_redirect( $this->settings_url() );
			exit;
		}

		isoft_fmf_notify_admin( __( 'Demo content installed successfully.', 'isoft-fm-foundation' ), 'success' );
		wp_safe_redirect( $this->settings_url( 'isoft_fmf_demo=installed' ) );
		exit;
	}

	public function handle_remove(): void {
		check_admin_referer( 'isoft_fmf_remove_demo' );
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to remove demo content.', 'isoft-fm-foundation' ), 403 );
		}

		( new ISOFT_FMF_Maintenance_Service() )->remove_demo();

		isoft_fmf_notify_admin( __( 'Demo content removed.', 'isoft-fm-foundation' ), 'success' );
		wp_safe_redirect( $this->settings_url( 'isoft_fmf_demo=removed' ) );
		exit;
	}

	/**
	 * CLI entry point — skips nonce and redirect.
	 */
	public function install_cli(): void {
		if ( self::has_content() ) {
			return;
		}
		$categories   = $this->create_categories();
		$download_ids = $this->create_downloads( $categories );
		$this->create_demo_page( $download_ids );
	}

	/**
	 * Programmatic uninstall — skips nonce and redirect. Mirrors install_cli().
	 * Called by ISOFT_FMF_Maintenance_Service and the AJAX handler.
	 */
	public function remove_silent(): void {
		$this->remove_demo_posts();
		$this->remove_demo_terms();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Content definitions
	//
	// Demo content is authored in English (the source language). Localisation
	// is handled the official WordPress way — strings translated via
	// /languages/ .po + .mo files when those are added. Do not branch demo
	// generation on the `cyrillic_titles` setting; that setting is for
	// transliterating uploaded filenames, not for switching demo language.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string,int> Keyed by internal slug → term_id.
	 */
	private function create_categories(): array {
		$ids  = array();
		$tree = $this->category_tree();

		foreach ( $tree as $slug => $node ) {
			$parent_id = 0;
			if ( ! empty( $node['parent'] ) && isset( $ids[ $node['parent'] ] ) ) {
				$parent_id = $ids[ $node['parent'] ];
			}

			$result = wp_insert_term(
				$node['name'],
				'isoft_fmf_category',
				array(
					'slug'   => $slug,
					'parent' => $parent_id,
				)
			);

			if ( is_wp_error( $result ) ) {
				if ( $result->get_error_code() === 'term_exists' ) {
					$ids[ $slug ] = (int) $result->get_error_data();
				}
				continue;
			}

			$ids[ $slug ] = (int) $result['term_id'];
			update_term_meta( $ids[ $slug ], '_isoft_fmf_demo_term', 1 );
		}

		return $ids;
	}

	/**
	 * @return array<string,array{name:string,parent?:string}>
	 */
	private function category_tree(): array {
		return array(
			'municipal-assembly'    => array( 'name' => 'Municipal Assembly' ),
			'term-2025-2029'        => array(
				'name'   => 'Term 2025-2029',
				'parent' => 'municipal-assembly',
			),
			'session-i'             => array(
				'name'   => 'Session I',
				'parent' => 'term-2025-2029',
			),
			'session-ii'            => array(
				'name'   => 'Session II',
				'parent' => 'term-2025-2029',
			),
			'term-2021-2025'        => array(
				'name'   => 'Term 2021-2025',
				'parent' => 'municipal-assembly',
			),
			'municipal-council'     => array( 'name' => 'Municipal Council' ),
			'decisions'             => array(
				'name'   => 'Decisions',
				'parent' => 'municipal-council',
			),
			'resolutions'           => array(
				'name'   => 'Resolutions',
				'parent' => 'municipal-council',
			),
			'public-procurement'    => array( 'name' => 'Public Procurement' ),
			'open-procedures'       => array(
				'name'   => 'Open Procedures',
				'parent' => 'public-procurement',
			),
			'negotiated-procedures' => array(
				'name'   => 'Negotiated Procedures',
				'parent' => 'public-procurement',
			),
			'urban-planning'        => array( 'name' => 'Urban Planning' ),
			'finance'               => array( 'name' => 'Finance' ),
			'budget'                => array(
				'name'   => 'Budget',
				'parent' => 'finance',
			),
			'final-account'         => array(
				'name'   => 'Final Account',
				'parent' => 'finance',
			),
		);
	}

	/**
	 * @param array<string,int> $cats Category slug → term_id map.
	 * @return list<int> Created download post IDs, in creation order.
	 */
	private function create_downloads( array $cats ): array {
		$downloads = $this->download_definitions();
		$file_mgr  = new ISOFT_FMF_File_Manager();
		$created   = array();

		foreach ( $downloads as $def ) {
			$cat_id = $cats[ $def['category'] ] ?? 0;

			// Back-date posts so the demo looks like it's been in service.
			// post_date is what the public-facing date column reads.
			$days_ago  = (int) ( $def['days_ago'] ?? 0 );
			$post_args = array(
				'post_title'   => $def['title'],
				'post_status'  => 'publish',
				'post_type'    => 'isoft_fmf_file',
				'post_content' => $def['description'] ?? '',
			);
			if ( $days_ago > 0 ) {
				$ts                         = time() - ( $days_ago * DAY_IN_SECONDS );
				$post_args['post_date']     = wp_date( 'Y-m-d H:i:s', $ts );
				$post_args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
			}

			$post_id = wp_insert_post( $post_args );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			update_post_meta( $post_id, '_isoft_fmf_demo_content', 1 );
			update_post_meta( $post_id, '_isoft_fmf_access_role', $def['access'] );

			if ( $cat_id ) {
				wp_set_object_terms( $post_id, $cat_id, 'isoft_fmf_category' );
			}

			$cat_path = $cat_id ? isoft_fmf_category_folder_path( $cat_id ) : '';

			foreach ( $def['files'] as $i => $file_def ) {
				$this->create_demo_file( $post_id, $file_def, $cat_id, $cat_path, $i, $file_mgr );
			}

			$total = (int) ( $def['downloads'] ?? 0 );
			if ( $total > 0 ) {
				$this->seed_download_stats(
					(int) $post_id,
					$total,
					! empty( $def['hot'] ),
					$days_ago
				);
			}

			$created[] = (int) $post_id;
		}

		delete_transient( 'isoft_fmf_stats_overview' );

		return $created;
	}

	/**
	 * Give demo entries realistic-looking download counters and (optionally) a
	 * HOT badge so screenshots aren't full of zeros. Splits the all-time count
	 * across the download's files (first file ~60%, rest split the remainder),
	 * syncs the post-level cached SUM, and seeds 30 days of daily activity
	 * weighted toward recent days so the stats dashboard chart looks like a
	 * site that's been in service. HOT entries get a heavier recent share so
	 * the nightly HOT cron — which ranks by SUM(count) over the last 7 days
	 * in isoft_fmf_download_daily — re-elects them instead of clearing the
	 * badge we set directly.
	 */
	private function seed_download_stats( int $post_id, int $total, bool $hot, int $days_ago = 30 ): void {
		global $wpdb;

		$files_table = $wpdb->prefix . 'isoft_fmf_files';
		$daily_table = $wpdb->prefix . 'isoft_fmf_download_daily';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot demo seed; not a hot path.
		$file_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE download_id = %d ORDER BY sort_order, id',
				$files_table,
				$post_id
			)
		);

		if ( empty( $file_ids ) ) {
			return;
		}

		$counts = $this->split_count( $total, count( $file_ids ) );
		foreach ( $file_ids as $i => $file_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Demo seed.
			$wpdb->update(
				$files_table,
				array( 'download_count' => $counts[ $i ] ),
				array( 'id' => (int) $file_id )
			);
		}

		update_post_meta( $post_id, '_isoft_fmf_download_count', $total );

		// Spread window: clamp to min(30, $days_ago) — a download posted 7
		// days ago can't have activity from 30 days ago, and we don't show
		// data older than 30 days on the dashboard.
		$age          = $days_ago > 0 ? $days_ago : 30;
		$window       = max( 1, min( 30, $age ) );
		$past_release = max( 0, $age - $window );

		// Share of all-time activity that lives in the chart window.
		// Entries whose entire life is in-window: all of it. Older entries:
		// just the trailing decay tail. HOT entries get +0.10 so they keep
		// winning the 7-day HOT-cron election once the release spike has
		// fallen outside the last 7 days.
		if ( $days_ago <= $window ) {
			$share = 1.0;
		} elseif ( $days_ago <= 60 ) {
			$share = 0.30;
		} else {
			$share = 0.15;
		}
		if ( $hot ) {
			$share = min( 1.0, $share + 0.10 );
		}
		$recent_total = min( $total, max( 0, (int) round( $total * $share ) ) );

		if ( $recent_total <= 0 ) {
			if ( $hot ) {
				update_post_meta( $post_id, '_isoft_fmf_is_hot', 1 );
			}
			return;
		}

		// Per-day weights: release spike at the oldest visible day (or off
		// the left edge for older entries), exponential decay toward today,
		// floored so old entries still show low background activity, and
		// damped on Sat/Sun — municipal / document content gets ~30% of
		// weekday traffic on weekends in practice. Produces a chart that
		// looks like a real document lifecycle: spike on release, taper
		// over the following week, weekend valleys, low ongoing background.
		$half_life     = 5.0;
		$weekend_mult  = 0.30;
		$decay_floor   = 0.04;
		$release_index = $window - 1;
		$weights       = array();
		for ( $d = 0; $d < $window; $d++ ) {
			$days_since_release = ( $release_index - $d ) + $past_release;
			$decay              = max( $decay_floor, exp( -$days_since_release / $half_life ) );

			$dow    = (int) gmdate( 'N', time() - ( $d * DAY_IN_SECONDS ) );
			$weight = $decay * ( $dow >= 6 ? $weekend_mult : 1.0 );

			$weights[ $d ] = $weight;
		}
		$sum_w = array_sum( $weights );
		if ( $sum_w <= 0 ) {
			return;
		}
		$assigned = 0;

		for ( $d = 0; $d < $window - 1; $d++ ) {
			$count     = (int) round( $recent_total * ( $weights[ $d ] / $sum_w ) );
			$assigned += $count;
			if ( 0 === $count ) {
				continue;
			}
			$log_date = gmdate( 'Y-m-d', time() - ( $d * DAY_IN_SECONDS ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Demo seed.
			$wpdb->replace(
				$daily_table,
				array(
					'download_id' => $post_id,
					'log_date'    => $log_date,
					'count'       => $count,
				)
			);
		}
		// Last bucket absorbs the rounding remainder so the sum matches recent_total.
		$tail = max( 0, $recent_total - $assigned );
		if ( $tail > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Demo seed.
			$wpdb->replace(
				$daily_table,
				array(
					'download_id' => $post_id,
					'log_date'    => gmdate( 'Y-m-d', time() - ( ( $window - 1 ) * DAY_IN_SECONDS ) ),
					'count'       => $tail,
				)
			);
		}

		if ( $hot ) {
			update_post_meta( $post_id, '_isoft_fmf_is_hot', 1 );
		}
	}

	/**
	 * Split an integer total across N buckets with a front-loaded bias —
	 * first bucket gets ~60% for two-bucket splits, smoothing toward even for
	 * larger N. Always sums exactly to $total (remainder lands in bucket 0).
	 *
	 * @return list<int>
	 */
	private function split_count( int $total, int $buckets ): array {
		if ( $buckets <= 1 ) {
			return array( $total );
		}
		$out      = array_fill( 0, $buckets, 0 );
		$weighted = 0;
		// Front-load: bucket 0 gets a heavier share than the rest.
		$weights    = array_fill( 0, $buckets, 1.0 );
		$weights[0] = max( 1.5, $buckets * 0.6 );
		$sum_w      = array_sum( $weights );
		for ( $i = 0; $i < $buckets - 1; $i++ ) {
			$out[ $i ] = (int) floor( $total * ( $weights[ $i ] / $sum_w ) );
			$weighted += $out[ $i ];
		}
		$out[ $buckets - 1 ] = max( 0, $total - $weighted );
		return $out;
	}

	/**
	 * Create one WP page that showcases all three layouts (single, list, grid)
	 * using the plugin's blocks. Tagged with _isoft_fmf_demo_content so the standard
	 * Remove button cleans it up.
	 *
	 * @param list<int> $download_ids Download IDs returned by create_downloads().
	 */
	private function create_demo_page( array $download_ids ): void {
		if ( empty( $download_ids ) ) {
			return;
		}

		$featured_id = $download_ids[0];

		$page_id = wp_insert_post(
			array(
				'post_title'   => 'I-Soft File Manager: Foundation — Demo Page',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => $this->demo_page_content( $featured_id ),
			)
		);

		if ( ! is_wp_error( $page_id ) && $page_id ) {
			update_post_meta( $page_id, '_isoft_fmf_demo_content', 1 );
		}
	}

	/**
	 * Build Gutenberg block markup demonstrating the three layouts in turn:
	 * single download card → list-mode listing → grid-mode listing.
	 */
	private function demo_page_content( int $featured_id ): string {
		$intro          = 'This page was auto-generated by the I-Soft File Manager: Foundation demo content. It shows the three ways you can embed downloads inside any page or post.';
		$heading_single = 'Single download';
		$caption_single = 'The "Download Entry" block renders one specific download as a card. Useful for embedding inline inside posts and pages.';
		$heading_list   = 'List layout';
		$caption_list   = 'The "Download List" block in list mode stacks downloads one per row.';
		$heading_grid   = 'Grid layout';
		$caption_grid   = 'The same "Download List" block in grid mode renders downloads as portrait tiles in a responsive grid.';

		$parts   = array();
		$parts[] = '<!-- wp:paragraph --><p><em>' . esc_html( $intro ) . '</em></p><!-- /wp:paragraph -->';

		$parts[] = '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $heading_single ) . '</h2><!-- /wp:heading -->';
		$parts[] = '<!-- wp:paragraph --><p>' . esc_html( $caption_single ) . '</p><!-- /wp:paragraph -->';
		$parts[] = '<!-- wp:isoft-fm-foundation/download-button {"downloadId":' . (int) $featured_id . '} /-->';

		$parts[] = '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $heading_list ) . '</h2><!-- /wp:heading -->';
		$parts[] = '<!-- wp:paragraph --><p>' . esc_html( $caption_list ) . '</p><!-- /wp:paragraph -->';
		$parts[] = '<!-- wp:isoft-fm-foundation/download-list {"layout":"list","limit":6} /-->';

		$parts[] = '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $heading_grid ) . '</h2><!-- /wp:heading -->';
		$parts[] = '<!-- wp:paragraph --><p>' . esc_html( $caption_grid ) . '</p><!-- /wp:paragraph -->';
		$parts[] = '<!-- wp:isoft-fm-foundation/download-list {"layout":"grid","limit":6} /-->';

		return implode( "\n\n", $parts );
	}

	/**
	 * @return list<array{title:string,category:string,access:string,description:string,files:list<array{name:string,format:string,body:string}>}>
	 */
	private function download_definitions(): array {
		return array(
			array(
				'title'       => 'Budget Decision 2026',
				'category'    => 'session-i',
				'access'      => 'public',
				'description' => 'Municipal budget decision for fiscal year 2026, adopted at Session I of the Municipal Assembly.',
				'downloads'   => 1247,
				'hot'         => true,
				'days_ago'    => 14,
				'files'       => array(
					array(
						'name'   => 'budget-decision-2026',
						'format' => 'pdf',
						'body'   => "Municipal Budget Decision for 2026\n\nArticle 1.\nTotal revenues of the municipal budget for 2026 are planned at 500,000,000 RSD.\n\nArticle 2.\nFunds from Article 1 shall be allocated to current expenditures, capital investments, and reserves.",
					),
				),
			),
			array(
				'title'       => 'Session I Minutes',
				'category'    => 'session-i',
				'access'      => 'subscriber',
				'description' => 'Minutes from the first session of the Municipal Assembly, term 2025-2029.',
				'downloads'   => 412,
				'days_ago'    => 30,
				'files'       => array(
					array(
						'name'   => 'session-i-minutes',
						'format' => 'pdf',
						'body'   => "Minutes of Session I — Municipal Assembly\n\nDate: January 15, 2026\nPresent: 31 of 35 council members\nChair: Ivan Petrovic\n\nAgenda:\n1. Mandate verification\n2. Election of Assembly President\n3. Budget decision for 2026",
					),
				),
			),
			array(
				'title'       => 'Procurement Plan 2026',
				'category'    => 'open-procedures',
				'access'      => 'public',
				'description' => 'Annual public procurement plan for the municipality.',
				'downloads'   => 893,
				'hot'         => true,
				'days_ago'    => 21,
				'files'       => array(
					array(
						'name'   => 'procurement-plan-2026',
						'format' => 'docx',
						'body'   => "Public Procurement Plan for 2026\n\nPursuant to Article 88 of the Public Procurement Act, the municipality adopts the annual procurement plan.\n\n1. Office supplies — estimated value: 2,000,000 RSD\n2. Road maintenance — estimated value: 15,000,000 RSD\n3. IT equipment — estimated value: 5,000,000 RSD",
					),
				),
			),
			array(
				'title'       => 'Final Account 2025',
				'category'    => 'final-account',
				'access'      => 'public',
				'description' => 'Municipal budget final account for fiscal year 2025.',
				'downloads'   => 187,
				'days_ago'    => 60,
				'files'       => array(
					array(
						'name'   => 'final-account-2025',
						'format' => 'pdf',
						'body'   => "Budget Final Account for 2025\n\nTotal realized revenue: 485,000,000 RSD\nTotal executed expenditure: 472,000,000 RSD\nBudget surplus: 13,000,000 RSD",
					),
				),
			),
			array(
				'title'       => 'Urban Development Plan — Draft',
				'category'    => 'urban-planning',
				'access'      => 'editor',
				'description' => 'Draft general regulation plan for the municipal area.',
				'downloads'   => 56,
				'days_ago'    => 7,
				'files'       => array(
					array(
						'name'   => 'urban-plan-draft',
						'format' => 'pdf',
						'body'   => "Urban Development Plan — Draft\n\nThe planning area covers 320 hectares in the central municipal zone.\n\nLand use:\n- Residential zone: 45%\n- Business zone: 20%\n- Green areas: 25%\n- Transportation: 10%",
					),
					array(
						'name'   => 'urban-plan-appendix',
						'format' => 'docx',
						'body'   => "Appendix A — Parcel List\n\nParcel 1234/1 — 0.5 ha — residential zone\nParcel 1234/2 — 0.3 ha — business zone\nParcel 1235/1 — 1.2 ha — green area",
					),
				),
			),
			array(
				'title'       => 'Appointment Decision',
				'category'    => 'resolutions',
				'access'      => 'public',
				'description' => 'Decision on the appointment of the Municipal Administration Chief.',
				'downloads'   => 23,
				'days_ago'    => 45,
				'files'       => array(
					array(
						'name'   => 'appointment-decision',
						'format' => 'pdf',
						'body'   => "Appointment Decision\n\nPursuant to Article 56 of the Local Self-Government Act, the Municipal Council adopts the decision on the appointment of the Chief of Municipal Administration.",
					),
				),
			),
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// File generators
	// ─────────────────────────────────────────────────────────────────────────

	private function create_demo_file(
		int $post_id,
		array $file_def,
		int $cat_id,
		string $cat_path,
		int $sort_order,
		ISOFT_FMF_File_Manager $mgr
	): void {
		$format = $file_def['format'];
		$name   = $file_def['name'];
		$title  = $file_def['body'];
		$body   = $file_def['body'];

		if ( $cat_id ) {
			ISOFT_FMF_Category_Folders::ensure( $cat_id );
		}

		$content = null;
		$ext     = $format;
		$mime    = 'text/plain';

		if ( 'pdf' === $format ) {
			$content = $this->generate_pdf( $name, $body );
			$mime    = 'application/pdf';
		} elseif ( 'docx' === $format ) {
			$content = $this->generate_docx( $name, $body );
			$mime    = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		}

		if ( null === $content ) {
			$content = "I-Soft File Manager: Foundation Demo File\n\n{$body}\n\nGenerated: " . wp_date( 'Y-m-d H:i:s' ) . "\n";
			$ext     = 'txt';
			$mime    = 'text/plain';
		}

		$filename = "{$name}.{$ext}";
		$rel_path = $cat_path ? "{$cat_path}/{$filename}" : $filename;
		$abs_path = isoft_fmf_files_dir() . '/' . $rel_path;

		$dir = dirname( $abs_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing generated demo file to plugin storage directory.
		file_put_contents( $abs_path, $content );

		$mgr->add_local_file(
			$post_id,
			array(
				'title'      => ucwords( str_replace( '-', ' ', $name ) ),
				'file_name'  => $filename,
				'file_path'  => $rel_path,
				'file_size'  => filesize( $abs_path ),
				'file_mime'  => $mime,
				'file_hash'  => hash_file( 'sha256', $abs_path ),
				'sort_order' => $sort_order,
			)
		);
	}

	/**
	 * Generate a minimal valid PDF with text content. No external libraries.
	 */
	private function generate_pdf( string $title, string $body ): string {
		$lines     = $this->pdf_escape_lines( $body );
		$font_size = 11;
		$leading   = 14;
		$margin_x  = 72;
		$page_h    = 842;
		$start_y   = $page_h - 72;

		$stream  = "BT\n";
		$stream .= "/F1 {$font_size} Tf\n";
		$stream .= "{$margin_x} {$start_y} Td\n";
		$stream .= "0 -{$leading} Td\n";

		foreach ( $lines as $line ) {
			$stream .= "({$line}) Tj\n";
			$stream .= "0 -{$leading} Td\n";
		}
		$stream .= "ET\n";

		$objects   = array();
		$objects[] = null; // 1-indexed

		// Object 1: Catalog
		$objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

		// Object 2: Pages
		$objects[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

		// Object 3: Page
		$objects[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 {$page_h}] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";

		// Object 4: Content stream
		$stream_len = strlen( $stream );
		$objects[4] = "4 0 obj\n<< /Length {$stream_len} >>\nstream\n{$stream}endstream\nendobj\n";

		// Object 5: Font
		$objects[5] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$offsets[ $i ] = strlen( $pdf );
			$pdf          .= $objects[ $i ];
		}

		$xref_offset = strlen( $pdf );
		$pdf        .= "xref\n0 6\n";
		$pdf        .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= 5; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}

		$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\n";
		$pdf .= "startxref\n{$xref_offset}\n%%EOF\n";

		return $pdf;
	}

	/**
	 * @return list<string>
	 */
	private function pdf_escape_lines( string $text ): array {
		$lines = explode( "\n", str_replace( "\r\n", "\n", $text ) );
		return array_map(
			function ( string $line ): string {
				$line = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $line );
				return preg_replace( '/[^\x20-\x7E]/', '', $line );
			},
			$lines
		);
	}

	/**
	 * Generate a minimal valid DOCX. Returns null if ZipArchive unavailable.
	 */
	private function generate_docx( string $title, string $body ): ?string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$tmp = wp_tempnam( 'isoft_fmf_demo_' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return null;
		}

		$zip->addFromString( '[Content_Types].xml', $this->docx_content_types() );
		$zip->addFromString( '_rels/.rels', $this->docx_rels() );
		$zip->addFromString( 'word/_rels/document.xml.rels', $this->docx_document_rels() );
		$zip->addFromString( 'word/document.xml', $this->docx_document( $title, $body ) );
		$zip->close();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading temp file we just created.
		$content = file_get_contents( $tmp );
		wp_delete_file( $tmp );

		return $content ?: null;
	}

	private function docx_content_types(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';
	}

	private function docx_rels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';
	}

	private function docx_document_rels(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '</Relationships>';
	}

	private function docx_document( string $title, string $body ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
		$xml .= '<w:body>';

		$lines = explode( "\n", str_replace( "\r\n", "\n", $body ) );
		foreach ( $lines as $line ) {
			$escaped = htmlspecialchars( $line, ENT_XML1, 'UTF-8' );
			$xml    .= '<w:p><w:r><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>';
		}

		$xml .= '</w:body></w:document>';
		return $xml;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Removal
	// ─────────────────────────────────────────────────────────────────────────

	private function remove_demo_posts(): void {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only one-shot removal; bounded by demo content count (~6 posts + 1 page).
		$posts = get_posts(
			array(
				'post_type'      => array( 'isoft_fmf_file', 'page' ),
				'post_status'    => 'any',
				'meta_key'       => '_isoft_fmf_demo_content',
				'meta_value'     => '1',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		global $wpdb;
		$daily_table = $wpdb->prefix . 'isoft_fmf_download_daily';
		$log_table   = $wpdb->prefix . 'isoft_fmf_download_log';

		$file_mgr = new ISOFT_FMF_File_Manager();
		foreach ( $posts as $post_id ) {
			// Pages have no associated isoft_fmf_files rows; only run file cleanup for downloads.
			if ( 'isoft_fmf_file' === get_post_type( $post_id ) ) {
				$files = $file_mgr->get_files( $post_id );
				foreach ( $files as $file ) {
					if ( $file->file_path ) {
						$abs = isoft_fmf_files_dir() . '/' . $file->file_path;
						if ( file_exists( $abs ) ) {
							wp_delete_file( $abs );
						}
					}
					$file_mgr->delete_file( (int) $file->id );
				}
				// Clear seeded daily-log rows + per-event log rows so a
				// re-generate / remove cycle doesn't leave orphaned
				// download_ids cluttering the dashboard's "(deleted)" row.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot demo cleanup.
				$wpdb->delete( $daily_table, array( 'download_id' => (int) $post_id ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot demo cleanup.
				$wpdb->delete( $log_table, array( 'download_id' => (int) $post_id ) );
			}
			wp_delete_post( $post_id, true );
		}

		delete_transient( 'isoft_fmf_stats_overview' );
	}

	private function remove_demo_terms(): void {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only one-shot cleanup; no persistent query, no performance concern.
		$terms = get_terms(
			array(
				'taxonomy'   => 'isoft_fmf_category',
				'hide_empty' => false,
				'meta_key'   => '_isoft_fmf_demo_term',
				'meta_value' => '1',
				'fields'     => 'ids',
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		if ( is_wp_error( $terms ) ) {
			return;
		}

		// Delete deepest children first to avoid parent conflicts.
		$terms = array_reverse( $terms );
		foreach ( $terms as $term_id ) {
			wp_delete_term( (int) $term_id, 'isoft_fmf_category' );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	private function settings_url( string $extra = '' ): string {
		$url = admin_url( 'edit.php?post_type=isoft_fmf_file&page=isoft-fmf-settings&tab=maintenance' );
		return $extra ? "{$url}&{$extra}" : $url;
	}
}
