<?php
/**
 * Plugin Name: Dahu Deferred SEPA
 * Plugin URI:  https://dahu-concept.fr
 * Description: Charges a Stripe SEPA Direct Debit mandate a configurable number of days after an order is completed, instead of at checkout. The charge date can be viewed and moved from the order screen.
 * Version:     1.1.0
 * Author:      Hugo Vial-Jaime
 * Author URI:  mailto:contact@dahu-concept.fr
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: dahu-deferred-sepa
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to: 11.1
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DAHU_DEFERRED_SEPA_VERSION', '1.1.0' );
define( 'DAHU_DEFERRED_SEPA_FILE', __FILE__ );
define( 'DAHU_DEFERRED_SEPA_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_DEFERRED_SEPA_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_DEFERRED_SEPA_BASENAME', plugin_basename( __FILE__ ) );

/**
 * This plugin's own payment gateway: a checkout option that saves nothing to
 * charge now and everything to charge later.
 */
define( 'DAHU_DEFERRED_SEPA_GATEWAY_ID', 'dahu_deferred_sepa' );

/**
 * Gateways whose orders this plugin will schedule a charge for: its own, plus
 * the official Stripe plugin's SEPA ids, so an order taken before this plugin
 * was installed is still recognised.
 */
define(
	'DAHU_DEFERRED_SEPA_GATEWAYS',
	array( DAHU_DEFERRED_SEPA_GATEWAY_ID, 'stripe_sepa', 'stripe_sepa_debit' )
);

/**
 * The official Stripe plugin's own SEPA gateway ids — the ones that charge
 * immediately. Optionally hidden at checkout and on the order screen so they
 * cannot be picked as a competing, immediate alternative.
 */
define( 'DAHU_DEFERRED_SEPA_NATIVE_GATEWAYS', array( 'stripe_sepa_debit', 'stripe_sepa' ) );

/** Scheduled-action hook that performs the charge. */
define( 'DAHU_DEFERRED_SEPA_HOOK', 'dahu_deferred_sepa_confirm_payment' );

/** Action Scheduler group, so this plugin's actions are identifiable. */
define( 'DAHU_DEFERRED_SEPA_GROUP', 'dahu-deferred-sepa' );

/** Admin screen listing upcoming and past charges. */
define( 'DAHU_DEFERRED_SEPA_CHARGES_PAGE', 'dahu-deferred-sepa-charges' );

/* ------------------------------------------------------------------
 * User meta
 * ------------------------------------------------------------------ */

/** Per-customer charge delay in days. Empty means "use the global setting". */
define( 'DAHU_DEFERRED_SEPA_DELAY_META', '_dahu_deferred_sepa_delay_days' );

/**
 * Which saved mandate an administrator has made the active one for this
 * customer. A customer may have several IBANs saved at Stripe; only the one
 * named here is used for a deferred charge.
 */
define( 'DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META', '_dahu_deferred_sepa_active_token_id' );

/**
 * The customer's default SEPA token as this plugin last saw it. Making an IBAN
 * the default is what counts as submitting it for approval, so the comparison
 * is done on each visit to the payment-methods page rather than by hooking an
 * event whose name varies between WooCommerce versions.
 */
define( 'DAHU_DEFERRED_SEPA_KNOWN_DEFAULT_META', '_dahu_deferred_sepa_known_default_token' );

/* ------------------------------------------------------------------
 * Overridable from wp-config.php
 * ------------------------------------------------------------------ */

/**
 * Last-resort charge delay, in days. The delay actually applied is resolved by
 * dahu_deferred_sepa_get_delay_days(): the customer's own delay first, then the
 * gateway setting, then this.
 */
if ( ! defined( 'DAHU_DEFERRED_SEPA_DELAY_DAYS' ) ) {
	define( 'DAHU_DEFERRED_SEPA_DELAY_DAYS', 8 );
}

/**
 * Signing secret of this plugin's own Stripe webhook endpoint. Define it in
 * wp-config.php, never in a plugin file:
 *
 *     define( 'DAHU_DEFERRED_SEPA_WEBHOOK_SECRET', 'whsec_...' );
 *
 * Test mode and live mode use different endpoints with different secrets. While
 * this is empty the webhook rejects everything, which is the safe default: a
 * forged notification must never be able to mark an order paid.
 */
if ( ! defined( 'DAHU_DEFERRED_SEPA_WEBHOOK_SECRET' ) ) {
	define( 'DAHU_DEFERRED_SEPA_WEBHOOK_SECRET', '' );
}

/**
 * Diagnostics override. Normally the gateway setting decides; this constant
 * forces them on for a site where the settings screen is not reachable.
 */
if ( ! defined( 'DAHU_DEFERRED_SEPA_DEBUG' ) ) {
	define( 'DAHU_DEFERRED_SEPA_DEBUG', false );
}

/* ------------------------------------------------------------------
 * Settings read from one place
 * ------------------------------------------------------------------ */

/**
 * This gateway's stored settings.
 *
 * @return array<string,mixed>
 */
function dahu_deferred_sepa_settings() {
	$settings = get_option( 'woocommerce_' . DAHU_DEFERRED_SEPA_GATEWAY_ID . '_settings', array() );

	return is_array( $settings ) ? $settings : array();
}

/**
 * One gateway setting, with a fallback.
 *
 * @param string $key      Setting key.
 * @param mixed  $fallback Value to return when the setting is absent.
 * @return mixed
 */
function dahu_deferred_sepa_setting( $key, $fallback = '' ) {
	$settings = dahu_deferred_sepa_settings();

	return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $fallback;
}

/**
 * Charge delay in days, in order of priority: the customer's own delay (a B2B
 * customer may have negotiated different terms), then the gateway setting, then
 * the constant.
 *
 * @param int $user_id Customer, or 0 for the global value.
 * @return int
 */
function dahu_deferred_sepa_get_delay_days( $user_id = 0 ) {
	// A delay of zero is a real answer — "charge as soon as it ships" — so each
	// source is tested for being SET, not for being non-zero. Treating 0 as
	// absent would tell the customer one date at checkout and debit them on
	// another.
	$days = null;

	if ( $user_id ) {
		$custom = get_user_meta( $user_id, DAHU_DEFERRED_SEPA_DELAY_META, true );

		if ( '' !== $custom && is_numeric( $custom ) && (int) $custom >= 0 ) {
			$days = (int) $custom;
		}
	}

	if ( null === $days ) {
		$settings = dahu_deferred_sepa_settings();

		if ( isset( $settings['delay_days'] ) && '' !== $settings['delay_days'] && is_numeric( $settings['delay_days'] ) ) {
			$days = (int) $settings['delay_days'];
		}
	}

	if ( null === $days ) {
		$days = (int) DAHU_DEFERRED_SEPA_DELAY_DAYS;
	}

	/**
	 * Filters the charge delay in days.
	 *
	 * @param int $days    Resolved delay.
	 * @param int $user_id Customer the delay was resolved for, or 0.
	 */
	return max( 0, (int) apply_filters( 'dahu_deferred_sepa_delay_days', $days, $user_id ) );
}

/**
 * Roles that must never be offered the deferred gateway, whatever is set on the
 * individual account. Empty by default: a shop that wants to keep late payment
 * for professionals declares its own customer role here.
 *
 * @return string[]
 */
function dahu_deferred_sepa_excluded_roles() {
	/**
	 * Filters the roles barred from the deferred gateway.
	 *
	 * @param string[] $roles Role slugs.
	 */
	return (array) apply_filters( 'dahu_deferred_sepa_excluded_roles', array() );
}

/**
 * Whether the availability diagnostics are shown to administrators.
 *
 * Why this exists at all: the gateway appears only for a customer who has an
 * approved mandate set as their default payment method, which means the usual
 * support question is "why is it not showing?". Answering that from the outside
 * is guesswork, so the plugin can answer it itself.
 *
 * @return bool
 */
function dahu_deferred_sepa_diagnostics_enabled() {
	if ( DAHU_DEFERRED_SEPA_DEBUG ) {
		return true;
	}

	return 'yes' === dahu_deferred_sepa_setting( 'diagnostics', 'no' );
}

/**
 * Address notified when a customer submits or changes a SEPA mandate. The
 * gateway setting first, then the site admin address.
 *
 * @return string
 */
function dahu_deferred_sepa_notify_email() {
	$email = (string) dahu_deferred_sepa_setting( 'notify_email', '' );

	return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
}

/**
 * Address shown to customers as the contact for a mandate change.
 *
 * @return string
 */
function dahu_deferred_sepa_contact_email() {
	$email = (string) dahu_deferred_sepa_setting( 'contact_email', '' );

	return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
}

/* ------------------------------------------------------------------
 * Bootstrap
 * ------------------------------------------------------------------ */

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'dahu-deferred-sepa', false, dirname( DAHU_DEFERRED_SEPA_BASENAME ) . '/languages' );
	}
);

require_once DAHU_DEFERRED_SEPA_PATH . 'includes/stripe.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/schedule.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/offline.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/alerts.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/gateway.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/mandates.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/checkout.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/webhook.php';
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/diagnostics.php';

if ( is_admin() ) {
	require_once DAHU_DEFERRED_SEPA_PATH . 'includes/order-screen.php';
	require_once DAHU_DEFERRED_SEPA_PATH . 'includes/admin-charges.php';
}

/**
 * Declare compatibility with WooCommerce's custom order tables (HPOS).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				DAHU_DEFERRED_SEPA_FILE,
				true
			);
		}
	}
);

/**
 * Plugins screen: "Settings" before "Deactivate".
 */
add_filter(
	'plugin_action_links_' . DAHU_DEFERRED_SEPA_BASENAME,
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . DAHU_DEFERRED_SEPA_GATEWAY_ID );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'dahu-deferred-sepa' ) . '</a>'
		);

		return $links;
	}
);

/**
 * Plugins screen: version and author, keeping WordPress's own "Version x.y.z"
 * string so it stays translated.
 */
add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {
		if ( DAHU_DEFERRED_SEPA_BASENAME !== $file ) {
			return $links;
		}

		return array(
			/* translators: %s: plugin version. */
			// Our own text domain, not WordPress's. Borrowing core's
			// "Version %s" meant the string came already translated in every
			// locale, which was the point — but it relies on a catalogue this
			// plugin does not control, and Plugin Check flags a call with no
			// domain, which is a submission blocker for two words we can
			// translate ourselves.
			sprintf( esc_html__( 'Version %s', 'dahu-deferred-sepa' ), esc_html( DAHU_DEFERRED_SEPA_VERSION ) ),
			'<a href="https://dahu-concept.fr" target="_blank" rel="noopener">' . esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'dahu-deferred-sepa' ) . '</a>',
		);
	},
	10,
	2
);

/**
 * Deactivation: drop any charge still scheduled, so turning the plugin off does
 * not leave actions that would fire with nothing to run them.
 */
register_deactivation_hook(
	DAHU_DEFERRED_SEPA_FILE,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( DAHU_DEFERRED_SEPA_HOOK, array(), DAHU_DEFERRED_SEPA_GROUP );
		}

		wp_clear_scheduled_hook( DAHU_DEFERRED_SEPA_HOOK );
	}
);

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_DEFERRED_SEPA_PATH . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-deferred-sepa' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-deferred-sepa',
				'name'       => __( 'Deferred SEPA', 'dahu-deferred-sepa' ),
				'tagline'    => __( 'Takes a SEPA payment a set number of days after the order ships.', 'dahu-deferred-sepa' ),
				'file'       => DAHU_DEFERRED_SEPA_FILE,
				'version'    => DAHU_DEFERRED_SEPA_VERSION,
				'textdomain' => 'dahu-deferred-sepa',
				/*
				 * Les mots de l'accueil viennent d'ici : le fichier partagé est
				 * recopié dans toute la famille et ne peut donc porter aucun
				 * domaine de traduction.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'dahu-deferred-sepa' ),
					'done'   => __( 'Done', 'dahu-deferred-sepa' ),
					'skip'   => __( 'Skip', 'dahu-deferred-sepa' ),
					'of'     => __( 'of', 'dahu-deferred-sepa' ),
					'denied' => __( 'You are not allowed to view this page.', 'dahu-deferred-sepa' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Charged once', 'dahu-deferred-sepa' ),
					'text'  => __( 'One key per order, so a retry or a timeout cannot debit twice.', 'dahu-deferred-sepa' ),
				),
				array(
					'title' => __( 'Nothing fails quietly', 'dahu-deferred-sepa' ),
					'text'  => __( 'A refused or returned charge is emailed to the shop.', 'dahu-deferred-sepa' ),
				),
				array(
					'title' => __( 'You keep the date', 'dahu-deferred-sepa' ),
					'text'  => __( 'Movable until it fires, and every move is recorded.', 'dahu-deferred-sepa' ),
				),
				),

				/*
				 * The one-time tour.
				 *
				 * Every selector points at the screen's shell, which is rendered
				 * on both tabs and whether or not a single charge exists. A step
				 * whose target is missing is skipped without a sound, so nothing
				 * here may sit inside the empty-list branch or the overdue block.
				 */
				'tour_screen' => 'woocommerce_page_dahu-deferred-sepa-charges',
				'tour'        => array(
					array(
						'selector' => '.dahu-ui-head__title',
						'title'    => __( 'Charged after shipping', 'dahu-deferred-sepa' ),
						'text'     => __( 'Each SEPA payment is taken a set number of days after the order ships.', 'dahu-deferred-sepa' ),
					),
					array(
						'selector' => '.nav-tab-wrapper a[href*="tab=upcoming"]',
						'title'    => __( 'What is due', 'dahu-deferred-sepa' ),
						'text'     => __( 'Charges still to come, with the date each one is sent.', 'dahu-deferred-sepa' ),
					),
					array(
						'selector' => '.nav-tab-wrapper a[href*="tab=history"]',
						'title'    => __( 'What was taken', 'dahu-deferred-sepa' ),
						'text'     => __( 'Every charge already sent, with its outcome. Export it as CSV.', 'dahu-deferred-sepa' ),
					),
				),
			)
		);
	}
);
