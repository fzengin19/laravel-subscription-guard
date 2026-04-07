# Laravel Subscription Guard

Multi-provider subscription billing package for Laravel. Supports Iyzico (provider-managed) and PayTR (package-managed) with licensing, webhooks, dunning, metered billing, and invoicing.

## Docs Operating System

This project uses the Docs Operating System skill. **Before starting any work:**

1. SessionStart hook injects `docs/01-CURRENT-STATE.md` automatically
2. Follow BIND-EXECUTE-SYNC protocol from `docs-operating-system` skill
3. Validate: `bash ~/.claude/skills/docs-operating-system/scripts/validate-docs.sh`

## Key Commands

```bash
# Tests
composer test              # Run full test suite (Pest)
vendor/bin/pest            # Direct Pest execution
vendor/bin/pest --filter="keyword"  # Filter tests

# Static analysis
composer analyse           # PHPStan level 5

# Docs validation
bash ~/.claude/skills/docs-operating-system/scripts/validate-docs.sh
```

## Project Structure

```
src/
  Billing/          # MeteredBillingProcessor, InvoicePdfRenderer
  Commands/         # Artisan commands (simulate-webhook, sync-plans, etc.)
  Contracts/        # PaymentProviderInterface, ProviderEventDispatcherInterface
  Data/             # PaymentResponse, RefundResponse, WebhookResult DTOs
  Enums/            # SubscriptionStatus, LicenseStatus
  Http/             # WebhookController, PaymentCallbackController
  Jobs/             # FinalizeWebhookEventJob, PaymentChargeJob, ProcessDunningRetryJob
  Models/           # Subscription, Transaction, License, Plan, Invoice, WebhookCall
  Payment/          # PaymentManager, Providers/Iyzico, Providers/PayTR
  Subscription/     # SubscriptionService (core orchestration)
tests/
  Feature/          # Integration tests
  Unit/             # Unit tests
  Live/             # Live sandbox tests (Iyzico)
docs/
  00-START-HERE.md        # Work protocol
  01-CURRENT-STATE.md     # Live state (read every task)
  02-DECISION-BOARD.md    # Accepted decisions
  plans/                  # Phase plans and execution history
  providers/              # Provider-specific documentation
  templates/              # Document templates
```

## Domain Ownership

| Domain | Scope |
|--------|-------|
| Billing | Subscription lifecycle, renewals, dunning, metered billing, seats, invoicing |
| Licensing | License CRUD, validation, revocation, feature gates, heartbeat |
| Providers | Iyzico/PayTR adapters, provider contracts, payment flows |
| Webhooks | Intake, dedup, signature validation, finalization, callbacks |
| Infrastructure | Models, migrations, config, service provider, commands |
