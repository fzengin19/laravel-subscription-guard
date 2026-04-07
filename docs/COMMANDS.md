# Commands

Use this document as the complete reference for all artisan commands provided by the package.

## Command Index

| Command | Category | Purpose |
|---|---|---|
| `subguard:install` | Setup | Verify core setup and show next steps |
| `subguard:process-renewals` | Billing | Dispatch due self-managed subscription renewals |
| `subguard:process-dunning` | Billing | Dispatch due dunning retry jobs |
| `subguard:suspend-overdue` | Billing | Suspend past-due subscriptions after grace period |
| `subguard:process-plan-changes` | Billing | Dispatch due scheduled plan change jobs |
| `subguard:process-metered-billing` | Billing | Process metered usage billing and reset periods |
| `subguard:simulate-webhook` | Testing | Simulate provider webhook requests locally |
| `subguard:sync-plans` | Provider | Sync plan references from Iyzico |
| `subguard:reconcile-iyzico-subscriptions` | Provider | Reconcile local state with Iyzico remote |
| `subguard:generate-license` | Licensing | Generate a signed license key |
| `subguard:check-license` | Licensing | Validate a license key and print diagnostics |
| `subguard:sync-license-revocations` | Licensing | Sync revocation snapshot/delta from remote endpoint |
| `subguard:sync-license-heartbeats` | Licensing | Refresh heartbeat cache from persisted licenses |

## Setup Commands

### subguard:install

```bash
php artisan subguard:install
```

Verifies the package is registered and displays next steps: publish config, run migrations, configure providers, register scheduler commands. No arguments or options.

## Billing Commands

### subguard:process-renewals

```bash
php artisan subguard:process-renewals
php artisan subguard:process-renewals --date="2026-04-01 00:00:00"
```

Dispatches `ProcessRenewalCandidateJob` for each self-managed subscription with a due `next_billing_date`. Provider-managed subscriptions are skipped.

| Option | Default | Purpose |
|---|---|---|
| `--date` | `now()` | Process subscriptions due until this date-time |

### subguard:process-dunning

```bash
php artisan subguard:process-dunning
php artisan subguard:process-dunning --date="2026-04-01 00:00:00"
```

Dispatches `ProcessDunningRetryJob` for failed or retrying transactions with a due `next_retry_at`. See [Dunning And Retries](DUNNING-AND-RETRIES.md) for the full retry flow.

| Option | Default | Purpose |
|---|---|---|
| `--date` | `now()` | Process retries due until this date-time |

### subguard:suspend-overdue

```bash
php artisan subguard:suspend-overdue
php artisan subguard:suspend-overdue --date="2026-04-01 00:00:00"
```

Suspends `past_due` subscriptions whose `grace_ends_at` has passed. Also suspends the linked license. See [Dunning And Retries](DUNNING-AND-RETRIES.md).

| Option | Default | Purpose |
|---|---|---|
| `--date` | `now()` | Suspend where grace period ended before this date-time |

### subguard:process-plan-changes

```bash
php artisan subguard:process-plan-changes
php artisan subguard:process-plan-changes --date="2026-04-01 00:00:00"
```

Dispatches `ProcessScheduledPlanChangeJob` for pending scheduled plan changes with a due `scheduled_at`.

| Option | Default | Purpose |
|---|---|---|
| `--date` | `now()` | Process changes due until this date-time |

### subguard:process-metered-billing

```bash
php artisan subguard:process-metered-billing
php artisan subguard:process-metered-billing --date="2026-04-01 00:00:00"
```

Processes metered usage billing for active subscriptions with a linked license. See [Metered Billing](METERED-BILLING.md).

| Option | Default | Purpose |
|---|---|---|
| `--date` | `now()` | Process usage due until this date-time |

## Testing Commands

### subguard:simulate-webhook

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
php artisan subguard:simulate-webhook paytr payment.fail --amount=25.00
php artisan subguard:simulate-webhook iyzico payment.success --event-id=custom_123
```

Builds a synthetic webhook payload for the given provider and event, then sends it through the application's HTTP kernel to the webhook endpoint. Useful for local validation without real provider credentials.

| Argument | Required | Purpose |
|---|---|---|
| `provider` | Yes | `iyzico` or `paytr` |
| `event` | Yes | Event type (e.g., `payment.success`, `payment.fail`) |

| Option | Default | Purpose |
|---|---|---|
| `--event-id` | Auto-generated UUID | Explicit event ID for idempotency |
| `--subscription-id` | Auto-generated | Provider subscription reference |
| `--transaction-id` | Auto-generated | Provider transaction reference |
| `--amount` | `10.00` | Amount in major currency unit |

The command generates a valid signature using the configured provider credentials (merchant_key/salt for PayTR, secret_key for Iyzico). If credentials are empty, the payload is sent without a signature.

Output includes: `provider`, `event`, `event_id`, HTTP `status`, and response body.

## Provider Commands

### subguard:sync-plans

```bash
php artisan subguard:sync-plans
php artisan subguard:sync-plans --provider=iyzico --dry-run
php artisan subguard:sync-plans --remote --force
```

Synchronizes plan references from the provider. Currently supports Iyzico only.

| Option | Default | Purpose |
|---|---|---|
| `--provider` | `iyzico` | Provider to sync plans for |
| `--dry-run` | `false` | Show planned changes without applying |
| `--force` | `false` | Refresh existing plan references |
| `--remote` | `false` | Force remote Iyzico API synchronization |

### subguard:reconcile-iyzico-subscriptions

```bash
php artisan subguard:reconcile-iyzico-subscriptions
php artisan subguard:reconcile-iyzico-subscriptions --dry-run
php artisan subguard:reconcile-iyzico-subscriptions --remote
```

Reconciles local subscription state with Iyzico's remote state. Identifies discrepancies and optionally corrects them.

| Option | Default | Purpose |
|---|---|---|
| `--dry-run` | `false` | Show reconciliation actions only |
| `--remote` | `false` | Force remote Iyzico status pull |

## Licensing Commands

### subguard:generate-license

```bash
php artisan subguard:generate-license 1 42
php artisan subguard:generate-license 1 42 --domain=app.example.com
```

Generates a signed license key for a plan and owner. Optionally activates it against a domain.

| Argument | Required | Purpose |
|---|---|---|
| `plan_id` | Yes | Plan identifier |
| `user_id` | Yes | Owner/user identifier |

| Option | Default | Purpose |
|---|---|---|
| `--domain` | None | Optional activation domain |

Output includes: `license_key` and activation status (if domain provided).

### subguard:check-license

```bash
php artisan subguard:check-license "SG.eyJ2IjoiMSIs..."
```

Validates a signed license key and prints diagnostic details: validity, reason, and payload metadata.

| Argument | Required | Purpose |
|---|---|---|
| `license_key` | Yes | The signed license key to validate |

Exit code: `0` for valid, `1` for invalid.

### subguard:sync-license-revocations

```bash
php artisan subguard:sync-license-revocations
php artisan subguard:sync-license-revocations --endpoint=https://api.example.com/revocations
php artisan subguard:sync-license-revocations --token=bearer_token_here --timeout=30
```

Fetches revocation data from a remote endpoint and applies it to the local cache store. Supports full snapshots and sequential deltas with automatic fallback. See [Domain Licensing](DOMAIN-LICENSING.md) for revocation store details.

| Option | Default | Purpose |
|---|---|---|
| `--endpoint` | Config value | Revocation sync endpoint URL |
| `--token` | Config value | Optional bearer token |
| `--timeout` | `10` seconds | Request timeout |

Security: HTTPS required in production. Private/reserved IP ranges are blocked (SSRF protection).

### subguard:sync-license-heartbeats

```bash
php artisan subguard:sync-license-heartbeats
php artisan subguard:sync-license-heartbeats --batch=1000 --statuses=active
```

Refreshes heartbeat cache entries from persisted `License` records. Verifies each license key signature before updating the cache.

| Option | Default | Purpose |
|---|---|---|
| `--batch` | `500` | Max license rows to scan |
| `--statuses` | `active,trialing` | Comma-separated statuses to include |

## Scheduling Recommendations

```php
// In routes/console.php or Console Kernel

use Illuminate\Support\Facades\Schedule;

Schedule::command('subguard:process-renewals')->hourly();
Schedule::command('subguard:process-dunning')->hourly();
Schedule::command('subguard:suspend-overdue')->daily();
Schedule::command('subguard:process-plan-changes')->hourly();
Schedule::command('subguard:process-metered-billing')->daily();
Schedule::command('subguard:sync-license-heartbeats')->daily();
Schedule::command('subguard:sync-license-revocations')->everyFourHours();
```

These are recommendations. Adjust frequencies based on your billing volume and operational requirements.

## Related Documents

- [Domain Billing](DOMAIN-BILLING.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
- [Dunning And Retries](DUNNING-AND-RETRIES.md)
- [Metered Billing](METERED-BILLING.md)
- [Queues And Jobs](QUEUES-AND-JOBS.md)
- [Configuration](CONFIGURATION.md)
- [API](API.md)
