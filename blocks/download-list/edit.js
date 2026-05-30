/**
 * isoft-fm-foundation/download-list — Block editor component.
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
	const { category, includeSubcategories, tag, limit, orderby, order, layout, showSearch } = attributes;
	const blockProps = useBlockProps();

	// Fetch categories from the WP data store (hits /wp/v2/isfm_category)
	const categories = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'isfm_category', {
				per_page: -1,
				orderby: 'name',
				order: 'asc',
				_fields: 'id,name,parent',
			} ),
		[]
	);

	// Fetch tags
	const tags = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'isfm_tag', {
				per_page: 100,
				orderby: 'name',
				order: 'asc',
				_fields: 'id,name',
			} ),
		[]
	);

	const categoryOptions = [
		{ label: __( '— All categories —', 'isoft-fm-foundation' ), value: 0 },
		...( categories ?? [] ).map( ( cat ) => ( {
			label: ( cat.parent ? '— ' : '' ) + cat.name,
			value: cat.id,
		} ) ),
	];

	const tagOptions = [
		{ label: __( '— All tags —', 'isoft-fm-foundation' ), value: 0 },
		...( tags ?? [] ).map( ( t ) => ( { label: t.name, value: t.id } ) ),
	];

	const isLoading = ! categories || ! tags;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter', 'isoft-fm-foundation' ) }>
					{ isLoading ? (
						<Spinner />
					) : (
						<>
							<SelectControl
								label={ __( 'Category', 'isoft-fm-foundation' ) }
								value={ category }
								options={ categoryOptions }
								onChange={ ( val ) => setAttributes( { category: Number( val ) } ) }
							/>
							<ToggleControl
								label={ __( 'Include subcategories', 'isoft-fm-foundation' ) }
								help={ __( 'When on, downloads from every descendant category are listed too.', 'isoft-fm-foundation' ) }
								checked={ includeSubcategories }
								onChange={ ( val ) => setAttributes( { includeSubcategories: val } ) }
								disabled={ ! category }
							/>
							<SelectControl
								label={ __( 'Tag', 'isoft-fm-foundation' ) }
								value={ tag }
								options={ tagOptions }
								onChange={ ( val ) => setAttributes( { tag: Number( val ) } ) }
							/>
						</>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Display', 'isoft-fm-foundation' ) }>
					<RangeControl
						label={ __( 'Number of items', 'isoft-fm-foundation' ) }
						value={ limit }
						onChange={ ( val ) => setAttributes( { limit: val } ) }
						min={ 1 }
						max={ 50 }
					/>
					<SelectControl
						label={ __( 'Layout', 'isoft-fm-foundation' ) }
						value={ layout }
						options={ [
							{ label: __( 'List', 'isoft-fm-foundation' ),  value: 'list' },
							{ label: __( 'Grid', 'isoft-fm-foundation' ),  value: 'grid' },
							{ label: __( 'Table', 'isoft-fm-foundation' ), value: 'table' },
						] }
						onChange={ ( val ) => setAttributes( { layout: val } ) }
					/>
					<SelectControl
						label={ __( 'Order by', 'isoft-fm-foundation' ) }
						value={ orderby }
						options={ [
							{ label: __( 'Date', 'isoft-fm-foundation' ),           value: 'date' },
							{ label: __( 'Title', 'isoft-fm-foundation' ),          value: 'title' },
							{ label: __( 'Download count', 'isoft-fm-foundation' ), value: 'download_count' },
						] }
						onChange={ ( val ) => setAttributes( { orderby: val } ) }
					/>
					<SelectControl
						label={ __( 'Order', 'isoft-fm-foundation' ) }
						value={ order }
						options={ [
							{ label: __( 'Newest first', 'isoft-fm-foundation' ),  value: 'DESC' },
							{ label: __( 'Oldest first', 'isoft-fm-foundation' ), value: 'ASC' },
						] }
						onChange={ ( val ) => setAttributes( { order: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show search bar', 'isoft-fm-foundation' ) }
						checked={ showSearch }
						onChange={ ( val ) => setAttributes( { showSearch: val } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<Placeholder
					icon="download"
					label={ __( 'Download List', 'isoft-fm-foundation' ) }
					instructions={ __(
						'Displays a live list of downloads. Configure filters and layout in the sidebar. The preview renders on the frontend.',
						'isoft-fm-foundation'
					) }
				>
					{ category > 0 && (
						<p style={ { margin: 0, fontSize: '12px', opacity: 0.8 } }>
							{ categoryOptions.find( ( o ) => o.value === category )?.label }
							{ tag > 0 && ' · ' + tagOptions.find( ( o ) => o.value === tag )?.label }
						</p>
					) }
				</Placeholder>
			</div>
		</>
	);
}
