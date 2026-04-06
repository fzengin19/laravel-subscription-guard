# Providers

This document describes provider responsibilities and integration rules.

## Architecture Boundary

Provider adapter classes must:

- call provider APIs
- validate signatures
- normalize payloads into DTOs

Provider adapter classes must not:

- mutate package domain models directly
- dispatch domain events directly

Domain mutation and event orchestration belongs to `SubscriptionService` and jobs.

## iyzico

- `manages_own_billing = true`
- Supports provider-managed subscription lifecycle mapping through webhook callbacks.
- Signature header defaults to `x-iyz-signature-v3`.

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
- Signature header defaults to `x-paytr-signature`.

## Webhook Simulation

Use the built-in command during local integration and tests:

```bash
php artisan subguard:simulate-webhook paytr payment.success
php artisan subguard:simulate-webhook iyzico payment.success
```

Useful options:

```bash
php artisan subguard:simulate-webhook paytr payment.success --event-id=evt_001 --amount=99.90
php artisan subguard:simulate-webhook iyzico payment.failed --event-id=evt_002 --transaction-id=txn_123
```

## Installment Strategy Warning

**BANK INSTALLMENT IN PROVIDER SUBSCRIPTION APIS IS NOT THE SAME AS APPLICATION-LEVEL MANUAL INSTALLMENT COLLECTION.**

**BANK INSTALLMENT:** single capture, bank internally splits customer repayment.

**MANUAL INSTALLMENT:** your system schedules multiple charges over periods, with its own retry/dunning/accounting logic.

Do not treat these as equivalent in product or finance communication.
