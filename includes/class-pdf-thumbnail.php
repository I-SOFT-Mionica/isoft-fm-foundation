<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Pdf_Thumbnail {

	public function register_hooks(): void {
		add_action( 'isoft_fmf_file_uploaded', array( $this, 'maybe_generate' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
	}

	public function maybe_generate( int $file_id, int $download_id ): void {
		$settings = isoft_fmf_get_settings();
		if ( ! $settings['enable_pdf_thumbnails'] ) {
			return;
		}

		$file = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
		if ( ! $file || 'application/pdf' !== $file->file_mime || empty( $file->file_path ) ) {
			return;
		}
		if ( has_post_thumbnail( $download_id ) && ! $settings['overwrite_pdf_thumbnail'] ) {
			return;
		}

		$pdf_path = isoft_fmf_files_dir() . '/' . $file->file_path;
		if ( ! file_exists( $pdf_path ) ) {
			return;
		}

		$backend = apply_filters( 'isoft_fmf_pdf_thumbnail_backend', $this->detect_backend() );
		if ( ! $backend ) {
			return;
		}

		$args = apply_filters(
			'isoft_fmf_pdf_thumbnail_args',
			array(
				'width'   => $settings['pdf_thumb_width'],
				'height'  => $settings['pdf_thumb_height'],
				'quality' => $settings['pdf_thumb_quality'],
			)
		);

		$thumb_path = $this->render( $pdf_path, $args );
		if ( ! $thumb_path ) {
			return;
		}

		$attachment_id = $this->save_as_attachment( $thumb_path, $download_id );
		if ( $attachment_id ) {
			set_post_thumbnail( $download_id, $attachment_id );
			do_action( 'isoft_fmf_pdf_thumbnail_generated', $download_id, $file_id, $attachment_id );
		}
	}

	public function detect_backend(): ?string {
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			return 'imagick';
		}
		return null;
	}

	private function render( string $pdf_path, array $args ): ?string {
		$out = sys_get_temp_dir() . '/isoft_fmf_thumb_' . md5( $pdf_path . microtime() ) . '.jpg';

		try {
			$im = new \Imagick();
			$im->setResolution( 150, 150 );
			$im->readImage( $pdf_path . '[0]' );
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( $args['quality'] );
			$im->thumbnailImage( $args['width'], $args['height'], true );
			$im->writeImage( $out );
			$im->clear();
		} catch ( Exception $e ) {
			return null;
		}

		return file_exists( $out ) ? $out : null;
	}

	private function save_as_attachment( string $image_path, int $parent_id ): int|false {
		// Required by wp_upload_bits() (file.php) and wp_generate_attachment_metadata() (image.php) called immediately below.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a thumbnail we just wrote to sys_get_temp_dir(); WP_Filesystem cannot read from outside ABSPATH.
		$contents = file_get_contents( $image_path );
		if ( false === $contents ) {
			return false;
		}

		$upload = wp_upload_bits( basename( $image_path ), null, $contents );
		wp_delete_file( $image_path );

		if ( $upload['error'] ) {
			return false;
		}

		$att_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => get_the_title( $parent_id ) . ' — PDF Thumbnail',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$parent_id
		);

		if ( is_wp_error( $att_id ) ) {
			return false;
		}

		wp_generate_attachment_metadata( $att_id, $upload['file'] );
		return $att_id;
	}

	public function maybe_show_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'isoft_fmf_pdf_notice_dismissed' ) ) {
			return;
		}
		// Only nag when the feature is actually requested. If the admin has
		// PDF thumbnails turned off in settings, the lack of Imagick is
		// irrelevant to them — surfacing the warning anyway is just noise.
		$settings = isoft_fmf_get_settings();
		if ( empty( $settings['enable_pdf_thumbnails'] ) ) {
			return;
		}
		if ( ! $this->detect_backend() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'I-Soft File Manager: Foundation: PDF thumbnail generation is disabled — the Imagick PHP extension is not available on this server.', 'isoft-fm-foundation' );
			echo '</p></div>';
		}
	}
}
