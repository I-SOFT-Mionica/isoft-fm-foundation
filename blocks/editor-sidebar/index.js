/**
 * Editor sidebar plugin for the isoft_fmf_file post type.
 *
 * 0.12.1 scope (this entry):
 *   - Hide the standard taxonomy-panel-isoft_fmf_category (the multi-
 *     checkbox panel that drove the 0.11.0 known-issue bug).
 *   - Register a single-select Category panel in its place. The filesystem
 *     layer (class-category-folders.php) only honors the first assignment,
 *     so the UI now enforces what the data layer was already doing.
 *
 * Later sub-PRs in 0.12.1 will add Files / Version-License / Stats panels
 * and move access-role into PluginPostStatusInfo. Until then, the existing
 * PHP meta boxes still render below the editor canvas as collapsible
 * "Additional fields" panels — no functionality is lost during the phase.
 *
 * Panel hiding strategy (defense in depth):
 *   1. PHP: register_taxonomy meta_box_cb=false suppresses the classic-
 *      editor meta box outright.
 *   2. JS: domReady fires removeEditorPanel BEFORE the React tree mounts —
 *      earliest possible call.
 *   3. JS: subscribe to core/editor watches for re-registration (some
 *      Gutenberg paths re-add the panel on post-type changes) and re-
 *      fires removeEditorPanel idempotently.
 *   4. JS: useEffect inside CategoryPanel as a third trigger on mount.
 *   The earlier useEffect-only approach raced the panel's initial render
 *   on first page load — user saw the multi-checkbox panel briefly and
 *   then permanently. The domReady call lands before the panel ever
 *   renders, which is what made the difference.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { SelectControl, Spinner } from '@wordpress/components';
import { useSelect, useDispatch, dispatch, subscribe, select } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editPostStore } from '@wordpress/edit-post';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import domReady from '@wordpress/dom-ready';

const POST_TYPE  = 'isoft_fmf_file';
const TAXONOMY   = 'isoft_fmf_category';
const PANEL_NAME = `taxonomy-panel-${ TAXONOMY }`;

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
// this, the user sees the multi-checkbox panel briefly before the React
// useEffect inside the component would have had a chance to hide it.
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

// Scope the registration to the post type so the panel doesn't leak onto
// regular posts / pages / other CPTs that happen to use the block editor.
const ScopedSidebar = () => {
	const currentPostType = useSelect(
		( s ) => s( 'core/editor' ).getCurrentPostType(),
		[]
	);
	if ( currentPostType !== POST_TYPE ) {
		return null;
	}
	return <CategoryPanel />;
};

registerPlugin( 'isoft-fmf-sidebar', {
	render: ScopedSidebar,
} );
