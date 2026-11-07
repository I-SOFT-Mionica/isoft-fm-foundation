<?php
defined( 'ABSPATH' ) || exit;

/**
 * First-page thumbnails for uploaded PDFs.
 *
 * Rendering a PDF can cost far more than its size suggests (invisible
 * nested tables in an accounting export can take gigabytes to draw), so
 * it never runs inside the upload request: the upload schedules a one-off
 * cron job, and the job renders under Imagick resource limits. A thumbnail
 * that cannot be made is skipped and reported; the upload is unaffected.
 */
class ISOFT_FMF_Pdf_Thumbnail {

	public const CRON_HOOK = 'isoft_fmf_pdf_thumbnail';

	/** Renders in progress: token => file_id, download_id, name, started. */
	public const RUNNING_OPTION = 'isoft_fmf_pdf_thumbnail_running';

	/** Seconds after which a render that never finished counts as ended by the server. */
	public const STALE_AFTER = 600;

	/** @var array<string, true> Renders this request started and has not finished. */
	private array $running = array();

	public function register_hooks(): void {
		add_action( 'isoft_fmf_file_uploaded', array( $this, 'maybe_generate' ), 10, 2 );
		add_action( self::CRON_HOOK, array( $this, 'generate' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_init', array( $this, 'report_unfinished' ) );
		add_action(
			'isoft_fmf_deactivate',
			static function (): void {
				wp_unschedule_hook( self::CRON_HOOK );
			}
		);
	}

	/**
	 * On upload: schedule the thumbnail when this file should get one.
	 */
	public function maybe_generate( int $file_id, int $download_id ): void {
		if ( ! $this->wanted( $file_id, $download_id ) ) {
			return;
		}

		/**
		 * Filters whether the thumbnail renders in a background cron job
		 * (default) or inside the upload request. Return false only on
		 * sites where WP-Cron never runs.
		 *
		 * @param bool $async Render in the background.
		 */
		if ( ! apply_filters( 'isoft_fmf_pdf_thumbnail_async', true ) ) {
			$this->generate( $file_id, $download_id );
			return;
		}

		$args = array( $file_id, $download_id );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, $args );
		}
	}

	/**
	 * Render and attach the thumbnail. Any error is contained and reported.
	 */
	public function generate( int $file_id, int $download_id ): void {
		$this->report_unfinished();
		ISOFT_FMF_Task_Budget::guard(
			'pdf_thumbnail',
			function () use ( $file_id, $download_id ): void {
				$this->generate_now( $file_id, $download_id );
			}
		);
	}

	/**
	 * Resource limits for one render.
	 *
	 * @return array<string, int> memory, map, disk and max_file_bytes in bytes; time in seconds; width and height in pixels.
	 */
	public static function limits(): array {
		/**
		 * Filters the resource limits for rendering a PDF thumbnail.
		 *
		 * Keys: memory, map, disk (Imagick resource limits, bytes), time (seconds),
		 * width and height (largest image Imagick will create, pixels), and
		 * max_file_bytes (larger PDFs are not rendered at all).
		 *
		 * @param array<string, int> $limits Limits.
		 */
		$limits = (array) apply_filters(
			'isoft_fmf_pdf_thumbnail_limits',
			array(
				'memory'         => 256 * MB_IN_BYTES,
				'map'            => 512 * MB_IN_BYTES,
				'disk'           => 1024 * MB_IN_BYTES,
				'time'           => 30,
				'width'          => 10000,
				'height'         => 10000,
				'max_file_bytes' => 200 * MB_IN_BYTES,
			)
		);
		return array_map( 'intval', $limits );
	}

	/**
	 * Cheap checks shared by scheduling and rendering.
	 */
	private function wanted( int $file_id, int $download_id ): bool {
		$settings = isoft_fmf_get_settings();
		if ( ! $settings['enable_pdf_thumbnails'] ) {
			return false;
		}

		$file = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
		if ( ! $file || 'application/pdf' !== $file->file_mime || empty( $file->file_path ) ) {
			return false;
		}
		if ( has_post_thumbnail( $download_id ) && ! $settings['overwrite_pdf_thumbnail'] ) {
			return false;
		}
		return (bool) apply_filters( 'isoft_fmf_pdf_thumbnail_backend', $this->detect_backend() );
	}

	private function generate_now( int $file_id, int $download_id ): void {
		if ( ! $this->wanted( $file_id, $download_id ) ) {
			return;
		}

		$settings = isoft_fmf_get_settings();
		$file     = ( new ISOFT_FMF_File_Manager() )->get_file( $file_id );
		$pdf_path = isoft_fmf_files_dir() . '/' . $file->file_path;
		if ( ! is_file( $pdf_path ) ) {
			return;
		}

		$limits = self::limits();
		$size   = (int) filesize( $pdf_path );
		if ( $limits['max_file_bytes'] > 0 && $size > $limits['max_file_bytes'] ) {
			ISOFT_FMF_Task_Budget::report(
				'pdf_thumbnail',
				'too_large',
				sprintf(
					/* translators: 1: file name, 2: file size, 3: size limit */
					__( '%1$s is %2$s; thumbnails are made for PDFs up to %3$s.', 'isoft-fm-foundation' ),
					$file->file_name,
					size_format( $size ),
					size_format( $limits['max_file_bytes'] )
				)
			);
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

		// Let ImageMagick give up before PHP would cut the request off, so a
		// slow render ends in the reported error below, not a fatal error.
		$budget = new ISOFT_FMF_Task_Budget( 'pdf_thumbnail' );
		if ( $limits['time'] > 0 ) {
			$limits['time'] = min( $limits['time'], max( 5, (int) floor( $budget->remaining() ) ) );
		}

		// A render the server kills (time or memory limit, a worker timeout)
		// cannot be caught. Mark it running: the shutdown handler, or a later
		// request finding the mark left behind, reports it.
		$token = $this->mark_running( $file_id, $download_id, (string) $file->file_name );
		register_shutdown_function( array( $this, 'on_shutdown' ), $token );
		$thumb_path = $this->render( $pdf_path, $args, $limits );
		$this->clear_running( $token );

		if ( ! $thumb_path ) {
			ISOFT_FMF_Task_Budget::report(
				'pdf_thumbnail',
				'error',
				sprintf(
					/* translators: %s: file name */
					__( 'Could not render the first page of %s. The file is unchanged and can still be downloaded.', 'isoft-fm-foundation' ),
					$file->file_name
				)
			);
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

	/**
	 * @param array<string, int> $args   width, height, quality.
	 * @param array<string, int> $limits See limits().
	 */
	private function render( string $pdf_path, array $args, array $limits ): ?string {
		$out = sys_get_temp_dir() . '/isoft_fmf_thumb_' . md5( $pdf_path . microtime() ) . '.jpg';

		// Imagick limits are process-wide: set them for this render only and
		// restore the previous values, so other image work is unaffected.
		$types = array();
		foreach ( array( 'memory', 'map', 'disk', 'time', 'width', 'height' ) as $key ) {
			$const = 'Imagick::RESOURCETYPE_' . strtoupper( $key );
			if ( defined( $const ) && ! empty( $limits[ $key ] ) ) {
				$types[ $key ] = constant( $const );
			}
		}
		$previous = array();
		foreach ( $types as $key => $type ) {
			$previous[ $type ] = \Imagick::getResourceLimit( $type );
			\Imagick::setResourceLimit( $type, $limits[ $key ] );
		}

		$im = null;
		try {
			$im = new \Imagick();
			$im->setResolution( 150, 150 );
			$im->readImage( $pdf_path . '[0]' );
			// Most PDFs do not paint a page background, and JPEG has no alpha:
			// without flattening onto white, the empty page turns black.
			$im->setImageBackgroundColor( 'white' );
			if ( defined( 'Imagick::ALPHACHANNEL_REMOVE' ) ) {
				$im->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
			} else {
				$flat = $im->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
				$im->clear();
				$im = $flat;
			}
			$im->setImageFormat( 'jpeg' );
			$im->setImageCompressionQuality( $args['quality'] );
			$im->thumbnailImage( $args['width'], $args['height'], true );
			$im->writeImage( $out );
		} catch ( \Throwable $e ) {
			if ( file_exists( $out ) ) {
				wp_delete_file( $out );
			}
			return null;
		} finally {
			if ( $im ) {
				$im->clear();
			}
			foreach ( $previous as $type => $value ) {
				\Imagick::setResourceLimit( $type, (int) $value );
			}
		}

		return file_exists( $out ) ? $out : null;
	}

	/**
	 * Record a render as running and return its token.
	 */
	private function mark_running( int $file_id, int $download_id, string $name ): string {
		$token                   = $file_id . ':' . $download_id . ':' . wp_generate_password( 6, false );
		$this->running[ $token ] = true;
		$markers                 = self::running_markers();
		$markers[ $token ]       = array(
			'file_id'     => $file_id,
			'download_id' => $download_id,
			'name'        => $name,
			'started'     => time(),
		);
		// Autoloaded and kept (empty) when idle, so the check on every admin
		// page costs no query.
		update_option( self::RUNNING_OPTION, $markers, true );
		return $token;
	}

	private function clear_running( string $token ): void {
		unset( $this->running[ $token ] );
		$markers = self::running_markers();
		if ( isset( $markers[ $token ] ) ) {
			unset( $markers[ $token ] );
			update_option( self::RUNNING_OPTION, $markers, true );
		}
	}

	/**
	 * @return array<string, array{file_id: int, download_id: int, name: string, started: int}>
	 */
	private static function running_markers(): array {
		$markers = get_option( self::RUNNING_OPTION, array() );
		return is_array( $markers ) ? $markers : array();
	}

	/**
	 * Shutdown handler: a render this request started and never finished
	 * was ended by a fatal error (time or memory limit). Report it.
	 */
	public function on_shutdown( string $token ): void {
		if ( isset( $this->running[ $token ] ) ) {
			$this->report_unfinished_render( $token, error_get_last() );
		}
	}

	/**
	 * Report one render that did not finish, and forget its mark.
	 *
	 * @param string                    $token Render token.
	 * @param array<string, mixed>|null $error error_get_last() at the time, if any.
	 */
	public function report_unfinished_render( string $token, ?array $error ): void {
		unset( $this->running[ $token ] );
		$markers = self::running_markers();
		if ( ! isset( $markers[ $token ] ) ) {
			return;
		}
		$marker = $markers[ $token ];
		unset( $markers[ $token ] );
		update_option( self::RUNNING_OPTION, $markers, true );

		$message = (string) ( $error['message'] ?? '' );
		$reason  = 'killed';
		if ( false !== stripos( $message, 'Maximum execution time' ) ) {
			$reason = 'time';
		} elseif ( false !== stripos( $message, 'Allowed memory size' ) || false !== stripos( $message, 'Out of memory' ) ) {
			$reason = 'memory';
		}

		ISOFT_FMF_Task_Budget::report(
			'pdf_thumbnail',
			$reason,
			sprintf(
				/* translators: %s: file name */
				__( '%s may be too complex to render on this server. No thumbnail was made; the file is unchanged and can still be downloaded.', 'isoft-fm-foundation' ),
				$marker['name']
			)
		);
	}

	/**
	 * Report renders whose request ended without finishing them or saying
	 * why (the process was killed). Runs on admin pages and before a render.
	 */
	public function report_unfinished(): void {
		$markers = self::running_markers();
		foreach ( $markers as $token => $marker ) {
			if ( isset( $this->running[ $token ] ) ) {
				continue;
			}
			if ( time() - (int) ( $marker['started'] ?? 0 ) >= self::STALE_AFTER ) {
				$this->report_unfinished_render( (string) $token, null );
			}
		}
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
