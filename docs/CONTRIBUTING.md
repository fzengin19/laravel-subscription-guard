# Contributing

Guidelines for contributing to Laravel Subscription Guard.

## Development Setup

```bash
git clone <repository-url>
cd laravel-subscription-guard
composer install
```

## Running Tests

```bash
# Full deterministic suite
composer test

# Filter tests
vendor/bin/pest --filter="webhook"

# Static analysis (PHPStan level 5)
composer analyse

# Code formatting check
composer format -- --test

# Live sandbox tests (requires Iyzico credentials)
composer test-live
```

All tests must pass before submitting changes.

## Test Framework

The project uses [Pest](https://pestphp.com/) with Orchestra Testbench. Tests run against in-memory SQLite.

- **Feature tests**: Full Laravel container with database
- **Unit tests**: Isolated logic without container
- **Live tests**: Real Iyzico sandbox API calls (separate suite)

See [Testing](TESTING.md) for the complete test guide.

## Code Standards

- PHPStan level 5 (`composer analyse`)
- `declare(strict_types=1)` in all PHP files
- Final classes by default
- Explicit type declarations on parameters and return types

## Project Structure

```
src/
  Billing/          # MeteredBillingProcessor, SeatManager, InvoicePdfRenderer
  Commands/         # Artisan commands
  Contracts/        # Interfaces
  Data/             # DTOs (PaymentResponse, RefundResponse, WebhookResult)
  Enums/            # SubscriptionStatus, LicenseStatus
  Http/             # Controllers (Webhook, Callback)
  Jobs/             # Queue jobs
  Models/           # Eloquent models
  Payment/          # PaymentManager, provider adapters
  Subscription/     # SubscriptionService
tests/
  Feature/          # Integration tests
  Unit/             # Unit tests
  Live/             # Live sandbox tests
```

## Domain Ownership

Before making changes, understand which domain owns the code:

| Domain | Scope | Key Files |
|---|---|---|
| Billing | Subscriptions, renewals, dunning, metered, seats, invoicing | `SubscriptionService`, `MeteredBillingProcessor`, `SeatManager` |
| Licensing | License CRUD, validation, revocation, feature gates | `LicenseManager`, `LicenseSignature`, `FeatureGate` |
| Providers | Iyzico/PayTR adapters, provider contracts | `PaymentManager`, `IyzicoProvider`, `PaytrProvider` |
| Webhooks | Intake, dedup, signature, finalization | `WebhookController`, `FinalizeWebhookEventJob` |
| Infrastructure | Models, migrations, config, commands | Service provider, command classes |

See [Architecture](ARCHITECTURE.md) for the full system overview.

## Documentation

Documentation lives in `docs/`. When your change affects:

- A new command → update [Commands](COMMANDS.md)
- A route or endpoint → update [API](API.md)
- A config key → update [Configuration](CONFIGURATION.md)
- Provider behavior → update the relevant [provider doc](providers/)
- Billing or licensing flows → update the relevant domain doc

See [Documentation Standards](DOCUMENTATION-STANDARDS.md) for writing guidelines.

## Commit Guidelines

- Write clear, descriptive commit messages
- Reference the domain or area affected
- Keep commits focused — one logical change per commit

## Security

If you discover a security vulnerability, do not open a public issue. Contact the maintainers directly. See [Security](SECURITY.md) for the package's security model.

## Related Documents

- [Architecture](ARCHITECTURE.md)
- [Testing](TESTING.md)
- [Security](SECURITY.md)
- [Documentation Standards](DOCUMENTATION-STANDARDS.md)
- [Commands](COMMANDS.md)
