<?php
/**
 * Settings page — React app for General / Display / Security / Advanced,
 * PHP for Maintenance / Extensions.
 *
 * The React app in `blocks/settings-page/` owns the four schema-driven
 * tabs via [[ISOFT_FMF_Rest_Settings]]. Maintenance and Extensions stay
 * PHP because they aren't option forms — Maintenance triggers admin-post
 * actions (demo content, bundle cache, integrity, log purge, exports)
 * and Extensions is a static marketing surface.
 *
 * The pre-React PHP fallback forms for the four schema tabs were removed
 * in 0.12.5 demolition.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'I-Soft File Manager: Foundation Settings', 'isoft-fm-foundation' ); ?></h1>

	<?php settings_errors( 'isoft_fmf_settings' ); ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cosmetic post-action banner; the action itself is nonce-protected.
	if ( isset( $_GET['isoft_fmf_cache_cleared'] ) ) :
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of the post-action count.
		$isoft_fmf_cleared = (int) $_GET['isoft_fmf_cache_cleared'];
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				if ( $isoft_fmf_cleared > 0 ) {
					printf(
						/* translators: %d: number of cache files deleted */
						esc_html( _n( 'Bundle cache cleared. %d file removed.', 'Bundle cache cleared. %d files removed.', $isoft_fmf_cleared, 'isoft-fm-foundation' ) ),
						(int) $isoft_fmf_cleared
					);
				} else {
					esc_html_e( 'Bundle cache was already empty.', 'isoft-fm-foundation' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector; nonce belongs on form submit, not nav.
	$isoft_fmf_active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
	$isoft_fmf_tabs       = array(
		'general'     => __( 'General', 'isoft-fm-foundation' ),
		'display'     => __( 'Display', 'isoft-fm-foundation' ),
		'security'    => __( 'Security', 'isoft-fm-foundation' ),
		'advanced'    => __( 'Advanced', 'isoft-fm-foundation' ),
		'maintenance' => __( 'Maintenance', 'isoft-fm-foundation' ),
		'extensions'  => __( 'Extensions', 'isoft-fm-foundation' ),
	);

	$isoft_fmf_react_tabs = array( 'general', 'display', 'security', 'advanced' );
	$isoft_fmf_is_react   = in_array( $isoft_fmf_active_tab, $isoft_fmf_react_tabs, true );

	// PHP-fallback nav: only render when React isn't taking over the
	// page. When React is mounting, the React TabPanel owns the tab
	// strip — duplicate nav would just be confusing chrome.
	if ( ! $isoft_fmf_is_react ) :
		?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $isoft_fmf_tabs as $isoft_fmf_tab => $isoft_fmf_label ) : ?>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'page'      => 'isoft-fmf-settings',
							'post_type' => 'isoft_fmf_file',
							'tab'       => $isoft_fmf_tab,
						),
						admin_url( 'edit.php' )
					)
				);
				?>
							"
					class="nav-tab <?php echo $isoft_fmf_active_tab === $isoft_fmf_tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $isoft_fmf_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	endif;

	if ( $isoft_fmf_is_react ) :
		// Build the URL map the React side needs to do full-page
		// navigation for the PHP-rendered tabs (Maintenance, Extensions).
		// The 4 React tabs map to the same admin URL so reload-clicks on
		// them work too, even though React normally handles them
		// in-place.
		$isoft_fmf_tab_urls = array();
		foreach ( $isoft_fmf_tabs as $isoft_fmf_tab => $isoft_fmf_label ) {
			$isoft_fmf_tab_urls[ $isoft_fmf_tab ] = add_query_arg(
				array(
					'page'      => 'isoft-fmf-settings',
					'post_type' => 'isoft_fmf_file',
					'tab'       => $isoft_fmf_tab,
				),
				admin_url( 'edit.php' )
			);
		}
		?>
		<div
			id="isoft-fmf-settings-root"
			data-tab="<?php echo esc_attr( $isoft_fmf_active_tab ); ?>"
			data-php-tab-urls="
			<?php
			echo esc_attr(
				wp_json_encode(
					array(
						'maintenance' => $isoft_fmf_tab_urls['maintenance'],
						'extensions'  => $isoft_fmf_tab_urls['extensions'],
					)
				)
			);
			?>
								"
		></div>
		<noscript>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'The Settings page requires JavaScript. Please enable JavaScript in your browser.', 'isoft-fm-foundation' ); ?></p>
			</div>
		</noscript>

		<p class="description" style="margin-top: 24px; max-width: 720px;">
			<strong><?php esc_html_e( 'Storage Location:', 'isoft-fm-foundation' ); ?></strong>
			<code><?php echo esc_html( isoft_fmf_files_dir() ); ?></code><br>
			<?php esc_html_e( 'All files are stored here, organised by category. The folder is protected by .htaccess and served through the plugin\'s secure download handler.', 'isoft-fm-foundation' ); ?>
		</p>
		<?php
	elseif ( 'maintenance' === $isoft_fmf_active_tab ) :
		require __DIR__ . '/maintenance-tab.php';
	elseif ( 'extensions' === $isoft_fmf_active_tab ) :
		require __DIR__ . '/extensions-tab.php';
	endif;
	?>
</div>
