# Dahu Deferred SEPA

Charge a WooCommerce customer's saved Stripe SEPA mandate a set number of days
after their order ships, instead of at checkout.

Stripe charges a SEPA Direct Debit as soon as the order is placed. Some
customers are not meant to be charged then — a trade account on 30-day terms, a
wholesale buyer — and this plugin is the checkout option that waits.

## How it works

1. **At checkout.** The "Deferred SEPA Direct Debit" gateway takes no money. It
   finds the mandate the customer already has saved at Stripe, writes the
   customer and payment method onto the order, and puts the order **on hold** —
   to be prepared and shipped, but not paid, because saying otherwise would be
   a lie in every report the shop looks at.
2. **When the order is completed.** A single scheduled action is queued, N days
   out. Eight by default; overridable globally and per customer.
3. **When it fires.** One off-session PaymentIntent is created and confirmed
   against the saved mandate, carrying a persistent idempotency key.
4. **Days later.** SEPA settles. A dedicated webhook hears the outcome and
   completes or fails the order.

## Approval needs both sides

A Stripe mandate is legally valid from the moment the customer signs it.
Whether the shop is willing to be paid *late* by that customer is a separate,
commercial decision, and this plugin keeps the two apart.

- An administrator names one of the customer's saved IBANs as the **active
  mandate**, on the customer's user profile.
- The customer has that same IBAN as their **own default** payment method.

Either one alone is not enough. That is what stops a customer quietly moving to
an IBAN nobody approved, and it is the reason the gateway is invisible to almost
every shopper.

## Features

| | |
|---|---|
| **Charged once** | One persistent idempotency key per order. A replayed action, a timeout or a retry cannot produce a second debit. |
| **Movable date** | Shown on the order screen and movable until it fires. Every move is counted and kept. |
| **Charge now** | Behind a capability check, a nonce and a confirmation. |
| **Own webhook** | Separate endpoint, separate signing secret, acts only on this plugin's own charges. |
| **Per-customer terms** | A delay on a customer's profile overrides the global one. |
| **Charges screen** | Expected cash, and a filterable, sortable, exportable history. |
| **Nothing fails in silence** | A refused charge is emailed to the shop, once. A charge whose date passed and which never ran is listed at the top of the screen and emailed by a daily check — a deferred charge is the one payment path with no customer watching it fail. |
| **A debit that comes back** | A charge Stripe accepted and then refused before settlement takes no money, so the order is **reopened** — this plugin's own "already charged" guard used to leave such an order with no way out: no charge, no reschedule, no "settled another way". A failure or a dispute on a charge already collected is left exactly as it is, because undoing a settlement on an ambiguous event is the one move that could cost money — and it is emailed, which it never was before. |
| **Diagnostics** | The plugin explains its own availability instead of leaving you to guess. |
| **HPOS** | Both order storages. No custom tables. |

## Requirements

- WordPress 6.0+, PHP 7.4+
- WooCommerce 7.0+
- SEPA Direct Debit working at Stripe — in practice the official
  [WooCommerce Stripe Payment Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/),
  which is how a customer signs and saves a mandate.

The Stripe secret key is either this plugin's own setting — a restricted key
that may only write PaymentIntents is enough — or borrowed from the official
plugin's configuration. Filling in your own key means this plugin does not
depend on another plugin's stored settings.

## Setup

Enable the gateway under **WooCommerce → Settings → Payments → Deferred SEPA
Direct Debit**, then create the webhook endpoint in your Stripe dashboard:

```
https://yoursite.example/wp-json/dahu-deferred-sepa/v1/webhook
```

listening for `payment_intent.succeeded`, `payment_intent.payment_failed` and
`charge.dispute.created`. Put its signing secret in `wp-config.php`:

```php
define( 'DAHU_DEFERRED_SEPA_WEBHOOK_SECRET', 'whsec_...' );
```

Never in a plugin file. While the constant is empty the webhook accepts
nothing, which is the safe default: a forged notification must never be able to
mark an order paid.

Test mode and live mode are **separate endpoints with different secrets**.

## Before you switch it on

A SEPA mandate carries obligations to the payer that are yours, not this
plugin's. Depending on the mandate you use, you may owe the customer advance
notice of the date and the amount of each debit — and deferring a charge changes
that date. Check what your mandate and your bank require, and tell your
customers what to expect.

Stripe accepts SEPA Direct Debit in **euro only**. An order in another currency
is refused with an order note rather than charged in the wrong unit.

## Hooks

### Filters

| Filter | Purpose |
|---|---|
| `dahu_deferred_sepa_delay_days` | The resolved charge delay, per customer. |
| `dahu_deferred_sepa_excluded_roles` | Roles never offered the gateway. Empty by default. |
| `dahu_deferred_sepa_secret_key` | The Stripe secret key, for a shop that keeps keys outside the database. |
| `dahu_deferred_sepa_payment_intent_body` | The PaymentIntent body before it is sent. |
| `dahu_deferred_sepa_charge_hour` | Hour of day a rescheduled charge is sent. Default 9. |
| `dahu_deferred_sepa_settlement_days` | Assumed SEPA settlement time, in working days. Default 3. |
| `dahu_deferred_sepa_webhook_tolerance` | How far out of date a webhook may be. Default 5 minutes. |
| `dahu_deferred_sepa_csv_delimiter` | CSV delimiter for the export. Default a comma. |
| `dahu_deferred_sepa_mandate_submitted_admin_email` | The shop's "mandate submitted" email. |
| `dahu_deferred_sepa_mandate_submitted_customer_email` | The customer's "we received your IBAN" email. |
| `dahu_deferred_sepa_mandate_approved_email` | The customer's "approved" email. |
| `dahu_deferred_sepa_overdue_grace` | How late a charge may be before it is reported. Default one day; it is never allowed to be zero. |
| `dahu_deferred_sepa_charge_refused_email` | The shop's "charge refused" alert. |
| `dahu_deferred_sepa_overdue_email` | The daily "charges never ran" alert. |

### Constants

All overridable from `wp-config.php`.

| Constant | Default | Purpose |
|---|---|---|
| `DAHU_DEFERRED_SEPA_WEBHOOK_SECRET` | *(empty)* | Webhook signing secret. Define this in `wp-config.php`. |
| `DAHU_DEFERRED_SEPA_DELAY_DAYS` | `8` | Last-resort delay, used when neither the customer's nor the gateway's setting is set. |
| `DAHU_DEFERRED_SEPA_DEBUG` | `false` | Forces the diagnostics on, for a site whose settings screen is unreachable. |

## Not affiliated

This plugin is not affiliated with, endorsed by or sponsored by Stripe. "Stripe"
and "SEPA" are used only to say what the plugin works with.

## License

MIT. See [LICENSE](LICENSE).
