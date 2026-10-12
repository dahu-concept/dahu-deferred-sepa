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
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether to render a diagnostics panel for the current user.
 *
 * @return bool
 */
function dahu_deferred_sepa_may_see_diagnostics() {
	return dahu_deferred_sepa_diagnostics_enabled() && current_user_can( 'manage_woocommerce' );
}

/**
 * One row of a diagnostics panel.
 *
 * @param string      $label  What is being checked.
 * @param bool|null   $ok     True, false, or null for a plain value.
 * @param string      $detail Value or explanation.
 * @return string HTML.
 */
function dahu_deferred_sepa_diag_row( $label, $ok, $detail = '' ) {
	if ( null === $ok ) {
		$verdict = '';
	} else {
		$verdict = $ok
			? '<span class="dahu-sepa-diag__yes">' . esc_html__( 'yes', 'dahu-deferred-sepa' ) . '</span>'
			: '<span class="dahu-sepa-diag__no">' . esc_html__( 'no', 'dahu-deferred-sepa' ) . '</span>';
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
function dahu_deferred_sepa_diag_panel( $title, $rows ) {
	echo '<div class="dahu-sepa-diag">';
	echo '<p class="dahu-sepa-diag__title">' . esc_html( $title ) . '</p>';
	echo '<dl class="dahu-sepa-diag__list">' . $rows . '</dl>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are escaped as they are built.
	echo '</div>';
}

/**
 * The profile panel: every availability condition, for this customer.
 *
 * @param WP_User $user User being edited.
 * @return void
 */
function dahu_deferred_sepa_render_user_diagnostics( $user ) {
	if ( ! dahu_deferred_sepa_may_see_diagnostics() ) {
		return;
	}

	$rows = dahu_deferred_sepa_diag_row(
		__( 'Gateway enabled', 'dahu-deferred-sepa' ),
		'yes' === dahu_deferred_sepa_setting( 'enabled', 'no' )
	);

	$rows .= dahu_deferred_sepa_diag_row(
		__( 'Role allowed', 'dahu-deferred-sepa' ),
		! array_intersect( dahu_deferred_sepa_excluded_roles(), (array) $user->roles ),
		implode( ', ', (array) $user->roles )
	);

	$active_id = (int) get_user_meta( $user->ID, DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META, true );

	$rows .= dahu_deferred_sepa_diag_row( __( 'A mandate is named active', 'dahu-deferred-sepa' ), (bool) $active_id );

	if ( $active_id && class_exists( 'WC_Payment_Tokens' ) ) {
		$token = WC_Payment_Tokens::get( $active_id );

		$rows .= dahu_deferred_sepa_diag_row( __( 'That mandate still exists', 'dahu-deferred-sepa' ), (bool) $token );

		if ( $token ) {
			$rows .= dahu_deferred_sepa_diag_row(
				__( 'It belongs to this customer', 'dahu-deferred-sepa' ),
				(int) $token->get_user_id() === (int) $user->ID
			);
			$rows .= dahu_deferred_sepa_diag_row(
				__( 'The customer has it as their own default', 'dahu-deferred-sepa' ),
				$token->is_default()
			);
			$rows .= dahu_deferred_sepa_diag_row(
				__( 'It is a Stripe payment method', 'dahu-deferred-sepa' ),
				0 === strpos( (string) $token->get_token(), 'pm_' ),
				(string) $token->get_token()
			);
		}
	}

	$rows .= dahu_deferred_sepa_diag_row(
		__( 'A Stripe secret key is configured', 'dahu-deferred-sepa' ),
		(bool) dahu_deferred_sepa_get_secret_key(),
		dahu_deferred_sepa_get_secret_key()
			? ( dahu_deferred_sepa_is_test_mode()
				? __( 'test mode', 'dahu-deferred-sepa' )
				: __( 'live mode', 'dahu-deferred-sepa' ) )
			: ''
	);

	$mandate = dahu_deferred_sepa_find_saved_mandate( $user->ID );

	$rows .= dahu_deferred_sepa_diag_row(
		__( 'Stripe customer resolved', 'dahu-deferred-sepa' ),
		$mandate && ! empty( $mandate['customer'] ),
		$mandate ? (string) $mandate['customer'] : ''
	);

	$rows .= dahu_deferred_sepa_diag_row(
		__( 'Result: the gateway is offered to this customer', 'dahu-deferred-sepa' ),
		(bool) $mandate
	);

	dahu_deferred_sepa_diag_panel( __( 'Availability for this customer', 'dahu-deferred-sepa' ), $rows );
}

/**
 * The order panel: the Stripe identifiers a charge depends on.
 *
 * @param WC_Order $order Order.
 * @return void
 */
function dahu_deferred_sepa_render_order_diagnostics( WC_Order $order ) {
	if ( ! dahu_deferred_sepa_may_see_diagnostics() ) {
		return;
	}

	$rows = dahu_deferred_sepa_diag_row( __( 'Payment method', 'dahu-deferred-sepa' ), null, $order->get_payment_method() );
	$rows .= dahu_deferred_sepa_diag_row( __( 'Currency', 'dahu-deferred-sepa' ), null, $order->get_currency() );

	foreach ( array( '_stripe_intent_id', '_stripe_customer_id', '_stripe_source_id', '_stripe_charge_id' ) as $key ) {
		$value = (string) $order->get_meta( $key );

		$rows .= dahu_deferred_sepa_diag_row( $key, null, '' !== $value ? $value : '—' );
	}

	$count = (int) $order->get_meta( '_dahu_deferred_sepa_reschedule_count' );

	if ( $count ) {
		$rows .= dahu_deferred_sepa_diag_row( __( 'Times the charge was moved', 'dahu-deferred-sepa' ), null, (string) $count );
	}

	dahu_deferred_sepa_diag_panel( __( 'Stripe identifiers on this order', 'dahu-deferred-sepa' ), $rows );
}

add_action( 'woocommerce_before_checkout_form', 'dahu_deferred_sepa_render_checkout_diagnostics' );

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
function dahu_deferred_sepa_render_checkout_diagnostics() {
	if ( ! dahu_deferred_sepa_may_see_diagnostics() ) {
		return;
	}

	wp_enqueue_style(
		'dahu-deferred-sepa-admin',
		DAHU_DEFERRED_SEPA_URL . 'assets/css/admin.css',
		array(),
		DAHU_DEFERRED_SEPA_VERSION
	);

	$user_id = get_current_user_id();
	$mandate = $user_id ? dahu_deferred_sepa_find_saved_mandate( $user_id ) : false;

	$rows = dahu_deferred_sepa_diag_row( __( 'Signed in as', 'dahu-deferred-sepa' ), null, (string) $user_id );
	$rows .= dahu_deferred_sepa_diag_row( __( 'Allowed to use deferred payment', 'dahu-deferred-sepa' ), dahu_deferred_sepa_is_authorized( $user_id ) );
	$rows .= dahu_deferred_sepa_diag_row( __( 'Gateway enabled', 'dahu-deferred-sepa' ), 'yes' === dahu_deferred_sepa_setting( 'enabled', 'no' ) );
	$rows .= dahu_deferred_sepa_diag_row(
		__( 'Mandate found', 'dahu-deferred-sepa' ),
		(bool) $mandate,
		$mandate ? (string) $mandate['payment_method'] : ''
	);

	global $wp_filter;

	$hooked = array();

	if ( isset( $wp_filter['woocommerce_available_payment_gateways'] ) ) {
		foreach ( $wp_filter['woocommerce_available_payment_gateways']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$hooked[] = '[' . $priority . '] ' . dahu_deferred_sepa_describe_callback( $callback['function'] );
			}
		}
	}

	if ( $hooked ) {
		foreach ( $hooked as $index => $line ) {
			$rows .= dahu_deferred_sepa_diag_row(
				0 === $index ? __( 'Filtering the gateway list', 'dahu-deferred-sepa' ) : ' ',
				null,
				$line
			);
		}
	} else {
		$rows .= dahu_deferred_sepa_diag_row( __( 'Filtering the gateway list', 'dahu-deferred-sepa' ), null, __( 'nothing', 'dahu-deferred-sepa' ) );
	}

	dahu_deferred_sepa_diag_panel( __( 'Deferred SEPA — why this checkout shows what it shows', 'dahu-deferred-sepa' ), $rows );
}

/**
 * Describes a WordPress callback: its name, and the file and line it is defined
 * at.
 *
 * @param callable $callback Callback.
 * @return string
 */
function dahu_deferred_sepa_describe_callback( $callback ) {
	try {
		if ( is_string( $callback ) && function_exists( $callback ) ) {
			$reflection = new ReflectionFunction( $callback );
			$name       = $callback . '()';
		} elseif ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			$name       = __( '(anonymous function)', 'dahu-deferred-sepa' );
		} elseif ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$reflection = new ReflectionMethod( $class, $callback[1] );
			$name       = $class . '::' . $callback[1] . '()';
		} else {
			return __( 'callback could not be identified', 'dahu-deferred-sepa' );
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
		return __( 'callback could not be inspected', 'dahu-deferred-sepa' );
	}
}
