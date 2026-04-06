# Documentation Standards

> **Scope**: Repository-wide documentation governance for Laravel Subscription Guard
> **Applies To**: Public documentation under `README.md` and `docs/**`, plus internal planning documentation under `docs/plans/**`
> **Status**: Active

---

## 1. Purpose

These standards define how documentation is written, organized, updated, and reviewed in this repository.

The goal is not just "good writing." The goal is a documentation system that:

- reflects the real package behavior,
- scales with future package growth,
- separates public product documentation from internal execution history,
- and prevents silent drift between code, tests, and docs.

---

## 2. Core Principles

### Professional by Default

All documentation should read like production-grade package documentation:

- precise,
- structured,
- evidence-aware,
- and free from filler text.

### Public and Internal Docs Serve Different Purposes

The repository has two documentation classes:

- **Public documentation** explains the package as it works now.
- **Internal planning documentation** explains how work was planned, executed, and reviewed.

These are related, but they are not interchangeable.

### One Topic, One Primary Home

Each major fact type must have one canonical document.

Other documents may summarize and link, but should not become full competing sources of truth.

### Evidence Before Claims

Commands, routes, config keys, runtime behavior, supported flows, and operational constraints must match the current repository state.

If evidence is unclear, document uncertainty explicitly instead of overstating support.

### No Silent Drift

When behavior changes materially, the relevant public docs must be reviewed. When planning status changes materially, the relevant internal docs must be reviewed.

---

## 3. Documentation Classes

### 3.1 Public Documentation

Public docs live in:

- `README.md`
- `docs/*.md`
- future public subtrees such as `docs/providers/*.md`

Public docs are for:

- integrators,
- package evaluators,
- operators,
- maintainers,
- and contributors who need current package behavior.

Public docs must explain:

- what the package does,
- how to install it,
- how to configure it,
- how the system behaves,
- how providers differ,
- how runtime operations work,
- and what constraints or safety rules matter.

### 3.2 Internal Planning Documentation

Internal planning docs live in:

- `docs/plans/**`

Internal planning docs are for:

- execution sequencing,
- design decisions,
- implementation history,
- work-results logs,
- risk tracking,
- and phase governance.

Internal planning docs may mention temporary decisions, incomplete work, or historical inconsistencies. Public docs should not inherit this execution noise unless it affects current package behavior.

---

## 4. Canonical Source Policy

Use the following source-of-truth map.

| Topic | Canonical Location |
|---|---|
| Package overview and navigation | `README.md` |
| Installation and bootstrap | `docs/INSTALLATION.md` |
| First working setup | `docs/QUICKSTART.md` |
| Configuration reference | `docs/CONFIGURATION.md` |
| System boundaries and architecture | `docs/ARCHITECTURE.md` |
| Billing domain behavior | `docs/DOMAIN-BILLING.md` |
| Licensing domain behavior | `docs/DOMAIN-LICENSING.md` |
| Provider overview and contracts | `docs/DOMAIN-PROVIDERS.md` |
| Provider-specific implementation behavior | `docs/providers/*.md` |
| Data model and persistence roles | `docs/DATA-MODEL.md` |
| Events, jobs, queues, and async flow | `docs/EVENTS-AND-JOBS.md`, `docs/QUEUES-AND-JOBS.md` |
| Routes, callbacks, and webhook surfaces | `docs/API.md`, `docs/WEBHOOKS.md`, `docs/CALLBACKS.md` |
| Operational commands | `docs/COMMANDS.md` |
| Live sandbox and environment-sensitive validation | `docs/LIVE-SANDBOX.md` |
| Troubleshooting and known operational pitfalls | `docs/TROUBLESHOOTING.md` |
| Security posture and safety rules | `docs/SECURITY.md` |
| Applied usage scenarios | `docs/RECIPES.md`, `docs/USE-CASES.md` |
| Historical implementation sequence | `docs/plans/**` |

Rule:

- If a concept appears in two places, one file owns the explanation and the other file links to it.

---

## 5. File Placement and Responsibility

### 5.1 `README.md`

`README.md` is the front door.

It should contain:

- package value proposition,
- supported capabilities at a high level,
- installation pointer,
- quick navigation,
- minimal examples,
- and a safe path to first success.

It should not become the full architecture manual.

### 5.2 Root `docs/*.md`

Root docs are stable reference entry points.

They should cover:

- installation,
- configuration,
- architecture,
- domain reference,
- runtime operations,
- testing,
- security,
- and troubleshooting.

Each root doc should answer one major question well.

### 5.3 `docs/providers/*.md`

Provider-specific docs explain:

- provider capabilities,
- provider constraints,
- billing ownership model,
- callback and webhook behavior,
- special operational requirements,
- and provider-specific pitfalls.

Provider-specific docs must not duplicate all generic billing or licensing explanation.

### 5.4 `docs/plans/**`

Planning docs are internal execution artifacts.

They may contain:

- roadmap material,
- historical notes,
- work packages,
- execution results,
- and risk registers.

They must not be treated as the main public reference layer.

---

## 6. Language Policy

### Public Docs

Public package docs should be English-first.

Reason:

- the package itself targets a broader technical audience,
- English reduces fragmentation in public-facing technical material,
- and mixed-language public docs create navigation and maintenance friction.

### Internal Planning Docs

Internal planning docs may remain Turkish where that improves repository workflow and review quality.

### Mixed Language Rule

Do not mix languages arbitrarily within the same document.

If a document is public-facing, keep it consistently English.

---

## 7. Writing Standards

### 7.1 Tone

Write in a direct, technical, professional style.

Avoid:

- vague promises,
- filler prose,
- marketing language,
- and overconfident unsupported claims.

### 7.2 Structure

Each document should begin with:

- a clear title,
- scope or purpose,
- and enough orientation for a reader to know whether they are in the right place.

Use sections that reflect real reader questions, not arbitrary symmetry.

### 7.3 Depth

Prefer layered explanation:

- overview first,
- details after,
- edge cases and cautions where they matter.

Avoid turning every doc into a wall of text.

### 7.4 Truthfulness

Document current behavior, not intended behavior, unless the document is explicitly a plan or roadmap.

If something is partial, environment-dependent, backlog, or operator-assisted, say so plainly.

---

## 8. Examples and Snippet Policy

Examples must be:

- minimal,
- accurate,
- and consistent with the current repository state.

### Required Rules

- Use real command names from the package.
- Use real route patterns from the package.
- Use real config keys from the package.
- Use placeholders for secrets and IDs.
- Avoid showing obsolete or hypothetical APIs as if they exist.

### Snippet Scope Rule

Do not place large illustrative code blocks into roadmap-only master plan files that are explicitly no-code.

---

## 9. Cross-Linking Rules

Cross-links should reduce duplication, not compensate for poor structure.

### Required

- Link outward when a topic belongs elsewhere.
- Link laterally between adjacent docs when the next question is predictable.
- Link from entry docs to deeper reference docs.

### Avoid

- repeating full explanations across files,
- dead-end docs with no onward navigation,
- and "see elsewhere" references without a specific target.

---

## 10. Secret and Environment Policy

The repository secret hygiene rules apply to documentation.

### Never Document

- real API keys,
- real secret keys,
- `.env` contents,
- credential dumps,
- or copied environment files.

### Always Document

- which variables are required,
- who owns them,
- when they are needed,
- and whether exported process environment or fallback behavior is used.

Live sandbox docs must clearly explain environment ownership without exposing any real secret material.

---

## 11. Terminology Rules

Use stable terminology consistently.

Preferred examples:

- "public documentation" vs "internal planning documentation"
- "provider adapter" vs generic "gateway code"
- "billing orchestration" for service-layer mutation logic
- "webhook intake" for request capture and persistence
- "callback" for provider return endpoints such as 3DS or checkout callbacks
- "live sandbox" for real remote validation in test environments

Do not alternate between multiple names for the same concept unless the distinction is intentional and explained.

---

## 12. Update Triggers

Documentation review is required when:

- a new command is added,
- a route or callback surface changes,
- a config key is added or removed,
- provider behavior changes materially,
- a new domain capability is introduced,
- a public workflow changes,
- or a historical/status surface is updated in planning docs.

If the change affects package users, at least one public doc must be reviewed.

If the change affects planning status, the relevant internal docs must be reviewed.

---

## 13. Review Checklist

Before considering a documentation change complete, verify:

- the file scope is clear,
- the file belongs in the chosen location,
- claims match code or verified repository evidence,
- duplicated explanations are minimized,
- command/route/config examples are accurate,
- secret hygiene is preserved,
- and cross-links lead readers to the next relevant document.

---

## 14. Enforcement Guidance

When there is tension between elegance and accuracy, choose accuracy.

When there is tension between completeness and duplication, choose a clear canonical source plus links.

When there is tension between historical cleanliness and evidence, choose evidence and document the inconsistency explicitly.
