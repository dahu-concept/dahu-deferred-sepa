<?php
/**
 * The order screen: when the charge is due, and moving it.
 *
 * Both order screens are covered — the HPOS one and the legacy post screen —
 * because a shop can be on either, and a metabox that silently fails to appear
 * on one of them is worse than none.
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hour of day, in the site's timezone, at which a rescheduled charge is sent.
 *
 * Mid-morning rather than midnight: a charge that fails should fail during
 * office hours, where somebody will see the order note the same day.
 *
 * @return int Hour, 0–23.
 */
function dahu_deferred_sepa_charge_hour() {
	/**
	 * Filters the hour at which a rescheduled charge is sent.
	 *
	 * @param int $hour Hour of day in the site's timezone.
	 */
	$hour = (int) apply_filters( 'dahu_deferred_sepa_charge_hour', 9 );

	return max( 0, min( 23, $hour ) );
}

add_action( 'add_meta_boxes', 'dahu_deferred_sepa_register_metabox' );

/**
 * Registers the metabox on both order screens.
 *
 * @return void
 */
function dahu_deferred_sepa_register_metabox() {
	foreach ( array( 'woocommerce_page_wc-orders', 'shop_order' ) as $screen ) {
		add_meta_box(
			'dahu_deferred_sepa_metabox',
			__( 'Deferred SEPA Direct Debit', 'dahu-deferred-sepa' ),
			'dahu_deferred_sepa_render_metabox',
			$screen,
			'side',
			'high'
		);
	}
}

/**
 * The metabox.
 *
 * @param WP_Post|WC_Order $post_or_order Whatever the screen passes.
 * @return void
 */
function dahu_deferred_sepa_render_metabox( $post_or_order ) {
	// The metabox is registered on 'woocommerce_page_wc-orders', which is also
	// the orders LIST screen id, so the callback can be reached with something
	// that is neither an order nor a post.
	$order = false;

	if ( $post_or_order instanceof WC_Order ) {
		$order = $post_or_order;
	} elseif ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
		$order = wc_get_order( $post_or_order->ID );
	}

	if ( ! $order ) {
		echo '<p>' . esc_html__( 'Order not found.', 'dahu-deferred-sepa' ) . '</p>';

		return;
	}

	if ( ! dahu_deferred_sepa_order_is_sepa( $order ) ) {
		echo '<p>' . esc_html__( 'This order was not paid by SEPA Direct Debit, so there is nothing to manage here.', 'dahu-deferred-sepa' ) . '</p>';

		return;
	}

	// The family sheet first, and its own second: the plugin's own rules
	// are written to sit on top of the shared components, so the order is
	// a dependency and not a hope. One handle across the family -- the
	// file is byte-identical everywhere, so whichever plugin loads it
	// first serves all of them, and a shop with six of these installed
	// downloads one copy.
	wp_enqueue_style(
		'dahu-ui',
		DAHU_DEFERRED_SEPA_URL . 'assets/css/dahu-ui.css',
		array(),
		DAHU_DEFERRED_SEPA_VERSION
	);

	wp_enqueue_style(
		'dahu-deferred-sepa-admin',
		DAHU_DEFERRED_SEPA_URL . 'assets/css/admin.css',
		array( 'dahu-ui' ),
		DAHU_DEFERRED_SEPA_VERSION
	);

	// Settled another way: said first, and nothing else is offered. A box
	// that still showed a date field under "this order was paid by cheque"
	// would be inviting somebody to schedule a second payment.
	if ( dahu_deferred_sepa_is_settled_offline( $order ) ) {

		printf(
			'<p class="dahu-sepa-charge__row"><strong>%s</strong></p>',
			esc_html( dahu_deferred_sepa_offline_summary( $order ) )
		);

		echo '<p class="description dahu-sepa-charge__hint">'
			. esc_html__( 'No direct debit will be taken on this order: nothing is scheduled, the date cannot be changed, and a charge is refused even if an old scheduled action fires.', 'dahu-deferred-sepa' )
			. '</p>';

		if ( current_user_can( 'edit_shop_orders' ) && ! dahu_deferred_sepa_stripe_confirmed( $order ) ) {

			wp_nonce_field( 'dahu_deferred_sepa_offline_' . $order->get_id(), 'dahu_deferred_sepa_offline_nonce' );
			?>
			<p class="dahu-sepa-charge__row">
				<label>
					<input type="checkbox" name="dahu_deferred_sepa_offline_lift" value="1">
					<?php esc_html_e( 'Lift this mark when the order is saved', 'dahu-deferred-sepa' ); ?>
				</label>
			</p>
			<p class="description dahu-sepa-charge__hint">
				<?php esc_html_e( 'Nothing is rescheduled when the mark is lifted: the order goes back to having no date, and you set one if a direct debit is still wanted.', 'dahu-deferred-sepa' ); ?>
			</p>
			<?php
		}

		dahu_deferred_sepa_render_order_diagnostics( $order );

		return;
	}

	$done = 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' );

	if ( $done ) {
		$executed = (int) $order->get_meta( '_dahu_deferred_sepa_executed_ts' );

		echo '<p class="dahu-sepa-charge__row">';

		if ( $executed ) {
			printf(
				/* translators: %s: date and time the charge was sent. */
				esc_html__( 'Charge sent on %s.', 'dahu-deferred-sepa' ),
				'<span class="dahu-sepa-charge__date">' . esc_html( dahu_deferred_sepa_format_date( $executed ) ) . '</span>'
			);
		} else {
			esc_html_e( 'Charge already sent.', 'dahu-deferred-sepa' );
		}

		echo '</p>';

		$result = (string) $order->get_meta( '_dahu_deferred_sepa_result' );

		if ( $result ) {
			echo '<p class="dahu-sepa-charge__row">' . dahu_deferred_sepa_result_badge( $result ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
		}
	} else {
		$scheduled = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );
		$attempted = (int) $order->get_meta( '_dahu_deferred_sepa_executed_ts' );
		$result    = (string) $order->get_meta( '_dahu_deferred_sepa_result' );

		wp_nonce_field( 'dahu_deferred_sepa_reschedule', 'dahu_deferred_sepa_nonce' );

		// An attempt that was made and refused, said here and not only in the
		// history. Without it this box read "charge due on the 14th" on an
		// order whose charge had been refused on the 14th — the one screen a
		// shop opens about that order, showing the one thing it needed to
		// know nowhere.
		if ( $attempted > 0 && '' !== $result ) {

			echo '<p class="dahu-sepa-charge__row">';
			printf(
				/* translators: %s: date and time of the attempt. */
				esc_html__( 'A charge was attempted on %s and did not go through.', 'dahu-deferred-sepa' ),
				'<span class="dahu-sepa-charge__date">' . esc_html( dahu_deferred_sepa_format_date( $attempted ) ) . '</span>'
			);
			echo ' ' . dahu_deferred_sepa_result_badge( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
			echo '</p>';

			echo '<p class="description dahu-sepa-charge__hint">'
				. esc_html__( 'Put right whatever the order note says, then set a new date below. Nothing is retried on its own: a charge sent twice is taken twice.', 'dahu-deferred-sepa' )
				. '</p>';
		}

		// What the mandate looks like now, which is the question the back
		// office is actually asking when it opens this box: a payment method
		// set by hand on an order whose customer has no approved mandate
		// produces a charge that will be refused days later.
		$blocking = dahu_deferred_sepa_blocking_reason( $order );

		if ( '' !== $blocking ) {

			printf(
				'<p class="dahu-sepa-charge__row"><strong>%s</strong></p>',
				esc_html( $blocking )
			);

			echo '<p class="description dahu-sepa-charge__hint">'
				. esc_html__( 'The mandate is read again when the charge comes due, so putting this right before the date is enough — nothing else needs doing here.', 'dahu-deferred-sepa' )
				. '</p>';
		}

		echo '<p class="dahu-sepa-charge__row">';

		if ( $scheduled ) {
			printf(
				/* translators: %s: date and time of the scheduled charge. */
				esc_html__( 'Charge due on %s.', 'dahu-deferred-sepa' ),
				'<span class="dahu-sepa-charge__date">' . esc_html( dahu_deferred_sepa_format_date( $scheduled ) ) . '</span>'
			);
		} else {
			esc_html_e( 'Nothing scheduled yet: the order has not been marked Completed.', 'dahu-deferred-sepa' );
		}

		echo '</p>';

		$value = $scheduled ? wp_date( 'Y-m-d', $scheduled, wp_timezone() ) : '';
		$min   = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS, wp_timezone() );
		?>
		<p class="dahu-sepa-charge__row">
			<label for="dahu_deferred_sepa_new_date">
				<strong><?php esc_html_e( 'Move the charge to', 'dahu-deferred-sepa' ); ?></strong>
			</label><br>
			<input type="date" id="dahu_deferred_sepa_new_date" name="dahu_deferred_sepa_new_date"
				value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>">
		</p>
		<p class="description dahu-sepa-charge__hint">
			<?php
			printf(
				/* translators: %s: time of day, already formatted. */
				esc_html__( 'The charge is sent at %s on the chosen day. SEPA then settles in a few working days. Leave the field as it is to change nothing, and save the order to apply.', 'dahu-deferred-sepa' ),
				esc_html(
					wp_date(
						get_option( 'time_format' ),
						// Any date will do: only the time is shown.
						( new DateTimeImmutable( 'today ' . dahu_deferred_sepa_charge_hour() . ':00', wp_timezone() ) )->getTimestamp(),
						wp_timezone()
					)
				)
			);
			?>
		</p>
		<?php
	}

	if ( ! $done && current_user_can( 'edit_shop_orders' ) ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=dahu_deferred_sepa_charge_now&order_id=' . $order->get_id() ),
			'dahu_deferred_sepa_charge_now_' . $order->get_id()
		);

		wp_enqueue_script(
			'dahu-deferred-sepa-admin-order',
			DAHU_DEFERRED_SEPA_URL . 'assets/js/admin-order.js',
			array(),
			DAHU_DEFERRED_SEPA_VERSION,
			true
		);

		wp_localize_script(
			'dahu-deferred-sepa-admin-order',
			'dahuDeferredSepaOrder',
			array(
				'confirm' => __( 'Charge this SEPA mandate now? This takes real money from the customer.', 'dahu-deferred-sepa' ),
			)
		);
		?>
		<div class="dahu-sepa-charge__actions">
			<a href="<?php echo esc_url( $url ); ?>" class="button button-secondary js-dahu-sepa-charge-now">
				<?php esc_html_e( 'Charge now', 'dahu-deferred-sepa' ); ?>
			</a>
		</div>
		<p class="description dahu-sepa-charge__hint">
			<?php esc_html_e( 'Sends the charge immediately instead of waiting for the due date. The scheduled action stays in place but will do nothing: the order is marked as charged, and the idempotency key rules out a second debit.', 'dahu-deferred-sepa' ); ?>
		</p>
		<?php
	}

	if ( ! $done && current_user_can( 'edit_shop_orders' ) && ! dahu_deferred_sepa_stripe_confirmed( $order ) ) {

		wp_nonce_field( 'dahu_deferred_sepa_offline_' . $order->get_id(), 'dahu_deferred_sepa_offline_nonce' );
		?>
		<hr>
		<p class="dahu-sepa-charge__row">
			<label for="dahu_deferred_sepa_offline_reason">
				<strong><?php esc_html_e( 'Settled another way', 'dahu-deferred-sepa' ); ?></strong>
			</label><br>
			<select id="dahu_deferred_sepa_offline_reason" name="dahu_deferred_sepa_offline_reason">
				<option value=""><?php esc_html_e( '— no, a direct debit is still due —', 'dahu-deferred-sepa' ); ?></option>
				<?php foreach ( dahu_deferred_sepa_offline_reasons() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="dahu-sepa-charge__row">
			<label for="dahu_deferred_sepa_offline_comment"><?php esc_html_e( 'How, exactly', 'dahu-deferred-sepa' ); ?></label><br>
			<input type="text" class="widefat" id="dahu_deferred_sepa_offline_comment"
				name="dahu_deferred_sepa_offline_comment" maxlength="200">
		</p>
		<p class="description dahu-sepa-charge__hint">
			<?php esc_html_e( 'Use this when the order was paid by cheque, by transfer, by card over the telephone or in Stripe by hand. It cancels the scheduled charge and takes the order out of the pending list, with a note saying who recorded it and why — which is what somebody reconciling a bank statement in six months will read. Required for "another way".', 'dahu-deferred-sepa' ); ?>
		</p>
		<?php
	}

	dahu_deferred_sepa_render_order_diagnostics( $order );
}

add_action( 'woocommerce_process_shop_order_meta', 'dahu_deferred_sepa_save_metabox', 20, 1 );
add_action( 'woocommerce_process_shop_order_meta', 'dahu_deferred_sepa_save_offline', 15, 1 );

/**
 * Records, or lifts, the "settled another way" mark when the order is saved.
 *
 * Its own nonce and its own priority, before the rescheduling handler at 20:
 * an order saved with both a new date and a settle-another-way reason is an
 * order somebody has settled, and the date must not be applied on top of it.
 * The handler at 20 then finds the mark and refuses the date, which is the
 * right way round.
 *
 * @param int $order_id Order id.
 * @return void
 */
function dahu_deferred_sepa_save_offline( $order_id ) {

	if ( ! isset( $_POST['dahu_deferred_sepa_offline_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dahu_deferred_sepa_offline_nonce'] ) ), 'dahu_deferred_sepa_offline_' . $order_id )
	) {
		return;
	}

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( ! $order || ! dahu_deferred_sepa_order_is_sepa( $order ) ) {
		return;
	}

	if ( ! empty( $_POST['dahu_deferred_sepa_offline_lift'] ) ) {

		$lifted = dahu_deferred_sepa_unmark_settled_offline( $order, get_current_user_id() );

		if ( is_wp_error( $lifted ) ) {
			$order->add_order_note( $lifted->get_error_message() );
		}

		return;
	}

	$reason = isset( $_POST['dahu_deferred_sepa_offline_reason'] ) ? sanitize_key( wp_unslash( $_POST['dahu_deferred_sepa_offline_reason'] ) ) : '';

	// The empty option means "no, a direct debit is still due", and it is the
	// default: a form saved without touching this field changes nothing.
	if ( '' === $reason ) {
		return;
	}

	$comment = isset( $_POST['dahu_deferred_sepa_offline_comment'] ) ? sanitize_text_field( wp_unslash( $_POST['dahu_deferred_sepa_offline_comment'] ) ) : '';

	$marked = dahu_deferred_sepa_mark_settled_offline( $order, $reason, $comment, get_current_user_id() );

	// Refused: said on the order and nothing recorded. A form that silently
	// drops a shop's answer is a form that gets filled in twice.
	if ( is_wp_error( $marked ) ) {
		$order->add_order_note( $marked->get_error_message() );
	}
}

/**
 * Applies a new charge date.
 *
 * @param int $order_id Order id.
 * @return void
 */
function dahu_deferred_sepa_save_metabox( $order_id ) {
	if ( ! isset( $_POST['dahu_deferred_sepa_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dahu_deferred_sepa_nonce'] ) ), 'dahu_deferred_sepa_reschedule' )
	) {
		return;
	}

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	if ( empty( $_POST['dahu_deferred_sepa_new_date'] ) ) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( ! $order || ! dahu_deferred_sepa_order_is_sepa( $order ) ) {
		return;
	}

	if ( 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' ) ) {
		return;
	}

	if ( dahu_deferred_sepa_is_settled_offline( $order ) ) {
		$order->add_order_note( __( 'Deferred SEPA: the date was not changed, because this order is recorded as settled another way.', 'dahu-deferred-sepa' ) );

		return;
	}

	$date = sanitize_text_field( wp_unslash( $_POST['dahu_deferred_sepa_new_date'] ) );

	// Checked before parsing. DateTime::createFromFormat() is lenient: it
	// happily rolls 2026-13-45 over into a real date months away and accepts
	// it, so a forged or fat-fingered value would move the charge somewhere
	// nobody asked for.
	if ( ! dahu_deferred_sepa_is_ymd( $date ) ) {
		$order->add_order_note( __( 'Deferred SEPA: that date could not be read, so the schedule is unchanged.', 'dahu-deferred-sepa' ) );

		return;
	}

	// Read in the site's own timezone: a shop manager typing a date means that
	// date where they are, not in UTC.
	$when = DateTime::createFromFormat(
		'Y-m-d H:i',
		$date . ' ' . sprintf( '%02d:00', dahu_deferred_sepa_charge_hour() ),
		wp_timezone()
	);

	if ( ! $when ) {
		$order->add_order_note( __( 'Deferred SEPA: that date could not be read, so the schedule is unchanged.', 'dahu-deferred-sepa' ) );

		return;
	}

	$timestamp = $when->getTimestamp();

	if ( $timestamp <= time() ) {
		$order->add_order_note( __( 'Deferred SEPA: that date is in the past, so the schedule is unchanged.', 'dahu-deferred-sepa' ) );

		return;
	}

	$previous = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );

	if ( $previous === $timestamp ) {
		return;
	}

	dahu_deferred_sepa_schedule_at( $order, $timestamp );

	$order->add_order_note(
		$previous
			? sprintf(
				/* translators: 1: previous date and time, 2: new date and time. */
				__( 'Deferred SEPA: charge moved by hand from %1$s to %2$s.', 'dahu-deferred-sepa' ),
				dahu_deferred_sepa_format_date( $previous ),
				dahu_deferred_sepa_format_date( $timestamp )
			)
			: sprintf(
				/* translators: %s: date and time of the charge. */
				__( 'Deferred SEPA: charge set by hand for %s.', 'dahu-deferred-sepa' ),
				dahu_deferred_sepa_format_date( $timestamp )
			)
	);
}

add_action( 'admin_post_dahu_deferred_sepa_charge_now', 'dahu_deferred_sepa_handle_charge_now' );

/**
 * Charges an order now, from the order screen.
 *
 * @return void
 */
function dahu_deferred_sepa_handle_charge_now() {
	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

	if ( ! $order_id
		|| ! current_user_can( 'edit_shop_orders' )
		|| ! isset( $_GET['_wpnonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'dahu_deferred_sepa_charge_now_' . $order_id )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'dahu-deferred-sepa' ), '', array( 'response' => 403 ) );
	}

	// Refused here as well as by `dahu_deferred_sepa_charge()`, so that the
	// shop reads why instead of pressing a button that silently does nothing.
	$order = wc_get_order( $order_id );

	if ( $order && dahu_deferred_sepa_is_settled_offline( $order ) ) {
		wp_die(
			esc_html__( 'This order is recorded as settled another way, so no direct debit can be taken on it. Lift that mark first if a charge really is wanted.', 'dahu-deferred-sepa' ),
			'',
			array( 'response' => 409, 'back_link' => true )
		);
	}

	dahu_deferred_sepa_charge( $order_id );

	$redirect = wp_get_referer();

	if ( ! $redirect ) {
		$redirect = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
	}

	wp_safe_redirect( $redirect );
	exit;
}
