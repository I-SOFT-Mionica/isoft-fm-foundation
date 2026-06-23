/**
 * React app for the Download Log admin page (Downloads > Download Log).
 *
 * 0.12.3 scope, sub-PR 2 of 4. First DataViews surface: server-side
 * pagination + filter + search backed by GET /logs.
 *
 * Why DataViews here (and not in Stats):
 *   This IS a paginated list — thousands of log rows on busy sites,
 *   needs page/perPage controls, sortable date column, filter by
 *   download, search across title/file/ip. DataViews handles all of
 *   that with one component instead of bespoke pagination UI.
 *
 * Server-side everything:
 *   data items, totalItems, totalPages all come from the REST endpoint
 *   per request — no client-side filtering / sorting / pagination.
 *   Re-fetches on every view change. Cheap because the user changes
 *   view rarely and the SQL is indexed on downloaded_at DESC.
 */

import { DataViews } from '@wordpress/dataviews';
import {
	Notice,
	Spinner,
	Button,
} from '@wordpress/components';
import { useState, useEffect, useMemo, createRoot, render } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import apiFetch from '@wordpress/api-fetch';

const LOGS_ROUTE      = '/isoft-fm-foundation/v1/logs';
const DOWNLOADS_ROUTE = '/isoft-fm-foundation/v1/downloads';

const DEFAULT_VIEW = {
	type:    'table',
	perPage: 25,
	page:    1,
	sort:    {
		field:     'downloaded_at',
		direction: 'desc',
	},
	search:   '',
	filters:  [],
	fields:   [
		'downloaded_at',
		'download_title',
		'file_name',
		'user_id',
		'user_ip',
	],
	// Compact density by default — the log is information-dense and the
	// default "balanced" mode wastes vertical space. Users can switch
	// back via Appearance > Density.
	density: 'compact',
	layout:  {},
};

/**
 * Format a MySQL DATETIME using the site's WP date + time format
 * settings (Settings > General). @wordpress/date is auto-hydrated by
 * WP with the current site formats when wp-date is enqueued.
 */
const formatDate = ( mysqlDate ) => {
	if ( ! mysqlDate ) {
		return '';
	}
	const formats = getDateSettings().formats;
	const fmt     = `${ formats.date } ${ formats.time }`;
	return dateI18n( fmt, mysqlDate );
};

const LogApp = ( { exportBaseUrl, purgeUrl, retentionDays, loggingEnabled, canExport, canPurge } ) => {
	const [ view, setView ]           = useState( DEFAULT_VIEW );
	const [ rows, setRows ]           = useState( [] );
	const [ total, setTotal ]         = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ loading, setLoading ]     = useState( false );
	const [ error, setError ]         = useState( null );

	// Download list for the filter dropdown. Independent fetch — cheap,
	// runs once. If the viewer lacks edit_posts the list is empty and
	// the filter degrades gracefully (search still works).
	const [ downloads, setDownloads ] = useState( [] );

	useEffect( () => {
		apiFetch( { path: `${ DOWNLOADS_ROUTE }?per_page=100&orderby=title&order=ASC` } )
			.then( ( list ) => setDownloads( Array.isArray( list ) ? list : [] ) )
			.catch( () => setDownloads( [] ) );
	}, [] );

	// Server-side fetch on every view change. Parse total / totalPages
	// from response headers using apiFetch's parse:false escape hatch.
	useEffect( () => {
		const params = new URLSearchParams();
		params.set( 'per_page', String( view.perPage || 25 ) );
		params.set( 'page', String( view.page || 1 ) );
		if ( view.search ) {
			params.set( 'search', view.search );
		}
		// Filter field id is download_title (single combined column + filter
		// per the comment above); the value the user picks IS the post ID
		// because that's what we set elements.value to.
		const downloadFilter = ( view.filters || [] ).find(
			( f ) => f.field === 'download_title'
		);
		if ( downloadFilter && downloadFilter.value ) {
			params.set( 'download_id', String( downloadFilter.value ) );
		}

		setLoading( true );
		setError( null );

		// Plain apiFetch (no parse:false). The REST endpoint returns
		// { items, totalItems, totalPages } as a single JSON object.
		apiFetch( { path: `${ LOGS_ROUTE }?${ params.toString() }` } )
			.then( ( payload ) => {
				setRows( Array.isArray( payload?.items ) ? payload.items : [] );
				setTotal( parseInt( payload?.totalItems || 0, 10 ) );
				setTotalPages( parseInt( payload?.totalPages || 0, 10 ) );
			} )
			.catch( ( err ) => {
				setError(
					err?.message ||
						__( 'Could not load log entries.', 'isoft-fm-foundation' )
				);
				setRows( [] );
				setTotal( 0 );
				setTotalPages( 0 );
			} )
			.finally( () => setLoading( false ) );
	}, [ view.perPage, view.page, view.search, view.filters ] );

	// DataViews fields schema. id is required and must be unique per row.
	const fields = useMemo(
		() => [
			{
				id:       'downloaded_at',
				label:    __( 'When', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:   ( { item } ) => formatDate( item.downloaded_at ),
			},
			{
				// Single field carries both the visible column (rendered as
				// linked title) and the filter dropdown (elements = list of
				// downloads, value = post ID). DataViews uses getValue for
				// filter matching; render for display. Avoids the duplicate
				// "Download" entries that appeared when we had separate
				// download_title (column) and download_id (filter) fields —
				// the Properties panel lists every field once.
				id:       'download_title',
				label:    __( 'Download', 'isoft-fm-foundation' ),
				enableSorting: false,
				getValue: ( { item } ) => parseInt( item.download_id, 10 ) || 0,
				elements: [
					{ label: __( '— All downloads —', 'isoft-fm-foundation' ), value: 0 },
					...downloads.map( ( d ) => ( {
						label: d.title,
						value: parseInt( d.id, 10 ),
					} ) ),
				],
				filterBy: {
					operators: [ 'is' ],
				},
				render:   ( { item } ) => {
					const title = item.download_title || __( '(deleted)', 'isoft-fm-foundation' );
					if ( item.download_id && item.download_title ) {
						const url = `${ window.location.origin }/wp-admin/post.php?post=${ item.download_id }&action=edit`;
						return <a href={ url }>{ title }</a>;
					}
					return <em>{ title }</em>;
				},
			},
			{
				id:       'file_name',
				label:    __( 'File', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:   ( { item } ) => item.file_name || '—',
			},
			{
				id:       'user_id',
				label:    __( 'User', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:   ( { item } ) => {
					if ( ! item.user_id ) {
						return <em>{ __( 'Guest', 'isoft-fm-foundation' ) }</em>;
					}
					const url = `${ window.location.origin }/wp-admin/user-edit.php?user_id=${ item.user_id }`;
					return <a href={ url }>{ `#${ item.user_id }` }</a>;
				},
			},
			{
				id:    'user_ip',
				label: __( 'IP', 'isoft-fm-foundation' ),
				enableSorting: false,
				render: ( { item } ) => (
					<code style={ { fontSize: '11px' } }>{ item.user_ip || '—' }</code>
				),
			},
		],
		[ downloads ]
	);

	const paginationInfo = useMemo(
		() => ( { totalItems: total, totalPages } ),
		[ total, totalPages ]
	);

	const defaultLayouts = { table: {} };

	return (
		<div className="wrap">
			<h1 className="wp-heading-inline">
				{ __( 'Download Log', 'isoft-fm-foundation' ) }
			</h1>

			{ canExport && (
				<>
					<a
						href={ `${ exportBaseUrl }&isoft_fmf_action=export_csv` }
						className="page-title-action"
						style={ { marginLeft: '8px' } }
					>
						{ __( 'Export CSV', 'isoft-fm-foundation' ) }
					</a>
					<a
						href={ `${ exportBaseUrl }&isoft_fmf_action=export_json` }
						className="page-title-action"
					>
						{ __( 'Export JSON', 'isoft-fm-foundation' ) }
					</a>
				</>
			) }

			<hr className="wp-header-end" />

			{ ! loggingEnabled && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Download logging is currently disabled. Enable it in Settings → General.',
						'isoft-fm-foundation'
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<div style={ { marginTop: '16px', position: 'relative' } }>
				{ loading && (
					<div
						style={ {
							position:        'absolute',
							top:             '12px',
							right:           '12px',
							zIndex:          10,
						} }
					>
						<Spinner />
					</div>
				) }
				<DataViews
					data={ rows }
					view={ view }
					onChangeView={ setView }
					fields={ fields }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					getItemId={ ( item ) => String( item.id ) }
					// No row actions on the log — read-only audit trail.
					// Bulk delete-individual-log-rows was never wired in
					// the PHP page either; purge-by-retention is the only
					// destructive op and it lives in the footer below.
					actions={ [] }
				/>
			</div>

			{ canPurge && (
				<>
					<hr />
					<form
						method="post"
						action={ purgeUrl }
						onSubmit={ ( e ) => {
							if (
								! window.confirm(
									__(
										'This will permanently delete log entries older than the configured retention period. Continue?',
										'isoft-fm-foundation'
									)
								)
							) {
								e.preventDefault();
							}
						} }
						style={ { marginTop: '16px' } }
					>
						{ /* Nonce + action emitted by PHP into the mount-shell data attrs. */ }
						<input
							type="hidden"
							name="_wpnonce"
							value={ document
								.getElementById( 'isoft-fmf-log-root' )
								?.getAttribute( 'data-purge-nonce' ) || '' }
						/>
						<input type="hidden" name="action" value="isoft_fmf_purge_logs" />
						<Button
							variant="secondary"
							isDestructive
							type="submit"
							__next40pxDefaultSize
						>
							{ __( 'Purge old log entries', 'isoft-fm-foundation' ) }
						</Button>
						<span
							style={ {
								marginLeft: '8px',
								color:      '#646970',
							} }
						>
							{ sprintf(
								/* translators: %d: retention days setting */
								__(
									'Deletes entries older than %d days (configured in Settings).',
									'isoft-fm-foundation'
								),
								retentionDays
							) }
						</span>
					</form>
				</>
			) }
		</div>
	);
};

// Bootstrap. PHP shell hands over config via data-* attributes on the
// mount node — base export URL, purge URL + nonce, retention days,
// logging-enabled state, and capability flags. Keeps the JS bundle
// free of hardcoded URLs or admin-only PHP calls.
const mountNode = document.getElementById( 'isoft-fmf-log-root' );
if ( mountNode ) {
	const props = {
		exportBaseUrl:   mountNode.getAttribute( 'data-export-base' ) || '',
		purgeUrl:        mountNode.getAttribute( 'data-purge-url' ) || '',
		retentionDays:   parseInt( mountNode.getAttribute( 'data-retention-days' ) || '365', 10 ),
		loggingEnabled:  mountNode.getAttribute( 'data-logging-enabled' ) === '1',
		canExport:       mountNode.getAttribute( 'data-can-export' ) === '1',
		canPurge:        mountNode.getAttribute( 'data-can-purge' ) === '1',
	};
	if ( typeof createRoot === 'function' ) {
		createRoot( mountNode ).render( <LogApp { ...props } /> );
	} else if ( typeof render === 'function' ) {
		render( <LogApp { ...props } />, mountNode );
	}
}
