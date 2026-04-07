# Live Sandbox

Use this document to understand how to run the Iyzico live sandbox test suite and validate real provider integration.

## Purpose

The live sandbox suite validates package behavior against the real Iyzico sandbox API. These tests make actual HTTP calls to Iyzico's sandbox environment and verify end-to-end flows including payment, webhooks, refunds, plan sync, reconciliation, and card vault operations.

Live sandbox tests are isolated from the deterministic test suite. They require real Iyzico sandbox credentials and network access.

## Test Location

```
tests/Live/
  Iyzico/
    PhaseEightIyzicoSandboxPreflightTest.php
    PhaseEightIyzicoPaymentContractsTest.php
    PhaseEightIyzicoWebhookRoundTripTest.php
    PhaseEightIyzicoRefundContractTest.php
    PhaseEightIyzicoRemotePlanSyncTest.php
    PhaseEightIyzicoSubscriptionLifecycleTest.php
    PhaseEightIyzicoCardVaultTest.php
    PhaseEightIyzicoReconcileTest.php
  Support/
    IyzicoSandboxFixturesTest.php
    IyzicoSandboxRunContextTest.php
    IyzicoSandboxCleanupRegistryTest.php
    IyzicoSandboxGateTest.php
```

## Support Infrastructure

The live tests use a dedicated support layer:

| Class | Purpose |
|---|---|
| `IyzicoSandboxGate` | Checks whether sandbox credentials and network are available |
| `IyzicoSandboxFixtures` | Provides test data (plans, users, cards) for sandbox operations |
| `IyzicoSandboxRunContext` | Tracks created resources for cleanup |
| `IyzicoSandboxCleanupRegistry` | Ensures sandbox resources are cleaned up after test runs |

## Prerequisites

### 1. Iyzico Sandbox Credentials

Set the following environment variables:

```env
IYZICO_API_KEY=sandbox_api_key_here
IYZICO_SECRET_KEY=sandbox_secret_key_here
IYZICO_BASE_URL=https://sandbox-api.iyzipay.com
```

### 2. Network Access

The test machine must have HTTPS access to `sandbox-api.iyzipay.com`.

### 3. Sandbox Gate

Tests are gated by `IyzicoSandboxGate`. If credentials are missing or the sandbox is unreachable, tests are skipped — they do not fail.

## Running Live Tests

```bash
composer test-live
```

Or directly:

```bash
vendor/bin/pest tests/Live
```

To run a specific test:

```bash
vendor/bin/pest tests/Live/Iyzico/PhaseEightIyzicoPaymentContractsTest.php
```

## What The Tests Cover

| Test File | Coverage |
|---|---|
| SandboxPreflightTest | Credential verification, API connectivity |
| PaymentContractsTest | Payment creation, charge flows against sandbox |
| WebhookRoundTripTest | Webhook send and receive against sandbox |
| RefundContractTest | Refund operations against sandbox |
| RemotePlanSyncTest | Plan synchronization with remote Iyzico plans |
| SubscriptionLifecycleTest | Full subscription lifecycle against sandbox |
| CardVaultTest | Card tokenization and vault operations |
| ReconcileTest | Local/remote state reconciliation |

## Test Isolation

- Live tests use a separate test suite configuration (`composer test-live`)
- They do not share database state with deterministic tests
- Each test run creates fresh sandbox resources via `IyzicoSandboxFixtures`
- `IyzicoSandboxCleanupRegistry` tracks created resources and cleans up after the run
- Sandbox credentials are never committed to the repository

## Limitations

- Only Iyzico has live sandbox coverage. PayTR does not have a sandbox environment.
- Sandbox API behavior may differ from production in edge cases.
- Network-dependent — tests will skip if connectivity is unavailable.
- Sandbox rate limits may affect large test runs.

## Related Documents

- [Providers: Iyzico](providers/IYZICO.md)
- [Testing](TESTING.md)
- [Commands](COMMANDS.md)
