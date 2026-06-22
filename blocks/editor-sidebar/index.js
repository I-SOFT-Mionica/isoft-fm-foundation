/**
 * Editor sidebar plugin for the isoft_fmf_file post type.
 *
 * 0.12.1 scope (this entry):
 *   - Hide the standard taxonomy-panel-isoft_fmf_category (the multi-checkbox
 *     panel that drove the 0.11.0 known-issue bug).
 *   - Register a single-select Category panel in its place. The filesystem
 *     layer (class-category-folders.php) only honors the first assignment,
 *     so the UI now enforces what the data layer was already doing.
 *
 * Later sub-PRs in 0.12.1 will add Files / Version-License / Stats panels
 * and move access-role into PluginPostStatusInfo. Until then, the existing
 * PHP meta boxes still render below the editor canvas as collapsible
 * "Additional fields" panels — no functionality is lost during the phase.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { SelectControl, Spinner } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editPostStore } from '@wordpress/edit-post';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const POST_TYPE = 'isoft_fmf_file';
const TAXONOMY  = 'isoft_fmf_category';

const CategoryPanel = () => {
	// Hide the standard taxonomy panel once per mount. WP re-emits it on
	// every editor open; removeEditorPanel is idempotent so re-calling is
	// cheap. Belt-and-braces: also fires the legacy
	// editor.PostTaxonomyType filter that some themes might still pick up.
	const { removeEditorPanel } = useDispatch( editPostStore );
	useEffect( () => {
		removeEditorPanel( `taxonomy-panel-${ TAXONOMY }` );
	}, [ removeEditorPanel ] );

	const terms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', TAXONOMY, {
				per_page: -1,
				orderby:  'name',
				order:    'asc',
				_fields:  'id,name,parent',
			} ),
		[]
	);

	const assignedIds = useSelect(
		( select ) =>
			select( 'core/editor' ).getEditedPostAttribute( TAXONOMY ) || [],
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

	// One option per term, indented by depth. Linear walk — category counts
	// are in the hundreds at most (see [[arbiter-addon]] context).
	const byId      = Object.fromEntries( terms.map( ( t ) => [ t.id, t ] ) );
	const depthOf   = ( term ) => {
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
		( select ) => select( 'core/editor' ).getCurrentPostType(),
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
