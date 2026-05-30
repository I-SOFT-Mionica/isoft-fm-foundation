<?php defined( 'ABSPATH' ) || exit; ?>
<p>
	<strong><?php esc_html_e( 'Total Downloads:', 'isoft-fm-foundation' ); ?></strong>
	<?php echo esc_html( number_format_i18n( $total_downloads ) ); ?>
</p>

<?php if ( $files ) : ?>
	<table class="widefat striped" style="margin-top:.5em;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'File', 'isoft-fm-foundation' ); ?></th>
				<th><?php esc_html_e( 'Count', 'isoft-fm-foundation' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $files as $file ) : ?>
			<tr>
				<td><?php echo esc_html( $file->title ?: $file->file_name ?: $file->external_url ); ?></td>
				<td><?php echo esc_html( number_format_i18n( $file->download_count ) ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php else : ?>
	<p style="color:#999;"><?php esc_html_e( 'No files attached.', 'isoft-fm-foundation' ); ?></p>
<?php endif; ?>

<?php if ( $total_downloads > 0 ) : ?>
	<p style="margin-top:.75em;">
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page'        => 'isfm-log',
					'post_type'   => 'isfm_file',
					'download_id' => get_the_ID(),
				),
				admin_url( 'edit.php' )
			)
		);
		?>
					">
			<?php esc_html_e( 'View full download log →', 'isoft-fm-foundation' ); ?>
		</a>
	</p>
<?php endif; ?>
