/**
 * React enhancer for the Downloads > Categories add/edit form.
 *
 * 0.12.4 scope, sub-PR 2 of 3. Replaces the plain text input for the
 * category Icon field with a small React component that supports:
 *   - Free-text input (dashicon name like "dashicons-folder" or a
 *     pasted image URL — same shape the PHP form has always accepted)
 *   - "Select from media library" button that opens the WP media
 *     modal via @wordpress/media-utils' MediaUpload
 *   - Live preview: dashicon names render as a dashicon glyph; URLs
 *     render as a 32x32 thumbnail
 *
 * The visible <input name="isoft_fmf_cat_icon"> is still the source
 * of truth that ships to the WP tag-form $_POST handler — React
 * keeps it in sync with its own state but doesn't replace the input
 * (so the form submission keeps working unchanged).
 *
 * Other category fields (access role, default license, sort order)
 * stay as their PHP renders — already-functional dropdowns and a
 * number input that a React port wouldn't improve.
 */

import { Button, BaseControl } from '@wordpress/components';
import { MediaUpload, MediaUploadCheck } from '@wordpress/media-utils';
import { useState, useEffect, createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const isUrl = ( v ) => typeof v === 'string' && /^https?:\/\//i.test( v );
const isDashicon = ( v ) => typeof v === 'string' && /^dashicons-[a-z0-9-]+$/i.test( v );

const IconPicker = ( { input } ) => {
	const [ value, setValue ] = useState( input.value || '' );

	// Mirror state back into the native <input> so the existing form
	// submission picks up the value without any additional plumbing.
	useEffect( () => {
		input.value = value;
		// Fire 'change' so any other listeners (form-dirty guards etc.)
		// notice. WP core doesn't rely on this but it's polite.
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}, [ value, input ] );

	const onMediaSelect = ( media ) => {
		if ( media && media.url ) {
			setValue( media.url );
		}
	};

	const preview = (
		<span
			style={ {
				display:        'inline-flex',
				alignItems:     'center',
				justifyContent: 'center',
				width:          '40px',
				height:         '40px',
				marginRight:    '10px',
				borderRadius:   '4px',
				background:     '#f0f0f1',
				flexShrink:     0,
				overflow:       'hidden',
			} }
			aria-hidden="true"
		>
			{ isUrl( value ) ? (
				<img
					src={ value }
					alt=""
					style={ { maxWidth: '32px', maxHeight: '32px' } }
				/>
			) : isDashicon( value ) ? (
				<span
					className={ `dashicons ${ value }` }
					style={ { fontSize: '22px', color: '#1d2327' } }
				/>
			) : (
				<span style={ { color: '#a7aaad', fontSize: '11px' } }>?</span>
			) }
		</span>
	);

	return (
		<BaseControl __nextHasNoMarginBottom>
			<div style={ { display: 'flex', alignItems: 'center', gap: '8px' } }>
				{ preview }
				<div style={ { flex: 1, display: 'flex', gap: '6px', alignItems: 'center', flexWrap: 'wrap' } }>
					<input
						type="text"
						value={ value }
						onChange={ ( e ) => setValue( e.target.value ) }
						style={ { minWidth: '260px', flex: 1 } }
						placeholder={ __(
							'dashicons-folder or https://example.com/icon.png',
							'isoft-fm-foundation'
						) }
					/>
					<MediaUploadCheck>
						<MediaUpload
							onSelect={ onMediaSelect }
							allowedTypes={ [ 'image' ] }
							value={ isUrl( value ) ? value : 0 }
							render={ ( { open } ) => (
								<Button
									variant="secondary"
									onClick={ open }
									__next40pxDefaultSize
								>
									{ __(
										'Select from media library',
										'isoft-fm-foundation'
									) }
								</Button>
							) }
						/>
					</MediaUploadCheck>
					{ value && (
						<Button
							variant="tertiary"
							onClick={ () => setValue( '' ) }
							__next40pxDefaultSize
						>
							{ __( 'Clear', 'isoft-fm-foundation' ) }
						</Button>
					) }
				</div>
			</div>
		</BaseControl>
	);
};

/**
 * Find the existing <input id="isoft-fmf-cat-icon">, replace its
 * visible rendering with the React enhancer, and hide the original
 * input off-screen (still part of the form submission).
 *
 * Two surfaces to handle:
 *   - edit-tags.php (add screen): the input is inside a
 *     <div class="form-field">...</div> block.
 *   - term.php (edit screen): the input is inside a <td> of a
 *     <tr class="form-field"> row.
 */
const mountIconPicker = () => {
	const input = document.getElementById( 'isoft-fmf-cat-icon' );
	if ( ! input ) {
		return;
	}

	// Hide the original input but keep it in the DOM so the form
	// submission still includes its value.
	input.style.display = 'none';

	// Mount point: append a sibling after the input.
	const host = document.createElement( 'div' );
	host.className = 'isoft-fmf-icon-picker-host';
	input.parentNode.insertBefore( host, input.nextSibling );

	if ( typeof createRoot === 'function' ) {
		createRoot( host ).render( <IconPicker input={ input } /> );
	} else if ( typeof render === 'function' ) {
		render( <IconPicker input={ input } />, host );
	}
};

mountIconPicker();
