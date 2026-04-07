# Laravel Subscription Guard

Laravel Subscription Guard is a Laravel package for subscription billing, payment-provider integration, and license lifecycle management.

It combines:

- iyzico and PayTR provider adapters
- centralized billing orchestration
- webhook and callback intake
- license generation and validation
- feature and limit gating
- operational commands for renewals, dunning, plan changes, and license sync

## What The Package Covers

The current package surface includes:

- provider-managed and self-managed billing paths
- subscription creation, renewal, cancellation, and scheduled plan changes
- webhook persistence, idempotency, and async finalization
- 3DS and checkout callback intake
- license activation, revocation, heartbeat, and online validation
- seat-based and metered billing support
- invoice and notification pipeline hooks
- isolated iyzico live sandbox validation

## Provider Model

The package supports two billing ownership models:

- `iyzico`: provider-managed recurring billing, with local state updated from webhook and callback results
- `paytr`: package-managed recurring billing, with the package running renewal and retry orchestration

See [Providers](docs/PROVIDERS.md) for the current provider overview.

Provider-specific integration docs:

- [iyzico Provider](docs/providers/IYZICO.md)
- [PayTR Provider](docs/providers/PAYTR.md)

## Install And Start

Install the package:

```bash
composer require fzengin19/laravel-subscription-guard
php artisan vendor:publish --tag="laravel-subscription-guard-config"
php artisan vendor:publish --tag="laravel-subscription-guard-migrations"
php artisan migrate
```

Then continue with:

- [Installation Guide](docs/INSTALLATION.md)
- [Quickstart](docs/QUICKSTART.md)
- [Configuration Reference](docs/CONFIGURATION.md)

## Core Commands

The package currently exposes these core operational commands:

```bash
php artisan subguard:process-renewals
php artisan subguard:process-dunning
php artisan subguard:suspend-overdue
php artisan subguard:process-plan-changes
php artisan subguard:process-metered-billing
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
php artisan subguard:sync-license-revocations
php artisan subguard:sync-license-heartbeats
php artisan subguard:generate-license 1 1
php artisan subguard:check-license <signed-license-key>
```

Route and command surface summary lives in [API](docs/API.md).

## Queue Topology

Default queues are isolated by concern:

- billing jobs: `subguard-billing`
- webhook finalization jobs: `subguard-webhooks`
- notifications: `subguard-notifications`

Queue names are configurable under `subscription-guard.queue`.

## First Local Validation Path

For a safe local first-success path, start with install + migrate and then use simulated webhook intake:

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

That validates the local webhook intake and finalization path without requiring real provider credentials.

## Testing

```bash
composer test
composer test-live
composer analyse
composer format -- --test
```

- `composer test` runs the deterministic suite.
- `composer test-live` runs the isolated iyzico sandbox suite under `tests/Live`.

## Documentation

### Getting Started

- [Installation](docs/INSTALLATION.md)
- [Quickstart](docs/QUICKSTART.md)
- [Configuration](docs/CONFIGURATION.md)
- [FAQ](docs/FAQ.md)

### System Reference

- [Architecture](docs/ARCHITECTURE.md)
- [Domain Billing](docs/DOMAIN-BILLING.md)
- [Domain Licensing](docs/DOMAIN-LICENSING.md)
- [Domain Providers](docs/DOMAIN-PROVIDERS.md)
- [Data Model](docs/DATA-MODEL.md)
- [Events And Jobs](docs/EVENTS-AND-JOBS.md)

### Providers and Integration

- [Providers](docs/PROVIDERS.md)
- [iyzico Provider](docs/providers/IYZICO.md)
- [PayTR Provider](docs/providers/PAYTR.md)
- [Custom Provider](docs/providers/CUSTOM-PROVIDER.md)
- [API](docs/API.md)
- [Webhooks](docs/WEBHOOKS.md)
- [Callbacks](docs/CALLBACKS.md)
- [Licensing](docs/LICENSING.md)

### Billing and Licensing Flows

- [Dunning And Retries](docs/DUNNING-AND-RETRIES.md)
- [Metered Billing](docs/METERED-BILLING.md)
- [Seat-Based Billing](docs/SEAT-BASED-BILLING.md)
- [Invoicing](docs/INVOICING.md)

### Runtime and Operations

- [Commands](docs/COMMANDS.md)
- [Queues And Jobs](docs/QUEUES-AND-JOBS.md)
- [Security](docs/SECURITY.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)

### Testing

- [Testing](docs/TESTING.md)
- [Live Sandbox](docs/LIVE-SANDBOX.md)

### Guides and Contributor

- [Recipes](docs/RECIPES.md)
- [Use Cases](docs/USE-CASES.md)
- [Contributing](docs/CONTRIBUTING.md)

Internal planning docs live under `docs/plans/`.

## Safety Notes

Bank installment flags and application-level recurring collection are not the same thing.

If you need true multi-period manual collection, model it as your own scheduled billing flow instead of assuming provider installment settings create that behavior automatically.

Do not put real credentials into repository docs or example files.

## License

MIT
