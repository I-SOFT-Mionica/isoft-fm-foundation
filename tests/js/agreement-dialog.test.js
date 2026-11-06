/**
 * Agreement dialog in public-script.js, clicked through in jsdom.
 *
 * Regression for the 0.10.18 click guard: it stopped the dialog's own
 * Download link (which carries .isoft-fmf-download-btn for styling), so
 * agreeing never started the download.
 */

const path = require( 'path' );

const SCRIPT = path.resolve( __dirname, '../../public/js/public-script.js' );

// Same markup as ISOFT_FMF_Shortcodes::render_agree_modal(), plus a card
// button that needs agreement, a plain download button, and a later
// "popup" the way a page builder appends one.
function setUpPage() {
	document.body.innerHTML = `
		<div id="card">
			<a id="needs-agree" href="https://example.test/dl/1" class="isoft-fmf-download-btn isoft-fmf-requires-agree"
				data-agree-title="Licence" data-agree-content="#licence-1">Download</a>
			<a id="plain" href="https://example.test/dl/2" class="isoft-fmf-download-btn">Download</a>
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
		<div id="builder-popup">popup</div>
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

const overlay = () => document.getElementById( 'isoft-fmf-agree-overlay' );

describe( 'agreement dialog', () => {
	let themeHandler;

	beforeAll( () => {
		setUpPage();
		// A theme's ajax-navigation handler, bubble phase on document.
		themeHandler = jest.fn();
		document.addEventListener( 'click', themeHandler );
		require( SCRIPT );
	} );

	beforeEach( () => {
		themeHandler.mockClear();
	} );

	test( 'plain download buttons are still kept away from theme click handlers', () => {
		click( document.getElementById( 'plain' ) );
		expect( themeHandler ).not.toHaveBeenCalled();
	} );

	test( 'a button that needs agreement opens the dialog above later popups', () => {
		const event = click( document.getElementById( 'needs-agree' ) );

		expect( event.defaultPrevented ).toBe( true );
		expect( overlay().classList.contains( 'is-open' ) ).toBe( true );
		expect( document.body.lastElementChild ).toBe( overlay() );
	} );

	test( 'Download stays inert until the box is ticked', () => {
		const proceed = document.getElementById( 'isoft-fmf-agree-proceed' );
		click( proceed );
		expect( overlay().classList.contains( 'is-open' ) ).toBe( true );
	} );

	test( 'agreeing and clicking Download runs the proceed handler', () => {
		const checkbox = document.getElementById( 'isoft-fmf-agree-checkbox' );
		checkbox.checked = true;
		checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const proceed = document.getElementById( 'isoft-fmf-agree-proceed' );
		expect( proceed.hasAttribute( 'aria-disabled' ) ).toBe( false );

		// jsdom cannot navigate, so check what the handler does around it:
		// it cancels the "#" link and closes the dialog before setting
		// window.location. Under the bug neither happened.
		const event = click( proceed );
		expect( event.defaultPrevented ).toBe( true );
		expect( overlay().classList.contains( 'is-open' ) ).toBe( false );
	} );
} );
