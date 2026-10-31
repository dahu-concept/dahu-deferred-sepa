<?php
/**
 * Which saved IBAN the shop will charge, and how it gets approved.
 *
 * The Stripe mandate is legally valid from the moment the customer signs it.
 * Whether the shop is willing to be paid late by that customer is a separate,
 * commercial decision, and this file is where it is recorded: an administrator
 * names one of the customer's saved IBANs as the active mandate.
 *
 * Approval needs both sides to agree. The shop names an IBAN, and the customer
 * has that same IBAN set as their own default. Either alone is not enough,
 * which is what stops a customer quietly moving to an IBAN nobody approved.
 *
 * @package DahuDeferredSepa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * The mandate that will actually be charged
 * ------------------------------------------------------------------ */

/**
 * The SEPA mandate to charge for a customer, or false.
 *
 * Both conditions have to hold:
 *
 * 1. An administrator named this token as the active mandate.
 * 2. The customer has that same token as their own default payment method.
 *
 * When they disagree, or nothing is named, no mandate is returned and the
 * gateway does not appear.
 *
 * @param int $user_id WordPress user.
 * @return array{customer:string,payment_method:string}|false
 */
function dahu_deferred_sepa_find_saved_mandate( $user_id ) {
	if ( ! $user_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return false;
	}

	$active_id = get_user_meta( $user_id, DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META, true );

	if ( ! $active_id ) {
		return false;
	}

	$token = WC_Payment_Tokens::get( (int) $active_id );

	// Gone, or now belonging to somebody else.
	if ( ! $token || (int) $token->get_user_id() !== (int) $user_id ) {
		return false;
	}

	if ( ! $token->is_default() ) {
		return false;
	}

	// Stripe card payment methods also carry the `pm_` prefix. The profile
	// radio only offers SEPA tokens, but the stored id is a plain integer that
	// any other code could set, and a card token here would advertise the
	// gateway and then fail at charge time, days after shipping.
	if ( ! dahu_deferred_sepa_token_is_sepa( $token ) ) {
		return false;
	}

	$pm_id = (string) $token->get_token();

	if ( 0 !== strpos( $pm_id, 'pm_' ) ) {
		return false;
	}

	// Stripe is the source of truth for which customer owns this payment
	// method, with a cache keyed by the payment method itself, so a new mandate
	// or a different Stripe account invalidates it rather than serving a stale
	// customer id from another environment.
	$customer = dahu_deferred_sepa_fetch_customer_from_stripe( $pm_id, $user_id );

	if ( ! $customer ) {
		// Fallback for when the API key is unavailable: local meta and orders.
		$customer = dahu_deferred_sepa_resolve_customer_id( $user_id );
	}

	if ( ! $customer ) {
		return false;
	}

	return array(
		'customer'       => $customer,
		'payment_method' => $pm_id,
	);
}

/**
 * The customer's current default SEPA token, or null.
 *
 * @param int $user_id WordPress user.
 * @return WC_Payment_Token|null
 */
function dahu_deferred_sepa_get_default_sepa_token( $user_id ) {
	if ( ! $user_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return null;
	}

	foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id ) as $token ) {
		if ( dahu_deferred_sepa_token_is_sepa( $token ) && $token->is_default() ) {
			return $token;
		}
	}

	return null;
}

/**
 * Every SEPA token a customer has saved.
 *
 * @param int $user_id WordPress user.
 * @return WC_Payment_Token[]
 */
function dahu_deferred_sepa_get_sepa_tokens( $user_id ) {
	if ( ! $user_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return array();
	}

	$tokens = array();

	foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id ) as $token ) {
		if ( dahu_deferred_sepa_token_is_sepa( $token ) ) {
			$tokens[] = $token;
		}
	}

	return $tokens;
}

/**
 * Whether a payment token is a SEPA mandate.
 *
 * The type string has not been spelled the same way across Stripe plugin
 * versions, so both the exact type and a case-insensitive match are accepted.
 *
 * @param WC_Payment_Token $token Token.
 * @return bool
 */
function dahu_deferred_sepa_token_is_sepa( $token ) {
	$type = (string) $token->get_type();

	return 'SEPA' === $type || false !== stripos( $type, 'sepa' );
}

/**
 * The last four digits of a token's IBAN, or a placeholder.
 *
 * @param WC_Payment_Token $token Token.
 * @return string
 */
function dahu_deferred_sepa_token_last4( $token ) {
	$last4 = method_exists( $token, 'get_last4' ) ? (string) $token->get_last4() : '';

	return '' !== $last4 ? $last4 : '••••';
}

/* ------------------------------------------------------------------
 * User profile: naming the active mandate
 * ------------------------------------------------------------------ */

add_action( 'show_user_profile', 'dahu_deferred_sepa_render_profile_section' );
add_action( 'edit_user_profile', 'dahu_deferred_sepa_render_profile_section' );

/**
 * The "Deferred SEPA Direct Debit" section of a user profile.
 *
 * @param WP_User $user User being edited.
 * @return void
 */
function dahu_deferred_sepa_render_profile_section( $user ) {
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
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

	$excluded = (bool) array_intersect( dahu_deferred_sepa_excluded_roles(), (array) $user->roles );

	// The notification email links straight to this anchor.
	echo '<h2 id="dahu-deferred-sepa">' . esc_html__( 'Deferred SEPA Direct Debit', 'dahu-deferred-sepa' ) . '</h2>';

	if ( $excluded ) {
		echo '<p class="description">'
			. esc_html__( 'This account has a role that is barred from deferred payment, so the gateway is never offered to it.', 'dahu-deferred-sepa' )
			. '</p>';

		return;
	}

	$tokens    = dahu_deferred_sepa_get_sepa_tokens( $user->ID );
	$active_id = (string) get_user_meta( $user->ID, DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META, true );
	$custom    = (string) get_user_meta( $user->ID, DAHU_DEFERRED_SEPA_DELAY_META, true );
	$global    = dahu_deferred_sepa_get_delay_days();
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Active mandate', 'dahu-deferred-sepa' ); ?></th>
			<td>
				<label class="dahu-sepa-mandates__option">
					<input type="radio" name="dahu_deferred_sepa_active_token" value="" <?php checked( '' === $active_id || '0' === $active_id ); ?>>
					<?php esc_html_e( 'None — this customer will not be offered deferred payment', 'dahu-deferred-sepa' ); ?>
				</label>

				<?php if ( empty( $tokens ) ) : ?>
					<p class="description"><?php esc_html_e( 'This customer has no IBAN saved at Stripe yet.', 'dahu-deferred-sepa' ); ?></p>
				<?php else : ?>
					<?php foreach ( $tokens as $token ) : ?>
						<label class="dahu-sepa-mandates__option">
							<input type="radio" name="dahu_deferred_sepa_active_token"
								value="<?php echo esc_attr( $token->get_id() ); ?>"
								<?php checked( $active_id === (string) $token->get_id() ); ?>>
							<?php
							printf(
								/* translators: %s: last four digits of an IBAN. */
								esc_html__( 'IBAN ending %s', 'dahu-deferred-sepa' ),
								esc_html( dahu_deferred_sepa_token_last4( $token ) )
							);
							?>
							<?php if ( $token->is_default() ) : ?>
								<span class="description"><?php esc_html_e( '(currently the customer\'s own default)', 'dahu-deferred-sepa' ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>

				<p class="description dahu-sepa-mandates__hint">
					<?php esc_html_e( 'Naming an IBAN here approves it for deferred payment. The customer must also have that same IBAN set as their default under My account → Payment methods before the gateway is offered to them — that is what stops them moving to an IBAN you have not approved.', 'dahu-deferred-sepa' ); ?>
				</p>

				<p class="dahu-sepa-mandates__delay">
					<label for="dahu_deferred_sepa_delay_days">
						<strong><?php esc_html_e( 'Charge delay for this customer, in days', 'dahu-deferred-sepa' ); ?></strong>
					</label>
					<input type="number" id="dahu_deferred_sepa_delay_days" name="dahu_deferred_sepa_delay_days"
						class="dahu-sepa-mandates__delay-input" min="0" step="1"
						value="<?php echo esc_attr( $custom ); ?>"
						placeholder="<?php echo esc_attr( $global ); ?>">
				</p>

				<p class="description dahu-sepa-mandates__hint">
					<?php
					printf(
						/* translators: %d: the global delay in days. */
						esc_html__( 'Leave empty to use the global delay, currently %d days. A value here applies to this customer only, and only to their future orders — charges already scheduled are not moved.', 'dahu-deferred-sepa' ),
						(int) $global
					);
					?>
				</p>

				<?php dahu_deferred_sepa_render_user_diagnostics( $user ); ?>
			</td>
		</tr>
	</table>
	<?php
}

add_action( 'personal_options_update', 'dahu_deferred_sepa_save_profile_section' );
add_action( 'edit_user_profile_update', 'dahu_deferred_sepa_save_profile_section' );

/**
 * Saves the active mandate and the per-customer delay.
 *
 * @param int $user_id User being saved.
 * @return void
 */
function dahu_deferred_sepa_save_profile_section( $user_id ) {
	// Per user, not the blanket capability: approving a mandate is approving
	// late payment for that person, and a role allowed to edit some users
	// should not be able to approve one on any user.
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	if ( ! isset( $_POST['_wpnonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id )
	) {
		return;
	}

	if ( isset( $_POST['dahu_deferred_sepa_active_token'] ) ) {
		$new = absint( wp_unslash( $_POST['dahu_deferred_sepa_active_token'] ) );
		$old = (int) get_user_meta( $user_id, DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META, true );

		update_user_meta( $user_id, DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META, $new );

		// Only on a newly approved IBAN: not when clearing it, and not when the
		// administrator saved the profile without changing the choice.
		if ( $new && $new !== $old ) {
			dahu_deferred_sepa_notify_iban_approved( $user_id, $new );
		}
	}

	if ( isset( $_POST['dahu_deferred_sepa_delay_days'] ) ) {
		$delay = sanitize_text_field( wp_unslash( $_POST['dahu_deferred_sepa_delay_days'] ) );

		/*
		 * EMPTY is what clears the override, and nothing else is.
		 *
		 * Zero is a real answer — "charge as soon as it ships" — and the
		 * field offers it: its `min` is 0, and
		 * dahu_deferred_sepa_get_delay_days() accepts any value from 0 up,
		 * testing each source for being SET rather than for being non-zero.
		 * Treating 0 as "clear" made the writer disagree with the reader: an
		 * administrator who set zero days for a customer had it silently
		 * thrown away, that customer fell back to the shop's global delay,
		 * and the only visible trace was a charge date days later than the
		 * one agreed. On the field that decides when money is taken, a
		 * silently discarded setting is the worst outcome of the three.
		 *
		 * A value that is not a number is treated as empty rather than as
		 * zero: reading "soon" as "charge today" would be inventing an
		 * instruction nobody gave. A negative one is clamped to zero, which
		 * is what the reader does with it anyway.
		 */
		if ( '' === $delay || ! is_numeric( $delay ) ) {
			delete_user_meta( $user_id, DAHU_DEFERRED_SEPA_DELAY_META );
		} else {
			update_user_meta( $user_id, DAHU_DEFERRED_SEPA_DELAY_META, max( 0, (int) $delay ) );
		}
	}
}

/* ------------------------------------------------------------------
 * Noticing that a customer submitted an IBAN
 * ------------------------------------------------------------------ */

/*
 * What counts as submitting an IBAN for approval is making it the default —
 * automatic for the first one saved, and the "Use as default" button after
 * that. Simply adding another IBAN submits nothing.
 *
 * It is detected by comparing the current default against the one last seen,
 * on every visit to the payment-methods page, because that is where WooCommerce
 * redirects after either action. The documented hooks are attached as well: if
 * they fire on the installed version the notification goes out at once, and if
 * they do not, the page visit catches it. The remembered token is what keeps
 * either path from notifying twice.
 */

add_action(
	'woocommerce_account_payment-methods_endpoint',
	static function () {
		dahu_deferred_sepa_check_default_iban( get_current_user_id() );
	},
	1
);

add_action(
	'woocommerce_payment_token_added',
	static function ( $token_id ) {
		if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
			return;
		}

		$token = WC_Payment_Tokens::get( (int) $token_id );

		if ( $token ) {
			dahu_deferred_sepa_check_default_iban( $token->get_user_id() );
		}
	}
);

add_action(
	'woocommerce_payment_token_set_default',
	static function ( $token_id, $token = null ) {
		if ( $token && method_exists( $token, 'get_user_id' ) ) {
			dahu_deferred_sepa_check_default_iban( $token->get_user_id() );
		}
	},
	10,
	2
);

/**
 * Notices a change of default IBAN and sends the notifications.
 *
 * Nothing happens when the new default is the IBAN already approved — the
 * customer going back to it has nothing to re-approve.
 *
 * @param int $user_id WordPress user.
 * @return void
 */
function dahu_deferred_sepa_check_default_iban( $user_id ) {
	if ( ! $user_id ) {
		return;
	}

	$default = dahu_deferred_sepa_get_default_sepa_token( $user_id );
	$known   = (int) get_user_meta( $user_id, DAHU_DEFERRED_SEPA_KNOWN_DEFAULT_META, true );

	if ( ! $default ) {
		// No default any more: forget it, so a later one is noticed again.
		if ( $known ) {
			update_user_meta( $user_id, DAHU_DEFERRED_SEPA_KNOWN_DEFAULT_META, 0 );
		}

		return;
	}

	if ( (int) $default->get_id() === $known ) {
		return;
	}

	update_user_meta( $user_id, DAHU_DEFERRED_SEPA_KNOWN_DEFAULT_META, (int) $default->get_id() );

	$active = (int) get_user_meta( $user_id, DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META, true );

	if ( (int) $default->get_id() === $active ) {
		return;
	}

	dahu_deferred_sepa_notify_iban_submitted( $user_id, dahu_deferred_sepa_token_last4( $default ) );
}

/* ------------------------------------------------------------------
 * Notifications
 * ------------------------------------------------------------------ */

/**
 * Tells the shop a customer has submitted an IBAN, and tells the customer it
 * was received.
 *
 * @param int    $user_id WordPress user.
 * @param string $last4   Last four digits of the IBAN.
 * @return void
 */
function dahu_deferred_sepa_notify_iban_submitted( $user_id, $last4 ) {
	$user = get_userdata( $user_id );

	if ( ! $user ) {
		return;
	}

	$to = dahu_deferred_sepa_notify_email();

	if ( $to ) {
		$subject = __( 'A customer submitted a SEPA mandate for approval', 'dahu-deferred-sepa' );
		$body    = sprintf(
			/* translators: 1: customer name, 2: customer email, 3: last four digits of the IBAN, 4: date and time, 5: link to the customer's profile. */
			__(
				"A customer has made a new IBAN their default payment method, which submits it for your approval.\n\nCustomer: %1\$s (%2\$s)\nIBAN ending: %3\$s\nWhen: %4\$s\n\nTo approve it, open their profile and name it as the active mandate:\n%5\$s\n\nUntil it is approved, deferred payment is not offered to this customer.",
				'dahu-deferred-sepa'
			),
			$user->display_name,
			$user->user_email,
			$last4,
			dahu_deferred_sepa_format_date( time() ),
			admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#dahu-deferred-sepa' )
		);

		/**
		 * Filters the shop's "mandate submitted" notification.
		 *
		 * @param array $mail    'to', 'subject' and 'body'.
		 * @param int   $user_id Customer.
		 * @param string $last4  Last four digits of the IBAN.
		 */
		// wp_parse_args, not a bare cast: a filter that returns a string or
		// null would otherwise leave the keys undefined and send wp_mail() a
		// set of nulls.
		$mail = wp_parse_args(
			(array) apply_filters(
				'dahu_deferred_sepa_mandate_submitted_admin_email',
				compact( 'to', 'subject', 'body' ),
				$user_id,
				$last4
			),
			compact( 'to', 'subject', 'body' )
		);

		wp_mail( $mail['to'], $mail['subject'], $mail['body'] );
	}

	$subject = __( 'We have received your IBAN', 'dahu-deferred-sepa' );
	$body    = sprintf(
		/* translators: 1: customer name, 2: last four digits of the IBAN, 3: shop name. */
		__(
			"Hello %1\$s,\n\nWe have received your IBAN ending %2\$s.\n\nWe will email you once it has been approved. From then on you will be able to pay for your orders by deferred SEPA Direct Debit.\n\n%3\$s",
			'dahu-deferred-sepa'
		),
		$user->display_name,
		$last4,
		get_bloginfo( 'name' )
	);

	/**
	 * Filters the customer's "we received your IBAN" notification.
	 *
	 * @param array  $mail    'subject' and 'body'.
	 * @param int    $user_id Customer.
	 * @param string $last4   Last four digits of the IBAN.
	 */
	$mail = wp_parse_args(
		(array) apply_filters(
			'dahu_deferred_sepa_mandate_submitted_customer_email',
			compact( 'subject', 'body' ),
			$user_id,
			$last4
		),
		compact( 'subject', 'body' )
	);

	wp_mail( $user->user_email, $mail['subject'], $mail['body'] );
}

/**
 * Tells the customer their IBAN has been approved.
 *
 * @param int $user_id  WordPress user.
 * @param int $token_id Approved token.
 * @return void
 */
function dahu_deferred_sepa_notify_iban_approved( $user_id, $token_id ) {
	$user = get_userdata( $user_id );

	if ( ! $user || ! class_exists( 'WC_Payment_Tokens' ) ) {
		return;
	}

	$token = WC_Payment_Tokens::get( (int) $token_id );

	if ( ! $token ) {
		return;
	}

	$subject = __( 'Your IBAN has been approved for deferred payment', 'dahu-deferred-sepa' );
	$body    = sprintf(
		/* translators: 1: customer name, 2: last four digits of the IBAN, 3: delay in days, 4: shop name. */
		__(
			"Hello %1\$s,\n\nYour IBAN ending %2\$s has been approved for deferred SEPA Direct Debit.\n\nYou can now choose it when you order. Nothing is charged when you place the order: your mandate is charged %3\$d days after it ships, and SEPA then settles in a few working days.\n\n%4\$s",
			'dahu-deferred-sepa'
		),
		$user->display_name,
		dahu_deferred_sepa_token_last4( $token ),
		dahu_deferred_sepa_get_delay_days( $user_id ),
		get_bloginfo( 'name' )
	);

	/**
	 * Filters the customer's "IBAN approved" notification.
	 *
	 * @param array $mail     'subject' and 'body'.
	 * @param int   $user_id  Customer.
	 * @param int   $token_id Approved token.
	 */
	$mail = wp_parse_args(
		(array) apply_filters(
			'dahu_deferred_sepa_mandate_approved_email',
			compact( 'subject', 'body' ),
			$user_id,
			$token_id
		),
		compact( 'subject', 'body' )
	);

	wp_mail( $user->user_email, $mail['subject'], $mail['body'] );
}

/* ------------------------------------------------------------------
 * My account → Payment methods
 * ------------------------------------------------------------------ */

add_action( 'woocommerce_account_payment-methods_endpoint', 'dahu_deferred_sepa_account_payment_methods', 20 );

/**
 * Says where the customer stands, and loads the page touch-ups.
 *
 * @return void
 */
function dahu_deferred_sepa_account_payment_methods() {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return;
	}

	wp_enqueue_style(
		'dahu-deferred-sepa-account',
		DAHU_DEFERRED_SEPA_URL . 'assets/css/account.css',
		array(),
		DAHU_DEFERRED_SEPA_VERSION
	);

	$active  = (int) get_user_meta( $user_id, DAHU_DEFERRED_SEPA_ACTIVE_TOKEN_META, true );
	$default = dahu_deferred_sepa_get_default_sepa_token( $user_id );
	$last4   = '';

	if ( $active ) {
		$token = class_exists( 'WC_Payment_Tokens' ) ? WC_Payment_Tokens::get( $active ) : null;
		$last4 = $token ? dahu_deferred_sepa_token_last4( $token ) : '';
	}

	wp_enqueue_script(
		'dahu-deferred-sepa-account',
		DAHU_DEFERRED_SEPA_URL . 'assets/js/account.js',
		array(),
		DAHU_DEFERRED_SEPA_VERSION,
		true
	);

	wp_localize_script(
		'dahu-deferred-sepa-account',
		'dahuDeferredSepaAccount',
		array(
			'last4'         => $last4,
			'approvedLabel' => __( 'approved', 'dahu-deferred-sepa' ),
			'submitLabel'   => __( 'Submit for approval', 'dahu-deferred-sepa' ),
		)
	);

	if ( dahu_deferred_sepa_is_authorized( $user_id ) ) {
		$message = sprintf(
			/* translators: %d: delay in days. */
			__( 'Your approved mandate is charged %d days after your order ships. SEPA then settles in a few working days.', 'dahu-deferred-sepa' ),
			dahu_deferred_sepa_get_delay_days( $user_id )
		);
	} elseif ( $default && (int) $default->get_id() !== $active ) {
		$message = __( 'Your IBAN has been submitted. We will email you once it has been approved.', 'dahu-deferred-sepa' );
	} else {
		$message = __( 'To pay by SEPA Direct Debit, use "Add payment method" above to save your IBAN. We will email you once it has been approved.', 'dahu-deferred-sepa' );
	}

	$contact = dahu_deferred_sepa_contact_email();

	if ( $contact ) {
		$message .= ' ' . sprintf(
			/* translators: %s: contact email address. */
			__( 'To change the approved mandate, write to %s.', 'dahu-deferred-sepa' ),
			$contact
		);
	}

	echo '<p class="dahu-sepa-account-note">' . esc_html( $message ) . '</p>';
}
