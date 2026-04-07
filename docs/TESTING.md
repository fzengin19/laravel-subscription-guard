# Testing

Use this document to understand the test suite structure, how to run tests, and what each test category covers.

## Running Tests

```bash
# Full deterministic test suite
composer test

# Direct Pest execution
vendor/bin/pest

# Filter by keyword
vendor/bin/pest --filter="dunning"
vendor/bin/pest --filter="webhook"

# Static analysis (PHPStan level 5)
composer analyse

# Code formatting check
composer format -- --test

# Live sandbox tests (requires Iyzico credentials)
composer test-live
```

## Test Framework

The package uses [Pest](https://pestphp.com/) as the test framework, built on top of PHPUnit. The base test case extends `Orchestra\Testbench\TestCase` for Laravel package testing with an in-memory SQLite database.

## Test Suite Structure

```
tests/
  TestCase.php              # Base test case with SQLite setup
  Pest.php                  # Pest configuration
  ArchTest.php              # Architecture tests
  ExampleTest.php           # Basic smoke test
  Feature/                  # Integration tests
  Unit/                     # Unit tests
  Live/                     # Live sandbox tests (separate suite)
  Support/                  # Test helpers and fixtures
```

## Test Categories

### Feature Tests

Feature tests verify end-to-end behavior with a real database (in-memory SQLite) and full Laravel container.

| Test File | Coverage Area |
|---|---|
| PhaseOneSchemaTest | Database migrations and schema |
| PhaseOneBillingOrchestrationTest | Subscription creation, renewal, billing flows |
| PhaseOneWebhookFlowTest | Webhook intake, dedup, finalization |
| PhaseTwoIyzicoProviderTest | Iyzico adapter behavior |
| PhaseThreePaytrProviderTest | PayTR adapter behavior |
| PhaseThreePaytrWebhookIngressTest | PayTR webhook intake specifics |
| PhaseThreePreflightTest | Provider preflight checks |
| PhaseFourLicenseManagerTest | License generation, validation, activation |
| PhaseFourFeatureGateTest | Feature and limit gating |
| PhaseFourBladeDirectiveTest | Blade template directives |
| PhaseFourLicensingMiddlewareTest | License middleware |
| PhaseFourLicenseLifecycleListenerTest | Billing-to-license event bridge |
| PhaseFourLicenseValidationEndpointTest | Online validation endpoint |
| PhaseFourOperationsTest | Operational command tests |
| PhaseFiveEndToEndFlowTest | Full lifecycle end-to-end |
| PhaseFiveCouponDiscountClosureTest | Coupon and discount logic |
| PhaseFiveNotificationsAndInvoicesTest | Invoice generation, notifications |
| PhaseFivePerformanceAuditTest | Performance-related checks |
| PhaseFiveWebhookSimulatorCommandTest | Webhook simulation command |
| PhaseSixMassAssignmentHardeningTest | Mass assignment protection |
| PhaseSevenContainerResolutionTest | Service container bindings |
| PhaseEightTestRuntimeIsolationTest | Test runtime isolation |
| PhaseTenSubscriptionServiceTest | Subscription service operations |
| PhaseTenDunningExhaustionTest | Dunning exhaustion flow |
| PhaseTenLicenseManagerTest | License manager edge cases |
| PhaseTenRefundFlowTest | Refund flow |
| PhaseTenPaymentCallbackTest | Payment callback handling |
| PhaseTenConcurrencyTest | Concurrency and locking |
| PhaseElevenConfigSafetyTest | Configuration validation |
| PhaseElevenBillingPeriodTest | Billing period calculations |
| PhaseElevenDuplicateDiscountTest | Duplicate discount prevention |
| PhaseElevenModelCastsTest | Model attribute casting |
| PhaseElevenWebhookRateLimitTest | Webhook rate limiting |
| PhaseElevenMeteredPeriodTest | Metered billing periods |
| PhaseElevenDunningQueryTest | Dunning query behavior |
| WebhookSignatureValidationTest | Webhook signature validation |

### Unit Tests

Unit tests verify isolated behavior without full Laravel bootstrapping.

| Test File | Coverage Area |
|---|---|
| Models/WebhookCallStateTransitionTest | WebhookCall state machine |
| Models/ScheduledPlanChangeStateTransitionTest | Plan change state machine |
| Models/TransactionStateTransitionTest | Transaction state machine |
| Payment/Providers/Iyzico/IyzicoSupportTest | Iyzico support utilities |
| Payment/Providers/Iyzico/IyzicoWebhookSignatureTest | Iyzico signature verification |

### Architecture Tests

`ArchTest.php` uses Pest's architecture testing to enforce structural rules across the codebase.

### Live Sandbox Tests

See [Live Sandbox](LIVE-SANDBOX.md) for the full live test documentation.

## Test Environment

- **Database**: In-memory SQLite (`database.connections.testing`)
- **Migrations**: Loaded from `database/migrations` directory
- **Users table**: Created in `TestCase::defineDatabaseMigrations()` for polymorphic relation testing
- **Package provider**: `LaravelSubscriptionGuardServiceProvider` auto-registered via Testbench

## Writing Tests

Follow existing conventions:

- Use Pest syntax (`test()`, `it()`, `describe()`)
- Name test files after the phase and domain they cover
- Use the `TestCase` base class for feature tests
- Mock external provider calls — do not make real HTTP requests in feature tests
- Use `composer test-live` for real provider validation

## Related Documents

- [Live Sandbox](LIVE-SANDBOX.md)
- [Architecture](ARCHITECTURE.md)
- [Commands](COMMANDS.md)
