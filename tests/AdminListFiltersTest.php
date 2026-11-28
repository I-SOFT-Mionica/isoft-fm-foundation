<?php
/**
 * The Downloads list screen: format and kind filters, the sortable
 * Downloads column, and the single Category column.
 */

class AdminListFiltersTest extends WP_UnitTestCase {

	private ISOFT_FMF_Admin_Columns $columns;
	private int $pdf_upload;
	private int $docx_upload;
	private int $pdf_link;
	private int $no_files;

	public function set_up(): void {
		parent::set_up();
		$this->columns = new ISOFT_FMF_Admin_Columns();
		$this->columns->register_hooks();
		wp_cache_delete( 'formats', 'isoft_fmf_admin_list' );

		$this->pdf_upload  = $this->download_with_file( 'local', 'Report.PDF' );
		$this->docx_upload = $this->download_with_file( 'local', 'minutes.docx' );
		$this->pdf_link    = $this->download_with_file( 'external', 'plan.pdf' );
		$this->no_files    = (int) self::factory()->post->create( array( 'post_type' => 'isoft_fmf_file' ) );
	}

	public function tear_down(): void {
		remove_filter( 'posts_where', array( $this->columns, 'filter_by_files' ), 10 );
		remove_action( 'pre_get_posts', array( $this->columns, 'apply_list_query' ) );
		parent::tear_down();
	}

	private function download_with_file( string $kind, string $file_name ): int {
		global $wpdb;
		$id = (int) self::factory()->post->create( array( 'post_type' => 'isoft_fmf_file' ) );
		$wpdb->insert(
			$wpdb->prefix . 'isoft_fmf_files',
			array(
				'download_id' => $id,
				'file_type'   => $kind,
				'file_name'   => $file_name,
			)
		);
		return $id;
	}

	/** @return int[] */
	private function ids_for( string $format, string $kind ): array {
		$ids = ( new WP_Query(
			array(
				'post_type'                              => 'isoft_fmf_file',
				'post_status'                            => 'any',
				'fields'                                 => 'ids',
				'posts_per_page'                         => -1,
				ISOFT_FMF_Admin_Columns::FILE_FILTER_VAR => array(
					'format' => $format,
					'kind'   => $kind,
				),
			)
		) )->posts;
		sort( $ids );
		return array_map( 'intval', $ids );
	}

	public function test_format_filter_matches_extension_case_insensitively(): void {
		$expected = array( $this->pdf_upload, $this->pdf_link );
		sort( $expected );
		$this->assertSame( $expected, $this->ids_for( 'pdf', '' ) );
	}

	public function test_kind_filter(): void {
		$this->assertSame( array( $this->pdf_link ), $this->ids_for( '', 'external' ) );
	}

	public function test_format_and_kind_apply_to_the_same_file(): void {
		$this->assertSame( array( $this->pdf_upload ), $this->ids_for( 'pdf', 'local' ) );
		$this->assertSame( array(), $this->ids_for( 'docx', 'external' ) );
	}

	public function test_invalid_values_do_not_filter(): void {
		$ids = $this->ids_for( "pdf' OR 1=1 --", 'bogus' );
		$this->assertContains( $this->no_files, $ids );
		$this->assertSame( '', ISOFT_FMF_Admin_Columns::sanitize_format( '../pdf' ) );
		$this->assertSame( 'pdf', ISOFT_FMF_Admin_Columns::sanitize_format( ' PDF ' ) );
		$this->assertSame( '', ISOFT_FMF_Admin_Columns::sanitize_kind( 'LOCAL' ) );
	}

	public function test_formats_lists_extensions_in_the_library(): void {
		$formats = ISOFT_FMF_Admin_Columns::formats();
		$this->assertContains( 'pdf', $formats );
		$this->assertContains( 'docx', $formats );
		$this->assertSame( array_values( array_unique( $formats ) ), $formats );
	}

	public function test_downloads_column_is_sortable(): void {
		$sortable = apply_filters( 'manage_edit-isoft_fmf_file_sortable_columns', array() );
		$this->assertArrayHasKey( 'isoft_fmf_download_count', $sortable );
	}

	public function test_category_column_appears_once(): void {
		$this->assertFalse( get_taxonomy( 'isoft_fmf_category' )->show_admin_column );
		$columns = $this->columns->add_columns(
			array(
				'cb'    => '',
				'title' => 'Title',
			)
		);
		$this->assertArrayHasKey( 'isoft_fmf_category', $columns );
	}

	public function test_category_dropdown_and_file_filters_render(): void {
		ob_start();
		$this->columns->render_filters( 'isoft_fmf_file', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="isoft_fmf_category"', $html );
		$this->assertStringContainsString( 'name="isoft_fmf_format"', $html );
		$this->assertStringContainsString( '<option value="pdf"', $html );
		$this->assertStringContainsString( 'name="isoft_fmf_kind"', $html );

		ob_start();
		$this->columns->render_filters( 'post', 'top' );
		$this->assertSame( '', ob_get_clean() );
	}
}
