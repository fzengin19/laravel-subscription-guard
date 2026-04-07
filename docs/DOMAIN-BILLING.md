# Domain Billing

Use this document to understand the package's billing model, renewal flow, retry behavior, metered usage handling, and seat-management rules.

## Billing Scope

In this package, billing covers:

- subscription lifecycle
- recurring renewal charging
- failure and dunning handling
- scheduled plan changes
- discounts and coupons
- metered usage collection
- seat quantity management
- invoice and notification hooks

The canonical orchestration service for these behaviors is `SubscriptionService`, with follow-up work delegated to jobs.

## Ownership Model

Billing behavior depends on `providers.drivers.<provider>.manages_own_billing`.

| Mode | Current Provider | What Owns Renewal Execution |
|---|---|---|
| Provider-managed | `iyzico` | The provider executes recurring billing; the package reflects state from provider results |
| Package-managed | `paytr` | The package dispatches and executes renewal, retry, and suspension work |

This distinction affects:

- whether `processRenewals()` dispatches work
- whether recurring transactions are born from command-driven charging or webhook-driven results
- where billing truth originates during normal operation

## Core Billing Entities

The main billing-side records are:

- `Plan`
  Commercial definition of price, currency, interval, features, and limits.
- `Subscription`
  The main lifecycle record linking a billable subject to a plan and provider.
- `Transaction`
  A billable event such as renewal, metered usage charge, or refund-related state.
- `PaymentMethod`
  Provider-owned payment tokens associated with the billable model.
- `SubscriptionItem`
  Quantity-aware line item used for seat-based subscriptions.
- `ScheduledPlanChange`
  Deferred upgrade or downgrade record.
- `Coupon`
  Reusable discount campaign definition.
- `Discount`
  Applied discount instance for a specific subscription.
- `Invoice`
  Post-payment billing artifact generated from successful transactions.

See [Data Model](DATA-MODEL.md) for persistence details.

## Subscription Lifecycle

The package normalizes subscription status through `SubscriptionStatus`.

Current normalized states are:

- `pending`
- `active`
- `past_due`
- `paused`
- `suspended`
- `failed`
- `cancelled`

Important transitions:

- `pending -> active`
- `active -> past_due`
- `past_due -> active`
- `past_due -> suspended`
- `active -> cancelled`
- `active -> paused`
- `paused -> active`

The package enforces allowed transitions through `Subscription::transitionTo()`.

## Renewal Flow

Renewal processing starts with `subguard:process-renewals`.

The command:

- resolves a cutoff date
- asks `SubscriptionService::processRenewals()` for due subscriptions
- dispatches only self-managed subscriptions

The self-managed renewal path is:

1. `ProcessRenewalCandidateJob` locks the subscription
2. it skips deleted, non-due, or provider-managed subscriptions
3. it creates or reuses an idempotent renewal transaction key
4. it resolves any applicable discount for the cycle
5. it dispatches `PaymentChargeJob`

`PaymentChargeJob` then:

- locks the transaction
- marks it `processing`
- calls `chargeRecurring()` on the provider adapter
- marks success as `processed`
- advances `next_billing_date`
- dispatches `PaymentCompleted` and `SubscriptionRenewed`
- queues `payment.completed` notifications

## Provider-Managed Renewal Mapping

For provider-managed subscriptions, recurring billing does not start with `subguard:process-renewals`.

Instead:

1. the provider charges remotely
2. webhook or callback intake stores a `WebhookCall`
3. `FinalizeWebhookEventJob` validates and normalizes the provider payload
4. `SubscriptionService::handleWebhookResult()` records the billing outcome locally
5. generic and provider-specific events are emitted

This is why iyzico renewals are primarily state-reflection workflows, not locally executed recurring charges.

## Dunning and Failure Flow

When a self-managed recurring charge fails:

- the transaction becomes `failed`
- `retry_count` increments
- `next_retry_at` is scheduled unless the failure is terminal
- the subscription moves to `past_due`
- `grace_ends_at` is set if it was not already set
- `payment.failed` notifications are queued

Retry dispatch starts with `subguard:process-dunning`.

That command selects failed or retrying transactions that are due and dispatches `ProcessDunningRetryJob`.

`ProcessDunningRetryJob`:

- takes a lock on the transaction
- checks whether max retries were reached
- either re-dispatches `PaymentChargeJob`
- or triggers dunning exhaustion

When dunning is exhausted:

- `next_retry_at` is cleared
- the subscription becomes `suspended`
- the linked license is also suspended when present
- `DunningExhausted` is emitted
- `dunning.exhausted` notifications are queued

`subguard:suspend-overdue` is the explicit grace-period suspension command for past-due subscriptions whose `grace_ends_at` has passed.

## Scheduled Plan Changes

Upgrades and downgrades are modeled through `ScheduledPlanChange`.

`SubscriptionService::upgrade()`:

- validates the requested mode
- creates a `ScheduledPlanChange`
- links it to the subscription through `scheduled_change_id`

`subguard:process-plan-changes` dispatches due plan-change jobs.

`ProcessScheduledPlanChangeJob`:

- locks the scheduled change and subscription
- verifies both still exist
- moves the subscription to the target plan
- copies plan pricing and billing metadata when the target plan exists
- clears `scheduled_change_id`
- marks the change `processed`
- queues `plan-change.processed` notifications

Current plan changes are local billing mutations. Provider-specific remote upgrade semantics are documented separately where relevant.

## Discounts and Coupons

Discount application is handled by `DiscountService`.

Current rules include:

- coupon must be active and inside its date window
- coupon currency must match subscription currency when both are set
- minimum purchase amount must be satisfied
- max uses and per-user usage limits are enforced
- duplicate coupon application on the same subscription is rejected
- applicability can be scoped to:
  - all subscriptions
  - a plan
  - a provider

Discounts are intentionally modeled as separate persisted records.

Renewal-time discount resolution:

- is skipped for provider-managed billing
- is applied for self-managed billing
- can support once, forever, or repeating durations
- is marked consumed only after a successful charge

## Metered Billing

Metered usage is processed through `MeteredBillingProcessor` and the `subguard:process-metered-billing` command.

The processor:

- finds active subscriptions with a linked license and a due billing date
- computes a period window
- sums unbilled `LicenseUsage` rows for that period
- reads `metered_price_per_unit` from subscription metadata
- creates an idempotent `metered_usage` transaction
- optionally charges the provider for self-managed billing
- marks included usage rows with `billed_at`
- advances the subscription billing period

Key behavior:

- already processed periods are protected by transaction idempotency
- usage rows are protected from double billing through `billed_at`
- provider-managed billing can still record local metered usage charges without a direct provider charge call

## Seat Management

Seat quantity changes are handled by `SeatManager`.

`SeatManager` currently:

- adjusts `SubscriptionItem.quantity`
- calculates remaining-period proration for seat changes
- stores seat metadata on the subscription
- synchronizes the linked license's `limit_overrides['seats']`

This means seat changes affect both billing context and license enforcement context.

## Invoices and Notifications

`DispatchBillingNotificationsJob` is the current bridge from billing outcomes to customer-facing artifacts.

Current supported side effects include:

- generating `Invoice` rows on `payment.completed`
- rendering invoice PDFs
- dispatching `InvoicePaidNotification`
- dispatching `SubscriptionCancelledNotification`
- logging billing or webhook notification events by channel

Not every billing event currently has a dedicated notification implementation, but the notification job is already the canonical hook point.

## Related Documents

Use the adjacent references when you need more detail:

- [Architecture](ARCHITECTURE.md)
- [Domain Providers](DOMAIN-PROVIDERS.md)
- [Data Model](DATA-MODEL.md)
- [Events And Jobs](EVENTS-AND-JOBS.md)
- [Recipes](RECIPES.md)
