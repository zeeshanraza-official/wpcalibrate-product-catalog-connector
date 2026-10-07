/**
 * WPCalibrate Product Catalog Connector - Admin Scripts
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// Feed URL Copy to Clipboard.
		$('.wpcal-pcc-copy-btn').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var targetId = $btn.data('target');
			var $input = $('#' + targetId);
			var url = $input.val();

			if (!url) {
				return;
			}

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(url).then(function() {
					showCopiedFeedback($btn);
				}).catch(function() {
					fallbackCopy($input, $btn);
				});
			} else {
				fallbackCopy($input, $btn);
			}
		});

		function showCopiedFeedback($btn) {
			var origText = $btn.html();
			$btn.text(wpcalPccData.strings.copied || 'Copied!').prop('disabled', true);
			setTimeout(function() {
				$btn.html(origText).prop('disabled', false);
			}, 2000);
		}

		function fallbackCopy($input, $btn) {
			$input.select();
			try {
				document.execCommand('copy');
				showCopiedFeedback($btn);
			} catch (err) {
				alert(wpcalPccData.strings.copyFailed || 'Failed to copy URL');
			}
		}

		// Confirmation before token rotation.
		$('.wpcal-pcc-confirm-rotate').on('click', function(e) {
			if (!confirm(wpcalPccData.strings.confirmRotate)) {
				e.preventDefault();
			}
		});

		// Confirmation before manual sync.
		$('.wpcal-pcc-confirm-sync').on('click', function(e) {
			if (!confirm(wpcalPccData.strings.confirmSync)) {
				e.preventDefault();
			}
		});

		// Auto-select text in read-only input on focus.
		$('.wpcal-pcc-url-input').on('focus', function() {
			$(this).select();
		});
	});
})(jQuery);
