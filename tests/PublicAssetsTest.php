<?php
/**
 * Public CSS/JS loading: in the head when the page visibly holds Foundation
 * content, and at render time for content the head check cannot see (page
 * builder templates, theme parts, popups).
 */

class PublicAssetsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->reset_assets();
	}

	public function tear_down(): void {
		$this->reset_assets();
		parent::tear_down();
	}

	private function reset_assets(): void {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	private function loaded(): bool {
		return wp_script_is( 'isoft-fmf-public', 'enqueued' ) && wp_style_is( 'isoft-fmf-public', 'enqueued' );
	}

	private function page( string $content ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
	}

	public function test_page_without_foundation_content_loads_nothing(): void {
		$this->go_to( get_permalink( $this->page( 'Plain text.' ) ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( 'isoft-fmf-public', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'isoft-fmf-public', 'enqueued' ) );
	}

	public function test_shortcode_in_post_content_loads_in_head(): void {
		$this->go_to( get_permalink( $this->page( '[isoft_fmf_list]' ) ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( $this->loaded() );
	}

	public function test_shortcode_rendered_from_elsewhere_loads_on_render(): void {
		// A builder template or theme part: the shortcode is not in the
		// current post, so the head check says no.
		$this->go_to( get_permalink( $this->page( 'Plain text.' ) ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( $this->loaded() );

		do_shortcode( '[isoft_fmf_categories]' );

		$this->assertTrue( $this->loaded() );
	}

	public function test_late_assets_are_printed_in_the_footer(): void {
		$this->go_to( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );
		do_shortcode( '[isoft_fmf_search]' );

		ob_start();
		_wp_footer_scripts();
		$footer = ob_get_clean();

		$this->assertStringContainsString( 'public/css/public-style.css', $footer );
		$this->assertStringContainsString( 'public/js/public-script.js', $footer );
	}

	public function test_repeated_renders_enqueue_once(): void {
		do_shortcode( '[isoft_fmf_list][isoft_fmf_search][isoft_fmf_list]' );

		$data = (string) wp_scripts()->get_data( 'isoft-fmf-public', 'data' );
		$this->assertSame( 1, substr_count( $data, 'var ISFMPublic' ) );
	}

	public function test_other_shortcodes_do_not_load_assets(): void {
		add_shortcode(
			'isoft_test_other',
			static function (): string {
				return 'x';
			}
		);
		do_shortcode( '[isoft_test_other]' );
		remove_shortcode( 'isoft_test_other' );

		$this->assertFalse( $this->loaded() );
	}

	public function test_admin_render_loads_nothing(): void {
		set_current_screen( 'edit.php' );
		do_shortcode( '[isoft_fmf_list]' );
		set_current_screen( 'front' );

		$this->assertFalse( $this->loaded() );
	}

	public function test_download_button_block_loads_on_render(): void {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'isoft_fmf_file',
				'post_status' => 'publish',
				'post_title'  => 'Odluka',
			)
		);

		render_block(
			array(
				'blockName'    => 'isoft-fm-foundation/download-button',
				'attrs'        => array( 'downloadId' => $id ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		$this->assertTrue( $this->loaded() );
	}

	public function test_elementor_preview_loads_assets(): void {
		do_action( 'elementor/preview/enqueue_styles' );

		$this->assertTrue( $this->loaded() );
	}

	public function test_filter_forces_head_loading(): void {
		$this->go_to( home_url( '/' ) );
		add_filter( 'isoft_fmf_needs_assets', '__return_true' );
		do_action( 'wp_enqueue_scripts' );
		remove_filter( 'isoft_fmf_needs_assets', '__return_true' );

		$this->assertTrue( $this->loaded() );
	}
}
