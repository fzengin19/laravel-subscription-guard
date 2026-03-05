# Laravel Subscription Guard

Laravel Subscription Guard is a modular package for subscription billing, payment provider integration (iyzico + PayTR), and license lifecycle management for SaaS-style products.

## What It Provides

- Provider adapters for iyzico and PayTR
- Central subscription orchestration service
- Webhook intake + idempotent finalization
- License lifecycle bridge (create, active, past_due, cancelled)
- Dunning and retry processing commands/jobs
- Metered billing processor
- Phase 5 DX tools including webhook simulation command

## Installation

```bash
composer require fzengin19/laravel-subscription-guard
php artisan vendor:publish --tag="laravel-subscription-guard-config"
php artisan vendor:publish --tag="laravel-subscription-guard-migrations"
php artisan migrate
```

## Core Commands

```bash
php artisan subguard:process-renewals
php artisan subguard:process-dunning
php artisan subguard:suspend-overdue
php artisan subguard:process-metered-billing
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
php artisan subguard:sync-license-revocations
php artisan subguard:sync-license-heartbeats
```

## Queue Topology

Default queues are isolated by concern:

- Billing jobs: `subguard-billing`
- Webhook finalization jobs: `subguard-webhooks`
- Notifications: `subguard-notifications`

Queue names are configurable under `subscription-guard.queue`.

## Provider Model

- `iyzico`: `manages_own_billing = true`
- `paytr`: `manages_own_billing = false`

For details, see `docs/PROVIDERS.md`.

## Critical Installment Rule (TR Market)

**BANK INSTALLMENT ON SUBSCRIPTION APIS IS A SINGLE CHARGE SPLIT BY THE BANK, NOT A MONTHLY PARTIAL COLLECTION BY YOUR APP.**

**IF YOU NEED TRUE MANUAL INSTALLMENT COLLECTION OVER MULTIPLE PERIODS, MODEL IT AS YOUR OWN SCHEDULED CHARGE FLOW (PLAN/LEDGER/RETRY), NOT AS PROVIDER BANK INSTALLMENT FLAG ONLY.**

This distinction must be explicit in product, support, and accounting workflows.

## Testing

```bash
composer test
composer analyse
composer format -- --test
```

## Documentation

- `docs/plans/master-plan.md`
- `docs/plans/phase-5-integration-testing/plan.md`
- `docs/PROVIDERS.md`
- `docs/RECIPES.md`

## License

MIT
