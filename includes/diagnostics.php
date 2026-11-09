<?php
/**
 * Answering "why is the gateway not showing?".
 *
 * This gateway appears only when several conditions hold at once: it is
 * enabled, the customer's role is not excluded, an administrator has named one
 * of their IBANs as the active mandate, the customer has that same IBAN as
 * their own default, and Stripe can tell us which customer owns it. Five
 * conditions, any one of which silently removes the option from the checkout.
 *
 * Rather than leave that to be guessed from the outside, the plugin can show
 * each condition and its answer. Everything here is read-only and visible only
 * to a shop manager, behind the "Diagnostics" setting.
 *
 * @package UbacSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether to render a diagnostics panel for the current user.
 *
 * @return bool
 */
function ubac_sepa_may_see_diagnostics() {
	return ubac_sepa_diagnostics_enabled() && current_user_can( 'manage_woocommerce' );
}

/**
 * One row of a diagnostics panel.
 *
 * @param string      $label  What is being checked.
 * @param bool|null   $ok     True, false, or null for a plain value.
 * @param string      $detail Value or explanation.
 * @return string HTML.
 */
function ubac_sepa_diag_row( $label, $ok, $detail = '' ) {
	if ( null === $ok ) {
		$verdict = '';
	} else {
		$verdict = $ok
			? '<span class="ubac-sepa-diag__yes">' . esc_html__( 'yes', 'dahuconcept-deferred-sepa' ) . '</span>'
			: '<span class="ubac-sepa-diag__no">' . esc_html__( 'no', 'dahuconcept-deferred-sepa' ) . '</span>';
	}

	if ( '' !== $detail ) {
		$detail = ( '' !== $verdict ? ' — ' : '' ) . '<code>' . esc_html( $detail ) . '</code>';
	}

	return '<dt>' . esc_html( $label ) . '</dt><dd>' . $verdict . $detail . '</dd>';
}

/**
 * Wraps rows in a panel.
 *
 * @param string $title Panel title.
 * @param string $rows  Rows, already escaped.
 * @return void
 */
function ubac_sepa_diag_panel( $title, $rows ) {
	echo '<div class="ubac-sepa-diag">';
	echo '<p class="ubac-sepa-diag__title">' . esc_html( $title ) . '</p>';
	echo '<dl class="ubac-sepa-diag__list">' . $rows . '</dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are escaped as they are built.
	echo '</div>';
}

/**
 * The profile panel: every availability condition, for this customer.
 *
 * @param WP_User $user User being edited.
 * @return void
 */
function ubac_sepa_render_user_diagnostics( $user ) {
	if ( ! ubac_sepa_may_see_diagnostics() ) {
		return;
	}

	$rows = ubac_sepa_diag_row(
		__( 'Gateway enabled', 'dahuconcept-deferred-sepa' ),
		'yes' === ubac_sepa_setting( 'enabled', 'no' )
	);

	$rows .= ubac_sepa_diag_row(
		__( 'Role allowed', 'dahuconcept-deferred-sepa' ),
		! array_intersect( ubac_sepa_excluded_roles(), (array) $user->roles ),
		implode( ', ', (array) $user->roles )
	);

	$active_id = (int) get_user_meta( $user->ID, UBAC_SEPA_ACTIVE_TOKEN_META, true );

	$rows .= ubac_sepa_diag_row( __( 'A mandate is named active', 'dahuconcept-deferred-sepa' ), (bool) $active_id );

	if ( $active_id && class_exists( 'WC_Payment_Tokens' ) ) {
		$token = WC_Payment_Tokens::get( $active_id );

		$rows .= ubac_sepa_diag_row( __( 'That mandate still exists', 'dahuconcept-deferred-sepa' ), (bool) $token );

		if ( $token ) {
			$rows .= ubac_sepa_diag_row(
				__( 'It belongs to this customer', 'dahuconcept-deferred-sepa' ),
				(int) $token->get_user_id() === (int) $user->ID
			);
			$rows .= ubac_sepa_diag_row(
				__( 'The customer has it as their own default', 'dahuconcept-deferred-sepa' ),
				$token->is_default()
			);
			$rows .= ubac_sepa_diag_row(
				__( 'It is a Stripe payment method', 'dahuconcept-deferred-sepa' ),
				0 === strpos( (string) $token->get_token(), 'pm_' ),
				(string) $token->get_token()
			);
		}
	}

	$rows .= ubac_sepa_diag_row(
		__( 'A Stripe secret key is configured', 'dahuconcept-deferred-sepa' ),
		(bool) ubac_sepa_get_secret_key(),
		ubac_sepa_get_secret_key()
			? ( ubac_sepa_is_test_mode()
				? __( 'test mode', 'dahuconcept-deferred-sepa' )
				: __( 'live mode', 'dahuconcept-deferred-sepa' ) )
			: ''
	);

	$mandate = ubac_sepa_find_saved_mandate( $user->ID );

	$rows .= ubac_sepa_diag_row(
		__( 'Stripe customer resolved', 'dahuconcept-deferred-sepa' ),
		$mandate && ! empty( $mandate['customer'] ),
		$mandate ? (string) $mandate['customer'] : ''
	);

	$rows .= ubac_sepa_diag_row(
		__( 'Result: the gateway is offered to this customer', 'dahuconcept-deferred-sepa' ),
		(bool) $mandate
	);

	ubac_sepa_diag_panel( __( 'Availability for this customer', 'dahuconcept-deferred-sepa' ), $rows );
}

/**
 * The order panel: the Stripe identifiers a charge depends on.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function ubac_sepa_render_order_diagnostics( WC_Order $order ) {
	if ( ! ubac_sepa_may_see_diagnostics() ) {
		return;
	}

	$rows = ubac_sepa_diag_row( __( 'Payment method', 'dahuconcept-deferred-sepa' ), null, $order->get_payment_method() );
	$rows .= ubac_sepa_diag_row( __( 'Currency', 'dahuconcept-deferred-sepa' ), null, $order->get_currency() );

	foreach ( array( '_stripe_intent_id', '_stripe_customer_id', '_stripe_source_id', '_stripe_charge_id' ) as $key ) {
		$value = (string) $order->get_meta( $key );

		$rows .= ubac_sepa_diag_row( $key, null, '' !== $value ? $value : '—' );
	}

	$count = (int) $order->get_meta( '_ubac_sepa_reschedule_count' );

	if ( $count ) {
		$rows .= ubac_sepa_diag_row( __( 'Times the charge was moved', 'dahuconcept-deferred-sepa' ), null, (string) $count );
	}

	ubac_sepa_diag_panel( __( 'Stripe identifiers on this order', 'dahuconcept-deferred-sepa' ), $rows );
}

add_action( 'woocommerce_before_checkout_form', 'ubac_sepa_render_checkout_diagnostics' );

/**
 * The checkout panel, for an administrator looking at the checkout.
 *
 * It also lists everything hooked to `woocommerce_available_payment_gateways`
 * with the file and line it came from, because the commonest reason a gateway
 * is missing is another plugin or snippet removing it, and that is otherwise
 * invisible.
 *
 * @return void
 */
function ubac_sepa_render_checkout_diagnostics() {
	if ( ! ubac_sepa_may_see_diagnostics() ) {
		return;
	}

	// The family sheet first, and its own second: the plugin's own rules
	// are written to sit on top of the shared components, so the order is
	// a dependency and not a hope. One handle across the family -- the
	// file is byte-identical everywhere, so whichever plugin loads it
	// first serves all of them, and a shop with six of these installed
	// downloads one copy.
	wp_enqueue_style(
		'ubac-ui',
		UBAC_SEPA_URL . 'assets/css/ubac-ui.css',
		array(),
		UBAC_SEPA_VERSION
	);

	wp_enqueue_style(
		'ubac-sepa-admin',
		UBAC_SEPA_URL . 'assets/css/admin.css',
		array( 'ubac-ui' ),
		UBAC_SEPA_VERSION
	);

	$user_id = get_current_user_id();
	$mandate = $user_id ? ubac_sepa_find_saved_mandate( $user_id ) : false;

	$rows = ubac_sepa_diag_row( __( 'Signed in as', 'dahuconcept-deferred-sepa' ), null, (string) $user_id );
	$rows .= ubac_sepa_diag_row( __( 'Allowed to use deferred payment', 'dahuconcept-deferred-sepa' ), ubac_sepa_is_authorized( $user_id ) );
	$rows .= ubac_sepa_diag_row( __( 'Gateway enabled', 'dahuconcept-deferred-sepa' ), 'yes' === ubac_sepa_setting( 'enabled', 'no' ) );
	$rows .= ubac_sepa_diag_row(
		__( 'Mandate found', 'dahuconcept-deferred-sepa' ),
		(bool) $mandate,
		$mandate ? (string) $mandate['payment_method'] : ''
	);

	global $wp_filter;

	$hooked = array();

	if ( isset( $wp_filter['woocommerce_available_payment_gateways'] ) ) {
		foreach ( $wp_filter['woocommerce_available_payment_gateways']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$hooked[] = '[' . $priority . '] ' . ubac_sepa_describe_callback( $callback['function'] );
			}
		}
	}

	if ( $hooked ) {
		foreach ( $hooked as $index => $line ) {
			$rows .= ubac_sepa_diag_row(
				0 === $index ? __( 'Filtering the gateway list', 'dahuconcept-deferred-sepa' ) : ' ',
				null,
				$line
			);
		}
	} else {
		$rows .= ubac_sepa_diag_row( __( 'Filtering the gateway list', 'dahuconcept-deferred-sepa' ), null, __( 'nothing', 'dahuconcept-deferred-sepa' ) );
	}

	ubac_sepa_diag_panel( __( 'Deferred SEPA — why this checkout shows what it shows', 'dahuconcept-deferred-sepa' ), $rows );
}

/**
 * Describes a WordPress callback: its name, and the file and line it is defined
 * at.
 *
 * @param callable $callback Callback.
 * @return string
 */
function ubac_sepa_describe_callback( $callback ) {
	try {
		if ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflection = new ReflectionFunction( $callback );
			$name       = $callback . '()';
		} elseif ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			$name       = __( '(anonymous function)', 'dahuconcept-deferred-sepa' );
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$reflection = new ReflectionMethod( $class, $callback[1] );
			$name       = $class . '::' . $callback[1] . '()';
		} else {
			return __( 'callback could not be identified', 'dahuconcept-deferred-sepa' );
		}

		$file = (string) $reflection->getFileName();
		$pos  = strpos( $file, 'wp-content' );

		if ( false !== $pos ) {
			$file = substr( $file, $pos );
		}

		return $name . ' — ' . $file . ':' . $reflection->getStartLine();
		// Throwable, not Exception: this runs on the checkout page for every
		// administrator with diagnostics on, and a class whose autoloader
		// raises an Error would take the page down with it.
	} catch ( Throwable $e ) {
		return __( 'callback could not be inspected', 'dahuconcept-deferred-sepa' );
	}
}
