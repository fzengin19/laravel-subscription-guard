# Recipes

Practical integration recipes for common scenarios.

## Recipe: Simulate Webhook During Local Development

Goal: validate intake + idempotency + finalization pipeline without waiting for real provider callbacks.

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

Use deterministic event id for duplicate behavior testing:

```bash
php artisan subguard:simulate-webhook paytr payment.success --event-id=evt_duplicate_001
php artisan subguard:simulate-webhook paytr payment.success --event-id=evt_duplicate_001
```

Expected behavior:

- first call creates pending webhook record and dispatches finalizer
- second call is treated as duplicate/idempotent path

## Recipe: Invoice PDF Generation on Payment Completed

Payment completed notification flow creates/updates invoice records and writes PDF artifact path.

- Invoice model: `src/Models/Invoice.php`
- PDF renderer: `src/Billing/Invoices/InvoicePdfRenderer.php`
- Notification job: `src/Jobs/DispatchBillingNotificationsJob.php`

If `spatie/laravel-pdf` is available, renderer uses it; otherwise local fallback artifact is written for deterministic behavior.

## Recipe: E-Fatura Hook Baseline

Use generic billing events as integration hooks for e-fatura providers.

Recommended event hooks:

- `PaymentCompleted`
- `SubscriptionRenewed`
- `SubscriptionCancelled`

Implementation guideline:

1. Listen to generic event in your app layer.
2. Build e-fatura payload from transaction/subscription/invoice entities.
3. Persist provider response reference in your own audit table.
4. Keep retries idempotent by external reference key.

## Recipe: Dedicated Notification Queue

Notifications should run on isolated queue:

- config key: `subscription-guard.queue.notifications_queue`
- default queue: `subguard-notifications`

This keeps billing and webhook workloads independent from mail/database delivery latency.

## Recipe: Run iyzico Live Sandbox Suite

Export the sandbox vars you want to force from your shell or CI environment, then run the dedicated live suite. Any missing live values can fall back to `.env.test` or the file named by `SUBGUARD_LIVE_ENV_FILE`.

```bash
export RUN_IYZICO_LIVE_SANDBOX_TESTS=true
export IYZICO_MOCK=false
export IYZICO_API_KEY=...
export IYZICO_SECRET_KEY=...
export IYZICO_BASE_URL=https://sandbox-api.iyzipay.com
export IYZICO_CALLBACK_URL=https://<public-url>/subguard/payment/iyzico/callback
composer test-live
```

The suite is intentionally isolated from `composer test` and will skip instead of silently using mock traffic when env is incomplete. Exported process env wins; the fallback file only fills missing live values and never overwrites exported ones.

## Recipe: Tunnel-Assisted Webhook / Callback Validation

For operator-assisted iyzico callback and webhook roundtrip tests, expose your local Laravel app over a public HTTPS tunnel.

Example with ngrok:

```bash
php artisan serve --host=127.0.0.1 --port=8000
ngrok http 8000
```

Example with Laravel Expose:

```bash
php artisan serve --host=127.0.0.1 --port=8000
expose share http://127.0.0.1:8000
```

Then set:

```bash
IYZICO_CALLBACK_URL=https://<public-url>/subguard/payment/iyzico/callback
```

Use the public tunnel URL for operator-assisted tests that require real callback/webhook delivery. If the endpoint is unreachable, the live suite will skip those tests with an explicit reason.

## Related Documents

- [Use Cases](USE-CASES.md)
- [Commands](COMMANDS.md)
- [Invoicing](INVOICING.md)
- [Live Sandbox](LIVE-SANDBOX.md)
- [FAQ](FAQ.md)
