/**
 * Order screen: ask before charging.
 *
 * "Charge now" moves real money, so it gets a confirmation. The link is a
 * nonced admin-post URL, and the server checks the capability and the nonce
 * again — this is a courtesy to the person clicking, not a security measure.
 */
( function () {
	'use strict';

	var data = window.dahuDeferredSepaOrder || {};

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '.js-dahu-sepa-charge-now' );

		if ( ! link ) {
			return;
		}

		if ( data.confirm && ! window.confirm( data.confirm ) ) {
			event.preventDefault();
		}
	} );
} )();
