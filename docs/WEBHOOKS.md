# Webhooks

Use this document to understand the package's generic webhook intake contract.

It explains what happens when a provider POSTs to the webhook endpoint, how duplicates are handled, and where signature validation and billing mutation actually occur.

## Route Shape

When auto-registration is enabled, the package registers:

- `POST /subguard/webhooks/{provider}`

Named route:

- `subguard.webhooks.handle`

The actual base path is controlled by `subscription-guard.webhooks.prefix`.

The route is auto-registered only when `subscription-guard.webhooks.auto_register_routes` is `true`.

## Middleware And Rate Limiting

Webhook routes are registered with:

- `subscription-guard.webhooks.middleware`

The default middleware stack is:

- `api`
- `throttle:webhook-intake`

The `webhook-intake` rate limiter is configured from `subscription-guard.webhooks.rate_limit.*`.

See [Configuration](CONFIGURATION.md) for the relevant keys.

## Intake Rules

The generic webhook controller applies these rules before the finalization job runs:

- unknown provider: `404` JSON rejection
- empty payload: `400` JSON rejection
- first accepted delivery: persisted and queued for finalization
- duplicate delivery for an already-pending or processed event: accepted without creating a second record
- re-delivery of a previously failed event: existing record is reset to `pending` and re-queued
- lock timeout during intake: `503` JSON retry response

The controller does not apply billing mutations directly.

## Event Type And Event ID Derivation

Event type is derived from:

- `event_type`
- `type`
- otherwise `unknown`

Event id resolution is more defensive. The current candidate order includes:

- `event_id`
- `eventId`
- `id`
- `merchant_oid`
- `reference_no`
- `payment_id`
- `paymentId`
- `conversationId`
- `referenceCode`
- `orderReferenceCode`
- `subscriptionReferenceCode`
- `token`

If none of those values exist, the package falls back to a hash of:

- provider
- event type
- raw body

This is why PayTR commonly keys by `merchant_oid`, while iyzico callback traffic often keys by conversation or reference values.

## Persistence And Duplicate Handling

Webhook requests are persisted into `WebhookCall`.

Current intake behavior:

- the controller takes a cache lock scoped by provider and event id
- inside a database transaction, it checks for an existing `WebhookCall`
- if none exists, it creates a new `pending` record
- if one exists with `failed` status, it resets the record for retry
- otherwise it returns a duplicate acceptance response

This is the first idempotency boundary.

## Queue Handoff

Accepted webhook deliveries dispatch:

- `FinalizeWebhookEventJob`

That job is pushed onto:

- `subscription-guard.queue.webhooks_queue`
- default queue name: `subguard-webhooks`

The webhook controller stops at durable intake. Final business processing happens later in the job.

## Where Signature Validation Happens

Generic webhooks are not signature-validated synchronously in `WebhookController`.

Instead:

1. the request is accepted and persisted
2. `FinalizeWebhookEventJob` loads the stored payload and headers
3. the provider adapter re-validates the signature
4. invalid signatures mark the webhook as failed
5. valid payloads are normalized through `processWebhook()`
6. `SubscriptionService::handleWebhookResult()` applies the package-side mutation

This split keeps the ingress path fast and durable while still enforcing signature checks before billing state changes.

## Response Semantics

The default JSON response shape is:

- first accepted delivery: `202`
- duplicate delivery: `200`
- body fields include `status`, `provider`, `event_id`, and `duplicate`

However, the provider config may override the success response format.

Current example:

- PayTR sets `webhook_response_format = text`
- PayTR sets `webhook_response_body = OK`

So a successful PayTR webhook ingress responds with plain-text `OK` and status `200`, even though the internal persistence and queue handoff rules still apply.

Error responses such as unknown provider, empty payload, or lock timeout remain JSON responses.

## Finalization Flow

Inside `FinalizeWebhookEventJob`, the package:

- takes a second lock on the stored webhook record
- short-circuits already-processed records
- checks that the provider still exists and resolves correctly
- validates the stored signature through the provider adapter
- calls `processWebhook()` to obtain a normalized `WebhookResult`
- marks the webhook `processed` or `failed`
- hands the result to `SubscriptionService`
- dispatches `DispatchBillingNotificationsJob` with `webhook.processed`

This is the actual bridge from transport to billing state.

## Provider-Specific Notes

- `iyzico`
  Uses the generic webhook route plus dedicated callback routes. Signature validation for generic webhooks happens during finalization.
- `paytr`
  Uses the generic webhook route as its main inbound surface and typically responds with plain-text `OK`.

Use provider docs for adapter-specific behavior, not this generic transport doc.

## Where To Go Next

- [Callbacks](CALLBACKS.md)
- [API](API.md)
- [Domain Providers](DOMAIN-PROVIDERS.md)
- [Events And Jobs](EVENTS-AND-JOBS.md)
- [providers/IYZICO](providers/IYZICO.md)
- [providers/PAYTR](providers/PAYTR.md)
