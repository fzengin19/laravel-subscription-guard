# FAQ

Common questions about Laravel Subscription Guard.

## General

### What billing providers are supported?

Iyzico and PayTR. You can also write custom providers by implementing `PaymentProviderInterface`. See [Custom Provider](providers/CUSTOM-PROVIDER.md).

### What is the difference between provider-managed and self-managed billing?

- **Provider-managed** (Iyzico): The provider executes recurring charges. The package reflects state from webhooks.
- **Self-managed** (PayTR): The package executes recurring charges, retries, and suspension. The provider is called for individual charge operations.

See [Domain Providers](DOMAIN-PROVIDERS.md) for details.

### Does the package handle 3DS / Checkout redirects?

Yes. The `PaymentCallbackController` handles provider redirects after 3DS or checkout flows. See [Callbacks](CALLBACKS.md).

### Can I use both Iyzico and PayTR at the same time?

Yes. Each subscription is linked to a specific provider. The package routes operations to the correct provider adapter based on the subscription's `provider` field.

## Billing

### How does renewal work?

For self-managed providers, schedule `subguard:process-renewals` in your Kernel. It dispatches `ProcessRenewalCandidateJob` for due subscriptions, which creates a transaction and charges via `PaymentChargeJob`. See [Domain Billing](DOMAIN-BILLING.md).

### What happens when a payment fails?

The subscription moves to `past_due`, a grace period is set, and retries are scheduled. After exhausting retries, the subscription and license are suspended. See [Dunning And Retries](DUNNING-AND-RETRIES.md).

### How do I change the grace period or retry count?

Set these in your `.env`:

```env
SUBGUARD_GRACE_PERIOD_DAYS=14
SUBGUARD_MAX_DUNNING_RETRIES=5
SUBGUARD_DUNNING_RETRY_INTERVAL_DAYS=3
```

### Does the package support plan upgrades/downgrades?

Yes. Scheduled plan changes are modeled through `ScheduledPlanChange` and processed by `subguard:process-plan-changes`. See [Domain Billing](DOMAIN-BILLING.md).

### Does the package calculate taxes?

No. Tax amounts come from the transaction data. There is no built-in tax calculation engine. You are responsible for setting tax amounts when creating subscriptions.

## Licensing

### How are license keys signed?

With Ed25519 via `ext-sodium`. Keys contain a version prefix, base64 payload, and detached signature. See [Domain Licensing](DOMAIN-LICENSING.md).

### Can licenses work offline?

Yes, with constraints. Offline validation checks the signature and heartbeat freshness. If the heartbeat is older than the configured stale window (default: 7 days), validation fails. Schedule `subguard:sync-license-heartbeats` to keep heartbeats fresh.

### How do I revoke a license?

Use the revocation store. Apply a full snapshot or delta via `subguard:sync-license-revocations` from a remote endpoint, or interact with `LicenseRevocationStore` directly. See [Domain Licensing](DOMAIN-LICENSING.md).

### What is the license validation endpoint?

When enabled (default), the package registers a POST endpoint at `/subguard/licenses/validate`. It accepts a `license_key`, validates it, and refreshes the heartbeat on success. See [Domain Licensing](DOMAIN-LICENSING.md).

## Webhooks

### Do I need to set up webhook routes manually?

No. The package auto-registers webhook routes at `/{prefix}/{provider}` (default: `/subguard/webhooks/iyzico` and `/subguard/webhooks/paytr`). Set `webhooks.auto_register_routes` to `false` to disable. See [Webhooks](WEBHOOKS.md).

### How does the package prevent duplicate webhook processing?

Two layers: webhook deduplication by `provider` + `event_id`, and transaction idempotency by `idempotency_key`. Duplicate webhooks return 200 but are not re-finalized.

### How do I test webhooks locally?

Use the built-in simulator:

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

See [Commands](COMMANDS.md) for all options.

## Operations

### What queues do I need to run?

Three queues: `subguard-billing`, `subguard-webhooks`, `subguard-notifications`. See [Queues And Jobs](QUEUES-AND-JOBS.md) for worker configuration.

### What commands should I schedule?

At minimum:

```php
Schedule::command('subguard:process-renewals')->hourly();
Schedule::command('subguard:process-dunning')->hourly();
Schedule::command('subguard:suspend-overdue')->daily();
```

See [Commands](COMMANDS.md) for the full scheduling recommendation.

### How do I diagnose webhook issues?

Check `subguard_webhooks` log channel, verify credentials, and test with the simulator. See [Troubleshooting](TROUBLESHOOTING.md).

## Metered and Seat Billing

### How does metered billing work?

Record usage as `LicenseUsage` rows. Schedule `subguard:process-metered-billing` to aggregate and charge at period end. See [Metered Billing](METERED-BILLING.md).

### How do I add seats to a subscription?

Use `SeatManager::addSeat()`. This updates the subscription item quantity and syncs the license's seat limit. Note: seat changes are local only — no provider-side sync. See [Seat-Based Billing](SEAT-BASED-BILLING.md).

## Security

### Is mock mode safe for production?

No. Mock mode bypasses signature validation. Both providers log a `critical` warning if mock is enabled in production. Always set `IYZICO_MOCK=false` and `PAYTR_MOCK=false` in production.

### Does the package store card numbers?

No. The `SanitizesProviderData` trait strips card numbers, CVV, and other sensitive fields from all provider responses before storage. See [Security](SECURITY.md).

## Related Documents

- [Installation](INSTALLATION.md)
- [Quickstart](QUICKSTART.md)
- [Configuration](CONFIGURATION.md)
- [Troubleshooting](TROUBLESHOOTING.md)
