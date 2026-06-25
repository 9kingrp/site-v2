(function ($) {
	'use strict';

	if (typeof nk1of1 === 'undefined') {
		return;
	}

	var i18n = nk1of1.i18n;

	/* ── Build modal HTML ─────────────────────────────────────────────── */
	var modalHtml =
		'<div id="nk-1of1-overlay" class="nk-1of1-overlay">' +
			'<div class="nk-1of1-modal">' +
				'<button type="button" class="nk-1of1-close" aria-label="Close">&times;</button>' +

				/* Header */
				'<div class="nk-1of1-header">' +
					( i18n.badge ? '<div class="nk-1of1-badge">' + i18n.badge + '</div>' : '' ) +
					'<h2>' + i18n.title + '</h2>' +
				'</div>' +

				/* Body — step 1: question */
				'<div class="nk-1of1-body">' +
					'<p class="nk-1of1-desc">' + i18n.description + '</p>' +

					'<div id="nk-1of1-step-question" class="nk-1of1-step">' +
						'<p class="nk-1of1-question">' + i18n.question + '</p>' +
						'<div class="nk-1of1-buttons">' +
							'<button type="button" class="nk-1of1-btn nk-1of1-btn--yes">' + i18n.yesLabel + '</button>' +
							'<button type="button" class="nk-1of1-btn nk-1of1-btn--no">' + i18n.noLabel + '</button>' +
						'</div>' +
					'</div>' +

					/* Body — step 2: ticket input */
					'<div id="nk-1of1-step-ticket" class="nk-1of1-step" style="display:none;">' +
						'<label for="nk-1of1-ticket-input" class="nk-1of1-label">' + i18n.ticketLabel + '</label>' +
						'<p class="nk-1of1-hint">' + i18n.ticketHint + '</p>' +
						'<input type="text" id="nk-1of1-ticket-input" class="nk-1of1-input" placeholder="' + i18n.ticketPlaceholder + '" autocomplete="off" />' +
						'<div id="nk-1of1-feedback" class="nk-1of1-feedback"></div>' +
						'<div class="nk-1of1-buttons">' +
							'<button type="button" id="nk-1of1-verify-btn" class="nk-1of1-btn nk-1of1-btn--verify">' + i18n.verifyBtn + '</button>' +
							'<button type="button" class="nk-1of1-btn nk-1of1-btn--cancel">' + i18n.cancelBtn + '</button>' +
						'</div>' +
					'</div>' +

					/* Body — step 3: redirect message */
					'<div id="nk-1of1-step-redirect" class="nk-1of1-step" style="display:none;">' +
						'<p class="nk-1of1-redirect-msg">' + i18n.redirectMsg + '</p>' +
					'</div>' +

				'</div>' +
			'</div>' +
		'</div>';

	/* ── Append modal to page ─────────────────────────────────────────── */
	$(document.body).append(modalHtml);

	/* ── Block express-checkout buttons until ticket is verified ────────── */
	// PPCP (PayPal/GooglePay/ApplePay) and Stripe Express Checkout (Link/
	// GooglePay/ApplePay) render inside iframes we can't intercept.
	// Cover them with an overlay that opens the 1of1 modal on click,
	// then remove it after approval.
	var expressBlocked = true;
	var expressSelectors = '.ppc-button-wrapper, #wc-stripe-express-checkout-element';

	function blockExpressButtons() {
		if (!expressBlocked) return;
		$(expressSelectors).each(function () {
			var $wrapper = $(this);
			if ($wrapper.find('.nk-1of1-express-block').length) {
				return; // already blocked
			}
			$wrapper.css('position', 'relative');
			$wrapper.append(
				'<div class="nk-1of1-express-block" style="' +
					'position:absolute;inset:0;z-index:9999;cursor:pointer;' +
					'background:rgba(0,0,0,0.45);border-radius:8px;' +
					'display:flex;align-items:center;justify-content:center;' +
					'font-size:12px;color:#fff;font-weight:600;letter-spacing:0.5px;' +
				'">' +
					'<span style="background:rgba(0,0,0,0.6);padding:4px 12px;border-radius:6px;">Ticket approval required</span>' +
				'</div>'
			);
		});
	}

	function unblockExpressButtons() {
		expressBlocked = false;
		$('.nk-1of1-express-block').remove();
	}

	// Block immediately (if wrappers exist already) + watch the DOM
	// for express-checkout wrappers that load asynchronously.
	blockExpressButtons();
	var expressObserver = new MutationObserver(function () {
		blockExpressButtons();
	});
	expressObserver.observe(document.body, { childList: true, subtree: true });

	// Open modal when clicking an express-checkout overlay
	$(document).on('click', '.nk-1of1-express-block', function (e) {
		e.preventDefault();
		e.stopImmediatePropagation();
		openModal();
	});

	var $overlay    = $('#nk-1of1-overlay');
	var $stepQ      = $('#nk-1of1-step-question');
	var $stepT      = $('#nk-1of1-step-ticket');
	var $stepR      = $('#nk-1of1-step-redirect');
	var $input      = $('#nk-1of1-ticket-input');
	var $feedback   = $('#nk-1of1-feedback');
	var $verifyBtn  = $('#nk-1of1-verify-btn');

	/* ── Helpers ──────────────────────────────────────────────────────── */
	function openModal() {
		$stepQ.show();
		$stepT.hide();
		$stepR.hide();
		$input.val('');
		$feedback.html('').removeClass('nk-1of1-feedback--error nk-1of1-feedback--success');
		$verifyBtn.prop('disabled', false).text(i18n.verifyBtn);
		$overlay.addClass('nk-1of1-overlay--visible');
		$('body').css('overflow', 'hidden');
	}

	function closeModal() {
		$overlay.removeClass('nk-1of1-overlay--visible');
		$('body').css('overflow', '');
	}

	/* ── Intercept add-to-cart ────────────────────────────────────────── */
	$(document).on('click', '.single_add_to_cart_button', function (e) {
		// Only intercept on the 1of1 product page (script is only enqueued there)
		// If ticket is already set (hidden field), let it through
		if ($('#nk-1of1-ticket-hidden').length && $('#nk-1of1-ticket-hidden').val()) {
			return; // allow form submit
		}

		e.preventDefault();
		e.stopImmediatePropagation();
		openModal();
	});

	/* ── Close modal ──────────────────────────────────────────────────── */
	$overlay.on('click', '.nk-1of1-close, .nk-1of1-btn--cancel', function () {
		closeModal();
	});
	$overlay.on('click', function (e) {
		if ($(e.target).is($overlay)) {
			closeModal();
		}
	});

	/* ── Step 1: "No" → redirect to Discord ──────────────────────────── */
	$overlay.on('click', '.nk-1of1-btn--no', function () {
		$stepQ.hide();
		$stepR.show();
		setTimeout(function () {
			window.open(nk1of1.discordTicketUrl, '_blank');
			closeModal();
		}, 1500);
	});

	/* ── Step 1: "Yes" → show ticket input ───────────────────────────── */
	$overlay.on('click', '.nk-1of1-btn--yes', function () {
		$stepQ.hide();
		$stepT.show();
		$input.focus();
	});

	/* ── Step 2: Verify ticket ───────────────────────────────────────── */
	$verifyBtn.on('click', function () {
		var ticketValue = $.trim($input.val());

		if (!ticketValue) {
			$feedback
				.html(i18n.ticketPlaceholder)
				.removeClass('nk-1of1-feedback--success')
				.addClass('nk-1of1-feedback--error');
			return;
		}

		$verifyBtn.prop('disabled', true).text(i18n.verifying);
		$feedback.html('').removeClass('nk-1of1-feedback--error nk-1of1-feedback--success');

		// Detect if the input is a Discord snowflake (channel ID) or a channel name
		var isChannelId = /^\d{17,20}$/.test(ticketValue);
		var payload = isChannelId
			? { channel_id: ticketValue }
			: { ticket_name: ticketValue };

		$.ajax({
			url: nk1of1.restUrl,
			method: 'POST',
			beforeSend: function (xhr) {
				xhr.setRequestHeader('X-WP-Nonce', nk1of1.nonce);
			},
			contentType: 'application/json',
			data: JSON.stringify(payload),
		})
			.done(function (res) {
				if (res.valid) {
					$feedback
						.html(res.message)
						.removeClass('nk-1of1-feedback--error')
						.addClass('nk-1of1-feedback--success');

					// Inject hidden field into the add-to-cart form & submit
					setTimeout(function () {
						var $form = $('form.cart');
						if (!$('#nk-1of1-ticket-hidden').length) {
							$form.append(
								'<input type="hidden" id="nk-1of1-ticket-hidden" name="nk_1of1_ticket" />'
							);
						}
						$('#nk-1of1-ticket-hidden').val(ticketValue);
						closeModal();

						// Unblock express-checkout buttons now that ticket is verified
						unblockExpressButtons();
						expressObserver.disconnect();

						// Trigger the native add-to-cart
						$form.find('.single_add_to_cart_button').trigger('click');
					}, 800);
				} else {
					$feedback
						.html(res.message)
						.removeClass('nk-1of1-feedback--success')
						.addClass('nk-1of1-feedback--error');
					$verifyBtn.prop('disabled', false).text(i18n.verifyBtn);
				}
			})
			.fail(function () {
				$feedback
					.html(i18n.error)
					.removeClass('nk-1of1-feedback--success')
					.addClass('nk-1of1-feedback--error');
				$verifyBtn.prop('disabled', false).text(i18n.verifyBtn);
			});
	});

	/* Allow Enter key in ticket input */
	$input.on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			$verifyBtn.trigger('click');
		}
	});

})(jQuery);
