<?php
/**
 * A deferred charge that never happened, said out loud.
 *
 * ## The failure this file exists for
 *
 * A deferred charge is the one payment path with nobody watching. The order
 * is marked Completed, the goods go out, and the money is supposed to move
 * days later, in a cron job, at Stripe. Every other payment path has a
 * customer sitting in front of a screen who finds out immediately that it
 * did not work.
 *
 * So the whole of this file is about one thing: the shop finds out. Not from
 * a screen it happens to open, not from an order note nobody reads, and not
 * weeks later when the bank statement is reconciled.
 *
 * There are two ways a deferred charge comes to nothing, and they are
 * different failures:
 *
 *  1. **It ran and was refused.** There is a reason, written on the order by
 *     `dahu_deferred_sepa_refuse()`: no approved mandate, the approval was
 *     withdrawn, the IBAN changed, Stripe said no. The order carries a
 *     result and it is in the history.
 *  2. **It never ran at all.** The due date passed and nothing happened —
 *     the queue was blocked, the site had no traffic for the cron to ride
 *     on, the action was lost. The order carries a date in the past, no
 *     result, and nothing anywhere says so. This is the silent one, and it
 *     is the one that shows a shop an order as "pending" with a settlement
 *     estimate that will never arrive.
 *
 * `dahu_deferred_sepa_is_overdue()` names the second; the email below names
 * both.
 *
 * ## One alert per failure, and not one per day
 *
 * An alert that repeats is an alert that gets filtered into a folder, and a
 * filtered alert is worse than none: it is silence a shop believes is
 * coverage. So every alert carries a fingerprint — what failed, the date it
 * was due, and the sentence a person reads — kept on the order in
 * `_dahu_deferred_sepa_alerted`. The same failure, re-detected tomorrow by
 * the same sweep, sends nothing. A rescheduling moves the due date, so the
 * fingerprint changes, so a charge that fails again after being retried is
 * said again. That is the behaviour wanted on both sides: no repetition of
 * the same news, and no swallowing of new news that looks like old news.
 *
 * The store of fingerprints is capped. An order that somehow failed thirty
 * times does not need its first twenty remembered, and an unbounded array in
 * order meta is a row that grows forever.
 *
 * ## What never goes in the email
 *
 * The carrier of the money is Stripe and its answers are JSON, with
 * identifiers, amounts, and sometimes a customer's name. Raw responses stay
 * where they already are — the order note and the log — and the email
 * carries the sentence written for a person plus the order number. An email
 * goes through somebody else's mail server, is forwarded, and is kept
 * forever by the recipient's client.
 *
 * ## Why nothing here refuses to schedule
 *
 * It would be easy to check the mandate when the order is completed and
 * refuse to schedule at all when there is none. That is the wrong answer,
 * and not for a theoretical reason: a shop that approves a mandate *after*
 * marking the order Completed is a shop whose normal working day this would
 * break, and the delay exists precisely so that things can change between
 * the order and the charge. The mandate is therefore re-read at charge time,
 * as it always was — and the state as it stands *now* is said in the note
 * when the charge is scheduled, so that the shop can fix it in the days it
 * has rather than find out on the day.
 *
 * @package DahuDeferredSepa
 */

defined( 'ABSPATH' ) || exit;

/**
 * How late a charge has to be before it is called overdue.
 *
 * A day, and the reason it is not an hour: Action Scheduler runs on the
 * site's own traffic, so a shop with nobody browsing it overnight is
 * routinely minutes or hours late through nothing being wrong. An alert that
 * fires on a quiet night is an alert that teaches its reader to ignore it.
 *
 * @return int Seconds.
 */
function dahu_deferred_sepa_overdue_grace() {

	/**
	 * Filters how late a scheduled charge may be before it is reported.
	 *
	 * @param int $seconds Grace period, in seconds.
	 */
	$grace = (int) apply_filters( 'dahu_deferred_sepa_overdue_grace', DAY_IN_SECONDS );

	// Never negative, and never zero: zero would report every charge the
	// moment its date passed, which is every charge, every time.
	return $grace > 0 ? $grace : DAY_IN_SECONDS;
}

/**
 * Was this charge due, and nothing happened?
 *
 * Deliberately NOT "did it fail": an order that ran and was refused carries
 * `_dahu_deferred_sepa_executed_ts` and a result, and it is in the history
 * with its reason. This answers the other question — the date passed, and
 * there is no trace of anything having been tried.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function dahu_deferred_sepa_is_overdue( WC_Order $order ) {

	if ( ! dahu_deferred_sepa_order_is_sepa( $order ) ) {
		return false;
	}

	if ( 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' ) ) {
		return false;
	}

	// Settled another way, on purpose, by somebody who said so. Not late:
	// paid. Without this, the shop that marks a cheque would be told about
	// that order every day, which is how an alert becomes noise.
	if ( dahu_deferred_sepa_is_settled_offline( $order ) ) {
		return false;
	}

	$when = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );

	// An attempt was recorded **for this date**, whatever became of it: that
	// belongs to the history and its result, not to this question.
	//
	// Compared with the scheduled date rather than merely tested for
	// existence, and that is a correction: a charge that was refused and then
	// given a new date would otherwise be silent for ever, because the
	// refusal had stamped an attempt. The order would sit with a date in the
	// past, no second attempt, and nothing watching it — which is the exact
	// failure this file was written for, reintroduced by the fix for it.
	//
	// The attempt is not erased on rescheduling, deliberately: the history
	// screen is bounded on there having been one, and clearing it would take
	// the refused charge out of the record. The two facts live side by side —
	// an attempt was made on the 14th, another is due on the 21st — and the
	// comparison is what reads them in the right order.
	$attempt = (int) $order->get_meta( '_dahu_deferred_sepa_executed_ts' );

	if ( $attempt > 0 && $attempt >= $when ) {
		return false;
	}

	// No date at all is an order whose clock has not started — it is not
	// late, it is waiting to be completed.
	if ( $when <= 0 ) {
		return false;
	}

	return ( $when + dahu_deferred_sepa_overdue_grace() ) < time();
}

/**
 * Why this order could not be charged as things stand, or ''.
 *
 * A reading, with no side effect and nothing written: the same function the
 * charge itself uses, asked early. It is what turns "this charge is late"
 * into an errand somebody can run — "no approved mandate" is fixed on a
 * profile in a minute, and the alternative is a shop opening Stripe looking
 * for a payment that was never sent.
 *
 * An empty string means nothing is visibly wrong, which on an overdue order
 * points at the scheduler rather than at the mandate.
 *
 * @param WC_Order $order Order.
 * @return string Translated sentence, or ''.
 */
function dahu_deferred_sepa_blocking_reason( WC_Order $order ) {

	$mandate = dahu_deferred_sepa_mandate_for_order( $order );

	return is_wp_error( $mandate ) ? (string) $mandate->get_error_message() : '';
}

/**
 * Has this exact failure already been reported for this order?
 *
 * @param WC_Order $order       Order.
 * @param string   $fingerprint Fingerprint.
 * @return bool
 */
function dahu_deferred_sepa_already_alerted( WC_Order $order, $fingerprint ) {

	$seen = $order->get_meta( '_dahu_deferred_sepa_alerted' );

	return is_array( $seen ) && in_array( (string) $fingerprint, $seen, true );
}

/**
 * Writes down that this failure has been reported.
 *
 * @param WC_Order $order       Order.
 * @param string   $fingerprint Fingerprint.
 * @return void
 */
function dahu_deferred_sepa_remember_alert( WC_Order $order, $fingerprint ) {

	$seen = $order->get_meta( '_dahu_deferred_sepa_alerted' );
	$seen = is_array( $seen ) ? $seen : array();

	$seen[] = (string) $fingerprint;

	// The last twenty, oldest dropped. Order meta is a row in a table, not a
	// log file.
	if ( count( $seen ) > 20 ) {
		$seen = array_slice( $seen, -20 );
	}

	$order->update_meta_data( '_dahu_deferred_sepa_alerted', $seen );
	$order->save();
}

/**
 * The fingerprint of one failure.
 *
 * The due date is in it on purpose: the same refusal on a charge that has
 * since been rescheduled is new news, and the shop has to hear it.
 *
 * @param WC_Order $order   Order.
 * @param string   $code    What failed.
 * @param string   $message The sentence a person reads.
 * @return string
 */
function dahu_deferred_sepa_alert_fingerprint( WC_Order $order, $code, $message ) {

	// Which cycle this alert belongs to.
	//
	// The attempt first, the due date only when there has been no attempt.
	// Both are needed, and the order matters:
	//
	// - The overdue sweep alerts about charges that NEVER ran, so there is no
	//   attempt to key on. The due date is what distinguishes "late on the
	//   14th" from "late again on the 21st after a reschedule".
	// - Every path that alerts about a charge that DID run has already deleted
	//   the due date by the time it alerts — `dahu_deferred_sepa_refuse()`
	//   deletes it before calling here, `dahu_deferred_sepa_mark_done()` deletes
	//   it too. Keyed on the date alone those alerts all fingerprint as 0, and
	//   the second refusal on the same order — after the shop put the cause
	//   right and rescheduled — was swallowed as old news. A refused charge
	//   going unreported is the exact thing this file exists to prevent, so
	//   that was the worst kind of bug: a watchdog quietly covering a silence.
	$cycle = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );

	if ( ! $cycle ) {
		// Both paths that alert about a charge which RAN have already deleted
		// the live date, and both keep the cycle's date under this key before
		// doing so. Preferred over the attempt timestamp because two refusals
		// within the same second — which the bench can produce and a retry
		// loop could too — would otherwise share a fingerprint.
		$cycle = (int) $order->get_meta( '_dahu_deferred_sepa_final_scheduled_ts' );
	}

	if ( ! $cycle ) {
		$cycle = (int) $order->get_meta( '_dahu_deferred_sepa_executed_ts' );
	}

	return md5(
		implode(
			'|',
			array(
				(string) $code,
				(string) $cycle,
				(string) $message,
			)
		)
	);
}

/**
 * Tells the shop that one charge was refused — once.
 *
 * Called from the refusal path, so every reason that stops a charge arrives
 * by email as well as being written on the order.
 *
 * @param WC_Order $order   Order.
 * @param string   $code    What failed, for the fingerprint.
 * @param string   $message The sentence written on the order. No raw payload.
 * @return bool Whether an email was sent.
 */
function dahu_deferred_sepa_alert_failure( WC_Order $order, $code, $message ) {

	$fingerprint = dahu_deferred_sepa_alert_fingerprint( $order, $code, $message );

	if ( dahu_deferred_sepa_already_alerted( $order, $fingerprint ) ) {
		return false;
	}

	// Written BEFORE the email is handed over, not after. A mail server that
	// times out must not be the reason the same alert goes out again on the
	// next run: one of those two mistakes sends an email twice, the other
	// sends it in a loop.
	dahu_deferred_sepa_remember_alert( $order, $fingerprint );

	$to = dahu_deferred_sepa_notify_email();

	if ( ! $to ) {
		return false;
	}

	$subject = sprintf(
		/* translators: %s: order number. */
		__( 'Deferred SEPA charge refused on order #%s', 'dahu-deferred-sepa' ),
		$order->get_order_number()
	);

	$body = sprintf(
		/* translators: 1: order number, 2: amount, 3: the reason, 4: link to the order. */
		__(
			"A deferred SEPA charge was refused and no money moved.\n\nOrder: #%1\$s\nAmount: %2\$s\n\n%3\$s\n\nThe order:\n%4\$s\n\nNothing is retried automatically: a charge sent twice is taken twice. Put right whatever the sentence above names, then reschedule the charge from the order.",
			'dahu-deferred-sepa'
		),
		$order->get_order_number(),
		html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
		(string) $message,
		$order->get_edit_order_url()
	);

	/**
	 * Filters the "charge refused" alert.
	 *
	 * @param array    $mail  'to', 'subject' and 'body'.
	 * @param WC_Order $order Order.
	 * @param string   $code  What failed.
	 */
	$mail = wp_parse_args(
		(array) apply_filters(
			'dahu_deferred_sepa_charge_refused_email',
			compact( 'to', 'subject', 'body' ),
			$order,
			$code
		),
		compact( 'to', 'subject', 'body' )
	);

	wp_mail( $mail['to'], $mail['subject'], $mail['body'] );

	return true;
}

/**
 * Every charge that was due and never ran.
 *
 * @return WC_Order[]
 */
function dahu_deferred_sepa_overdue_orders() {

	$orders = wc_get_orders(
		array(
			'limit'        => -1,
			'meta_key'     => '_dahu_deferred_sepa_scheduled_ts', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- wc_get_orders' own argument.
			'meta_compare' => 'EXISTS',
		)
	);

	$late = array();

	foreach ( (array) $orders as $order ) {

		if ( $order instanceof WC_Order && dahu_deferred_sepa_is_overdue( $order ) ) {
			$late[] = $order;
		}
	}

	// Oldest first: the one that has been unpaid longest is the one to look
	// at first, and it is the one furthest down a list sorted any other way.
	usort(
		$late,
		static function ( $a, $b ) {
			return (int) $a->get_meta( '_dahu_deferred_sepa_scheduled_ts' ) <=> (int) $b->get_meta( '_dahu_deferred_sepa_scheduled_ts' );
		}
	);

	return $late;
}

/**
 * The daily sweep: finds charges that never ran, and says so once.
 *
 * One email for the whole sweep, not one per order. A shop that discovers
 * twelve unpaid orders needs one list it reads, and twelve emails is a list
 * nobody reads in the order it matters.
 *
 * Nothing here charges anything, reschedules anything or touches Stripe. A
 * charge that may or may not have been sent is not a charge to send again
 * from a cron job with nobody watching: the sweep's whole job is to put a
 * person in front of the question.
 *
 * @return int How many orders the email named, 0 when nothing was sent.
 */
function dahu_deferred_sepa_sweep() {

	$late = dahu_deferred_sepa_overdue_orders();

	if ( ! $late ) {
		return 0;
	}

	$fresh = array();

	foreach ( $late as $order ) {

		$fingerprint = dahu_deferred_sepa_alert_fingerprint( $order, 'overdue', '' );

		if ( ! dahu_deferred_sepa_already_alerted( $order, $fingerprint ) ) {
			$fresh[] = $order;
		}
	}

	// Every one of them was already reported. The orders are still overdue
	// and still on the screen; what is not repeated is the email.
	if ( ! $fresh ) {
		return 0;
	}

	foreach ( $fresh as $order ) {
		dahu_deferred_sepa_remember_alert( $order, dahu_deferred_sepa_alert_fingerprint( $order, 'overdue', '' ) );
	}

	$to = dahu_deferred_sepa_notify_email();

	if ( ! $to ) {
		return count( $fresh );
	}

	$lines = array();

	foreach ( $fresh as $order ) {

		$reason = dahu_deferred_sepa_blocking_reason( $order );

		$lines[] = sprintf(
			/* translators: 1: order number, 2: amount, 3: the date the charge was due, 4: what is in the way, already a sentence. */
			__( "#%1\$s — %2\$s — was due %3\$s\n    %4\$s", 'dahu-deferred-sepa' ),
			$order->get_order_number(),
			html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
			dahu_deferred_sepa_format_date( (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' ) ),
			'' !== $reason
				? $reason
				: __( 'Nothing is visibly wrong with this order, so the charge was never run at all: the scheduled job was lost or the queue is not running.', 'dahu-deferred-sepa' )
		);
	}

	$subject = sprintf(
		/* translators: %d: number of orders. */
		_n(
			'%d deferred SEPA charge was due and never ran',
			'%d deferred SEPA charges were due and never ran',
			count( $fresh ),
			'dahu-deferred-sepa'
		),
		count( $fresh )
	);

	$body = sprintf(
		/* translators: 1: the list of orders, one per line, 2: link to the charges screen. */
		__(
			"These orders have a deferred charge whose date has passed, and no charge was ever attempted on them. No money has moved and the customers have their goods.\n\n%1\$s\n\nThe full list, with the amounts:\n%2\$s\n\nNothing is retried automatically, because a charge that may already have been sent would be taken twice. Check each order, then reschedule it from the order itself.\n\nThis list is sent once per order. An order that stays overdue is not reported again, so it stays on the screen above until it is dealt with.",
			'dahu-deferred-sepa'
		),
		implode( "\n\n", $lines ),
		admin_url( 'admin.php?page=' . DAHU_DEFERRED_SEPA_CHARGES_PAGE )
	);

	/**
	 * Filters the "charges never ran" sweep alert.
	 *
	 * @param array      $mail  'to', 'subject' and 'body'.
	 * @param WC_Order[] $fresh The orders named in it.
	 */
	$mail = wp_parse_args(
		(array) apply_filters(
			'dahu_deferred_sepa_overdue_email',
			compact( 'to', 'subject', 'body' ),
			$fresh
		),
		compact( 'to', 'subject', 'body' )
	);

	wp_mail( $mail['to'], $mail['subject'], $mail['body'] );

	dahu_deferred_sepa_log( sprintf( '%d overdue charge(s) reported', count( $fresh ) ), 'error' );

	update_option( 'dahu_deferred_sepa_last_sweep', time(), false );

	return count( $fresh );
}

/**
 * When the sweep last ran, or 0.
 *
 * Read by the screen, and the reason it is an option and not a transient:
 * the one thing worth knowing about a watchdog is when it last barked, and a
 * transient that expired answers "never".
 *
 * @return int
 */
function dahu_deferred_sepa_last_sweep() {

	return (int) get_option( 'dahu_deferred_sepa_last_sweep', 0 );
}

/**
 * Keeps the daily sweep scheduled.
 *
 * Checked on every admin load rather than only on activation: a shop that
 * loses its cron entry — a migration, a plugin that flushes the schedule, a
 * restore from a backup taken mid-run — would otherwise have a watchdog that
 * is silently gone, which is the failure this file is about.
 *
 * @return void
 */
function dahu_deferred_sepa_keep_sweep_scheduled() {

	if ( ! wp_next_scheduled( 'dahu_deferred_sepa_sweep' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dahu_deferred_sepa_sweep' );
	}
}

add_action( 'dahu_deferred_sepa_sweep', 'dahu_deferred_sepa_sweep' );
add_action( 'admin_init', 'dahu_deferred_sepa_keep_sweep_scheduled' );

/**
 * Tells the shop that money which HAD moved has moved back.
 *
 * Not the same event as a refused charge, and deliberately not the same email.
 *
 * A refusal happens while the charge is being sent: nothing moved, the order
 * is still scheduled, and the right instruction is "put the cause right and
 * reschedule". A reversal happens days later and from the other direction —
 * Stripe accepted the debit, the bank pulled it back (an R-transaction), or the
 * customer disputed it. There, the money moved and came back, the charge is
 * recorded as done, and this plugin's own guard REFUSES to reschedule it,
 * because rescheduling a confirmed debit is how a customer gets charged twice.
 *
 * So an email telling the shop to reschedule would be an instruction the code
 * forbids. Nor does this one say "settle it another way and record that":
 * `dahu_deferred_sepa_mark_settled_offline()` REFUSES an order whose debit is
 * confirmed, and the order screen does not even draw the form. Advice the code
 * turns down is worse than no advice — it sends somebody looking for a button
 * that is not there.
 *
 * What is left is true and is all this email claims: nothing has been retried
 * and nothing has been changed here, the reason is on the order and in the
 * Stripe dashboard, and that is where the decision belongs.
 *
 * NOT for a charge Stripe refused before settlement. That one took no money,
 * and the charge path reopens it and sends the ordinary refusal alert, whose
 * "put it right and reschedule" is then achievable. See the
 * `payment_intent.payment_failed` branch in includes/webhook.php.
 *
 * Nothing here carries Stripe's own wording: a bank's rejection text is for
 * the order note and the dashboard, not for an email that may be forwarded.
 *
 * @param WC_Order $order Order.
 * @param string   $kind  'failed' (the bank took it back) or 'disputed'.
 * @return bool Whether an email was handed over.
 */
function dahu_deferred_sepa_alert_reversal( WC_Order $order, $kind ) {

	$kind = 'disputed' === $kind ? 'disputed' : 'failed';

	// A distinct code per kind, so that a reversal is never deduplicated
	// against a refusal alert, and a dispute is never read as a repeat of a
	// bank return. The cycle comes from the shared fingerprint, which keys on
	// the attempt once there has been one.
	$fingerprint = dahu_deferred_sepa_alert_fingerprint( $order, 'reversal-' . $kind, '' );

	if ( dahu_deferred_sepa_already_alerted( $order, $fingerprint ) ) {
		return false;
	}

	// Written first, for the same reason as the refusal alert: a mail server
	// that times out must not turn one reversal into a stream of emails.
	// Stripe retries a webhook it believes failed, so this path is called
	// again by design.
	dahu_deferred_sepa_remember_alert( $order, $fingerprint );

	$to = dahu_deferred_sepa_notify_email();

	if ( ! $to ) {
		return false;
	}

	$amount = html_entity_decode(
		wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
		ENT_QUOTES,
		'UTF-8'
	);

	if ( 'disputed' === $kind ) {
		$subject = sprintf(
			/* translators: %s: order number. */
			__( 'SEPA charge disputed by the customer on order #%s', 'dahu-deferred-sepa' ),
			$order->get_order_number()
		);

		$body = sprintf(
			/* translators: 1: order number, 2: amount, 3: link to the order. */
			__(
				"The customer has disputed a SEPA charge that had already been collected. The money is being taken back.\n\nOrder: #%1\$s\nAmount: %2\$s\n\nThe order:\n%3\$s\n\nNothing has been retried and nothing has been changed on the order: this charge is recorded as collected, and sending it again would take the money twice. The reason is on the order and in the Stripe dashboard, which is where a dispute is answered or accepted.",
				'dahu-deferred-sepa'
			),
			$order->get_order_number(),
			$amount,
			$order->get_edit_order_url()
		);
	} else {
		$subject = sprintf(
			/* translators: %s: order number. */
			__( 'Stripe reports a failed SEPA charge on collected order #%s', 'dahu-deferred-sepa' ),
			$order->get_order_number()
		);

		$body = sprintf(
			/* translators: 1: order number, 2: amount, 3: link to the order. */
			__(
				"Stripe reports that a SEPA charge has failed on an order whose charge is recorded as collected. If the bank has taken the money back, the order is not paid and whatever was in it has already gone out.\n\nOrder: #%1\$s\nAmount: %2\$s\n\nThe order:\n%3\$s\n\nNothing has been retried and nothing has been changed on the order, because a charge recorded as collected must not be sent again. What the money actually did is in the Stripe dashboard, and the reason is on the order.",
				'dahu-deferred-sepa'
			),
			$order->get_order_number(),
			$amount,
			$order->get_edit_order_url()
		);
	}

	/**
	 * Filters the "charge reversed" alert.
	 *
	 * @param array    $mail  'to', 'subject' and 'body'.
	 * @param WC_Order $order Order.
	 * @param string   $kind  'failed' or 'disputed'.
	 */
	$mail = wp_parse_args(
		(array) apply_filters(
			'dahu_deferred_sepa_charge_reversed_email',
			compact( 'to', 'subject', 'body' ),
			$order,
			$kind
		),
		compact( 'to', 'subject', 'body' )
	);

	wp_mail( $mail['to'], $mail['subject'], $mail['body'] );

	return true;
}
