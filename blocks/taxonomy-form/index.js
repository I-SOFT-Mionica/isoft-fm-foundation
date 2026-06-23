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
import { useState, useEffect, useCallback, createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

// Open the WP media-library modal directly via the wp.media() global
// that wp_enqueue_media() registers. @wordpress/media-utils' MediaUpload
// component bridges to the same modal but isn't reliably exported as
// `wp-media-utils` outside editor contexts — using wp.media() directly
// avoids the dependency-resolution roulette.
const openMediaPicker = ( onSelect ) => {
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	const frame = window.wp.media( {
		title:    __( 'Select Category Icon', 'isoft-fm-foundation' ),
		button:   { text: __( 'Use this image', 'isoft-fm-foundation' ) },
		library:  { type: 'image' },
		multiple: false,
	} );
	frame.on( 'select', () => {
		const attachment = frame.state().get( 'selection' ).first().toJSON();
		if ( attachment && attachment.url ) {
			onSelect( attachment.url );
		}
	} );
	frame.open();
};

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

	const onPickFromLibrary = useCallback( () => {
		openMediaPicker( ( url ) => setValue( url ) );
	}, [] );

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
					<Button
						variant="secondary"
						onClick={ onPickFromLibrary }
						__next40pxDefaultSize
					>
						{ __(
							'Select from media library',
							'isoft-fm-foundation'
						) }
					</Button>
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
	// eslint-disable-next-line no-console
	console.log( '[isoft-fmf taxonomy-form] bundle executing' );

	const input = document.getElementById( 'isoft-fmf-cat-icon' );
	// eslint-disable-next-line no-console
	console.log( '[isoft-fmf taxonomy-form] icon input:', input );

	if ( ! input ) {
		return;
	}

	input.style.display = 'none';

	const host = document.createElement( 'div' );
	host.className = 'isoft-fmf-icon-picker-host';
	input.parentNode.insertBefore( host, input.nextSibling );

	try {
		if ( typeof createRoot === 'function' ) {
			createRoot( host ).render( <IconPicker input={ input } /> );
			// eslint-disable-next-line no-console
			console.log( '[isoft-fmf taxonomy-form] createRoot mounted' );
		} else if ( typeof render === 'function' ) {
			render( <IconPicker input={ input } />, host );
			// eslint-disable-next-line no-console
			console.log( '[isoft-fmf taxonomy-form] render mounted' );
		} else {
			// eslint-disable-next-line no-console
			console.error( '[isoft-fmf taxonomy-form] no React mount fn available' );
		}
	} catch ( err ) {
		// eslint-disable-next-line no-console
		console.error( '[isoft-fmf taxonomy-form] mount threw:', err );
	}
};

// The script is enqueued in_footer, so the DOM is parsed by the time
// this file executes. No need for DOMContentLoaded wrapping.
mountIconPicker();
