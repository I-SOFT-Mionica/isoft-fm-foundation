/* global ISFM, jQuery */
( function ( $, ISFM ) {
	'use strict';

	var $fileList = $( '#isfm-file-list-body' );
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
			? '<span class="isfm-badge isfm-badge--local">' + esc( ext ) + '</span>'
			: ( parseInt( file.is_mirror, 10 )
				? '<span class="isfm-badge isfm-badge--mirror">' + esc( ISFM.i18n.mirror ) + '</span>'
				: '<span class="isfm-badge isfm-badge--external">' + esc( ISFM.i18n.external ) + '</span>' );

		return '<tr class="isfm-file-row" data-file-id="' + esc( file.id ) + '">'
			+ '<td class="isfm-col-sort"><span class="dashicons dashicons-move isfm-sort-handle"></span></td>'
			+ '<td class="isfm-file-title" data-title="' + esc( file.title || '' ) + '" data-description="' + esc( file.description || '' ) + '">'
				+ esc( file.title || file.file_name || file.external_url )
			+ '</td>'
			+ '<td class="isfm-file-source">' + source + '</td>'
			+ '<td>' + badge + '</td>'
			+ '<td>' + esc( formatSize( file.file_size ) ) + '</td>'
			+ '<td>' + esc( file.download_count || 0 ) + '</td>'
			+ '<td>'
				+ '<button type="button" class="button button-small isfm-btn-edit-file" data-file-id="' + esc( file.id ) + '">' + esc( ISFM.i18n.edit ) + '</button> '
				+ '<button type="button" class="button button-small isfm-btn-delete-file" data-file-id="' + esc( file.id ) + '">' + esc( ISFM.i18n.remove ) + '</button>'
			+ '</td>'
			+ '</tr>';
	}

	function appendFileRow( file ) {
		$( '#isfm-no-files-row' ).remove();
		$fileList.append( fileRowHtml( file ) );
	}

	// -------------------------------------------------------------------------
	// Tabs
	// -------------------------------------------------------------------------
	$( '.isfm-tab-nav' ).on(
		'click',
		'.isfm-tab-btn',
		function () {
			var tab = $( this ).data( 'tab' );
			$( '.isfm-tab-btn' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
			$( this ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
			$( '.isfm-tab-panel' ).prop( 'hidden', true ).removeClass( 'is-active' );
			$( '.isfm-tab-panel[data-tab="' + tab + '"]' ).prop( 'hidden', false ).addClass( 'is-active' );

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
			handle: '.isfm-sort-handle',
			placeholder: 'isfm-sortable-placeholder',
			update: function () {
				var order = {};
				$fileList.find( '.isfm-file-row' ).each(
					function ( i ) {
						order[ $( this ).data( 'file-id' ) ] = i;
					}
				);
				$.post(
					ISFM.ajaxUrl,
					{
						action: 'isfm_save_file_order',
						nonce:  ISFM.nonce,
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
		'.isfm-btn-edit-file',
		function () {
			var $btn   = $( this );
			var fileId = $btn.data( 'file-id' );
			var $row   = $btn.closest( '.isfm-file-row' );

			// Toggle: close if this row's editor is already open below it.
			var $existing = $row.next( '.isfm-file-edit-row' );
			if ( $existing.length ) {
				$existing.remove();
				return;
			}
			// Close any other open editor first.
			$fileList.find( '.isfm-file-edit-row' ).remove();

			var $titleCell = $row.find( '.isfm-file-title' );
			var title      = $titleCell.data( 'title' ) || '';
			var desc       = $titleCell.data( 'description' ) || '';

			var $editor = $(
				'<tr class="isfm-file-edit-row" data-edit-for="' + fileId + '">'
				+ '<td colspan="7">'
				+ '<div class="isfm-file-edit">'
				+ '<p><label>' + esc( ISFM.i18n.title ) + '<br>'
				+ '<input type="text" class="widefat isfm-edit-title" />'
				+ '</label></p>'
				+ '<p><label>' + esc( ISFM.i18n.description ) + '<br>'
				+ '<textarea class="widefat isfm-edit-description" rows="3"></textarea>'
				+ '</label></p>'
				+ '<p>'
				+ '<button type="button" class="button button-primary isfm-edit-save">' + esc( ISFM.i18n.save ) + '</button> '
				+ '<button type="button" class="button isfm-edit-cancel">' + esc( ISFM.i18n.cancel ) + '</button>'
				+ '<span class="isfm-edit-status" aria-live="polite"></span>'
				+ '</p>'
				+ '</div>'
				+ '</td>'
				+ '</tr>'
			);
			$editor.find( '.isfm-edit-title' ).val( title );
			$editor.find( '.isfm-edit-description' ).val( desc );
			$row.after( $editor );
			$editor.find( '.isfm-edit-title' ).trigger( 'focus' );
		}
	);

	$fileList.on(
		'click',
		'.isfm-edit-cancel',
		function () {
			$( this ).closest( '.isfm-file-edit-row' ).remove();
		}
	);

	$fileList.on(
		'click',
		'.isfm-edit-save',
		function () {
			var $editor  = $( this ).closest( '.isfm-file-edit-row' );
			var fileId   = $editor.data( 'edit-for' );
			var $row     = $fileList.find( '.isfm-file-row[data-file-id="' + fileId + '"]' );
			var newTitle = $editor.find( '.isfm-edit-title' ).val();
			var newDesc  = $editor.find( '.isfm-edit-description' ).val();
			var $status  = $editor.find( '.isfm-edit-status' );
			var $save    = $( this );

			$save.prop( 'disabled', true );
			$status.text( ISFM.i18n.saving );

			$.post(
				ISFM.ajaxUrl,
				{
					action:      'isfm_update_file_meta',
					nonce:       ISFM.nonce,
					file_id:     fileId,
					title:       newTitle,
					description: newDesc,
				},
				function ( res ) {
					if ( res.success ) {
						var $titleCell = $row.find( '.isfm-file-title' );
						$titleCell
						.text( res.data.file.title || res.data.file.file_name || res.data.file.external_url )
						.attr( 'data-title', res.data.file.title || '' )
						.attr( 'data-description', res.data.file.description || '' );
						$editor.remove();
					} else {
						$save.prop( 'disabled', false );
						$status.text( ( res.data && res.data.message ) ? res.data.message : ISFM.i18n.error );
					}
				}
			).fail(
				function () {
					$save.prop( 'disabled', false );
					$status.text( ISFM.i18n.networkError );
				}
			);
		}
	);

	// -------------------------------------------------------------------------
	// Remove file
	// -------------------------------------------------------------------------
	$fileList.on(
		'click',
		'.isfm-btn-delete-file',
		function () {
			if ( ! window.confirm( ISFM.i18n.confirmDelete ) ) {
				return;
			}
			var $btn   = $( this );
			var fileId = $btn.data( 'file-id' );
			var $row   = $btn.closest( '.isfm-file-row' );

			$btn.prop( 'disabled', true );

			$.post(
				ISFM.ajaxUrl,
				{
					action:  'isfm_delete_file',
					nonce:   ISFM.nonce,
					file_id: fileId,
				},
				function ( res ) {
					if ( res.success ) {
						$row.next( '.isfm-file-edit-row' ).remove();
						$row.remove();
						if ( $fileList.find( '.isfm-file-row' ).length === 0 ) {
							$fileList.append( '<tr class="isfm-no-files" id="isfm-no-files-row"><td colspan="7">' + esc( ISFM.i18n.noFiles ) + '</td></tr>' );
						}
					} else {
						$btn.prop( 'disabled', false );
						alert( res.data && res.data.message ? res.data.message : ISFM.i18n.error );
					}
				}
			);
		}
	);

	// -------------------------------------------------------------------------
	// Upload — dropzone + file input
	// -------------------------------------------------------------------------
	var $dropzone  = $( '#isfm-dropzone' );
	var $fileInput = $( '#isfm-file-input' );
	var $queue     = $( '#isfm-upload-queue' );

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
			'<li class="isfm-upload-item">'
			+ '<span class="isfm-upload-name"></span>'
			+ '<span class="isfm-upload-bar"><span class="isfm-upload-bar-fill"></span></span>'
			+ '<span class="isfm-upload-status"></span>'
			+ '</li>'
		);
		$item.find( '.isfm-upload-name' ).text( file.name );
		$queue.append( $item );

		var fd = new FormData();
		fd.append( 'action',      'isfm_upload_file' );
		fd.append( 'nonce',       ISFM.nonce );
		fd.append( 'download_id', postId );
		fd.append( 'file',        file );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ISFM.ajaxUrl, true );

		xhr.upload.addEventListener(
			'progress',
			function ( evt ) {
				if ( evt.lengthComputable ) {
					var pct = Math.round( ( evt.loaded / evt.total ) * 100 );
					$item.find( '.isfm-upload-bar-fill' ).css( 'width', pct + '%' );
					$item.find( '.isfm-upload-status' ).text( pct + '%' );
				}
			}
		);

		xhr.onload = function () {
			try {
				var res = JSON.parse( xhr.responseText );
				if ( res.success ) {
					$item.addClass( 'is-done' );
					$item.find( '.isfm-upload-bar-fill' ).css( 'width', '100%' );
					$item.find( '.isfm-upload-status' ).text( '✓' );
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
					$item.find( '.isfm-upload-status' ).text( res.data && res.data.message ? res.data.message : ISFM.i18n.error );
				}
			} catch ( err ) {
				$item.addClass( 'is-error' );
				$item.find( '.isfm-upload-status' ).text( ISFM.i18n.serverError );
			}
		};

		xhr.onerror = function () {
			$item.addClass( 'is-error' );
			$item.find( '.isfm-upload-status' ).text( ISFM.i18n.networkError );
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
		var $list = $( '#isfm-browse-list' );
		if ( ! $list.length ) {
			return; }

		$.post(
			ISFM.ajaxUrl,
			{
				action:      'isfm_browse_category',
				nonce:       ISFM.nonce,
				download_id: postId,
			},
			function ( res ) {
				browseLoaded = true;
				$list.empty();
				if ( ! res.success || ! res.data.files || res.data.files.length === 0 ) {
					$list.append( '<li class="isfm-browse-empty">' + esc( ISFM.i18n.noFolderFiles ) + '</li>' );
					return;
				}
				res.data.files.forEach(
					function ( item ) {
						var $li = $( '<li class="isfm-browse-item"></li>' );
						$li.append( '<span class="isfm-browse-name">' + esc( item.name ) + '</span>' );
						$li.append( '<span class="isfm-browse-size">' + esc( formatSize( item.size ) ) + '</span>' );
						if ( item.tracked ) {
								$li.append( '<span class="isfm-browse-tag">' + esc( ISFM.i18n.alreadyLinked ) + '</span>' );
								$li.addClass( 'is-tracked' );
						} else {
							var $btn = $( '<button type="button" class="button button-small"></button>' ).text( ISFM.i18n.linkButton );
							$btn.on(
								'click',
								function () {
									$btn.prop( 'disabled', true ).text( ISFM.i18n.linking );
									$.post(
										ISFM.ajaxUrl,
										{
											action:      'isfm_import_file',
											nonce:       ISFM.nonce,
											download_id: postId,
											rel_path:    item.rel,
										},
										function ( r ) {
											if ( r.success ) {
												appendFileRow( r.data.file );
												$li.addClass( 'is-tracked' );
												$btn.replaceWith( '<span class="isfm-browse-tag">' + esc( ISFM.i18n.linked ) + '</span>' );
											} else {
												$btn.prop( 'disabled', false ).text( ISFM.i18n.retry );
												alert( r.data && r.data.message ? r.data.message : ISFM.i18n.error );
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
	$( '.isfm-tab-nav' ).on(
		'click',
		'.isfm-tab-btn',
		function () {
			browseLoaded = false;
		}
	);

	// -------------------------------------------------------------------------
	// External link
	// -------------------------------------------------------------------------
	$( document ).on(
		'click',
		'.isfm-btn-ext-save',
		function () {
			var url      = $( '#isfm-ext-url' ).val().trim();
			var title    = $( '#isfm-ext-title' ).val().trim();
			var isMirror = $( '#isfm-ext-mirror' ).is( ':checked' ) ? 1 : 0;

			if ( ! url ) {
				$( '#isfm-ext-url' ).trigger( 'focus' );
				return;
			}

			$.post(
				ISFM.ajaxUrl,
				{
					action:      'isfm_add_external',
					nonce:       ISFM.nonce,
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
						alert( res.data && res.data.message ? res.data.message : ISFM.i18n.error );
					}
				}
			);
		}
	);

} )( jQuery, ISFM );
