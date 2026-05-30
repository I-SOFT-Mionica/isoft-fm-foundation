<?php
/**
 * Plugin Name: I-Soft File Manager: Foundation
 * Plugin URI:  https://isoft.rs/isoft-fm-foundation
 * Description: Hierarchical file download manager — categories, multi-file entries, secure download handler, audit logging, and role-based access control.
 * Version:     0.8.2
 * Author:      I-SOFT Mionica
 * Author URI:  https://isoft.rs
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: isoft-fm-foundation
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP:      8.4
 */

defined( 'ABSPATH' ) || exit;

const ISFM_VERSION = '0.8.2';
define( 'ISFM_PLUGIN_FILE', __FILE__ );
define( 'ISFM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ISFM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ISFM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Global helper functions (always available, no autoload magic needed)
require_once ISFM_PLUGIN_DIR . 'includes/functions.php';

// Class autoloader: ISFM_Post_Type → includes/class-post-type.php
spl_autoload_register(
	function ( string $class ): void {
		if ( ! str_starts_with( $class, 'ISFM_' ) ) {
				return;
		}
		$name = strtolower( str_replace( array( 'ISFM_', '_' ), array( '', '-' ), $class ) );
		$path = ISFM_PLUGIN_DIR . 'includes/class-' . $name . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

// Activation / deactivation
register_activation_hook( __FILE__, array( 'ISFM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ISFM_Deactivator', 'deactivate' ) );

// Ensure custom capabilities are always registered (guards against activation timing issues).
add_action( 'init', array( 'ISFM_Activator', 'maybe_register_capabilities' ) );

// If the stored version differs from the current version, queue a rewrite flush and
// run dbDelta so new table columns appear without manual deactivate/reactivate.
add_action(
	'plugins_loaded',
	function (): void {
		if ( get_option( 'isfm_db_version' ) !== ISFM_VERSION ) {
			ISFM_Activator::activate();
		}
	},
	1
);

/**
 * Bootstrap after all plugins are loaded so extensions can hook in.
 */
add_action(
	'plugins_loaded',
	function (): void {
		// Translations for wp.org-hosted plugins are auto-loaded by WordPress since 4.6.

		// Core registrations
		( new ISFM_Post_Type() )->register_hooks();
		( new ISFM_Taxonomy() )->register_hooks();
		( new ISFM_Meta_Fields() )->register_hooks();

		// Extension API — fires isfm_extensions_init so Sentinel/Orbit can register
		( new ISFM_Extension_Api() )->register_hooks();

		// Download routing (frontend)
		( new ISFM_Download_Handler() )->register_hooks();

		// Access control — query-level RBAC filtering on frontend.
		( new ISFM_Access_Control() )->register_hooks();

		// File integrity — serve-time detection + daily cron.
		( new ISFM_File_Integrity() )->register_hooks();

		// Template hierarchy — load plugin templates for CPT/taxonomies (classic themes only).
		// Single downloads are handled via the_content filter in ISFM_Post_Type for all themes.
		// Archive/taxonomy pages still need PHP templates for classic themes.
		add_filter(
			'template_include',
			function ( string $template ): string {
				// FSE block themes handle everything via block templates + the_content filter.
				if ( wp_is_block_theme() ) {
					return $template;
				}

				// Classic theme fallbacks — single download uses the_content filter, no custom template needed.
				$candidates = array();
				if ( is_post_type_archive( 'isfm_file' ) ) {
					$candidates[] = 'archive-isfm_file.php';
				}
				if ( is_tax( 'isfm_category' ) ) {
					$candidates[] = 'taxonomy-isfm_category.php';
				}
				if ( is_tax( 'isfm_tag' ) ) {
					$candidates[] = 'taxonomy-isfm_tag.php';
				}

				foreach ( $candidates as $file ) {
					$theme_override = locate_template( "isoft-fm-foundation/{$file}" );
					if ( $theme_override ) {
						return $theme_override;
					}
					$plugin_template = ISFM_PLUGIN_DIR . 'templates/' . $file;
					if ( file_exists( $plugin_template ) ) {
						return $plugin_template;
					}
				}

				return $template;
			}
		);

		// Category folder lifecycle (create / rename / warn on delete)
		( new ISFM_Category_Folders() )->register_hooks();

		// Per-user write-side category ACL.
		( new ISFM_Category_ACL() )->register_hooks();

		// Shortcodes (registered on all requests for REST/preview compatibility)
		( new ISFM_Shortcodes() )->register_hooks();

		// REST API (needed outside admin too)
		( new ISFM_Rest_Api() )->register_hooks();

		// Gutenberg blocks
		( new ISFM_Blocks() )->register_hooks();

		// CSV / JSON export + log purge (admin-post.php actions)
		( new ISFM_Export() )->register_hooks();

		// Scheduled tasks (HOT recalculation, log purge)
		( new ISFM_Cron() )->register_hooks();

		if ( is_admin() ) {
			( new ISFM_Admin_Meta_Boxes() )->register_hooks();
			( new ISFM_Admin_Columns() )->register_hooks();
			( new ISFM_Settings() )->register_hooks();
			( new ISFM_Broken_Links_Ajax() )->register_hooks();
			( new ISFM_License_Manager() )->register_hooks();
			( new ISFM_Pdf_Thumbnail() )->register_hooks();
			( new ISFM_Tinymce() )->register_hooks();
			( new ISFM_Demo_Content() )->register_hooks();
		}
	}
);
