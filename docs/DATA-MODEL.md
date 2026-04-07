# Data Model

Use this document to understand the package's persisted entities, how they relate to each other, and which tables carry the most operational importance.

This is a domain-level map, not a full schema dump.

## Persistence Groups

The package persistence layer currently falls into six groups:

1. catalog and commercial configuration
2. subscription and payment execution
3. webhook and callback intake
4. licensing and usage tracking
5. discounts and incentives
6. invoices and billing profiles

## Catalog and Commercial Configuration

### `plans`

Purpose:

- commercial plan definition
- price, currency, billing period, and interval
- signed license payload inputs through `features` and `limits`

Relations:

- has many `licenses`
- has many `subscriptions`

Important characteristics:

- unique `slug`
- `price` cast to float
- active flag in the persisted model

## Subscription and Payment Execution

### `subscriptions`

Purpose:

- primary recurring billing record
- binds a subscribable model to a plan and provider
- tracks lifecycle, billing dates, and provider identifiers

Relations:

- morphs to `subscribable`
- belongs to `plan`
- belongs to `license`
- has many `subscription_items`
- has many `transactions`
- has many `scheduled_plan_changes`
- belongs to the currently linked `scheduled_change`

Operational characteristics:

- soft deletes are enabled
- indexed by provider plus provider subscription id
- indexed by status
- indexed by next billing date

Important stored concerns:

- status and provider ownership
- `next_billing_date`
- `current_period_start`
- `current_period_end`
- `billing_anchor_day`
- grace, pause, resume, and cancellation timestamps
- schedule linkage through `scheduled_change_id`

### `subscription_items`

Purpose:

- quantity-aware subscription line items
- seat-based billing support

Relations:

- belongs to `subscription`
- belongs to `plan`

Operational characteristics:

- quantity and unit price are typed
- used by `SeatManager` for seat adjustments and proration calculations

### `transactions`

Purpose:

- canonical payment execution record
- tracks renewal, metered, retry, and refund-adjacent state

Relations:

- belongs to `subscription`
- has one `invoice`
- belongs to `license`
- morphs to `payable`
- belongs to `coupon`
- belongs to `discount`

Operational characteristics:

- soft deletes are enabled
- unique `idempotency_key`
- indexed by provider plus provider transaction id
- indexed by status
- indexed by subscription id

Important stored concerns:

- provider and provider transaction identifiers
- type and status
- retry counters and retry timestamps
- discount and coupon linkage
- tax and currency fields
- provider response payload

## Webhook and Callback Intake

### `webhook_calls`

Purpose:

- durable intake record for webhooks and callbacks
- duplicate protection and retry reopening

Relations:

- belongs to `transaction`
- belongs to `subscription`

Operational characteristics:

- unique constraint on provider plus event id
- indexed by status
- stores payload, headers, status, processed timestamp, and error message

This table is the main persistence layer for idempotent inbound event handling.

## Licensing and Usage Tracking

### `licenses`

Purpose:

- persisted representation of the signed license lifecycle

Relations:

- belongs to `plan`
- belongs to `owner`
- has many `license_activations`
- has many `license_usages`

Operational characteristics:

- soft deletes are enabled
- unique `key`
- indexed by status
- indexed by expires_at

Important stored concerns:

- lifecycle status
- expiration and grace windows
- heartbeat timestamp
- domain binding
- activation counts
- feature and limit overrides

### `license_activations`

Purpose:

- tracks domain-level activation and deactivation history

Relations:

- belongs to `license`

Operational characteristics:

- activation lookup index added for faster domain queries
- stores `activated_at` and `deactivated_at`

### `license_usages`

Purpose:

- usage ledger for feature limits and metered billing

Relations:

- belongs to `license`

Operational characteristics:

- indexed by license plus metric
- indexed by usage period
- indexed by billed state

Important stored concerns:

- metric name
- quantity
- period start and end
- `billed_at` to prevent double billing

## Discounts and Incentives

### `coupons`

Purpose:

- reusable commercial discount definition

Relations:

- has many `discounts`

Operational characteristics:

- unique `code`
- supports date windows, usage caps, and metadata-driven duration rules

### `discounts`

Purpose:

- applied discount instance bound to a specific discountable model

Relations:

- belongs to `coupon`
- morphs to `discountable`

Operational characteristics:

- stores applied amount, duration, cycle counters, and description
- currently used for subscription discount application and renewal-time discount resolution

## Invoices and Billing Profiles

### `invoices`

Purpose:

- customer-facing billing artifact generated from successful transactions

Relations:

- belongs to `transaction`
- morphs to `subscribable`

Operational characteristics:

- soft deletes are enabled
- unique `invoice_number`
- indexed by status
- stores PDF path and amount breakdown

### `billing_profiles`

Purpose:

- normalized billing metadata for a billable model

Relations:

- morphs to `billable`

Operational characteristics:

- soft deletes are enabled
- metadata-centric shape
- updated through `SyncBillingProfileJob`

### `payment_methods`

Purpose:

- stored provider-side payment method references

Relations:

- morphs to `payable`

Operational characteristics:

- soft deletes are enabled
- stores provider method id plus customer and card tokens
- supports default and active flags

## Scheduled Change Layer

### `scheduled_plan_changes`

Purpose:

- deferred plan upgrade or downgrade requests

Relations:

- belongs to `subscription`
- belongs to `from_plan`
- belongs to `to_plan`

Operational characteristics:

- indexed by scheduled time plus status
- stores processed timestamp, status, error message, and optional proration credit

## Relationship Summary

The most important cross-domain relationships are:

- `Plan -> Subscription`
- `Plan -> License`
- `Subscription -> Transaction`
- `Subscription -> License`
- `Subscription -> SubscriptionItem`
- `Subscription -> ScheduledPlanChange`
- `Transaction -> Invoice`
- `Transaction -> Coupon/Discount`
- `License -> LicenseActivation`
- `License -> LicenseUsage`
- `WebhookCall -> Subscription/Transaction`

## Operational Notes

These persistence decisions support the package's runtime guarantees:

- idempotent transaction creation through unique keys
- idempotent webhook intake through unique provider-event pairs
- soft-delete safety for financially relevant records
- queryable retry and suspension workflows
- usage-ledger backed metered billing
- persisted override support on top of signed license claims

## Related Documents

- [Architecture](ARCHITECTURE.md)
- [Domain Billing](DOMAIN-BILLING.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
- [Events And Jobs](EVENTS-AND-JOBS.md)
