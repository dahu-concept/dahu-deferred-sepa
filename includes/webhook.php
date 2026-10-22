<?php
/**
 * This plugin's own Stripe webhook.
 *
 * SEPA is asynchronous. A charge sent today leaves Stripe in `processing` and
 * succeeds or fails days later, so something has to be listening when it does.
 *
 * It is a separate endpoint from the official Stripe plugin's, with its own
 * signing secret, and it acts only on charges this plugin made — identified by
 * the gateway on the order or by the `source` metadata the charge carried. It
 * cannot interfere with the other plugin's webhook, and the other plugin's
 * webhook secret is not needed here.
 *
 * Endpoint: /wp-json/dahu-deferred-sepa/v1/webhook
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'dahu-deferred-sepa/v1',
			'/webhook',
			array(
				'methods'  => 'POST',
				'callback' => 'dahu_deferred_sepa_webhook_handler',
				// The route is public because Stripe calls it; the signature
				// check below is what authenticates the caller, and nothing is
				// read from the body until it passes.
				'permission_callback' => '__return_true',
			)
		);
	}
);

/**
 * Handles a Stripe event.
 *
 * Unhandled event types are acknowledged with a 200 rather than refused, so
 * Stripe does not retry something this plugin has deliberately ignored.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function dahu_deferred_sepa_webhook_handler( WP_REST_Request $request ) {
	$payload = $request->get_body();

	if ( ! dahu_deferred_sepa_verify_webhook( $payload, $request->get_header( 'stripe-signature' ) ) ) {
		dahu_deferred_sepa_log( 'Webhook rejected: signature did not verify.', 'warning' );

		return new WP_REST_Response( 'invalid signature', 400 );
	}

	$event = json_decode( $payload, true );

	if ( ! is_array( $event ) ) {
		return new WP_REST_Response( 'bad payload', 400 );
	}

	$type   = isset( $event['type'] ) ? (string) $event['type'] : '';
	$object = isset( $event['data']['object'] ) ? (array) $event['data']['object'] : array();

	$order = dahu_deferred_sepa_resolve_webhook_order( $object );

	if ( ! $order ) {
		return new WP_REST_Response( 'no matching order', 200 );
	}

	/*
	 * Only this plugin's own charges, and only the payment this order is
	 * actually waiting on.
	 *
	 * The signature proves the event came from Stripe. It does not prove the
	 * event is about this order: `metadata` is set by whoever created the
	 * PaymentIntent, so a second integration, a staging site sharing the Stripe
	 * account, or anyone who can create an intent on it could send a 50-cent
	 * payment carrying `order_id` and `source` and have a real, correctly
	 * signed event mark that order collected.
	 *
	 * So the intent id has to match the one this plugin recorded when it sent
	 * the charge, and the site has to be this site.
	 */
	$ours = DAHU_DEFERRED_SEPA_GATEWAY_ID === $order->get_payment_method()
		|| 'dahu-deferred-sepa' === ( $object['metadata']['source'] ?? '' );

	if ( ! $ours ) {
		return new WP_REST_Response( 'not ours', 200 );
	}

	$site = isset( $object['metadata']['site_url'] ) ? (string) $object['metadata']['site_url'] : '';

	if ( '' !== $site && untrailingslashit( $site ) !== untrailingslashit( home_url() ) ) {
		dahu_deferred_sepa_log(
			sprintf( 'Webhook ignored: event for order %d carries another site (%s).', $order->get_id(), $site ),
			'warning'
		);

		return new WP_REST_Response( 'another site', 200 );
	}

	$recorded = (string) $order->get_meta( '_stripe_intent_id' );
	$event_on = dahu_deferred_sepa_event_intent( $object );

	if ( $recorded && $event_on && $recorded !== $event_on ) {
		dahu_deferred_sepa_log(
			sprintf(
				'Webhook ignored: event is about %s, order %d is waiting on %s.',
				$event_on,
				$order->get_id(),
				$recorded
			),
			'warning'
		);

		return new WP_REST_Response( 'not this payment', 200 );
	}

	dahu_deferred_sepa_apply_event( $order, $type, $object );

	return new WP_REST_Response( 'ok', 200 );
}

/**
 * What this plugin does about one verified Stripe event.
 *
 * Separated from `dahu_deferred_sepa_webhook_handler()` on purpose, and the
 * split is where it is for a reason: the handler owns everything that decides
 * whether an event may be BELIEVED — signature, ownership, which site, which
 * payment intent — and this function owns what the plugin then DOES about it.
 *
 * Two things follow. The handler can refuse an event without this function
 * ever being reached, which is what keeps those checks un-bypassable. And the
 * decisions below can be exercised on their own by a caller that already knows
 * the event is genuine — which is the only way the bench can measure them,
 * because the bench deliberately runs with NO webhook secret configured, so
 * every signature it could produce is refused long before the handler gets
 * this far.
 *
 * Takes no decision about authenticity. Call it only with an event the handler
 * has already vouched for.
 *
 * @param WC_Order $order  The order the event is about.
 * @param string   $type   Stripe event type.
 * @param array    $object The event's data object.
 * @return void
 */
function dahu_deferred_sepa_apply_event( WC_Order $order, $type, array $object ) {

	switch ( $type ) {
		case 'payment_intent.succeeded':
			// Not guarded by is_paid(): an order charged on the normal path is
			// already Completed, which is_paid() reports as paid, so the guard
			// would skip recording the paid date and the transaction id — the
			// very things this event is confirming. See
			// dahu_deferred_sepa_record_payment().
			dahu_deferred_sepa_record_payment( $order, isset( $object['id'] ) ? sanitize_text_field( (string) $object['id'] ) : '' );

			$order->add_order_note( __( 'Deferred SEPA: charge collected (confirmed by Stripe).', 'dahu-deferred-sepa' ) );
			dahu_deferred_sepa_set_result( $order, 'succeeded' );
			break;

		case 'payment_intent.payment_failed':
			$before = (string) $order->get_meta( '_dahu_deferred_sepa_result' );

			$reason = isset( $object['last_payment_error']['message'] )
				? (string) $object['last_payment_error']['message']
				: __( 'no reason given', 'dahu-deferred-sepa' );

			// Already settled, and now Stripe says this charge failed.
			//
			// Two different things look like this and the payload does not tell
			// them apart: a genuine post-settlement return, and a stale or
			// duplicate event for the intent that did settle. So the order is
			// left exactly as it is — undoing a settlement on an ambiguous
			// event is the one move here that could cost money — but it is
			// SAID, because of the two halves of that uncertainty, silence is
			// the expensive one. Before today this path logged a warning and
			// told nobody.
			if ( 'succeeded' === $before ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: the reason Stripe gave. */
						__( 'Deferred SEPA: Stripe reports a failure on this charge, which is recorded as collected. Nothing has been changed here — check the Stripe dashboard. Reason given: %s', 'dahu-deferred-sepa' ),
						$reason
					)
				);

				dahu_deferred_sepa_log(
					sprintf( 'Webhook: a failure arrived for order %d, which is recorded as collected. The order was left alone and the shop was told.', $order->get_id() ),
					'warning'
				);

				dahu_deferred_sepa_alert_reversal( $order, 'failed' );
				break;
			}

			// A debit that was submitted and is now refused BEFORE settlement.
			// `processing` means it was on its way; nothing left the customer's
			// account.
			//
			// This is where the plugin used to paint itself into a corner. The
			// charge path calls mark_done() as soon as Stripe accepts the
			// intent, and `_dahu_deferred_sepa_done` is what every guard reads
			// as "a debit is confirmed": it refuses a reschedule, and
			// mark_settled_offline() refuses the mark as well. So an order in
			// exactly this state — submitted, refused, unpaid, goods gone —
			// had no route left at all. Neither charge, nor reschedule, nor
			// "settled another way".
			//
			// Stripe has now said this intent is dead, and that is precisely
			// the fact that makes another attempt safe, so the flag comes off.
			// `_executed_ts` stays: an attempt WAS made, the history is built
			// on it, and erasing it would hide the attempt from the record.
			$submitted = 'processing' === $before || 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' );

			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: the reason Stripe gave. */
					__( 'Deferred SEPA: charge failed (reported by Stripe) — %s', 'dahu-deferred-sepa' ),
					$reason
				)
			);
			dahu_deferred_sepa_set_result( $order, 'failed' );

			if ( $submitted ) {
				$order->delete_meta_data( '_dahu_deferred_sepa_done' );
				$order->save();

				$order->add_order_note(
					__( 'Deferred SEPA: no money left the customer\'s account, so this charge can be sent again. Set a new date on this order once the cause is put right.', 'dahu-deferred-sepa' )
				);

				// The ordinary refusal alert, and not the reversal one: nothing
				// was taken, so "put it right and reschedule from the order" is
				// both true and now achievable.
				dahu_deferred_sepa_alert_failure(
					$order,
					'stripe refused the charge before settlement',
					__( 'A SEPA charge was submitted and Stripe refused it before settlement. No money left the customer\'s account, and this order has been reopened so the charge can be scheduled again.', 'dahu-deferred-sepa' )
				);
			} else {
				// Said in the log and not by email: the charge path owns this
				// one and has already written to the shop. Logged all the same,
				// because "no email was sent here" is the kind of decision that
				// looks like a bug when somebody comes to check why.
				dahu_deferred_sepa_log(
					sprintf( 'Webhook: order %d failed before anything was submitted. The charge path has already reported it.', $order->get_id() )
				);
			}
			break;

		case 'charge.dispute.created':
			$order->add_order_note(
				__( 'Deferred SEPA: the customer has disputed this charge (reported by Stripe). Check the Stripe dashboard.', 'dahu-deferred-sepa' )
			);
			dahu_deferred_sepa_set_result( $order, 'disputed' );

			// Always, with no condition: a dispute only exists for money that
			// was taken, and no other path in this plugin reports one.
			dahu_deferred_sepa_alert_reversal( $order, 'disputed' );
			break;
	}
}

/**
 * Finds the order a Stripe event is about.
 *
 * Two ways, in this order: the `order_id` this plugin put in the charge's
 * metadata, then the PaymentIntent id stored on the order.
 *
 * @param array $object The event's data object.
 * @return WC_Order|false
 */
function dahu_deferred_sepa_resolve_webhook_order( $object ) {
	$order_id = absint( $object['metadata']['order_id'] ?? 0 );

	if ( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			return $order;
		}
	}

	$intent = dahu_deferred_sepa_event_intent( $object );

	if ( ! $intent ) {
		return false;
	}

	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_key'   => '_stripe_intent_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- wc_get_orders' own argument.
			'meta_value' => $intent,             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
		)
	);

	return $orders ? $orders[0] : false;
}

/**
 * The PaymentIntent an event is about.
 *
 * On a PaymentIntent event the id is the object's own; on a dispute it is the
 * `payment_intent` field, which Stripe sends either as a string or, on some
 * payloads, as the EXPANDED object. Casting an array to a string there raises a
 * notice and loses the event, so both shapes are read.
 *
 * @param array $object The event's data object.
 * @return string Intent id, or '' when there is none.
 */
function dahu_deferred_sepa_event_intent( $object ) {
	$intent = isset( $object['payment_intent'] ) ? $object['payment_intent'] : ( $object['id'] ?? '' );

	if ( is_array( $intent ) ) {
		$intent = isset( $intent['id'] ) ? $intent['id'] : '';
	}

	$intent = is_scalar( $intent ) ? (string) $intent : '';

	return 0 === strpos( $intent, 'pi_' ) ? $intent : '';
}

/**
 * Verifies a Stripe webhook signature.
 *
 * Stripe's v1 scheme, implemented directly: the header carries a timestamp and
 * a signature, the signed payload is `timestamp.body`, and the comparison is
 * timing-safe. A replay older than the tolerance is refused.
 *
 * With no secret configured this returns false, so an unconfigured endpoint
 * accepts nothing rather than everything.
 *
 * @param string $payload    Raw request body.
 * @param string $sig_header The Stripe-Signature header.
 * @return bool
 */
function dahu_deferred_sepa_verify_webhook( $payload, $sig_header ) {
	$secret = DAHU_DEFERRED_SEPA_WEBHOOK_SECRET;

	if ( ! $secret || ! $sig_header || ! $payload ) {
		return false;
	}

	// The header can carry SEVERAL v1 signatures — Stripe sends one per active
	// signing secret while a secret is being rolled. Keeping only the last one,
	// as a single-assignment parse does, drops every event whenever the
	// configured secret is not the one Stripe happened to list last.
	$timestamp  = '';
	$signatures = array();

	foreach ( explode( ',', (string) $sig_header ) as $pair ) {
		$kv = explode( '=', $pair, 2 );

		if ( 2 !== count( $kv ) ) {
			continue;
		}

		$name  = trim( $kv[0] );
		$value = trim( $kv[1] );

		if ( 't' === $name ) {
			$timestamp = $value;
		} elseif ( 'v1' === $name ) {
			$signatures[] = $value;
		}
	}

	if ( '' === $timestamp || ! $signatures ) {
		return false;
	}

	/**
	 * Filters how far out of date a webhook may be, in seconds.
	 *
	 * @param int $tolerance Seconds.
	 */
	$tolerance = (int) apply_filters( 'dahu_deferred_sepa_webhook_tolerance', 5 * MINUTE_IN_SECONDS );

	if ( abs( time() - (int) $timestamp ) > $tolerance ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

	foreach ( $signatures as $signature ) {
		if ( hash_equals( $expected, $signature ) ) {
			return true;
		}
	}

	return false;
}
