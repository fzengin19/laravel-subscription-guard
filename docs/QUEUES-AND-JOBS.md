# Queues and Jobs

Use this document to understand the package's queue topology, job inventory, concurrency controls, and configuration.

## Queue Topology

The package uses three isolated queues by default:

| Queue | Env Variable | Default | Purpose |
|---|---|---|---|
| Billing | `SUBGUARD_QUEUE` | `subguard-billing` | Renewal, dunning, plan change, and payment charge jobs |
| Webhooks | `SUBGUARD_WEBHOOKS_QUEUE` | `subguard-webhooks` | Webhook finalization jobs |
| Notifications | `SUBGUARD_NOTIFICATIONS_QUEUE` | `subguard-notifications` | Billing notifications, invoice generation, profile sync |

Queue connection is configured via `SUBGUARD_QUEUE_CONNECTION` (falls back to `QUEUE_CONNECTION`, then `database`).

## Why Separate Queues

Isolating queues prevents:

- webhook processing delays from blocking billing jobs
- notification volume from starving renewal processing
- a failed notification from retrying on the billing queue

Run separate workers per queue for best isolation:

```bash
php artisan queue:work --queue=subguard-billing
php artisan queue:work --queue=subguard-webhooks
php artisan queue:work --queue=subguard-notifications
```

Or combine with priority ordering:

```bash
php artisan queue:work --queue=subguard-webhooks,subguard-billing,subguard-notifications
```

## Job Inventory

### Billing Queue Jobs

| Job | Dispatched By | Purpose |
|---|---|---|
| `ProcessRenewalCandidateJob` | `subguard:process-renewals` | Lock subscription, resolve discount, create transaction, dispatch charge |
| `PaymentChargeJob` | `ProcessRenewalCandidateJob`, `ProcessDunningRetryJob` | Execute provider charge, update transaction/subscription state |
| `ProcessDunningRetryJob` | `subguard:process-dunning` | Check retry count, re-dispatch charge or trigger exhaustion |
| `ProcessScheduledPlanChangeJob` | `subguard:process-plan-changes` | Apply deferred plan upgrade/downgrade |

### Webhook Queue Jobs

| Job | Dispatched By | Purpose |
|---|---|---|
| `FinalizeWebhookEventJob` | `WebhookController` | Validate, normalize, and finalize webhook payloads |

### Notification Queue Jobs

| Job | Dispatched By | Purpose |
|---|---|---|
| `DispatchBillingNotificationsJob` | Various billing/webhook jobs | Generate invoices, dispatch notifications, log events |
| `SyncBillingProfileJob` | Application code | Sync billable model profile data |

## Job Flow Diagram

```
subguard:process-renewals
  └─ ProcessRenewalCandidateJob (billing queue)
       └─ PaymentChargeJob (billing queue)
            ├─ Success → DispatchBillingNotificationsJob (notifications queue)
            └─ Failure → next_retry_at scheduled
                           └─ subguard:process-dunning
                                └─ ProcessDunningRetryJob (billing queue)
                                     ├─ Below max → PaymentChargeJob (billing queue)
                                     └─ Exhausted → DispatchBillingNotificationsJob (notifications queue)

WebhookController (HTTP)
  └─ FinalizeWebhookEventJob (webhooks queue)
       └─ DispatchBillingNotificationsJob (notifications queue)
```

## Concurrency Controls

The package uses two layers of concurrency protection:

### Cache Locks

| Lock Key Pattern | TTL | Used By |
|---|---|---|
| `subguard:renewal:{subscriptionId}` | 30s | `ProcessRenewalCandidateJob` |
| `subguard:dunning:{transactionId}` | 30s | `ProcessDunningRetryJob` |
| `subguard:webhook:{webhookCallId}` | 30s | `FinalizeWebhookEventJob` |

If a lock cannot be acquired, the job silently returns (renewal/dunning) or releases back to the queue (`FinalizeWebhookEventJob` releases with a 5-second delay).

### Database Locks

All state-mutating jobs use `lockForUpdate()` within database transactions on the rows they modify. This prevents race conditions even when cache locks are lost due to TTL expiration.

### Lock Configuration

| Config Key | Env Variable | Default | Purpose |
|---|---|---|---|
| `locks.webhook_lock_ttl` | `SUBGUARD_WEBHOOK_LOCK_TTL` | `10` | Webhook intake lock TTL |
| `locks.webhook_block_timeout` | `SUBGUARD_WEBHOOK_BLOCK_TIMEOUT` | `5` | Webhook intake block wait |
| `locks.callback_lock_ttl` | `SUBGUARD_CALLBACK_LOCK_TTL` | `10` | Callback intake lock TTL |
| `locks.callback_block_timeout` | `SUBGUARD_CALLBACK_BLOCK_TIMEOUT` | `5` | Callback intake block wait |
| `locks.renewal_job_lock_ttl` | `SUBGUARD_RENEWAL_JOB_LOCK_TTL` | `30` | Renewal job lock TTL |
| `locks.dunning_job_lock_ttl` | `SUBGUARD_DUNNING_JOB_LOCK_TTL` | `30` | Dunning job lock TTL |

## Configuration

```php
// config/subscription-guard.php

'queue' => [
    'connection' => env('SUBGUARD_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('SUBGUARD_QUEUE', 'subguard-billing'),
    'webhooks_queue' => env('SUBGUARD_WEBHOOKS_QUEUE', 'subguard-webhooks'),
    'notifications_queue' => env('SUBGUARD_NOTIFICATIONS_QUEUE', 'subguard-notifications'),
],
```

## Failure Handling

- Payment charge failures are handled explicitly: the transaction is marked `failed`, retry metadata is set, and the dunning flow takes over.
- Webhook finalization failures release the job back to the queue with a delay.
- Notification job failures follow Laravel's default retry behavior.
- No jobs in the package use `$maxExceptions` or `$backoff` — failures are handled at the application level through dunning or queue worker configuration.

## Related Documents

- [Commands](COMMANDS.md)
- [Domain Billing](DOMAIN-BILLING.md)
- [Webhooks](WEBHOOKS.md)
- [Dunning And Retries](DUNNING-AND-RETRIES.md)
- [Configuration](CONFIGURATION.md)
