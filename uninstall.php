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
 * AND NO SETTING CHANGES THAT. This header used to say that a shop wanting the
 * order meta gone could set `ubac_sepa_remove_data_on_uninstall`
 * first, and that was false: the body of this file declines to touch order
 * meta *even when that option is set*, deliberately and for the reason above.
 * The option governs this plugin's own data and the mandate approvals it put
 * on user accounts, not the shop's payment records.
 *
 * The gap mattered more than a wrong sentence usually does. A shop acting on a
 * data-erasure request would have set the option, uninstalled, and believed
 * the records gone while they were still on every order. Erasing them is the
 * shop's own operation on its own accounts -- WooCommerce's personal-data
 * eraser, or SQL -- and this plugin does not register an eraser of its own.
 *
 * Measured 30/10/2026: the two statements had contradicted each other since
 * the option existed.
 *
 * @package UbacSepa
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Whether this site asked for its data to go.
 *
 * Read from the gateway's own settings array, where the box that sets it
 * lives, so there is one place to set it and one place to read it. Until
 * 06/11/2026 it was a standalone option named
 * `ubac_sepa_remove_data_on_uninstall` that NOTHING anywhere wrote: the branch
 * it guards could never run, and the promise was unreachable.
 *
 * Compared against 'yes' and never read as a boolean: WooCommerce stores the
 * STRING 'no' for an unticked checkbox, and 'no' is truthy in PHP, so casting
 * it would wipe the data of every shop that left the box alone.
 *
 * @return bool
 */
function ubac_sepa_uninstall_wanted() {
	$settings = get_option( 'woocommerce_ubac_sepa_settings', array() );

	if ( ! is_array( $settings ) || ! isset( $settings['delete_data_on_uninstall'] ) ) {
		return false;
	}

	return 'yes' === $settings['delete_data_on_uninstall'];
}

/**
 * Cleans one site.
 *
 * @return void
 */
function ubac_sepa_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-sepa' );
	// Les memes clefs sous le nouveau slug : elle est construite au runtime
	// a partir du slug, donc elle a change avec lui. Sans ces trois lignes,
	// une mise a jour depuis la version Ubac laisse des orphelines que plus
	// rien ne nomme.
	delete_option( 'ubac_welcome_seen_dahuconcept-deferred-sepa' );
	delete_option( 'ubac_welcome_pending_ubac-sepa' );
	delete_option( 'ubac_welcome_pending_dahuconcept-deferred-sepa' );
	delete_option( 'ubac_welcome_tour_ubac-sepa' );
	delete_option( 'ubac_welcome_tour_dahuconcept-deferred-sepa' );
	global $wpdb;

	// Read BEFORE the settings array is deleted just below.
	$wipe = ubac_sepa_uninstall_wanted();

	delete_option( 'woocommerce_ubac_sepa_settings' );

	// The old name goes too. Nothing in the code ever wrote it, but a shop that
	// followed the previous header and set it by hand would otherwise keep an
	// orphan row that no longer does anything.
	delete_option( 'ubac_sepa_remove_data_on_uninstall' );

	// When the overdue sweep last reported. Interface state of a watchdog that
	// is going away with the plugin, so it goes too — like the welcome flags,
	// whatever the shop chose to keep of its own data.
	delete_option( 'ubac_sepa_last_sweep' );

	/*
	 * The per-order charge claims.
	 *
	 * `ubac_sepa_claim()` takes one with add_option() and releases it
	 * with delete_option(), so in normal operation none is left. One survives
	 * exactly when a worker was killed in the middle of a charge — which is
	 * the case the claim exists for — and there is no API that deletes options
	 * by prefix. A LIKE on the name prefix is the only way, and it runs once,
	 * on uninstall.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API deletes options by prefix; uninstall only.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'ubac_sepa_claim_' ) . '%'
		)
	);

	// Any charge still queued. Both schedulers, since either may have taken it.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'ubac_sepa_confirm_payment', array(), 'ubac-sepa' );
	}

	wp_clear_scheduled_hook( 'ubac_sepa_confirm_payment' );

	/*
	 * And the daily sweep, which is a WP-Cron event of its own.
	 *
	 * `ubac_sepa_keep_sweep_scheduled()` puts it back on every admin
	 * load, so it is certainly there. Leaving it behind leaves WP-Cron waking
	 * up once a day for a hook nothing answers any more — the very thing the
	 * charge hook above is cleared to avoid.
	 */
	wp_clear_scheduled_hook( 'ubac_sepa_sweep' );

	/*
	 * Order meta is not touched, even when $wipe is set. There is no safe way
	 * to remove a payment record on the way out, and $wipe is about this
	 * plugin's own configuration, not about the shop's accounts.
	 *
	 * User meta is not touched HERE either, because `$wpdb->usermeta` is a
	 * NETWORK-GLOBAL table: deleting from it inside a per-site loop would wipe
	 * every mandate approval on every site of a network because one site
	 * uninstalled the plugin, and would repeat the work once per site. It is
	 * handled once, after the loop, by ubac_sepa_uninstall_users().
	 */
	unset( $wipe );
}

/**
 * Cleans the user meta. Once per installation, not once per site.
 *
 * @param bool $wipe Whether the approvals go too.
 * @return void
 */
function ubac_sepa_uninstall_users( $wipe ) {
	global $wpdb;

	// The cached Stripe customer lookup is derived data: always safe to drop.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall routine; no caching layer is available here.
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_ubac_sepa_customer_for_pm' ) );

	if ( ! $wipe ) {
		return;
	}

	foreach ( array(
		'_ubac_sepa_delay_days',
		'_ubac_sepa_active_token_id',
		'_ubac_sepa_known_default_token',
	) as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
	}
}

$ubac_sepa_wipe_users = ubac_sepa_uninstall_wanted();

if ( is_multisite() ) {
	$ubac_sepa_site_ids = get_sites(
		array(
			'fields'        => 'ids',
			'number'        => 0,
			'no_found_rows' => true,
		)
	);

	foreach ( $ubac_sepa_site_ids as $ubac_sepa_site_id ) {
		switch_to_blog( (int) $ubac_sepa_site_id );

		// Read per site: only a site that asked for it gets its own options
		// removed, and any one site asking is enough for the shared user meta.
		if ( ubac_sepa_uninstall_wanted() ) {
			$ubac_sepa_wipe_users = true;
		}

		ubac_sepa_uninstall_site();
		restore_current_blog();
	}
} else {
	ubac_sepa_uninstall_site();
}

ubac_sepa_uninstall_users( $ubac_sepa_wipe_users );
