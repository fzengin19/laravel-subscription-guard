# Laravel Subscription Guard Documentation Master Plan

> **Version**: 1.0
> **Date**: 2026-04-06
> **Status**: Planning
> **For agentic workers**: This file is a roadmap only. Detailed execution belongs in dedicated documentation phase plan files.

**Goal**: Build a professional, layered documentation system that matches the real package surface area and separates public documentation from internal execution history.

**Architecture**: Use a layered hybrid documentation model with entry docs, system reference docs, provider/integration docs, runtime operations docs, applied workflow docs, and contributor-facing maintenance docs.

**Tech Stack**: Markdown documentation, Laravel package conventions, repository planning rules under `docs/plans`

---

## Overview

This master plan defines the public documentation program for Laravel Subscription Guard.

Its purpose is to transform the current documentation set into a professional, scalable, and trustworthy documentation system that reflects the real package surface area.

This file is a roadmap document. It defines direction, scope, and phase boundaries. Detailed execution steps will be written in separate phase plans.

---

## Strategic Objectives

### Core Objectives

1. Build a documentation system that matches the actual package capabilities.
2. Separate public package documentation from internal development history.
3. Make the package understandable from first install to advanced runtime operations.
4. Reduce duplication and ambiguity by assigning one clear purpose to each document.
5. Create a documentation program that can evolve with future providers, billing features, and hardening phases.

### Quality Objectives

- Professional information architecture
- English-first public docs
- Clear public/internal boundary
- Consistent terminology and cross-linking
- Accurate command, route, config, and runtime behavior coverage
- Phase-based execution to avoid uncontrolled bulk rewriting

---

## Current-State Diagnosis

The repository already contains valuable documentation, but it is unbalanced.

### Strengths

- Internal planning material is detailed.
- Risk and work-results files preserve implementation history.
- Several public docs already exist and can be expanded instead of replaced.
- Test suite names and coverage areas expose the real system scope clearly.

### Weaknesses

- Public docs are too shallow for the real package behavior.
- Core system concepts are distributed across plans instead of public reference docs.
- Some repo status signals are inconsistent across documentation layers.
- Provider, billing, licensing, and runtime topics are not decomposed cleanly.
- New readers do not get a reliable path from entry docs to deep reference docs.

---

## Documentation Architecture

The target architecture is a layered hybrid model.

### Layer 1: Entry and Orientation

Primary purpose: introduce the package, guide first setup, and route readers to the right next document.

Target documents:

- `README.md`
- `docs/INSTALLATION.md`
- `docs/QUICKSTART.md`
- `docs/CONFIGURATION.md`
- `docs/FAQ.md`

### Layer 2: System and Architecture

Primary purpose: explain the package as a coherent system.

Target documents:

- `docs/ARCHITECTURE.md`
- `docs/DOMAIN-BILLING.md`
- `docs/DOMAIN-LICENSING.md`
- `docs/DOMAIN-PROVIDERS.md`
- `docs/DATA-MODEL.md`
- `docs/EVENTS-AND-JOBS.md`

### Layer 3: Provider and Integration Reference

Primary purpose: explain provider-specific contracts and inbound integration surfaces.

Target documents:

- `docs/providers/IYZICO.md`
- `docs/providers/PAYTR.md`
- `docs/providers/CUSTOM-PROVIDER.md`
- `docs/API.md`
- `docs/WEBHOOKS.md`
- `docs/CALLBACKS.md`

### Layer 4: Runtime and Operations

Primary purpose: explain how the package is run, operated, validated, and secured.

Target documents:

- `docs/COMMANDS.md`
- `docs/QUEUES-AND-JOBS.md`
- `docs/LIVE-SANDBOX.md`
- `docs/TROUBLESHOOTING.md`
- `docs/SECURITY.md`

### Layer 5: Applied Workflows

Primary purpose: explain practical billing and licensing scenarios.

Target documents:

- `docs/RECIPES.md`
- `docs/USE-CASES.md`
- `docs/INVOICING.md`
- `docs/DUNNING-AND-RETRIES.md`
- `docs/METERED-BILLING.md`
- `docs/SEAT-BASED-BILLING.md`

### Layer 6: Contributor Surface

Primary purpose: support maintainers and future documentation quality.

Target documents:

- `docs/TESTING.md`
- `docs/CONTRIBUTING.md`
- `docs/CHANGELOG-POLICY.md`

---

## Governance Rules

### Public vs Internal Rule

Public docs describe the package as it works now.

Internal plan files describe implementation history, execution sequence, and phase outcomes.

If a fact is required to install, integrate, operate, or trust the package, that fact must exist in public docs.

### Canonical Source Rule

Each major topic must have one primary document. Other docs should summarize briefly and link, not duplicate.

### No-Silent-Drift Rule

When code behavior changes materially, public docs and internal plan status must be reviewed together.

### Secret Hygiene Rule

Environment examples may use placeholders only. Real secrets, `.env` contents, and credential dumps are never documentation assets.

---

## Proposed Execution Phases

### Phase 0: Documentation Baseline and Governance

**Detailed Plan**: `phase-0-documentation-baseline-and-governance/plan.md`
**Status**: Completed (2026-04-06)

Purpose:

- lock documentation scope,
- define document ownership,
- reconcile current repo documentation status,
- and establish writing standards before rewriting content.

Expected outputs:

- documentation standards and scope decisions,
- normalized current-state inventory,
- confirmed target document tree,
- resolved status inconsistencies in planning signals.

### Phase 1: Entry Path and First Success

**Detailed Plan**: `phase-1-documentation-entry-path-and-first-success/plan.md`
**Status**: Completed (2026-04-06)

Purpose:

- turn the package front door into a trustworthy onboarding path.

Expected outputs:

- rewritten `README.md`,
- expanded `docs/INSTALLATION.md`,
- new `docs/QUICKSTART.md`,
- improved `docs/CONFIGURATION.md`,
- initial reader navigation map.

### Phase 2: System Model and Core Reference

**Detailed Plan**: `phase-2-documentation-system-model-and-core-reference/plan.md`
**Status**: Completed (2026-04-06)

Purpose:

- explain how billing, licensing, providers, models, events, and jobs fit together.

Expected outputs:

- `docs/ARCHITECTURE.md`,
- `docs/DOMAIN-BILLING.md`,
- `docs/DOMAIN-LICENSING.md`,
- `docs/DOMAIN-PROVIDERS.md`,
- `docs/DATA-MODEL.md`,
- `docs/EVENTS-AND-JOBS.md`.

### Phase 3: Provider and Integration Surface

**Detailed Plan**: `phase-3-documentation-provider-and-integration-surface/plan.md`
**Status**: Planning

Purpose:

- document provider-specific behavior and inbound integration contracts.

Expected outputs:

- `docs/providers/IYZICO.md`,
- `docs/providers/PAYTR.md`,
- `docs/providers/CUSTOM-PROVIDER.md`,
- `docs/WEBHOOKS.md`,
- `docs/CALLBACKS.md`,
- refined `docs/API.md`.

### Phase 4: Billing and Licensing Operational Flows

Purpose:

- explain the real business flows the package supports.

Expected outputs:

- `docs/DUNNING-AND-RETRIES.md`,
- `docs/METERED-BILLING.md`,
- `docs/SEAT-BASED-BILLING.md`,
- `docs/INVOICING.md`,
- expanded licensing and billing workflow guidance.

### Phase 5: Runtime Operations, Testing, and Security

Purpose:

- document how the package runs in real environments and how it is verified safely.

Expected outputs:

- `docs/COMMANDS.md`,
- `docs/QUEUES-AND-JOBS.md`,
- `docs/LIVE-SANDBOX.md`,
- `docs/TESTING.md`,
- `docs/SECURITY.md`,
- `docs/TROUBLESHOOTING.md`.

### Phase 6: Consistency, Navigation, and Release Readiness

Purpose:

- connect the full documentation system, remove dead ends, and prepare it for long-term maintenance.

Expected outputs:

- `docs/FAQ.md`,
- `docs/USE-CASES.md`,
- contributor-facing maintenance docs,
- cross-link audit,
- terminology normalization,
- final completeness review.

---

## Phase Dependency Logic

The documentation program must follow this order:

1. Governance before mass rewriting
2. Entry docs before deep references
3. Core system model before provider detail
4. Provider detail before advanced business workflows
5. Runtime/test/security docs after the system model is stable
6. Final navigation and polish after content coverage exists

This sequencing prevents early duplication and avoids writing detailed docs on top of an unstable information architecture.

---

## Existing Docs Treatment Strategy

### Expand and Retain

- `README.md`
- `docs/INSTALLATION.md`
- `docs/CONFIGURATION.md`
- `docs/API.md`
- `docs/RECIPES.md`

### Split and Re-scope

- `docs/PROVIDERS.md`
- `docs/LICENSING.md`

### Add New Documents

- architecture, domain, provider-specific, runtime, troubleshooting, testing, and contributor docs listed in this plan.

---

## Acceptance Criteria for the Documentation Program

The program will be considered successful when:

1. Public docs cover package scope without forcing readers into internal phase files.
2. A new Laravel integrator can move from package overview to safe installation to working configuration without ambiguity.
3. Provider responsibilities, webhook rules, callback rules, and billing ownership differences are clearly documented.
4. Licensing, feature gates, metered billing, seat billing, dunning, notifications, invoice generation, and live sandbox validation all have proper public coverage.
5. Internal planning docs remain useful as historical records but stop carrying public documentation responsibilities.
6. Repo-level documentation signals are consistent and professionally structured.

---

## Non-Goals for This Master Plan

This master plan does not:

- rewrite all documents immediately,
- replace per-phase planning,
- or embed detailed task-level execution steps.

Those belong in dedicated documentation phase plan files.

---

## Next Step

Execute the detailed phase plan for **Phase 3: Provider and Integration Surface** and expand the public docs from system-model references into provider-specific and inbound-integration reference docs.
