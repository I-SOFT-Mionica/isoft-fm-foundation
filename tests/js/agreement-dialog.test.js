/**
 * Agreement dialog in public-script.js, clicked through in jsdom.
 *
 * Regressions:
 * - AG-1: the 0.10.18 click guard stopped the dialog's own Download link
 *   (it carries .isoft-fmf-download-btn for styling), so its handler never ran.
 * - AG-1b: the handler cleared the address before navigating to it, so
 *   agreeing reloaded the page instead of downloading.
 * - AG-2: inside an Elementor popup the dialog opened behind the popup.
 * - AG-3: every click in the dialog closed the Elementor popup under it.
 *
 * jsdom cannot load other documents, but it does follow fragment-only
 * navigations. The test downloads therefore use "#..." addresses, so
 * window.location shows exactly where the dialog sent the browser.
 */

const path = require( 'path' );

const SCRIPT = path.resolve( __dirname, '../../public/js/public-script.js' );

function agreeButton( id, href ) {
	return `<a id="${ id }" href="${ href }" class="isoft-fmf-download-btn isoft-fmf-requires-agree"
		data-agree-title="Licence" data-agree-content="#licence-1">Download</a>`;
}

// Same dialog markup as ISOFT_FMF_Shortcodes::render_agree_modal(), plus a
// card, a plain download button, and an Elementor Pro popup that was
// appended after the dialog, the way Elementor adds popups.
function setUpPage() {
	document.body.innerHTML = `
		<div id="card">
			${ agreeButton( 'needs-agree', '#download-1' ) }
			<a id="plain" href="#plain-download" class="isoft-fmf-download-btn">Download</a>
			<div id="licence-1" hidden><p>Terms</p></div>
		</div>
		<div id="isoft-fmf-agree-overlay" class="isoft-fmf-modal-overlay" hidden aria-modal="true" role="dialog">
			<div class="isoft-fmf-modal">
				<h3 id="isoft-fmf-agree-title"></h3>
				<div id="isoft-fmf-agree-body"></div>
				<p><label><input type="checkbox" id="isoft-fmf-agree-checkbox" /> I agree</label></p>
				<p>
					<a id="isoft-fmf-agree-proceed" href="#" class="wp-element-button isoft-fmf-download-btn" aria-disabled="true">Download</a>
					<button type="button" id="isoft-fmf-agree-cancel">Cancel</button>
				</p>
			</div>
		</div>
		<div class="elementor-popup-modal">
			<div data-elementor-type="popup" data-elementor-id="77">
				${ agreeButton( 'in-popup', '#download-2' ) }
			</div>
		</div>
	`;
}

function click( el ) {
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );
	el.dispatchEvent( event );
	return event;
}

function key( el, type ) {
	el.dispatchEvent(
		new window.KeyboardEvent( type, { key: 'Escape', bubbles: true } )
	);
}

function agree() {
	const checkbox = document.getElementById( 'isoft-fmf-agree-checkbox' );
	checkbox.checked = true;
	checkbox.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
}

const byId = ( id ) => document.getElementById( id );
const overlay = () => byId( 'isoft-fmf-agree-overlay' );
const isOpen = () => overlay().classList.contains( 'is-open' );

describe( 'agreement dialog', () => {
	let themeHandler;
	let popupHide;

	beforeAll( () => {
		setUpPage();

		// A theme's ajax-navigation handler, bubble phase on document.
		themeHandler = jest.fn();
		document.addEventListener( 'click', themeHandler );

		// Elementor Pro's popup registry, reduced to what the script reads.
		popupHide = { ignore: '.flatpickr-calendar' };
		window.elementorFrontend = {
			documentsManager: {
				documents: {
					77: {
						getModal: () => ( {
							getSettings: ( name ) =>
								'hide' === name ? popupHide : undefined,
						} ),
					},
				},
			},
		};

		require( SCRIPT );
	} );

	beforeEach( () => {
		themeHandler.mockClear();
		window.location.hash = '';
	} );

	test( 'plain download buttons are still kept away from theme click handlers', () => {
		click( byId( 'plain' ) );
		expect( themeHandler ).not.toHaveBeenCalled();
	} );

	test( 'a button that needs agreement opens the dialog at the end of body', () => {
		const event = click( byId( 'needs-agree' ) );

		expect( event.defaultPrevented ).toBe( true );
		expect( isOpen() ).toBe( true );
		expect( document.body.lastElementChild ).toBe( overlay() );
	} );

	test( 'Download stays inert until the box is ticked', () => {
		click( byId( 'isoft-fmf-agree-proceed' ) );
		expect( isOpen() ).toBe( true );
		expect( window.location.hash ).toBe( '' );
	} );

	test( 'agreeing and clicking Download goes to the download address', () => {
		agree();
		const proceed = byId( 'isoft-fmf-agree-proceed' );
		expect( proceed.hasAttribute( 'aria-disabled' ) ).toBe( false );

		const event = click( proceed );

		expect( event.defaultPrevented ).toBe( true );
		expect( isOpen() ).toBe( false );
		expect( window.location.hash ).toBe( '#download-1' );
	} );

	test( 'opened from an Elementor popup, the popup ignores clicks on the dialog', () => {
		click( byId( 'in-popup' ) );

		expect( isOpen() ).toBe( true );
		expect( document.body.lastElementChild ).toBe( overlay() );
		expect( popupHide.ignore ).toBe(
			'.flatpickr-calendar, #isoft-fmf-agree-overlay'
		);

		// Opening it again does not add the selector twice.
		key( byId( 'isoft-fmf-agree-checkbox' ), 'keydown' );
		click( byId( 'in-popup' ) );
		expect( popupHide.ignore ).toBe(
			'.flatpickr-calendar, #isoft-fmf-agree-overlay'
		);

		agree();
		click( byId( 'isoft-fmf-agree-proceed' ) );
		expect( window.location.hash ).toBe( '#download-2' );
	} );

	test( 'Escape in the dialog closes only the dialog', () => {
		const popupEscape = jest.fn();
		window.addEventListener( 'keyup', popupEscape );
		window.addEventListener( 'keydown', popupEscape );

		click( byId( 'needs-agree' ) );
		const checkbox = byId( 'isoft-fmf-agree-checkbox' );
		key( checkbox, 'keydown' );
		key( checkbox, 'keyup' );

		expect( isOpen() ).toBe( false );
		expect( popupEscape ).not.toHaveBeenCalled();
		expect( window.location.hash ).toBe( '' );
	} );
} );
