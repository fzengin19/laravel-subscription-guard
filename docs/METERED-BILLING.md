# Metered Billing

Use this document to understand how the package collects, aggregates, and charges for usage-based billing on top of subscriptions.

## Concept

Metered billing tracks usage against a license and charges the subscriber at the end of each billing period. Unlike flat-rate subscriptions, the charge amount depends on actual consumption.

The package handles metered billing through:

- `LicenseUsage` rows to record consumption
- `MeteredBillingProcessor` to aggregate and charge
- `subguard:process-metered-billing` command to trigger processing

## Prerequisites

A subscription is eligible for metered billing when:

1. Subscription status is `active`
2. Subscription has a linked `license_id`
3. `next_billing_date` is at or before the processing date
4. Subscription metadata contains `metered_price_per_unit` with a value greater than zero

If any of these conditions are not met, the subscription is skipped during processing.

## Recording Usage

Usage is recorded as `LicenseUsage` rows with:

- `license_id` — the license being consumed against
- `quantity` — the amount of usage
- `period_start` — when this usage occurred (used for period filtering)
- `billed_at` — null until the usage has been billed

Usage rows are created by application code. The package provides the model and billing infrastructure, but the act of recording usage is the integrator's responsibility.

## Command

```bash
php artisan subguard:process-metered-billing
php artisan subguard:process-metered-billing --date="2026-04-01 00:00:00"
```

The `--date` option overrides the processing cutoff (defaults to `now()`).

## Processing Flow

`MeteredBillingProcessor::process()` runs the following for each eligible subscription:

### 1. Period Resolution

The billing period is determined from the subscription's `current_period_start` and `current_period_end` fields. If `current_period_start` is not set, it falls back based on `billing_period`:

| billing_period | Fallback Start |
|---|---|
| `week` / `weekly` | Start of the current week |
| `year` / `yearly` / `annual` | Start of the current year |
| default (monthly) | Start of the current month |

### 2. Usage Aggregation

The processor sums all `LicenseUsage` rows where:

- `license_id` matches the subscription's license
- `period_start` is within the billing period
- `billed_at` is null (not yet billed)

If total usage is zero or negative, the subscription is skipped.

### 3. Amount Calculation

```
amount = round(total_usage × metered_price_per_unit, 2)
```

The `metered_price_per_unit` is read from the subscription's `metadata` JSON field.

### 4. Idempotent Transaction Creation

A `metered_usage` transaction is created with an idempotency key:

```
subguard:metered:{subscription_id}:{period_end_utc_timestamp}
```

If a transaction with this key already exists and is `processed`, the subscription is skipped. This prevents double-billing for the same period.

### 5. Provider Charge

The charge behavior depends on the billing ownership model:

| Mode | Behavior |
|---|---|
| Self-managed (package-managed) | Calls `chargeRecurring()` on the provider adapter |
| Provider-managed | Records the transaction locally without a provider charge call |

If the provider charge fails for self-managed billing, the transaction is marked `failed` with an incremented `retry_count`.

### 6. Post-Charge Cleanup

On success:

- The transaction is marked `processed`
- All aggregated `LicenseUsage` rows are updated with `billed_at = now()`
- The subscription's `current_period_start` advances to the period end
- `current_period_end` and `next_billing_date` advance to the next period

## Period Advancement

The next period end is calculated based on `billing_period` and `billing_interval`:

| billing_period | Interval=1 Result |
|---|---|
| `weekly` | +1 week |
| `yearly` / `annual` | +1 year |
| default (monthly) | +1 month |

The `billing_interval` field controls the multiplier (e.g., `billing_interval=3` with monthly period = quarterly billing).

## Configuration

| Config Key | Default | Purpose |
|---|---|---|
| `billing.timezone` | `Europe/Istanbul` | Timezone for period calculations |
| `billing.metered_command` | `subguard:process-metered-billing` | Command name reference |

## Double-Billing Protection

Two mechanisms prevent duplicate charges:

1. **Transaction idempotency**: The period-based idempotency key ensures only one transaction per subscription per period
2. **Usage row marking**: `billed_at` is set after billing, so rows cannot be aggregated twice

## Concurrency Safety

The processor uses pessimistic locking:

- Subscription row is locked with `lockForUpdate()` before processing
- Usage rows are locked with `lockForUpdate()` before aggregation
- All mutations happen within a database transaction

## Limitations

- No tiered pricing (all usage is charged at a flat per-unit rate)
- No usage alerts or threshold notifications
- No mid-period billing triggers
- No usage caps or overage handling
- Price per unit is stored in subscription metadata, not in a dedicated pricing model
- Failed metered charges do not automatically enter the dunning flow

## Scheduling Recommendation

```php
$schedule->command('subguard:process-metered-billing')->daily();
```

## Related Documents

- [Domain Billing](DOMAIN-BILLING.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
- [Data Model](DATA-MODEL.md)
- [Configuration](CONFIGURATION.md)
