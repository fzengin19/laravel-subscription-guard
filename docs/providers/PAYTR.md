# PAYTR Provider

Use this document when you are integrating the `paytr` adapter specifically.

It explains the current PayTR payment flow, webhook expectations, and the package-managed recurring billing model that sits behind this provider.

## Role In The Package

`paytr` is the current package-managed billing adapter.

That means:

- `manages_own_billing = false`
- the package dispatches renewal and dunning work for PayTR subscriptions
- the provider adapter focuses on payment APIs, webhook validation, and normalization
- recurring billing behavior is owned by package jobs and commands, not by a remote provider subscription engine

See [Domain Providers](../DOMAIN-PROVIDERS.md) for the abstraction boundary and [Domain Billing](../DOMAIN-BILLING.md) for the renewal and dunning flow.

## Payment And Subscription Surface

The adapter currently supports:

- `pay()`
- `refund()`
- `createSubscription()`
- `cancelSubscription()`
- `upgradeSubscription()`
- `chargeRecurring()`
- `validateWebhook()`
- `processWebhook()`

Unlike `iyzico`, `chargeRecurring()` is a first-class path here because the package is expected to execute recurring charges itself.

## Iframe Payment Flow

`pay()` returns PayTR-oriented iframe metadata.

Current behavior:

- mock mode returns deterministic iframe token and URL values
- live mode returns generated iframe token and URL metadata without using placeholder failure responses
- the practical mode is still iframe-oriented even though the package normalizes the response into `PaymentResponse`

Readers integrating checkout UI should treat the PayTR provider response as an iframe/bootstrap contract, not as a direct card charge confirmation.

## Webhook Validation Model

PayTR currently uses the generic webhook route:

- `POST /subguard/webhooks/paytr`

The adapter validates webhook traffic with merchant-key and merchant-salt hashing rules.

Current behavior:

- live mode computes the expected hash from `merchant_oid`, `status`, and `total_amount`
- the signature may be passed explicitly by the caller or taken from the payload's `hash` field
- mock mode accepts the webhook so deterministic local tests can proceed

The default provider config also sets:

- `webhook_response_format = text`
- `webhook_response_body = OK`

This is why a successful PayTR webhook ingress responds with plain-text `OK` instead of the JSON acceptance body used by other providers.

See [Webhooks](../WEBHOOKS.md) for the transport semantics.

## Webhook Normalization

`processWebhook()` currently normalizes PayTR payloads into package event types and billing state:

- `status=success` becomes `subscription.order.success` and local `active`
- `status=failed` becomes `subscription.order.failure` and local `past_due`

The adapter also derives:

- `eventId` from `merchant_oid` first
- `subscriptionId` from `subscription_id`, provider subscription id, or `merchant_oid`
- `transactionId` from `reference_no`, `payment_id`, or `merchant_oid`
- `amount` from `total_amount`, with integer minor-unit inputs normalized into major-unit amounts

This keeps the controller and finalizer generic while still preserving PayTR-specific semantics.

## Recurring Billing Implications

Because PayTR is package-managed:

- renewal discovery is local
- recurring charge attempts are dispatched by package jobs
- failures enter dunning flow
- overdue suspension logic remains inside package commands and jobs

This is the key difference from `iyzico`.

If the host application needs to reason about retry and suspension behavior, use the billing docs, not only the provider doc.

## Mock Vs Live Boundary

PayTR also exposes a mock flag.

Mock mode is appropriate for:

- deterministic local development
- contract tests for iframe responses and webhook normalization
- safe validation of package-managed billing orchestration without real provider traffic

Live mode is necessary when you need to validate:

- real merchant-key and merchant-salt hashing
- actual iframe bootstrap behavior
- real recurring charge and refund contracts

Like `iyzico`, mock mode is a development tool, not a production substitute.

## Current Integration Notes

Current repo evidence shows:

- no dedicated PayTR `3ds` or checkout callback flow is documented or exercised
- webhook ingress is the main inbound integration path
- provider-specific events are emitted through `PaytrProviderEventDispatcher`
- the adapter is intentionally thinner than `iyzico` because the package owns more of the recurring lifecycle

## Where To Go Next

- [Providers Overview](../PROVIDERS.md)
- [Domain Providers](../DOMAIN-PROVIDERS.md)
- [Domain Billing](../DOMAIN-BILLING.md)
- [Webhooks](../WEBHOOKS.md)
- [API](../API.md)
