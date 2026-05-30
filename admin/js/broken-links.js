/* global jQuery, isfmBrokenLinks */
(function ($) {
	'use strict';

	const dialog = $('#isfm-recover-dialog');
	const $status = dialog.find('.isfm-recover-status');
	const $summary = dialog.find('.isfm-recover-summary');
	const $title = dialog.find('#isfm-recover-title');
	let currentFileId = 0;

	function open() {
		dialog.show().attr('aria-hidden', 'false');
	}
	function close() {
		dialog.hide().attr('aria-hidden', 'true');
		$status.removeClass('is-error').text('');
		$summary.empty();
		dialog.find('[data-action]').hide();
		dialog.find('.isfm-recover-cross-cat').attr('hidden', true);
		currentFileId = 0;
	}
	function setStatus(msg, isError) {
		$status.toggleClass('is-error', !!isError).text(msg);
	}

	function ajax(action, data, files) {
		const formData = new FormData();
		formData.append('action', 'isfm_recover_' + action);
		formData.append('nonce', isfmBrokenLinks.nonce);
		formData.append('file_id', currentFileId);
		Object.keys(data || {}).forEach((k) => formData.append(k, data[k]));
		if (files && files.length) {
			formData.append('replacement', files[0]);
		}
		return $.ajax({
			url: isfmBrokenLinks.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
		});
	}

	function probe(fileId) {
		currentFileId = fileId;
		setStatus('Looking up file…', false);
		open();

		ajax('probe', {})
			.done(function (res) {
				if (!res || !res.success) {
					setStatus(isfmBrokenLinks.i18n.generic_error, true);
					return;
				}
				const d = res.data;
				$title.text(d.file_name || 'Recover File');
				$summary.html(
					'<p><strong>' + $('<i>').text(d.download_title || '').html() + '</strong><br>' +
					'<code>' + $('<i>').text(d.expected_folder || '').html() + '/' + $('<i>').text(d.file_name || '').html() + '</code></p>'
				);
				setStatus('', false);

				if (d.candidate_found && d.is_cross_cat) {
					dialog.find('.isfm-recover-cross-cat').removeAttr('hidden');
					$summary.append(
						'<p>File found in folder: <code>' + $('<i>').text(d.candidate_folder || '').html() + '</code></p>'
					);
					dialog.find('[data-action="move_back"]').show();
					dialog.find('[data-action="reassign"]').show();
					dialog.find('[data-action="split"]').show();
				} else if (!d.candidate_found) {
					$summary.append(
						'<p><em>File not found anywhere under the downloads folder. Use Reupload or Detach.</em></p>'
					);
				}
			})
			.fail(function () {
				setStatus(isfmBrokenLinks.i18n.generic_error, true);
			});
	}

	function runAction(action) {
		const confirmKey = 'confirm' + action.charAt(0).toUpperCase() + action.slice(1).replace(/_([a-z])/g, (m, c) => c.toUpperCase());
		const confirmMsg = isfmBrokenLinks.i18n[confirmKey];
		if (confirmMsg && !window.confirm(confirmMsg)) {
			return;
		}
		setStatus('Working…', false);
		ajax(action, {})
			.done(function (res) {
				if (res && res.success) {
					setStatus(res.data.message || 'Done.', false);
					setTimeout(function () { window.location.reload(); }, 900);
				} else {
					setStatus((res && res.data && res.data.message) || isfmBrokenLinks.i18n.generic_error, true);
				}
			})
			.fail(function (xhr) {
				const msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || isfmBrokenLinks.i18n.generic_error;
				setStatus(msg, true);
			});
	}

	function runReupload(files) {
		setStatus('Uploading…', false);
		ajax('reupload', {}, files)
			.done(function (res) {
				if (res && res.success) {
					setStatus(res.data.message || 'Done.', false);
					setTimeout(function () { window.location.reload(); }, 900);
				} else {
					setStatus((res && res.data && res.data.message) || isfmBrokenLinks.i18n.generic_error, true);
				}
			})
			.fail(function (xhr) {
				const msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || isfmBrokenLinks.i18n.generic_error;
				setStatus(msg, true);
			});
	}

	$(document).on('click', '.isfm-recover-btn', function (e) {
		e.preventDefault();
		probe(parseInt($(this).data('file-id'), 10));
	});

	dialog.on('click', '.isfm-recover-close, .isfm-recover-dialog__backdrop', close);

	dialog.on('click', '[data-action]', function () {
		runAction($(this).data('action'));
	});

	dialog.on('change', '.isfm-recover-file', function () {
		if (this.files && this.files.length) {
			runReupload(this.files);
		}
	});

	// ESC closes.
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && dialog.is(':visible')) {
			close();
		}
	});
})(jQuery);
