/**
 * Payment-methods page touch-ups.
 *
 * Two things WooCommerce gives no server-side hook for on the versions this
 * plugin supports:
 *
 * 1. Marking which saved IBAN the shop has approved. The documented filter
 *    `woocommerce_saved_payment_methods_list` does not fire on that template,
 *    verified by probe, so the row is found by the last four digits that are
 *    actually rendered rather than by a data structure that may not exist.
 *
 * 2. Renaming "Use as default" on SEPA rows, because in this plugin's flow
 *    clicking it is what submits the IBAN for approval. Card rows, if the shop
 *    has any, keep the standard label.
 *
 * Both are presentation only: nothing here decides whether a mandate is
 * approved, and the page is correct with JavaScript switched off.
 */
( function () {
	'use strict';

	var data = window.dahuDeferredSepaAccount || {};

	function badgeApprovedRow() {
		if ( ! data.last4 || ! data.approvedLabel ) {
			return;
		}

		var cells = document.querySelectorAll(
			'.payment-method-method, .woocommerce-PaymentMethod--method'
		);

		Array.prototype.forEach.call( cells, function ( cell ) {
			if ( cell.querySelector( '.dahu-sepa-approved' ) ) {
				return;
			}

			if ( cell.textContent.indexOf( data.last4 ) === -1 ) {
				return;
			}

			var badge = document.createElement( 'span' );

			badge.className = 'dahu-sepa-approved';
			badge.textContent = data.approvedLabel;
			cell.appendChild( badge );
		} );
	}

	function relabelSubmitButtons() {
		if ( ! data.submitLabel ) {
			return;
		}

		var rows = document.querySelectorAll(
			'.account-payment-methods-table tr.payment-method, .woocommerce-PaymentMethod'
		);

		Array.prototype.forEach.call( rows, function ( row ) {
			var method = row.querySelector(
				'.payment-method-method, .woocommerce-PaymentMethod--method'
			);
			var button = row.querySelector( '.payment-method-actions .button.default' );

			if ( ! method || ! button ) {
				return;
			}

			if ( /sepa|iban/i.test( method.textContent ) ) {
				button.textContent = data.submitLabel;
			}
		} );
	}

	function run() {
		badgeApprovedRow();
		relabelSubmitButtons();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
