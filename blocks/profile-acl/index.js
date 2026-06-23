/**
 * React app for the per-user category ACL on user profile screens.
 *
 * 0.12.4 scope, sub-PR 1 of 3. Replaces the legacy
 * <details>/<summary> checkbox tree rendered by
 * ISOFT_FMF_Category_ACL::render_profile_field() with a React tree
 * that:
 *   - Loads the category tree from GET /categories
 *   - Loads the user's current selection from GET /users/{id}/category-acl
 *   - Renders one CheckboxControl per term, hierarchically indented
 *   - Auto-expands branches that contain a selected node
 *   - Filters by name as you type
 *   - Saves the selection via POST /users/{id}/category-acl on Save
 *
 * Note on the plan: the phase plan called for <TreeSelect> from
 * @wordpress/components, but that component is single-select (it's
 * the parent-page picker in the block editor). Multi-category ACL
 * needs hierarchical multi-select — CheckboxControl per term with
 * depth-based indent is the right primitive here.
 *
 * Why not bundle DataViews here too: DataViews is a flat-list
 * component with sorting/filtering/pagination. The ACL UI is a
 * hierarchical tree with checkbox selection per node. Wrong tool.
 */

import {
	Notice,
	Spinner,
	Button,
	CheckboxControl,
	TextControl,
} from '@wordpress/components';
import { useState, useEffect, useMemo, useCallback, createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const CATEGORIES_ROUTE = '/isoft-fm-foundation/v1/categories';

/** Build parent->children adjacency map keyed by parent id (0 = roots). */
const buildTree = ( terms ) => {
	const byParent = {};
	for ( const t of terms ) {
		const p = parseInt( t.parent, 10 ) || 0;
		if ( ! byParent[ p ] ) {
			byParent[ p ] = [];
		}
		byParent[ p ].push( t );
	}
	return byParent;
};

/** Recursively collect every descendant id of `rootId`. */
const collectDescendants = ( byParent, rootId, out = [] ) => {
	const kids = byParent[ rootId ] || [];
	for ( const k of kids ) {
		out.push( k.id );
		collectDescendants( byParent, k.id, out );
	}
	return out;
};

/** True if any node in the subtree under `rootId` matches the lowercase filter. */
const subtreeMatches = ( byParent, byId, rootId, filterLc ) => {
	const root = byId[ rootId ];
	if ( root && root.name.toLowerCase().includes( filterLc ) ) {
		return true;
	}
	const kids = byParent[ rootId ] || [];
	for ( const k of kids ) {
		if ( subtreeMatches( byParent, byId, k.id, filterLc ) ) {
			return true;
		}
	}
	return false;
};

const TreeNode = ( { node, depth, byParent, byId, selected, onToggle, filterLc, expandedDescendantSet } ) => {
	const kids = byParent[ node.id ] || [];
	const isSelected = selected.has( node.id );

	// Filter: hide nodes whose subtree has no match.
	if ( filterLc && ! subtreeMatches( byParent, byId, node.id, filterLc ) ) {
		return null;
	}

	const expanded = expandedDescendantSet.has( node.id ) || !! filterLc;

	const containerStyle = {
		marginLeft: `${ depth * 18 }px`,
		padding:    '2px 0',
	};

	return (
		<div>
			<div style={ containerStyle }>
				{ kids.length > 0 ? (
					<details open={ expanded }>
						<summary
							style={ {
								cursor:       'pointer',
								listStyle:    'revert',
								padding:      '2px 0',
							} }
						>
							<span style={ { display: 'inline-block', marginLeft: '4px' } }>
								<CheckboxControl
									label={ node.name }
									checked={ isSelected }
									onChange={ () => onToggle( node.id ) }
									__nextHasNoMarginBottom
								/>
							</span>
						</summary>
						{ kids.map( ( child ) => (
							<TreeNode
								key={ child.id }
								node={ child }
								depth={ depth + 1 }
								byParent={ byParent }
								byId={ byId }
								selected={ selected }
								onToggle={ onToggle }
								filterLc={ filterLc }
								expandedDescendantSet={ expandedDescendantSet }
							/>
						) ) }
					</details>
				) : (
					<CheckboxControl
						label={ node.name }
						checked={ isSelected }
						onChange={ () => onToggle( node.id ) }
						__nextHasNoMarginBottom
					/>
				) }
			</div>
		</div>
	);
};

const ProfileAclApp = ( { userId, aclRoute } ) => {
	const [ terms, setTerms ]     = useState( null );
	const [ selected, setSelected ] = useState( null );  // Set<int>
	const [ initial, setInitial ]   = useState( null );  // Set<int> baseline
	const [ filter, setFilter ]     = useState( '' );
	const [ loading, setLoading ]   = useState( true );
	const [ saving, setSaving ]     = useState( false );
	const [ error, setError ]       = useState( null );
	const [ notice, setNotice ]     = useState( null );

	useEffect( () => {
		setLoading( true );
		Promise.all( [
			apiFetch( { path: `${ CATEGORIES_ROUTE }?per_page=-1` } ),
			apiFetch( { path: aclRoute } ),
		] )
			.then( ( [ termList, aclPayload ] ) => {
				setTerms( Array.isArray( termList ) ? termList : [] );
				const sel = new Set(
					( aclPayload?.selected || [] ).map( ( n ) => parseInt( n, 10 ) )
				);
				setSelected( sel );
				setInitial( new Set( sel ) );
			} )
			.catch( ( err ) => {
				setError(
					err?.message ||
						__( 'Could not load category ACL.', 'isoft-fm-foundation' )
				);
			} )
			.finally( () => setLoading( false ) );
	}, [ aclRoute ] );

	const byParent = useMemo( () => terms ? buildTree( terms ) : {}, [ terms ] );
	const byId     = useMemo( () => {
		const m = {};
		if ( terms ) {
			for ( const t of terms ) m[ t.id ] = t;
		}
		return m;
	}, [ terms ] );

	// Auto-expand any branch that contains a selected node so the user
	// can see their existing selection without clicking around.
	const expandedDescendantSet = useMemo( () => {
		const out = new Set();
		if ( ! terms || ! selected || selected.size === 0 ) {
			return out;
		}
		// Walk upward from each selected node, marking parents as expanded.
		for ( const id of selected ) {
			let curId = id;
			while ( curId && byId[ curId ] ) {
				out.add( curId );
				curId = parseInt( byId[ curId ].parent, 10 ) || 0;
			}
		}
		return out;
	}, [ terms, selected, byId ] );

	const onToggle = useCallback( ( id ) => {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );
	}, [] );

	const onSelectAllDescendants = ( rootId ) => {
		const desc = collectDescendants( byParent, rootId );
		setSelected( ( prev ) => {
			const next = new Set( prev );
			next.add( rootId );
			for ( const d of desc ) next.add( d );
			return next;
		} );
	};

	const onClearAll = () => {
		setSelected( new Set() );
	};

	const isDirty = useMemo( () => {
		if ( ! selected || ! initial ) {
			return false;
		}
		if ( selected.size !== initial.size ) {
			return true;
		}
		for ( const id of selected ) {
			if ( ! initial.has( id ) ) {
				return true;
			}
		}
		return false;
	}, [ selected, initial ] );

	const save = () => {
		if ( ! isDirty || saving ) {
			return;
		}
		setSaving( true );
		setNotice( null );
		apiFetch( {
			path:   aclRoute,
			method: 'POST',
			data:   { selected: Array.from( selected ) },
		} )
			.then( ( res ) => {
				const fresh = new Set(
					( res?.selected || [] ).map( ( n ) => parseInt( n, 10 ) )
				);
				setSelected( fresh );
				setInitial( new Set( fresh ) );
				setNotice( {
					status:  'success',
					message: __( 'Category access saved.', 'isoft-fm-foundation' ),
				} );
			} )
			.catch( ( err ) => {
				setNotice( {
					status:  'error',
					message: err?.message || __( 'Save failed.', 'isoft-fm-foundation' ),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return (
			<div style={ { padding: '12px 0' } }>
				<Spinner />
			</div>
		);
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( ! terms || terms.length === 0 ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __( 'No categories exist yet. Create categories under Downloads > Categories first.', 'isoft-fm-foundation' ) }
			</Notice>
		);
	}

	const roots         = byParent[ 0 ] || [];
	const filterLc      = filter.trim().toLowerCase();
	const selectedCount = selected.size;

	return (
		<div style={ { maxWidth: '720px' } }>
			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div style={ { marginBottom: '12px' } }>
				<TextControl
					label={ __( 'Filter categories', 'isoft-fm-foundation' ) }
					value={ filter }
					onChange={ setFilter }
					placeholder={ __( 'Type to filter…', 'isoft-fm-foundation' ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</div>

			<p
				style={ {
					margin:   '8px 0 12px',
					color:    '#646970',
					fontSize: '12px',
				} }
			>
				{ selectedCount === 0
					? __( 'No categories selected. The user will have no write access (admins are always unrestricted).', 'isoft-fm-foundation' )
					: `${ selectedCount } ${ __( 'categories selected', 'isoft-fm-foundation' ) }`
				}
			</p>

			<div
				style={ {
					border:       '1px solid #c3c4c7',
					borderRadius: '4px',
					padding:      '12px',
					maxHeight:    '480px',
					overflowY:    'auto',
					background:   '#fff',
				} }
			>
				{ roots.map( ( root ) => (
					<TreeNode
						key={ root.id }
						node={ root }
						depth={ 0 }
						byParent={ byParent }
						byId={ byId }
						selected={ selected }
						onToggle={ onToggle }
						filterLc={ filterLc }
						expandedDescendantSet={ expandedDescendantSet }
					/>
				) ) }
			</div>

			<div style={ { marginTop: '16px', display: 'flex', gap: '8px', alignItems: 'center' } }>
				<Button
					variant="primary"
					onClick={ save }
					disabled={ ! isDirty || saving }
					__next40pxDefaultSize
				>
					{ saving
						? __( 'Saving…', 'isoft-fm-foundation' )
						: __( 'Save Category Access', 'isoft-fm-foundation' ) }
				</Button>
				{ selected.size > 0 && (
					<Button
						variant="tertiary"
						onClick={ onClearAll }
						disabled={ saving }
						__next40pxDefaultSize
					>
						{ __( 'Clear all', 'isoft-fm-foundation' ) }
					</Button>
				) }
				{ isDirty && ! saving && (
					<span
						style={ {
							color:    '#646970',
							fontSize: '12px',
						} }
					>
						{ __( 'Unsaved changes', 'isoft-fm-foundation' ) }
					</span>
				) }
			</div>
		</div>
	);
};

const mountNode = document.getElementById( 'isoft-fmf-profile-acl-root' );
if ( mountNode ) {
	const userId = parseInt( mountNode.getAttribute( 'data-user-id' ) || '0', 10 );
	if ( userId > 0 ) {
		const aclRoute = `/isoft-fm-foundation/v1/users/${ userId }/category-acl`;
		if ( typeof createRoot === 'function' ) {
			createRoot( mountNode ).render( <ProfileAclApp userId={ userId } aclRoute={ aclRoute } /> );
		} else if ( typeof render === 'function' ) {
			render( <ProfileAclApp userId={ userId } aclRoute={ aclRoute } />, mountNode );
		}
	}
}
