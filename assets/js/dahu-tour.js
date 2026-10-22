/*
 * THE ONE-TIME TOUR -- copied, byte for byte, into every plugin.
 *
 * Points at the real controls on the real screen, rather than describing
 * them somewhere else. Three or four stops, then it is gone for good.
 *
 * WHAT IT REFUSES TO DO. It never blocks the page: no modal, no overlay that
 * swallows clicks. Somebody who wants to get on with their work can ignore
 * it entirely, and that is the difference between a tour and a nag. It also
 * never shows twice — the server records that it played, and a tour that
 * reappears is the single fastest way to make a product feel broken.
 *
 * A step whose target is missing is SKIPPED, not shown pointing at nothing:
 * screens differ by what the shop has configured, and a tour that points at
 * an absent button teaches the wrong thing.
 *
 * Single source of truth: /Dev/dahu-public/UI-ADMIN-DAHU.md
 */
( function () {
	'use strict';

	var data = window.dahuTour;

	if ( ! data || ! data.steps || ! data.steps.length ) {
		return;
	}

	var steps = data.steps.filter( function ( step ) {
		return step.selector && document.querySelector( step.selector );
	} );

	if ( ! steps.length ) {
		return;
	}

	var index = 0;
	var bubble = document.createElement( 'div' );
	bubble.className = 'dahu-ui dahu-tour';
	bubble.setAttribute( 'role', 'dialog' );
	bubble.setAttribute( 'aria-live', 'polite' );
	document.body.appendChild( bubble );

	var ring = document.createElement( 'div' );
	ring.className = 'dahu-tour-ring';
	ring.setAttribute( 'aria-hidden', 'true' );
	document.body.appendChild( ring );

	function finish() {
		bubble.remove();
		ring.remove();
		window.removeEventListener( 'resize', place );
		window.removeEventListener( 'scroll', place, true );

		var body = new URLSearchParams();
		body.append( 'action', 'dahu_welcome_tour_done' );
		body.append( 'slug', data.slug );
		body.append( '_wpnonce', data.nonce );

		// Best effort, and deliberately so: a tour that failed to record
		// itself is a tour shown twice, which is a nuisance. A tour that
		// blocked the screen waiting for the answer would be worse.
		window.fetch( data.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {} );
	}

	function place() {
		var target = document.querySelector( steps[ index ].selector );

		if ( ! target ) {
			finish();
			return;
		}

		var box = target.getBoundingClientRect();
		var top = window.scrollY + box.bottom + 12;
		var left = window.scrollX + box.left;

		ring.style.top = ( window.scrollY + box.top - 6 ) + 'px';
		ring.style.left = ( window.scrollX + box.left - 6 ) + 'px';
		ring.style.width = ( box.width + 12 ) + 'px';
		ring.style.height = ( box.height + 12 ) + 'px';

		bubble.style.top = top + 'px';
		bubble.style.left = Math.max( 12, Math.min( left, window.innerWidth - bubble.offsetWidth - 20 ) ) + 'px';
	}

	function draw() {
		var step = steps[ index ];
		var last = index === steps.length - 1;

		bubble.innerHTML = '';

		var count = document.createElement( 'p' );
		count.className = 'dahu-tour__count';
		count.textContent = ( index + 1 ) + ' ' + data.i18n.of + ' ' + steps.length;

		var title = document.createElement( 'h2' );
		title.className = 'dahu-tour__title';
		title.textContent = step.title || '';

		var text = document.createElement( 'p' );
		text.className = 'dahu-tour__text';
		text.textContent = step.text || '';

		var row = document.createElement( 'p' );
		row.className = 'dahu-tour__row';

		var skip = document.createElement( 'button' );
		skip.type = 'button';
		skip.className = 'dahu-tour__skip';
		skip.textContent = data.i18n.skip;
		skip.addEventListener( 'click', finish );

		var next = document.createElement( 'button' );
		next.type = 'button';
		next.className = 'dahu-ui-btn dahu-ui-btn--go';
		next.textContent = last ? data.i18n.done : data.i18n.next;
		next.addEventListener( 'click', function () {
			if ( last ) {
				finish();
				return;
			}

			index++;
			draw();
		} );

		row.appendChild( skip );
		row.appendChild( next );
		bubble.appendChild( count );
		bubble.appendChild( title );
		bubble.appendChild( text );
		bubble.appendChild( row );

		var target = document.querySelector( step.selector );

		if ( target && target.scrollIntoView ) {
			target.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}

		place();
		next.focus();
	}

	window.addEventListener( 'resize', place );
	window.addEventListener( 'scroll', place, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && bubble.isConnected ) {
			finish();
		}
	} );

	draw();
}() );
