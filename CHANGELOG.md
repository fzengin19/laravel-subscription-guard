# Changelog

## v1.2.0 — 2026-05-13

### Post-v1.1.0 hardening

- **PHP constraint relaxed**: `composer.json` `"php"` is now `"^8.3 || ^8.4"`
  (was `"^8.4"`). The codebase uses zero PHP 8.4-specific features; the previous
  constraint artificially blocked PHP 8.3 deployments.
- **Trial as first-class state**: new `SubscriptionStatus::Trialing` enum case
  with transitions `Pending → Trialing` and `Trialing → {Active, PastDue,
  Cancelled, Failed}`. Seven raw `'trialing'` strings in production code
  (`SubscriptionService`, `PaytrProvider`, `PaymentChargeJob`,
  `ProcessRenewalCandidateJob`, `LicenseManager`) replaced with the enum so
  `Subscription::transitionTo()`'s state-machine guard actually fires.
- **Trial orchestration**: new `plans.trial_days` column, trial-aware
  `SubscriptionService::create()` (sets `status=trialing` and `trial_ends_at`
  when `trial_days > 0`), new `ProcessTrialExpiryJob`, and new
  `subguard:process-trial-expiry` Artisan command (uses `lazyById(500)`).
  Self-managed providers transition `trialing → past_due` on trial end;
  provider-managed (`iyzico`) defers to its own subscription event.
- **Idempotency hash safety**: new `Support\Json::safeHash()` helper using
  `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE`, replacing
  `hash('sha256', (string) json_encode(\$x))` at three webhook eventId
  fallback sites (`IyzicoProvider::eventId`, `PaytrProvider::processWebhook`,
  `IyzicoProviderEventDispatcher::dispatch`). Falls back to `serialize()`
  on `JsonException`.
- **Chunked cron queries**: `processRenewals`, `processDunning`,
  `processScheduledPlanChanges`, and `retryPastDuePayments` now stream
  via `lazyById(500)` — peak memory is bounded regardless of backlog size.
- **Cancel TOCTOU defensive refresh**: `SubscriptionService::cancel()`
  re-reads the subscription after the cache lock attempt (both on
  contention-loss and after acquiring the lock) and returns `true`
  idempotently if a concurrent worker has cancelled the subscription.
- **PaymentCallbackController header filtering**: 3DS and checkout
  callbacks now strip `authorization`, `cookie`, `set-cookie`, and
  `proxy-authorization` headers before persisting to `webhook_calls`,
  mirroring `WebhookController::filterHeaders()`.

### Documentation

- `INSTALLATION.md`: corrected the iyzico `IYZICO_CALLBACK_URL` example
  (was the non-existent `/subguard/payment/iyzico/callback`; now the
  correct base `/subguard/webhooks/iyzico` with a note that the package
  appends `/3ds/callback` and `/checkout/callback`).
- `DOMAIN-BILLING.md`, `DUNNING-AND-RETRIES.md`, `FAQ.md`, `SECURITY.md`,
  `TROUBLESHOOTING.md`, `providers/IYZICO.md`: refreshed to reflect
  v1.1.0 + v1.2.0 behaviour (mock-mode guard, terminal-state guard,
  trial flow, provider-managed dunning isolation, cancel orchestration
  order, hash-safety helper).

### Tests

- New `tests/Feature/PhaseThirteenHardeningTest.php` — 26 regression tests
  across Tasks 1, 2, 3, 4, 5, 6, 7, 9, 12, B.
- Full suite: 246 → 272 passed / 856 → 911 assertions.
- PHPStan level 5 remains clean.

### Deferred to v1.3.0 (evidence required before merging)

- `DB::afterCommit` event dispatch refactor (13 sites in `SubscriptionService`)
  — needs a listener audit to confirm none rely on pre-commit visibility.
- Iyzico cancel idempotency (`subscription not found` / `already cancelled`
  error code mapping) — needs verification against
  `vendor/iyzico/iyzipay-php` or a live sandbox round-trip.

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
