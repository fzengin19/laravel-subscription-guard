# Documentation Phase 3: Provider and Integration Surface Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the provider-specific and inbound integration reference layer so readers can integrate `iyzico` and `paytr`, understand webhook and callback behavior, and extend the package with custom providers without reverse-engineering controllers and tests.

**Architecture:** Phase 3 sits on top of the Phase 2 system-model layer. It adds provider-specific documents under `docs/providers/`, creates dedicated webhook and callback references, and refines `docs/API.md` into a clean index of inbound routes, simulator tooling, and adjacent docs. It does not absorb runtime operations, live sandbox runbooks, or business-flow deep dives that belong to later phases.

**Tech Stack:** Markdown, repository documentation standards, `routes/webhooks.php`, `src/Http/Controllers/*`, `src/Commands/SimulateWebhookCommand.php`, `src/Contracts/*`, `src/Payment/Providers/Iyzico/*`, `src/Payment/Providers/PayTR/*`, provider-related feature/live tests

---

## Scope Guardrails

- Do not turn Phase 3 into a billing or licensing workflow manual; those belong to later phases.
- Do not duplicate the Phase 2 system-model documents; use them as canonical upstream references.
- Do not document secret values, `.env` contents, or operator-specific credentials.
- Do not invent provider payload fields that are not evidenced by current code or tests.
- Do not collapse live sandbox operating instructions into provider reference pages; keep only the provider-facing boundary and route readers toward later runtime docs.
- Do not add links to public docs that do not exist at Phase 3 completion.

## File Map

### Files to Create

- `docs/providers/IYZICO.md`
  Purpose: document `iyzico`-specific payment modes, ownership model, callback behavior, webhook behavior, provider commands, and integration caveats.
- `docs/providers/PAYTR.md`
  Purpose: document `paytr`-specific iframe payment flow, package-managed recurring behavior, webhook hashing rules, and integration expectations.
- `docs/providers/CUSTOM-PROVIDER.md`
  Purpose: document how to add a custom provider safely using the package contracts and ownership rules.
- `docs/WEBHOOKS.md`
  Purpose: document webhook route behavior, idempotency, response semantics, queue handoff, and provider-specific response differences.
- `docs/CALLBACKS.md`
  Purpose: document `3ds` and checkout callback behavior, signature requirements, current provider usage, and persistence/finalization flow.

### Files to Modify

- `README.md`
  Purpose: expose the provider and integration reference layer in public navigation.
- `docs/API.md`
  Purpose: become the canonical route and command entry point for integration readers, with links to deeper provider, webhook, and callback docs.
- `docs/PROVIDERS.md`
  Purpose: remain the short overview and route readers into the new provider-specific references.
- `docs/DOMAIN-PROVIDERS.md`
  Purpose: stay the abstraction-level doc while linking to the new provider-deep docs and inbound references.
- `docs/CONFIGURATION.md`
  Purpose: link provider config readers to the new provider-specific docs where that makes the surface clearer.
- `docs/plans/documentation-master-plan.md`
  Purpose: update Phase 3 planning metadata now and closeout metadata after execution.
- `docs/plans/phase-3-documentation-provider-and-integration-surface/work-results.md`
  Purpose: record actual execution results at close.
- `docs/plans/phase-3-documentation-provider-and-integration-surface/risk-notes.md`
  Purpose: record actual risks, residual debt, and later-phase handoff at close.

### Files to Review Before and During Execution

- `docs/DOCUMENTATION-STANDARDS.md`
- `docs/plans/2026-04-06-documentation-architecture-design.md`
- `docs/plans/documentation-master-plan.md`
- `docs/plans/phase-0-documentation-baseline-and-governance/work-results.md`
- `docs/plans/phase-0-documentation-baseline-and-governance/risk-notes.md`
- `docs/plans/phase-1-documentation-entry-path-and-first-success/work-results.md`
- `docs/plans/phase-1-documentation-entry-path-and-first-success/risk-notes.md`
- `docs/plans/phase-2-documentation-system-model-and-core-reference/work-results.md`
- `docs/plans/phase-2-documentation-system-model-and-core-reference/risk-notes.md`
- `README.md`
- `docs/API.md`
- `docs/CONFIGURATION.md`
- `docs/PROVIDERS.md`
- `docs/ARCHITECTURE.md`
- `docs/DOMAIN-PROVIDERS.md`
- `routes/webhooks.php`
- `src/Http/Controllers/WebhookController.php`
- `src/Http/Controllers/PaymentCallbackController.php`
- `src/Http/Controllers/LicenseValidationController.php`
- `src/Commands/SimulateWebhookCommand.php`
- `src/Contracts/PaymentProviderInterface.php`
- `src/Contracts/ProviderEventDispatcherInterface.php`
- `src/Payment/PaymentManager.php`
- `src/Payment/ProviderEvents/ProviderEventDispatcherResolver.php`
- `src/Payment/Providers/Iyzico/IyzicoProvider.php`
- `src/Payment/Providers/Iyzico/IyzicoProviderEventDispatcher.php`
- `src/Payment/Providers/Iyzico/IyzicoSupport.php`
- `src/Payment/Providers/Iyzico/Commands/SyncPlansCommand.php`
- `src/Payment/Providers/Iyzico/Commands/ReconcileIyzicoSubscriptionsCommand.php`
- `src/Payment/Providers/PayTR/PaytrProvider.php`
- `src/Payment/Providers/PayTR/PaytrProviderEventDispatcher.php`
- `tests/Feature/PhaseTwoIyzicoProviderTest.php`
- `tests/Feature/PhaseThreePaytrProviderTest.php`
- `tests/Feature/PhaseThreePaytrWebhookIngressTest.php`
- `tests/Feature/PhaseFiveWebhookSimulatorCommandTest.php`
- `tests/Feature/PhaseFiveEndToEndFlowTest.php`
- `tests/Feature/PhaseTenPaymentCallbackTest.php`
- `tests/Feature/PhaseElevenWebhookRateLimitTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoPaymentContractsTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoRefundContractTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoWebhookRoundTripTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoSandboxPreflightTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoCardVaultTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoReconcileTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoSubscriptionLifecycleTest.php`
- `tests/Live/Iyzico/PhaseEightIyzicoRemotePlanSyncTest.php`

## Chunk 1: Lock the Integration Surface

### Task 1: Reconfirm the Provider and Inbound Boundaries

**Files:**
- Review: `docs/DOCUMENTATION-STANDARDS.md`
- Review: `docs/plans/2026-04-06-documentation-architecture-design.md`
- Review: `docs/plans/documentation-master-plan.md`
- Review: previous documentation phase closeout files
- Review: `docs/API.md`
- Review: `docs/PROVIDERS.md`
- Review: `docs/DOMAIN-PROVIDERS.md`
- Review: `routes/webhooks.php`
- Review: `src/Http/Controllers/WebhookController.php`
- Review: `src/Http/Controllers/PaymentCallbackController.php`
- Review: `src/Commands/SimulateWebhookCommand.php`

- [ ] **Step 1: Re-read standards, design basis, and phase handoff**

Run:
```bash
sed -n '1,260p' docs/DOCUMENTATION-STANDARDS.md
sed -n '1,260p' docs/plans/2026-04-06-documentation-architecture-design.md
sed -n '1,260p' docs/plans/documentation-master-plan.md
sed -n '1,220p' docs/plans/phase-0-documentation-baseline-and-governance/work-results.md
sed -n '1,220p' docs/plans/phase-0-documentation-baseline-and-governance/risk-notes.md
sed -n '1,220p' docs/plans/phase-1-documentation-entry-path-and-first-success/work-results.md
sed -n '1,220p' docs/plans/phase-1-documentation-entry-path-and-first-success/risk-notes.md
sed -n '1,220p' docs/plans/phase-2-documentation-system-model-and-core-reference/work-results.md
sed -n '1,220p' docs/plans/phase-2-documentation-system-model-and-core-reference/risk-notes.md
```

Expected:
- Phase 3 scope and non-goals are explicit before any new provider docs are written.

- [ ] **Step 2: Capture route, controller, and simulator evidence**

Run:
```bash
sed -n '1,220p' routes/webhooks.php
sed -n '1,260p' src/Http/Controllers/WebhookController.php
sed -n '1,260p' src/Http/Controllers/PaymentCallbackController.php
sed -n '1,240p' src/Commands/SimulateWebhookCommand.php
rg -n "simulate-webhook|threeDs|checkout|webhooks.handle|3ds-callback|checkout-callback|WebhookController|PaymentCallbackController" tests/Feature tests/Live src
```

Expected:
- The public inbound docs will be anchored to actual routes, status codes, lock behavior, and test coverage.

## Chunk 2: Provider-Specific Reference Docs

### Task 2: Write `docs/providers/IYZICO.md`

**Files:**
- Create: `docs/providers/IYZICO.md`
- Review: `src/Payment/Providers/Iyzico/IyzicoProvider.php`
- Review: `src/Payment/Providers/Iyzico/IyzicoProviderEventDispatcher.php`
- Review: `src/Payment/Providers/Iyzico/IyzicoSupport.php`
- Review: `src/Payment/Providers/Iyzico/Commands/SyncPlansCommand.php`
- Review: `src/Payment/Providers/Iyzico/Commands/ReconcileIyzicoSubscriptionsCommand.php`
- Review: `tests/Feature/PhaseTwoIyzicoProviderTest.php`
- Review: `tests/Feature/PhaseTenPaymentCallbackTest.php`
- Review: `tests/Live/Iyzico/*`

- [ ] **Step 1: Write the iyzico provider document around concrete integration behavior**

Required sections:
- provider role and billing ownership,
- supported payment modes (`non_3ds`, `3ds`, `checkout_form`),
- subscription lifecycle operations and remote-first renewal implications,
- callback URL behavior and route expectations,
- webhook signature/header behavior,
- plan sync and reconcile commands,
- mock vs live boundary,
- current live sandbox caveats and what still belongs to later runtime docs.

- [ ] **Step 2: Verify iyzico claims against code and tests**

Run:
```bash
sed -n '1,260p' src/Payment/Providers/Iyzico/IyzicoProvider.php
sed -n '1,220p' src/Payment/Providers/Iyzico/Commands/SyncPlansCommand.php
sed -n '1,220p' src/Payment/Providers/Iyzico/Commands/ReconcileIyzicoSubscriptionsCommand.php
rg -n "checkout_form|3ds|callback_url|signature|sync-plans|reconcile" tests/Feature/PhaseTwoIyzicoProviderTest.php tests/Feature/PhaseTenPaymentCallbackTest.php tests/Live/Iyzico
```

Expected:
- The iyzico doc reflects the real adapter surface, not assumptions carried over from historical phase prose.

- [ ] **Step 3: Commit the iyzico provider doc**

Run:
```bash
git add docs/providers/IYZICO.md
git commit -m "docs: add iyzico provider reference"
```

### Task 3: Write `docs/providers/PAYTR.md`

**Files:**
- Create: `docs/providers/PAYTR.md`
- Review: `src/Payment/Providers/PayTR/PaytrProvider.php`
- Review: `src/Payment/Providers/PayTR/PaytrProviderEventDispatcher.php`
- Review: `tests/Feature/PhaseThreePaytrProviderTest.php`
- Review: `tests/Feature/PhaseThreePaytrWebhookIngressTest.php`
- Review: `tests/Feature/PhaseFiveEndToEndFlowTest.php`

- [ ] **Step 1: Write the PayTR provider document around self-managed recurring billing**

Required sections:
- provider role and ownership mode,
- iframe payment flow and returned response data,
- create/cancel/upgrade/refund/charge-recurring behavior,
- webhook hash validation inputs and response format expectations,
- package-managed renewal and dunning implications,
- mock vs live behavior,
- integration caveats and next-step docs.

- [ ] **Step 2: Verify PayTR claims against code and tests**

Run:
```bash
sed -n '1,260p' src/Payment/Providers/PayTR/PaytrProvider.php
rg -n "iframe|merchant_key|merchant_salt|status|hash|chargeRecurring|refund" tests/Feature/PhaseThreePaytrProviderTest.php tests/Feature/PhaseThreePaytrWebhookIngressTest.php tests/Feature/PhaseFiveEndToEndFlowTest.php
```

Expected:
- The PayTR doc describes the actual hash/signature path, response shape, and ownership consequences.

- [ ] **Step 3: Commit the PayTR provider doc**

Run:
```bash
git add docs/providers/PAYTR.md
git commit -m "docs: add paytr provider reference"
```

### Task 4: Write `docs/providers/CUSTOM-PROVIDER.md`

**Files:**
- Create: `docs/providers/CUSTOM-PROVIDER.md`
- Review: `src/Contracts/PaymentProviderInterface.php`
- Review: `src/Contracts/ProviderEventDispatcherInterface.php`
- Review: `src/Payment/PaymentManager.php`
- Review: `src/Payment/ProviderEvents/ProviderEventDispatcherResolver.php`
- Review: `docs/DOMAIN-PROVIDERS.md`

- [ ] **Step 1: Write the custom-provider integration guide**

Required sections:
- when a custom provider is needed,
- required contracts,
- choosing `manages_own_billing`,
- webhook normalization expectations,
- provider-event dispatcher expectations,
- config registration rules,
- test strategy expectations,
- explicit "do not mutate package models directly" boundary.

- [ ] **Step 2: Verify custom-provider guidance against the current contracts**

Run:
```bash
sed -n '1,220p' src/Contracts/PaymentProviderInterface.php
sed -n '1,220p' src/Contracts/ProviderEventDispatcherInterface.php
sed -n '1,220p' src/Payment/PaymentManager.php
sed -n '1,220p' src/Payment/ProviderEvents/ProviderEventDispatcherResolver.php
```

Expected:
- The custom-provider guide remains implementable from current contracts.

- [ ] **Step 3: Commit the custom-provider guide**

Run:
```bash
git add docs/providers/CUSTOM-PROVIDER.md
git commit -m "docs: add custom provider integration guide"
```

## Chunk 3: Webhooks, Callbacks, and Route Surface

### Task 5: Write `docs/WEBHOOKS.md`

**Files:**
- Create: `docs/WEBHOOKS.md`
- Review: `routes/webhooks.php`
- Review: `src/Http/Controllers/WebhookController.php`
- Review: `src/Jobs/FinalizeWebhookEventJob.php`
- Review: `tests/Feature/PhaseOneWebhookFlowTest.php`
- Review: `tests/Feature/PhaseFiveEndToEndFlowTest.php`
- Review: `tests/Feature/PhaseTenConcurrencyTest.php`
- Review: `tests/Feature/PhaseElevenWebhookRateLimitTest.php`

- [ ] **Step 1: Write the webhook reference around transport and processing rules**

Required sections:
- route shape and current named route,
- accepted providers and provider lookup failure behavior,
- empty payload rejection,
- event type and event id derivation,
- `WebhookCall` persistence and duplicate handling,
- lock and retry behavior,
- queue handoff to finalization,
- response semantics including provider-specific text response overrides,
- where signature validation happens and why intake does not finalize billing directly.

- [ ] **Step 2: Verify webhook behavior against controllers, jobs, and tests**

Run:
```bash
sed -n '1,220p' routes/webhooks.php
sed -n '1,260p' src/Http/Controllers/WebhookController.php
sed -n '1,240p' src/Jobs/FinalizeWebhookEventJob.php
rg -n "duplicate|lock timeout|accepted|retry|webhook_response_format|throttle" tests/Feature/PhaseOneWebhookFlowTest.php tests/Feature/PhaseFiveEndToEndFlowTest.php tests/Feature/PhaseTenConcurrencyTest.php tests/Feature/PhaseElevenWebhookRateLimitTest.php
```

Expected:
- Webhook docs clearly separate HTTP acceptance from later business processing.

- [ ] **Step 3: Commit the webhook reference**

Run:
```bash
git add docs/WEBHOOKS.md
git commit -m "docs: add webhook integration reference"
```

### Task 6: Write `docs/CALLBACKS.md`

**Files:**
- Create: `docs/CALLBACKS.md`
- Review: `routes/webhooks.php`
- Review: `src/Http/Controllers/PaymentCallbackController.php`
- Review: `tests/Feature/PhaseTwoIyzicoProviderTest.php`
- Review: `tests/Feature/PhaseTenPaymentCallbackTest.php`

- [ ] **Step 1: Write the callback reference around current provider usage**

Required sections:
- route shapes for `3ds` and checkout callbacks,
- current provider applicability,
- signature header lookup and validation path,
- failure responses for unknown providers, empty payloads, and invalid signatures,
- event id derivation, duplicate handling, and queue handoff,
- relation to iyzico payment modes and callback URL config.

- [ ] **Step 2: Verify callback behavior against code and tests**

Run:
```bash
sed -n '1,220p' routes/webhooks.php
sed -n '1,260p' src/Http/Controllers/PaymentCallbackController.php
rg -n "3ds|checkout|Invalid callback signature|duplicate|accepted" tests/Feature/PhaseTwoIyzicoProviderTest.php tests/Feature/PhaseTenPaymentCallbackTest.php
```

Expected:
- Callback docs remain precise about transport semantics and current provider scope.

- [ ] **Step 3: Commit the callback reference**

Run:
```bash
git add docs/CALLBACKS.md
git commit -m "docs: add callback integration reference"
```

## Chunk 4: API Refinement and Discoverability

### Task 7: Refine `docs/API.md` and bridge docs into the new layer

**Files:**
- Modify: `README.md`
- Modify: `docs/API.md`
- Modify: `docs/PROVIDERS.md`
- Modify: `docs/DOMAIN-PROVIDERS.md`
- Modify: `docs/CONFIGURATION.md`
- Review: `src/Http/Controllers/LicenseValidationController.php`
- Review: `src/Commands/SimulateWebhookCommand.php`

- [ ] **Step 1: Rewrite `docs/API.md` as the route and command index**

Required sections:
- inbound route summary,
- license validation route summary,
- webhook simulator command,
- operational command surface summary,
- doc map linking to provider, webhook, callback, architecture, and domain references.

- [ ] **Step 2: Update bridge docs without duplicating deep provider content**

Rules:
- `README.md` should expose the new layer but remain navigational.
- `docs/PROVIDERS.md` should stay short and route readers into `docs/providers/*`.
- `docs/DOMAIN-PROVIDERS.md` should keep the abstraction boundary and point down into provider-specific docs.
- `docs/CONFIGURATION.md` should link to deeper provider docs only where it helps interpret config behavior.

- [ ] **Step 3: Verify links and route/command accuracy**

Run:
```bash
sed -n '1,220p' src/Http/Controllers/LicenseValidationController.php
sed -n '1,240p' src/Commands/SimulateWebhookCommand.php
rg -n 'protected \\$signature = ' src/Commands src/Payment/Providers/Iyzico/Commands
rg -n '\\]\\(([^)]*\\.md)\\)' README.md docs/API.md docs/PROVIDERS.md docs/DOMAIN-PROVIDERS.md docs/CONFIGURATION.md docs/WEBHOOKS.md docs/CALLBACKS.md docs/providers/IYZICO.md docs/providers/PAYTR.md docs/providers/CUSTOM-PROVIDER.md
```

Expected:
- The API and bridge docs point only to existing documents and mention only real routes and commands.

- [ ] **Step 4: Commit the API refinement and bridge updates**

Run:
```bash
git add README.md docs/API.md docs/PROVIDERS.md docs/DOMAIN-PROVIDERS.md docs/CONFIGURATION.md
git commit -m "docs: refine integration entry points"
```

## Chunk 5: Phase Closure

### Task 8: Close Phase 3 cleanly

**Files:**
- Modify: `docs/plans/documentation-master-plan.md`
- Modify: `docs/plans/phase-3-documentation-provider-and-integration-surface/work-results.md`
- Modify: `docs/plans/phase-3-documentation-provider-and-integration-surface/risk-notes.md`

- [ ] **Step 1: Write execution results and risk notes after the docs are finished**

Required closeout contents:
- what was created and modified,
- verification commands actually run,
- residual provider/runtime gaps,
- risks observed during the phase,
- recommendations for Phase 4 and later.

- [ ] **Step 2: Update master-plan status**

Update:
- Phase 3 status,
- detailed-plan continuity,
- next-step sentence for the next documentation phase.

- [ ] **Step 3: Run final cross-link and scope checks**

Run:
```bash
git diff --stat
rg -n 'To be written|TODO|placeholder' docs/providers docs/WEBHOOKS.md docs/CALLBACKS.md docs/API.md docs/plans/phase-3-documentation-provider-and-integration-surface
```

Expected:
- The new public-doc layer is complete enough to close Phase 3 without dead stubs or fake coverage.
