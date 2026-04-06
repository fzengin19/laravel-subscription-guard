# Installation

## Requirements

- PHP 8.4+
- Laravel 11 or 12
- Database driver supported by Laravel
- Queue driver for background jobs

## Package Install

```bash
composer require fzengin19/laravel-subscription-guard
```

## Publish Config and Migrations

```bash
php artisan vendor:publish --tag="laravel-subscription-guard-config"
php artisan vendor:publish --tag="laravel-subscription-guard-migrations"
php artisan migrate
```

## Initial Verify

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

## Live Sandbox Test Setup

The default suite stays deterministic. Real iyzico sandbox traffic runs only through the dedicated live suite, and `composer test` does not discover `tests/Live/*`.

Export the required sandbox vars in your shell or CI environment when you want them to take precedence. Missing live values can fall back to a user-managed `.env.test` file (or a custom path provided via `SUBGUARD_LIVE_ENV_FILE`).

```bash
export RUN_IYZICO_LIVE_SANDBOX_TESTS=true
export IYZICO_MOCK=false
export IYZICO_API_KEY=...
export IYZICO_SECRET_KEY=...
export IYZICO_BASE_URL=https://sandbox-api.iyzipay.com
export IYZICO_CALLBACK_URL=https://<public-url>/subguard/payment/iyzico/callback
```

Run the isolated suite with:

```bash
composer test-live
```

Process environment values always win. If some live variables are still missing, the live gate may read them from `.env.test` or the path in `SUBGUARD_LIVE_ENV_FILE` without overwriting exported values. If the required values are still missing, the live suite skips cleanly instead of falling back to mock traffic.

## Worker Setup

Run dedicated workers for isolation:

- `subguard-billing`
- `subguard-webhooks`
- `subguard-notifications`

Example:

```bash
php artisan queue:work --queue=subguard-webhooks,subguard-billing,subguard-notifications
```

For live sandbox runs, prefer `QUEUE_CONNECTION=sync` unless you are explicitly testing multi-process queue behavior.
