<?php
/**
 * An order settled some other way, said so and taken out of the queue.
 *
 * @package DahuDeferredSepa
 */

defined( 'ABSPATH' ) || exit;

/**
 * Why this exists, and why it is not a checkbox.
 *
 * A deferred charge can be settled outside this plugin, and often is: the
 * customer rings up and pays by card, a cheque arrives, a transfer lands, or
 * somebody takes the payment in Stripe's own dashboard. The order is paid,
 * and this plugin still has a scheduled charge pointing at it.
 *
 * Before this, there was no clean way out. The shop could delete order meta
 * by hand, or leave the charge scheduled and hope it failed — and since the
 * daily sweep was added, *hoping it failed* means being emailed about that
 * order every day. An alert with no way to resolve it is an alert a shop
 * learns to delete, which costs more than having no alert at all.
 *
 * So: a reason, a comment, a confirmation, and a trace.
 *
 * ## It takes a reason and not just a tick
 *
 * Because the question "why was this not charged by direct debit?" is asked
 * months later, by somebody reconciling a bank statement, and "somebody
 * ticked a box in October" is not an answer. The reason is one of four, the
 * comment is required for "another way", and both go on the order note where
 * an accountant will find them.
 *
 * ## It is reversible, until it is not
 *
 * A mark put on the wrong order has to come off, so it does — while nothing
 * has been charged at Stripe. Once a debit is confirmed, the mark cannot be
 * lifted, because the order was then charged twice and the second fact is
 * the one that matters: the money moved.
 *
 * ## What it stops, precisely
 *
 * Everything that could still take money: no scheduling when the order is
 * completed again, no rescheduling, no "charge now", and
 * `dahu_deferred_sepa_charge()` refuses even if a stale scheduled action
 * fires. Four doors, and a charge path that checks the mark itself rather
 * than trusting the three screens in front of it.
 */

/**
 * The reasons a shop may give, and how each one reads.
 *
 * Four, not a free-text box: a list can be filtered, counted and exported,
 * and the one case a list cannot express has "another way" plus a comment
 * that is then required.
 *
 * @return array<string,string>
 */
function dahu_deferred_sepa_offline_reasons() {

	return array(
		'manual_card' => __( 'Taken by card or in Stripe by hand', 'dahu-deferred-sepa' ),
		'cheque'      => __( 'Cheque', 'dahu-deferred-sepa' ),
		'transfer'    => __( 'Bank transfer', 'dahu-deferred-sepa' ),
		'other'       => __( 'Another way (say which)', 'dahu-deferred-sepa' ),
	);
}

/**
 * Is this order marked as settled outside this plugin?
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function dahu_deferred_sepa_is_settled_offline( WC_Order $order ) {

	$record = $order->get_meta( '_dahu_deferred_sepa_offline' );

	return is_array( $record ) && ! empty( $record['reason'] );
}

/**
 * What was recorded, or an empty array.
 *
 * @param WC_Order $order Order.
 * @return array
 */
function dahu_deferred_sepa_offline_record( WC_Order $order ) {

	$record = $order->get_meta( '_dahu_deferred_sepa_offline' );

	return is_array( $record ) ? $record : array();
}

/**
 * Has Stripe confirmed a debit on this order?
 *
 * The question that decides whether the mark can be lifted. Read from the
 * recorded result and from the Stripe identifiers the gateway writes, and
 * never from `is_paid()`: this plugin's own gateway leaves an order unpaid
 * on purpose, and an order marked Completed by hand would otherwise read as
 * charged.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function dahu_deferred_sepa_stripe_confirmed( WC_Order $order ) {

	if ( 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' ) ) {
		return true;
	}

	// `processing` counts: a SEPA debit sent and not yet settled is money on
	// its way, and marking that order "settled by cheque" would hide a real
	// debit about to land.
	if ( in_array( (string) $order->get_meta( '_dahu_deferred_sepa_result' ), array( 'processing', 'succeeded', 'disputed' ), true ) ) {
		return true;
	}

	return '' !== (string) $order->get_meta( '_stripe_charge_id' );
}

/**
 * Records that this order was settled another way.
 *
 * @param WC_Order $order   Order.
 * @param string   $reason  One of dahu_deferred_sepa_offline_reasons().
 * @param string   $comment Free text. Required for 'other'.
 * @param int      $user_id Who said so.
 * @return true|WP_Error
 */
function dahu_deferred_sepa_mark_settled_offline( WC_Order $order, $reason, $comment, $user_id = 0 ) {

	$reasons = dahu_deferred_sepa_offline_reasons();
	$reason  = (string) $reason;
	$comment = trim( (string) $comment );

	if ( ! isset( $reasons[ $reason ] ) ) {
		return new WP_Error(
			'dahu_deferred_sepa_offline_reason',
			__( 'Choose how this order was settled.', 'dahu-deferred-sepa' )
		);
	}

	// The one case the list cannot describe is the one that has to be
	// described.
	if ( 'other' === $reason && '' === $comment ) {
		return new WP_Error(
			'dahu_deferred_sepa_offline_comment',
			__( 'Say how it was settled: "another way" with nothing written is not an answer anybody can read in six months.', 'dahu-deferred-sepa' )
		);
	}

	if ( dahu_deferred_sepa_stripe_confirmed( $order ) ) {
		return new WP_Error(
			'dahu_deferred_sepa_offline_charged',
			__( 'This order has a confirmed Stripe debit, so it cannot be marked as settled another way. If it was paid twice, refund one of the two — recording it here would hide the debit instead.', 'dahu-deferred-sepa' )
		);
	}

	$previous = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );

	// The scheduled action first, and the meta after. The other way round
	// leaves a window in which the mark is recorded and the charge still
	// fires; this way round, the worst case is an action that fires and is
	// refused by `dahu_deferred_sepa_charge()`, which reads the mark.
	dahu_deferred_sepa_unschedule( $order->get_id() );

	$order->delete_meta_data( '_dahu_deferred_sepa_scheduled_ts' );

	$order->update_meta_data(
		'_dahu_deferred_sepa_offline',
		array(
			'reason'   => $reason,
			'comment'  => $comment,
			'by'       => (int) $user_id,
			'at'       => time(),
			// Kept so that the order can be put back where it was if the
			// mark is lifted, and so that the note says what was cancelled.
			'was_due'  => $previous,
		)
	);

	$order->save();

	$user = $user_id ? get_userdata( $user_id ) : null;

	$order->add_order_note(
		sprintf(
			/* translators: 1: how it was settled, 2: the comment or a dash, 3: who recorded it. */
			__( 'Deferred SEPA: recorded as settled another way — %1$s. %2$s Recorded by %3$s. No direct debit will be taken: the scheduled charge has been cancelled and a charge is refused even if an old scheduled action fires.', 'dahu-deferred-sepa' ),
			$reasons[ $reason ],
			'' !== $comment ? $comment : '—',
			$user instanceof WP_User ? $user->display_name : __( 'a shop user', 'dahu-deferred-sepa' )
		)
	);

	return true;
}

/**
 * Lifts the mark, while nothing has been charged.
 *
 * Nothing is rescheduled automatically. A charge that reappeared in the queue
 * because somebody un-ticked a box is a charge nobody decided to send: the
 * order goes back to having no date, and the shop sets one if it means to.
 *
 * @param WC_Order $order   Order.
 * @param int      $user_id Who said so.
 * @return true|WP_Error
 */
function dahu_deferred_sepa_unmark_settled_offline( WC_Order $order, $user_id = 0 ) {

	if ( ! dahu_deferred_sepa_is_settled_offline( $order ) ) {
		return new WP_Error(
			'dahu_deferred_sepa_offline_absent',
			__( 'This order is not marked as settled another way.', 'dahu-deferred-sepa' )
		);
	}

	if ( dahu_deferred_sepa_stripe_confirmed( $order ) ) {
		return new WP_Error(
			'dahu_deferred_sepa_offline_charged_since',
			__( 'A Stripe debit has been confirmed on this order since it was marked, so the mark stays: the money moved, and that is the fact the record has to keep.', 'dahu-deferred-sepa' )
		);
	}

	$record = dahu_deferred_sepa_offline_record( $order );

	$order->delete_meta_data( '_dahu_deferred_sepa_offline' );
	$order->save();

	$user = $user_id ? get_userdata( $user_id ) : null;

	$order->add_order_note(
		sprintf(
			/* translators: 1: who lifted it, 2: the date the charge had been due. */
			__( 'Deferred SEPA: the "settled another way" mark was lifted by %1$s. Nothing has been rescheduled — it had been due on %2$s — so set a date on this order if a direct debit is still wanted.', 'dahu-deferred-sepa' ),
			$user instanceof WP_User ? $user->display_name : __( 'a shop user', 'dahu-deferred-sepa' ),
			! empty( $record['was_due'] ) ? dahu_deferred_sepa_format_date( (int) $record['was_due'] ) : '—'
		)
	);

	return true;
}

/**
 * One line saying what was recorded, for a screen.
 *
 * @param WC_Order $order Order.
 * @return string Empty when the order carries no mark.
 */
function dahu_deferred_sepa_offline_summary( WC_Order $order ) {

	$record = dahu_deferred_sepa_offline_record( $order );

	if ( empty( $record['reason'] ) ) {
		return '';
	}

	$reasons = dahu_deferred_sepa_offline_reasons();
	$user    = ! empty( $record['by'] ) ? get_userdata( (int) $record['by'] ) : null;

	return sprintf(
		/* translators: 1: how it was settled, 2: date, 3: who recorded it, 4: the comment or a dash. */
		__( 'Settled another way — %1$s, recorded on %2$s by %3$s. %4$s', 'dahu-deferred-sepa' ),
		isset( $reasons[ $record['reason'] ] ) ? $reasons[ $record['reason'] ] : (string) $record['reason'],
		! empty( $record['at'] ) ? dahu_deferred_sepa_format_date( (int) $record['at'] ) : '—',
		$user instanceof WP_User ? $user->display_name : __( 'a shop user', 'dahu-deferred-sepa' ),
		! empty( $record['comment'] ) ? (string) $record['comment'] : '—'
	);
}
