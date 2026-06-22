<?php
/**
 * AJAX handlers for the Broken Links recovery dialog.
 *
 * Actions exposed to the browser (each nonce-protected with 'isoft_fmf_broken_links'):
 *   isoft_fmf_recover_probe       — look up file + cross-category inode hunt, return dialog data.
 *   isoft_fmf_recover_move_back   — move a candidate file back to the expected path.
 *   isoft_fmf_recover_reassign    — reassign the download (and all its files) to the new category.
 *   isoft_fmf_recover_split       — detach this file into a new draft download in its new category.
 *   isoft_fmf_recover_reupload    — accept an uploaded replacement and update the DB row.
 *   isoft_fmf_recover_detach      — drop the isoft_fmf_files row (physical file untouched).
 *
 * All business logic now lives in [[ISOFT_FMF_Broken_Links_Service]] —
 * this class is a thin wire-format adapter (nonce + permission check,
 * service call, send_json_* with the service's WP_Error or success
 * payload). The browser shape is unchanged so admin/js/broken-links.js
 * keeps working until 0.12.3 (when the Broken Links page becomes a
 * React app).
 */
defined( 'ABSPATH' ) || exit;

// Every public handle_* method calls $this->guard() as its first statement,
// which runs check_ajax_referer( 'isoft_fmf_broken_links', 'nonce' ) — so
// every $_POST/$_FILES read below is nonce-verified at the top of the
// method. The sniff can't follow the indirection.
// phpcs:disable WordPress.Security.NonceVerification.Missing
class ISOFT_FMF_Broken_Links_Ajax {

	public function register_hooks(): void {
		$actions = array(
			'probe',
			'move_back',
			'reassign',
			'split',
			'reupload',
			'detach',
		);
		foreach ( $actions as $action ) {
			add_action( "wp_ajax_isoft_fmf_recover_{$action}", array( $this, "handle_{$action}" ) );
		}
	}

	private function guard(): void {
		if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'isoft-fm-foundation' ) ), 403 );
		}
		check_ajax_referer( 'isoft_fmf_broken_links', 'nonce' );
	}

	private function file_id_from_post(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by guard() above.
		return isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
	}

	private function send( $result ): void {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}
		wp_send_json_success( $result );
	}

	public function handle_probe(): void {
		$this->guard();
		$this->send( ( new ISOFT_FMF_Broken_Links_Service() )->probe( $this->file_id_from_post() ) );
	}

	public function handle_move_back(): void {
		$this->guard();
		$this->send( ( new ISOFT_FMF_Broken_Links_Service() )->move_back( $this->file_id_from_post() ) );
	}

	public function handle_reassign(): void {
		$this->guard();
		$this->send( ( new ISOFT_FMF_Broken_Links_Service() )->reassign( $this->file_id_from_post() ) );
	}

	public function handle_split(): void {
		$this->guard();
		$this->send( ( new ISOFT_FMF_Broken_Links_Service() )->split( $this->file_id_from_post() ) );
	}

	public function handle_reupload(): void {
		$this->guard();
		$file_id = $this->file_id_from_post();
		if ( empty( $_FILES['replacement'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'isoft-fm-foundation' ) ), 400 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw $_FILES entry forwarded to service; wp_handle_upload validates inside.
		$this->send( ( new ISOFT_FMF_Broken_Links_Service() )->reupload( $file_id, $_FILES['replacement'] ) );
	}

	public function handle_detach(): void {
		$this->guard();
		$this->send( ( new ISOFT_FMF_Broken_Links_Service() )->detach( $this->file_id_from_post() ) );
	}
}
