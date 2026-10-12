<?php
/**
 * The checkout option.
 *
 * This gateway charges nothing. It finds the mandate the customer already has
 * at Stripe, writes it onto the order, and puts the order on hold. The money
 * moves later, from includes/schedule.php.
 *
 * It offers itself only to a customer who has an approved mandate, so most
 * shoppers never see it at all.
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'woocommerce_payment_gateways', 'dahu_deferred_sepa_register_gateway' );

/**
 * Registers the gateway with WooCommerce.
 *
 * @param string[] $methods Gateway class names.
 * @return string[]
 */
function dahu_deferred_sepa_register_gateway( $methods ) {
	$methods[] = 'Dahu_Deferred_SEPA_Gateway';

	return $methods;
}

add_action( 'plugins_loaded', 'dahu_deferred_sepa_load_gateway_class', 11 );

/**
 * Declares the gateway class, once WC_Payment_Gateway exists to extend.
 *
 * @return void
 */
function dahu_deferred_sepa_load_gateway_class() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'Dahu_Deferred_SEPA_Gateway' ) ) {
		return;
	}

	/**
	 * Deferred SEPA Direct Debit.
	 */
	class Dahu_Deferred_SEPA_Gateway extends WC_Payment_Gateway {

		/**
		 * Sets the gateway up.
		 */
		public function __construct() {
			$this->id                 = DAHU_DEFERRED_SEPA_GATEWAY_ID;
			$this->method_title       = __( 'Deferred SEPA Direct Debit', 'dahu-deferred-sepa' );
			$this->method_description = __(
				'Reuses the SEPA mandate the customer already has saved at Stripe. Nothing is charged at checkout: the charge is sent automatically a configurable number of days after the order is marked Completed. The delay is set below and can be overridden per customer on their user profile.',
				'dahu-deferred-sepa'
			);
			$this->has_fields         = false;

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title', __( 'SEPA Direct Debit (deferred)', 'dahu-deferred-sepa' ) );
			$this->description = $this->get_option( 'description' );
			$this->enabled     = $this->get_option( 'enabled', 'no' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Settings shown under WooCommerce → Settings → Payments.
		 *
		 * @return void
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'         => array(
					'title'   => __( 'Enable', 'dahu-deferred-sepa' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable deferred SEPA Direct Debit', 'dahu-deferred-sepa' ),
					'default' => 'no',
				),
				'title'           => array(
					'title'       => __( 'Title', 'dahu-deferred-sepa' ),
					'type'        => 'text',
					'description' => __( 'What the customer sees at checkout.', 'dahu-deferred-sepa' ),
					'default'     => __( 'SEPA Direct Debit (deferred)', 'dahu-deferred-sepa' ),
					'desc_tip'    => true,
				),
				'description'     => array(
					'title'       => __( 'Description', 'dahu-deferred-sepa' ),
					'type'        => 'textarea',
					/* translators: %s: the {days} placeholder, shown literally. */
					'description' => sprintf(
						__( 'What the customer sees under the title at checkout. %s is replaced by the delay that applies to this customer, plus two working days for the bank.', 'dahu-deferred-sepa' ),
						'<code>{days}</code>'
					),
					'default'     => __( 'Your saved SEPA mandate will be charged {days} days after your order ships.', 'dahu-deferred-sepa' ),
				),
				'delay_days'      => array(
					'title'             => __( 'Default charge delay (days)', 'dahu-deferred-sepa' ),
					'type'              => 'number',
					'description'       => __( 'Days between the order being marked Completed and the charge being sent. Can be overridden per customer, under Users → the customer → Deferred SEPA Direct Debit.', 'dahu-deferred-sepa' ),
					'default'           => DAHU_DEFERRED_SEPA_DELAY_DAYS,
					'custom_attributes' => array(
						'min'  => 0,
						'step' => 1,
					),
				),
				'hide_native'     => array(
					'title'       => __( 'Stripe\'s own SEPA option', 'dahu-deferred-sepa' ),
					'type'        => 'checkbox',
					'label'       => __( 'Hide it at checkout', 'dahu-deferred-sepa' ),
					'description' => __( 'The official Stripe plugin\'s SEPA Direct Debit charges immediately. Hiding it at checkout stops it being picked as a competing option, while leaving it available under My account → Payment methods, which is where the customer signs the mandate in the first place.', 'dahu-deferred-sepa' ),
					'default'     => 'no',
				),
				'notify_email'    => array(
					'title'       => __( 'Notify this address', 'dahu-deferred-sepa' ),
					'type'        => 'email',
					'description' => __( 'Notified when a customer submits or changes a SEPA mandate. Empty falls back to the site admin address.', 'dahu-deferred-sepa' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'contact_email'   => array(
					'title'       => __( 'Contact address for customers', 'dahu-deferred-sepa' ),
					'type'        => 'email',
					'description' => __( 'Shown to customers as the address to write to about a mandate change. Empty falls back to the site admin address.', 'dahu-deferred-sepa' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'keys_title'      => array(
					'title'       => __( 'Stripe keys', 'dahu-deferred-sepa' ),
					'type'        => 'title',
					'description' => __( 'Leave these empty to use the keys already configured in the official WooCommerce Stripe Gateway. Fill them in to charge with a key of your own — a restricted key that may only write PaymentIntents is enough, and means this plugin does not depend on another plugin\'s stored settings.', 'dahu-deferred-sepa' ),
				),
				'testmode'        => array(
					'title'   => __( 'Test mode', 'dahu-deferred-sepa' ),
					'type'    => 'checkbox',
					'label'   => __( 'Use the test key below', 'dahu-deferred-sepa' ),
					'default' => 'no',
				),
				'secret_key'      => array(
					'title'   => __( 'Live secret key', 'dahu-deferred-sepa' ),
					'type'    => 'password',
					'default' => '',
				),
				'test_secret_key' => array(
					'title'   => __( 'Test secret key', 'dahu-deferred-sepa' ),
					'type'    => 'password',
					'default' => '',
				),
				'diagnostics'     => array(
					'title'       => __( 'Diagnostics', 'dahu-deferred-sepa' ),
					'type'        => 'checkbox',
					'label'       => __( 'Show administrators why the gateway is or is not available', 'dahu-deferred-sepa' ),
					'description' => __( 'Adds a panel, visible only to administrators, at checkout and on the user profile, explaining each condition this gateway checks. Useful while setting the plugin up; turn it off afterwards.', 'dahu-deferred-sepa' ),
					'default'     => 'no',
				),
			);
		}

		/**
		 * Offered only to a signed-in customer who has an approved mandate.
		 *
		 * @return bool
		 */
		public function is_available() {
			// In the admin the gateway has to be listed whatever the current
			// user's own mandates are, so an order can be edited.
			if ( is_admin() ) {
				return parent::is_available();
			}

			if ( 'yes' !== $this->enabled ) {
				return false;
			}

			$user_id = get_current_user_id();

			if ( ! $user_id || ! dahu_deferred_sepa_is_authorized( $user_id ) ) {
				return false;
			}

			return (bool) dahu_deferred_sepa_find_saved_mandate( $user_id );
		}

		/**
		 * Takes the order without taking any money.
		 *
		 * @param int $order_id Order id.
		 * @return array
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			// Checked again here, server-side: the gateway being offered is a
			// display decision, and this is the one that matters.
			if ( ! $order || ! dahu_deferred_sepa_is_authorized( $order->get_user_id() ) ) {
				wc_add_notice( __( 'This payment method is not available for your account.', 'dahu-deferred-sepa' ), 'error' );

				return array( 'result' => 'failure' );
			}

			$currency_error = dahu_deferred_sepa_currency_error( $order );

			if ( $currency_error ) {
				wc_add_notice( __( 'This payment method is not available for this currency.', 'dahu-deferred-sepa' ), 'error' );

				return array( 'result' => 'failure' );
			}

			$mandate = dahu_deferred_sepa_find_saved_mandate( $order->get_user_id() );

			if ( ! $mandate ) {
				wc_add_notice(
					__( 'No approved SEPA mandate was found for your account. Please choose another payment method.', 'dahu-deferred-sepa' ),
					'error'
				);

				return array( 'result' => 'failure' );
			}

			$order->update_meta_data( '_stripe_customer_id', $mandate['customer'] );
			$order->update_meta_data( '_stripe_source_id', $mandate['payment_method'] );
			$order->set_payment_method( $this );

			// Mint the idempotency key NOW, not when the charge runs.
			//
			// If it were minted at charge time, two attempts that overlap —
			// Action Scheduler firing while someone clicks "Charge now", or a
			// worker killed mid-request whose action is re-claimed — would both
			// find no key, mint two different ones, and Stripe would charge
			// twice. Minting it here means the key exists before any attempt
			// can, so every attempt carries the same one.
			dahu_deferred_sepa_get_idempotency_key( $order );

			// On hold, not processing: the order is to be prepared and shipped,
			// but it is not paid, and saying otherwise would be a lie in every
			// report the shop looks at.
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: %d: delay in days. */
					__( 'Deferred SEPA: the customer\'s saved mandate will be used. Nothing charged at checkout — the charge is scheduled when the order is marked Completed, %d days out.', 'dahu-deferred-sepa' ),
					dahu_deferred_sepa_get_delay_days( $order->get_user_id() )
				)
			);
			$order->save();

			wc_reduce_stock_levels( $order_id );

			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}

/**
 * Whether a customer may use the deferred gateway.
 *
 * There is no separate approval checkbox: naming a mandate as the active one on
 * the customer's profile IS the approval. It also requires the customer to have
 * made that same IBAN their own default, so they cannot quietly move to an IBAN
 * the shop has not approved.
 *
 * @param int $user_id WordPress user.
 * @return bool
 */
function dahu_deferred_sepa_is_authorized( $user_id ) {
	if ( ! $user_id ) {
		return false;
	}

	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return false;
	}

	if ( array_intersect( dahu_deferred_sepa_excluded_roles(), (array) $user->roles ) ) {
		return false;
	}

	return (bool) dahu_deferred_sepa_find_saved_mandate( $user_id );
}

add_filter( 'woocommerce_gateway_description', 'dahu_deferred_sepa_gateway_description', 10, 2 );

/**
 * Replaces {days} in the checkout description with the delay that applies to
 * the customer looking at it, plus two working days for the bank.
 *
 * @param string $description Gateway description.
 * @param string $gateway_id  Gateway id.
 * @return string
 */
function dahu_deferred_sepa_gateway_description( $description, $gateway_id ) {
	if ( DAHU_DEFERRED_SEPA_GATEWAY_ID !== $gateway_id || is_admin() ) {
		return $description;
	}

	$days = dahu_deferred_sepa_get_delay_days( get_current_user_id() ) + 2;

	return str_replace( '{days}', (string) $days, $description );
}
