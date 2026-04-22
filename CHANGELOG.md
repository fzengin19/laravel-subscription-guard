# Changelog

## v1.1.0 — 2026-04-22

### Production readiness blockers fixed (P0/P1 from 2026-04-21 review)

- **P0-01 Mock mode fail-closed in production**: new `ProviderMockModeGuard`
  throws `ProviderException` from `IyzicoSupport::mockMode()` and
  `PaytrProvider::mockMode()` when `app()->environment('production')` and the
  provider mock flag is enabled. The prior critical-log-only bypass in
  `validateWebhook()` is removed.
- **P0-02 Cancellation orchestrates remote before local**:
  `SubscriptionService::cancel()` now acquires a per-subscription cache lock,
  calls the provider's `cancelSubscription()` for provider-managed providers
  before touching local state, and aborts without local changes if the remote
  call fails or throws. Dispatches `SubscriptionCancelled`, the provider-specific
  event, and `DispatchBillingNotificationsJob` on success. Returns `true`
  idempotently for already-cancelled subscriptions.
- **P1-01 Provider-managed dunning isolation**: `recordWebhookTransaction`
  does not set `next_retry_at` for provider-managed providers. `processDunning`
  skips provider-managed transactions. `ProcessDunningRetryJob` and
  `PaymentChargeJob::prepareChargePayload` neutralize legacy rows so
  provider-managed transactions never reach `chargeRecurring()`.
- **P1-02 Cancelled terminal-state guard**: new
  `SubscriptionService::applySubscriptionStatus()` replaces every direct
  `status` write in `recordWebhookTransaction`, `handlePaymentResult`,
  `PaymentChargeJob::handle`, and
  `ProcessDunningRetryJob::handleDunningExhaustion`. Late webhooks and
  in-flight charge jobs can no longer reactivate or downgrade a cancelled
  subscription.

### Dependency

- spatie/laravel-pdf constraint now allows both 1.5 and 2.0.

### API additions

- `SubscriptionGuard\LaravelSubscriptionGuard\Payment\ProviderMockModeGuard`
  (new class).
- `SubscriptionService::applySubscriptionStatus(Subscription, SubscriptionStatus, string $source): bool`
  (new public method).

### Signature change

- `ProcessDunningRetryJob::handle(PaymentManager, SubscriptionService)` —
  adds a second container-resolved parameter. Transparent for queue workers;
  callers that instantiate the job directly must pass both dependencies.

### Tests

- New `tests/Feature/PhaseTwelveProductionReadinessTest.php` (16 focused
  cases across all 4 blockers).
- Full suite: **246 passed / 856 assertions**. PHPStan level 5 clean.

## v1.0.1 — 2026-04-07

- `fix(dependencies)`: widen `illuminate/contracts` constraint to support 13.0.

## v1.0.0 — 2026-04-07

- Initial tagged release.

## 2026-03-05

### Phase 4.1 Closure

- PayTR live-path placeholder responses replaced by deterministic live DTO flows
- Revocation and heartbeat sync operations added
- Dunning terminal failure handling hardened
- Metered charge path hardened with provider-charge integration and idempotency tests

### Phase 5 Integration and Testing (ongoing)

- Added `subguard:simulate-webhook` command
- Added notification pipeline (`InvoicePaidNotification`, `SubscriptionCancelledNotification`)
- Added invoice PDF renderer with safe fallback behavior
- Added E2E and performance audit feature tests
- Expanded coupon/discount behavior and transaction propagation coverage
- Added documentation set: INSTALLATION, CONFIGURATION, LICENSING, API, PROVIDERS, RECIPES
