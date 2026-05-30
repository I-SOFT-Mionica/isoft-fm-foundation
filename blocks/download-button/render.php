<?php
/**
 * Server-side render for isoft-fm-foundation/download-button block.
 *
 * Renders the full download card layout for the selected download,
 * identical to the download list view.
 */
defined( 'ABSPATH' ) || exit;

$download_id = absint( $attributes['downloadId'] ?? 0 );

if ( ! $download_id ) {
	echo '<p class="isfm-block-placeholder">' . esc_html__( 'Select a download in the block settings.', 'isoft-fm-foundation' ) . '</p>';
	return;
}

$post = get_post( $download_id );
if ( ! $post || $post->post_type !== 'isfm_file' || $post->post_status !== 'publish' ) {
	echo '<p class="isfm-block-placeholder">' . esc_html__( 'Download not found.', 'isoft-fm-foundation' ) . '</p>';
	return;
}

$settings = isfm_get_settings();

ob_start();
require ISFM_PLUGIN_DIR . 'public/views/download-card.php';
echo wp_kses( ob_get_clean(), isfm_allowed_html() );
