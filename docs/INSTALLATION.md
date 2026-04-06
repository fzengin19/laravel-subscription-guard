# Installation

Use this guide to install the package, publish its assets, bootstrap the runtime expectations, and validate a safe first local path.

## Requirements

- PHP 8.4+
- Laravel 11 or 12
- a Laravel-supported database connection
- a queue connection for asynchronous jobs

## 1. Install The Package

```bash
composer require fzengin19/laravel-subscription-guard
```

## 2. Publish Config And Migrations

```bash
php artisan vendor:publish --tag="laravel-subscription-guard-config"
php artisan vendor:publish --tag="laravel-subscription-guard-migrations"
php artisan migrate
```

After publishing:

- package config will live in `config/subscription-guard.php`
- package tables will be added through the published migrations

## 3. Check Baseline Runtime Assumptions

Before moving to real provider traffic, make sure the host app has:

- a working database connection
- a queue connection selected for billing and webhook jobs
- API routes enabled for package webhook and validation endpoints
- a plan for provider credentials outside the repository

The package auto-registers webhook routes by default under:

- `POST /subguard/webhooks/{provider}`
- `POST /subguard/webhooks/{provider}/3ds/callback`
- `POST /subguard/webhooks/{provider}/checkout/callback`

If you disable auto-registration, you must provide equivalent routing yourself.

## 4. Choose A Safe First Verification Path

The recommended first local validation path does not require live provider credentials.

Run:

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

This exercises the local webhook intake and finalization path using simulated provider events.

For a more guided first-success path, continue with [Quickstart](QUICKSTART.md).

## 5. Configure Workers

The package isolates work by concern.

Default queues:

- `subguard-billing`
- `subguard-webhooks`
- `subguard-notifications`

Example worker setup:

```bash
php artisan queue:work --queue=subguard-webhooks,subguard-billing,subguard-notifications
```

Queue names and connection can be changed in `subscription-guard.queue`.

## 6. Know The Provider Bootstrap Boundary

The package can be installed without immediately wiring real iyzico or PayTR credentials.

You only need real provider credentials when you start:

- live payment flows
- real provider callbacks
- or isolated live sandbox validation

Provider-specific setup is summarized in [Providers](PROVIDERS.md).

## 7. Live Sandbox Validation

The default test suite stays deterministic. Real iyzico sandbox traffic runs only through the dedicated live suite, and `composer test` does not discover `tests/Live/*`.

When you intentionally want real sandbox validation, export the required live variables in your shell or CI environment:

```bash
export RUN_IYZICO_LIVE_SANDBOX_TESTS=true
export IYZICO_MOCK=false
export IYZICO_API_KEY=...
export IYZICO_SECRET_KEY=...
export IYZICO_BASE_URL=https://sandbox-api.iyzipay.com
export IYZICO_CALLBACK_URL=https://<public-url>/subguard/payment/iyzico/callback
```

Then run:

```bash
composer test-live
```

Process environment values win. If live values are missing, the live gate may optionally use a user-managed fallback file through `.env.test` or `SUBGUARD_LIVE_ENV_FILE` without overwriting exported values. If required values are still missing, the live suite skips cleanly instead of silently falling back to mock traffic.

## 8. Next Reading

After installation, continue with:

- [Quickstart](QUICKSTART.md)
- [Configuration](CONFIGURATION.md)
- [API](API.md)
- [Recipes](RECIPES.md)
