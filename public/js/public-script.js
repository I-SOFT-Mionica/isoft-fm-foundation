/* I-Soft File Manager: Foundation — Public JS */
( function () {
	'use strict';

	var overlay = document.getElementById( 'isoft-fmf-agree-overlay' );
	if ( ! overlay ) {
		return;
	}

	var title       = document.getElementById( 'isoft-fmf-agree-title' );
	var body        = document.getElementById( 'isoft-fmf-agree-body' );
	var checkbox    = document.getElementById( 'isoft-fmf-agree-checkbox' );
	var proceed     = document.getElementById( 'isoft-fmf-agree-proceed' );
	var cancel      = document.getElementById( 'isoft-fmf-agree-cancel' );
	var pendingHref = '';

	// -------------------------------------------------------------------------
	// Intercept clicks on buttons that require agreement
	// -------------------------------------------------------------------------
	document.addEventListener(
		'click',
		function ( e ) {
			var btn = e.target.closest( '.isoft-fmf-requires-agree' );
			if ( ! btn ) {
				return;
			}

			e.preventDefault();

			pendingHref = btn.getAttribute( 'href' );

			// Populate modal
			title.textContent = btn.getAttribute( 'data-agree-title' ) || '';

			var contentEl  = document.querySelector( btn.getAttribute( 'data-agree-content' ) );
			body.innerHTML = contentEl ? contentEl.innerHTML : '';

			// Reset state
			checkbox.checked = false;
			proceed.setAttribute( 'aria-disabled', 'true' );
			proceed.style.opacity       = '0.5';
			proceed.style.pointerEvents = 'none';

			// Show
			overlay.classList.add( 'is-open' );
			checkbox.focus();
		}
	);

	// -------------------------------------------------------------------------
	// Checkbox enables the proceed button
	// -------------------------------------------------------------------------
	checkbox.addEventListener(
		'change',
		function () {
			if ( checkbox.checked ) {
				proceed.removeAttribute( 'aria-disabled' );
				proceed.style.opacity       = '';
				proceed.style.pointerEvents = '';
			} else {
				proceed.setAttribute( 'aria-disabled', 'true' );
				proceed.style.opacity       = '0.5';
				proceed.style.pointerEvents = 'none';
			}
		}
	);

	// -------------------------------------------------------------------------
	// Proceed — navigate to the download URL
	// -------------------------------------------------------------------------
	proceed.addEventListener(
		'click',
		function ( e ) {
			e.preventDefault();
			if ( proceed.getAttribute( 'aria-disabled' ) === 'true' ) {
				return;
			}
			closeModal();
			window.location.href = pendingHref;
		}
	);

	// -------------------------------------------------------------------------
	// Cancel / close
	// -------------------------------------------------------------------------
	cancel.addEventListener( 'click', closeModal );

	overlay.addEventListener(
		'click',
		function ( e ) {
			if ( e.target === overlay ) {
				closeModal();
			}
		}
	);

	document.addEventListener(
		'keydown',
		function ( e ) {
			if ( 'Escape' === e.key && overlay.classList.contains( 'is-open' ) ) {
				closeModal();
			}
		}
	);

	function closeModal() {
		overlay.classList.remove( 'is-open' );
		pendingHref      = '';
		checkbox.checked = false;
	}

} )();
