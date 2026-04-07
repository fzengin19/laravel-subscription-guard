# Callbacks

Use this document to understand the package's dedicated payment callback routes.

Callbacks are separate from generic webhooks because the package validates them synchronously before persistence and currently uses them for iyzico payment flows such as `3ds` and `checkout_form`.

## Route Shapes

When webhook routes are auto-registered, the package also registers:

- `POST /subguard/webhooks/{provider}/3ds/callback`
- `POST /subguard/webhooks/{provider}/checkout/callback`

Named routes:

- `subguard.webhooks.3ds-callback`
- `subguard.webhooks.checkout-callback`

The actual prefix is controlled by `subscription-guard.webhooks.prefix`.

## Current Provider Scope

The callback routes are generic in shape, but current repo evidence shows active callback-oriented behavior only for `iyzico`.

That matters because:

- `iyzico` payment initialization paths generate callback URLs
- current tests cover iyzico `3ds` and checkout callback handling
- current PayTR coverage focuses on the generic webhook route instead

## Synchronous Validation Rule

Unlike generic webhooks, callbacks are signature-validated before the request is persisted.

Current flow:

1. package checks that the provider exists
2. package rejects empty payloads
3. provider adapter is resolved
4. signature header is read from `providers.drivers.{provider}.signature_header`
5. `validateWebhook()` runs immediately
6. invalid signatures return `401` without creating a `WebhookCall`
7. valid callbacks continue into locked persistence and queue handoff

For `iyzico`, the default signature header is:

- `x-iyz-signature-v3`

## Event Type And Event ID

The callback controller stores a fixed event type per route:

- `payment.3ds.callback`
- `payment.checkout.callback`

Event id resolution uses the same defensive candidate order as generic webhooks.

In practice this means callback records often use:

- `conversationId`
- `referenceCode`
- `paymentId`
- `token`

depending on the payload shape.

## Duplicate And Retry Behavior

Callbacks use the same durable-intake pattern as webhooks:

- provider-plus-event-id lock
- database transaction with `lockForUpdate()`
- one `WebhookCall` record per provider and event id
- duplicate callback returns `200` with `duplicate=true`
- first accepted callback returns `202`
- previously failed callback is reset to `pending` and re-queued

This gives callbacks the same idempotency posture as generic webhooks while keeping signature validation earlier in the flow.

## Failure Responses

Current callback controller behavior:

- unknown provider: `404` JSON rejection
- empty payload: `400` JSON error
- invalid signature: `401` JSON rejection
- lock timeout: `503` JSON retry response
- accepted callback: `202` JSON
- duplicate callback: `200` JSON

## Queue Handoff And Finalization

Accepted callbacks also dispatch:

- `FinalizeWebhookEventJob`

So the callback controller still does not apply billing state directly.

The callback route is only an authenticated intake surface. The actual result handling still happens through the same finalization and `SubscriptionService` path used by generic webhooks.

## Callback URL Generation For IYZICO

`iyzico` builds callback URLs from either:

- `providers.drivers.iyzico.callback_url` when it is a valid HTTP or HTTPS base URL
- or the package webhook prefix when no valid custom base URL is configured

Current generated suffixes are:

- `/3ds/callback`
- `/checkout/callback`

This is why iyzico callback configuration should be treated as a base URL, not as a full final path.

## Practical Integration Notes

If you are integrating iyzico callbacks:

- make the callback endpoint reachable from the provider
- use HTTPS for real sandbox traffic
- keep the signature header intact
- expect the package to queue finalization instead of finishing the billing change in the HTTP request

## Where To Go Next

- [Webhooks](WEBHOOKS.md)
- [API](API.md)
- [providers/IYZICO](providers/IYZICO.md)
- [Events And Jobs](EVENTS-AND-JOBS.md)
