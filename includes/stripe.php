<?php
/**
 * Talking to Stripe, and the small helpers everything else shares.
 *
 * There is no SDK here on purpose: the plugin makes three kinds of call
 * (retrieve a payment method, create a PaymentIntent, confirm one), all
 * form-encoded, all through wp_remote_request(). A vendored SDK would be more
 * code than the plugin and another thing to keep patched.
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stripe's live API root. */
define( 'DAHU_DEFERRED_SEPA_API', 'https://api.stripe.com/v1/' );

/**
 * Currency Stripe accepts for SEPA Direct Debit. Everything else has to be
 * refused rather than charged in the wrong unit.
 */
define( 'DAHU_DEFERRED_SEPA_CURRENCY', 'EUR' );

/**
 * The Stripe secret key to charge with.
 *
 * Two sources, in this order:
 *
 * 1. This gateway's own `secret_key` / `test_secret_key` settings. A shop that
 *    fills these in is independent of any other plugin, and can use a
 *    restricted key that may only write PaymentIntents.
 * 2. The official WooCommerce Stripe Gateway's stored settings.
 *
 * The second source is a convenience, not a contract: `woocommerce_stripe_settings`
 * is that plugin's own option and its shape is not a published API. If it ever
 * changes, this returns an empty string, the charge is not attempted, and the
 * order says so — it must never fall through to charging with the wrong key.
 *
 * @return string Secret key, or '' when none is configured.
 */
function dahu_deferred_sepa_get_secret_key() {
	$own  = dahu_deferred_sepa_settings();
	$test = 'yes' === ( $own['testmode'] ?? 'no' );

	$key = $test ? ( $own['test_secret_key'] ?? '' ) : ( $own['secret_key'] ?? '' );

	if ( ! $key ) {
		$stripe = get_option( 'woocommerce_stripe_settings', array() );

		if ( is_array( $stripe ) ) {
			// The official plugin's own test flag decides, not ours: the key has
			// to match the mode the mandate was created in.
			$test = isset( $stripe['testmode'] ) && 'yes' === $stripe['testmode'];
			$key  = $test ? ( $stripe['test_secret_key'] ?? '' ) : ( $stripe['secret_key'] ?? '' );
		}
	}

	$key = is_string( $key ) ? trim( $key ) : '';

	/**
	 * Filters the Stripe secret key used for deferred charges.
	 *
	 * For a shop that keeps its keys outside the database altogether.
	 *
	 * @param string $key Resolved secret key, possibly ''.
	 */
	return (string) apply_filters( 'dahu_deferred_sepa_secret_key', $key );
}

/**
 * Whether the resolved key is a test-mode key.
 *
 * Read from the key itself rather than from a setting, because the setting and
 * the key can disagree and the key is what Stripe will act on.
 *
 * @return bool
 */
function dahu_deferred_sepa_is_test_mode() {
	$key = dahu_deferred_sepa_get_secret_key();

	return 0 === strpos( $key, 'sk_test_' ) || 0 === strpos( $key, 'rk_test_' );
}

/**
 * A stable, persistent idempotency key for an order.
 *
 * Generated once and reused, so every retry of the scheduled action carries the
 * same key and Stripe charges once however many times the action runs. This is
 * the single most important line in the plugin.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function dahu_deferred_sepa_get_idempotency_key( WC_Order $order ) {
	$key = $order->get_meta( '_dahu_deferred_sepa_idem' );

	if ( ! $key ) {
		$key = 'dahu_deferred_sepa_' . $order->get_id() . '_' . wp_generate_uuid4();

		$order->update_meta_data( '_dahu_deferred_sepa_idem', $key );
		$order->save();
	}

	return (string) $key;
}

/**
 * One request to the Stripe API.
 *
 * No pinned `Stripe-Version` header: the account's own API version applies,
 * which is the same one the official plugin is working against. Pinning a
 * version here would let the two drift apart silently.
 *
 * @param string $method          'GET' or 'POST'.
 * @param string $endpoint        Path after /v1/.
 * @param array  $body            Request body, nested arrays allowed.
 * @param string $secret_key      Stripe secret key.
 * @param string $idempotency_key Idempotency key, POST only.
 * @return array|WP_Error Decoded response, or an error.
 */
function dahu_deferred_sepa_stripe_request( $method, $endpoint, $body, $secret_key, $idempotency_key = '' ) {
	$args = array(
		'method'  => $method,
		'timeout' => 45,
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret_key,
		),
	);

	if ( 'POST' === $method ) {
		// Only meaningful on a mutating request. This is what stops a double charge.
		if ( $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

		if ( ! empty( $body ) ) {
			$args['body'] = dahu_deferred_sepa_flatten_body( $body );
		}
	}

	$response = wp_remote_request( DAHU_DEFERRED_SEPA_API . $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		dahu_deferred_sepa_log( sprintf( '%s %s failed: %s', $method, $endpoint, $response->get_error_message() ), 'error' );

		return $response;
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $decoded ) ) {
		dahu_deferred_sepa_log(
			sprintf( '%s %s returned unreadable JSON (HTTP %d)', $method, $endpoint, (int) wp_remote_retrieve_response_code( $response ) ),
			'error'
		);

		return new WP_Error(
			'dahu_deferred_sepa_bad_json',
			__( 'Stripe returned a response that could not be read.', 'dahu-deferred-sepa' )
		);
	}

	if ( isset( $decoded['error'] ) ) {
		dahu_deferred_sepa_log(
			sprintf(
				'%s %s returned error: %s',
				$method,
				$endpoint,
				isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'unspecified'
			),
			'error'
		);
	}

	return $decoded;
}

/**
 * Flattens a nested array into the form-encoded keys Stripe expects, such as
 * `metadata[order_id]` or `payment_method_types[0]`.
 *
 * @param array  $body   Body.
 * @param string $prefix Key prefix, used by the recursion.
 * @return array<string,scalar>
 */
function dahu_deferred_sepa_flatten_body( array $body, $prefix = '' ) {
	$flat = array();

	foreach ( $body as $key => $value ) {
		$full = $prefix ? $prefix . '[' . $key . ']' : $key;

		if ( is_array( $value ) ) {
			$flat = array_merge( $flat, dahu_deferred_sepa_flatten_body( $value, $full ) );
		} else {
			$flat[ $full ] = $value;
		}
	}

	return $flat;
}

/**
 * Order total in the smallest currency unit.
 *
 * SEPA Direct Debit is euro-only at Stripe, and the euro has two decimals, so
 * this is a multiplication by 100. The currency is checked before any charge
 * (see dahu_deferred_sepa_currency_error()) rather than guessed here, because a
 * wrong unit would charge a hundred times too much or too little.
 *
 * @param WC_Order $order Order.
 * @return int
 */
function dahu_deferred_sepa_amount_in_cents( WC_Order $order ) {
	return (int) round( (float) $order->get_total() * 100 );
}

/**
 * Why an order cannot be charged by SEPA, when its currency is not the one
 * Stripe accepts for it.
 *
 * @param WC_Order $order Order.
 * @return string Reason, or '' when the currency is fine.
 */
function dahu_deferred_sepa_currency_error( WC_Order $order ) {
	$currency = strtoupper( (string) $order->get_currency() );

	if ( DAHU_DEFERRED_SEPA_CURRENCY === $currency ) {
		return '';
	}

	return sprintf(
		/* translators: 1: the order's currency, 2: the only currency SEPA accepts. */
		__( 'This order is in %1$s. Stripe only accepts SEPA Direct Debit in %2$s, so no charge was attempted.', 'dahu-deferred-sepa' ),
		$currency,
		DAHU_DEFERRED_SEPA_CURRENCY
	);
}

/**
 * A timestamp as a date and time, in the site's own timezone and format.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function dahu_deferred_sepa_format_date( $timestamp ) {
	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

	return (string) wp_date( $format, (int) $timestamp, wp_timezone() );
}

/**
 * A timestamp as a date alone, in the site's own timezone and format.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function dahu_deferred_sepa_format_day( $timestamp ) {
	return (string) wp_date( get_option( 'date_format' ), (int) $timestamp, wp_timezone() );
}

/**
 * Writes to WooCommerce's own log, under this plugin's handle.
 *
 * A deferred charge happens with nobody watching, so when one fails the order
 * note says what the customer-facing outcome was and the log says what Stripe
 * actually answered.
 *
 * @param string $message Message.
 * @param string $level   A WC_Log_Levels level.
 * @return void
 */
function dahu_deferred_sepa_log( $message, $level = 'info' ) {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	wc_get_logger()->log( $level, $message, array( 'source' => 'dahu-deferred-sepa' ) );
}

/**
 * Whether the thing handed in is an order this plugin takes care of.
 *
 * Accepts an order or an id, because the filters it is called from do not all
 * pass the same thing. When the order cannot be determined it returns false:
 * the safe answer is always "not ours".
 *
 * @param WC_Order|int|null $order Order or order id.
 * @return bool
 */
function dahu_deferred_sepa_order_is_sepa( $order ) {
	if ( $order instanceof WC_Order ) {
		return in_array( $order->get_payment_method(), DAHU_DEFERRED_SEPA_GATEWAYS, true );
	}

	if ( is_numeric( $order ) ) {
		$maybe = wc_get_order( (int) $order );

		return $maybe && in_array( $maybe->get_payment_method(), DAHU_DEFERRED_SEPA_GATEWAYS, true );
	}

	return false;
}

/**
 * The Stripe customer (cus_…) that owns a payment method (pm_…).
 *
 * Cached in user meta keyed BY the payment method, so changing mandate — or
 * moving to another Stripe account — invalidates the cache instead of serving a
 * customer id from a different environment.
 *
 * @param string $pm_id   Stripe payment method id.
 * @param int    $user_id WordPress user.
 * @return string Customer id, or ''.
 */
function dahu_deferred_sepa_fetch_customer_from_stripe( $pm_id, $user_id ) {
	$cached = get_user_meta( $user_id, '_dahu_deferred_sepa_customer_for_pm', true );

	if ( is_array( $cached )
		&& ( $cached['pm'] ?? '' ) === $pm_id
		&& 0 === strpos( (string) ( $cached['cus'] ?? '' ), 'cus_' )
	) {
		return (string) $cached['cus'];
	}

	$secret_key = dahu_deferred_sepa_get_secret_key();

	if ( ! $secret_key ) {
		return '';
	}

	$pm = dahu_deferred_sepa_stripe_request( 'GET', 'payment_methods/' . $pm_id, array(), $secret_key );

	if ( is_wp_error( $pm ) || empty( $pm['customer'] ) || 0 !== strpos( (string) $pm['customer'], 'cus_' ) ) {
		return '';
	}

	$customer = sanitize_text_field( $pm['customer'] );

	update_user_meta(
		$user_id,
		'_dahu_deferred_sepa_customer_for_pm',
		array(
			'pm'  => $pm_id,
			'cus' => $customer,
		)
	);

	return $customer;
}

/**
 * The Stripe customer id of a WordPress user.
 *
 * User meta first, then the most recent order that actually carries the meta.
 * Targeting orders that have it, rather than the last few orders of any kind,
 * matters: a run of card or cancelled test orders without `_stripe_customer_id`
 * would otherwise be enough to fail a resolution that an older SEPA order
 * answers correctly.
 *
 * @param int $user_id WordPress user.
 * @return string Customer id, or ''.
 */
function dahu_deferred_sepa_resolve_customer_id( $user_id ) {
	foreach ( array( '_stripe_customer_id', 'stripe_customer_id', 'wp_stripe_customer_id' ) as $meta_key ) {
		$value = get_user_meta( $user_id, $meta_key, true );

		if ( $value && 0 === strpos( (string) $value, 'cus_' ) ) {
			return (string) $value;
		}
	}

	$orders = wc_get_orders(
		array(
			'customer_id'  => $user_id,
			'limit'        => 1,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'meta_key'     => '_stripe_customer_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- wc_get_orders' own argument, indexed by WooCommerce.
			'meta_compare' => 'EXISTS',
		)
	);

	foreach ( $orders as $order ) {
		$value = $order->get_meta( '_stripe_customer_id' );

		if ( $value && 0 === strpos( (string) $value, 'cus_' ) ) {
			return (string) $value;
		}
	}

	return '';
}
