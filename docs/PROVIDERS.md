# Providers

Use this document as the short provider overview.

The canonical provider-domain reference now lives in [Domain Providers](DOMAIN-PROVIDERS.md).

## Provider Overview

The package currently supports two provider modes:

- `iyzico`
- `paytr`

Both providers are resolved through `PaymentManager`, and both implement `PaymentProviderInterface`.

The important difference is who owns recurring billing execution.

| Provider | `manages_own_billing` | Practical Meaning |
|---|---|---|
| `iyzico` | `true` | Provider-managed recurring billing; local state is updated from provider results |
| `paytr` | `false` | Package-managed recurring billing; the package runs renewal and retry orchestration |

## Architecture Boundary

Provider adapter classes are expected to:

- call provider APIs
- validate signatures
- normalize webhook or callback payloads
- return package DTOs and provider-response metadata

Provider adapter classes are not expected to:

- mutate subscriptions, transactions, or licenses directly
- replace package billing orchestration

That mutation boundary belongs to `SubscriptionService` and background jobs.

## iyzico

- `manages_own_billing = true`
- Supports provider-managed subscription lifecycle mapping through webhook callbacks.
- Signature header defaults to `x-iyz-signature-v3`.
- Exposes remote subscription and plan-sync surfaces that do not exist in the PayTR adapter.

### iyzico Live Sandbox Notes

- The deterministic test suite does not hit sandbox.
- Real sandbox validation lives under `tests/Live` and runs via `composer test-live`.
- `IYZICO_MOCK=false` and explicit sandbox credentials are required.
- Exported process env takes precedence; any missing live values may fall back to `.env.test` or `SUBGUARD_LIVE_ENV_FILE` without overwriting exported values.
- Sandbox cards use valid-format CVV and a future expiry date; the package fixture layer pins documented PANs for success, foreign, and failure scenarios.
- Callback/webhook roundtrip scenarios require a public HTTPS URL; localhost alone is not enough.
- Remote `sync-plans` currently creates a separate iyzico product per local plan, so real upgrade validation needs same-product fixture provisioning.

## PayTR

- `manages_own_billing = false`
- Package orchestrates recurring charge and dunning behavior.
- Webhook validation uses merchant key and merchant salt hashing rules, with payload `hash` support.
- Webhook normalization maps provider payloads into package billing events and retryable outcomes.

## Webhook Simulation

Use the built-in command during local integration and tests:

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

See [API](API.md) for the route summary and [Events And Jobs](EVENTS-AND-JOBS.md) for the intake-to-finalization flow.

Useful options:

```bash
php artisan subguard:simulate-webhook paytr payment.success --event-id=evt_001 --amount=99.90
php artisan subguard:simulate-webhook iyzico payment.failed --event-id=evt_002 --transaction-id=txn_123
```

## Where To Go Next

Use the deeper references by question:

- [Domain Providers](DOMAIN-PROVIDERS.md)
- [Domain Billing](DOMAIN-BILLING.md)
- [Architecture](ARCHITECTURE.md)
- [API](API.md)
- [Webhooks](WEBHOOKS.md)
- [Callbacks](CALLBACKS.md)
- [iyzico Provider](providers/IYZICO.md)
- [PayTR Provider](providers/PAYTR.md)
- [Custom Provider](providers/CUSTOM-PROVIDER.md)

## Installment Strategy Warning

**BANK INSTALLMENT IN PROVIDER SUBSCRIPTION APIS IS NOT THE SAME AS APPLICATION-LEVEL MANUAL INSTALLMENT COLLECTION.**

**BANK INSTALLMENT:** single capture, bank internally splits customer repayment.

**MANUAL INSTALLMENT:** your system schedules multiple charges over periods, with its own retry/dunning/accounting logic.

Do not treat these as equivalent in product or finance communication.

## Related Documents

- [Domain Providers](DOMAIN-PROVIDERS.md)
- [iyzico Provider](providers/IYZICO.md)
- [PayTR Provider](providers/PAYTR.md)
- [Custom Provider](providers/CUSTOM-PROVIDER.md)
- [Domain Billing](DOMAIN-BILLING.md)
