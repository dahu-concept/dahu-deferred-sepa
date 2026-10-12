<?php
/**
 * Keeping Stripe's own, immediate SEPA option out of the way.
 *
 * The "SEPA Direct Debit" checkbox in the official Stripe plugin's settings
 * drives two places at once: the checkout, and My account → Payment methods.
 * A shop using this plugin needs it on in the second place — that is where the
 * customer signs the mandate — while not offering it at checkout as an
 * immediate-charge alternative sitting next to the deferred one.
 *
 * There is no setting in that plugin for the difference, so this file takes it
 * out of the checkout and leaves the account page alone. All of it is behind
 * the "Hide it at checkout" setting, off by default: a shop that wants both
 * options offered should get both.
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the shop asked for Stripe's own SEPA option to be hidden.
 *
 * @return bool
 */
function dahu_deferred_sepa_hides_native() {
	return 'yes' === dahu_deferred_sepa_setting( 'hide_native', 'no' );
}

/**
 * Whether this request is the checkout, and not the add-payment-method page.
 *
 * The account page has to keep every way of saving a mandate, so it is excluded
 * from all of the hiding below.
 *
 * @return bool
 */
function dahu_deferred_sepa_is_checkout_only() {
	if ( is_admin() ) {
		return false;
	}

	if ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() ) {
		return false;
	}

	return function_exists( 'is_checkout' ) && is_checkout();
}

add_filter( 'woocommerce_available_payment_gateways', 'dahu_deferred_sepa_hide_native_sepa_gateway' );

/**
 * Removes Stripe's SEPA gateways from the checkout.
 *
 * With Stripe's "Optimized Checkout" off, its SEPA option is a WooCommerce
 * gateway in its own right, so WooCommerce's documented filter is enough and
 * nothing about Stripe's internals is assumed.
 *
 * @param array<string,WC_Payment_Gateway> $gateways Available gateways.
 * @return array<string,WC_Payment_Gateway>
 */
function dahu_deferred_sepa_hide_native_sepa_gateway( $gateways ) {
	if ( ! dahu_deferred_sepa_hides_native() || ! dahu_deferred_sepa_is_checkout_only() ) {
		return $gateways;
	}

	foreach ( DAHU_DEFERRED_SEPA_NATIVE_GATEWAYS as $native_id ) {
		unset( $gateways[ $native_id ] );
	}

	return $gateways;
}

add_filter( 'wc_stripe_upe_params', 'dahu_deferred_sepa_hide_sepa_from_upe_params' );

/**
 * Removes SEPA from the parameters Stripe's payment element is built from.
 *
 * With "Optimized Checkout" on, Stripe's SEPA option is not a WooCommerce
 * gateway and the filter above never sees it. This one is the official plugin's
 * own filter, applied in class-wc-stripe-upe-payment-gateway.php's
 * javascript_params() just before the parameters reach the browser, so it is a
 * real extension point rather than a guess — but it is still another plugin's
 * filter, and if it ever goes away this returns the parameters untouched and
 * the only consequence is that both options show.
 *
 * @param array $params Parameters passed to Stripe's JavaScript.
 * @return array
 */
function dahu_deferred_sepa_hide_sepa_from_upe_params( $params ) {
	if ( ! is_array( $params ) || ! dahu_deferred_sepa_hides_native() || ! dahu_deferred_sepa_is_checkout_only() ) {
		return $params;
	}

	if ( empty( $params['paymentMethodsConfig'] ) || ! is_array( $params['paymentMethodsConfig'] ) ) {
		return $params;
	}

	// Classic mode: one key per payment method.
	unset( $params['paymentMethodsConfig']['sepa_debit'] );

	// Optimized Checkout: everything is merged under a single key, and the
	// sub-list is what actually decides what shows.
	foreach ( $params['paymentMethodsConfig'] as $method_id => $config ) {
		if ( isset( $config['enabledPaymentMethods'] ) && is_array( $config['enabledPaymentMethods'] ) ) {
			$params['paymentMethodsConfig'][ $method_id ]['enabledPaymentMethods'] = array_values(
				array_diff( $config['enabledPaymentMethods'], array( 'sepa_debit' ) )
			);
		}
	}

	return $params;
}

/* ------------------------------------------------------------------
 * The admin order screen's payment-method select
 * ------------------------------------------------------------------ */

add_action( 'admin_enqueue_scripts', 'dahu_deferred_sepa_enqueue_payment_method_script' );

/**
 * Loads the script that takes Stripe's SEPA option out of the order screen's
 * payment-method select.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function dahu_deferred_sepa_enqueue_payment_method_script( $hook ) {
	unset( $hook );

	if ( ! dahu_deferred_sepa_hides_native() ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'shop_order' ), true ) ) {
		return;
	}

	wp_enqueue_script(
		'dahu-deferred-sepa-payment-method',
		DAHU_DEFERRED_SEPA_URL . 'assets/js/admin-payment-method.js',
		array( 'jquery' ),
		DAHU_DEFERRED_SEPA_VERSION,
		true
	);

	wp_localize_script(
		'dahu-deferred-sepa-payment-method',
		'dahuDeferredSepaPaymentMethod',
		array( 'nativeIds' => array_values( DAHU_DEFERRED_SEPA_NATIVE_GATEWAYS ) )
	);
}

// Priority 5, ahead of WC_Meta_Box_Order_Data::save() at 40, which is what
// reads $_POST['_payment_method'].
add_action( 'woocommerce_process_shop_order_meta', 'dahu_deferred_sepa_block_native_sepa_selection', 5, 2 );

/**
 * Refuses a switch to Stripe's immediate SEPA gateway on an order.
 *
 * The server half of the pair: the script above only hides the option, and this
 * is what holds whether the script ran, was blocked, or was worked around. An
 * order already on that gateway keeps it — this refuses a change, not a state.
 *
 * @param int           $order_id Order id.
 * @param WC_Order|null $order    Order, when WooCommerce passed one.
 * @return void
 */
function dahu_deferred_sepa_block_native_sepa_selection( $order_id, $order = null ) {
	if ( ! dahu_deferred_sepa_hides_native() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the order screen's own nonce is checked by WooCommerce before this fires.
	if ( empty( $_POST['_payment_method'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
	$requested = wc_clean( wp_unslash( $_POST['_payment_method'] ) );

	if ( ! in_array( $requested, DAHU_DEFERRED_SEPA_NATIVE_GATEWAYS, true ) ) {
		return;
	}

	$order   = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	$current = $order ? $order->get_payment_method() : '';

	if ( $requested === $current ) {
		return;
	}

	$_POST['_payment_method'] = $current;

	if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
		WC_Admin_Meta_Boxes::add_error(
			__( 'Stripe\'s own SEPA Direct Debit charges immediately and cannot be set on an order here. Use Deferred SEPA Direct Debit instead. The payment method was left as it was.', 'dahu-deferred-sepa' )
		);
	}
}
