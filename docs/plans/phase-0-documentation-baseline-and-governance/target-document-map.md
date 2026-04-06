# Documentation Phase 0: Target Document Map

> **Purpose**: Define the target public documentation tree, file responsibilities, and migration path from the current doc set
> **Status**: Completed

---

## 1. Target Tree

### Layer 1: Entry and Orientation

| Target File | Status | Responsibility | Primary Inputs | Target Phase |
|---|---|---|---|---|
| `README.md` | Rewrite | Package front door, capability map, navigation | Current `README.md`, current public docs, code/test surface | Phase 1 |
| `docs/INSTALLATION.md` | Expand | Installation, prerequisites, publish/migrate/bootstrap | Current `docs/INSTALLATION.md`, config/runtime evidence | Phase 1 |
| `docs/QUICKSTART.md` | New | Minimal first-success path | Installation/config docs, safe defaults | Phase 1 |
| `docs/CONFIGURATION.md` | Expand | Canonical configuration reference | Current `docs/CONFIGURATION.md`, `config/subscription-guard.php` | Phase 1 |
| `docs/FAQ.md` | New | Short answers to recurring package questions | All public docs after first rewrite waves | Phase 6 |

### Layer 2: System and Architecture

| Target File | Status | Responsibility | Primary Inputs | Target Phase |
|---|---|---|---|---|
| `docs/ARCHITECTURE.md` | New | System boundaries, orchestration model, domain relationships | Architecture design docs, service/provider boundaries | Phase 2 |
| `docs/DOMAIN-BILLING.md` | New | Billing lifecycle, renewals, dunning, plan changes | Billing service, jobs, events, tests | Phase 2 |
| `docs/DOMAIN-LICENSING.md` | New | License lifecycle, validation, activation, feature/limit model | Licensing code/tests and current `docs/LICENSING.md` | Phase 2 |
| `docs/DOMAIN-PROVIDERS.md` | New | Generic provider contract and responsibilities | Current `docs/PROVIDERS.md`, architecture rules | Phase 2 |
| `docs/DATA-MODEL.md` | New | Table/model responsibilities and relationships | migrations, models, plan docs | Phase 2 |
| `docs/EVENTS-AND-JOBS.md` | New | Generic events, provider events, jobs, async flow | events, jobs, queue behavior, tests | Phase 2 |

### Layer 3: Provider and Integration Reference

| Target File | Status | Responsibility | Primary Inputs | Target Phase |
|---|---|---|---|---|
| `docs/providers/IYZICO.md` | New | iyzico capabilities, constraints, callbacks, webhook rules, live sandbox specifics | Current `docs/PROVIDERS.md`, iyzico code/tests/live docs | Phase 3 |
| `docs/providers/PAYTR.md` | New | PayTR capabilities, self-managed billing model, webhook behavior | Current `docs/PROVIDERS.md`, PayTR code/tests | Phase 3 |
| `docs/providers/CUSTOM-PROVIDER.md` | New | Contract for adding future providers | contracts, architecture rules, provider manager design | Phase 3 |
| `docs/API.md` | Re-scope | High-level route and command index | Current `docs/API.md`, command/route evidence | Phase 3 |
| `docs/WEBHOOKS.md` | New | Webhook intake, validation, idempotency, provider response formats | controllers, jobs, provider docs, tests | Phase 3 |
| `docs/CALLBACKS.md` | New | 3DS/checkout callback behavior and verification rules | routes, callback controller, provider behavior | Phase 3 |

### Layer 4: Runtime and Operations

| Target File | Status | Responsibility | Primary Inputs | Target Phase |
|---|---|---|---|---|
| `docs/COMMANDS.md` | New | Full operational command catalog | command signatures, service provider registration | Phase 5 |
| `docs/QUEUES-AND-JOBS.md` | New | Queue topology, job responsibilities, runtime guidance | jobs, config, tests | Phase 5 |
| `docs/LIVE-SANDBOX.md` | New | Live iyzico sandbox process, constraints, env ownership, skips | current install/providers/recipes docs, live tests | Phase 5 |
| `docs/TROUBLESHOOTING.md` | New | Failure modes, likely causes, where to look next | risk notes, live sandbox notes, test evidence | Phase 5 |
| `docs/SECURITY.md` | New | Security posture, idempotency, signature validation, secret hygiene | phase 6 outputs, controller and middleware behavior | Phase 5 |

### Layer 5: Applied Workflows

| Target File | Status | Responsibility | Primary Inputs | Target Phase |
|---|---|---|---|---|
| `docs/RECIPES.md` | Expand | Practical reusable workflows | Current recipes, runtime docs, provider docs | Phase 4 / 6 |
| `docs/USE-CASES.md` | New | Opinionated scenario navigation | Recipes and domain docs | Phase 6 |
| `docs/INVOICING.md` | New | Invoice generation, PDFs, notifications, external invoicing hooks | notification/invoice flows, recipes | Phase 4 |
| `docs/DUNNING-AND-RETRIES.md` | New | Retry policy, grace handling, suspension, exhaustion | billing/jobs/tests | Phase 4 |
| `docs/METERED-BILLING.md` | New | Usage aggregation, billing periods, safety and idempotency | metered billing processor/tests | Phase 4 |
| `docs/SEAT-BASED-BILLING.md` | New | Seat quantity changes, proration, license limit sync | seat manager/tests | Phase 4 |

### Layer 6: Contributor Surface

| Target File | Status | Responsibility | Primary Inputs | Target Phase |
|---|---|---|---|---|
| `docs/TESTING.md` | New | Test layers, live suite separation, command reference | test tree, composer scripts, live suite rules | Phase 5 |
| `docs/CONTRIBUTING.md` | New | Contribution workflow, documentation expectations, plan discipline | AGENTS rules, documentation standards | Phase 6 |
| `docs/CHANGELOG-POLICY.md` | New | Release-note and change-summary policy | maintainer workflow | Phase 6 |

---

## 2. Current-to-Target Migration Map

| Current File | Future Role |
|---|---|
| `README.md` | Remains root entry doc, fully rewritten |
| `docs/INSTALLATION.md` | Remains install guide, significantly expanded |
| `docs/CONFIGURATION.md` | Remains config reference, significantly expanded |
| `docs/PROVIDERS.md` | Split into generic provider overview plus provider-specific docs |
| `docs/LICENSING.md` | Expanded and likely repositioned as entry point into `docs/DOMAIN-LICENSING.md` |
| `docs/API.md` | Reduced to index/reference entry point and linked into deeper integration docs |
| `docs/RECIPES.md` | Retained and expanded as applied workflow library |

---

## 3. Canonical Fact Ownership Map

| Fact Type | Owner |
|---|---|
| "What is this package?" | `README.md` |
| "How do I get it running?" | `docs/INSTALLATION.md`, `docs/QUICKSTART.md` |
| "What does this config key do?" | `docs/CONFIGURATION.md` |
| "How does the system work?" | `docs/ARCHITECTURE.md` |
| "How does billing behave?" | `docs/DOMAIN-BILLING.md` |
| "How does licensing behave?" | `docs/DOMAIN-LICENSING.md` |
| "What is generic vs provider-specific?" | `docs/DOMAIN-PROVIDERS.md` |
| "How does iyzico work here?" | `docs/providers/IYZICO.md` |
| "How does PayTR work here?" | `docs/providers/PAYTR.md` |
| "What tables and models exist?" | `docs/DATA-MODEL.md` |
| "What events and jobs exist?" | `docs/EVENTS-AND-JOBS.md` |
| "What routes and callbacks do I expose?" | `docs/API.md`, `docs/WEBHOOKS.md`, `docs/CALLBACKS.md` |
| "What commands do I run?" | `docs/COMMANDS.md` |
| "How do I operate the queues?" | `docs/QUEUES-AND-JOBS.md` |
| "How do I run live sandbox validation?" | `docs/LIVE-SANDBOX.md` |
| "What do I do when something breaks?" | `docs/TROUBLESHOOTING.md` |

---

## 4. Navigation Paths to Support

### New Integrator

Path:

`README.md` -> `docs/INSTALLATION.md` -> `docs/QUICKSTART.md` -> `docs/CONFIGURATION.md`

### System Evaluator or Maintainer

Path:

`README.md` -> `docs/ARCHITECTURE.md` -> `docs/DOMAIN-*` -> `docs/DATA-MODEL.md`

### Provider Integrator

Path:

`README.md` -> `docs/DOMAIN-PROVIDERS.md` -> `docs/providers/IYZICO.md` or `docs/providers/PAYTR.md` -> `docs/WEBHOOKS.md` / `docs/CALLBACKS.md`

### Operator

Path:

`README.md` -> `docs/COMMANDS.md` -> `docs/QUEUES-AND-JOBS.md` -> `docs/LIVE-SANDBOX.md` -> `docs/TROUBLESHOOTING.md`

---

## 5. Phase Sequencing Logic

### Phase 1

Build the entry path:

- `README.md`
- `docs/INSTALLATION.md`
- `docs/QUICKSTART.md`
- `docs/CONFIGURATION.md`

### Phase 2

Build the system model:

- architecture,
- domain references,
- data model,
- events/jobs.

### Phase 3

Build provider and integration reference:

- provider-specific docs,
- webhooks,
- callbacks,
- API index.

### Phase 4

Build business-flow guidance:

- dunning,
- metered billing,
- seat billing,
- invoicing,
- expanded recipes.

### Phase 5

Build runtime and verification reference:

- commands,
- queues/jobs,
- testing,
- live sandbox,
- security,
- troubleshooting.

### Phase 6

Close navigation, contributor docs, and FAQ/use-case coverage.

---

## 6. Phase 1 Lock

Phase 1 should not absorb architecture, provider deep dives, or runtime operations.

Its job is to create a trustworthy front door, not to finish the entire documentation program.
