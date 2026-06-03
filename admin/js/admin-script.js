/* global ISOFT_FMF, jQuery */
( function ( $, ISOFT_FMF ) {
	'use strict';

	var $fileList = $( '#isoft-fmf-file-list-body' );
	var postId    = $( '#post_ID' ).val();

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------
	function esc( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function formatSize( bytes ) {
		bytes = Number( bytes ) || 0;
		if ( bytes === 0 ) {
			return '—'; }
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i     = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024; i++; }
		return bytes.toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
	}

	function fileRowHtml( file ) {
		var ext     = ( file.file_name || '' ).split( '.' ).pop().toUpperCase();
		var isLocal = file.file_type === 'local';
		var source  = isLocal
			? esc( file.file_name )
			: '<a href="' + esc( file.external_url ) + '" target="_blank" rel="noopener noreferrer">' + esc( file.external_url ) + '</a>';
		var badge   = isLocal
			? '<span class="isoft-fmf-badge isoft-fmf-badge--local">' + esc( ext ) + '</span>'
			: ( parseInt( file.is_mirror, 10 )
				? '<span class="isoft-fmf-badge isoft-fmf-badge--mirror">' + esc( ISOFT_FMF.i18n.mirror ) + '</span>'
				: '<span class="isoft-fmf-badge isoft-fmf-badge--external">' + esc( ISOFT_FMF.i18n.external ) + '</span>' );

		return '<tr class="isoft-fmf-file-row" data-file-id="' + esc( file.id ) + '">'
			+ '<td class="isoft-fmf-col-sort"><span class="dashicons dashicons-move isoft-fmf-sort-handle"></span></td>'
			+ '<td class="isoft-fmf-file-title" data-title="' + esc( file.title || '' ) + '" data-description="' + esc( file.description || '' ) + '">'
				+ esc( file.title || file.file_name || file.external_url )
			+ '</td>'
			+ '<td class="isoft-fmf-file-source">' + source + '</td>'
			+ '<td>' + badge + '</td>'
			+ '<td>' + esc( formatSize( file.file_size ) ) + '</td>'
			+ '<td>' + esc( file.download_count || 0 ) + '</td>'
			+ '<td>'
				+ '<button type="button" class="button button-small isoft-fmf-btn-edit-file" data-file-id="' + esc( file.id ) + '">' + esc( ISOFT_FMF.i18n.edit ) + '</button> '
				+ '<button type="button" class="button button-small isoft-fmf-btn-delete-file" data-file-id="' + esc( file.id ) + '">' + esc( ISOFT_FMF.i18n.remove ) + '</button>'
			+ '</td>'
			+ '</tr>';
	}

	function appendFileRow( file ) {
		$( '#isoft-fmf-no-files-row' ).remove();
		$fileList.append( fileRowHtml( file ) );
	}

	// -------------------------------------------------------------------------
	// Tabs
	// -------------------------------------------------------------------------
	$( '.isoft-fmf-tab-nav' ).on(
		'click',
		'.isoft-fmf-tab-btn',
		function () {
			var tab = $( this ).data( 'tab' );
			$( '.isoft-fmf-tab-btn' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
			$( this ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
			$( '.isoft-fmf-tab-panel' ).prop( 'hidden', true ).removeClass( 'is-active' );
			$( '.isoft-fmf-tab-panel[data-tab="' + tab + '"]' ).prop( 'hidden', false ).addClass( 'is-active' );

			if ( 'browse' === tab ) {
				loadBrowseList();
			}
		}
	);

	// -------------------------------------------------------------------------
	// Sortable file list
	// -------------------------------------------------------------------------
	$fileList.sortable(
		{
			handle: '.isoft-fmf-sort-handle',
			placeholder: 'isoft-fmf-sortable-placeholder',
			update: function () {
				var order = {};
				$fileList.find( '.isoft-fmf-file-row' ).each(
					function ( i ) {
						order[ $( this ).data( 'file-id' ) ] = i;
					}
				);
				$.post(
					ISOFT_FMF.ajaxUrl,
					{
						action: 'isoft_fmf_save_file_order',
						nonce:  ISOFT_FMF.nonce,
						order:  order,
						}
				);
			},
		}
	);

	// -------------------------------------------------------------------------
	// Edit file metadata (title + description)
	// -------------------------------------------------------------------------
	$fileList.on(
		'click',
		'.isoft-fmf-btn-edit-file',
		function () {
			var $btn   = $( this );
			var fileId = $btn.data( 'file-id' );
			var $row   = $btn.closest( '.isoft-fmf-file-row' );

			// Toggle: close if this row's editor is already open below it.
			var $existing = $row.next( '.isoft-fmf-file-edit-row' );
			if ( $existing.length ) {
				$existing.remove();
				return;
			}
			// Close any other open editor first.
			$fileList.find( '.isoft-fmf-file-edit-row' ).remove();

			var $titleCell = $row.find( '.isoft-fmf-file-title' );
			var title      = $titleCell.data( 'title' ) || '';
			var desc       = $titleCell.data( 'description' ) || '';

			var $editor = $(
				'<tr class="isoft-fmf-file-edit-row" data-edit-for="' + fileId + '">'
				+ '<td colspan="7">'
				+ '<div class="isoft-fmf-file-edit">'
				+ '<p><label>' + esc( ISOFT_FMF.i18n.title ) + '<br>'
				+ '<input type="text" class="widefat isoft-fmf-edit-title" />'
				+ '</label></p>'
				+ '<p><label>' + esc( ISOFT_FMF.i18n.description ) + '<br>'
				+ '<textarea class="widefat isoft-fmf-edit-description" rows="3"></textarea>'
				+ '</label></p>'
				+ '<p>'
				+ '<button type="button" class="button button-primary isoft-fmf-edit-save">' + esc( ISOFT_FMF.i18n.save ) + '</button> '
				+ '<button type="button" class="button isoft-fmf-edit-cancel">' + esc( ISOFT_FMF.i18n.cancel ) + '</button>'
				+ '<span class="isoft-fmf-edit-status" aria-live="polite"></span>'
				+ '</p>'
				+ '</div>'
				+ '</td>'
				+ '</tr>'
			);
			$editor.find( '.isoft-fmf-edit-title' ).val( title );
			$editor.find( '.isoft-fmf-edit-description' ).val( desc );
			$row.after( $editor );
			$editor.find( '.isoft-fmf-edit-title' ).trigger( 'focus' );
		}
	);

	$fileList.on(
		'click',
		'.isoft-fmf-edit-cancel',
		function () {
			$( this ).closest( '.isoft-fmf-file-edit-row' ).remove();
		}
	);

	$fileList.on(
		'click',
		'.isoft-fmf-edit-save',
		function () {
			var $editor  = $( this ).closest( '.isoft-fmf-file-edit-row' );
			var fileId   = $editor.data( 'edit-for' );
			var $row     = $fileList.find( '.isoft-fmf-file-row[data-file-id="' + fileId + '"]' );
			var newTitle = $editor.find( '.isoft-fmf-edit-title' ).val();
			var newDesc  = $editor.find( '.isoft-fmf-edit-description' ).val();
			var $status  = $editor.find( '.isoft-fmf-edit-status' );
			var $save    = $( this );

			$save.prop( 'disabled', true );
			$status.text( ISOFT_FMF.i18n.saving );

			$.post(
				ISOFT_FMF.ajaxUrl,
				{
					action:      'isoft_fmf_update_file_meta',
					nonce:       ISOFT_FMF.nonce,
					file_id:     fileId,
					title:       newTitle,
					description: newDesc,
				},
				function ( res ) {
					if ( res.success ) {
						var $titleCell = $row.find( '.isoft-fmf-file-title' );
						$titleCell
						.text( res.data.file.title || res.data.file.file_name || res.data.file.external_url )
						.attr( 'data-title', res.data.file.title || '' )
						.attr( 'data-description', res.data.file.description || '' );
						$editor.remove();
					} else {
						$save.prop( 'disabled', false );
						$status.text( ( res.data && res.data.message ) ? res.data.message : ISOFT_FMF.i18n.error );
					}
				}
			).fail(
				function () {
					$save.prop( 'disabled', false );
					$status.text( ISOFT_FMF.i18n.networkError );
				}
			);
		}
	);

	// -------------------------------------------------------------------------
	// Remove file
	// -------------------------------------------------------------------------
	$fileList.on(
		'click',
		'.isoft-fmf-btn-delete-file',
		function () {
			if ( ! window.confirm( ISOFT_FMF.i18n.confirmDelete ) ) {
				return;
			}
			var $btn   = $( this );
			var fileId = $btn.data( 'file-id' );
			var $row   = $btn.closest( '.isoft-fmf-file-row' );

			$btn.prop( 'disabled', true );

			$.post(
				ISOFT_FMF.ajaxUrl,
				{
					action:  'isoft_fmf_delete_file',
					nonce:   ISOFT_FMF.nonce,
					file_id: fileId,
				},
				function ( res ) {
					if ( res.success ) {
						$row.next( '.isoft-fmf-file-edit-row' ).remove();
						$row.remove();
						if ( $fileList.find( '.isoft-fmf-file-row' ).length === 0 ) {
							$fileList.append( '<tr class="isoft-fmf-no-files" id="isoft-fmf-no-files-row"><td colspan="7">' + esc( ISOFT_FMF.i18n.noFiles ) + '</td></tr>' );
						}
					} else {
						$btn.prop( 'disabled', false );
						alert( res.data && res.data.message ? res.data.message : ISOFT_FMF.i18n.error );
					}
				}
			);
		}
	);

	// -------------------------------------------------------------------------
	// Upload — dropzone + file input
	// -------------------------------------------------------------------------
	var $dropzone  = $( '#isoft-fmf-dropzone' );
	var $fileInput = $( '#isoft-fmf-file-input' );
	var $queue     = $( '#isoft-fmf-upload-queue' );

	$dropzone.on(
		'click',
		function () {
			// Must call the native DOM click() — jQuery's .trigger('click') fires
			// a synthetic event that browsers do not treat as a user gesture, and
			// the file-picker dialog will not open.
			if ( $fileInput[ 0 ] ) {
				$fileInput[ 0 ].click();
			}
		}
	);

	$fileInput.on(
		'change',
		function ( e ) {
			handleFiles( e.target.files );
			$fileInput.val( '' );
		}
	);

	$dropzone.on(
		'dragover dragenter',
		function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			$dropzone.addClass( 'is-dragover' );
		}
	);

	$dropzone.on(
		'dragleave dragend drop',
		function () {
			$dropzone.removeClass( 'is-dragover' );
		}
	);

	$dropzone.on(
		'drop',
		function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
			if ( files && files.length ) {
				handleFiles( files );
			}
		}
	);

	function handleFiles( fileList ) {
		for ( var i = 0; i < fileList.length; i++ ) {
			uploadOne( fileList[ i ] );
		}
	}

	function uploadOne( file ) {
		var $item = $(
			'<li class="isoft-fmf-upload-item">'
			+ '<span class="isoft-fmf-upload-name"></span>'
			+ '<span class="isoft-fmf-upload-bar"><span class="isoft-fmf-upload-bar-fill"></span></span>'
			+ '<span class="isoft-fmf-upload-status"></span>'
			+ '</li>'
		);
		$item.find( '.isoft-fmf-upload-name' ).text( file.name );
		$queue.append( $item );

		var fd = new FormData();
		fd.append( 'action',      'isoft_fmf_upload_file' );
		fd.append( 'nonce',       ISOFT_FMF.nonce );
		fd.append( 'download_id', postId );
		fd.append( 'file',        file );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ISOFT_FMF.ajaxUrl, true );

		xhr.upload.addEventListener(
			'progress',
			function ( evt ) {
				if ( evt.lengthComputable ) {
					var pct = Math.round( ( evt.loaded / evt.total ) * 100 );
					$item.find( '.isoft-fmf-upload-bar-fill' ).css( 'width', pct + '%' );
					$item.find( '.isoft-fmf-upload-status' ).text( pct + '%' );
				}
			}
		);

		xhr.onload = function () {
			try {
				var res = JSON.parse( xhr.responseText );
				if ( res.success ) {
					$item.addClass( 'is-done' );
					$item.find( '.isoft-fmf-upload-bar-fill' ).css( 'width', '100%' );
					$item.find( '.isoft-fmf-upload-status' ).text( '✓' );
					appendFileRow( res.data.file );
					setTimeout(
						function () {
							$item.fadeOut(
								400,
								function () {
												$item.remove(); }
							); },
						800
					);
				} else {
					$item.addClass( 'is-error' );
					$item.find( '.isoft-fmf-upload-status' ).text( res.data && res.data.message ? res.data.message : ISOFT_FMF.i18n.error );
				}
			} catch ( err ) {
				$item.addClass( 'is-error' );
				$item.find( '.isoft-fmf-upload-status' ).text( ISOFT_FMF.i18n.serverError );
			}
		};

		xhr.onerror = function () {
			$item.addClass( 'is-error' );
			$item.find( '.isoft-fmf-upload-status' ).text( ISOFT_FMF.i18n.networkError );
		};

		xhr.send( fd );
	}

	// -------------------------------------------------------------------------
	// Browse — list untracked files in the category folder
	// -------------------------------------------------------------------------
	var browseLoaded = false;
	function loadBrowseList() {
		if ( browseLoaded ) {
			return; }
		var $list = $( '#isoft-fmf-browse-list' );
		if ( ! $list.length ) {
			return; }

		$.post(
			ISOFT_FMF.ajaxUrl,
			{
				action:      'isoft_fmf_browse_category',
				nonce:       ISOFT_FMF.nonce,
				download_id: postId,
			},
			function ( res ) {
				browseLoaded = true;
				$list.empty();
				if ( ! res.success || ! res.data.files || res.data.files.length === 0 ) {
					$list.append( '<li class="isoft-fmf-browse-empty">' + esc( ISOFT_FMF.i18n.noFolderFiles ) + '</li>' );
					return;
				}
				res.data.files.forEach(
					function ( item ) {
						var $li = $( '<li class="isoft-fmf-browse-item"></li>' );
						$li.append( '<span class="isoft-fmf-browse-name">' + esc( item.name ) + '</span>' );
						$li.append( '<span class="isoft-fmf-browse-size">' + esc( formatSize( item.size ) ) + '</span>' );
						if ( item.tracked ) {
								$li.append( '<span class="isoft-fmf-browse-tag">' + esc( ISOFT_FMF.i18n.alreadyLinked ) + '</span>' );
								$li.addClass( 'is-tracked' );
						} else {
							var $btn = $( '<button type="button" class="button button-small"></button>' ).text( ISOFT_FMF.i18n.linkButton );
							$btn.on(
								'click',
								function () {
									$btn.prop( 'disabled', true ).text( ISOFT_FMF.i18n.linking );
									$.post(
										ISOFT_FMF.ajaxUrl,
										{
											action:      'isoft_fmf_import_file',
											nonce:       ISOFT_FMF.nonce,
											download_id: postId,
											rel_path:    item.rel,
										},
										function ( r ) {
											if ( r.success ) {
												appendFileRow( r.data.file );
												$li.addClass( 'is-tracked' );
												$btn.replaceWith( '<span class="isoft-fmf-browse-tag">' + esc( ISOFT_FMF.i18n.linked ) + '</span>' );
											} else {
												$btn.prop( 'disabled', false ).text( ISOFT_FMF.i18n.retry );
												alert( r.data && r.data.message ? r.data.message : ISOFT_FMF.i18n.error );
											}
										}
									);
								}
							);
							$li.append( $btn );
						}
						$list.append( $li );
					}
				);
			}
		);
	}

	// Reset browse cache when switching away and back (picks up newly uploaded files).
	$( '.isoft-fmf-tab-nav' ).on(
		'click',
		'.isoft-fmf-tab-btn',
		function () {
			browseLoaded = false;
		}
	);

	// -------------------------------------------------------------------------
	// External link
	// -------------------------------------------------------------------------
	$( document ).on(
		'click',
		'.isoft-fmf-btn-ext-save',
		function () {
			var url      = $( '#isoft-fmf-ext-url' ).val().trim();
			var title    = $( '#isoft-fmf-ext-title' ).val().trim();
			var isMirror = $( '#isoft-fmf-ext-mirror' ).is( ':checked' ) ? 1 : 0;

			if ( ! url ) {
				$( '#isoft-fmf-ext-url' ).trigger( 'focus' );
				return;
			}

			$.post(
				ISOFT_FMF.ajaxUrl,
				{
					action:      'isoft_fmf_add_external',
					nonce:       ISOFT_FMF.nonce,
					download_id: postId,
					url:         url,
					title:       title,
					is_mirror:   isMirror,
				},
				function ( res ) {
					if ( res.success ) {
						// Fetch fresh row by reloading — external add does not return full row.
						location.reload();
					} else {
						alert( res.data && res.data.message ? res.data.message : ISOFT_FMF.i18n.error );
					}
				}
			);
		}
	);

} )( jQuery, ISOFT_FMF );
