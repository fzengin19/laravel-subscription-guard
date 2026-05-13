# Current State

> Last updated: 2026-05-13

## Project Mode
Code — v1.2.0 released and tagged. v1.3.0 cascade-delete protection in progress on `fix/cascade-delete-protection`.

## Active Focus
`fix/cascade-delete-protection` closes a P0 data-loss vector discovered by a
post-v1.2.0 third-party review: `subscriptions.plan_id`, `licenses.plan_id`,
`licenses.user_id`, `subscription_items.plan_id` were all `ON DELETE CASCADE`
— a single `DELETE FROM plans` would physically wipe dependent rows even
though `softDeletes` traits were declared. Plan: see
`docs/plans/2026-05-13-cascade-delete-protection-plan.md`. The other v1.3.0
candidates (DA-01 `DB::afterCommit` listener audit, DA-02 Iyzico cancel
idempotency error-code mapping) remain pending and require evidence before
merging.

## Active Domain / Phase
Billing + Providers + Webhooks (production-readiness fixes).

## Reading List (this task)

Required reads:
- `docs/00-START-HERE.md`
- `docs/01-CURRENT-STATE.md`
- `docs/02-DECISION-BOARD.md`
- `PRODUCTION-REVIEW-2026-04-21.md`
- `docs/plans/2026-04-21-production-readiness-remediation-plan.md`

## Key Decisions (quick ref)
See `docs/02-DECISION-BOARD.md` for full list.
- D1: 6-layer documentation architecture
- D2: English-first public docs, Turkish OK for internal plans
- D3: Canonical source policy — one topic, one primary home
- D4: Phase dependency order must be respected

## Last Completed Work
- Documentation Phases 0-6 completed (2026-04-06 to 2026-04-07)
- Security audit fixes merged to main (32 findings fixed)
- 2026-05-13: Cascade-delete protection on `fix/cascade-delete-protection` (v1.3.0):
  - **P0 data-loss fix**: new migration `2026_05_13_090000_fix_cascade_delete_protection.php`
    swaps four foreign keys from `cascadeOnDelete` to `restrictOnDelete`
    (`subscriptions.plan_id`, `licenses.plan_id`, `licenses.user_id`,
    `subscription_items.plan_id`). Adds `plans.deleted_at`. Idempotent.
  - **Plan model**: gains `SoftDeletes` trait. `$plan->delete()` archives;
    `$plan->forceDelete()` blocked by RESTRICT FKs when dependents exist.
  - **belongsTo plan() relations**: `Subscription::plan()`,
    `License::plan()`, `SubscriptionItem::plan()` chain `->withTrashed()`
    so historical billing keeps resolving archived plans.
  - **BREAKING**: `User::delete()` no longer cascades to licenses; consuming
    apps must archive/cancel dependent licenses first. Documented in
    `CHANGELOG.md` v1.3.0 BREAKING section + `docs/INSTALLATION.md` §8 upgrade
    note + `docs/SECURITY.md` Cascade-Delete Defense subsection.
  - **Tests**: `tests/Feature/PhaseFourteenCascadeProtectionTest.php` adds
    7 regression tests. Full suite 282 passed / 932 assertions. PHPStan
    level 5 clean.
- 2026-05-13: Post-v1.1.0 hardening released as v1.2.0 (merged to main):
  - **PHP constraint relaxed** `^8.4` → `^8.3 || ^8.4` (composer.json:19).
    The codebase uses zero PHP 8.4-specific features; the previous constraint
    artificially blocked PHP 8.3 deployments.
  - **Trial flow**: added `SubscriptionStatus::Trialing` enum case with
    transitions; replaced 7 raw `'trialing'` strings; added
    `plans.trial_days` migration; trial-aware `SubscriptionService::create()`;
    new `ProcessTrialExpiryJob` + `subguard:process-trial-expiry` Artisan
    command.
  - **Idempotency hash safety**: new `Support\Json::safeHash()` helper;
    three webhook eventId fallback sites rerouted (`IyzicoProvider::eventId`,
    `PaytrProvider::processWebhook`, `IyzicoProviderEventDispatcher::dispatch`).
  - **Chunked cron queries**: `processRenewals`, `processDunning`,
    `processScheduledPlanChanges`, `retryPastDuePayments` now stream via
    `lazyById(500)`.
  - **Cancel TOCTOU**: `SubscriptionService::cancel()` re-reads the
    subscription on both lock-contention and post-lock; returns `true`
    idempotently if a concurrent worker already cancelled it.
  - **PaymentCallbackController** strips `authorization`, `cookie`,
    `set-cookie`, `proxy-authorization` headers before persisting to
    `webhook_calls` (mirrors `WebhookController::filterHeaders()`).
  - **INSTALLATION.md** callback URL corrected — `/subguard/payment/iyzico/callback`
    was a non-existent path; replaced with the real
    `/subguard/webhooks/iyzico` base.
  - Documentation refresh: `DOMAIN-BILLING`, `DUNNING-AND-RETRIES`, `FAQ`,
    `SECURITY`, `TROUBLESHOOTING`, `providers/IYZICO`, `CHANGELOG v1.2.0`.
  - Tests: `tests/Feature/PhaseThirteenHardeningTest.php` adds 26 regression
    tests. Full suite 272 passed / 911 assertions. PHPStan level 5 clean.
- 2026-04-22: Production readiness remediation on `fix/production-readiness-blockers`:
  - P0-01 mock-mode fail-closed: `ProviderMockModeGuard` throws `ProviderException`
    from `IyzicoSupport::mockMode()` and `PaytrProvider::mockMode()` when
    `app()->environment('production')`. Removed prior critical-log-only bypass.
  - P0-02 cancellation orchestration: `SubscriptionService::cancel()` now calls
    `PaymentProviderInterface::cancelSubscription()` for provider-managed
    providers before transitioning local state, under a `subguard:subscription-cancel:{id}`
    cache lock. Dispatches same events the webhook cancel path dispatches.
  - P1-01 dunning isolation: `recordWebhookTransaction` skips `next_retry_at`
    for provider-managed providers; `processDunning` filters them out;
    `ProcessDunningRetryJob` and `PaymentChargeJob::prepareChargePayload`
    carry defensive guards that neutralize legacy rows without dispatching
    `PaymentChargeJob` or `chargeRecurring`.
  - P1-02 terminal-state guard: new `SubscriptionService::applySubscriptionStatus()`
    wraps `transitionTo` and refuses to change status on cancelled subscriptions.
    Used in `recordWebhookTransaction`, `handlePaymentResult`, `PaymentChargeJob`,
    and `ProcessDunningRetryJob::handleDunningExhaustion`.
  - Tests: `tests/Feature/PhaseTwelveProductionReadinessTest.php` covers all
    4 blockers (16 new tests). Full suite 246 passed / 856 assertions. PHPStan
    level 5 clean.

## Next Tasks
- Merge `fix/cascade-delete-protection` to main after review.
- Tag v1.3.0 and publish GitHub release.
- Staging validation: confirm `User::delete()` / plan-delete UI paths in the
  consuming app surface `QueryException` instead of silent data wipe.
- Remaining v1.3.0+ follow-ups (require evidence before merging):
  - DA-01 `DB::afterCommit` event-dispatch refactor (needs a listener audit).
  - DA-02 Iyzico cancel idempotency (`already cancelled` / `not found` error
    code mapping — needs sandbox or vendor verification).

## Open Questions / Blockers
- None blocking merge for v1.3.0 cascade-protection. Remaining candidates
  have explicit evidence requirements documented in
  `docs/plans/2026-05-13-post-v1.1.0-hardening-plan.md` revision log and
  `docs/plans/2026-05-13-cascade-delete-protection-plan.md` "Out of scope"
  section.
