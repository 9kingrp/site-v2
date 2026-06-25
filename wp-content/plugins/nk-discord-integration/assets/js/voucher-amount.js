/**
 * Nine Kings — Voucher Amount Input
 *
 * Handles validation and UX for the custom voucher amount field
 * on single product pages. Prevents form submission without a
 * valid amount and provides visual error feedback.
 */
(function ($) {
	'use strict';

	var $wrapper = $('#nk-voucher-amount');
	if (!$wrapper.length) {
		return;
	}

	var $input = $('#nk_voucher_amount');
	var $form  = $input.closest('form.cart');

	/**
	 * Clear the error state when the user starts typing.
	 */
	$input.on('input', function () {
		$wrapper.removeClass('nk-voucher-amount--error');
	});

	/**
	 * Validate on form submit.
	 */
	if ($form.length) {
		$form.on('submit', function (e) {
			var val = parseFloat($input.val());
			var min = parseFloat($input.attr('min'));
			var max = parseFloat($input.attr('max'));

			if (isNaN(val) || val <= 0) {
				e.preventDefault();
				$wrapper.addClass('nk-voucher-amount--error');
				$input.trigger('focus');
				$('html, body').animate({
					scrollTop: $wrapper.offset().top - 100
				}, 300);
				return;
			}

			if (!isNaN(min) && val < min) {
				e.preventDefault();
				$wrapper.addClass('nk-voucher-amount--error');
				$input.trigger('focus');
				return;
			}

			if (!isNaN(max) && val > max) {
				e.preventDefault();
				$wrapper.addClass('nk-voucher-amount--error');
				$input.trigger('focus');
				return;
			}
		});
	}

})(jQuery);
