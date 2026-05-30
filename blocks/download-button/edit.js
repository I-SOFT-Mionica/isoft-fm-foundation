/**
 * isoft-fm-foundation/download-button — Block editor component.
 *
 * Shows a live search UI when no download is selected.
 * Once selected, renders a compact preview card with a "Change" button.
 * On the front end, render.php outputs the full download card.
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Spinner, TextControl, SelectControl, Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const DEBOUNCE_MS = 300;

export default function Edit( { attributes, setAttributes } ) {
	const { downloadId } = attributes;
	const blockProps = useBlockProps();

	// Search state
	const [ query, setQuery ]               = useState( '' );
	const [ categoryFilter, setCategoryFilter ] = useState( 0 );
	const [ results, setResults ]           = useState( null ); // null = loading
	const debounceRef = useRef( null );

	// Load category list once for the filter dropdown.
	const categories = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'isfm_category', {
				per_page: 100,
				_fields: 'id,name',
				orderby: 'name',
				order: 'asc',
			} ),
		[]
	);

	// Resolve the title for an already-saved downloadId.
	const selectedPost = useSelect(
		( select ) =>
			downloadId
				? select( coreStore ).getEntityRecord( 'postType', 'isfm_file', downloadId )
				: null,
		[ downloadId ]
	);
	const selectedTitle =
		selectedPost?.title?.rendered ||
		selectedPost?.title?.raw ||
		( downloadId ? `#${ downloadId }` : '' );

	// Debounced search — fires on query or category change.
	useEffect( () => {
		if ( downloadId ) return; // don't search when already selected
		setResults( null );
		if ( debounceRef.current ) clearTimeout( debounceRef.current );
		debounceRef.current = setTimeout( () => {
			const params = new URLSearchParams( { per_page: '20' } );
			if ( query ) {
				params.set( 'search', query );
			} else {
				params.set( 'orderby', 'date' );
				params.set( 'order', 'DESC' );
			}
			if ( categoryFilter ) params.set( 'category', String( categoryFilter ) );
			apiFetch( { path: `/isoft-fm-foundation/v1/downloads?${ params }` } )
				.then( ( data ) => setResults( data ?? [] ) )
				.catch( () => setResults( [] ) );
		}, DEBOUNCE_MS );
		return () => {
			if ( debounceRef.current ) clearTimeout( debounceRef.current );
		};
	}, [ query, categoryFilter, downloadId ] );

	const categoryOptions = [
		{ label: __( 'All categories', 'isoft-fm-foundation' ), value: 0 },
		...( categories ?? [] ).map( ( c ) => ( { label: c.name, value: c.id } ) ),
	];

	// ── SELECTED STATE ──────────────────────────────────────────────────────────
	if ( downloadId ) {
		return (
			<div { ...blockProps }>
				<div style={ {
					display: 'flex', alignItems: 'center', gap: '10px',
					padding: '10px 12px',
					background: '#f0f6fc', border: '1px solid #c8d7e1', borderRadius: '4px',
				} }>
					<span
						className="dashicons dashicons-download"
						style={ { color: '#0a7ee3', fontSize: '20px', lineHeight: '20px', flexShrink: 0 } }
					/>
					<span style={ { flex: 1, fontSize: '13px', minWidth: 0 } }>
						<strong>{ selectedTitle || <Spinner style={ { margin: 0 } } /> }</strong>
						<span style={ { marginLeft: '8px', color: '#888', fontSize: '11px' } }>
							{ __( 'download card', 'isoft-fm-foundation' ) }
						</span>
					</span>
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => {
							setAttributes( { downloadId: 0 } );
							setResults( null );
							setQuery( '' );
							setCategoryFilter( 0 );
						} }
					>
						{ __( 'Change', 'isoft-fm-foundation' ) }
					</Button>
				</div>
			</div>
		);
	}

	// ── SEARCH / PICKER STATE ───────────────────────────────────────────────────
	return (
		<div { ...blockProps }>
			<div style={ {
				border: '1px solid #ccd0d4', borderRadius: '4px',
				background: '#fff', overflow: 'hidden',
				fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
			} }>

				{/* ── Header ── */}
				<div style={ {
					background: '#f6f7f7', borderBottom: '1px solid #ddd',
					padding: '8px 12px', display: 'flex', alignItems: 'center', gap: '8px',
				} }>
					<span className="dashicons dashicons-download" style={ { color: '#1e1e1e' } } />
					<strong style={ { fontSize: '13px' } }>
						{ __( 'Insert Download Entry [iD]', 'isoft-fm-foundation' ) }
					</strong>
				</div>

				{/* ── Filters ── */}
				<div style={ {
					padding: '8px 12px', display: 'flex', gap: '8px',
					borderBottom: '1px solid #eee', alignItems: 'flex-end',
				} }>
					<div style={ { flex: 2 } }>
						<TextControl
							label={ __( 'Search', 'isoft-fm-foundation' ) }
							hideLabelFromVision
							placeholder={ __( 'Search downloads…', 'isoft-fm-foundation' ) }
							value={ query }
							onChange={ ( val ) => setQuery( val ) }
							// eslint-disable-next-line jsx-a11y/no-autofocus
							autoFocus
						/>
					</div>
					<div style={ { flex: 1 } }>
						{ ! categories ? (
							<Spinner />
						) : (
							<SelectControl
								label={ __( 'Category', 'isoft-fm-foundation' ) }
								hideLabelFromVision
								value={ categoryFilter }
								options={ categoryOptions }
								onChange={ ( val ) => setCategoryFilter( Number( val ) ) }
							/>
						) }
					</div>
				</div>

				{/* ── Results ── */}
				<div style={ { maxHeight: '260px', overflowY: 'auto' } }>
					{ results === null ? (
						<div style={ { padding: '24px', textAlign: 'center' } }>
							<Spinner />
						</div>
					) : results.length === 0 ? (
						<p style={ { padding: '12px', margin: 0, color: '#888', fontSize: '13px' } }>
							{ query
								? __( 'No downloads found.', 'isoft-fm-foundation' )
								: __( 'No downloads yet.', 'isoft-fm-foundation' ) }
						</p>
					) : (
						<ul style={ { margin: 0, padding: 0, listStyle: 'none' } }>
							{ results.map( ( item ) => (
								<li key={ item.id }>
									<button
										type="button"
										onClick={ () => setAttributes( { downloadId: item.id } ) }
										style={ {
											display: 'flex', alignItems: 'center', gap: '10px',
											width: '100%', padding: '7px 12px',
											background: 'transparent', border: 0,
											borderBottom: '1px solid #f0f0f0',
											cursor: 'pointer', textAlign: 'left', fontSize: '13px',
										} }
										onMouseEnter={ ( e ) => ( e.currentTarget.style.background = '#f0f6fc' ) }
										onMouseLeave={ ( e ) => ( e.currentTarget.style.background = 'transparent' ) }
									>
										<span
											className="dashicons dashicons-media-default"
											style={ { color: '#0a7ee3', flexShrink: 0, fontSize: '16px', lineHeight: '16px' } }
										/>
										<span style={ { flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
											{ item.title }
										</span>
										{ item.categories && item.categories.length > 0 && (
											<span style={ {
												fontSize: '11px', color: '#888',
												flexShrink: 0, maxWidth: '120px',
												overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
											} }>
												{ item.categories[ 0 ] }
											</span>
										) }
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>

				{/* ── Footer hint ── */}
				<div style={ {
					padding: '5px 12px', borderTop: '1px solid #eee',
					fontSize: '11px', color: '#aaa',
				} }>
					{ query
						? __( 'Click a result to insert.', 'isoft-fm-foundation' )
						: __( 'Showing most recent — type to search by title.', 'isoft-fm-foundation' ) }
				</div>
			</div>
		</div>
	);
}
