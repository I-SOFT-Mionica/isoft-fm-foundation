<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Blocks {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'register_block_template' ) );
		add_filter( 'block_categories_all', array( $this, 'register_category' ), 10, 2 );
	}

	public function register_category( array $categories ): array {
		// Prepend so it appears at the top of the inserter.
		array_unshift(
			$categories,
			array(
				'slug'  => 'isoft-fm-foundation',
				'title' => 'I-Soft File Manager: Foundation',
				'icon'  => 'download',
			)
		);
		return $categories;
	}

	public function register(): void {
		foreach ( array( 'download-list', 'download-button', 'category-grid' ) as $block ) {
			$result = register_block_type( ISOFT_FMF_PLUGIN_DIR . 'blocks/' . $block );

			// Make @wordpress/i18n __() calls in the block's editor script
			// consume translations from our /languages/ directory. WP serves
			// these as .json files generated from the .po set by wp-cli:
			// wp i18n make-json languages/
			if ( $result && ! empty( $result->editor_script_handles ) ) {
				foreach ( $result->editor_script_handles as $handle ) {
					wp_set_script_translations(
						$handle,
						'isoft-fm-foundation',
						ISOFT_FMF_PLUGIN_DIR . 'languages'
					);
				}
			}
		}
	}

	/**
	 * Register a block template for single downloads so FSE themes wrap it correctly.
	 * Uses register_block_template() (WP 6.7+) when available; falls back to
	 * the_content filter injection which works with any theme.
	 */
	public function register_block_template(): void {
		if ( function_exists( 'register_block_template' ) ) {
			register_block_template(
				'isoft-fm-foundation//single-isoft_fmf_file',
				array(
					'title'       => __( 'Single Download', 'isoft-fm-foundation' ),
					'description' => __( 'Template for individual download entries.', 'isoft-fm-foundation' ),
					'content'     =>
						'<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' .
						'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->' .
						'<main class="wp-block-group">' .
						'<!-- wp:post-title {"level":1} /-->' .
						'<!-- wp:post-content {"layout":{"type":"constrained"}} /-->' .
						'</main>' .
						'<!-- /wp:group -->' .
						'<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
				)
			);
		}
	}
}
