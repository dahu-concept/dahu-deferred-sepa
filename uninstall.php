<?php
/**
 * Uninstall routine.
 *
 * The gateway's settings go. Nothing on an order does.
 *
 * That asymmetry is deliberate, and it matters more here than in most plugins:
 * the order meta this plugin writes is the record of money taken from real
 * customers — when each charge was due, when it was sent, what Stripe answered,
 * and the idempotency key that guarantees it was taken once. Deleting that on
 * uninstall would destroy a payment record the shop may be legally required to
 * keep, and an uninstall is very often just a step in reinstalling.
 *
 * The scheduled charges are cancelled, because leaving actions queued for a
 * plugin that is gone means a hook nothing answers.
 *
 * A shop that genuinely wants the order meta gone sets
 * `dahu_deferred_sepa_remove_data_on_uninstall` to a truthy value first.
 *
 * @package DahuDeferredSepa
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans one site.
 *
 * @return void
 */
function dahu_deferred_sepa_uninstall_site() {
	global $wpdb;

	$wipe = (bool) get_option( 'dahu_deferred_sepa_remove_data_on_uninstall', false );

	delete_option( 'woocommerce_dahu_deferred_sepa_settings' );
	delete_option( 'dahu_deferred_sepa_remove_data_on_uninstall' );

	// Any charge still queued. Both schedulers, since either may have taken it.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'dahu_deferred_sepa_confirm_payment', array(), 'dahu-deferred-sepa' );
	}

	wp_clear_scheduled_hook( 'dahu_deferred_sepa_confirm_payment' );

	/*
	 * Order meta is not touched, even when $wipe is set. There is no safe way
	 * to remove a payment record on the way out, and $wipe is about this
	 * plugin's own configuration, not about the shop's accounts.
	 *
	 * User meta is not touched HERE either, because `$wpdb->usermeta` is a
	 * NETWORK-GLOBAL table: deleting from it inside a per-site loop would wipe
	 * every mandate approval on every site of a network because one site
	 * uninstalled the plugin, and would repeat the work once per site. It is
	 * handled once, after the loop, by dahu_deferred_sepa_uninstall_users().
	 */
	unset( $wipe );
}

/**
 * Cleans the user meta. Once per installation, not once per site.
 *
 * @param bool $wipe Whether the approvals go too.
 * @return void
 */
function dahu_deferred_sepa_uninstall_users( $wipe ) {
	global $wpdb;

	// The cached Stripe customer lookup is derived data: always safe to drop.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall routine; no caching layer is available here.
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_dahu_deferred_sepa_customer_for_pm' ) );

	if ( ! $wipe ) {
		return;
	}

	foreach ( array(
		'_dahu_deferred_sepa_delay_days',
		'_dahu_deferred_sepa_active_token_id',
		'_dahu_deferred_sepa_known_default_token',
	) as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
	}
}

$wipe_users = (bool) get_option( 'dahu_deferred_sepa_remove_data_on_uninstall', false );

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields'        => 'ids',
			'number'        => 0,
			'no_found_rows' => true,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );

		// Read per site: only a site that asked for it gets its own options
		// removed, and any one site asking is enough for the shared user meta.
		if ( get_option( 'dahu_deferred_sepa_remove_data_on_uninstall', false ) ) {
			$wipe_users = true;
		}

		dahu_deferred_sepa_uninstall_site();
		restore_current_blog();
	}
} else {
	dahu_deferred_sepa_uninstall_site();
}

dahu_deferred_sepa_uninstall_users( $wipe_users );
