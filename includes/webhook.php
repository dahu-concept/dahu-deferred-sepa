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
 * Endpoint: /wp-json/ubac-sepa/v1/webhook
 *
 * @package UbacSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'ubac-sepa/v1',
			'/webhook',
			array(
				'methods'  => 'POST',
				'callback' => 'ubac_sepa_webhook_handler',
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
function ubac_sepa_webhook_handler( WP_REST_Request $request ) {
	$payload = $request->get_body();

	if ( ! ubac_sepa_verify_webhook( $payload, $request->get_header( 'stripe-signature' ) ) ) {
		ubac_sepa_log( 'Webhook rejected: signature did not verify.', 'warning' );

		return new WP_REST_Response( 'invalid signature', 400 );
	}

	$event = json_decode( $payload, true );

	if ( ! is_array( $event ) ) {
		return new WP_REST_Response( 'bad payload', 400 );
	}

	$type   = isset( $event['type'] ) ? (string) $event['type'] : '';
	$object = isset( $event['data']['object'] ) ? (array) $event['data']['object'] : array();

	$order = ubac_sepa_resolve_webhook_order( $object );

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
	$ours = UBAC_SEPA_GATEWAY_ID === $order->get_payment_method()
		|| 'ubac-sepa' === ( $object['metadata']['source'] ?? '' );

	if ( ! $ours ) {
		return new WP_REST_Response( 'not ours', 200 );
	}

	$site = isset( $object['metadata']['site_url'] ) ? (string) $object['metadata']['site_url'] : '';

	if ( '' !== $site && untrailingslashit( $site ) !== untrailingslashit( home_url() ) ) {
		ubac_sepa_log(
			sprintf( 'Webhook ignored: event for order %d carries another site (%s).', $order->get_id(), $site ),
			'warning'
		);

		return new WP_REST_Response( 'another site', 200 );
	}

	$recorded = (string) $order->get_meta( '_stripe_intent_id' );
	$event_on = ubac_sepa_event_intent( $object );

	/*
	 * NO RECORDED PAYMENT MEANS NO CONFIRMATION, AND THE SHOP IS TOLD.
	 *
	 * The binding below used to apply only when BOTH ids were present, which
	 * meant it applied exactly when it was least needed. Measured with validly
	 * signed events built from a bench secret:
	 *
	 *  - an order this plugin had never charged, paid by another gateway, with
	 *    no recorded intent: metadata naming `source` and `order_id` was enough
	 *    to set the paid date, write the event's own id as the transaction
	 *    reference, mark the charge collected and add the note saying so. The
	 *    order then showed as collected on the charges screen.
	 *  - a `payment_failed` built the same way took a completed order back to
	 *    failed, with the failure reason printed from text the event's author
	 *    chose.
	 *  - an order genuinely charged, whose intent WAS recorded, could still be
	 *    confirmed by an event whose object id did not start with `pi_`:
	 *    `ubac_sepa_event_intent()` returns '' there, so `$event_on`
	 *    was empty and the comparison was skipped. The reference written on the
	 *    order was then the event's id, not the one this plugin had recorded.
	 *
	 * All of that needs the ability to create an intent on the Stripe account,
	 * which is the threat named above -- a second integration, a staging site
	 * sharing the account. The signature cannot tell them apart, so the
	 * recorded intent has to.
	 *
	 * A confirmation therefore requires a recorded intent AND a recognisable
	 * one in the event, and both must match. The honest cost is that a charge
	 * whose Stripe answer was lost in transit has no recorded intent -- the one
	 * case where a confirming event is most wanted. So the refusal is not
	 * silent: it is written on the order, which is what makes that case
	 * recoverable by hand instead of merely dropped.
	 */
	if ( '' === $recorded || '' === $event_on ) {
		/*
		 * SPOKEN ONCE, AND ONLY ABOUT WHAT WE WOULD HAVE ACTED ON.
		 *
		 * Written on every delivery, this note was a weapon rather than a
		 * signal: a thousand refused deliveries wrote a thousand notes on any
		 * order an event's metadata cared to name, and the cost to whoever
		 * sends them is nothing, because it is Stripe that signs. Scoping it to
		 * the three types `apply_event()` acts on, and to one note per order
		 * and per type, keeps the signal the refusal was added for and takes
		 * the volume away.
		 */
		if ( in_array( $type, ubac_sepa_acted_types(), true ) ) {
			ubac_sepa_say_once(
				$order,
				'unbound-' . $type,
				sprintf(
					/* translators: %s: the event type Stripe sent. */
					__( 'Deferred SEPA: a Stripe event (%s) arrived for this order, but no charge of this plugin is recorded against it, so nothing was changed. Check the Stripe dashboard before treating this order as collected.', 'dahuconcept-deferred-sepa' ),
					$type
				)
			);
		}

		ubac_sepa_log(
			sprintf(
				'Webhook ignored: event %s for order %d has no payment to confirm (recorded "%s", event "%s").',
				$type,
				$order->get_id(),
				$recorded,
				$event_on
			),
			'warning'
		);

		return new WP_REST_Response( 'no recorded payment', 200 );
	}

	if ( $recorded !== $event_on ) {
		ubac_sepa_log(
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

	/*
	 * ONE EVENT IS APPLIED ONCE, HOWEVER OFTEN STRIPE SENDS IT.
	 *
	 * Stripe redelivers. Nothing here used to remember an event, and the only
	 * protection was that what gets applied happens to be idempotent -- which
	 * covers the amounts and not the telling. Measured with five deliveries of
	 * one valid event: the status, the paid date and the transaction reference
	 * stayed put, but the order collected FIVE identical notes, and a replayed
	 * `payment_failed` on a settled order sent FIVE reversal alerts by mail for
	 * one incident. The signature's five-minute window bounds how old a
	 * signature may be, not how many times the same body arrives.
	 *
	 * The seen list lives on the order, so it is scoped to the thing the event
	 * is about and disappears with it. It keeps the last few ids rather than
	 * growing for ever: Stripe's retries are close together, so a short memory
	 * is enough, and an unbounded one would be a meta that only ever grows.
	 */
	$event_id = isset( $event['id'] ) ? (string) $event['id'] : '';
	$remember = '' !== $event_id && in_array( $type, ubac_sepa_acted_types(), true );

	if ( $remember ) {
		$seen = $order->get_meta( '_ubac_sepa_events' );
		$seen = is_array( $seen ) ? array_map( 'strval', $seen ) : array();

		if ( in_array( $event_id, $seen, true ) ) {
			ubac_sepa_log(
				sprintf( 'Webhook: event %s was already applied to order %d; nothing done.', $event_id, $order->get_id() ),
				'info'
			);

			return new WP_REST_Response( 'already applied', 200 );
		}
	}

	ubac_sepa_apply_event( $order, $type, $object );

	/*
	 * REMEMBERED AFTER, NOT BEFORE.
	 *
	 * Written before the event was applied, this memory turned an interruption
	 * that used to heal itself into a permanent loss: Stripe's next delivery
	 * found the id already listed and answered "already applied" without ever
	 * applying it. Measured on a charge of 1 840 EUR interrupted mid-apply, the
	 * payment was never reconciled on the order and no retry could fix it; on a
	 * 430 EUR charge refused before settlement, the order kept the flag that
	 * refuses both a reschedule and a manual settlement, with no alert to
	 * anyone -- the exact dead end this plugin exists to avoid.
	 *
	 * So the id is written only once the work is done. If the apply itself
	 * breaks half way, Stripe retries and the second pass redoes it, which can
	 * repeat a note or an alert -- the noisy failure rather than the silent
	 * one, and the same behaviour this plugin had before any of this memory
	 * existed.
	 *
	 * Only the acted-on types are remembered: ignored ones used to fill the
	 * list and evict a real confirmation, which then applied a second time.
	 */
	if ( $remember ) {
		$seen = $order->get_meta( '_ubac_sepa_events' );
		$seen = is_array( $seen ) ? array_map( 'strval', $seen ) : array();
		$seen[] = $event_id;

		/**
		 * Filters how many event ids an order remembers.
		 *
		 * @param int $keep Number of ids.
		 */
		$keep = (int) apply_filters( 'ubac_sepa_events_remembered', 20 );
		$keep = $keep > 0 ? $keep : 1;

		$order->update_meta_data( '_ubac_sepa_events', array_slice( $seen, -$keep ) );
		$order->save();
	}

	return new WP_REST_Response( 'ok', 200 );
}

/**
 * What this plugin does about one verified Stripe event.
 *
 * Separated from `ubac_sepa_webhook_handler()` on purpose, and the
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
function ubac_sepa_apply_event( WC_Order $order, $type, array $object ) {

	switch ( $type ) {
		case 'payment_intent.succeeded':
			// Not guarded by is_paid(): an order charged on the normal path is
			// already Completed, which is_paid() reports as paid, so the guard
			// would skip recording the paid date and the transaction id — the
			// very things this event is confirming. See
			// ubac_sepa_record_payment().
			/*
			 * ONE READING OF THE ID, USED EVERYWHERE.
			 *
			 * This line cast `$object['id']` straight to a string while the
			 * binding above read the same field through
			 * ubac_sepa_event_intent(), which unwraps the expanded
			 * object form Stripe sends when a payment intent is expanded. The
			 * two readings disagreed: an event whose id arrived expanded passed
			 * the binding and then wrote the literal string "Array" as the
			 * order's payment reference, with a PHP warning to go with it.
			 * Measured on a bench event carrying `id: { id: pi_… }`.
			 *
			 * The extracted id is what the binding compared, so it is what gets
			 * recorded. A field read twice in two ways is a field that will
			 * eventually be read wrongly once.
			 */
			ubac_sepa_record_payment( $order, sanitize_text_field( ubac_sepa_event_intent( $object ) ) );

			$order->add_order_note( __( 'Deferred SEPA: charge collected (confirmed by Stripe).', 'dahuconcept-deferred-sepa' ) );
			ubac_sepa_set_result( $order, 'succeeded' );
			break;

		case 'payment_intent.payment_failed':
			$before = (string) $order->get_meta( '_ubac_sepa_result' );

			$reason = isset( $object['last_payment_error']['message'] )
				? (string) $object['last_payment_error']['message']
				: __( 'no reason given', 'dahuconcept-deferred-sepa' );

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
						__( 'Deferred SEPA: Stripe reports a failure on this charge, which is recorded as collected. Nothing has been changed here — check the Stripe dashboard. Reason given: %s', 'dahuconcept-deferred-sepa' ),
						$reason
					)
				);

				ubac_sepa_log(
					sprintf( 'Webhook: a failure arrived for order %d, which is recorded as collected. The order was left alone and the shop was told.', $order->get_id() ),
					'warning'
				);

				ubac_sepa_alert_reversal( $order, 'failed' );
				break;
			}

			// A debit that was submitted and is now refused BEFORE settlement.
			// `processing` means it was on its way; nothing left the customer's
			// account.
			//
			// This is where the plugin used to paint itself into a corner. The
			// charge path calls mark_done() as soon as Stripe accepts the
			// intent, and `_ubac_sepa_done` is what every guard reads
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
			$submitted = 'processing' === $before || 'yes' === $order->get_meta( '_ubac_sepa_done' );

			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: the reason Stripe gave. */
					__( 'Deferred SEPA: charge failed (reported by Stripe) — %s', 'dahuconcept-deferred-sepa' ),
					$reason
				)
			);
			ubac_sepa_set_result( $order, 'failed' );

			if ( $submitted ) {
				$order->delete_meta_data( '_ubac_sepa_done' );
				$order->save();

				$order->add_order_note(
					__( 'Deferred SEPA: no money left the customer\'s account, so this charge can be sent again. Set a new date on this order once the cause is put right.', 'dahuconcept-deferred-sepa' )
				);

				// The ordinary refusal alert, and not the reversal one: nothing
				// was taken, so "put it right and reschedule from the order" is
				// both true and now achievable.
				ubac_sepa_alert_failure(
					$order,
					'stripe refused the charge before settlement',
					__( 'A SEPA charge was submitted and Stripe refused it before settlement. No money left the customer\'s account, and this order has been reopened so the charge can be scheduled again.', 'dahuconcept-deferred-sepa' )
				);
			} else {
				// Said in the log and not by email: the charge path owns this
				// one and has already written to the shop. Logged all the same,
				// because "no email was sent here" is the kind of decision that
				// looks like a bug when somebody comes to check why.
				ubac_sepa_log(
					sprintf( 'Webhook: order %d failed before anything was submitted. The charge path has already reported it.', $order->get_id() )
				);
			}
			break;

		case 'charge.dispute.created':
			$order->add_order_note(
				__( 'Deferred SEPA: the customer has disputed this charge (reported by Stripe). Check the Stripe dashboard.', 'dahuconcept-deferred-sepa' )
			);
			ubac_sepa_set_result( $order, 'disputed' );

			// Always, with no condition: a dispute only exists for money that
			// was taken, and no other path in this plugin reports one.
			ubac_sepa_alert_reversal( $order, 'disputed' );
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
function ubac_sepa_resolve_webhook_order( $object ) {
	$order_id = absint( $object['metadata']['order_id'] ?? 0 );

	if ( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			return $order;
		}
	}

	$intent = ubac_sepa_event_intent( $object );

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
function ubac_sepa_event_intent( $object ) {
	$intent = isset( $object['payment_intent'] ) ? $object['payment_intent'] : ( $object['id'] ?? '' );

	if ( is_array( $intent ) ) {
		$intent = isset( $intent['id'] ) ? $intent['id'] : '';
	}

	$intent = is_scalar( $intent ) ? (string) $intent : '';

	return 0 === strpos( $intent, 'pi_' ) ? $intent : '';
}

/**
 * The event types this plugin acts on.
 *
 * Stripe sends far more than these -- `payment_intent.created`,
 * `payment_intent.processing`, `charge.updated`, `invoice.paid` and a dozen
 * more arrive for a single charge. `apply_event()` has a case for three of
 * them and ignores the rest, so anything the plugin does per event -- speaking
 * about a refusal, remembering an id -- is scoped to these three. Measured
 * before that scoping: five ignored types produced five refusal notes, and
 * twenty ignored types were enough to push a real confirmation out of the
 * remembered list and let it apply twice.
 *
 * @return string[]
 */
function ubac_sepa_acted_types() {
	return array(
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'charge.dispute.created',
	);
}

/**
 * Writes an order note once per order and per subject.
 *
 * A refusal is repeatable by nature: nothing is written, so the next delivery
 * refuses again. Measured before this existed: a thousand refused deliveries
 * wrote a thousand identical notes on a single order, and the one legitimate
 * refusal a shop needs to find -- a charge whose Stripe answer was lost -- was
 * buried under them. A message that repeats is a message nobody reads, which
 * defeats the whole point of refusing out loud.
 *
 * @param WC_Order $order   The order to annotate.
 * @param string   $subject What this message is about, unique per order.
 * @param string   $note    The message, already translated.
 * @return void
 */
function ubac_sepa_say_once( WC_Order $order, $subject, $note ) {

	$said = $order->get_meta( '_ubac_sepa_said' );
	$said = is_array( $said ) ? array_map( 'strval', $said ) : array();

	if ( in_array( (string) $subject, $said, true ) ) {
		return;
	}

	$said[] = (string) $subject;
	$order->update_meta_data( '_ubac_sepa_said', array_values( array_unique( $said ) ) );
	$order->add_order_note( $note );
	$order->save();
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
function ubac_sepa_verify_webhook( $payload, $sig_header ) {
	$secret = UBAC_SEPA_WEBHOOK_SECRET;

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
	$tolerance = (int) apply_filters( 'ubac_sepa_webhook_tolerance', 5 * MINUTE_IN_SECONDS );

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
