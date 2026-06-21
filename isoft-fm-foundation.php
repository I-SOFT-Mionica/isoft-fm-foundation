<?php
/**
 * Plugin Name: I-Soft File Manager: Foundation
 * Plugin URI:  https://github.com/I-SOFT-Mionica/isoft-fm-foundation
 * Description: Hierarchical file download manager — categories, multi-file entries, secure download handler, audit logging, and role-based access control.
 * Version:     0.11.0
 * Author:      I-SOFT Mionica
 * Author URI:  https://github.com/I-SOFT-Mionica
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: isoft-fm-foundation
 * Domain Path: /languages
 * Requires at least: 6.7
 * Requires PHP:      8.4
 */

defined( 'ABSPATH' ) || exit;

const ISOFT_FMF_VERSION = '0.11.0';
define( 'ISOFT_FMF_PLUGIN_FILE', __FILE__ );
define( 'ISOFT_FMF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ISOFT_FMF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ISOFT_FMF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Global helper functions (always available, no autoload magic needed)
require_once ISOFT_FMF_PLUGIN_DIR . 'includes/functions.php';

// Class autoloader: ISOFT_FMF_Post_Type → includes/class-post-type.php
spl_autoload_register(
	function ( string $class ): void {
		if ( ! str_starts_with( $class, 'ISOFT_FMF_' ) ) {
				return;
		}
		$name = strtolower( str_replace( array( 'ISOFT_FMF_', '_' ), array( '', '-' ), $class ) );
		$path = ISOFT_FMF_PLUGIN_DIR . 'includes/class-' . $name . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

// Activation / deactivation
register_activation_hook( __FILE__, array( 'ISOFT_FMF_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ISOFT_FMF_Deactivator', 'deactivate' ) );

// Ensure custom capabilities are always registered (guards against activation timing issues).
add_action( 'init', array( 'ISOFT_FMF_Activator', 'maybe_register_capabilities' ) );

// Surface install failures (typically the DB user lacks CREATE) — without this
// the AJAX endpoints would fail with vague "Could not save file record"
// messages forever and the admin would have no clue why.
add_action( 'admin_notices', array( 'ISOFT_FMF_Activator', 'render_install_error_notice' ) );

// If the stored version differs from the current version, queue a rewrite flush and
// run dbDelta so new table columns appear without manual deactivate/reactivate.
add_action(
	'plugins_loaded',
	function (): void {
		if ( get_option( 'isoft_fmf_db_version' ) !== ISOFT_FMF_VERSION ) {
			ISOFT_FMF_Activator::activate();
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
		( new ISOFT_FMF_Post_Type() )->register_hooks();
		( new ISOFT_FMF_Taxonomy() )->register_hooks();
		( new ISOFT_FMF_Meta_Fields() )->register_hooks();

		// Extension API — fires isoft_fmf_extensions_init so Sentinel/Orbit can register
		( new ISOFT_FMF_Extension_Api() )->register_hooks();

		// Download routing (frontend)
		( new ISOFT_FMF_Download_Handler() )->register_hooks();
		( new ISOFT_FMF_Bundle_Handler() )->register_hooks();

		// Access control — query-level RBAC filtering on frontend.
		( new ISOFT_FMF_Access_Control() )->register_hooks();
		( new ISOFT_FMF_License_Resolver() )->register_hooks();
		( new ISOFT_FMF_Featured() )->register_hooks();

		// File integrity — serve-time detection + daily cron.
		( new ISOFT_FMF_File_Integrity() )->register_hooks();

		// Template hierarchy — load plugin templates for CPT/taxonomies (classic themes only).
		// Single downloads are handled via the_content filter in ISOFT_FMF_Post_Type for all themes.
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
				if ( is_post_type_archive( 'isoft_fmf_file' ) ) {
					$candidates[] = 'archive-isoft_fmf_file.php';
				}
				if ( is_tax( 'isoft_fmf_category' ) ) {
					$candidates[] = 'taxonomy-isoft_fmf_category.php';
				}
				if ( is_tax( 'isoft_fmf_tag' ) ) {
					$candidates[] = 'taxonomy-isoft_fmf_tag.php';
				}

				foreach ( $candidates as $file ) {
					$theme_override = locate_template( "isoft-fm-foundation/{$file}" );
					if ( $theme_override ) {
						return $theme_override;
					}
					$plugin_template = ISOFT_FMF_PLUGIN_DIR . 'templates/' . $file;
					if ( file_exists( $plugin_template ) ) {
						return $plugin_template;
					}
				}

				return $template;
			}
		);

		// Category folder lifecycle (create / rename / warn on delete)
		( new ISOFT_FMF_Category_Folders() )->register_hooks();

		// Per-user write-side category ACL.
		( new ISOFT_FMF_Category_ACL() )->register_hooks();

		// Shortcodes (registered on all requests for REST/preview compatibility)
		( new ISOFT_FMF_Shortcodes() )->register_hooks();

		// REST API (needed outside admin too)
		( new ISOFT_FMF_Rest_Api() )->register_hooks();

		// Gutenberg blocks
		( new ISOFT_FMF_Blocks() )->register_hooks();

		// CSV / JSON export + log purge (admin-post.php actions)
		( new ISOFT_FMF_Export() )->register_hooks();

		// Scheduled tasks (HOT recalculation, log purge)
		( new ISOFT_FMF_Cron() )->register_hooks();

		if ( is_admin() ) {
			( new ISOFT_FMF_Admin_Meta_Boxes() )->register_hooks();
			( new ISOFT_FMF_Admin_Columns() )->register_hooks();
			( new ISOFT_FMF_Settings() )->register_hooks();
			( new ISOFT_FMF_Broken_Links_Ajax() )->register_hooks();
			( new ISOFT_FMF_License_Manager() )->register_hooks();
			( new ISOFT_FMF_Pdf_Thumbnail() )->register_hooks();
			( new ISOFT_FMF_Tinymce() )->register_hooks();
			( new ISOFT_FMF_Demo_Content() )->register_hooks();
		}
	}
);
