/**
 * Editor sidebar plugin for the isoft_fmf_file post type.
 *
 * 0.12.1 scope (this entry):
 *   - Hide the standard taxonomy-panel-isoft_fmf_category (the multi-
 *     checkbox panel that drove the 0.11.0 known-issue bug).
 *   - Register a single-select Category panel in its place.
 *   - Register an Access role <SelectControl> inside the Status &
 *     visibility panel via PluginPostStatusInfo. Replaces the classic-
 *     editor post_submitbox_misc_actions hook that doesn't fire in the
 *     block editor.
 *   - Register a compact Files summary panel that reports count, type
 *     breakdown, and total size. "Manage files" button scrolls to the
 *     existing Files meta box below the canvas — the meta box stays as
 *     the rich UI through 0.12.5 demolition; the sidebar is the
 *     at-a-glance widget.
 *
 * Later sub-PRs in 0.12.1 will add Version-License / Stats panels.
 * Until then, those meta boxes still render below the editor canvas as
 * collapsible "Additional fields" panels.
 *
 * Panel hiding strategy (defense in depth):
 *   1. PHP: register_taxonomy meta_box_cb=false suppresses the classic-
 *      editor meta box outright; show_in_quick_edit=false suppresses
 *      the WP_Posts_List_Table inline-edit checkbox panel.
 *   2. JS: domReady fires removeEditorPanel BEFORE the React tree
 *      mounts — earliest possible call.
 *   3. JS: subscribe to core/editor watches for re-registration (some
 *      Gutenberg paths re-add the panel on post-type changes) and re-
 *      fires removeEditorPanel idempotently.
 *   4. JS: useEffect inside CategoryPanel as a third trigger on mount.
 *   The earlier useEffect-only approach raced the panel's initial
 *   render. The domReady call lands before the panel ever renders,
 *   which is what made the difference.
 */

import { registerPlugin } from '@wordpress/plugins';
// PluginDocumentSettingPanel + PluginPostStatusInfo moved from
// @wordpress/edit-post to @wordpress/editor in WP 6.5 (Gutenberg
// 17.8). Old import paths still re-export through 6.8 but are
// deprecated; in newer Gutenberg builds they return null and render
// nothing. Import from the new canonical location for our 6.7+
// minimum.
import { PluginDocumentSettingPanel, PluginPostStatusInfo } from '@wordpress/editor';
import {
	Button,
	CheckboxControl,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useSelect, useDispatch, dispatch, subscribe, select } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editPostStore } from '@wordpress/edit-post';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';

const POST_TYPE       = 'isoft_fmf_file';
const TAXONOMY        = 'isoft_fmf_category';
const PANEL_NAME      = `taxonomy-panel-${ TAXONOMY }`;
const ACCESS_META_KEY = '_isoft_fmf_access_role';

// Idempotent — removeEditorPanel just flips a preference flag. Safe to
// re-call across mount cycles, post-type changes, and editor reloads.
const hideStandardCategoryPanel = () => {
	const editPost = dispatch( editPostStore );
	if ( editPost && typeof editPost.removeEditorPanel === 'function' ) {
		editPost.removeEditorPanel( PANEL_NAME );
	}
};

// Earliest hook into the editor lifecycle — fires before React mount.
// This is what catches the standard panel's initial render; without
// this, the user sees the multi-checkbox panel briefly before the
// React useEffect inside the component would have had a chance to
// hide it.
domReady( () => {
	hideStandardCategoryPanel();

	// Belt-and-braces: some Gutenberg flows lazily register the
	// taxonomy panel after the editor's initial render. Subscribe to
	// the editor store and re-fire whenever the post type is known to
	// be ours — once is enough because removeEditorPanel persists in
	// preferences for that post type. Unsubscribe after the first hit
	// to keep things tidy.
	const unsubscribe = subscribe( () => {
		const editor = select( 'core/editor' );
		if ( editor && editor.getCurrentPostType && editor.getCurrentPostType() === POST_TYPE ) {
			hideStandardCategoryPanel();
			unsubscribe();
		}
	} );
} );

const CategoryPanel = () => {
	// Third trigger — fires after mount. Belt-and-braces with the two
	// above; cheap enough that a third call doesn't matter.
	const { removeEditorPanel } = useDispatch( editPostStore );
	useEffect( () => {
		removeEditorPanel( PANEL_NAME );
	}, [ removeEditorPanel ] );

	const terms = useSelect(
		( s ) =>
			s( coreStore ).getEntityRecords( 'taxonomy', TAXONOMY, {
				per_page: -1,
				orderby:  'name',
				order:    'asc',
				_fields:  'id,name,parent',
			} ),
		[]
	);

	const assignedIds = useSelect(
		( s ) =>
			s( 'core/editor' ).getEditedPostAttribute( TAXONOMY ) || [],
		[]
	);

	const { editPost } = useDispatch( 'core/editor' );

	if ( terms === null ) {
		return (
			<PluginDocumentSettingPanel
				name="isoft-fmf-category"
				title={ __( 'Category', 'isoft-fm-foundation' ) }
				className="isoft-fmf-category-panel"
			>
				<Spinner />
			</PluginDocumentSettingPanel>
		);
	}

	if ( ! terms.length ) {
		return (
			<PluginDocumentSettingPanel
				name="isoft-fmf-category"
				title={ __( 'Category', 'isoft-fm-foundation' ) }
				className="isoft-fmf-category-panel"
			>
				<p>
					{ __(
						'No categories yet. Create one under Downloads → Categories before assigning files.',
						'isoft-fm-foundation'
					) }
				</p>
			</PluginDocumentSettingPanel>
		);
	}

	// One option per term, indented by depth. Linear walk — category
	// counts are in the hundreds at most.
	const byId    = Object.fromEntries( terms.map( ( t ) => [ t.id, t ] ) );
	const depthOf = ( term ) => {
		let depth = 0;
		let cur   = term;
		while ( cur && cur.parent && byId[ cur.parent ] ) {
			depth += 1;
			cur = byId[ cur.parent ];
		}
		return depth;
	};

	const options = [
		{
			label: __( '— Select a category —', 'isoft-fm-foundation' ),
			value: '',
		},
		...terms.map( ( term ) => ( {
			label: `${ '— '.repeat( depthOf( term ) ) }${ term.name }`,
			value: String( term.id ),
		} ) ),
	];

	const currentId = assignedIds.length ? String( assignedIds[ 0 ] ) : '';

	const onChange = ( value ) => {
		const next = value ? [ parseInt( value, 10 ) ] : [];
		editPost( { [ TAXONOMY ]: next } );
	};

	return (
		<PluginDocumentSettingPanel
			name="isoft-fmf-category"
			title={ __( 'Category', 'isoft-fm-foundation' ) }
			className="isoft-fmf-category-panel"
		>
			<SelectControl
				label={ __( 'Category', 'isoft-fm-foundation' ) }
				hideLabelFromVision
				value={ currentId }
				options={ options }
				onChange={ onChange }
				__nextHasNoMarginBottom
			/>
			<p style={ { color: '#646970', fontSize: '12px', marginTop: '8px' } }>
				{ __(
					'Each download lives in exactly one category — categories map 1:1 to folders on disk.',
					'isoft-fm-foundation'
				) }
			</p>
		</PluginDocumentSettingPanel>
	);
};

const AccessRoleStatusInfo = () => {
	// Reads/writes via editPost on the meta object — the meta key is
	// already exposed to REST via ISOFT_FMF_Meta_Fields::register()
	// (show_in_rest: true) so the entity layer hydrates and persists
	// it. No separate REST call needed.
	const accessRole = useSelect(
		( s ) => s( 'core/editor' ).getEditedPostAttribute( 'meta' )?.[ ACCESS_META_KEY ] || 'inherit',
		[]
	);
	const { editPost } = useDispatch( 'core/editor' );

	const setAccessRole = ( value ) => {
		editPost( { meta: { [ ACCESS_META_KEY ]: value } } );
	};

	const options = [
		{ label: __( '— Inherit from category —', 'isoft-fm-foundation' ), value: 'inherit' },
		{ label: __( 'Public (everyone)', 'isoft-fm-foundation' ),         value: 'public' },
		{ label: __( 'Subscriber+', 'isoft-fm-foundation' ),               value: 'subscriber' },
		{ label: __( 'Contributor+', 'isoft-fm-foundation' ),              value: 'contributor' },
		{ label: __( 'Author+', 'isoft-fm-foundation' ),                   value: 'author' },
		{ label: __( 'Editor+', 'isoft-fm-foundation' ),                   value: 'editor' },
		{ label: __( 'Administrator only', 'isoft-fm-foundation' ),        value: 'administrator' },
	];

	return (
		<PluginPostStatusInfo className="isoft-fmf-access-status">
			<SelectControl
				label={ __( 'Access', 'isoft-fm-foundation' ) }
				value={ accessRole }
				options={ options }
				onChange={ setAccessRole }
				__nextHasNoMarginBottom
			/>
		</PluginPostStatusInfo>
	);
};

// ---------------------------------------------------------------------
// Files summary panel
// ---------------------------------------------------------------------

/** Human-readable size from a byte count. Mirrors PHP size_format(). */
const formatBytes = ( bytes ) => {
	if ( ! bytes || bytes < 0 ) {
		return '—';
	}
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let n = bytes;
	let i = 0;
	while ( n >= 1024 && i < units.length - 1 ) {
		n /= 1024;
		i += 1;
	}
	return `${ n.toFixed( n < 10 && i > 0 ? 1 : 0 ) } ${ units[ i ] }`;
};

/** DOM id of the existing Files meta box rendered by class-admin-meta-boxes. */
const FILES_METABOX_ID = 'isoft-fmf-files';

const FilesPanel = () => {
	const postId = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const [ files, setFiles ]     = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ]     = useState( null );

	const fetchFiles = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setLoading( true );
		setError( null );
		apiFetch( {
			path: `/isoft-fm-foundation/v1/downloads/${ postId }/files`,
		} )
			.then( ( rows ) => {
				setFiles( Array.isArray( rows ) ? rows : [] );
			} )
			.catch( ( err ) => {
				setError( err?.message || __( 'Could not load files.', 'isoft-fm-foundation' ) );
			} )
			.finally( () => setLoading( false ) );
	}, [ postId ] );

	useEffect( () => {
		fetchFiles();
	}, [ fetchFiles ] );

	const scrollToMetaBox = () => {
		const target = document.getElementById( FILES_METABOX_ID );
		if ( target ) {
			target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			// Briefly highlight so the user's eye lands on the right
			// section after the scroll completes. Removes itself.
			target.style.transition = 'box-shadow 1.2s ease-out';
			target.style.boxShadow  = '0 0 0 3px #2271b1';
			setTimeout( () => {
				target.style.boxShadow = '';
			}, 1200 );
		}
	};

	const renderBody = () => {
		if ( null === files && loading ) {
			return <Spinner />;
		}
		if ( error ) {
			return (
				<p style={ { color: '#b32d2e', margin: 0 } }>
					{ error }
				</p>
			);
		}
		const list      = files || [];
		const total     = list.length;
		const localList = list.filter( ( f ) => 'local' === f.file_type );
		const localCnt  = localList.length;
		const extCnt    = total - localCnt;
		const sizeSum   = localList.reduce(
			( sum, f ) => sum + ( parseInt( f.file_size, 10 ) || 0 ),
			0
		);

		if ( 0 === total ) {
			return (
				<p style={ { margin: 0, color: '#646970' } }>
					{ __( 'No files attached yet.', 'isoft-fm-foundation' ) }
				</p>
			);
		}

		const brokenCnt = list.filter( ( f ) => f.is_missing ).length;

		return (
			<div>
				<p style={ { margin: '0 0 4px', fontWeight: 600 } }>
					{ sprintf(
						/* translators: %s: total file count */
						_n( '%s file attached', '%s files attached', total, 'isoft-fm-foundation' ),
						total
					) }
				</p>
				<p style={ { margin: '0 0 4px', color: '#646970', fontSize: '12px' } }>
					{ localCnt > 0 &&
						sprintf(
							/* translators: %d: local file count */
							_n( '%d local', '%d local', localCnt, 'isoft-fm-foundation' ),
							localCnt
						) }
					{ localCnt > 0 && extCnt > 0 && ' · ' }
					{ extCnt > 0 &&
						sprintf(
							/* translators: %d: external link count */
							_n( '%d external', '%d external', extCnt, 'isoft-fm-foundation' ),
							extCnt
						) }
				</p>
				{ localCnt > 0 && (
					<p style={ { margin: '0 0 4px', color: '#646970', fontSize: '12px' } }>
						{ sprintf(
							/* translators: %s: total size, human-readable (e.g. "2.4 MB") */
							__( 'Total size: %s', 'isoft-fm-foundation' ),
							formatBytes( sizeSum )
						) }
					</p>
				) }
				{ brokenCnt > 0 && (
					<p style={ { margin: '4px 0 0', color: '#b32d2e', fontSize: '12px', fontWeight: 600 } }>
						{ sprintf(
							/* translators: %d: count of files flagged as missing on disk */
							_n(
								'%d file flagged as missing on disk',
								'%d files flagged as missing on disk',
								brokenCnt,
								'isoft-fm-foundation'
							),
							brokenCnt
						) }
					</p>
				) }
			</div>
		);
	};

	return (
		<PluginDocumentSettingPanel
			name="isoft-fmf-files-summary"
			title={ __( 'Files', 'isoft-fm-foundation' ) }
			className="isoft-fmf-files-summary-panel"
		>
			{ renderBody() }
			<div style={ { display: 'flex', gap: '8px', marginTop: '12px' } }>
				<Button
					variant="secondary"
					onClick={ scrollToMetaBox }
					__next40pxDefaultSize
				>
					{ __( 'Manage files', 'isoft-fm-foundation' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ fetchFiles }
					disabled={ loading }
					__next40pxDefaultSize
				>
					{ loading
						? __( 'Refreshing…', 'isoft-fm-foundation' )
						: __( 'Refresh', 'isoft-fm-foundation' ) }
				</Button>
			</div>
		</PluginDocumentSettingPanel>
	);
};

// ---------------------------------------------------------------------
// Version & License panel
// ---------------------------------------------------------------------

const LICENSE_INHERIT = -1;
const LICENSE_NONE    = 0;

/**
 * Reads a meta key from the edited post and returns a [value, setter]
 * pair. Setter writes through editPost on the meta object so other
 * code (autosave, save) sees the change immediately.
 */
const usePostMeta = ( metaKey, fallback = '' ) => {
	const value = useSelect(
		( s ) => {
			const meta = s( 'core/editor' ).getEditedPostAttribute( 'meta' );
			return meta && metaKey in meta ? meta[ metaKey ] : fallback;
		},
		[ metaKey ]
	);
	const { editPost } = useDispatch( 'core/editor' );
	const setValue     = useCallback(
		( next ) => editPost( { meta: { [ metaKey ]: next } } ),
		[ editPost, metaKey ]
	);
	return [ value, setValue ];
};

const VersionLicensePanel = () => {
	const [ version,        setVersion        ] = usePostMeta( '_isoft_fmf_version' );
	const [ changelog,      setChangelog      ] = usePostMeta( '_isoft_fmf_changelog' );
	const [ licenseId,      setLicenseId      ] = usePostMeta( '_isoft_fmf_license_id', LICENSE_NONE );
	const [ requireAgree,   setRequireAgree   ] = usePostMeta( '_isoft_fmf_require_agree', false );
	const [ agreeText,      setAgreeText      ] = usePostMeta( '_isoft_fmf_agree_text' );
	const [ featured,       setFeatured       ] = usePostMeta( '_isoft_fmf_featured', false );
	const [ externalOnly,   setExternalOnly   ] = usePostMeta( '_isoft_fmf_external_only', false );
	const [ authorName,     setAuthorName     ] = usePostMeta( '_isoft_fmf_author_name' );
	const [ authorUrl,      setAuthorUrl      ] = usePostMeta( '_isoft_fmf_author_url' );
	const [ datePublished,  setDatePublished  ] = usePostMeta( '_isoft_fmf_date_published' );
	// Download count is read-only here; it drives the change-warning copy.
	const downloadCount = useSelect(
		( s ) => {
			const meta = s( 'core/editor' ).getEditedPostAttribute( 'meta' );
			return parseInt( meta?._isoft_fmf_download_count || 0, 10 );
		},
		[]
	);

	const [ licenses, setLicenses ]                 = useState( null );
	const [ initialLicenseId, setInitialLicenseId ] = useState( null );

	useEffect( () => {
		if ( initialLicenseId === null ) {
			setInitialLicenseId( parseInt( licenseId, 10 ) || LICENSE_NONE );
		}
	}, [ licenseId, initialLicenseId ] );

	useEffect( () => {
		apiFetch( { path: '/isoft-fm-foundation/v1/licenses' } )
			.then( ( rows ) => setLicenses( Array.isArray( rows ) ? rows : [] ) )
			.catch( () => setLicenses( [] ) );
	}, [] );

	const licenseOptions = [
		{ label: __( '— None —', 'isoft-fm-foundation' ),                    value: String( LICENSE_NONE ) },
		{ label: __( '— Inherit from category —', 'isoft-fm-foundation' ), value: String( LICENSE_INHERIT ) },
		...( licenses || [] ).map( ( lic ) => ( {
			label: lic.title,
			value: String( lic.id ),
		} ) ),
	];

	const currentLicenseValue = String( parseInt( licenseId, 10 ) || LICENSE_NONE );
	const licenseChanged      =
		initialLicenseId !== null &&
		parseInt( currentLicenseValue, 10 ) !== initialLicenseId &&
		downloadCount > 0;

	return (
		<PluginDocumentSettingPanel
			name="isoft-fmf-version-license"
			title={ __( 'Version & License', 'isoft-fm-foundation' ) }
			className="isoft-fmf-version-license-panel"
		>
			<TextControl
				label={ __( 'Version', 'isoft-fm-foundation' ) }
				value={ version || '' }
				onChange={ setVersion }
				placeholder="1.0.0"
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<TextareaControl
				label={ __( 'Changelog', 'isoft-fm-foundation' ) }
				value={ changelog || '' }
				onChange={ setChangelog }
				help={ __( "What's new in this version.", 'isoft-fm-foundation' ) }
				rows={ 4 }
				__nextHasNoMarginBottom
			/>

			<CheckboxControl
				label={ __( 'Featured — pin to the top of listings', 'isoft-fm-foundation' ) }
				checked={ !! featured }
				onChange={ setFeatured }
				__nextHasNoMarginBottom
			/>

			<CheckboxControl
				label={ __( 'External only — hide local files on the card', 'isoft-fm-foundation' ) }
				checked={ !! externalOnly }
				onChange={ setExternalOnly }
				help={ __( 'Use when local files exist as backups but the external URL is canonical.', 'isoft-fm-foundation' ) }
				__nextHasNoMarginBottom
			/>

			{ licenses === null ? (
				<div style={ { marginTop: '12px' } }>
					<Spinner />
				</div>
			) : (
				<SelectControl
					label={ __( 'License', 'isoft-fm-foundation' ) }
					value={ currentLicenseValue }
					options={ licenseOptions }
					onChange={ ( v ) => setLicenseId( parseInt( v, 10 ) ) }
					__nextHasNoMarginBottom
				/>
			) }

			{ licenseChanged && (
				<p style={ { color: '#b32d2e', fontSize: '12px', marginTop: '8px' } }>
					<strong>{ __( 'Heads up:', 'isoft-fm-foundation' ) }</strong>{ ' ' }
					{ sprintf(
						/* translators: %d: prior download count under the original license */
						_n(
							'This file has been downloaded %d time under the previous license. Changing it affects new downloads only — recipients who already downloaded keep the original license terms (most permissive licenses are irrevocable for distributed copies).',
							'This file has been downloaded %d times under the previous license. Changing it affects new downloads only — recipients who already downloaded keep the original license terms (most permissive licenses are irrevocable for distributed copies).',
							downloadCount,
							'isoft-fm-foundation'
						),
						downloadCount
					) }
				</p>
			) }

			<CheckboxControl
				label={ __( 'Require user to agree before downloading', 'isoft-fm-foundation' ) }
				checked={ !! requireAgree }
				onChange={ setRequireAgree }
				help={ __( 'Uses the license full text, or the custom text below.', 'isoft-fm-foundation' ) }
				__nextHasNoMarginBottom
			/>

			{ !! requireAgree && (
				<TextareaControl
					label={ __( 'Agreement text', 'isoft-fm-foundation' ) }
					value={ agreeText || '' }
					onChange={ setAgreeText }
					help={ __( 'Shown in the agreement modal if no license is assigned.', 'isoft-fm-foundation' ) }
					rows={ 3 }
					__nextHasNoMarginBottom
				/>
			) }

			<TextControl
				label={ __( 'Author name', 'isoft-fm-foundation' ) }
				value={ authorName || '' }
				onChange={ setAuthorName }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<TextControl
				label={ __( 'Author URL', 'isoft-fm-foundation' ) }
				value={ authorUrl || '' }
				onChange={ setAuthorUrl }
				type="url"
				placeholder="https://…"
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<TextControl
				label={ __( 'Date published', 'isoft-fm-foundation' ) }
				value={ datePublished || '' }
				onChange={ setDatePublished }
				type="date"
				help={ __( 'Original publication date (may differ from the WP post date).', 'isoft-fm-foundation' ) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</PluginDocumentSettingPanel>
	);
};

// ---------------------------------------------------------------------
// Statistics panel
// ---------------------------------------------------------------------

const StatsPanel = () => {
	const postId = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostId(),
		[]
	);

	// Total downloads lives on post meta (already exposed to REST).
	const totalDownloads = useSelect(
		( s ) => {
			const meta = s( 'core/editor' ).getEditedPostAttribute( 'meta' );
			return parseInt( meta?._isoft_fmf_download_count || 0, 10 );
		},
		[]
	);

	// Per-file breakdown — fetch /files (same endpoint FilesPanel uses).
	// Independent fetch keeps each panel self-contained; the REST call is
	// cheap and the user opens panels rarely.
	const [ files, setFiles ]     = useState( null );
	const [ loading, setLoading ] = useState( false );

	const fetchFiles = useCallback( () => {
		if ( ! postId ) {
			return;
		}
		setLoading( true );
		apiFetch( {
			path: `/isoft-fm-foundation/v1/downloads/${ postId }/files`,
		} )
			.then( ( rows ) => setFiles( Array.isArray( rows ) ? rows : [] ) )
			.catch( () => setFiles( [] ) )
			.finally( () => setLoading( false ) );
	}, [ postId ] );

	useEffect( () => {
		fetchFiles();
	}, [ fetchFiles ] );

	const logUrl = postId
		? `${ window.location.origin }/wp-admin/edit.php?post_type=isoft_fmf_file&page=isoft-fmf-log&download_id=${ postId }`
		: '';

	return (
		<PluginDocumentSettingPanel
			name="isoft-fmf-stats"
			title={ __( 'Statistics', 'isoft-fm-foundation' ) }
			className="isoft-fmf-stats-panel"
			initialOpen={ false }
		>
			<div>
				<p style={ { margin: '0 0 4px', fontSize: '12px', color: '#646970' } }>
					{ __( 'Total downloads', 'isoft-fm-foundation' ) }
				</p>
				<p style={ { margin: '0 0 12px', fontSize: '22px', fontWeight: 600 } }>
					{ totalDownloads.toLocaleString() }
				</p>
			</div>

			{ null !== files && files.length > 0 && (
				<div style={ { marginTop: '8px' } }>
					<p style={ { margin: '0 0 4px', fontSize: '12px', color: '#646970', fontWeight: 600 } }>
						{ __( 'Per file', 'isoft-fm-foundation' ) }
					</p>
					<ul style={ { margin: 0, padding: 0, listStyle: 'none', fontSize: '12px' } }>
						{ files.map( ( f ) => (
							<li
								key={ f.id }
								style={ {
									display:      'flex',
									justifyContent: 'space-between',
									gap:           '8px',
									padding:       '4px 0',
									borderBottom:  '1px solid #f0f0f1',
								} }
							>
								<span
									style={ {
										overflow:     'hidden',
										textOverflow: 'ellipsis',
										whiteSpace:   'nowrap',
									} }
									title={ f.title }
								>
									{ f.title }
								</span>
								<span style={ { fontVariantNumeric: 'tabular-nums', color: '#1d2327' } }>
									{ ( f.download_count || 0 ).toLocaleString() }
								</span>
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ null === files && loading && (
				<div style={ { marginTop: '8px' } }>
					<Spinner />
				</div>
			) }

			{ totalDownloads > 0 && logUrl && (
				<p style={ { margin: '12px 0 0' } }>
					<a href={ logUrl }>
						{ __( 'View full download log →', 'isoft-fm-foundation' ) }
					</a>
				</p>
			) }
		</PluginDocumentSettingPanel>
	);
};

// Scope the registration to the post type so neither panel leaks onto
// regular posts / pages / other CPTs that happen to use the block
// editor.
const ScopedSidebar = () => {
	const currentPostType = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( currentPostType !== POST_TYPE ) {
		return null;
	}
	return (
		<>
			<CategoryPanel />
			<VersionLicensePanel />
			<FilesPanel />
			<StatsPanel />
			<AccessRoleStatusInfo />
		</>
	);
};

registerPlugin( 'isoft-fmf-sidebar', {
	render: ScopedSidebar,
} );
