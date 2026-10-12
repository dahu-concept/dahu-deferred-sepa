<?php
/**
 * Scheduling the charge, and performing it when the time comes.
 *
 * The whole plugin turns on one decision: an order marked "Completed" gets a
 * single scheduled action N days out, and that action is the only thing that
 * ever asks Stripe for money. Everything else — the metabox, the reports, the
 * webhook — reads or moves what this file wrote.
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * 1. Scheduling, on "Completed"
 * ------------------------------------------------------------------ */

add_action( 'woocommerce_order_status_completed', 'dahu_deferred_sepa_schedule_on_completed', 10, 2 );

/**
 * Schedules the charge when an order is completed.
 *
 * @param int           $order_id Order id.
 * @param WC_Order|null $order    Order, when WooCommerce passed one.
 * @return void
 */
function dahu_deferred_sepa_schedule_on_completed( $order_id, $order = null ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order ) {
		return;
	}

	// Only SEPA orders. Everything else is ignored without a word: this runs on
	// every completed order in the shop.
	if ( ! dahu_deferred_sepa_order_is_sepa( $order ) ) {
		return;
	}

	// Already charged, or already scheduled.
	if ( 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' ) ) {
		return;
	}

	if ( $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' ) ) {
		return;
	}

	// Already paid — a Stripe webhook may have settled it. The exception is this
	// plugin's own gateway: it leaves the order unpaid on purpose, so is_paid()
	// would be misleading there.
	if ( DAHU_DEFERRED_SEPA_GATEWAY_ID !== $order->get_payment_method() && $order->is_paid() ) {
		$order->add_order_note( __( 'Deferred SEPA: order is already paid, nothing scheduled.', 'dahu-deferred-sepa' ) );

		return;
	}

	$days      = dahu_deferred_sepa_get_delay_days( $order->get_user_id() );
	$timestamp = time() + ( $days * DAY_IN_SECONDS );

	dahu_deferred_sepa_schedule_at( $order, $timestamp );

	$order->add_order_note(
		sprintf(
			/* translators: 1: date and time of the charge, 2: delay in days. */
			__( 'Deferred SEPA: charge scheduled for %1$s (%2$d days from now).', 'dahu-deferred-sepa' ),
			dahu_deferred_sepa_format_date( $timestamp ),
			$days
		)
	);
}

/**
 * Schedules, or moves, the charge for an order.
 *
 * Action Scheduler when it is available — it is, WooCommerce ships it — and
 * WP-Cron otherwise, so a shop with Action Scheduler disabled still charges.
 *
 * Two of the meta written here deliberately outlive the charge, because the
 * history screen needs them after `_dahu_deferred_sepa_scheduled_ts` is gone:
 * the date first chosen, and how many times it moved.
 *
 * @param WC_Order $order     Order.
 * @param int      $timestamp When to charge.
 * @return void
 */
function dahu_deferred_sepa_schedule_at( WC_Order $order, $timestamp ) {
	$order_id  = $order->get_id();
	$timestamp = (int) $timestamp;

	dahu_deferred_sepa_unschedule( $order_id );

	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action(
			$timestamp,
			DAHU_DEFERRED_SEPA_HOOK,
			array( 'order_id' => $order_id ),
			DAHU_DEFERRED_SEPA_GROUP
		);
	} else {
		wp_schedule_single_event( $timestamp, DAHU_DEFERRED_SEPA_HOOK, array( $order_id ) );
	}

	$previous = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );

	if ( ! $order->get_meta( '_dahu_deferred_sepa_first_scheduled_ts' ) ) {
		$order->update_meta_data( '_dahu_deferred_sepa_first_scheduled_ts', $timestamp );
	} elseif ( $previous && $previous !== $timestamp ) {
		$order->update_meta_data(
			'_dahu_deferred_sepa_reschedule_count',
			1 + (int) $order->get_meta( '_dahu_deferred_sepa_reschedule_count' )
		);
	}

	$order->update_meta_data( '_dahu_deferred_sepa_scheduled_ts', $timestamp );
	$order->save();
}

/**
 * Records the outcome of a charge, for the history screen.
 *
 * @param WC_Order $order  Order.
 * @param string   $result One of processing, succeeded, failed, disputed.
 * @return void
 */
function dahu_deferred_sepa_set_result( WC_Order $order, $result ) {
	$order->update_meta_data( '_dahu_deferred_sepa_result', $result );
	$order->update_meta_data( '_dahu_deferred_sepa_result_ts', time() );
	$order->save();
}

/**
 * Removes any scheduled charge for an order, from both schedulers.
 *
 * Both are cleared whichever one scheduled it, so a shop that gains or loses
 * Action Scheduler between the scheduling and the cancelling does not keep a
 * charge nobody can see.
 *
 * @param int $order_id Order id.
 * @return void
 */
function dahu_deferred_sepa_unschedule( $order_id ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions(
			DAHU_DEFERRED_SEPA_HOOK,
			array( 'order_id' => (int) $order_id ),
			DAHU_DEFERRED_SEPA_GROUP
		);
	}

	wp_clear_scheduled_hook( DAHU_DEFERRED_SEPA_HOOK, array( (int) $order_id ) );
}

/* ------------------------------------------------------------------
 * 2. Running the charge
 * ------------------------------------------------------------------ */

/**
 * How long a charge may hold its claim before another attempt may take it.
 *
 * Longer than the Stripe request timeout, so a request still in flight keeps
 * its claim, and short enough that a worker killed mid-charge does not block
 * the order for the rest of the day.
 */
define( 'DAHU_DEFERRED_SEPA_CLAIM_TTL', 5 * MINUTE_IN_SECONDS );

/**
 * Takes an exclusive claim on charging an order, or fails.
 *
 * Why this exists: two charge attempts can overlap. Action Scheduler fires one
 * while a shop manager clicks "Charge now", or a worker is killed mid-request
 * and its action is re-claimed while the original HTTP call is still open. The
 * idempotency key makes a *replay* safe, but two attempts that both find no key
 * yet would mint two different keys and Stripe would charge twice.
 *
 * add_option() is the claim: it is a single INSERT that the database either
 * accepts or rejects, so exactly one caller can win it. A claim older than
 * DAHU_DEFERRED_SEPA_CLAIM_TTL is taken over, otherwise a crashed worker would
 * strand the order.
 *
 * @param int $order_id Order id.
 * @return bool Whether this caller may charge.
 */
function dahu_deferred_sepa_claim( $order_id ) {
	$key = 'dahu_deferred_sepa_claim_' . (int) $order_id;

	// autoload false: this is transient coordination, not configuration.
	if ( add_option( $key, time(), '', false ) ) {
		return true;
	}

	$held = (int) get_option( $key, 0 );

	if ( $held && ( time() - $held ) > DAHU_DEFERRED_SEPA_CLAIM_TTL ) {
		update_option( $key, time(), false );
		dahu_deferred_sepa_log(
			sprintf( 'Order %d: took over a charge claim left behind %d seconds ago.', $order_id, time() - $held ),
			'warning'
		);

		return true;
	}

	dahu_deferred_sepa_log( sprintf( 'Order %d: a charge is already under way, this attempt stands down.', $order_id ), 'info' );

	return false;
}

/**
 * Releases the claim.
 *
 * @param int $order_id Order id.
 * @return void
 */
function dahu_deferred_sepa_release( $order_id ) {
	delete_option( 'dahu_deferred_sepa_claim_' . (int) $order_id );
}

// Action Scheduler passes the values of its named argument array positionally,
// and WP-Cron passes the positional argument, so one parameter serves both.
add_action( DAHU_DEFERRED_SEPA_HOOK, 'dahu_deferred_sepa_run_scheduled', 10, 1 );

/**
 * Entry point of the scheduled action.
 *
 * @param int $order_id Order id.
 * @return void
 */
function dahu_deferred_sepa_run_scheduled( $order_id ) {
	$order_id = absint( $order_id );

	if ( ! $order_id ) {
		return;
	}

	dahu_deferred_sepa_charge( $order_id );
}

/**
 * Records that a charge was attempted, and why it went no further.
 *
 * Every refusal goes through here, so that three things always happen together:
 * the order says what happened, the log says it too, and the order stops being
 * counted as expected cash. Clearing the due date also makes the recovery work:
 * a shop manager who sets the order back to Processing and Completed again gets
 * a fresh schedule, which the "already scheduled" guard would otherwise refuse
 * in silence.
 *
 * @param WC_Order $order   Order.
 * @param string   $note    What to tell the shop, already translated.
 * @param string   $logged  What to tell the log, in English.
 * @param bool     $final   Whether to record the attempt as a failed charge.
 * @return void
 */
function dahu_deferred_sepa_refuse( WC_Order $order, $note, $logged, $final = true ) {
	$order->add_order_note( $note );

	if ( $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' ) ) {
		$order->delete_meta_data( '_dahu_deferred_sepa_scheduled_ts' );
		$order->save();
	}

	dahu_deferred_sepa_unschedule( $order->get_id() );

	if ( $final ) {
		// The history screen is bounded on there having been an attempt, so a
		// refused charge has to be stamped or it is invisible there.
		$order->update_meta_data( '_dahu_deferred_sepa_executed_ts', time() );
		$order->save();
		dahu_deferred_sepa_set_result( $order, 'failed' );
	}

	dahu_deferred_sepa_log( sprintf( 'Order %d: %s', $order->get_id(), $logged ), 'error' );
}

/**
 * The mandate to charge an order with, or a reason not to.
 *
 * The shop's approval and the customer's own default have to agree — that is
 * what includes/mandates.php enforces — and this re-checks it AT CHARGE TIME,
 * days after the order was placed. In between, the shop may have withdrawn its
 * approval, or the customer may have deleted the IBAN or made another one their
 * default.
 *
 * The mandate is taken whole, never half from the order and half from the
 * account: mixing an order-supplied payment method with an approval-derived
 * customer would charge an IBAN nobody approved.
 *
 * @param WC_Order $order Order.
 * @return array{customer:string,payment_method:string}|WP_Error
 */
function dahu_deferred_sepa_mandate_for_order( WC_Order $order ) {
	$user_id   = $order->get_user_id();
	$on_order  = (string) $order->get_meta( '_stripe_source_id' );
	$cus_order = (string) $order->get_meta( '_stripe_customer_id' );

	// A guest order cannot be re-checked against an account, so it is charged
	// only on what it already carries, and only if it carries both halves.
	if ( ! $user_id ) {
		if ( $on_order && $cus_order ) {
			return array(
				'customer'       => $cus_order,
				'payment_method' => $on_order,
			);
		}

		return new WP_Error(
			'dahu_deferred_sepa_guest_incomplete',
			__( 'Deferred SEPA: this order has no customer account and does not carry both a Stripe customer and a mandate, so nothing was charged.', 'dahu-deferred-sepa' ),
			array( 'log' => 'guest order missing customer or mandate' )
		);
	}

	if ( ! dahu_deferred_sepa_is_authorized( $user_id ) ) {
		return new WP_Error(
			'dahu_deferred_sepa_not_authorized',
			__( 'Deferred SEPA: this customer is no longer approved for deferred payment, so nothing was charged. Approve a mandate on their profile and mark the order Completed again, or take payment another way.', 'dahu-deferred-sepa' ),
			array( 'log' => 'approval withdrawn before the charge came due' )
		);
	}

	$mandate = dahu_deferred_sepa_find_saved_mandate( $user_id );

	if ( ! $mandate ) {
		return new WP_Error(
			'dahu_deferred_sepa_no_mandate',
			__( 'Deferred SEPA: no approved mandate could be found for this customer, so nothing was charged.', 'dahu-deferred-sepa' ),
			array( 'log' => 'no approved mandate at charge time' )
		);
	}

	// The order was taken against one IBAN. If the approved one is now a
	// different IBAN, that is not this order's mandate, and silently charging
	// the new one is not ours to decide.
	if ( $on_order && $on_order !== $mandate['payment_method'] ) {
		return new WP_Error(
			'dahu_deferred_sepa_mandate_changed',
			__( 'Deferred SEPA: the mandate approved for this customer is no longer the one this order was placed with, so nothing was charged. Check which IBAN should be used and charge it by hand.', 'dahu-deferred-sepa' ),
			array( 'log' => 'approved mandate differs from the one on the order' )
		);
	}

	return $mandate;
}

/**
 * Charges the order at Stripe.
 *
 * Two routes, in this order:
 *
 * 1. An existing PaymentIntent left at `requires_confirmation` is confirmed —
 *    but only if its amount and currency still match the order.
 * 2. Otherwise a new off-session PaymentIntent is created and confirmed against
 *    the approved mandate.
 *
 * Every refusal leaves an order note saying what a human has to do, because
 * this runs with nobody watching and a silent failure here is an unpaid order
 * that looks settled.
 *
 * @param int $order_id Order id.
 * @return void
 */
function dahu_deferred_sepa_charge( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	if ( 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' ) ) {
		return;
	}

	if ( ! dahu_deferred_sepa_order_is_sepa( $order ) ) {
		// The payment method was changed after the charge was scheduled. Say so
		// rather than returning in silence, and stop counting it as due.
		dahu_deferred_sepa_refuse(
			$order,
			__( 'Deferred SEPA: the charge came due, but this order is no longer on a SEPA payment method, so nothing was charged.', 'dahu-deferred-sepa' ),
			'payment method changed after scheduling',
			false
		);

		return;
	}

	// Same reasoning as when scheduling: is_paid() is not the truth for this
	// plugin's own gateway.
	if ( DAHU_DEFERRED_SEPA_GATEWAY_ID !== $order->get_payment_method() && $order->is_paid() ) {
		$order->add_order_note( __( 'Deferred SEPA: order was already paid when the charge came due. Nothing done.', 'dahu-deferred-sepa' ) );
		dahu_deferred_sepa_mark_done( $order );

		// Recorded, not left blank: the charge ended in a known state, and a
		// history row with no outcome tells the reader nothing about why.
		dahu_deferred_sepa_set_result( $order, 'succeeded' );

		return;
	}

	$currency_error = dahu_deferred_sepa_currency_error( $order );

	if ( $currency_error ) {
		dahu_deferred_sepa_refuse( $order, $currency_error, 'currency not supported by SEPA' );

		return;
	}

	$secret_key = dahu_deferred_sepa_get_secret_key();

	if ( ! $secret_key ) {
		dahu_deferred_sepa_refuse(
			$order,
			__( 'Deferred SEPA: no Stripe secret key is configured. No charge was attempted — this needs doing by hand.', 'dahu-deferred-sepa' ),
			'no secret key configured',
			false
		);

		return;
	}

	$mandate = dahu_deferred_sepa_mandate_for_order( $order );

	if ( is_wp_error( $mandate ) ) {
		$data = $mandate->get_error_data();

		dahu_deferred_sepa_refuse(
			$order,
			$mandate->get_error_message(),
			isset( $data['log'] ) ? $data['log'] : $mandate->get_error_code()
		);

		return;
	}

	// Exactly one attempt at a time. Without this, two attempts that both find
	// no idempotency key yet would mint two keys and Stripe would charge twice.
	if ( ! dahu_deferred_sepa_claim( $order_id ) ) {
		return;
	}

	try {
		// One stable key per order, minted at checkout and reused here, so a
		// replayed action — a network timeout, an Action Scheduler retry — can
		// never produce a second charge.
		$idempotency = dahu_deferred_sepa_get_idempotency_key( $order );

		/* ---- Route 1: an existing PaymentIntent waiting for confirmation ---- */

		$intent_id = $order->get_meta( '_stripe_intent_id' );

		if ( $intent_id ) {
			$intent = dahu_deferred_sepa_stripe_request( 'GET', 'payment_intents/' . $intent_id, array(), $secret_key );

			if ( ! is_wp_error( $intent ) && isset( $intent['status'] ) ) {
				if ( in_array( $intent['status'], array( 'processing', 'succeeded' ), true ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: Stripe PaymentIntent status. */
							__( 'Deferred SEPA: the existing payment is already under way at Stripe (%s). Nothing done.', 'dahu-deferred-sepa' ),
							$intent['status']
						)
					);
					dahu_deferred_sepa_mark_done( $order );
					dahu_deferred_sepa_set_result(
						$order,
						'succeeded' === $intent['status'] ? 'succeeded' : 'processing'
					);

					return;
				}

				if ( 'requires_confirmation' === $intent['status'] ) {
					// Confirming sends whatever the intent already holds, so the
					// amount and the currency have to still match the order. An
					// order edited after the intent was created would otherwise
					// be charged the old total and marked collected.
					$wanted_amount   = dahu_deferred_sepa_amount_in_cents( $order );
					$wanted_currency = strtolower( (string) $order->get_currency() );
					$has_amount      = isset( $intent['amount'] ) ? (int) $intent['amount'] : -1;
					$has_currency    = isset( $intent['currency'] ) ? strtolower( (string) $intent['currency'] ) : '';

					if ( $has_amount === $wanted_amount && $has_currency === $wanted_currency ) {
						$confirmed = dahu_deferred_sepa_stripe_request(
							'POST',
							'payment_intents/' . $intent_id . '/confirm',
							array(),
							$secret_key,
							$idempotency . '_confirm'
						);

						dahu_deferred_sepa_handle_result( $order, $confirmed, 'confirm' );

						return;
					}

					$order->add_order_note(
						__( 'Deferred SEPA: the payment already prepared at Stripe is for a different amount than this order, so a new one was created for the current total.', 'dahu-deferred-sepa' )
					);
					dahu_deferred_sepa_log(
						sprintf(
							'Order %d: existing intent %s is %d %s, order is %d %s — creating a new intent.',
							$order_id,
							$intent_id,
							$has_amount,
							$has_currency,
							$wanted_amount,
							$wanted_currency
						),
						'warning'
					);
				}

				// Anything else — canceled, requires_payment_method — falls
				// through to a fresh intent below.
			}
		}

		/* ---- Route 2: a new off-session PaymentIntent ---- */

		// Both halves come from the same approved mandate.
		$order->update_meta_data( '_stripe_customer_id', $mandate['customer'] );
		$order->update_meta_data( '_stripe_source_id', $mandate['payment_method'] );
		$order->save();

		$body = array(
			'amount'               => dahu_deferred_sepa_amount_in_cents( $order ),
			'currency'             => strtolower( $order->get_currency() ),
			'customer'             => $mandate['customer'],
			'payment_method'       => $mandate['payment_method'],
			'payment_method_types' => array( 'sepa_debit' ),
			'off_session'          => 'true',
			'confirm'              => 'true',
			'description'          => sprintf(
				/* translators: 1: order number, 2: site name. */
				__( 'Order #%1$s — %2$s', 'dahu-deferred-sepa' ),
				$order->get_order_number(),
				get_bloginfo( 'name' )
			),
			'metadata'             => array(
				'order_id'     => (string) $order->get_id(),
				'order_number' => (string) $order->get_order_number(),
				'site_url'     => home_url(),
				'source'       => 'dahu-deferred-sepa',
			),
		);

		/**
		 * Filters the PaymentIntent body before it is sent.
		 *
		 * @param array    $body  Request body.
		 * @param WC_Order $order Order being charged.
		 */
		$body = (array) apply_filters( 'dahu_deferred_sepa_payment_intent_body', $body, $order );

		$result = dahu_deferred_sepa_stripe_request( 'POST', 'payment_intents', $body, $secret_key, $idempotency );

		dahu_deferred_sepa_handle_result( $order, $result, 'create' );
	} finally {
		dahu_deferred_sepa_release( $order_id );
	}
}

/**
 * Records that an order has been paid.
 *
 * WC_Order::payment_complete() only acts when the order is on-hold, pending,
 * failed or cancelled — `completed` is NOT among them
 * (OrderStatus::PAYMENT_COMPLETE_STATUSES). A deferred charge runs precisely
 * BECAUSE the order was marked Completed, so on the normal path
 * payment_complete() does nothing at all, and the PaymentIntent id never
 * reaches `_transaction_id`: the order shows no payment reference where every
 * other gateway puts one.
 *
 * The paid date is a different matter — WooCommerce sets it itself on the move
 * to Completed, so it is already there, and it is the date the order was
 * completed rather than the date the money moved. It is left alone here rather
 * than overwritten: it is not this plugin's to rewrite, and a shop reading it
 * as "when we got paid" is closer to right with WooCommerce's value than with
 * one taken days later.
 *
 * So the transaction id is written here whatever the status, and
 * payment_complete() is still called afterwards for the hooks the rest of the
 * ecosystem listens to.
 *
 * @param WC_Order $order  Order.
 * @param string   $intent Stripe PaymentIntent id.
 * @return void
 */
function dahu_deferred_sepa_record_payment( WC_Order $order, $intent ) {
	if ( $intent ) {
		$order->set_transaction_id( $intent );
	}

	if ( ! $order->get_date_paid( 'edit' ) ) {
		$order->set_date_paid( time() );
	}

	$order->save();

	// Fires woocommerce_payment_complete and friends when the status allows it;
	// a no-op otherwise, which is why the two lines above exist.
	$order->payment_complete( $intent );
}

/**
 * Turns Stripe's answer into an order outcome. Shared by both routes.
 *
 * @param WC_Order       $order  Order.
 * @param array|WP_Error $result Stripe response.
 * @param string         $route  'create' or 'confirm', for the order note.
 * @return void
 */
function dahu_deferred_sepa_handle_result( WC_Order $order, $result, $route ) {
	$label = 'confirm' === $route
		? __( 'confirming the existing payment', 'dahu-deferred-sepa' )
		: __( 'creating the payment', 'dahu-deferred-sepa' );

	if ( is_wp_error( $result ) ) {
		// Deliberately NOT recorded as a failed charge and NOT unscheduled: the
		// request may well have reached Stripe and the answer been lost on the
		// way back. The stored idempotency key is what makes another attempt
		// safe, and Stripe honours it for about 24 hours — so the note says to
		// check the dashboard rather than inviting a blind retry days later.
		$order->add_order_note(
			sprintf(
				/* translators: 1: what the plugin was doing, 2: error message. */
				__( 'Deferred SEPA could not reach Stripe while %1$s — %2$s. The charge may or may not have been taken: check the Stripe dashboard for this order before charging it again.', 'dahu-deferred-sepa' ),
				$label,
				$result->get_error_message()
			)
		);
		dahu_deferred_sepa_log(
			sprintf( 'Order %d: network failure while %s — %s', $order->get_id(), $route, $result->get_error_message() ),
			'error'
		);

		return;
	}

	if ( isset( $result['error'] ) ) {
		// Off-session charges have a quirk: Stripe can answer with an "error"
		// while the PaymentIntent attached to it is in fact under way. Only
		// treat it as a failure when that intent is not collecting.
		$attached = isset( $result['error']['payment_intent'] ) ? $result['error']['payment_intent'] : null;

		if ( is_array( $attached ) && in_array( $attached['status'] ?? '', array( 'processing', 'succeeded' ), true ) ) {
			$result = $attached;
		} else {
			$message = isset( $result['error']['message'] )
				? $result['error']['message']
				: __( 'no reason given', 'dahu-deferred-sepa' );

			dahu_deferred_sepa_refuse(
				$order,
				sprintf(
					/* translators: 1: what the plugin was doing, 2: the message Stripe returned. */
					__( 'Deferred SEPA failed while %1$s: Stripe refused it — %2$s', 'dahu-deferred-sepa' ),
					$label,
					$message
				),
				sprintf( 'Stripe refused the charge while %s', $route )
			);

			return;
		}
	}

	$status = isset( $result['status'] ) ? (string) $result['status'] : '';
	$intent = isset( $result['id'] ) ? sanitize_text_field( (string) $result['id'] ) : '';

	if ( in_array( $status, array( 'processing', 'succeeded' ), true ) ) {
		// Keep the intent id so this plugin's webhook can find the order again,
		// and so the webhook can check that an event is about THIS payment.
		if ( $intent ) {
			$order->update_meta_data( '_stripe_intent_id', $intent );
		}

		dahu_deferred_sepa_mark_done( $order );
		dahu_deferred_sepa_set_result( $order, 'succeeded' === $status ? 'succeeded' : 'processing' );

		$order->add_order_note(
			'succeeded' === $status
				? __( 'Deferred SEPA: charge collected.', 'dahu-deferred-sepa' )
				: __( 'Deferred SEPA: charge sent to Stripe and under way. SEPA settles in a few working days.', 'dahu-deferred-sepa' )
		);

		if ( 'succeeded' === $status ) {
			dahu_deferred_sepa_record_payment( $order, $intent );
		}

		return;
	}

	// An unexpected status. The note says what happened; only the identifier
	// and the status go to the log. The full response must not be logged: a
	// created PaymentIntent carries a `client_secret`, which is a bearer
	// credential for confirming or cancelling that payment, and the log file is
	// readable in the admin and present in every backup.
	dahu_deferred_sepa_refuse(
		$order,
		sprintf(
			/* translators: 1: what the plugin was doing, 2: the status Stripe returned. */
			__( 'Deferred SEPA: unexpected status while %1$s — "%2$s". Check the Stripe dashboard for this order.', 'dahu-deferred-sepa' ),
			$label,
			$status
		),
		sprintf( 'unexpected status "%s" on intent %s while %s', $status, $intent ? $intent : '(none)', $route )
	);
}

/**
 * Closes an order's deferred charge: it will not be attempted again.
 *
 * The last scheduled date is frozen into its own meta before the live one is
 * deleted, so the history screen can still say when the charge had been due.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function dahu_deferred_sepa_mark_done( WC_Order $order ) {
	$scheduled = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );

	$order->update_meta_data( '_dahu_deferred_sepa_done', 'yes' );
	$order->update_meta_data( '_dahu_deferred_sepa_final_scheduled_ts', $scheduled ? $scheduled : time() );
	$order->update_meta_data( '_dahu_deferred_sepa_executed_ts', time() );
	$order->delete_meta_data( '_dahu_deferred_sepa_scheduled_ts' );
	$order->save();

	dahu_deferred_sepa_unschedule( $order->get_id() );
}

/* ------------------------------------------------------------------
 * 3. Cancelling
 * ------------------------------------------------------------------ */

add_action( 'woocommerce_order_status_cancelled', 'dahu_deferred_sepa_cancel_schedule' );
add_action( 'woocommerce_order_status_refunded', 'dahu_deferred_sepa_cancel_schedule' );

/**
 * Drops a scheduled charge when the order is cancelled or refunded first.
 *
 * @param int $order_id Order id.
 * @return void
 */
function dahu_deferred_sepa_cancel_schedule( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || ! $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' ) ) {
		return;
	}

	dahu_deferred_sepa_unschedule( $order_id );

	$order->delete_meta_data( '_dahu_deferred_sepa_scheduled_ts' );
	$order->save();
	$order->add_order_note( __( 'Deferred SEPA: scheduled charge cancelled, because the order was cancelled or refunded.', 'dahu-deferred-sepa' ) );
}
