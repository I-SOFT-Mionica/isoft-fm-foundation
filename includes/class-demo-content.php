<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Demo_Content {

	public function register_hooks(): void {
		add_action( 'admin_post_isfm_install_demo', array( $this, 'handle_install' ) );
		add_action( 'admin_post_isfm_remove_demo', array( $this, 'handle_remove' ) );
	}

	public static function has_content(): bool {
		$counts = wp_count_posts( 'isfm_file' );
		return ( (int) $counts->publish + (int) $counts->draft + (int) $counts->pending ) > 0;
	}

	public static function has_demo_content(): bool {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only one-shot check; LIMIT 1 keeps it fast.
		$query = new WP_Query(
			array(
				'post_type'      => 'isfm_file',
				'post_status'    => 'any',
				'meta_key'       => '_isfm_demo_content',
				'meta_value'     => '1',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		return $query->post_count > 0;
	}

	public function handle_install(): void {
		check_admin_referer( 'isfm_install_demo' );
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to install demo content.', 'isoft-fm-foundation' ), 403 );
		}
		if ( self::has_content() ) {
			isfm_notify_admin( __( 'Demo content cannot be installed — downloads already exist.', 'isoft-fm-foundation' ), 'error' );
			wp_safe_redirect( $this->settings_url() );
			exit;
		}

		$categories   = $this->create_categories();
		$download_ids = $this->create_downloads( $categories );
		$this->create_demo_page( $download_ids );

		isfm_notify_admin( __( 'Demo content installed successfully.', 'isoft-fm-foundation' ), 'success' );
		wp_safe_redirect( $this->settings_url( 'isfm_demo=installed' ) );
		exit;
	}

	public function handle_remove(): void {
		check_admin_referer( 'isfm_remove_demo' );
		if ( ! current_user_can( 'isfm_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to remove demo content.', 'isoft-fm-foundation' ), 403 );
		}

		$this->remove_demo_posts();
		$this->remove_demo_terms();

		isfm_notify_admin( __( 'Demo content removed.', 'isoft-fm-foundation' ), 'success' );
		wp_safe_redirect( $this->settings_url( 'isfm_demo=removed' ) );
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

	// ─────────────────────────────────────────────────────────────────────────
	// Content definitions
	// ─────────────────────────────────────────────────────────────────────────

	private function use_serbian(): bool {
		return (bool) isfm_get_settings()['cyrillic_titles'];
	}

	/**
	 * @return array<string,int> Keyed by internal slug → term_id.
	 */
	private function create_categories(): array {
		$sr   = $this->use_serbian();
		$ids  = array();
		$tree = $this->category_tree( $sr );

		foreach ( $tree as $slug => $node ) {
			$parent_id = 0;
			if ( ! empty( $node['parent'] ) && isset( $ids[ $node['parent'] ] ) ) {
				$parent_id = $ids[ $node['parent'] ];
			}

			$result = wp_insert_term(
				$node['name'],
				'isfm_category',
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
			update_term_meta( $ids[ $slug ], '_isfm_demo_term', 1 );
		}

		return $ids;
	}

	/**
	 * @return array<string,array{name:string,parent?:string}>
	 */
	private function category_tree( bool $sr ): array {
		return array(
			'municipal-assembly'    => array( 'name' => $sr ? 'Скупштина општине' : 'Municipal Assembly' ),
			'term-2025-2029'        => array(
				'name'   => $sr ? 'Сазив 2025-2029' : 'Term 2025-2029',
				'parent' => 'municipal-assembly',
			),
			'session-i'             => array(
				'name'   => $sr ? 'I седница' : 'Session I',
				'parent' => 'term-2025-2029',
			),
			'session-ii'            => array(
				'name'   => $sr ? 'II седница' : 'Session II',
				'parent' => 'term-2025-2029',
			),
			'term-2021-2025'        => array(
				'name'   => $sr ? 'Сазив 2021-2025' : 'Term 2021-2025',
				'parent' => 'municipal-assembly',
			),
			'municipal-council'     => array( 'name' => $sr ? 'Општинско веће' : 'Municipal Council' ),
			'decisions'             => array(
				'name'   => $sr ? 'Одлуке' : 'Decisions',
				'parent' => 'municipal-council',
			),
			'resolutions'           => array(
				'name'   => $sr ? 'Решења' : 'Resolutions',
				'parent' => 'municipal-council',
			),
			'public-procurement'    => array( 'name' => $sr ? 'Јавне набавке' : 'Public Procurement' ),
			'open-procedures'       => array(
				'name'   => $sr ? 'Отворени поступци' : 'Open Procedures',
				'parent' => 'public-procurement',
			),
			'negotiated-procedures' => array(
				'name'   => $sr ? 'Преговарачки поступци' : 'Negotiated Procedures',
				'parent' => 'public-procurement',
			),
			'urban-planning'        => array( 'name' => $sr ? 'Урбанизам' : 'Urban Planning' ),
			'finance'               => array( 'name' => $sr ? 'Финансије' : 'Finance' ),
			'budget'                => array(
				'name'   => $sr ? 'Буџет' : 'Budget',
				'parent' => 'finance',
			),
			'final-account'         => array(
				'name'   => $sr ? 'Завршни рачун' : 'Final Account',
				'parent' => 'finance',
			),
		);
	}

	/**
	 * @param array<string,int> $cats Category slug → term_id map.
	 * @return list<int> Created download post IDs, in creation order.
	 */
	private function create_downloads( array $cats ): array {
		$sr        = $this->use_serbian();
		$downloads = $this->download_definitions( $sr );
		$file_mgr  = new ISFM_File_Manager();
		$created   = array();

		foreach ( $downloads as $def ) {
			$cat_id = $cats[ $def['category'] ] ?? 0;

			$post_id = wp_insert_post(
				array(
					'post_title'   => $def['title'],
					'post_status'  => 'publish',
					'post_type'    => 'isfm_file',
					'post_content' => $def['description'] ?? '',
				)
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			update_post_meta( $post_id, '_isfm_demo_content', 1 );
			update_post_meta( $post_id, '_isfm_access_role', $def['access'] );

			if ( $cat_id ) {
				wp_set_object_terms( $post_id, $cat_id, 'isfm_category' );
			}

			$cat_path = $cat_id ? isfm_category_folder_path( $cat_id ) : '';

			foreach ( $def['files'] as $i => $file_def ) {
				$this->create_demo_file( $post_id, $file_def, $cat_id, $cat_path, $i, $file_mgr );
			}

			$created[] = (int) $post_id;
		}

		return $created;
	}

	/**
	 * Create one WP page that showcases all three layouts (single, list, grid)
	 * using the plugin's blocks. Tagged with _isfm_demo_content so the standard
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
				'post_title'   => $this->use_serbian() ? 'I-Soft File Manager: Foundation — Демо страница' : 'I-Soft File Manager: Foundation — Demo Page',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => $this->demo_page_content( $featured_id ),
			)
		);

		if ( ! is_wp_error( $page_id ) && $page_id ) {
			update_post_meta( $page_id, '_isfm_demo_content', 1 );
		}
	}

	/**
	 * Build Gutenberg block markup demonstrating the three layouts in turn:
	 * single download card → list-mode listing → grid-mode listing.
	 */
	private function demo_page_content( int $featured_id ): string {
		$sr = $this->use_serbian();

		$intro          = $sr
			? 'Ова страница је аутоматски генерисана од стране I-Soft File Manager: Foundation демо садржаја. Приказује три начина уграђивања преузимања у страницу или објаву.'
			: 'This page was auto-generated by the I-Soft File Manager: Foundation demo content. It shows the three ways you can embed downloads inside any page or post.';
		$heading_single = $sr ? 'Појединачно преузимање' : 'Single download';
		$caption_single = $sr
			? 'Блок „Унос за преузимање“ приказује једно одређено преузимање као картицу. Корисно за инлајн уграђивање у објаве и странице.'
			: 'The "Download Entry" block renders one specific download as a card. Useful for embedding inline inside posts and pages.';
		$heading_list   = $sr ? 'Распоред листе' : 'List layout';
		$caption_list   = $sr
			? 'Блок „Листа преузимања“ у режиму листе слаже преузимања по реду, једно испод другог.'
			: 'The "Download List" block in list mode stacks downloads one per row.';
		$heading_grid   = $sr ? 'Распоред мреже' : 'Grid layout';
		$caption_grid   = $sr
			? 'Иста „Листа преузимања“ у режиму мреже приказује преузимања као портретне плочице у респонзивној мрежи.'
			: 'The same "Download List" block in grid mode renders downloads as portrait tiles in a responsive grid.';

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
	private function download_definitions( bool $sr ): array {
		return array(
			array(
				'title'       => $sr ? 'Одлука о буџету за 2026. годину' : 'Budget Decision 2026',
				'category'    => 'session-i',
				'access'      => 'public',
				'description' => $sr
					? 'Одлука о буџету општине Мионица за фискалну 2026. годину, усвојена на I седници Скупштине општине.'
					: 'Municipal budget decision for fiscal year 2026, adopted at Session I of the Municipal Assembly.',
				'files'       => array(
					array(
						'name'   => 'budget-decision-2026',
						'format' => 'pdf',
						'body'   => $sr
							? "Одлука о буџету општине Мионица за 2026. годину\n\nЧлан 1.\nУкупни приходи буџета општине Мионица за 2026. годину планирани су у износу од 500.000.000 динара.\n\nЧлан 2.\nСредства из члана 1. ове одлуке распоређују се на текуће расходе, капиталне издатке и резерве."
							: "Municipal Budget Decision for 2026\n\nArticle 1.\nTotal revenues of the municipal budget for 2026 are planned at 500,000,000 RSD.\n\nArticle 2.\nFunds from Article 1 shall be allocated to current expenditures, capital investments, and reserves.",
					),
				),
			),
			array(
				'title'       => $sr ? 'Записник са I седнице' : 'Session I Minutes',
				'category'    => 'session-i',
				'access'      => 'subscriber',
				'description' => $sr
					? 'Записник са прве седнице Скупштине општине у сазиву 2025-2029.'
					: 'Minutes from the first session of the Municipal Assembly, term 2025-2029.',
				'files'       => array(
					array(
						'name'   => 'session-i-minutes',
						'format' => 'pdf',
						'body'   => $sr
							? "Записник са I седнице Скупштине општине\n\nДатум: 15. јануар 2026.\nПрисутно: 31 од 35 одборника\nПредседавајући: Иван Петровић\n\nДневни ред:\n1. Верификација мандата\n2. Избор председника Скупштине\n3. Одлука о буџету за 2026. годину"
							: "Minutes of Session I — Municipal Assembly\n\nDate: January 15, 2026\nPresent: 31 of 35 council members\nChair: Ivan Petrovic\n\nAgenda:\n1. Mandate verification\n2. Election of Assembly President\n3. Budget decision for 2026",
					),
				),
			),
			array(
				'title'       => $sr ? 'План јавних набавки за 2026' : 'Procurement Plan 2026',
				'category'    => 'open-procedures',
				'access'      => 'public',
				'description' => $sr
					? 'Годишњи план јавних набавки општине Мионица.'
					: 'Annual public procurement plan for the municipality.',
				'files'       => array(
					array(
						'name'   => 'procurement-plan-2026',
						'format' => 'docx',
						'body'   => $sr
							? "План јавних набавки за 2026. годину\n\nНа основу члана 88. Закона о јавним набавкама, општина Мионица доноси годишњи план набавки.\n\n1. Канцеларијски материјал — процењена вредност: 2.000.000 РСД\n2. Одржавање путева — процењена вредност: 15.000.000 РСД\n3. Информатичка опрема — процењена вредност: 5.000.000 РСД"
							: "Public Procurement Plan for 2026\n\nPursuant to Article 88 of the Public Procurement Act, the municipality adopts the annual procurement plan.\n\n1. Office supplies — estimated value: 2,000,000 RSD\n2. Road maintenance — estimated value: 15,000,000 RSD\n3. IT equipment — estimated value: 5,000,000 RSD",
					),
				),
			),
			array(
				'title'       => $sr ? 'Одлука о завршном рачуну за 2025' : 'Final Account 2025',
				'category'    => 'final-account',
				'access'      => 'public',
				'description' => $sr
					? 'Завршни рачун буџета општине за фискалну 2025. годину.'
					: 'Municipal budget final account for fiscal year 2025.',
				'files'       => array(
					array(
						'name'   => 'final-account-2025',
						'format' => 'pdf',
						'body'   => $sr
							? "Завршни рачун буџета за 2025. годину\n\nУкупно остварени приходи: 485.000.000 РСД\nУкупно извршени расходи: 472.000.000 РСД\nБуџетски суфицит: 13.000.000 РСД"
							: "Budget Final Account for 2025\n\nTotal realized revenue: 485,000,000 RSD\nTotal executed expenditure: 472,000,000 RSD\nBudget surplus: 13,000,000 RSD",
					),
				),
			),
			array(
				'title'       => $sr ? 'Урбанистички план — нацрт' : 'Urban Development Plan — Draft',
				'category'    => 'urban-planning',
				'access'      => 'editor',
				'description' => $sr
					? 'Нацрт плана генералне регулације за подручје општине Мионица.'
					: 'Draft general regulation plan for the municipal area.',
				'files'       => array(
					array(
						'name'   => 'urban-plan-draft',
						'format' => 'pdf',
						'body'   => $sr
							? "Нацрт урбанистичког плана\n\nПланско подручје обухвата 320 хектара у централној зони општине.\n\nНамена простора:\n- Зона становања: 45%\n- Пословна зона: 20%\n- Зелене површине: 25%\n- Саобраћајнице: 10%"
							: "Urban Development Plan — Draft\n\nThe planning area covers 320 hectares in the central municipal zone.\n\nLand use:\n- Residential zone: 45%\n- Business zone: 20%\n- Green areas: 25%\n- Transportation: 10%",
					),
					array(
						'name'   => 'urban-plan-appendix',
						'format' => 'docx',
						'body'   => $sr
							? "Прилог А — Списак парцела\n\nПарцела 1234/1 — 0.5 ха — зона становања\nПарцела 1234/2 — 0.3 ха — пословна зона\nПарцела 1235/1 — 1.2 ха — зелена површина"
							: "Appendix A — Parcel List\n\nParcel 1234/1 — 0.5 ha — residential zone\nParcel 1234/2 — 0.3 ha — business zone\nParcel 1235/1 — 1.2 ha — green area",
					),
				),
			),
			array(
				'title'       => $sr ? 'Решење о постављењу' : 'Appointment Decision',
				'category'    => 'resolutions',
				'access'      => 'public',
				'description' => $sr
					? 'Решење о постављењу начелника Општинске управе.'
					: 'Decision on the appointment of the Municipal Administration Chief.',
				'files'       => array(
					array(
						'name'   => 'appointment-decision',
						'format' => 'pdf',
						'body'   => $sr
							? "Решење о постављењу\n\nНа основу члана 56. Закона о локалној самоуправи, Општинско веће доноси решење о постављењу начелника Општинске управе општине Мионица."
							: "Appointment Decision\n\nPursuant to Article 56 of the Local Self-Government Act, the Municipal Council adopts the decision on the appointment of the Chief of Municipal Administration.",
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
		ISFM_File_Manager $mgr
	): void {
		$format = $file_def['format'];
		$name   = $file_def['name'];
		$title  = $file_def['body'];
		$body   = $file_def['body'];

		if ( $cat_id ) {
			ISFM_Category_Folders::ensure( $cat_id );
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
		$abs_path = isfm_files_dir() . '/' . $rel_path;

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

		$tmp = wp_tempnam( 'isfm_demo_' );

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
				'post_type'      => array( 'isfm_file', 'page' ),
				'post_status'    => 'any',
				'meta_key'       => '_isfm_demo_content',
				'meta_value'     => '1',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$file_mgr = new ISFM_File_Manager();
		foreach ( $posts as $post_id ) {
			// Pages have no associated isfm_files rows; only run file cleanup for downloads.
			if ( 'isfm_file' === get_post_type( $post_id ) ) {
				$files = $file_mgr->get_files( $post_id );
				foreach ( $files as $file ) {
					if ( $file->file_path ) {
						$abs = isfm_files_dir() . '/' . $file->file_path;
						if ( file_exists( $abs ) ) {
							wp_delete_file( $abs );
						}
					}
					$file_mgr->delete_file( (int) $file->id );
				}
			}
			wp_delete_post( $post_id, true );
		}
	}

	private function remove_demo_terms(): void {
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only one-shot cleanup; no persistent query, no performance concern.
		$terms = get_terms(
			array(
				'taxonomy'   => 'isfm_category',
				'hide_empty' => false,
				'meta_key'   => '_isfm_demo_term',
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
			wp_delete_term( (int) $term_id, 'isfm_category' );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	private function settings_url( string $extra = '' ): string {
		$url = admin_url( 'edit.php?post_type=isfm_file&page=isfm-settings&tab=maintenance' );
		return $extra ? "{$url}&{$extra}" : $url;
	}
}
