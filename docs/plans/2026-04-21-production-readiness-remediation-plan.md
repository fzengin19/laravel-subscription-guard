# Production Readiness Remediation Plan

**Date:** 2026-04-21  
**Source Review:** `PRODUCTION-REVIEW-2026-04-21.md`  
**Goal:** Fix the verified production blockers without breaking the package's provider/orchestration boundaries.

---

## Current Verified State

The current deterministic suite passes:

- `composer test`
- 230 tests
- 825 assertions

The passing suite does not cover the production blockers below. The remediation must add focused regression tests before or with the implementation changes.

Reviewed code surfaces:

- `config/subscription-guard.php`
- `src/Payment/PaymentManager.php`
- `src/Contracts/PaymentProviderInterface.php`
- `src/Payment/Providers/Iyzico/IyzicoProvider.php`
- `src/Payment/Providers/Iyzico/IyzicoSupport.php`
- `src/Payment/Providers/PayTR/PaytrProvider.php`
- `src/Subscription/SubscriptionService.php`
- `src/Jobs/ProcessDunningRetryJob.php`
- `src/Jobs/PaymentChargeJob.php`
- `src/Jobs/FinalizeWebhookEventJob.php`
- `src/Http/Controllers/WebhookController.php`
- `src/Models/Subscription.php`
- `src/Enums/SubscriptionStatus.php`
- `tests/ArchTest.php`
- Existing phase and regression tests around iyzico, dunning, callbacks, webhooks, cancellation, and concurrency.

---

## Architectural Constraints

These constraints must stay intact:

1. Provider adapters must not mutate database state.
2. Provider adapters must not dispatch domain events.
3. `FinalizeWebhookEventJob` must remain provider-agnostic.
4. `SubscriptionService` remains the owner of subscription, transaction, retry, event, and notification orchestration.
5. `manages_own_billing=true` providers, currently iyzico, must not be routed into package-managed recurring charge jobs.
6. No env files should be read, diffed, or documented with secret values.

---

## Fix Order

1. P0 mock mode production guard
2. P0 provider-managed cancellation orchestration
3. P1 provider-managed dunning isolation
4. P1 state transition guards for late webhook and in-flight charge events
5. Documentation and final verification

This order closes the highest financial-risk items first and avoids mixing behavior changes into the webhook/dunning refactor before the safety guard is in place.

---

## P0-01: Mock Mode Must Fail Closed in Production

### Problem

Mock mode defaults are safe, but runtime behavior is not fail-closed. If a production environment sets `IYZICO_MOCK=true` or `PAYTR_MOCK=true`, provider methods can still return deterministic successful responses. Webhook validation in mock mode also returns `true`.

### Implementation Plan

1. Add a small shared guard for provider mock mode.
   - Suggested location: `src/Payment/ProviderMockModeGuard.php`
   - Responsibility: throw a package exception when `app()->environment('production')` and the provider mock flag is enabled.
   - Exception type can reuse `ProviderException` or introduce a narrow `ConfigurationException`.

2. Call the guard from:
   - `IyzicoSupport::mockMode()`
   - `PaytrProvider::mockMode()`

3. Optionally add an early package boot check in `LaravelSubscriptionGuardServiceProvider`.
   - This catches bad production config as early as possible.
   - The provider-level guard should still remain, because tests and host applications can mutate config after boot.

4. Remove or downgrade the current critical-log-only behavior.
   - Logging is still useful, but it must not be the only protection.
   - The operation must not continue.

5. Update documentation that currently says mock mode logs a critical warning in production.
   - `docs/FAQ.md`
   - `docs/SECURITY.md`
   - `docs/TROUBLESHOOTING.md`
   - provider docs if needed

### Tests

Add focused tests, likely under `tests/Feature/PhaseTwelveProductionReadinessTest.php` or a similarly named file:

- iyzico mock mode throws in production when calling `pay()`.
- iyzico mock mode throws in production when calling `validateWebhook()`.
- PayTR mock mode throws in production when calling `pay()` or `validateWebhook()`.
- Non-production mock mode still works for deterministic local tests.
- Default config still has both provider mock flags false.

### Acceptance Criteria

- Production cannot process payment, subscription, cancellation, refund, or webhook validation through mock provider paths.
- Existing deterministic tests remain green after updating tests that intentionally expect mock behavior outside production.

---

## P0-02: Provider-Managed Cancellation Must Cancel Remote Before Local Finalization

### Problem

`SubscriptionService::cancel()` only changes local state. For iyzico, recurring billing is remote/provider-managed. Local cancellation without remote cancellation can leave the provider subscription active.

`IyzicoProvider::cancelSubscription()` also returns `false` on provider failure without logging enough operational context.

### Implementation Plan

1. Add a cancellation lock.
   - Suggested key: `subguard:subscription-cancel:{subscriptionId}`
   - Keep the lock outside any long database transaction.

2. In `SubscriptionService::cancel()`:
   - Load the subscription.
   - Resolve provider name and provider subscription id.
   - If provider is self-managed, preserve existing local cancellation behavior.
   - If provider is provider-managed and has a provider subscription id:
     - Resolve provider adapter.
     - Call `cancelSubscription(provider_subscription_id)` before marking local state as cancelled.
     - If the provider returns `false`, log a sanitized warning and return `false`.
     - Do not mark local status as `cancelled` on remote failure.
   - If provider is provider-managed but provider subscription id is missing:
     - Allow local cancellation only if the subscription is still local-only or pending.
     - Log a warning for active subscriptions with missing provider id.

3. After successful remote cancellation or self-managed decision:
   - Lock the subscription row.
   - Use `Subscription::transitionTo(SubscriptionStatus::Cancelled)`.
   - Set `cancelled_at`.
   - Save.

4. Dispatch the same lifecycle side effects that webhook cancellation already triggers:
   - Generic `SubscriptionCancelled`
   - Provider-specific `subscription.cancelled`
   - `DispatchBillingNotificationsJob` with `subscription.cancelled`

5. Keep provider adapters pure.
   - `IyzicoProvider::cancelSubscription()` must only call iyzico and return a result.
   - It must not update local DB state.

6. Improve provider cancellation observability.
   - Log sanitized provider cancellation failures in `IyzicoProvider::cancelSubscription()` or in `SubscriptionService::cancel()` after a false result.
   - Do not log credentials or raw secret-bearing payloads.

### Tests

Add tests for:

- PayTR/self-managed cancellation still succeeds locally.
- iyzico/provider-managed cancellation calls provider `cancelSubscription()` before local cancellation.
- iyzico cancellation does not mark local subscription cancelled when provider cancellation returns false.
- successful service cancellation dispatches `SubscriptionCancelled` and updates linked license through the existing listener.
- cancelling an already-cancelled subscription is idempotent or returns a documented result.
- missing provider subscription id behavior is explicit and tested.

Use a fake provider class in tests rather than hitting live iyzico.

### Acceptance Criteria

- Provider-managed subscription cannot be locally finalized as cancelled while the remote cancellation call has failed.
- Manual service cancellation and webhook cancellation produce consistent lifecycle side effects.
- No provider class mutates DB state or dispatches domain events.

---

## P1-01: Provider-Managed Failures Must Not Enter Local Dunning Charge Flow

### Problem

Iyzico failure webhooks can create failed transactions with `next_retry_at`. `processDunning()` then dispatches local retry jobs. The retry path eventually calls `chargeRecurring()`, but iyzico intentionally throws `UnsupportedProviderOperationException` for that method.

### Implementation Plan

1. Add a local-dunning eligibility helper.
   - Suggested place: `SubscriptionService`.
   - Rule: local dunning is eligible only when `PaymentManager::managesOwnBilling(provider)` is false.

2. Update `SubscriptionService::recordWebhookTransaction()`:
   - For self-managed providers:
     - preserve current retry scheduling behavior.
   - For provider-managed providers:
     - record the failed transaction for audit.
     - set `next_retry_at` to null.
     - avoid scheduling local retries.
     - keep subscription state reflection and notification behavior.

3. Update `SubscriptionService::processDunning()`:
   - Select only self-managed providers.
   - Either filter by provider list in the query or skip provider-managed transactions before dispatch.
   - Return count only for jobs actually dispatched.

4. Add a defensive guard in `ProcessDunningRetryJob`:
   - If the transaction provider is provider-managed, clear `next_retry_at`, log a warning, and return without dispatching `PaymentChargeJob`.
   - This protects legacy rows created before the fix.

5. Add a defensive guard in `PaymentChargeJob`:
   - If a provider-managed provider reaches the job, mark the transaction failed with no next retry and log a sanitized warning.
   - This is a last-resort guard, not the primary routing mechanism.

6. Keep iyzico recovery provider-managed.
   - Do not invent a non-existent iyzico retry endpoint.
   - If a future iyzico recovery flow is needed, design it explicitly as customer action, checkout link, reconciliation, or provider-side retry reflection.

### Tests

Add tests for:

- iyzico `subscription.order.failure` webhook records a failed transaction with `next_retry_at = null`.
- iyzico failure webhook moves or keeps subscription in the intended local failure state without local dunning dispatch.
- `processDunning()` does not dispatch `ProcessDunningRetryJob` for iyzico failed transactions.
- legacy iyzico transaction with due `next_retry_at` is cleared by `ProcessDunningRetryJob` and does not dispatch `PaymentChargeJob`.
- PayTR failed transactions still enter dunning and dispatch retry jobs.
- `PaymentChargeJob` does not leave transactions stuck in `processing` when unsupported provider-managed charge execution is reached.

### Acceptance Criteria

- iyzico never reaches local recurring charge execution.
- PayTR dunning behavior remains unchanged.
- No transaction is left in `processing` because iyzico does not support `chargeRecurring()`.

---

## P1-02: Late Webhook and In-Flight Charge Events Must Respect Terminal State

### Problem

Some paths use `Subscription::transitionTo()`, but others directly write subscription status. In particular, order success/failure handling in `recordWebhookTransaction()` writes `active` and `past_due` directly. A late webhook can mutate a cancelled subscription.

Similar direct writes exist in `handlePaymentResult()` and `PaymentChargeJob`, which can matter for in-flight charge jobs racing with cancellation.

### Implementation Plan

1. Add a small state application helper in `SubscriptionService`.
   - Suggested name: `applySubscriptionStatus()`.
   - Inputs: subscription, target status, event/source context.
   - Behavior:
     - if current status is `cancelled` and target is not `cancelled`, log and return false.
     - otherwise use `Subscription::transitionTo()`.
     - catch `SubGuardException`, log sanitized context, and return false.

2. Use the helper in webhook order success/failure handling.
   - On success, apply `active`.
   - On failure, apply `past_due` or the explicitly chosen failure target.
   - If status application fails because the subscription is terminal/stale, do not dispatch renewal/payment events as if the state changed.

3. Apply the same terminal-state guard to:
   - `SubscriptionService::handlePaymentResult()`
   - `PaymentChargeJob`

4. Decide audit behavior for stale events.
   - Preferred: keep the `WebhookCall` processed, because the payload was valid and handled.
   - Do not create misleading renewal success/failure transaction records after terminal cancellation unless a deliberate audit status is introduced.
   - If an audit transaction is needed, use a distinct status such as `ignored` and document it.

5. Avoid provider-specific branching inside `FinalizeWebhookEventJob`.
   - All state decisions stay inside `SubscriptionService`.

### Tests

Add tests for:

- cancelled iyzico subscription receives late `subscription.order.success` and remains `cancelled`.
- cancelled iyzico subscription receives late `subscription.order.failure` and remains `cancelled`.
- late order event does not dispatch `PaymentCompleted`, `PaymentFailed`, `SubscriptionRenewed`, or `SubscriptionRenewalFailed` as a normal billing transition.
- normal `past_due -> active` recovery still works.
- normal `active -> past_due` failure still works for valid states.
- self-managed `PaymentChargeJob` success does not reactivate a subscription cancelled while charge was in flight.

### Acceptance Criteria

- `cancelled` remains terminal across webhook, payment-result, and charge-job flows.
- Valid recovery flows such as `past_due -> active` still work.
- `FinalizeWebhookEventJob` remains provider-agnostic.

---

## Documentation Updates

Update these docs after implementation:

- `docs/SECURITY.md`
  - Mock mode in production is blocked, not merely logged.
- `docs/FAQ.md`
  - Clarify mock mode behavior.
- `docs/TROUBLESHOOTING.md`
  - Replace production mock warning guidance with fail-closed error guidance.
- `docs/DUNNING-AND-RETRIES.md`
  - State clearly that local dunning excludes provider-managed providers.
- `docs/providers/IYZICO.md`
  - Clarify iyzico failure webhook behavior and remote cancellation expectations.
- `docs/DOMAIN-BILLING.md`
  - Clarify provider-managed failure reflection vs self-managed retry execution.

Do not put code snippets in `docs/plans/master-plan.md` if that file is later updated.

---

## Verification Plan

Run after implementation:

1. Focused tests for new production readiness cases.
2. `composer test`
3. `composer analyse`
4. `composer format` or `vendor/bin/pint` if formatting changes are needed.

Recommended targeted commands during implementation:

- `composer test -- --filter=ProductionReadiness`
- `composer test -- --filter=PhaseTwoIyzicoProviderTest`
- `composer test -- --filter=PhaseTenDunningExhaustionTest`
- `composer test -- --filter=PhaseTenSubscriptionServiceTest`
- `composer test -- --filter=PhaseTenConcurrencyTest`
- `composer analyse`

---

## Definition of Done

The remediation is complete only when all of these are true:

- Production mock mode cannot execute for iyzico or PayTR.
- Provider-managed cancellation attempts remote cancellation before local cancellation finalization.
- A failed provider-managed webhook cannot enter local recurring charge retry.
- Legacy provider-managed dunning rows are safely neutralized.
- Cancelled subscriptions cannot be reactivated or moved to past due by late webhook or in-flight charge events.
- Generic and provider-specific cancellation side effects are consistent.
- New tests fail before the implementation and pass after it.
- Full test suite and static analysis pass.
- Documentation reflects the new behavior.

---

## Out of Scope

These are intentionally not part of this remediation:

- Live iyzico sandbox execution.
- New billing portal or UI work.
- New provider APIs beyond the existing `PaymentProviderInterface`.
- A new iyzico manual payment-link recovery product flow.
- Database schema expansion unless implementation proves it is unavoidable.

If a future cancellation-pending lifecycle is desired, it should be designed as a separate phase because it affects statuses, docs, notifications, and host-application expectations.
