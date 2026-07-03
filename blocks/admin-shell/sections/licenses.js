/**
 * Licenses section — hand-rolled wp-list-table + Modal-based CRUD.
 *
 * Ported from blocks/licenses-page/index.js in 0.12.6 when the 5
 * per-page bundles collapsed into a single admin-shell entry. Content
 * is unchanged aside from:
 *   - Renamed LicensesApp -> LicensesSection.
 *   - Removed self-mount code at the bottom (shell owns mounting).
 *   - Section renders bare content — the enclosing .wrap div is
 *     supplied by admin-shell-mount.php.
 *   - Bootstrap prop threaded in (unused for Licenses — the REST
 *     endpoint returns full state, no server-precomputed hints needed).
 */

import {
	Button,
	Modal,
	TextControl,
	TextareaControl,
	ToggleControl,
	Notice,
	Spinner,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const ROUTE = '/isoft-fm-foundation/v1/licenses';

const emptyLicense = () => ( {
	id:          0,
	title:       '',
	slug:        '',
	description: '',
	url:         '',
	full_text:   '',
	is_default:  false,
	sort_order:  0,
} );

const LicensesSection = () => {
	const [ licenses, setLicenses ]   = useState( null );
	const [ loadError, setLoadError ] = useState( null );

	const [ editing, setEditing ]     = useState( null );
	const [ saving, setSaving ]       = useState( false );
	const [ saveError, setSaveError ] = useState( null );

	const [ deleting, setDeleting ]       = useState( null );
	const [ deleteError, setDeleteError ] = useState( null );

	const [ confirmRestore, setConfirmRestore ] = useState( false );
	const [ restoring, setRestoring ]           = useState( false );

	const [ notice, setNotice ] = useState( null );

	const fetchLicenses = useCallback( () => {
		setLoadError( null );
		return apiFetch( { path: ROUTE } )
			.then( ( rows ) => {
				setLicenses( Array.isArray( rows ) ? rows : [] );
			} )
			.catch( ( err ) => {
				setLoadError(
					err?.message ||
						__( 'Could not load licenses.', 'isoft-fm-foundation' )
				);
				setLicenses( [] );
			} );
	}, [] );

	useEffect( () => {
		fetchLicenses();
	}, [ fetchLicenses ] );

	const openCreate = () => {
		setSaveError( null );
		setEditing( emptyLicense() );
	};

	const openEdit = ( license ) => {
		setSaveError( null );
		setEditing( { ...license } );
	};

	const closeModal = () => {
		setEditing( null );
		setSaveError( null );
	};

	const handleSave = () => {
		if ( ! editing ) {
			return;
		}
		if ( '' === ( editing.title || '' ).trim() ) {
			setSaveError( __( 'Title is required.', 'isoft-fm-foundation' ) );
			return;
		}
		setSaving( true );
		setSaveError( null );

		const isNew  = ! editing.id;
		const path   = isNew ? ROUTE : `${ ROUTE }/${ editing.id }`;
		const method = isNew ? 'POST' : 'PUT';

		apiFetch( {
			path,
			method,
			data: {
				title:       editing.title,
				slug:        editing.slug,
				description: editing.description,
				url:         editing.url,
				full_text:   editing.full_text,
				is_default:  !! editing.is_default,
				sort_order:  parseInt( editing.sort_order, 10 ) || 0,
			},
		} )
			.then( () => {
				setNotice( {
					status:  'success',
					message: isNew
						? __( 'License created.', 'isoft-fm-foundation' )
						: __( 'License updated.', 'isoft-fm-foundation' ),
				} );
				closeModal();
				return fetchLicenses();
			} )
			.catch( ( err ) => {
				setSaveError(
					err?.message ||
						__( 'Save failed.', 'isoft-fm-foundation' )
				);
			} )
			.finally( () => setSaving( false ) );
	};

	const handleDelete = () => {
		if ( ! deleting ) {
			return;
		}
		setDeleteError( null );
		apiFetch( {
			path:   `${ ROUTE }/${ deleting.id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setNotice( {
					status:  'success',
					message: __( 'License deleted.', 'isoft-fm-foundation' ),
				} );
				setDeleting( null );
				return fetchLicenses();
			} )
			.catch( ( err ) => {
				setDeleteError(
					err?.message ||
						__( 'Delete failed.', 'isoft-fm-foundation' )
				);
			} );
	};

	const handleRestoreSeeds = () => {
		setRestoring( true );
		apiFetch( {
			path:   `${ ROUTE }/restore-seeds`,
			method: 'POST',
		} )
			.then( ( res ) => {
				const added = parseInt( res?.restored || 0, 10 );
				setNotice( {
					status:  'success',
					message: added > 0
						? sprintf(
							/* translators: %d: number of seeded licenses installed */
							_n(
								'%d seeded license added.',
								'%d seeded licenses added.',
								added,
								'isoft-fm-foundation'
							),
							added
						)
						: __(
							'No new seeded licenses to add — all defaults already present.',
							'isoft-fm-foundation'
						),
				} );
				setConfirmRestore( false );
				return fetchLicenses();
			} )
			.catch( ( err ) => {
				setNotice( {
					status: 'error',
					message:
						err?.message ||
						__( 'Restore failed.', 'isoft-fm-foundation' ),
				} );
				setConfirmRestore( false );
			} )
			.finally( () => setRestoring( false ) );
	};

	return (
		<div className="isoft-fmf-licenses-app">
			<h1 className="wp-heading-inline">
				{ __( 'Licenses', 'isoft-fm-foundation' ) }
			</h1>
			<Button
				variant="primary"
				className="page-title-action"
				onClick={ openCreate }
				style={ { marginLeft: '8px' } }
				__next40pxDefaultSize
			>
				{ __( 'Add New', 'isoft-fm-foundation' ) }
			</Button>
			<hr className="wp-header-end" />

			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			) }

			<p style={ { margin: '16px 0' } }>
				<Button
					variant="secondary"
					onClick={ () => setConfirmRestore( true ) }
					disabled={ restoring }
					__next40pxDefaultSize
				>
					{ restoring
						? __( 'Restoring…', 'isoft-fm-foundation' )
						: __( 'Restore seeded licenses', 'isoft-fm-foundation' ) }
				</Button>
				<span
					className="description"
					style={ { marginLeft: '8px', color: '#646970' } }
				>
					{ __(
						'Adds any default licenses missing from this install. Add-only — never modifies existing rows.',
						'isoft-fm-foundation'
					) }
				</span>
			</p>

			{ licenses === null && (
				<p>
					<Spinner />
				</p>
			) }

			{ licenses !== null && (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Title', 'isoft-fm-foundation' ) }
							</th>
							<th scope="col">
								{ __( 'Slug', 'isoft-fm-foundation' ) }
							</th>
							<th scope="col">
								{ __( 'Description', 'isoft-fm-foundation' ) }
							</th>
							<th scope="col" style={ { width: '80px' } }>
								{ __( 'Default', 'isoft-fm-foundation' ) }
							</th>
							<th scope="col" style={ { width: '180px' } }>
								{ __( 'Actions', 'isoft-fm-foundation' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ licenses.length === 0 && (
							<tr>
								<td colSpan="5">
									{ __(
										'No licenses found.',
										'isoft-fm-foundation'
									) }
								</td>
							</tr>
						) }
						{ licenses.map( ( lic ) => (
							<tr key={ lic.id }>
								<td>
									<strong>
										<a
											href="#edit"
											onClick={ ( e ) => {
												e.preventDefault();
												openEdit( lic );
											} }
										>
											{ lic.title }
										</a>
									</strong>
								</td>
								<td>
									<code>{ lic.slug }</code>
								</td>
								<td>{ lic.description }</td>
								<td>
									{ lic.is_default
										? __( 'Yes', 'isoft-fm-foundation' )
										: '' }
								</td>
								<td>
									<Button
										variant="link"
										onClick={ () => openEdit( lic ) }
									>
										{ __( 'Edit', 'isoft-fm-foundation' ) }
									</Button>
									{ ' | ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () => {
											setDeleteError( null );
											setDeleting( lic );
										} }
									>
										{ __(
											'Delete',
											'isoft-fm-foundation'
										) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ editing && (
				<Modal
					title={
						editing.id
							? __( 'Edit License', 'isoft-fm-foundation' )
							: __( 'Add New License', 'isoft-fm-foundation' )
					}
					onRequestClose={ saving ? () => {} : closeModal }
					size="medium"
				>
					{ saveError && (
						<Notice status="error" isDismissible={ false }>
							{ saveError }
						</Notice>
					) }
					<TextControl
						label={ __( 'Title', 'isoft-fm-foundation' ) }
						value={ editing.title || '' }
						onChange={ ( title ) =>
							setEditing( { ...editing, title } )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<div style={ { marginTop: '12px' } }>
						<TextControl
							label={ __( 'Slug', 'isoft-fm-foundation' ) }
							value={ editing.slug || '' }
							onChange={ ( slug ) =>
								setEditing( { ...editing, slug } )
							}
							help={ __(
								'Auto-generated from title if left blank.',
								'isoft-fm-foundation'
							) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
					<div style={ { marginTop: '12px' } }>
						<TextControl
							label={ __(
								'Short Description',
								'isoft-fm-foundation'
							) }
							value={ editing.description || '' }
							onChange={ ( description ) =>
								setEditing( { ...editing, description } )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
					<div style={ { marginTop: '12px' } }>
						<TextControl
							label={ __(
								'License URL',
								'isoft-fm-foundation'
							) }
							value={ editing.url || '' }
							onChange={ ( url ) =>
								setEditing( { ...editing, url } )
							}
							type="url"
							placeholder="https://…"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
					<div style={ { marginTop: '12px' } }>
						<TextareaControl
							label={ __(
								'Full License Text',
								'isoft-fm-foundation'
							) }
							value={ editing.full_text || '' }
							onChange={ ( full_text ) =>
								setEditing( { ...editing, full_text } )
							}
							help={ __(
								'Shown in the agreement modal when a user downloads a file requiring consent.',
								'isoft-fm-foundation'
							) }
							rows={ 8 }
							__nextHasNoMarginBottom
						/>
					</div>
					<div style={ { marginTop: '12px' } }>
						<ToggleControl
							label={ __(
								'Default license for new downloads',
								'isoft-fm-foundation'
							) }
							checked={ !! editing.is_default }
							onChange={ ( is_default ) =>
								setEditing( { ...editing, is_default } )
							}
							__nextHasNoMarginBottom
						/>
					</div>
					<div style={ { marginTop: '12px' } }>
						<TextControl
							label={ __(
								'Sort Order',
								'isoft-fm-foundation'
							) }
							type="number"
							value={ String( editing.sort_order || 0 ) }
							onChange={ ( sort_order ) =>
								setEditing( { ...editing, sort_order } )
							}
							min={ 0 }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</div>
					<div
						style={ {
							display:        'flex',
							gap:            '8px',
							marginTop:      '20px',
							justifyContent: 'flex-end',
						} }
					>
						<Button
							variant="tertiary"
							onClick={ closeModal }
							disabled={ saving }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'isoft-fm-foundation' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ handleSave }
							disabled={ saving }
							__next40pxDefaultSize
						>
							{ saving
								? __( 'Saving…', 'isoft-fm-foundation' )
								: editing.id
								? __( 'Update', 'isoft-fm-foundation' )
								: __( 'Add License', 'isoft-fm-foundation' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ deleting && (
				<ConfirmDialog
					isOpen
					onConfirm={ handleDelete }
					onCancel={ () => setDeleting( null ) }
					confirmButtonText={ __(
						'Delete',
						'isoft-fm-foundation'
					) }
				>
					{ deleteError ? (
						<>
							<p>{ deleteError }</p>
						</>
					) : (
						<p>
							{ sprintf(
								/* translators: %s: license title */
								__(
									'Delete the license "%s"? Downloads currently using this license will fall back to inheriting from their category.',
									'isoft-fm-foundation'
								),
								deleting.title
							) }
						</p>
					) }
				</ConfirmDialog>
			) }

			{ confirmRestore && (
				<ConfirmDialog
					isOpen
					onConfirm={ handleRestoreSeeds }
					onCancel={ () => setConfirmRestore( false ) }
					confirmButtonText={ __(
						'Restore',
						'isoft-fm-foundation'
					) }
				>
					<p>
						{ __(
							'Add any missing seeded licenses (Public Domain variants, Creative Commons, etc.)? Existing licenses with the same slug will not be modified.',
							'isoft-fm-foundation'
						) }
					</p>
				</ConfirmDialog>
			) }
		</div>
	);
};

export default LicensesSection;
