<?php
defined( 'ABSPATH' ) || exit;

class ISOFT_FMF_Meta_Fields {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		$fields = array(
			'_isoft_fmf_version'        => 'string',
			'_isoft_fmf_changelog'      => 'string',
			'_isoft_fmf_license_id'     => 'integer',
			'_isoft_fmf_author_name'    => 'string',
			'_isoft_fmf_author_url'     => 'string',
			'_isoft_fmf_date_published' => 'string',
			'_isoft_fmf_download_count' => 'integer',
			'_isoft_fmf_access_role'    => 'string',
			'_isoft_fmf_require_agree'  => 'boolean',
			'_isoft_fmf_agree_text'     => 'string',
			'_isoft_fmf_is_hot'         => 'boolean',
			'_isoft_fmf_featured'       => 'boolean',
			'_isoft_fmf_external_only'  => 'boolean',
		);

		foreach ( $fields as $key => $type ) {
			register_post_meta(
				'isoft_fmf_file',
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => fn() => current_user_can( 'isoft_fmf_edit_own_downloads' ),
				)
			);
		}
	}
}
