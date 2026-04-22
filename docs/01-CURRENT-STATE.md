# Current State

> Last updated: 2026-04-22

## Project Mode
Code — Production readiness remediation complete on feature branch.

## Active Focus
`fix/production-readiness-blockers` branch addresses the 4 blockers from
`PRODUCTION-REVIEW-2026-04-21.md`. Awaiting merge + staging validation.

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
- Merge `fix/production-readiness-blockers` to main after review.
- Staging validation using the test plan in
  `docs/plans/2026-04-21-production-readiness-remediation-plan.md`.
- Documentation updates (FAQ, SECURITY, TROUBLESHOOTING, DUNNING-AND-RETRIES,
  providers/IYZICO, DOMAIN-BILLING) described in the remediation plan are not
  included in this branch yet — schedule follow-up doc pass.

## Open Questions / Blockers
- None blocking merge. Docs updates deferred to follow-up.
