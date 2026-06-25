(function () {
	'use strict';

	// Only fire when the blacklist query param is present
	var params = new URLSearchParams(window.location.search);
	if (!params.has('nk_blacklisted')) {
		return;
	}

	/* ── Build modal HTML ─────────────────────────────────────────────── */
	var shieldSvg =
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
			'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>' +
			'<line x1="12" y1="8" x2="12" y2="12"/>' +
			'<line x1="12" y1="16" x2="12.01" y2="16"/>' +
		'</svg>';

	var overlay = document.createElement('div');
	overlay.id = 'nk-blacklist-overlay';
	overlay.className = 'nk-blacklist-overlay';
	overlay.innerHTML =
		'<div class="nk-blacklist-modal">' +
			'<button type="button" class="nk-blacklist-close" aria-label="Close">&times;</button>' +

			'<div class="nk-blacklist-header">' +
				'<div class="nk-blacklist-badge">Account Blocked</div>' +
				'<h2>Access Denied</h2>' +
			'</div>' +

			'<div class="nk-blacklist-icon">' + shieldSvg + '</div>' +

			'<div class="nk-blacklist-body">' +
				'<p class="nk-blacklist-message">' +
					'Your Discord account has been blocked from accessing this store.' +
				'</p>' +
				'<p class="nk-blacklist-support">' +
					'If you believe this is an error, please open a support ticket in our Discord server.' +
				'</p>' +
				'<button type="button" class="nk-blacklist-btn nk-blacklist-dismiss">Dismiss</button>' +
			'</div>' +
		'</div>';

	document.body.appendChild(overlay);

	/* ── Show modal on next frame ─────────────────────────────────────── */
	requestAnimationFrame(function () {
		overlay.classList.add('nk-blacklist-overlay--visible');
		document.body.style.overflow = 'hidden';
	});

	/* ── Clean the URL so the modal doesn't re-trigger on refresh ───── */
	if (window.history && window.history.replaceState) {
		params.delete('nk_blacklisted');
		var clean = window.location.pathname;
		var remaining = params.toString();
		if (remaining) {
			clean += '?' + remaining;
		}
		window.history.replaceState(null, '', clean);
	}

	/* ── Close behaviour ──────────────────────────────────────────────── */
	function closeModal() {
		overlay.classList.remove('nk-blacklist-overlay--visible');
		document.body.style.overflow = '';
		setTimeout(function () {
			overlay.remove();
		}, 350);
	}

	overlay.querySelector('.nk-blacklist-close').addEventListener('click', closeModal);
	overlay.querySelector('.nk-blacklist-dismiss').addEventListener('click', closeModal);

	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) {
			closeModal();
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && overlay.classList.contains('nk-blacklist-overlay--visible')) {
			closeModal();
		}
	});

})();
