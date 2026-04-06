# Documentation Architecture and Public Docs Strategy Design

> **Date**: 2026-04-06
> **Scope**: Public documentation overhaul for Laravel Subscription Guard
> **Status**: Approved design basis for master documentation planning

---

## Problem Statement

The repository contains a large amount of system knowledge, but that knowledge is concentrated in internal planning artifacts instead of public-facing package documentation.

Current findings from repository analysis:

- Public docs are too thin for the actual package surface area.
- Internal plan documents are richer than user-facing documentation.
- Some documentation status signals are inconsistent across files.
- Core capabilities exist in code and tests but are not explained end-to-end for integrators, maintainers, and operators.
- The current doc set does not clearly separate entry docs, domain reference docs, provider docs, and runtime operations docs.

This creates a documentation posture where the package is more capable than it appears, but harder to trust and adopt because the knowledge architecture is weak.

---

## Design Goals

The new documentation system must:

1. Present the package as a professional, production-grade Laravel package.
2. Explain the system from first install to advanced operations without forcing readers into internal plan files.
3. Separate public product documentation from internal execution history.
4. Scale as the package grows in providers, billing behaviors, licensing rules, and operational tooling.
5. Reduce duplication by assigning one clear purpose to each document.
6. Make drift obvious when code evolves but docs do not.

---

## Documentation Model

Recommended model: **layered hybrid documentation architecture**.

This model combines:

- a short, high-signal entry layer for first contact,
- a stable reference layer for system understanding,
- a provider/runtime layer for integration and operations,
- a scenario layer for applied usage patterns,
- and a contributor layer for package maintenance.

This is the best fit for the repository because the package is neither a simple SDK nor a pure internal system. It has product documentation needs, architectural explanation needs, and operational guidance needs at the same time.

---

## Public vs Internal Documentation Boundary

The documentation system must enforce a strict boundary between two classes of documents.

### Public Documentation

Public docs explain the package as it works today:

- what it does,
- how it is installed,
- how it is configured,
- how core domains behave,
- how providers differ,
- how operators run it,
- how developers integrate it safely.

Public docs must not depend on readers knowing phase history.

### Internal Planning Documentation

Internal plan files explain development history and execution control:

- what was planned,
- what changed during implementation,
- what risks appeared,
- what was deferred,
- what phase gates were passed.

These documents are valuable, but they are not the main user documentation surface.

### Boundary Rule

Internal plans are historical and execution-oriented.

Public docs are normative and behavior-oriented.

If a fact is required to use, operate, or trust the package, that fact must live in public docs even if it also exists in internal plan files.

---

## Documentation Layers

### Layer 1: Entry and Orientation

Purpose: help a new reader understand what the package is, whether it fits their use case, and how to get to first success quickly.

Target documents:

- `README.md`
- `docs/INSTALLATION.md`
- `docs/QUICKSTART.md`
- `docs/CONFIGURATION.md`
- `docs/FAQ.md`

### Layer 2: System and Architecture

Purpose: explain the package as a system, not as a loose collection of files.

Target documents:

- `docs/ARCHITECTURE.md`
- `docs/DOMAIN-BILLING.md`
- `docs/DOMAIN-LICENSING.md`
- `docs/DOMAIN-PROVIDERS.md`
- `docs/DATA-MODEL.md`
- `docs/EVENTS-AND-JOBS.md`

### Layer 3: Provider and Integration Reference

Purpose: explain provider-specific behavior, webhook and callback surfaces, and integration contracts.

Target documents:

- `docs/providers/IYZICO.md`
- `docs/providers/PAYTR.md`
- `docs/providers/CUSTOM-PROVIDER.md`
- `docs/API.md`
- `docs/WEBHOOKS.md`
- `docs/CALLBACKS.md`

### Layer 4: Runtime and Operations

Purpose: explain how the package behaves in real environments and how operators manage it.

Target documents:

- `docs/COMMANDS.md`
- `docs/QUEUES-AND-JOBS.md`
- `docs/LIVE-SANDBOX.md`
- `docs/TROUBLESHOOTING.md`
- `docs/SECURITY.md`

### Layer 5: Applied Usage and Business Flows

Purpose: translate system capabilities into practical billing and licensing workflows.

Target documents:

- `docs/RECIPES.md`
- `docs/USE-CASES.md`
- `docs/INVOICING.md`
- `docs/DUNNING-AND-RETRIES.md`
- `docs/METERED-BILLING.md`
- `docs/SEAT-BASED-BILLING.md`

### Layer 6: Contributor and Maintenance Surface

Purpose: make future package work easier and reduce documentation drift.

Target documents:

- `docs/TESTING.md`
- `docs/CONTRIBUTING.md`
- `docs/CHANGELOG-POLICY.md`

---

## Canonical Source Rules

Each major fact type should have one primary home.

| Fact Type | Canonical Home |
|---|---|
| Package value proposition and entry path | `README.md` |
| Installation sequence and prerequisites | `docs/INSTALLATION.md` |
| Minimal successful setup | `docs/QUICKSTART.md` |
| Config key explanations and defaults | `docs/CONFIGURATION.md` |
| Domain boundaries and orchestration rules | `docs/ARCHITECTURE.md`, `docs/DOMAIN-*` |
| Table/model purpose and data relationships | `docs/DATA-MODEL.md` |
| Generic/provider events and async flow | `docs/EVENTS-AND-JOBS.md` |
| Provider-specific behavior | `docs/providers/*` |
| Route and payload surfaces | `docs/API.md`, `docs/WEBHOOKS.md`, `docs/CALLBACKS.md` |
| Commands, queues, and runtime operations | `docs/COMMANDS.md`, `docs/QUEUES-AND-JOBS.md` |
| Real sandbox execution contract | `docs/LIVE-SANDBOX.md` |
| Reusable workflow examples | `docs/RECIPES.md`, `docs/USE-CASES.md` |
| Development history and implementation chronology | `docs/plans/**` |

If the same concept appears in multiple places, one document explains it fully and the others link to it briefly.

---

## Document Writing Standard

Public documentation should follow these rules:

- English-first for public docs.
- Internal planning docs may remain Turkish if that best fits repository workflow.
- Every document must have a clear scope statement.
- Every document must answer one primary question well.
- Top sections should orient the reader before diving into detail.
- High-value behavior, constraints, and safety rules must be explicit.
- Code snippets should be minimal and truthful to the real package behavior.
- No marketing fluff, vague promises, or placeholder prose.
- Examples must match actual routes, commands, config keys, and runtime behaviors.
- Cross-links should connect adjacent docs without duplicating large sections.

---

## Secret and Environment Policy

Documentation must respect the repository secret hygiene rules:

- real credentials are never embedded in docs,
- `.env` content is never treated as public documentation,
- examples use placeholders only,
- operator-owned credential steps are described as responsibilities, not exposed as values.

Live sandbox docs must explicitly explain the process-env-first rule and optional fallback behavior without encouraging secret leakage.

---

## Current-to-Target Mapping

The existing public docs should mostly be expanded, not discarded.

| Current File | Planned Outcome |
|---|---|
| `README.md` | Rewrite as package front door with strong navigation |
| `docs/INSTALLATION.md` | Expand into full install and runtime bootstrap guide |
| `docs/CONFIGURATION.md` | Expand into real key-by-key reference |
| `docs/PROVIDERS.md` | Split into overview plus provider-specific reference docs |
| `docs/LICENSING.md` | Expand and likely split into domain-level licensing docs |
| `docs/API.md` | Keep, but narrow to route/command surface and link deeper docs |
| `docs/RECIPES.md` | Keep and enrich as applied workflows library |

New documents are required because the current set cannot absorb architecture, data model, operations, and testing guidance cleanly without turning into oversized mixed-purpose files.

---

## Known Consistency Work Required

The documentation overhaul must explicitly address repository-level consistency issues:

- status drift between AGENTS instructions and actual repo phase progression,
- mismatch between completed claims and placeholder phase result files,
- user-facing docs lagging behind code/test coverage,
- missing navigation between current docs and internal planning material.

These are not side issues. They are part of documentation quality.

---

## Success Criteria

The documentation architecture is successful when:

1. A new integrator can understand package scope and reach a safe first setup path from `README.md`.
2. A maintainer can understand system boundaries without reading implementation history first.
3. A provider integrator can find provider behavior, webhook rules, callback rules, and live test constraints without searching plan files.
4. An operator can discover commands, queues, live sandbox requirements, and troubleshooting paths from public docs alone.
5. Internal planning files remain useful without carrying the burden of being the main package documentation.

---

## Next Step

Create a no-code master documentation roadmap that:

- defines the target document tree,
- phases the work in a controlled order,
- assigns each phase a documentation purpose,
- and defers detailed per-phase planning to dedicated phase plan files.
