# Documentation Phase 2: System Model and Core Reference Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first deep public reference layer so readers can understand how the package is structured internally without reading historical plan files.

**Architecture:** Phase 2 adds the system-model documents that explain orchestration, billing, licensing, provider boundaries, persistent data, and async flow. It creates canonical public references for these topics and lightly re-links the existing overview docs so the new reference layer is discoverable without bloating the entry path.

**Tech Stack:** Markdown, repository documentation standards, `src/LaravelSubscriptionGuardServiceProvider.php`, `src/Subscription/SubscriptionService.php`, `src/Licensing/*`, `src/Models/*`, `src/Events/*`, `src/Jobs/*`, `database/migrations/*`, feature and unit tests

---

## Scope Guardrails

- Do not collapse Phase 2 into provider-specific API payload docs; those belong to Phase 3.
- Do not turn Phase 2 into runtime-ops or security docs; those belong to later phases.
- Do not rewrite entry docs into long-form architecture manuals.
- Use current code and tests as the source of truth, not historical phase prose.
- Keep `docs/PROVIDERS.md` and `docs/LICENSING.md` as overview/bridge docs unless a focused re-link is needed.
- Do not add links to files that do not exist at Phase 2 completion.

## File Map

### Files to Create

- `docs/ARCHITECTURE.md`
  Purpose: explain the package as a coherent runtime system, including boundaries, orchestration, registration, and major flows.
- `docs/DOMAIN-BILLING.md`
  Purpose: explain billing concepts, lifecycle states, renewal/dunning behavior, scheduled plan changes, metered billing, and billing ownership.
- `docs/DOMAIN-LICENSING.md`
  Purpose: explain signed-license lifecycle, validation rules, activation model, revocation/heartbeat behavior, and billing-to-license bridging.
- `docs/DOMAIN-PROVIDERS.md`
  Purpose: explain provider abstraction boundaries, ownership model differences, event-dispatch responsibilities, and adapter contracts.
- `docs/DATA-MODEL.md`
  Purpose: explain the persistent model/table landscape and the relationships that matter for readers and maintainers.
- `docs/EVENTS-AND-JOBS.md`
  Purpose: explain the sync/async execution flow, event emission points, provider events, and background jobs.

### Files to Modify

- `README.md`
  Purpose: expose the new core-reference layer in the public docs navigation without expanding the README into a deep manual.
- `docs/CONFIGURATION.md`
  Purpose: point configuration readers to the new canonical domain docs where appropriate.
- `docs/PROVIDERS.md`
  Purpose: remain the high-level provider overview and route readers to the new provider-domain reference.
- `docs/LICENSING.md`
  Purpose: remain the high-level licensing overview and route readers to the new licensing-domain reference.
- `docs/plans/documentation-master-plan.md`
  Purpose: update Phase 2 status and next-step metadata at close.
- `docs/plans/phase-2-documentation-system-model-and-core-reference/work-results.md`
  Purpose: record actual execution results at close.
- `docs/plans/phase-2-documentation-system-model-and-core-reference/risk-notes.md`
  Purpose: record actual risks, residual debt, and later-phase handoff at close.

### Files to Review Before and During Execution

- `docs/DOCUMENTATION-STANDARDS.md`
- `docs/plans/2026-04-06-documentation-architecture-design.md`
- `docs/plans/documentation-master-plan.md`
- `docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md`
- `docs/plans/phase-0-documentation-baseline-and-governance/public-docs-gap-analysis.md`
- `docs/plans/phase-1-documentation-entry-path-and-first-success/work-results.md`
- `docs/plans/phase-1-documentation-entry-path-and-first-success/risk-notes.md`
- `README.md`
- `docs/API.md`
- `docs/CONFIGURATION.md`
- `docs/PROVIDERS.md`
- `docs/LICENSING.md`
- `src/LaravelSubscriptionGuardServiceProvider.php`
- `src/Subscription/SubscriptionService.php`
- `src/Licensing/LicenseManager.php`
- `src/Licensing/LicenseRevocationStore.php`
- `src/Licensing/LicenseSignature.php`
- `src/Licensing/Listeners/LicenseLifecycleListener.php`
- `src/Payment/PaymentManager.php`
- `src/Payment/ProviderEvents/ProviderEventDispatcherResolver.php`
- `src/Payment/Providers/Iyzico/*`
- `src/Payment/Providers/PayTR/*`
- `src/Models/*`
- `src/Events/*`
- `src/Jobs/*`
- `database/migrations/*`
- `routes/webhooks.php`
- `tests/Feature/PhaseOneBillingOrchestrationTest.php`
- `tests/Feature/PhaseTwoIyzicoProviderTest.php`
- `tests/Feature/PhaseThreePaytrProviderTest.php`
- `tests/Feature/PhaseFourLicenseManagerTest.php`
- `tests/Feature/PhaseFourLicenseLifecycleListenerTest.php`
- `tests/Feature/PhaseFourLicenseValidationEndpointTest.php`
- `tests/Feature/PhaseFourOperationsTest.php`
- `tests/Feature/PhaseFiveEndToEndFlowTest.php`
- `tests/Feature/PhaseTenSubscriptionServiceTest.php`
- `tests/Feature/PhaseTenLicenseManagerTest.php`
- `tests/Feature/PhaseTenPaymentCallbackTest.php`
- `tests/Feature/PhaseTenDunningExhaustionTest.php`
- `tests/Feature/PhaseTenRefundFlowTest.php`
- `tests/Feature/PhaseElevenWebhookRateLimitTest.php`
- `tests/Feature/PhaseElevenBillingPeriodTest.php`
- `tests/Feature/PhaseElevenMeteredPeriodTest.php`
- `tests/Unit/Models/*`

## Chunk 1: Architecture Baseline

### Task 1: Lock the System Model Inputs

**Files:**
- Review: `docs/DOCUMENTATION-STANDARDS.md`
- Review: `docs/plans/2026-04-06-documentation-architecture-design.md`
- Review: `docs/plans/documentation-master-plan.md`
- Review: `docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md`
- Review: `docs/plans/phase-1-documentation-entry-path-and-first-success/work-results.md`
- Review: `src/LaravelSubscriptionGuardServiceProvider.php`
- Review: `src/Subscription/SubscriptionService.php`
- Review: `routes/webhooks.php`

- [ ] **Step 1: Re-read the standards, design basis, and phase handoff**

Run:
```bash
sed -n '1,260p' docs/DOCUMENTATION-STANDARDS.md
sed -n '1,240p' docs/plans/2026-04-06-documentation-architecture-design.md
sed -n '1,260p' docs/plans/documentation-master-plan.md
sed -n '1,220p' docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md
sed -n '1,220p' docs/plans/phase-1-documentation-entry-path-and-first-success/work-results.md
sed -n '1,220p' docs/plans/phase-1-documentation-entry-path-and-first-success/risk-notes.md
```

Expected:
- Phase 2 scope, canonical-source rules, and handoff constraints are explicit before writing.

- [ ] **Step 2: Capture the package structure evidence for Phase 2**

Run:
```bash
rg --files src/Models src/Events src/Jobs src/Licensing src/Subscription src/Payment/Providers src/Features
rg --files database/migrations
rg -n 'Route::|->name\\(|protected \\$signature = |class .* extends Model|class .* implements|event\\(|dispatch\\(' src routes tests
```

Expected:
- The architecture docs are anchored to the real package surface, not memory.

### Task 2: Write `docs/ARCHITECTURE.md`

**Files:**
- Create: `docs/ARCHITECTURE.md`
- Review: `src/LaravelSubscriptionGuardServiceProvider.php`
- Review: `src/Subscription/SubscriptionService.php`
- Review: `src/Payment/PaymentManager.php`
- Review: `src/Payment/ProviderEvents/ProviderEventDispatcherResolver.php`
- Review: `routes/webhooks.php`
- Review: `docs/API.md`

- [ ] **Step 1: Draft `docs/ARCHITECTURE.md` around system boundaries**

Required sections:
- package purpose and runtime actors,
- service-provider registration and container bindings,
- route registration model,
- core orchestration path through `SubscriptionService`,
- provider ownership split,
- sync vs async boundaries,
- key safety rules and boundaries,
- document map for deeper references.

- [ ] **Step 2: Verify architecture claims against code**

Run:
```bash
sed -n '1,260p' src/LaravelSubscriptionGuardServiceProvider.php
sed -n '1,260p' src/Subscription/SubscriptionService.php
sed -n '1,220p' routes/webhooks.php
```

Expected:
- Architecture doc statements line up with the actual registration and orchestration code.

- [ ] **Step 3: Commit the architecture document**

Run:
```bash
git add docs/ARCHITECTURE.md
git commit -m "docs: add architecture reference"
```

## Chunk 2: Billing and Provider Domain References

### Task 3: Write `docs/DOMAIN-BILLING.md`

**Files:**
- Create: `docs/DOMAIN-BILLING.md`
- Review: `src/Subscription/SubscriptionService.php`
- Review: `src/Billing/MeteredBillingProcessor.php`
- Review: `src/Billing/SeatManager.php`
- Review: `src/Subscription/DiscountService.php`
- Review: `src/Commands/ProcessRenewalsCommand.php`
- Review: `src/Commands/ProcessDunningCommand.php`
- Review: `src/Commands/SuspendOverdueCommand.php`
- Review: `src/Commands/ProcessMeteredBillingCommand.php`
- Review: `src/Commands/ProcessPlanChangesCommand.php`
- Review: `tests/Feature/PhaseOneBillingOrchestrationTest.php`
- Review: `tests/Feature/PhaseFiveEndToEndFlowTest.php`
- Review: `tests/Feature/PhaseTenDunningExhaustionTest.php`
- Review: `tests/Feature/PhaseElevenBillingPeriodTest.php`
- Review: `tests/Feature/PhaseElevenMeteredPeriodTest.php`

- [ ] **Step 1: Write the billing-domain document around behaviors, not file lists**

Required sections:
- billing ownership model,
- subscription lifecycle and billing states,
- renewal flow,
- dunning and suspension flow,
- scheduled plan changes,
- discounts and coupons,
- metered billing,
- seat management,
- invoice and notification touchpoints,
- links to deeper runtime docs that will come later.

- [ ] **Step 2: Verify command and flow references**

Run:
```bash
rg -n 'protected \\$signature = ' src/Commands src/Payment/Providers/Iyzico/Commands
rg -n 'processRenewals|processDunning|processScheduledPlanChanges|retryPastDuePayments|metered|seat|discount' src/Subscription src/Billing tests/Feature
```

Expected:
- Billing docs mention only real commands, flows, and supported concepts.

- [ ] **Step 3: Commit the billing-domain doc**

Run:
```bash
git add docs/DOMAIN-BILLING.md
git commit -m "docs: add billing domain reference"
```

### Task 4: Write `docs/DOMAIN-PROVIDERS.md` and re-link `docs/PROVIDERS.md`

**Files:**
- Create: `docs/DOMAIN-PROVIDERS.md`
- Modify: `docs/PROVIDERS.md`
- Review: `src/Payment/PaymentManager.php`
- Review: `src/Contracts/PaymentProviderInterface.php`
- Review: `src/Contracts/ProviderEventDispatcherInterface.php`
- Review: `src/Payment/ProviderEvents/ProviderEventDispatcherResolver.php`
- Review: `src/Payment/Providers/Iyzico/IyzicoProvider.php`
- Review: `src/Payment/Providers/Iyzico/IyzicoProviderEventDispatcher.php`
- Review: `src/Payment/Providers/PayTR/PaytrProvider.php`
- Review: `src/Payment/Providers/PayTR/PaytrProviderEventDispatcher.php`
- Review: `tests/Feature/PhaseTwoIyzicoProviderTest.php`
- Review: `tests/Feature/PhaseThreePaytrProviderTest.php`

- [ ] **Step 1: Write the provider-domain reference**

Required sections:
- provider abstraction contract,
- payment manager and default-provider resolution,
- provider-managed vs package-managed billing,
- provider event dispatch boundary,
- webhook/callback normalization expectations,
- mock/live validation boundary,
- where provider-specific docs will split in Phase 3.

- [ ] **Step 2: Keep `docs/PROVIDERS.md` as the short overview**

Rule:
- preserve it as a high-level landing page,
- add links to `docs/DOMAIN-PROVIDERS.md`,
- and do not yet absorb provider-specific payload/reference details.

- [ ] **Step 3: Verify provider ownership and adapter statements**

Run:
```bash
sed -n '1,240p' src/Payment/PaymentManager.php
sed -n '1,220p' src/Contracts/PaymentProviderInterface.php
sed -n '1,220p' src/Contracts/ProviderEventDispatcherInterface.php
rg -n 'manages_own_billing|dispatch\\(' src/Payment tests/Feature/PhaseTwoIyzicoProviderTest.php tests/Feature/PhaseThreePaytrProviderTest.php
```

Expected:
- Provider-domain docs reflect the real contract and ownership split.

- [ ] **Step 4: Commit the provider-domain docs**

Run:
```bash
git add docs/DOMAIN-PROVIDERS.md docs/PROVIDERS.md
git commit -m "docs: add provider domain reference"
```

## Chunk 3: Licensing and Data Model References

### Task 5: Write `docs/DOMAIN-LICENSING.md` and re-link `docs/LICENSING.md`

**Files:**
- Create: `docs/DOMAIN-LICENSING.md`
- Modify: `docs/LICENSING.md`
- Review: `src/Licensing/LicenseManager.php`
- Review: `src/Licensing/LicenseSignature.php`
- Review: `src/Licensing/LicenseRevocationStore.php`
- Review: `src/Licensing/Listeners/LicenseLifecycleListener.php`
- Review: `src/Features/FeatureGate.php`
- Review: `src/Features/FeatureManager.php`
- Review: `src/Http/Controllers/LicenseValidationController.php`
- Review: `src/Commands/GenerateLicenseCommand.php`
- Review: `src/Commands/CheckLicenseCommand.php`
- Review: `src/Commands/SyncLicenseRevocationsCommand.php`
- Review: `src/Commands/SyncLicenseHeartbeatsCommand.php`
- Review: `tests/Feature/PhaseFourLicenseManagerTest.php`
- Review: `tests/Feature/PhaseFourLicenseLifecycleListenerTest.php`
- Review: `tests/Feature/PhaseFourLicenseValidationEndpointTest.php`
- Review: `tests/Feature/PhaseTenLicenseManagerTest.php`

- [ ] **Step 1: Write the licensing-domain reference**

Required sections:
- signed license structure and signing algorithms,
- validation lifecycle,
- activation and deactivation,
- feature and limit checks,
- offline heartbeat freshness,
- revocation store behavior,
- bridge from subscription events to license state,
- commands and validation endpoint.

- [ ] **Step 2: Keep `docs/LICENSING.md` as the short overview**

Rule:
- preserve it as a quick orientation doc,
- add links to `docs/DOMAIN-LICENSING.md`,
- and avoid duplicating the deeper lifecycle material.

- [ ] **Step 3: Verify licensing references**

Run:
```bash
sed -n '1,260p' src/Licensing/LicenseManager.php
sed -n '1,260p' src/Licensing/LicenseRevocationStore.php
sed -n '1,220p' src/Licensing/Listeners/LicenseLifecycleListener.php
rg -n 'generate-license|check-license|sync-license' src/Commands tests/Feature/PhaseFourLicenseManagerTest.php tests/Feature/PhaseTenLicenseManagerTest.php
```

Expected:
- Licensing docs match actual validation, activation, and bridge behavior.

- [ ] **Step 4: Commit the licensing-domain docs**

Run:
```bash
git add docs/DOMAIN-LICENSING.md docs/LICENSING.md
git commit -m "docs: add licensing domain reference"
```

### Task 6: Write `docs/DATA-MODEL.md`

**Files:**
- Create: `docs/DATA-MODEL.md`
- Review: `src/Models/*`
- Review: `database/migrations/*`
- Review: `tests/Unit/Models/*`
- Review: `tests/Feature/PhaseOneSchemaTest.php`
- Review: `tests/Feature/PhaseElevenModelCastsTest.php`

- [ ] **Step 1: Write the data-model reference by domain group**

Required sections:
- catalog/configuration entities,
- subscription and billing entities,
- payment and webhook entities,
- licensing entities,
- discount/coupon entities,
- operational relationship notes,
- stateful tables and important indexes.

- [ ] **Step 2: Verify table/model coverage**

Run:
```bash
rg --files src/Models
rg --files database/migrations
rg -n 'class .* extends Model' src/Models
```

Expected:
- Every persisted domain concept in the public package surface is represented in the data-model doc.

- [ ] **Step 3: Commit the data-model doc**

Run:
```bash
git add docs/DATA-MODEL.md
git commit -m "docs: add data model reference"
```

## Chunk 4: Events, Jobs, and Navigation Closure

### Task 7: Write `docs/EVENTS-AND-JOBS.md`

**Files:**
- Create: `docs/EVENTS-AND-JOBS.md`
- Review: `src/Events/*`
- Review: `src/Jobs/*`
- Review: `src/Payment/Providers/Iyzico/Events/*`
- Review: `src/Payment/Providers/PayTR/Events/*`
- Review: `src/Http/Controllers/WebhookController.php`
- Review: `src/Http/Controllers/PaymentCallbackController.php`
- Review: `tests/Feature/PhaseOneWebhookFlowTest.php`
- Review: `tests/Feature/PhaseFiveNotificationsAndInvoicesTest.php`
- Review: `tests/Feature/PhaseTenPaymentCallbackTest.php`

- [ ] **Step 1: Write the events-and-jobs reference**

Required sections:
- generic domain events,
- provider-specific events,
- webhook/callback intake and finalization jobs,
- renewal/dunning/plan-change/payment jobs,
- notification dispatch,
- sync vs async transition points,
- queue ownership by concern.

- [ ] **Step 2: Verify async-flow statements**

Run:
```bash
rg --files src/Events src/Jobs src/Payment/Providers/Iyzico/Events src/Payment/Providers/PayTR/Events
rg -n 'FinalizeWebhookEventJob|ProcessRenewalCandidateJob|ProcessDunningRetryJob|ProcessScheduledPlanChangeJob|PaymentChargeJob|DispatchBillingNotificationsJob|Event::dispatch' src tests/Feature
```

Expected:
- The document reflects the actual event/job graph used by the package.

- [ ] **Step 3: Commit the events-and-jobs doc**

Run:
```bash
git add docs/EVENTS-AND-JOBS.md
git commit -m "docs: add events and jobs reference"
```

### Task 8: Cross-Link the New Reference Layer and Close the Phase

**Files:**
- Modify: `README.md`
- Modify: `docs/CONFIGURATION.md`
- Modify: `docs/PROVIDERS.md`
- Modify: `docs/LICENSING.md`
- Modify: `docs/plans/documentation-master-plan.md`
- Modify: `docs/plans/phase-2-documentation-system-model-and-core-reference/work-results.md`
- Modify: `docs/plans/phase-2-documentation-system-model-and-core-reference/risk-notes.md`

- [ ] **Step 1: Add navigation links to the new core-reference docs**

Rule:
- expose the new docs where readers will naturally look,
- keep the entry layer concise,
- and let the new documents be canonical for their topics.

- [ ] **Step 2: Verify that all new cross-links resolve**

Run:
```bash
rg -n '\\]\\(([^)]*\\.md)\\)' README.md docs/CONFIGURATION.md docs/PROVIDERS.md docs/LICENSING.md docs/ARCHITECTURE.md docs/DOMAIN-BILLING.md docs/DOMAIN-LICENSING.md docs/DOMAIN-PROVIDERS.md docs/DATA-MODEL.md docs/EVENTS-AND-JOBS.md
```

Expected:
- The new reference layer is discoverable without dead links.

- [ ] **Step 3: Update phase close-out files and master plan**

Rule:
- mark Phase 2 completed only after the new docs exist and the cross-links verify,
- record verification evidence in `work-results.md`,
- and record residual doc debt in `risk-notes.md`.

- [ ] **Step 4: Commit the cross-link and phase-close updates**

Run:
```bash
git add README.md docs/CONFIGURATION.md docs/PROVIDERS.md docs/LICENSING.md docs/plans/documentation-master-plan.md docs/plans/phase-2-documentation-system-model-and-core-reference/work-results.md docs/plans/phase-2-documentation-system-model-and-core-reference/risk-notes.md
git commit -m "docs: close phase 2 core reference rollout"
```

## Completion Checklist

- [ ] `docs/ARCHITECTURE.md` exists and matches the service-provider/orchestration structure.
- [ ] `docs/DOMAIN-BILLING.md` exists and explains the real billing lifecycle.
- [ ] `docs/DOMAIN-LICENSING.md` exists and explains validation, activation, and bridge logic.
- [ ] `docs/DOMAIN-PROVIDERS.md` exists and explains the provider abstraction boundary.
- [ ] `docs/DATA-MODEL.md` exists and covers the real persistent model landscape.
- [ ] `docs/EVENTS-AND-JOBS.md` exists and reflects the actual async/event flow.
- [ ] `README.md`, `docs/CONFIGURATION.md`, `docs/PROVIDERS.md`, and `docs/LICENSING.md` link into the new reference layer.
- [ ] `docs/plans/documentation-master-plan.md` records Phase 2 completion.
- [ ] `work-results.md` and `risk-notes.md` contain real close-out notes, not placeholders.
