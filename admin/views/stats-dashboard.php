<?php
/**
 * Statistics dashboard — Phase 4.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Locals passed in by the including class; not actual globals.

$stats             = isfm_get_stats_overview();
$total_downloads   = $stats['total_downloads'];
$total_files       = $stats['total_files'];
$total_size        = $stats['total_size_bytes'];
$total_log_entries = $stats['total_log_entries'];
$top_alltime       = $stats['top_alltime'];
$top_30d           = $stats['top_30d'];

// Index daily counts by date string for easy lookup
$daily_map = array();
foreach ( $stats['daily_30d'] as $row ) {
	$daily_map[ $row->day ] = (int) $row->count;
}

$max_daily = $daily_map ? max( $daily_map ) : 1;

// Build a 30-day array
$chart_days = array();
for ( $i = 29; $i >= 0; $i-- ) {
	$date                = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
	$chart_days[ $date ] = $daily_map[ $date ] ?? 0;
}

// ── Helper ────────────────────────────────────────────────────────────────────
function isfm_format_bytes( int $bytes ): string {
	if ( $bytes >= 1073741824 ) {
		return number_format( $bytes / 1073741824, 2 ) . ' GB';
	}
	if ( $bytes >= 1048576 ) {
		return number_format( $bytes / 1048576, 2 ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return number_format( $bytes / 1024, 1 ) . ' KB';
	}
	return $bytes . ' B';
}
?>
<div class="wrap isfm-stats">
	<h1><?php esc_html_e( 'Download Statistics', 'isoft-fm-foundation' ); ?></h1>

	<?php if ( ! get_option( 'isfm_enable_logging', true ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Download logging is disabled. Enable it in Settings to track activity over time.', 'isoft-fm-foundation' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Summary cards -->
	<div class="isfm-stat-cards">
		<div class="isfm-stat-card">
			<span class="isfm-stat-value"><?php echo esc_html( number_format( $total_downloads ) ); ?></span>
			<span class="isfm-stat-label"><?php esc_html_e( 'Published Downloads', 'isoft-fm-foundation' ); ?></span>
		</div>
		<div class="isfm-stat-card">
			<span class="isfm-stat-value"><?php echo esc_html( number_format( $total_files ) ); ?></span>
			<span class="isfm-stat-label"><?php esc_html_e( 'Total Files', 'isoft-fm-foundation' ); ?></span>
		</div>
		<div class="isfm-stat-card">
			<span class="isfm-stat-value"><?php echo esc_html( isfm_format_bytes( $total_size ) ); ?></span>
			<span class="isfm-stat-label"><?php esc_html_e( 'Total File Size', 'isoft-fm-foundation' ); ?></span>
		</div>
		<div class="isfm-stat-card">
			<span class="isfm-stat-value"><?php echo esc_html( number_format( $total_log_entries ) ); ?></span>
			<span class="isfm-stat-label"><?php esc_html_e( 'Log Entries', 'isoft-fm-foundation' ); ?></span>
		</div>
	</div>

	<!-- Daily chart -->
	<div class="isfm-stat-section">
		<h2><?php esc_html_e( 'Downloads — Last 30 Days', 'isoft-fm-foundation' ); ?></h2>
		<?php if ( array_sum( $chart_days ) === 0 ) : ?>
			<p class="description"><?php esc_html_e( 'No log entries in the last 30 days.', 'isoft-fm-foundation' ); ?></p>
		<?php else : ?>
			<div class="isfm-bar-chart" aria-label="<?php esc_attr_e( 'Daily download chart', 'isoft-fm-foundation' ); ?>">
				<?php foreach ( $chart_days as $date => $count ) : ?>
					<?php $pct = $max_daily > 0 ? round( ( $count / $max_daily ) * 100 ) : 0; ?>
					<div class="isfm-bar-wrap" title="<?php echo esc_attr( $date . ': ' . $count ); ?>">
						<div class="isfm-bar" style="height:<?php echo esc_attr( $pct ); ?>%"></div>
						<span class="isfm-bar-label"><?php echo esc_html( substr( $date, 5 ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="isfm-stat-columns">
		<!-- Top downloads all-time -->
		<div class="isfm-stat-section">
			<h2><?php esc_html_e( 'Top Downloads (All-Time)', 'isoft-fm-foundation' ); ?></h2>
			<?php if ( ! $top_alltime ) : ?>
				<p class="description"><?php esc_html_e( 'No data yet.', 'isoft-fm-foundation' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Download', 'isoft-fm-foundation' ); ?></th>
							<th style="width:80px;text-align:right"><?php esc_html_e( 'Count', 'isoft-fm-foundation' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_alltime as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $row->ID ) ); ?>">
										<?php echo esc_html( $row->post_title ); ?>
									</a>
								</td>
								<td style="text-align:right"><?php echo esc_html( number_format( (int) $row->total_count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Top downloads last 30 days -->
		<div class="isfm-stat-section">
			<h2><?php esc_html_e( 'Top Downloads (Last 30 Days)', 'isoft-fm-foundation' ); ?></h2>
			<?php if ( ! $top_30d ) : ?>
				<p class="description"><?php esc_html_e( 'No log entries in the last 30 days.', 'isoft-fm-foundation' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Download', 'isoft-fm-foundation' ); ?></th>
							<th style="width:80px;text-align:right"><?php esc_html_e( 'Count', 'isoft-fm-foundation' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_30d as $row ) : ?>
							<tr>
								<td>
									<?php if ( $row->download_id && get_post( $row->download_id ) ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $row->download_id ) ); ?>">
											<?php echo esc_html( $row->post_title ?: __( '(deleted)', 'isoft-fm-foundation' ) ); ?>
										</a>
									<?php else : ?>
										<em><?php echo esc_html( $row->post_title ?: __( '(deleted)', 'isoft-fm-foundation' ) ); ?></em>
									<?php endif; ?>
								</td>
								<td style="text-align:right"><?php echo esc_html( number_format( (int) $row->count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
