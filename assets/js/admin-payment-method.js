/**
 * Order screen: take Stripe's immediate SEPA option out of the payment-method
 * select.
 *
 * That select is not built from `woocommerce_available_payment_gateways`. It is
 * built by WC_Meta_Box_Order_Data::output() from every registered, enabled
 * gateway, with no filter of its own, so there is no server-side way to leave
 * one out of it.
 *
 * The option is removed unless it is the order's current payment method, which
 * is what keeps historical orders showing their real method rather than
 * "Other". Removing it here is presentation only: the server refuses a switch
 * to it on save whether this ran or not.
 */
( function ( $ ) {
	'use strict';

	var data = window.dahuDeferredSepaPaymentMethod || {};

	$( function () {
		var ids = data.nativeIds || [];
		var $select = $( '#_payment_method' );

		if ( ! ids.length || ! $select.length ) {
			return;
		}

		$select.find( 'option' ).each( function () {
			if ( ids.indexOf( this.value ) !== -1 && ! this.defaultSelected ) {
				$( this ).remove();
			}
		} );

		$select.trigger( 'change.select2' );
	} );
} )( jQuery );
