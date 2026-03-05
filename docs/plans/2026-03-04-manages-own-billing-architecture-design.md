# Billing Orchestration and Provider Boundaries Design

> **Date**: 2026-03-04
> **Scope**: Master + Phase 1-5 alignment gate
> **Status**: Mandatory architecture contract

---

## Problem Statement

Provider implementation drift caused responsibility leaks:

- Provider adapter started mutating `Subscription`/`Transaction` state.
- Provider adapter started dispatching business/domain events.
- Webhook finalization job gained provider-specific branches.

This makes `managesOwnBilling` behavior inconsistent and forces duplicated logic for every new provider.

---

## Target Architecture (3 Layers)

### Layer 1: Provider Adapter (API boundary only)

Provider classes under `src/Payment/Providers/<Provider>/`:

- Build provider requests.
- Verify signatures/hash.
- Parse provider responses and webhook payloads.
- Return typed DTOs (`PaymentResponse`, `WebhookResult`, `SubscriptionResponse`, etc.).

Forbidden in Layer 1:

- Direct model mutation (`Subscription`, `Transaction`, `PaymentMethod`, ...).
- Domain event dispatch (`PaymentCompleted`, `SubscriptionRenewed`, ...).
- Provider-specific branching in shared jobs.

### Layer 2: Billing Orchestration (single mutation point)

`SubscriptionService` (or dedicated orchestrator if split later) is the only place that:

- Updates subscription/transaction state.
- Applies idempotency decisions.
- Runs retry/grace transitions.
- Dispatches generic and provider-specific events.

Required service methods:

- `handleWebhookResult(WebhookResult $result, string $provider): void`
- `handlePaymentResult(PaymentResponse $result, Subscription $subscription): void`

### Layer 3: Event Hierarchy

Generic events live in `src/Events/` and are provider-independent:

- `PaymentCompleted`, `PaymentFailed`
- `SubscriptionCreated`, `SubscriptionCancelled`
- `SubscriptionRenewed`, `SubscriptionRenewalFailed`
- `WebhookReceived`

Provider events live in `src/Payment/Providers/<Provider>/Events/` and extend generic events when needed:

- Example: `IyzicoPaymentCompleted extends PaymentCompleted`

Rule: cross-domain listeners (notifications, licensing, analytics, e-fatura hooks) must depend on generic events.

---

## managesOwnBilling Decision Contract

### `managesOwnBilling = true` (iyzico)

- Provider owns recurring charge execution.
- Package does not perform recurring charge loop for this provider.
- Package ingests webhook/callback results and applies local state through orchestration layer.

### `managesOwnBilling = false` (PayTR)

- Package owns recurring charge execution (scheduler + queue).
- Provider adapter executes charge API calls only.
- Result is normalized and applied through the same orchestration layer.

Both paths must converge in service methods above.

---

## FinalizeWebhookEventJob Contract

`FinalizeWebhookEventJob` is provider-agnostic infrastructure.

- Load webhook payload and provider key.
- Validate signature via provider adapter.
- Call adapter `processWebhook()` to get DTO.
- Delegate DTO to service orchestration.

Forbidden:

- `if ($provider === 'iyzico')` branching for domain behavior.
- Dispatching provider business events directly in the job.

---

## DTO Contract Updates

`WebhookResult` must carry orchestration data, not only processing metadata.

Minimum required fields:

- `processed`, `eventId`, `eventType`, `duplicate`, `message`
- `subscriptionId`, `transactionId`
- `amount`, `status`, `nextBillingDate`
- optional provider metadata bag for traceability

---

## Phase Ownership Mapping

- **Phase 1**: Define contracts and orchestration method signatures; lock architecture rules in plan.
- **Phase 2**: Apply architecture for iyzico; remove provider mutation/event dispatch from adapter and finalizer.
- **Phase 3**: Implement PayTR with same contract; no duplication of mutation/event logic.
- **Phase 4**: License bridge listens to generic billing/subscription events only.
- **Phase 5**: Add architecture conformance tests (provider purity, job agnosticism, event hierarchy).

---

## Acceptance Gates (Non-Negotiable)

Before declaring Phase 2 or 3 complete:

1. No direct model writes inside provider adapters.
2. No domain `Event::dispatch()` inside provider adapters.
3. `FinalizeWebhookEventJob` has no provider-specific domain branching.
4. Service orchestration methods exist and are covered by tests.
5. Generic events exist and provider events extend or wrap them.
