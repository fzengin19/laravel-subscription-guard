# Events And Jobs

Use this document to understand how the package moves work between controllers, services, events, and queues.

## Why This Layer Exists

The package separates:

- fast request acceptance
- domain-state orchestration
- provider-specific observability
- background processing
- notification side effects

This keeps webhook intake responsive while still preserving billing and licensing correctness.

## Generic Domain Events

The package currently emits these generic billing-domain events:

- `WebhookReceived`
- `SubscriptionCreated`
- `SubscriptionCancelled`
- `PaymentCompleted`
- `PaymentFailed`
- `SubscriptionRenewed`
- `SubscriptionRenewalFailed`
- `DunningExhausted`

What they are for:

- generic lifecycle listeners
- application-level integrations that should not depend on a single provider
- license synchronization through `LicenseLifecycleListener`

## Provider-Specific Events

Provider event dispatchers emit provider-scoped events after generic orchestration has identified the meaning of a provider outcome.

Current iyzico-specific events include:

- `IyzicoWebhookReceived`
- `IyzicoWebhookProcessed`
- `IyzicoWebhookFailed`
- `IyzicoPaymentInitiated`
- `IyzicoPaymentCompleted`
- `IyzicoPaymentFailed`
- `IyzicoSubscriptionCreated`
- `IyzicoSubscriptionCancelled`
- `IyzicoSubscriptionUpgraded`
- `IyzicoSubscriptionOrderSucceeded`
- `IyzicoSubscriptionOrderFailed`

Current PayTR-specific events include:

- `PaytrWebhookReceived`
- `PaytrPaymentCompleted`
- `PaytrPaymentFailed`
- `PaytrRefundProcessed`

These events are useful for provider-specific monitoring and downstream integrations without polluting the generic billing event layer.

## Intake to Finalization Flow

Webhook and callback routes do not fully process provider payloads inline.

They follow this pattern:

1. resolve provider
2. validate basic request shape
3. derive event id
4. create or reopen a `WebhookCall`
5. return `202 Accepted` for new work or `200` for duplicates
6. dispatch `FinalizeWebhookEventJob`

Callback routes perform an extra step first:

- signature validation before persistence

This protects the package from heavy synchronous work during intake while still making inbound event state observable.

## Job Catalog

### `FinalizeWebhookEventJob`

Purpose:

- verify stored webhook or callback payloads
- call `processWebhook()` on the provider adapter
- mark the `WebhookCall` as processed or failed
- hand the normalized `WebhookResult` to `SubscriptionService`
- queue webhook-processed notifications

Queue:

- `subguard-webhooks`

Safety controls:

- cache lock per webhook call id
- transaction wrapping around finalization state

### `ProcessRenewalCandidateJob`

Purpose:

- determine whether a due self-managed subscription should create a renewal transaction
- create an idempotent renewal transaction
- dispatch `PaymentChargeJob`

Queue:

- `subguard-billing`

Safety controls:

- cache lock per subscription id
- transaction wrapping around transaction creation

### `PaymentChargeJob`

Purpose:

- execute provider recurring charge requests
- update transaction state
- update subscription lifecycle
- advance billing dates
- emit generic billing events
- queue payment notifications

Queue:

- `subguard-billing`

Safety controls:

- cache lock per transaction id
- transaction wrapping around state mutation
- provider response written back to transaction state

### `ProcessDunningRetryJob`

Purpose:

- retry failed recurring transactions
- move exhausted subscriptions and licenses into suspended state
- emit `DunningExhausted`

Queue:

- `subguard-billing`

Safety controls:

- cache lock per transaction id
- transaction-wrapped exhaustion handling

### `ProcessScheduledPlanChangeJob`

Purpose:

- apply due upgrades or downgrades
- update subscription commercial fields
- mark scheduled changes as processed or failed

Queue:

- `subguard-billing`

### `DispatchBillingNotificationsJob`

Purpose:

- log notification events by concern
- generate invoices for `payment.completed`
- render PDFs
- dispatch invoice and cancellation notifications

Queue:

- `subguard-notifications`

### `SyncBillingProfileJob`

Purpose:

- synchronize billing-profile data for billable models

Queue:

- billing queue by configuration

## Commands as Job Dispatchers

Several commands are intentionally thin wrappers around orchestration services or processors.

Current examples:

- `subguard:process-renewals`
  Dispatches renewal-candidate jobs.
- `subguard:process-dunning`
  Dispatches dunning retry jobs.
- `subguard:process-plan-changes`
  Dispatches scheduled plan-change jobs.
- `subguard:process-metered-billing`
  Runs `MeteredBillingProcessor`.
- `subguard:suspend-overdue`
  Performs direct overdue suspension work.
- `subguard:simulate-webhook`
  Creates deterministic local webhook intake for testing and operator validation.

This split keeps commands operationally explicit while still centralizing domain logic in services and jobs.

## Queue Ownership by Concern

The package uses separate queue names by concern.

| Concern | Default Queue |
|---|---|
| Billing orchestration and retries | `subguard-billing` |
| Webhook and callback finalization | `subguard-webhooks` |
| Notifications and invoices | `subguard-notifications` |

This separation helps operators:

- isolate noisy webhook traffic
- scale renewal processing independently
- keep notifications from blocking financial state changes

## Event Bridge into Licensing

The most important built-in listener is `LicenseLifecycleListener`.

It listens to generic billing events, not provider-specific events.

This means:

- licensing behavior stays provider-agnostic
- a successful payment can activate or refresh license state regardless of provider
- failure and cancellation semantics remain consistent across providers

See [Domain Licensing](DOMAIN-LICENSING.md).

## Idempotency and Concurrency Rules

The event and job layer relies on a few explicit protections:

- `WebhookCall` uniqueness by provider plus event id
- transaction uniqueness through `idempotency_key`
- cache locks for webhook, callback, renewal, dunning, plan-change, and payment-charge work
- duplicate webhook deliveries returning a non-error accepted response
- failed webhook calls being reopenable for retry

These protections are essential to safe financial and licensing state updates.

## Related Documents

- [Architecture](ARCHITECTURE.md)
- [Domain Billing](DOMAIN-BILLING.md)
- [Domain Providers](DOMAIN-PROVIDERS.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
- [Data Model](DATA-MODEL.md)
