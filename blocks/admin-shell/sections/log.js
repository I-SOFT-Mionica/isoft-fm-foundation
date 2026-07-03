/**
 * Download Log section — DataViews with server-side pagination + filter
 * + search backed by GET /logs.
 *
 * Ported from blocks/log-page/index.js in 0.12.6 when the 5 per-page
 * bundles collapsed into a single admin-shell entry. Content is
 * unchanged aside from:
 *   - Renamed LogApp -> LogSection.
 *   - Removed self-mount code at the bottom (shell owns mounting).
 *   - Bootstrap payload arrives as one `bootstrap` prop instead of
 *     eight separate scalar props (destructured inside).
 *   - formatDate imported from ../util/format instead of defined inline.
 *   - Nonce for the purge form reads from bootstrap.purgeNonce instead
 *     of scraping the old #isoft-fmf-log-root data-* attribute (which
 *     no longer exists — the shell's mount div carries a single JSON
 *     bootstrap blob, not per-section data-* scalars).
 *   - Stripped top-level .wrap wrapper (shell provides it).
 */

import { DataViews } from '@wordpress/dataviews';
import { Notice, Button } from '@wordpress/components';
import { useState, useEffect, useMemo, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { formatDate } from '../util/format';

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
		'download_title',
		'file_name',
		'user_id',
		'downloaded_at',
	],
	density: 'compact',
	layout:  {},
};

const LogSection = ( { bootstrap } ) => {
	const {
		exportBaseUrl    = '',
		purgeUrl         = '',
		purgeNonce       = '',
		retentionDays    = 365,
		loggingEnabled   = true,
		canExport        = false,
		canPurge         = false,
		initialTotal     = 0,
		initialPages     = 0,
		initialItems     = null,
		initialDownloads = null,
	} = bootstrap || {};

	const [ view, setView ]             = useState( DEFAULT_VIEW );
	const [ rows, setRows ]             = useState( Array.isArray( initialItems ) ? initialItems : [] );
	const [ total, setTotal ]           = useState( initialTotal );
	const [ totalPages, setTotalPages ] = useState( initialPages );
	// Start un-loading when we already have server-inlined data; the
	// spinner + "No results" flash on first paint is what the pre-perf
	// pass burned to a REST round-trip we didn't need.
	const [ loading, setLoading ]       = useState( ! Array.isArray( initialItems ) );
	const [ error, setError ]           = useState( null );

	const [ downloads, setDownloads ] = useState(
		Array.isArray( initialDownloads ) ? initialDownloads : []
	);

	// Skip the extra downloads-list fetch when PHP inlined it.
	useEffect( () => {
		if ( Array.isArray( initialDownloads ) ) {
			return;
		}
		apiFetch( { path: `${ DOWNLOADS_ROUTE }?per_page=100&orderby=title&order=ASC` } )
			.then( ( list ) => setDownloads( Array.isArray( list ) ? list : [] ) )
			.catch( () => setDownloads( [] ) );
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Debounce ANY view change (search, filter, page, perPage) by 300ms
	// so typing doesn't spam a slow backend. Keep-previous-data pattern:
	// on new fetch, do NOT clear rows — the visible table stays populated
	// while the request is in flight, only replaced on success.
	//
	// First render: the initial fetch is suppressed when server-inlined
	// data is present (initialItems). The ref-guarded skip catches the
	// mount-time effect fire so a view-untouched first paint doesn't
	// double-fetch what the shell already gave us.
	const skipFirstFetch = useRef( Array.isArray( initialItems ) );

	useEffect( () => {
		if ( skipFirstFetch.current ) {
			skipFirstFetch.current = false;
			return;
		}

		const params = new URLSearchParams();
		params.set( 'per_page', String( view.perPage || 25 ) );
		params.set( 'page', String( view.page || 1 ) );
		if ( view.search ) {
			params.set( 'search', view.search );
		}
		const downloadFilter = ( view.filters || [] ).find(
			( f ) => f.field === 'download_title'
		);
		if ( downloadFilter && downloadFilter.value ) {
			params.set( 'download_id', String( downloadFilter.value ) );
		}

		const timer = setTimeout( () => {
			setLoading( true );
			setError( null );

			apiFetch( { path: `${ LOGS_ROUTE }?${ params.toString() }` } )
				.then( ( payload ) => {
					// Keep-previous-data: only replace rows on success.
					if ( Array.isArray( payload?.items ) ) {
						setRows( payload.items );
					}
					setTotal( parseInt( payload?.totalItems || 0, 10 ) );
					setTotalPages( parseInt( payload?.totalPages || 0, 10 ) );
				} )
				.catch( ( err ) => {
					setError(
						err?.message ||
							__( 'Could not load log entries.', 'isoft-fm-foundation' )
					);
				} )
				.finally( () => setLoading( false ) );
		}, 300 );

		return () => clearTimeout( timer );
	}, [ view.perPage, view.page, view.search, view.filters ] );

	const fields = useMemo(
		() => [
			{
				id:            'downloaded_at',
				label:         __( 'Date', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => formatDate( item.downloaded_at ),
			},
			{
				id:            'download_title',
				label:         __( 'Download', 'isoft-fm-foundation' ),
				enableSorting: false,
				getValue:      ( { item } ) => parseInt( item.download_id, 10 ) || 0,
				elements:      [
					{ label: __( '— All downloads —', 'isoft-fm-foundation' ), value: 0 },
					...downloads.map( ( d ) => ( {
						label: d.title,
						value: parseInt( d.id, 10 ),
					} ) ),
				],
				filterBy: {
					operators: [ 'is' ],
				},
				render: ( { item } ) => {
					const title = item.download_title || __( '(deleted)', 'isoft-fm-foundation' );
					if ( item.download_id && item.download_title ) {
						const url = `${ window.location.origin }/wp-admin/post.php?post=${ item.download_id }&action=edit`;
						return <a href={ url }>{ title }</a>;
					}
					return <em>{ title }</em>;
				},
			},
			{
				id:            'file_name',
				label:         __( 'File', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => item.file_name || '—',
			},
			{
				id:            'user_id',
				label:         __( 'User', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => {
					if ( ! item.user_id ) {
						return <em>{ __( 'Guest', 'isoft-fm-foundation' ) }</em>;
					}
					const label = item.user_login || `#${ item.user_id }`;
					const url   = `${ window.location.origin }/wp-admin/user-edit.php?user_id=${ item.user_id }`;
					return <a href={ url }>{ label }</a>;
				},
			},
			{
				id:            'user_ip',
				label:         __( 'IP', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => (
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

	return (
		<div className="isoft-fmf-dataviews-table">
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

			<div style={ { marginTop: '16px' } }>
				<DataViews
					data={ rows }
					view={ view }
					onChangeView={ setView }
					fields={ fields }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => String( item.id ) }
					isLoading={ loading }
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
						<input type="hidden" name="_wpnonce" value={ purgeNonce } />
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

export default LogSection;
