/**
 * isoft-fm-foundation/category-grid — Block editor component.
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	ToggleControl,
	Spinner,
	Placeholder,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

export default function Edit( { attributes, setAttributes } ) {
	const { parent, columns, showCount, showDescription } = attributes;
	const blockProps = useBlockProps();

	// Top-level categories for the parent picker
	const topCategories = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'isfm_category', {
				per_page: -1,
				parent: 0,
				_fields: 'id,name',
				orderby: 'name',
				order: 'asc',
			} ),
		[]
	);

	const parentOptions = [
		{ label: __( '— Top level —', 'isoft-fm-foundation' ), value: 0 },
		...( topCategories ?? [] ).map( ( cat ) => ( {
			label: cat.name,
			value: cat.id,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Categories', 'isoft-fm-foundation' ) }>
					{ ! topCategories ? (
						<Spinner />
					) : (
						<SelectControl
							label={ __( 'Show children of', 'isoft-fm-foundation' ) }
							value={ parent }
							options={ parentOptions }
							onChange={ ( val ) => setAttributes( { parent: Number( val ) } ) }
							help={ __( 'Select a parent to show its subcategories, or leave as top level.', 'isoft-fm-foundation' ) }
						/>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Display', 'isoft-fm-foundation' ) }>
					<RangeControl
						label={ __( 'Columns', 'isoft-fm-foundation' ) }
						value={ columns }
						onChange={ ( val ) => setAttributes( { columns: val } ) }
						min={ 1 }
						max={ 4 }
					/>
					<ToggleControl
						label={ __( 'Show download count', 'isoft-fm-foundation' ) }
						checked={ showCount }
						onChange={ ( val ) => setAttributes( { showCount: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show description', 'isoft-fm-foundation' ) }
						checked={ showDescription }
						onChange={ ( val ) => setAttributes( { showDescription: val } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<Placeholder
					icon="grid-view"
					label={ __( 'Download Category Grid', 'isoft-fm-foundation' ) }
					instructions={ __(
						'Displays download categories as a grid. Configure in the sidebar. Preview renders on the frontend.',
						'isoft-fm-foundation'
					) }
				>
					<p style={ { margin: 0, fontSize: '12px', opacity: 0.8 } }>
						{ columns }
						{ ' ' }
						{ __( 'columns', 'isoft-fm-foundation' ) }
						{ parent > 0 &&
							' · ' +
								( parentOptions.find( ( o ) => o.value === parent )?.label ?? '' ) }
					</p>
				</Placeholder>
			</div>
		</>
	);
}
