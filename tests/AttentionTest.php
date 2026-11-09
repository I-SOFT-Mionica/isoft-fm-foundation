<?php
/**
 * The Needs attention screen: Foundation's own lists, sources added by other
 * plugins, actions, and text from untrusted sources.
 */

class AttentionTest extends WP_UnitTestCase {

	/** @var array<string, array<string, mixed>> */
	private array $registered;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->registered = $this->sources();
		delete_transient( 'isoft_fmf_missing_count' );
		delete_option( 'isoft_fmf_task_stops' );
		ISOFT_FMF_Category_Pending::flush();
	}

	public function tear_down(): void {
		$this->set_sources( $this->registered );
		delete_transient( 'isoft_fmf_missing_count' );
		parent::tear_down();
	}

	private function sources_property(): ReflectionProperty {
		$property = new ReflectionProperty( ISOFT_FMF_Attention::class, 'sources' );
		$property->setAccessible( true );
		return $property;
	}

	private function sources(): array {
		return $this->sources_property()->getValue();
	}

	private function set_sources( array $sources ): void {
		$this->sources_property()->setValue( null, $sources );
	}

	private function page(): string {
		ob_start();
		try {
			ISOFT_FMF_Attention::render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function source( array $items, array $extra = array() ): array {
		return array_merge(
			array(
				'label' => 'Held things',
				'count' => static fn(): int => count( $items ),
				'items' => static fn( int $page, int $per_page ): array => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			),
			$extra
		);
	}

	private function file( int $download ): int {
		return (int) ( new ISOFT_FMF_Files_Service() )->add_external( $download, 'https://example.org/' . wp_generate_password( 6, false ) );
	}

	private function download(): int {
		return (int) isoft_fmf_create_draft_download( array( 'title' => 'Attention download' ) );
	}

	public function test_foundation_lists_register_themselves(): void {
		foreach ( array( 'missing_files', 'pending_categories', 'stopped_tasks', 'unavailable_files' ) as $id ) {
			$this->assertArrayHasKey( $id, $this->registered, $id );
		}
		$this->assertTrue( isoft_fmf_has_feature( 'attention_screen' ) );
	}

	public function test_an_empty_site_says_so_and_mentions_no_extension(): void {
		$html = $this->page();

		$this->assertStringContainsString( 'Nothing needs your attention', $html );
		foreach ( array( 'Sentinel', 'Orbit', 'Nomad', 'Arbiter', 'Sage', 'Codices', 'install' ) as $word ) {
			$this->assertStringNotContainsStringIgnoringCase( $word, $html, $word );
		}
		$this->assertSame( 0, ISOFT_FMF_Attention::menu_count() );
	}

	public function test_a_pending_category_is_listed_with_approve(): void {
		$cat = isoft_fmf_create_category(
			array(
				'name'    => 'Waiting ' . wp_generate_password( 6, false ),
				'pending' => true,
			)
		);

		$items = ISOFT_FMF_Attention::items_for( 'pending_categories', 1, 20 );

		$this->assertSame( (string) $cat['term_id'], $items[0]['id'] );
		$this->assertSame( 'Approve', $items[0]['actions'][0]['label'] );
		$this->assertSame( 1, ISOFT_FMF_Attention::menu_count() );
	}

	public function test_a_missing_file_is_listed_and_counted(): void {
		global $wpdb;
		$file = $this->file( $this->download() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->update(
			$wpdb->prefix . 'isoft_fmf_files',
			array(
				'is_missing'    => 1,
				'missing_since' => '2026-01-02 03:04:05',
				'file_name'     => 'gone.pdf',
			),
			array( 'id' => $file )
		);

		$items = ISOFT_FMF_Attention::items_for( 'missing_files', 1, 20 );

		$this->assertSame( 'gone.pdf', $items[0]['name'] );
		$this->assertGreaterThan( 0, $items[0]['date'] );
		$this->assertSame( 1, ISOFT_FMF_Attention::menu_count() );
	}

	public function test_an_unavailable_file_is_listed_but_not_counted_in_the_menu(): void {
		$file = $this->file( $this->download() );
		isoft_fmf_set_file_unavailable( $file, true );

		$this->assertSame( 1, ISOFT_FMF_Attention::count_for( 'unavailable_files' ) );
		$this->assertSame( (string) $file, ISOFT_FMF_Attention::items_for( 'unavailable_files', 1, 20 )[0]['id'] );
		$this->assertSame( 0, ISOFT_FMF_Attention::menu_count() );
	}

	public function test_dismissing_a_stopped_task_clears_it_here_and_keeps_it_for_support(): void {
		ISOFT_FMF_Task_Budget::report( 'bundle', 'time', 'first' );
		ISOFT_FMF_Task_Budget::report( 'integrity', 'memory', 'second' );

		$items = ISOFT_FMF_Attention::items_for( 'stopped_tasks', 1, 20 );
		$this->assertCount( 2, $items );
		$this->assertSame( 2, ISOFT_FMF_Attention::menu_count() );

		$this->assertTrue( ISOFT_FMF_Attention::run_action( 'stopped_tasks', $items[0]['id'], 'dismiss' ) );

		$left = ISOFT_FMF_Attention::items_for( 'stopped_tasks', 1, 20 );
		$this->assertCount( 1, $left );
		$this->assertSame( 'first', $left[0]['reason'] );
		$this->assertCount( 2, ISOFT_FMF_Task_Budget::recent_stops() );
	}

	public function test_dismissing_an_unknown_stop_fails(): void {
		$this->assertWPError( ISOFT_FMF_Attention::run_action( 'stopped_tasks', 'nope', 'dismiss' ) );
	}

	public function test_a_source_adds_items_with_paging_and_counts(): void {
		$items = array();
		for ( $i = 1; $i <= 45; $i++ ) {
			$items[] = array(
				'id'   => 'held-' . $i,
				'name' => 'held-' . $i . '.pdf',
			);
		}
		$this->assertTrue( isoft_fmf_register_attention_source( 'held', $this->source( $items ) ) );

		$this->assertSame( 45, ISOFT_FMF_Attention::count_for( 'held' ) );
		$this->assertSame( 45, ISOFT_FMF_Attention::menu_count() );
		$this->assertSame( 'held-21', ISOFT_FMF_Attention::items_for( 'held', 2, 20 )[0]['id'] );

		$_GET['source'] = 'held';
		$_GET['paged']  = '3';
		$html           = $this->page();
		unset( $_GET['source'], $_GET['paged'] );

		$this->assertStringContainsString( 'held-45.pdf', $html );
		$this->assertStringNotContainsString( 'held-1.pdf', $html );
	}

	public function test_a_source_that_goes_away_leaves_nothing_behind(): void {
		$sources = $this->registered;
		isoft_fmf_register_attention_source(
			'going',
			$this->source(
				array(
					array(
						'id'   => '1',
						'name' => 'leaving.pdf',
					),
				)
			)
		);
		$this->assertStringContainsString( 'leaving.pdf', $this->page() );

		$this->set_sources( $sources );

		$html = $this->page();
		$this->assertStringNotContainsString( 'leaving.pdf', $html );
		$this->assertStringNotContainsString( 'Held things', $html );
		$this->assertNull( ISOFT_FMF_Attention::count_for( 'going' ) );
	}

	public function test_hostile_text_is_shown_as_text(): void {
		$bad = '<img src=x onerror=alert(1)>.pdf';
		isoft_fmf_register_attention_source(
			'hostile',
			$this->source(
				array(
					array(
						'id'      => '1"><script>x</script>',
						'name'    => $bad,
						'reason'  => '<script>alert(2)</script>',
						'actions' => array(
							array(
								'label' => '<b>Go</b>',
								'url'   => 'javascript:alert(3)',
							),
						),
					),
				)
			)
		);

		$html = $this->page();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>Go', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html );
	}

	public function test_a_source_that_throws_does_not_take_the_screen_down(): void {
		ISOFT_FMF_Task_Budget::report( 'bundle', 'time', 'still shown' );
		$throws = static function (): void {
			throw new RuntimeException( 'boom' );
		};
		isoft_fmf_register_attention_source(
			'broken',
			array(
				'label' => 'Broken source',
				'count' => $throws,
				'items' => $throws,
			)
		);

		$html = $this->page();

		$this->assertStringContainsString( 'This list could not be read', $html );
		$this->assertStringContainsString( 'still shown', $html );
		$this->assertSame( 1, ISOFT_FMF_Attention::menu_count() );
	}

	public function test_malformed_items_are_dropped(): void {
		isoft_fmf_register_attention_source(
			'odd',
			$this->source(
				array(
					'not an array',
					array( 'id' => 'no-name' ),
					array(
						'id'   => 'fine',
						'name' => array( 'not text' ),
					),
					array(
						'id'   => 'good',
						'name' => 'good.pdf',
					),
				)
			)
		);

		$items = ISOFT_FMF_Attention::items_for( 'odd', 1, 20 );

		$this->assertCount( 1, $items );
		$this->assertSame( 'good', $items[0]['id'] );
	}

	public function test_a_source_with_its_own_capability_is_hidden_from_others(): void {
		isoft_fmf_register_attention_source(
			'private_list',
			$this->source(
				array(
					array(
						'id'   => '1',
						'name' => 'secret.pdf',
					),
				),
				array(
					'capability'    => 'a_capability_nobody_has',
					'handle_action' => '__return_true',
				)
			)
		);

		$this->assertStringNotContainsString( 'secret.pdf', $this->page() );
		$this->assertSame( 0, ISOFT_FMF_Attention::menu_count() );
		$this->assertWPError( ISOFT_FMF_Attention::run_action( 'private_list', '1', 'anything' ) );
	}

	public function test_a_named_action_reaches_its_source(): void {
		$seen = array();
		isoft_fmf_register_attention_source(
			'acting',
			$this->source(
				array(
					array(
						'id'      => 'a1',
						'name'    => 'a.pdf',
						'actions' => array(
							array(
								'label'  => 'Release',
								'action' => 'release',
							),
						),
					),
				),
				array(
					'handle_action' => static function ( string $item, string $action ) use ( &$seen ) {
						$seen = array( $item, $action );
						return true;
					},
				)
			)
		);

		$this->assertStringContainsString( 'value="release"', $this->page() );
		$this->assertTrue( ISOFT_FMF_Attention::run_action( 'acting', 'a1', 'release' ) );
		$this->assertSame( array( 'a1', 'release' ), $seen );
	}

	public function test_a_source_can_refuse_an_action_with_a_reason(): void {
		isoft_fmf_register_attention_source(
			'refusing',
			$this->source(
				array(),
				array(
					'handle_action' => static fn() => new WP_Error( 'no', 'Already handled' ),
				)
			)
		);

		$result = ISOFT_FMF_Attention::run_action( 'refusing', 'x', 'y' );

		$this->assertWPError( $result );
		$this->assertSame( 'Already handled', $result->get_error_message() );
	}

	public function test_an_invalid_registration_is_refused(): void {
		$this->setExpectedIncorrectUsage( 'ISOFT_FMF_Attention::register_source' );

		$this->assertFalse( isoft_fmf_register_attention_source( 'Bad Id', $this->source( array() ) ) );
		$this->assertFalse( isoft_fmf_register_attention_source( 'no_callbacks', array( 'label' => 'x' ) ) );
		$this->assertFalse( isoft_fmf_register_attention_source( 'missing_files', $this->source( array() ) ) );
	}

	public function test_only_people_who_manage_settings_see_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		$this->page();
	}
}
