/**
 * Broken Links section — DataViews table + per-row Recover modal driven
 * by /broken-links/{id}/probe + /recover + /reupload.
 *
 * Ported from blocks/broken-links-page/index.js in 0.12.6 when the 5
 * per-page bundles collapsed into a single admin-shell entry. Content
 * is unchanged aside from:
 *   - Renamed BrokenLinksApp -> BrokenLinksSection.
 *   - Removed self-mount code at the bottom (shell owns mounting).
 *   - Bootstrap payload arrives as one `bootstrap` prop (initialTotal +
 *     initialPages destructured inside).
 *   - formatDate imported from ../util/format instead of defined inline.
 *
 * The integrity-check panel remains a PHP fragment rendered above the
 * shell mount by admin-shell-mount.php — server-side lock state +
 * admin-post.php action, not a good fit for REST round-tripping.
 */

import { DataViews } from '@wordpress/dataviews';
import {
	Notice,
	Spinner,
	Button,
	Modal,
} from '@wordpress/components';
import { useState, useEffect, useMemo, useCallback, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { formatDate } from '../util/format';

const LIST_ROUTE = '/isoft-fm-foundation/v1/broken-links';

const DEFAULT_VIEW = {
	type:    'table',
	perPage: 25,
	page:    1,
	sort:    {
		field:     'missing_since',
		direction: 'desc',
	},
	search:  '',
	filters: [],
	fields:  [
		'download_title',
		'file_name',
		'expected_path',
		'missing_since',
	],
	density: 'compact',
	layout:  {},
};

const RecoverModal = ( { item, onClose, onSuccess } ) => {
	const [ probe, setProbe ]       = useState( null );
	const [ probeErr, setProbeErr ] = useState( null );
	const [ busy, setBusy ]         = useState( false );
	const [ actionErr, setActionErr ] = useState( null );

	useEffect( () => {
		if ( ! item ) {
			return;
		}
		apiFetch( { path: `${ LIST_ROUTE }/${ item.id }/probe` } )
			.then( ( data ) => setProbe( data ) )
			.catch( ( err ) => setProbeErr(
				err?.message || __( 'Could not probe file.', 'isoft-fm-foundation' )
			) );
	}, [ item ] );

	const runAction = useCallback( ( action ) => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		setActionErr( null );
		apiFetch( {
			path:   `${ LIST_ROUTE }/${ item.id }/recover`,
			method: 'POST',
			data:   { action },
		} )
			.then( ( res ) => {
				onSuccess( res?.message || __( 'Done.', 'isoft-fm-foundation' ) );
			} )
			.catch( ( err ) => {
				setActionErr(
					err?.message || __( 'Action failed.', 'isoft-fm-foundation' )
				);
			} )
			.finally( () => setBusy( false ) );
	}, [ busy, item, onSuccess ] );

	const handleReupload = useCallback( ( file ) => {
		if ( busy || ! file ) {
			return;
		}
		setBusy( true );
		setActionErr( null );
		const fd = new FormData();
		fd.append( 'replacement', file );
		apiFetch( {
			path:   `${ LIST_ROUTE }/${ item.id }/reupload`,
			method: 'POST',
			body:   fd,
		} )
			.then( ( res ) => {
				onSuccess( res?.message || __( 'File reuploaded.', 'isoft-fm-foundation' ) );
			} )
			.catch( ( err ) => {
				setActionErr(
					err?.message || __( 'Reupload failed.', 'isoft-fm-foundation' )
				);
			} )
			.finally( () => setBusy( false ) );
	}, [ busy, item, onSuccess ] );

	if ( ! item ) {
		return null;
	}

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: file name */
				__( 'Recover File: %s', 'isoft-fm-foundation' ),
				item.file_name
			) }
			onRequestClose={ busy ? () => {} : onClose }
			size="medium"
		>
			{ probeErr && (
				<Notice status="error" isDismissible={ false }>
					{ probeErr }
				</Notice>
			) }

			{ ! probe && ! probeErr && (
				<p style={ { textAlign: 'center' } }>
					<Spinner />
				</p>
			) }

			{ probe && (
				<>
					<div
						style={ {
							background:   '#f6f7f7',
							padding:      '12px',
							borderRadius: '4px',
							marginBottom: '16px',
							fontSize:     '13px',
						} }
					>
						<p style={ { margin: '0 0 4px' } }>
							<strong>{ __( 'Download:', 'isoft-fm-foundation' ) }</strong>{ ' ' }
							{ probe.download_title || __( '(deleted)', 'isoft-fm-foundation' ) }
						</p>
						<p style={ { margin: '0 0 4px' } }>
							<strong>{ __( 'Expected folder:', 'isoft-fm-foundation' ) }</strong>{ ' ' }
							<code style={ { fontSize: '12px' } }>
								{ probe.expected_folder || '—' }
							</code>
						</p>
						{ probe.candidate_found && (
							<p style={ { margin: '0' } }>
								<strong>{ __( 'Found in:', 'isoft-fm-foundation' ) }</strong>{ ' ' }
								<code style={ { fontSize: '12px' } }>
									{ probe.candidate_folder || __( '(root)', 'isoft-fm-foundation' ) }
								</code>
							</p>
						) }
					</div>

					{ actionErr && (
						<Notice
							status="error"
							isDismissible
							onRemove={ () => setActionErr( null ) }
						>
							{ actionErr }
						</Notice>
					) }

					{ probe.is_cross_cat && (
						<div style={ { marginBottom: '16px' } }>
							<p style={ { marginTop: 0 } }>
								<strong>
									{ __(
										'File found in a different category folder. Pick how to resolve:',
										'isoft-fm-foundation'
									) }
								</strong>
							</p>
							<div style={ { display: 'flex', gap: '8px', flexWrap: 'wrap' } }>
								<Button
									variant="primary"
									onClick={ () => runAction( 'move_back' ) }
									disabled={ busy }
									__next40pxDefaultSize
								>
									{ __( '1. Move file back', 'isoft-fm-foundation' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ () => runAction( 'reassign' ) }
									disabled={ busy }
									__next40pxDefaultSize
								>
									{ __( '2. Reassign download', 'isoft-fm-foundation' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ () => runAction( 'split' ) }
									disabled={ busy }
									__next40pxDefaultSize
								>
									{ __( '3. Split into new download', 'isoft-fm-foundation' ) }
								</Button>
							</div>
						</div>
					) }

					{ ! probe.candidate_found && (
						<p style={ { color: '#646970' } }>
							{ __(
								'File could not be located in any category folder. Reupload a replacement or detach the file.',
								'isoft-fm-foundation'
							) }
						</p>
					) }

					<hr style={ { margin: '16px 0' } } />

					<div style={ { display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' } }>
						<label
							className="components-button is-secondary"
							style={ { cursor: busy ? 'not-allowed' : 'pointer' } }
						>
							{ __( 'Reupload…', 'isoft-fm-foundation' ) }
							<input
								type="file"
								hidden
								disabled={ busy }
								onChange={ ( e ) => handleReupload( e.target.files?.[ 0 ] ) }
							/>
						</label>
						<Button
							variant="secondary"
							isDestructive
							onClick={ () => runAction( 'detach' ) }
							disabled={ busy }
							__next40pxDefaultSize
						>
							{ __( 'Detach file', 'isoft-fm-foundation' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ onClose }
							disabled={ busy }
							__next40pxDefaultSize
							style={ { marginLeft: 'auto' } }
						>
							{ __( 'Close', 'isoft-fm-foundation' ) }
						</Button>
					</div>

					{ busy && (
						<p style={ { textAlign: 'center', marginTop: '12px' } }>
							<Spinner />
						</p>
					) }
				</>
			) }
		</Modal>
	);
};

const BrokenLinksSection = ( { bootstrap } ) => {
	const {
		initialTotal = 0,
		initialPages = 0,
		initialItems = null,
	} = bootstrap || {};

	const [ view, setView ]             = useState( DEFAULT_VIEW );
	const [ rows, setRows ]             = useState( Array.isArray( initialItems ) ? initialItems : [] );
	const [ total, setTotal ]           = useState( initialTotal );
	const [ totalPages, setTotalPages ] = useState( initialPages );
	const [ loading, setLoading ]       = useState( ! Array.isArray( initialItems ) );
	const [ error, setError ]           = useState( null );
	const [ recovering, setRecovering ] = useState( null );
	const [ notice, setNotice ]         = useState( null );

	// Debounce + keep-previous-data on view changes. See log.js for the
	// full rationale.
	const skipFirstFetch = useRef( Array.isArray( initialItems ) );

	const fetchList = useCallback( () => {
		const params = new URLSearchParams();
		params.set( 'per_page', String( view.perPage || 25 ) );
		params.set( 'page', String( view.page || 1 ) );
		setLoading( true );
		setError( null );
		return apiFetch( { path: `${ LIST_ROUTE }?${ params.toString() }` } )
			.then( ( payload ) => {
				if ( Array.isArray( payload?.items ) ) {
					setRows( payload.items );
				}
				setTotal( parseInt( payload?.totalItems || 0, 10 ) );
				setTotalPages( parseInt( payload?.totalPages || 0, 10 ) );
			} )
			.catch( ( err ) => {
				setError(
					err?.message ||
						__( 'Could not load broken-links list.', 'isoft-fm-foundation' )
				);
			} )
			.finally( () => setLoading( false ) );
	}, [ view.perPage, view.page ] );

	useEffect( () => {
		if ( skipFirstFetch.current ) {
			skipFirstFetch.current = false;
			return;
		}
		const timer = setTimeout( fetchList, 300 );
		return () => clearTimeout( timer );
	}, [ fetchList ] );

	const onRecoverSuccess = useCallback( ( message ) => {
		setRecovering( null );
		setNotice( { status: 'success', message } );
		fetchList();
	}, [ fetchList ] );

	const fields = useMemo(
		() => [
			{
				id:            'download_title',
				label:         __( 'Download', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => {
					const url = `${ window.location.origin }/wp-admin/post.php?post=${ item.download_id }&action=edit`;
					return item.download_id
						? <a href={ url }>{ item.download_title || `#${ item.download_id }` }</a>
						: <em>{ __( '(deleted)', 'isoft-fm-foundation' ) }</em>;
				},
			},
			{
				id:            'file_name',
				label:         __( 'File', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => (
					<code style={ { fontSize: '12px' } }>{ item.file_name || '—' }</code>
				),
			},
			{
				id:            'expected_path',
				label:         __( 'Expected Path', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => (
					<code style={ { fontSize: '11px', color: '#646970' } }>
						{ item.file_path || '—' }
					</code>
				),
			},
			{
				id:            'missing_since',
				label:         __( 'Missing Since', 'isoft-fm-foundation' ),
				enableSorting: false,
				render:        ( { item } ) => formatDate( item.missing_since ),
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id:        'recover',
				label:     __( 'Recover', 'isoft-fm-foundation' ),
				isPrimary: true,
				callback:  ( items ) => {
					if ( items?.[ 0 ] ) {
						setRecovering( items[ 0 ] );
					}
				},
			},
		],
		[]
	);

	const paginationInfo = useMemo(
		() => ( { totalItems: total, totalPages } ),
		[ total, totalPages ]
	);

	return (
		<div className="isoft-fmf-dataviews-table">
			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<p
				style={ {
					margin:   '12px 0 8px',
					color:    '#646970',
					fontSize: '13px',
				} }
			>
				{ loading
					? __( 'Loading broken-files list…', 'isoft-fm-foundation' )
					: sprintf(
						/* translators: %d: number of broken files */
						_n(
							'%d broken file.',
							'%d broken files.',
							total,
							'isoft-fm-foundation'
						),
						total
					) }
			</p>

			{ ! loading && rows.length === 0 && ! error && (
				<Notice status="success" isDismissible={ false }>
					{ __(
						'All files are present. Nothing to recover.',
						'isoft-fm-foundation'
					) }
				</Notice>
			) }

			{ rows.length > 0 && (
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
						actions={ actions }
					/>
				</div>
			) }

			{ recovering && (
				<RecoverModal
					item={ recovering }
					onClose={ () => setRecovering( null ) }
					onSuccess={ onRecoverSuccess }
				/>
			) }
		</div>
	);
};

export default BrokenLinksSection;
