=== Dahu Deferred SEPA ===
Contributors: hugovialjaime
Tags: woocommerce, sepa, direct debit, stripe, payment terms
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Charge a customer's saved SEPA mandate a set number of days after their order ships, instead of at checkout.

== Description ==

Some customers are not charged when they order. A trade account on 30-day terms,
a wholesale buyer, a subscription-style arrangement: the mandate is signed, the
order ships, and the money is taken later.

Stripe charges a SEPA Direct Debit as soon as the order is placed. This plugin
adds a checkout option that does not. It reuses the mandate the customer has
already saved, records it on the order, puts the order on hold, and schedules
the real charge for a configurable number of days after the order is marked
Completed. Eight days by default, overridable per customer.

**Who may pay late is decided by the shop, not by the customer.** An
administrator names one of the customer's saved IBANs as the active mandate on
their user profile. The customer must also have that same IBAN set as their own
default. Both halves have to agree, which is what stops a customer quietly
switching to an IBAN nobody approved.

= What it does =

* **Deferred charging.** Nothing is taken at checkout. The charge is scheduled
  automatically when the order is completed, using Action Scheduler.
* **Move the date.** The due date is shown on the order screen and can be moved
  until the moment it fires, with every move recorded.
* **Charge now.** A button to send the charge immediately, behind a capability
  check, a nonce and a confirmation.
* **Charged once, whatever happens.** A persistent idempotency key per order
  means a replayed scheduled action, a network timeout or a retry can never
  produce a second debit.
* **Its own Stripe webhook.** SEPA is asynchronous: a charge leaves as
  "processing" and succeeds or fails days later. A dedicated endpoint with its
  own signing secret listens for the outcome and for disputes, independently of
  the official Stripe plugin's webhook.
* **Per-customer terms.** A delay set on a customer's profile overrides the
  global one, for a trade account on different terms.
* **A charges screen.** WooCommerce → SEPA charges, with an Upcoming tab for
  expected cash and a History tab of every charge sent, filterable by date and
  outcome, sortable, and exportable to CSV.
* **Optionally hides Stripe's immediate SEPA option** at checkout, while
  leaving it available under My account → Payment methods, where the customer
  signs the mandate in the first place.
* **Nothing fails in silence.** A refused charge is emailed to the shop, once,
  with the reason and never with a raw carrier response. A charge whose date
  has passed and which never ran is taken out of "upcoming", listed at the top
  of the screen with what is in the way, and reported by a daily check: a
  deferred charge is the one payment path with no customer in front of a
  screen to find out it did not work. Nothing is ever retried automatically,
  because a charge that may already have been sent would be taken twice.
* **Diagnostics.** The gateway is deliberately hard to qualify for, so it can
  show an administrator each condition it checks and the answer, rather than
  leaving "why is it not showing?" to be guessed.
* **HPOS and legacy order storage.** Both, with no custom tables: everything is
  order meta.

= What it needs =

WooCommerce, and SEPA Direct Debit working at Stripe — in practice the official
WooCommerce Stripe Payment Gateway, which is what lets a customer sign and save
a mandate. This plugin does not replace it and does not take card payments.

The Stripe secret key can be this plugin's own setting (a restricted key that
may only write PaymentIntents is enough) or borrowed from the official plugin's
configuration.

= Before you switch it on =

A SEPA mandate carries obligations to the payer that are yours, not this
plugin's. Depending on the mandate you use, you may owe the customer advance
notice of the date and amount of each debit. Deferring a charge changes that
date. Check what your mandate and your bank require before charging customers
on a delay, and tell your customers what to expect.

This plugin is not affiliated with or endorsed by Stripe.

== Installation ==

1. Install and activate WooCommerce, and the official WooCommerce Stripe
   Payment Gateway with SEPA Direct Debit enabled. Keep Stripe in test mode
   while you set this up.
2. Install and activate this plugin.
3. Go to WooCommerce → Settings → Payments → Deferred SEPA Direct Debit and
   enable it. Set the default delay.
4. Create a webhook endpoint in your Stripe dashboard pointing at
   `https://yoursite.example/wp-json/dahu-deferred-sepa/v1/webhook`, listening
   for `payment_intent.succeeded`, `payment_intent.payment_failed` and
   `charge.dispute.created`. Copy its signing secret into `wp-config.php`:

   `define( 'DAHU_DEFERRED_SEPA_WEBHOOK_SECRET', 'whsec_...' );`

5. Have a test customer save an IBAN under My account → Payment methods, then
   approve it on their user profile under Deferred SEPA Direct Debit.

Test mode and live mode are separate endpoints with different secrets. When you
go live, create a live endpoint and update the constant, or every notification
is rejected and orders stay unresolved.

== External services ==

This plugin sends requests to **Stripe** (https://api.stripe.com), using the
Stripe API key you configure. Stripe is the payment processor that holds the
customer's SEPA mandate and moves the money; without it the plugin has nothing
to charge.

What is sent, and when:

* **When a deferred charge comes due** — the order total and currency, the
  Stripe customer and payment-method identifiers already saved on the order, a
  description containing the order number and your site name, and metadata
  containing the order id, the order number and your site URL. This is the
  request that creates and confirms the payment.
* **When resolving which Stripe customer owns a saved mandate** — the
  payment-method identifier alone, read back to confirm its owner. The answer is
  cached so this is not repeated.

Nothing is sent for an order that is not being charged, and no request is made
unless a Stripe secret key is configured.

The plugin also *receives* requests from Stripe, at its own webhook endpoint,
telling it whether a charge succeeded, failed or was disputed. Those requests
are rejected unless they carry a valid signature for your webhook signing
secret.

Stripe's terms: https://stripe.com/legal/ssa
Stripe's privacy policy: https://stripe.com/privacy

This plugin is not affiliated with, endorsed by or sponsored by Stripe.

== Frequently Asked Questions ==

= Why does the gateway not appear at checkout? =

It appears only when all of these hold: it is enabled; the customer is signed
in; their role is not excluded; an administrator has named one of their IBANs
as the active mandate; the customer has that same IBAN as their own default;
and a Stripe secret key is configured. Turn on Diagnostics in the gateway
settings and the plugin will tell you which one is failing.

= Can a customer be charged twice? =

No. Each order gets one idempotency key, generated once and reused, so Stripe
treats every retry of the same charge as the same request.

= What happens if a charge fails? =

The webhook marks the order failed and records the reason Stripe gave, as an
order note. The charge is not retried automatically: a failed direct debit
usually needs a human to look at it.

= Does it work without the official Stripe plugin? =

The charging does, given a secret key and a customer who already has a mandate
saved at Stripe. But that plugin is how a customer signs and saves a mandate in
the first place, so in practice you want both.

= Does it handle currencies other than the euro? =

No. Stripe accepts SEPA Direct Debit in euro only. An order in another currency
is refused with an order note rather than charged in the wrong unit.

= Is my order history deleted if I uninstall it? =

No. The settings are removed and any queued charge is cancelled. Everything
written on an order — when each charge was due, when it was sent, what Stripe
answered — is a payment record and is kept.

== Screenshots ==

1. The gateway settings.
2. Naming a customer's active mandate on their user profile.
3. The charge date on the order screen, with the button to send it now.
4. WooCommerce → SEPA charges: expected cash, and the history.

== Changelog ==

= 1.2.1 =
* An empty Upcoming tab now says what will appear there instead of showing a bare line of text, and an empty History tab no longer blames filters that were never set.

= 1.2.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 1.1.1 =
* A per-customer charge delay of zero days — "charge as soon as it ships" — is
  now saved. It was silently discarded, and that customer fell back to the
  shop's global delay with nothing on screen to say so.
* Uninstalling now also removes the overdue-sweep marker, any charge claim left
  behind by a worker killed mid-charge, and the daily sweep's own cron event,
  which used to keep waking the site up for a hook nothing answered.

= 1.1.0 =
* A charge Stripe accepted and then refused before settlement no longer leaves
  the order stuck. Nothing left the customer's account, so the order is
  reopened and can be scheduled again — previously the "already charged" guard
  refused a charge, a reschedule and the "settled another way" mark alike,
  leaving no way out at all.
* A failure or a dispute reported on a charge that was already collected is
  emailed to the shop. It was written on the order and told to nobody.
* Such an order is deliberately left untouched: undoing a settlement on an
  event that may be a duplicate is the one move that could cost money.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.1 =
Fixes a per-customer charge delay of zero days being discarded, and completes the uninstaller.

= 1.1.0 =
Reports charges that came back after being collected, and unsticks orders whose
debit was refused before settlement.

= 1.0.0 =
First release.
