# Troubleshooting

Use this document to diagnose common issues with the package.

## Webhook Issues

### Webhooks return 403 or 422

**Cause**: Signature validation failure.

Check:
1. Provider credentials are set correctly in `.env`
2. The signature header name matches the provider config (`signature_header`)
3. Payload has not been modified by middleware or a reverse proxy
4. Mock mode is not accidentally disabled when you need it for local testing

Diagnostic:
```bash
# Test with simulated webhook
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

### Webhooks return 200 but nothing happens

**Cause**: The webhook was accepted but finalization did not run.

Check:
1. Queue workers are running for `subguard-webhooks`
2. `FinalizeWebhookEventJob` is not stuck or failing silently
3. Check the `webhook_calls` table for the record status

Diagnostic:
```bash
php artisan queue:work --queue=subguard-webhooks --once
```

### Duplicate webhook deliveries

**Cause**: Normal provider behavior — providers often retry.

The package handles this via idempotency on `provider` + `event_id`. Duplicate webhooks are accepted (HTTP 200) but not re-finalized. No action needed.

### Webhook rate limit hit (429)

**Cause**: More than 120 webhook requests per minute from the same source.

Adjust in config:
```php
'webhooks' => [
    'rate_limit' => [
        'max_attempts' => 240,  // increase if needed
        'decay_minutes' => 1,
    ],
],
```

## Billing Issues

### Renewals not processing

**Cause**: The renewal command is not running or conditions are not met.

Check:
1. `subguard:process-renewals` is scheduled in your Kernel
2. The subscription's `next_billing_date` is in the past
3. The subscription status is `active` or `trialing`
4. The provider is self-managed (`manages_own_billing = false`)
5. Queue workers are running for `subguard-billing`

Diagnostic:
```bash
php artisan subguard:process-renewals --date="2026-12-31"
```

### Dunning retries not firing

**Cause**: No failed transactions with a due `next_retry_at`.

Check:
1. `subguard:process-dunning` is scheduled
2. Failed transactions exist with `next_retry_at <= now`
3. `retry_count < max_dunning_retries` (default: 3)

### Subscription stuck in past_due

**Cause**: Grace period has not expired yet, or `subguard:suspend-overdue` is not scheduled.

Check:
1. `grace_ends_at` value on the subscription
2. `subguard:suspend-overdue` is in the scheduler
3. Default grace period is 7 days — subscription will stay `past_due` until then

### Metered billing not charging

**Cause**: Prerequisites not met.

Check:
1. Subscription is `active` with a linked `license_id`
2. `next_billing_date` is in the past
3. Subscription metadata has `metered_price_per_unit > 0`
4. `LicenseUsage` rows exist with `billed_at = null` in the current period

## License Issues

### License validation fails with "expired"

**Cause**: The license's `exp` timestamp has passed.

Check the `expires_at` field on the `License` record. Generate a new license if needed:
```bash
php artisan subguard:generate-license {plan_id} {user_id}
```

### License validation fails with "revoked"

**Cause**: The license ID is in the revocation store.

Check whether the revocation is intentional. Sync the latest revocation state:
```bash
php artisan subguard:sync-license-revocations
```

### License validation fails with "stale heartbeat"

**Cause**: The offline heartbeat has not been refreshed within the allowed staleness window.

Sync heartbeats:
```bash
php artisan subguard:sync-license-heartbeats
```

Or trigger online validation to refresh the heartbeat automatically.

Default max stale window: `604800` seconds (7 days), configurable via `SUBGUARD_LICENSE_HEARTBEAT_MAX_STALE_SECONDS`.

### License activation fails

**Cause**: Max activations reached, license not valid, or domain mismatch.

Check:
1. License status is `active` or `trialing`
2. `current_activations < max_activations`
3. If a domain is already bound, the new activation domain must match

## Queue Issues

### Jobs stuck in queue

**Cause**: No workers running for the specific queue.

Each queue needs its own worker or a combined worker:
```bash
# Separate workers (recommended)
php artisan queue:work --queue=subguard-billing
php artisan queue:work --queue=subguard-webhooks
php artisan queue:work --queue=subguard-notifications

# Combined worker
php artisan queue:work --queue=subguard-webhooks,subguard-billing,subguard-notifications
```

### Job fails with "Could not acquire lock"

**Cause**: Another instance of the same job is running, or a previous lock was not released.

The package uses cache locks with 30-second TTL. If a lock is stuck, it will auto-expire. Wait 30 seconds and retry, or clear the specific cache key:
```bash
php artisan tinker
>>> cache()->forget('subguard:renewal:123')
```

## Configuration Issues

### "Mock mode is active in production" log warning

**Cause**: `IYZICO_MOCK=true` or `PAYTR_MOCK=true` in a production environment.

This is a critical security issue — signature validation is bypassed. Set mock to `false` in production:
```env
IYZICO_MOCK=false
PAYTR_MOCK=false
```

### Missing sodium extension

**Cause**: License signing requires `ext-sodium`.

Install the PHP sodium extension:
```bash
# Ubuntu/Debian
sudo apt install php-sodium

# Or verify it's loaded
php -m | grep sodium
```

## Logging

The package writes to three dedicated log channels:

| Channel | Purpose |
|---|---|
| `subguard_payments` | Billing events, charge results, dunning |
| `subguard_webhooks` | Webhook intake, finalization, events |
| `subguard_licenses` | License operations |

Configure these channels in your `config/logging.php`:
```php
'channels' => [
    'subguard_payments' => [
        'driver' => 'daily',
        'path' => storage_path('logs/subguard-payments.log'),
    ],
    'subguard_webhooks' => [
        'driver' => 'daily',
        'path' => storage_path('logs/subguard-webhooks.log'),
    ],
    'subguard_licenses' => [
        'driver' => 'daily',
        'path' => storage_path('logs/subguard-licenses.log'),
    ],
],
```

## Related Documents

- [Commands](COMMANDS.md)
- [Queues And Jobs](QUEUES-AND-JOBS.md)
- [Configuration](CONFIGURATION.md)
- [Security](SECURITY.md)
- [Dunning And Retries](DUNNING-AND-RETRIES.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
