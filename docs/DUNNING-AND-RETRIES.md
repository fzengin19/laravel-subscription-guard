# Dunning and Retries

Use this document to understand how the package handles recurring payment failures, retry scheduling, grace periods, and automatic suspension.

## Scope

Dunning applies only to **self-managed (package-managed) billing**. When `manages_own_billing` is `true` for a provider (e.g., Iyzico), the provider handles its own retry logic externally, and the package reflects the result via webhooks.

For self-managed providers (e.g., PayTR), the package owns the full dunning lifecycle:

1. Detect payment failure
2. Set grace period
3. Schedule retries
4. Exhaust retries and suspend

## Configuration

| Config Key | Env Variable | Default | Purpose |
|---|---|---|---|
| `billing.grace_period_days` | `SUBGUARD_GRACE_PERIOD_DAYS` | `7` | Days before a past-due subscription becomes eligible for suspension |
| `billing.max_dunning_retries` | `SUBGUARD_MAX_DUNNING_RETRIES` | `3` | Maximum retry attempts before dunning exhaustion |
| `billing.dunning_retry_interval_days` | `SUBGUARD_DUNNING_RETRY_INTERVAL_DAYS` | `2` | Days between retry attempts |

## Commands

| Command | Purpose |
|---|---|
| `subguard:process-dunning` | Dispatch retry jobs for failed or retrying transactions that are due |
| `subguard:suspend-overdue` | Suspend past-due subscriptions whose grace period has expired |

Both commands accept `--date=` to override the cutoff time (defaults to `now()`).

## Payment Failure Flow

When `PaymentChargeJob` processes a recurring charge and the provider returns a failure:

1. The transaction is marked `failed`
2. `retry_count` increments
3. `next_retry_at` is set to `now() + dunning_retry_interval_days`
4. The subscription transitions to `past_due`
5. `grace_ends_at` is set to `now() + grace_period_days` (only on the first failure in a cycle)
6. A `payment.failed` notification is dispatched via `DispatchBillingNotificationsJob`

At this point, the subscription remains accessible to the user during the grace period, but the billing state signals that action is needed.

## Retry Flow

The retry cycle is driven by the scheduler calling `subguard:process-dunning`:

```
Scheduler → subguard:process-dunning
  → SubscriptionService::processDunning()
    → selects transactions with status failed/retrying and next_retry_at <= now
    → dispatches ProcessDunningRetryJob for each
```

`ProcessDunningRetryJob` then:

1. Acquires a cache lock (`subguard:dunning:{transactionId}`, 30s TTL)
2. Locks the transaction row for update
3. Validates the transaction is still `failed` or `retrying`
4. Checks `retry_count` against `max_dunning_retries`
   - **Below max**: marks transaction as `retrying`, dispatches `PaymentChargeJob`
   - **At or above max**: triggers dunning exhaustion

If the retry charge succeeds, `PaymentChargeJob` marks the transaction `processed`, advances `next_billing_date`, and dispatches `PaymentCompleted` and `SubscriptionRenewed` events. The subscription returns to `active`.

If the retry charge fails again, the same failure flow repeats: `retry_count` increments, `next_retry_at` is rescheduled.

## Dunning Exhaustion

When `retry_count >= max_dunning_retries`:

1. `next_retry_at` is cleared (no more retries)
2. The subscription status becomes `suspended`
3. The linked license (if any) is also set to `suspended`
4. A `DunningExhausted` event is dispatched with:
   - `provider`
   - `subscriptionId`
   - `transactionId`
   - `retryCount`
   - `lastFailureReason`
5. A `dunning.exhausted` notification is dispatched
6. The exhaustion is logged to the `subguard_payments` channel

## Grace Period Suspension

Independently of the retry cycle, `subguard:suspend-overdue` handles grace period enforcement:

1. Selects subscriptions where `status = past_due` and `grace_ends_at <= now`
2. For each, within a database transaction:
   - Locks the subscription row
   - Sets status to `suspended`
   - Suspends the linked license if present
   - Dispatches a `subscription.suspended` notification

This command is a safety net: even if dunning retries are still in progress, a subscription whose grace period has expired will be suspended.

## Concurrency Safety

Both retry and suspension flows use pessimistic locking:

- `ProcessDunningRetryJob` uses a cache lock + `lockForUpdate()` on the transaction
- `SuspendOverdueCommand` uses `lockForUpdate()` on the subscription and license
- `PaymentChargeJob` uses a cache lock + `lockForUpdate()` on the transaction

This prevents duplicate charges and race conditions between the dunning and suspension paths.

## Subscription State Transitions

```
active → past_due (first payment failure)
past_due → active (successful retry)
past_due → suspended (grace period expired OR dunning exhausted)
```

## Events

| Event | When |
|---|---|
| `PaymentFailed` | A charge attempt fails |
| `DunningExhausted` | All retry attempts used, subscription suspended |

## Notifications

| Notification Key | When |
|---|---|
| `payment.failed` | Each failed charge attempt |
| `dunning.exhausted` | Retry limit reached |
| `subscription.suspended` | Grace period expired |

## Scheduling Recommendation

Run these commands on a schedule for continuous dunning operation:

```php
// In your application's Console Kernel or routes/console.php
$schedule->command('subguard:process-dunning')->hourly();
$schedule->command('subguard:suspend-overdue')->daily();
```

## Related Documents

- [Domain Billing](DOMAIN-BILLING.md)
- [Events and Jobs](EVENTS-AND-JOBS.md)
- [Commands](COMMANDS.md)
- [Queues And Jobs](QUEUES-AND-JOBS.md)
- [Configuration](CONFIGURATION.md)
