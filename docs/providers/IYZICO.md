# IYZICO Provider

Use this document when you are integrating the `iyzico` adapter specifically.

It explains the current provider-specific behavior, callback and webhook expectations, command surface, and the boundary between deterministic local flows and real sandbox traffic.

## Role In The Package

`iyzico` is the current provider-managed billing adapter.

That means:

- `manages_own_billing = true`
- recurring billing is expected to run remotely
- local renewal charging is not executed through `chargeRecurring()`
- local subscription and transaction state is mainly updated from inbound webhook and callback results

If you need the package to run recurring charging itself, that is the `paytr` model, not the `iyzico` model.

See [Domain Providers](../DOMAIN-PROVIDERS.md) for the abstraction boundary and [Domain Billing](../DOMAIN-BILLING.md) for the billing consequences.

## Supported Payment Modes

The adapter currently exposes three payment-mode paths through `pay()`:

- `non_3ds`
  Direct payment flow that returns a processed payment response when the provider accepts the payment.
- `3ds`
  Payment initialization that returns a callback-aware response and depends on the `3ds/callback` route for follow-up handling.
- `checkout_form`
  Checkout-form initialization that returns an iframe token or URL and depends on the `checkout/callback` route for follow-up handling.

In mock mode, the adapter returns deterministic local response contracts for all three modes.

In live mode, the adapter uses iyzico SDK requests and returns the real provider response shape that the package normalizes into `PaymentResponse`.

## Subscription Lifecycle Surface

The adapter currently supports:

- `createSubscription()`
- `cancelSubscription()`
- `upgradeSubscription()`
- `refund()`
- plan-sync and reconciliation helper commands

Important operational rule:

- `chargeRecurring()` is intentionally unsupported for `iyzico`

This is not an incidental gap. It reflects the provider-managed billing model. Local renewal commands should not try to charge iyzico subscriptions directly.

## Callback URL Behavior

`iyzico` is the only current adapter that actively uses the dedicated callback routes.

Current callback routes:

- `POST /subguard/webhooks/iyzico/3ds/callback`
- `POST /subguard/webhooks/iyzico/checkout/callback`

Callback URL generation follows this rule:

- if `providers.drivers.iyzico.callback_url` is a valid HTTP or HTTPS URL, the adapter appends `/3ds/callback` or `/checkout/callback`
- otherwise it falls back to the package webhook prefix, for example `http://localhost/subguard/webhooks/iyzico/3ds/callback`

This means a malformed custom callback URL does not silently disable callback handling; it falls back to the package route shape instead.

See [Callbacks](../CALLBACKS.md) for the controller-side behavior after the request reaches the package.

## Webhook And Callback Signature Behavior

The default signature header is:

- `x-iyz-signature-v3`

Current validation model:

- callback requests are validated synchronously in `PaymentCallbackController`
- general webhook requests are accepted first and validated later inside `FinalizeWebhookEventJob`
- mock mode returns `true` for signature validation so deterministic local flows can proceed without live credentials
- live mode requires a configured secret and a payload shape that matches one of the adapter's supported signature constructions

The adapter currently supports multiple live signature patterns, including callback-token and subscription-reference forms, because iyzico sends different payload shapes across payment and subscription flows.

See [Webhooks](../WEBHOOKS.md) and [Callbacks](../CALLBACKS.md) for the transport-level behavior.

## Plan Sync And Reconciliation Commands

`iyzico` currently adds two provider-specific commands:

- `php artisan subguard:sync-plans`
- `php artisan subguard:reconcile-iyzico-subscriptions`

`subguard:sync-plans`:

- syncs active local plans to iyzico product and pricing-plan references
- supports `--dry-run`, `--force`, and `--remote`
- falls back to local deterministic sync if live credentials or live mode are not available

`subguard:reconcile-iyzico-subscriptions`:

- checks local iyzico subscriptions that have provider subscription ids
- supports `--dry-run` and `--remote`
- prefers remote status pull when live mode is available
- otherwise falls back to local metadata reconciliation

These commands matter because provider-managed billing still needs local state alignment.

## Mock Vs Live Boundary

The adapter supports both deterministic and live paths, but they serve different purposes.

Mock mode is useful for:

- local development
- deterministic tests
- callback and webhook flow validation without real provider traffic

Live mode is necessary for:

- real iyzico sandbox verification
- actual checkout-form and payment contracts
- remote plan sync
- remote subscription reconciliation
- real refund and card-vault behavior

Do not treat mock mode as production-safe proof that a live iyzico flow is ready.

## Live Sandbox Caveats

The repository already contains a dedicated iyzico live sandbox suite under `tests/Live/Iyzico`.

Current caveats exposed by code and tests:

- live sandbox runs are isolated from the deterministic suite
- a public HTTPS callback URL is required for real webhook and callback roundtrips
- exported process environment values take precedence over fallback file-based values
- `subguard:sync-plans --remote` and real upgrade testing depend on iyzico-side product and pricing-plan references
- some 3DS roundtrip scenarios remain operator-assisted rather than fully deterministic

Those details belong to a later runtime doc, but they are important enough to mention here so readers do not confuse adapter support with automatic sandbox readiness.

## Integration Checklist

For a safe iyzico integration path:

- configure the iyzico driver and keep secrets in environment variables
- decide whether you are validating mock flows or real sandbox traffic
- ensure callback routes are reachable if you use `3ds` or `checkout_form`
- use webhook and callback docs to validate intake behavior
- use plan sync before creating subscriptions that require iyzico pricing-plan references

## Where To Go Next

- [Providers Overview](../PROVIDERS.md)
- [Domain Providers](../DOMAIN-PROVIDERS.md)
- [Webhooks](../WEBHOOKS.md)
- [Callbacks](../CALLBACKS.md)
- [API](../API.md)
