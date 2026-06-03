/**
 * TinyMCE plugin for I-Soft File Manager: Foundation — "Insert Download [iD]" toolbar button.
 *
 * Adds a button that opens the search modal (#isoft-fmf-tmce-modal).
 * On selection, inserts [isoft_fmf_download id=X] at the cursor position.
 */
( function () {
	'use strict';

	var DEBOUNCE_MS   = 300;
	var debounceTimer = null;

	tinymce.PluginManager.add(
		'isoft_fmf_insert',
		function ( editor ) {

			// ── Button ─────────────────────────────────────────────────────────────
			editor.addButton(
				'isoft_fmf_insert',
				{
					title: ISFMTmce.i18n.insertDownload,
					icon: 'dashicons-download',
					tooltip: ISFMTmce.i18n.insertDownload,
					onclick: openModal,
				}
			);

			// ── Modal helpers ──────────────────────────────────────────────────────
			function getModal() {
				return document.getElementById( 'isoft-fmf-tmce-modal' );
			}

			function openModal() {
				var modal = getModal();
				if ( ! modal ) {
					return;
				}

				modal.removeAttribute( 'hidden' );

				var searchInput = document.getElementById( 'isoft-fmf-tmce-search' );
				var catSelect   = document.getElementById( 'isoft-fmf-tmce-category' );

				// Reset state
				searchInput.value = '';
				catSelect.value   = '0';
				fetchResults( '', 0 );

				// Autofocus search
				setTimeout(
					function () {
						searchInput.focus(); },
					50
				);

				// Bind events (attach once via flag)
				if ( ! modal._isfmBound ) {
					modal._isfmBound = true;

					// Backdrop click closes
					modal.querySelector( '.isoft-fmf-tmce-modal__backdrop' ).addEventListener( 'click', closeModal );
					modal.querySelector( '.isoft-fmf-tmce-modal__close' ).addEventListener( 'click', closeModal );
					modal.querySelector( '.isoft-fmf-tmce-modal__cancel' ).addEventListener( 'click', closeModal );

					// Search input — debounced
					searchInput.addEventListener(
						'input',
						function () {
							clearTimeout( debounceTimer );
							debounceTimer = setTimeout(
								function () {
									fetchResults( searchInput.value, parseInt( catSelect.value, 10 ) || 0 );
								},
								DEBOUNCE_MS
							);
						}
					);

					// Category filter — immediate
					catSelect.addEventListener(
						'change',
						function () {
							fetchResults( searchInput.value, parseInt( catSelect.value, 10 ) || 0 );
						}
					);

					// Result click — event delegation
					document.getElementById( 'isoft-fmf-tmce-results' ).addEventListener(
						'click',
						function ( e ) {
							var btn = e.target.closest( '.isoft-fmf-tmce-modal__item' );
							if ( btn ) {
								insertDownload( parseInt( btn.dataset.id, 10 ) );
							}
						}
					);

					// Esc key
					document.addEventListener(
						'keydown',
						function ( e ) {
							if ( e.key === 'Escape' && ! getModal().hasAttribute( 'hidden' ) ) {
								closeModal();
							}
						}
					);
				}
			}

			function closeModal() {
				var modal = getModal();
				if ( modal ) {
					modal.setAttribute( 'hidden', '' );
				}
			}

			function fetchResults( search, category ) {
				var resultsEl         = document.getElementById( 'isoft-fmf-tmce-results' );
				resultsEl.textContent = '';
				var loading           = document.createElement( 'p' );
				loading.className     = 'isoft-fmf-tmce-modal__loading';
				loading.textContent   = ISFMTmce.i18n.loading;
				resultsEl.appendChild( loading );

				var data = new FormData();
				data.append( 'action',   'isoft_fmf_tmce_search' );
				data.append( 'nonce',    ISFMTmce.nonce );
				data.append( 'search',   search );
				data.append( 'category', category );

				function showError() {
					resultsEl.textContent = '';
					var err               = document.createElement( 'p' );
					err.className         = 'isoft-fmf-tmce-modal__empty';
					err.textContent       = ISFMTmce.i18n.loadError;
					resultsEl.appendChild( err );
				}

				fetch( ISFMTmce.ajaxUrl, { method: 'POST', body: data } )
				.then(
					function ( r ) {
						return r.json(); }
				)
					.then(
						function ( json ) {
							if ( json.success ) {
									resultsEl.innerHTML = json.data.html;
							} else {
								showError();
							}
						}
					)
					.catch( showError );
			}

			function insertDownload( id ) {
				if ( ! id ) {
					return;
				}
				editor.insertContent( '[isoft_fmf_download id=' + id + ']' );
				closeModal();
			}
		}
	);
} )();
