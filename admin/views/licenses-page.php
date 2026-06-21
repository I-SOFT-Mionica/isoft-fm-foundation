<?php defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

// Belt-and-braces capability gate even though the menu page is already capability-gated.
if ( ! current_user_can( 'isoft_fmf_manage_settings' ) ) {
	wp_die( esc_html__( 'You do not have permission to manage licenses.', 'isoft-fm-foundation' ), 403 );
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Licenses', 'isoft-fm-foundation' ); ?> <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'new' ), isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'isoft-fm-foundation' ); ?></a></h1>

	<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of query-string flags. ?>
	<?php if ( isset( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License saved.', 'isoft-fm-foundation' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License deleted.', 'isoft-fm-foundation' ); ?></p></div>
	<?php endif; ?>

	<?php
	if ( isset( $_GET['restored'] ) ) :
		$restored_count = absint( $_GET['restored'] );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
			<?php
			if ( $restored_count > 0 ) {
				printf(
					esc_html(
						/* translators: %d: number of seeded licenses installed */
						_n( '%d seeded license added.', '%d seeded licenses added.', $restored_count, 'isoft-fm-foundation' )
					),
					(int) $restored_count
				);
			} else {
				esc_html_e( 'No new seeded licenses to add — all defaults already present.', 'isoft-fm-foundation' );
			}
			?>
			</p>
		</div>
		<?php
	endif;
	?>

	<?php $action = sanitize_key( $_GET['action'] ?? 'list' ); ?>
	<?php $edit_id = absint( $_GET['edit_id'] ?? 0 ); ?>
	<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

	<?php
	if ( in_array( $action, array( 'new', 'edit' ), true ) ) :
		$license_manager = new ISOFT_FMF_License_Manager();
		$editing         = $edit_id ? $license_manager->get( $edit_id ) : null;
		?>
	<form method="post">
		<?php wp_nonce_field( 'isoft_fmf_license_action' ); ?>
		<input type="hidden" name="isoft_fmf_license_action" value="save" />
		<input type="hidden" name="license_id" value="<?php echo esc_attr( $edit_id ); ?>" />
		<table class="form-table">
			<tr>
				<th><label for="isoft-fmf-lic-title"><?php esc_html_e( 'Title', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="title" id="isoft-fmf-lic-title" value="<?php echo esc_attr( $editing->title ?? '' ); ?>" class="regular-text" required /></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-lic-slug"><?php esc_html_e( 'Slug', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="slug" id="isoft-fmf-lic-slug" value="<?php echo esc_attr( $editing->slug ?? '' ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-lic-desc"><?php esc_html_e( 'Short Description', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="text" name="description" id="isoft-fmf-lic-desc" value="<?php echo esc_attr( $editing->description ?? '' ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-lic-url"><?php esc_html_e( 'License URL', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="url" name="url" id="isoft-fmf-lic-url" value="<?php echo esc_attr( $editing->url ?? '' ); ?>" class="regular-text" placeholder="https://…" /></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-lic-full-text"><?php esc_html_e( 'Full License Text', 'isoft-fm-foundation' ); ?></label></th>
				<td>
					<textarea name="full_text" id="isoft-fmf-lic-full-text" class="widefat" rows="10"><?php echo esc_textarea( $editing->full_text ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown in the agreement modal when a user downloads a file requiring consent.', 'isoft-fm-foundation' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Default', 'isoft-fm-foundation' ); ?></th>
				<td><label><input type="checkbox" name="is_default" value="1" <?php checked( $editing->is_default ?? 0 ); ?> /> <?php esc_html_e( 'Set as default license for new downloads', 'isoft-fm-foundation' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="isoft-fmf-lic-order"><?php esc_html_e( 'Sort Order', 'isoft-fm-foundation' ); ?></label></th>
				<td><input type="number" name="sort_order" id="isoft-fmf-lic-order" value="<?php echo esc_attr( $editing->sort_order ?? 0 ); ?>" class="small-text" min="0" /></td>
			</tr>
		</table>
		<?php submit_button( $edit_id ? __( 'Update License', 'isoft-fm-foundation' ) : __( 'Add License', 'isoft-fm-foundation' ) ); ?>
	</form>

	<?php else : ?>
		<?php $licenses = ( new ISOFT_FMF_License_Manager() )->get_all(); ?>

	<form method="post" style="margin: 12px 0;" onsubmit="return confirm('<?php esc_attr_e( 'Add any missing seeded licenses (Public Domain variants, Creative Commons, etc.)? Existing licenses with the same slug will not be modified.', 'isoft-fm-foundation' ); ?>');">
		<?php wp_nonce_field( 'isoft_fmf_license_action' ); ?>
		<input type="hidden" name="isoft_fmf_license_action" value="restore_seeds" />
		<button type="submit" class="button"><?php esc_html_e( 'Restore seeded licenses', 'isoft-fm-foundation' ); ?></button>
		<span class="description" style="margin-left:8px;">
			<?php esc_html_e( 'Adds any default licenses missing from this install. Add-only — never modifies existing rows. Use after a plugin update that ships new defaults.', 'isoft-fm-foundation' ); ?>
		</span>
	</form>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Title', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Slug', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Description', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Default', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'isoft-fm-foundation' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $licenses ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No licenses found.', 'isoft-fm-foundation' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $licenses as $lic ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $lic->title ); ?></strong></td>
				<td><code><?php echo esc_html( $lic->slug ); ?></code></td>
				<td><?php echo esc_html( $lic->description ); ?></td>
				<td><?php echo $lic->is_default ? '✓' : ''; ?></td>
				<td>
					<a href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'action'  => 'edit',
								'edit_id' => $lic->id,
							)
						)
					);
					?>
								"><?php esc_html_e( 'Edit', 'isoft-fm-foundation' ); ?></a>
					&nbsp;|&nbsp;
					<form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Delete this license?', 'isoft-fm-foundation' ); ?>');">
						<?php wp_nonce_field( 'isoft_fmf_license_action' ); ?>
						<input type="hidden" name="isoft_fmf_license_action" value="delete" />
						<input type="hidden" name="license_id" value="<?php echo esc_attr( $lic->id ); ?>" />
						<button type="submit" class="button-link" style="color:#a00;"><?php esc_html_e( 'Delete', 'isoft-fm-foundation' ); ?></button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
