/**
 * React app for the Statistics admin page (Downloads > Statistics).
 *
 * 0.12.3 scope, sub-PR 1 of 4. Dashboard surface — KPI cards, 30-day
 * bar chart, top-N tables. NOT a DataViews list: a dashboard renders
 * pre-aggregated data, no pagination / filtering needed.
 *
 * Data source: GET /isoft-fm-foundation/v1/stats/overview (the existing
 * endpoint, extended in this PR to return the full dashboard payload
 * the PHP view used to read directly from isoft_fmf_get_stats_overview()).
 * Server-side transient cache (5 min) means the React app's apiFetch
 * is just as cheap as the PHP page-load it replaces.
 */

import {
	Notice,
	Spinner,
	Button,
} from '@wordpress/components';
import { useState, useEffect, useCallback, createRoot, render } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const ROUTE = '/isoft-fm-foundation/v1/stats/overview';

const formatBytes = ( bytes ) => {
	const n = parseInt( bytes, 10 ) || 0;
	if ( n >= 1073741824 ) {
		return ( n / 1073741824 ).toFixed( 2 ) + ' GB';
	}
	if ( n >= 1048576 ) {
		return ( n / 1048576 ).toFixed( 2 ) + ' MB';
	}
	if ( n >= 1024 ) {
		return ( n / 1024 ).toFixed( 1 ) + ' KB';
	}
	return n + ' B';
};

/** YYYY-MM-DD for a date offset (in days) from today, UTC-aligned. */
const offsetDay = ( daysAgo ) => {
	const d = new Date();
	d.setUTCDate( d.getUTCDate() - daysAgo );
	return d.toISOString().slice( 0, 10 );
};

/** "M YYYY" for a YYYY-MM-DD string. */
const monthLabel = ( ymd ) => {
	const d = new Date( ymd + 'T00:00:00Z' );
	if ( isNaN( d.getTime() ) ) {
		return ymd;
	}
	return d.toLocaleDateString( undefined, {
		month: 'short',
		year:  'numeric',
		timeZone: 'UTC',
	} );
};

const StatCard = ( { value, label } ) => (
	<div className="isoft-fmf-stat-card">
		<span className="isoft-fmf-stat-value">{ value }</span>
		<span className="isoft-fmf-stat-label">{ label }</span>
	</div>
);

const DailyBarChart = ( { daily } ) => {
	// Build a contiguous 30-day window so missing days render as zero
	// bars (just like the PHP view did with array fill + lookup).
	const days = [];
	for ( let i = 29; i >= 0; i-- ) {
		const date = offsetDay( i );
		days.push( { date, count: parseInt( daily?.[ date ] || 0, 10 ) } );
	}
	const total = days.reduce( ( s, d ) => s + d.count, 0 );
	const max   = Math.max( 1, ...days.map( ( d ) => d.count ) );

	if ( 0 === total ) {
		return (
			<p className="description">
				{ __( 'No log entries in the last 30 days.', 'isoft-fm-foundation' ) }
			</p>
		);
	}

	return (
		<div className="isoft-fmf-chart">
			<div className="isoft-fmf-chart-header">
				<span className="isoft-fmf-chart-month">
					{ monthLabel( days[ 0 ].date ) }
				</span>
				<span className="isoft-fmf-chart-month">
					{ monthLabel( days[ days.length - 1 ].date ) }
				</span>
			</div>
			<div
				className="isoft-fmf-bar-chart"
				aria-label={ __( 'Daily download chart', 'isoft-fm-foundation' ) }
			>
				{ days.map( ( d ) => {
					const pct     = Math.round( ( d.count / max ) * 100 );
					const dayNum  = parseInt( d.date.slice( 8 ), 10 );
					const tooltip = sprintf(
						/* translators: 1: number of downloads on the hovered day, 2: date (YYYY-MM-DD) */
						_n(
							'%1$s download on %2$s',
							'%1$s downloads on %2$s',
							d.count,
							'isoft-fm-foundation'
						),
						d.count.toLocaleString(),
						d.date
					);
					return (
						<div
							className="isoft-fmf-bar-wrap"
							key={ d.date }
							title={ tooltip }
						>
							<div
								className="isoft-fmf-bar"
								style={ { height: pct + '%' } }
							/>
							<span className="isoft-fmf-bar-label">
								{ dayNum }
							</span>
						</div>
					);
				} ) }
			</div>
		</div>
	);
};

const TopTable = ( { rows, title, fallbackNote, getId, getTitle, getCount } ) => (
	<div className="isoft-fmf-stat-section">
		<h2>{ title }</h2>
		{ fallbackNote && (
			<p className="description">{ fallbackNote }</p>
		) }
		{ rows.length === 0 ? (
			<p className="description">
				{ __( 'No data yet.', 'isoft-fm-foundation' ) }
			</p>
		) : (
			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>{ __( 'Download', 'isoft-fm-foundation' ) }</th>
						<th style={ { width: '80px', textAlign: 'right' } }>
							{ __( 'Count', 'isoft-fm-foundation' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row, idx ) => {
						const id    = getId( row );
						const title = getTitle( row );
						const count = getCount( row );
						const url   = id
							? `${ window.location.origin }/wp-admin/post.php?post=${ id }&action=edit`
							: '';
						return (
							<tr key={ id || idx }>
								<td>
									{ url ? (
										<a href={ url }>{ title }</a>
									) : (
										<em>{ title }</em>
									) }
								</td>
								<td style={ { textAlign: 'right' } }>
									{ count.toLocaleString() }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		) }
	</div>
);

const StatsApp = () => {
	const [ data, setData ]         = useState( null );
	const [ loading, setLoading ]   = useState( false );
	const [ error, setError ]       = useState( null );

	const fetchStats = useCallback( () => {
		setLoading( true );
		setError( null );
		return apiFetch( { path: ROUTE } )
			.then( ( payload ) => {
				setData( payload );
			} )
			.catch( ( err ) => {
				setError(
					err?.message ||
						__( 'Could not load statistics.', 'isoft-fm-foundation' )
				);
			} )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		fetchStats();
	}, [ fetchStats ] );

	if ( error && ! data ) {
		return (
			<div className="wrap isoft-fmf-stats">
				<h1>{ __( 'Download Statistics', 'isoft-fm-foundation' ) }</h1>
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			</div>
		);
	}

	if ( ! data ) {
		return (
			<div className="wrap isoft-fmf-stats">
				<h1>{ __( 'Download Statistics', 'isoft-fm-foundation' ) }</h1>
				<p><Spinner /></p>
			</div>
		);
	}

	const top30Label = 'alltime' === data.top_30d_window
		? __( 'Top Downloads (All-Time)', 'isoft-fm-foundation' )
		: __( 'Top Downloads (Last 30 Days)', 'isoft-fm-foundation' );
	const top30Fallback = 'alltime' === data.top_30d_window && data.top_30d.length > 0
		? __(
			'No downloads recorded in the last 30 days — showing all-time leaders instead.',
			'isoft-fm-foundation'
		)
		: null;

	return (
		<div className="wrap isoft-fmf-stats">
			<h1 className="wp-heading-inline">
				{ __( 'Download Statistics', 'isoft-fm-foundation' ) }
			</h1>
			<Button
				variant="secondary"
				onClick={ fetchStats }
				disabled={ loading }
				className="page-title-action"
				style={ { marginLeft: '8px' } }
				__next40pxDefaultSize
			>
				{ loading
					? __( 'Refreshing…', 'isoft-fm-foundation' )
					: __( 'Refresh', 'isoft-fm-foundation' ) }
			</Button>
			<hr className="wp-header-end" />

			{ ! data.logging_enabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Download logging is disabled. Enable it in Settings to track activity over time.',
						'isoft-fm-foundation'
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div className="isoft-fmf-stat-cards">
				<StatCard
					value={ ( data.total_downloads || 0 ).toLocaleString() }
					label={ __( 'Published Downloads', 'isoft-fm-foundation' ) }
				/>
				<StatCard
					value={ ( data.total_files || 0 ).toLocaleString() }
					label={ __( 'Total Files', 'isoft-fm-foundation' ) }
				/>
				<StatCard
					value={ formatBytes( data.total_size_bytes ) }
					label={ __( 'Total File Size', 'isoft-fm-foundation' ) }
				/>
				<StatCard
					value={ ( data.total_log_entries || 0 ).toLocaleString() }
					label={ __( 'Log Entries', 'isoft-fm-foundation' ) }
				/>
			</div>

			<div className="isoft-fmf-stat-section">
				<h2>
					{ __( 'Downloads — Last 30 Days', 'isoft-fm-foundation' ) }
				</h2>
				<DailyBarChart daily={ data.daily_30d } />
			</div>

			<div className="isoft-fmf-stat-columns">
				<TopTable
					rows={ data.top_alltime || [] }
					title={ __( 'Top Downloads (All-Time)', 'isoft-fm-foundation' ) }
					fallbackNote={ null }
					getId={ ( r ) => parseInt( r.ID, 10 ) || 0 }
					getTitle={ ( r ) => r.post_title || __( '(deleted)', 'isoft-fm-foundation' ) }
					getCount={ ( r ) => parseInt( r.total_count, 10 ) || 0 }
				/>
				<TopTable
					rows={ data.top_30d || [] }
					title={ top30Label }
					fallbackNote={ top30Fallback }
					// top_30d rows come from the daily aggregate via LEFT JOIN
					// on posts — if the post is deleted, download_id is still
					// the original FK but post_title is null. Gate the link
					// on title presence so deleted entries render unclickable.
					getId={ ( r ) => ( r.post_title ? parseInt( r.download_id, 10 ) : 0 ) || 0 }
					getTitle={ ( r ) => r.post_title || __( '(deleted)', 'isoft-fm-foundation' ) }
					getCount={ ( r ) => parseInt( r.count, 10 ) || 0 }
				/>
			</div>
		</div>
	);
};

const mountNode = document.getElementById( 'isoft-fmf-stats-root' );
if ( mountNode ) {
	if ( typeof createRoot === 'function' ) {
		createRoot( mountNode ).render( <StatsApp /> );
	} else if ( typeof render === 'function' ) {
		render( <StatsApp />, mountNode );
	}
}
