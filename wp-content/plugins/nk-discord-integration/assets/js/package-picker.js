/**
 * Nine Kings — Package Option Picker
 *
 * Handles selection of a product option on package product pages.
 * The visual picker (#nk-package-picker) sits OUTSIDE the <form>.
 * The hidden input (#nk_package_choice) sits INSIDE the <form>.
 * This script bridges the two.
 */
(function ($) {
	'use strict';

	var $picker = $('#nk-package-picker');
	if (!$picker.length) {
		return;
	}

	var $hidden  = $('#nk_package_choice');
	var $options = $picker.find('.nk-package-option');
	var $form    = $hidden.closest('form.cart');

	/**
	 * Select an option card.
	 */
	function selectOption($card) {
		// Deselect all
		$options
			.removeClass('nk-package-option--selected')
			.attr('aria-checked', 'false');

		// Select clicked
		$card
			.addClass('nk-package-option--selected')
			.attr('aria-checked', 'true');

		// Update hidden field inside the form
		$hidden.val($card.data('product-id'));

		// Remove error state if present
		$picker.removeClass('nk-package-picker--error');
	}

	// Click handler
	$options.on('click', function () {
		selectOption($(this));
	});

	// Keyboard support (Enter / Space)
	$options.on('keydown', function (e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			selectOption($(this));
		}
	});

	// Prevent form submission without a selection
	if ($form.length) {
		$form.on('submit', function (e) {
			if (!$hidden.val()) {
				e.preventDefault();
				$picker.addClass('nk-package-picker--error');

				// Scroll to picker
				$('html, body').animate({
					scrollTop: $picker.offset().top - 100
				}, 300);
			}
		});
	}

})(jQuery);
