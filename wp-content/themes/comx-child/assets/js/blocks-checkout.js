/**
 * NK Discord Integration — WooCommerce Blocks Checkout
 *
 * Pre-fills hidden billing/contact fields with Discord placeholder data
 * so the blocks checkout form passes validation without user input.
 */
(function () {
	'use strict';

	var data = window.nkDiscordCheckout || {};
	if ( ! data.billingAddress ) {
		return;
	}

	/* ── helpers ─────────────────────────────────────────────────────── */

	function setNativeValue( el, value ) {
		var setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype, 'value'
		).set;
		setter.call( el, value );
		el.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function stripRequired() {
		var selectors = [
			'.wc-block-checkout__contact-fields',
			'.wp-block-woocommerce-checkout-contact-information-block',
			'.wc-block-checkout__billing-fields',
			'.wp-block-woocommerce-checkout-billing-address-block',
			'.wc-block-checkout__shipping-fields',
			'.wp-block-woocommerce-checkout-shipping-address-block',
			'.wp-block-woocommerce-checkout-shipping-method-block',
			'.wc-block-checkout__order-notes',
			'.wp-block-woocommerce-checkout-order-note-block',
		];
		selectors.forEach( function ( sel ) {
			var block = document.querySelector( sel );
			if ( ! block ) return;
			block.querySelectorAll( '[required]' ).forEach( function ( el ) {
				el.removeAttribute( 'required' );
				el.removeAttribute( 'aria-required' );
			} );
		} );
	}

	function fillDomInputs() {
		var b   = data.billingAddress;
		var map = {
			'#email':              b.email,
			'#billing-first_name': b.first_name,
			'#billing-last_name':  b.last_name,
			'#billing-address_1':  b.address_1,
			'#billing-address_2':  b.address_2,
			'#billing-city':       b.city,
			'#billing-state':      b.state,
			'#billing-postcode':   b.postcode,
			'#billing-phone':      b.phone,
		};
		var filled = 0;
		for ( var id in map ) {
			var el = document.querySelector( id );
			if ( el ) {
				setNativeValue( el, map[ id ] || '' );
				filled++;
			}
		}
		var cs = document.querySelector( '#billing-country' );
		if ( cs && b.country ) {
			cs.value = b.country;
			cs.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			filled++;
		}
		return filled;
	}

	function setStoreData() {
		if ( typeof wp === 'undefined' || ! wp.data || ! wp.data.dispatch ) {
			return false;
		}
		var cart = wp.data.dispatch( 'wc/store/cart' );
		if ( ! cart || typeof cart.setBillingAddress !== 'function' ) {
			return false;
		}
		cart.setBillingAddress( data.billingAddress );
		if ( typeof cart.setShippingAddress === 'function' ) {
			cart.setShippingAddress( data.billingAddress );
		}
		return true;
	}

	function clearErrors() {
		if ( typeof wp === 'undefined' || ! wp.data || ! wp.data.dispatch ) {
			return;
		}
		var v = wp.data.dispatch( 'wc/store/validation' );
		if ( v && typeof v.clearValidationErrors === 'function' ) {
			v.clearValidationErrors();
		}
	}

	/* ── main loop ──────────────────────────────────────────────────── */

	var attempts = 0;
	var timer = setInterval( function () {
		attempts++;
		stripRequired();
		fillDomInputs();
		var storeOk = setStoreData();
		clearErrors();
		if ( ( storeOk && attempts > 4 ) || attempts > 40 ) {
			clearInterval( timer );
		}
	}, 300 );

	/* ── safety net on Place Order click ────────────────────────────── */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest(
			'.wc-block-components-checkout-place-order-button'
		);
		if ( ! btn ) return;
		stripRequired();
		setStoreData();
		clearErrors();
	}, true );
})();
