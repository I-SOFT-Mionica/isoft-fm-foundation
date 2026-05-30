<?php
defined( 'ABSPATH' ) || exit;

class ISFM_Meta_Fields {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		$fields = array(
			'_isfm_version'        => 'string',
			'_isfm_changelog'      => 'string',
			'_isfm_license_id'     => 'integer',
			'_isfm_author_name'    => 'string',
			'_isfm_author_url'     => 'string',
			'_isfm_date_published' => 'string',
			'_isfm_download_count' => 'integer',
			'_isfm_access_role'    => 'string',
			'_isfm_require_agree'  => 'boolean',
			'_isfm_agree_text'     => 'string',
			'_isfm_is_hot'         => 'boolean',
			'_isfm_featured'       => 'boolean',
			'_isfm_external_only'  => 'boolean',
		);

		foreach ( $fields as $key => $type ) {
			register_post_meta(
				'isfm_file',
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => fn() => current_user_can( 'isfm_edit_own_downloads' ),
				)
			);
		}
	}
}
