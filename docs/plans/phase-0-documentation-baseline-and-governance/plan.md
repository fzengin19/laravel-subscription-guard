# Documentation Phase 0: Baseline and Governance Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the documentation baseline, governance rules, and source-of-truth map required before rewriting the public documentation set.

**Architecture:** Phase 0 is a governance and normalization phase, not a bulk content-rewrite phase. It creates the documentation operating model: current-state inventory, public-vs-internal rules, canonical source mapping, status normalization, and a hard handoff checklist for Phase 1.

**Tech Stack:** Markdown, repository planning rules under `docs/plans`, `rg`, `find`, `sed`, `wc`, `git`

---

## Scope Guardrails

- Do not rewrite the full public documentation set in this phase.
- Do not invent historical phase results to make repo status look cleaner.
- If evidence is missing, mark the state as needing reconciliation rather than fabricating completion.
- Keep public-facing behavioral claims aligned with the code and tests that currently exist.
- Respect secret hygiene rules: never expose real env values, credentials, or `.env` contents.

## File Map

### Files to Create

- `docs/DOCUMENTATION-STANDARDS.md`
  Purpose: repository-wide documentation rules, scope boundaries, writing standards, and canonical source policy.
- `docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md`
  Purpose: evidence-backed inventory of current public docs, internal docs, code/test surface area, and missing coverage.
- `docs/plans/phase-0-documentation-baseline-and-governance/public-docs-gap-analysis.md`
  Purpose: map current public docs to documented gaps and rewrite/split/retain decisions.
- `docs/plans/phase-0-documentation-baseline-and-governance/target-document-map.md`
  Purpose: define the exact target documentation tree and assign one clear responsibility to each future doc.
- `docs/plans/phase-0-documentation-baseline-and-governance/status-normalization-report.md`
  Purpose: capture repo-level documentation/status inconsistencies and the conservative normalization strategy.
- `docs/plans/phase-0-documentation-baseline-and-governance/phase-1-readiness-checklist.md`
  Purpose: define the gate criteria for starting the entry-doc rewrite phase.

### Files to Modify

- `docs/plans/documentation-master-plan.md`
  Purpose: link the Phase 0 detailed plan, keep the roadmap aligned, and record status/handoff updates.
- `AGENTS.md`
  Purpose: optional, only if execution evidence supports a conservative status correction without inventing undocumented history.
- `docs/plans/master-plan.md`
  Purpose: optional, only if documentation status normalization requires a narrow cross-reference or status clarification.
- `docs/plans/phase-0-documentation-baseline-and-governance/work-results.md`
  Purpose: record actual outputs at phase completion.
- `docs/plans/phase-0-documentation-baseline-and-governance/risk-notes.md`
  Purpose: record actual risks and technical debt at phase completion.

### Files to Review Before and During Execution

- `README.md`
- `docs/INSTALLATION.md`
- `docs/CONFIGURATION.md`
- `docs/PROVIDERS.md`
- `docs/LICENSING.md`
- `docs/API.md`
- `docs/RECIPES.md`
- `docs/plans/2026-04-06-documentation-architecture-design.md`
- `docs/plans/documentation-master-plan.md`
- `docs/plans/master-plan.md`
- `docs/plans/phase-1-core-infrastructure/work-results.md`
- `docs/plans/phase-1-core-infrastructure/risk-notes.md`
- `docs/plans/phase-2-iyzico-provider/work-results.md`
- `docs/plans/phase-2-iyzico-provider/risk-notes.md`
- `docs/plans/phase-3-paytr-provider/work-results.md`
- `docs/plans/phase-3-paytr-provider/risk-notes.md`
- `docs/plans/phase-4-licensing-system/work-results.md`
- `docs/plans/phase-4-licensing-system/risk-notes.md`
- `docs/plans/phase-4-1-implementation-closure/work-results.md`
- `docs/plans/phase-4-1-implementation-closure/risk-notes.md`
- `docs/plans/phase-5-integration-testing/work-results.md`
- `docs/plans/phase-5-integration-testing/risk-notes.md`
- `docs/plans/phase-6-security-hardening/work-results.md`
- `docs/plans/phase-6-security-hardening/risk-notes.md`
- `docs/plans/phase-7-code-simplification/work-results.md`
- `docs/plans/phase-7-code-simplification/risk-notes.md`
- `docs/plans/phase-8-iyzico-live-sandbox-validation/work-results.md`
- `docs/plans/phase-8-iyzico-live-sandbox-validation/risk-notes.md`
- `docs/plans/phase-10/00-MASTER-PLAN.md`
- `docs/plans/phase-11-debug-fixes/plan.md`

## Chunk 1: Evidence and Baseline

### Task 1: Build the Current-State Inventory

**Files:**
- Create: `docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md`
- Review: `README.md`
- Review: `docs/INSTALLATION.md`
- Review: `docs/CONFIGURATION.md`
- Review: `docs/PROVIDERS.md`
- Review: `docs/LICENSING.md`
- Review: `docs/API.md`
- Review: `docs/RECIPES.md`
- Review: `docs/plans/documentation-master-plan.md`
- Review: `docs/plans/2026-04-06-documentation-architecture-design.md`

- [ ] **Step 1: Capture the current public-doc inventory**

Run:
```bash
find docs -maxdepth 2 -type f | sort
```

Expected:
- Root docs and provider/planning docs are listed clearly enough to classify into public vs internal buckets.

- [ ] **Step 2: Capture the current public-doc size baseline**

Run:
```bash
wc -l README.md docs/*.md
```

Expected:
- Line counts make the thin public-doc surface obvious and provide an evidence baseline for the inventory.

- [ ] **Step 3: Capture the current planning surface**

Run:
```bash
find docs/plans -maxdepth 1 -mindepth 1 -type d | sort
```

Expected:
- Documentation notes include the real phase directory set, including later phases already present in the repo.

- [ ] **Step 4: Write `current-state-inventory.md`**

Include at minimum:
- current public docs,
- current internal docs,
- code surface summary,
- test surface summary,
- missing public coverage areas,
- status-signal inconsistencies,
- open questions that must be answered before Phase 1.

- [ ] **Step 5: Verify every file referenced in the inventory exists**

Run:
```bash
rg -n '`(README\.md|docs/[^`]+|docs/plans/[^`]+)`' docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md
```

Expected:
- Only real files are referenced; no placeholder or imaginary paths appear.

- [ ] **Step 6: Commit the inventory**

Run:
```bash
git add docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md
git commit -m "docs: add documentation phase 0 current-state inventory"
```

## Chunk 2: Governance and Document Architecture

### Task 2: Write the Documentation Standards

**Files:**
- Create: `docs/DOCUMENTATION-STANDARDS.md`
- Review: `docs/plans/2026-04-06-documentation-architecture-design.md`
- Review: `docs/plans/documentation-master-plan.md`

- [ ] **Step 1: Draft the standards document with explicit section boundaries**

Write sections for:
- public vs internal docs,
- canonical source rule,
- naming and file placement,
- English-first public docs policy,
- cross-linking policy,
- snippet/example policy,
- secret/env policy,
- terminology consistency policy,
- update policy when behavior changes.

- [ ] **Step 2: Add a document responsibility matrix**

The standards doc must explain:
- what belongs in `README.md`,
- what belongs in root `docs/*.md`,
- what belongs in provider-specific docs,
- what belongs only in `docs/plans/**`.

- [ ] **Step 3: Verify the standards include the non-negotiable governance rules**

Run:
```bash
rg -n 'public vs internal|canonical|English-first|secret|env|cross-link|single source|source of truth|drift' docs/DOCUMENTATION-STANDARDS.md
```

Expected:
- The standards document explicitly covers governance instead of only style advice.

- [ ] **Step 4: Verify consistency against the design and master plan**

Run:
```bash
rg -n 'Layer|Phase 0|canonical|public' docs/plans/2026-04-06-documentation-architecture-design.md docs/plans/documentation-master-plan.md docs/DOCUMENTATION-STANDARDS.md
```

Expected:
- No major policy contradiction appears between the design doc, the master plan, and the standards doc.

- [ ] **Step 5: Commit the standards document**

Run:
```bash
git add docs/DOCUMENTATION-STANDARDS.md
git commit -m "docs: add documentation standards"
```

### Task 3: Write the Gap Analysis and Target Document Map

**Files:**
- Create: `docs/plans/phase-0-documentation-baseline-and-governance/public-docs-gap-analysis.md`
- Create: `docs/plans/phase-0-documentation-baseline-and-governance/target-document-map.md`
- Modify: `docs/plans/documentation-master-plan.md`
- Review: `README.md`
- Review: `docs/INSTALLATION.md`
- Review: `docs/CONFIGURATION.md`
- Review: `docs/PROVIDERS.md`
- Review: `docs/LICENSING.md`
- Review: `docs/API.md`
- Review: `docs/RECIPES.md`

- [ ] **Step 1: Write `public-docs-gap-analysis.md`**

For each current public doc, record:
- keep/expand/split/replace decision,
- current strengths,
- current deficiencies,
- specific missing topics,
- target destination in the future doc tree.

- [ ] **Step 2: Write `target-document-map.md`**

The document map must include:
- full target doc tree,
- one-line responsibility for each target file,
- canonical owner of each major fact type,
- current-file to future-file migration mapping,
- sequencing notes for future phases.

- [ ] **Step 3: Link the detailed Phase 0 plan from the documentation master plan**

Update `docs/plans/documentation-master-plan.md` so the Phase 0 section references:
- `phase-0-documentation-baseline-and-governance/plan.md`

- [ ] **Step 4: Verify that every current public doc has an explicit target outcome**

Run:
```bash
rg -n 'README\.md|INSTALLATION\.md|CONFIGURATION\.md|PROVIDERS\.md|LICENSING\.md|API\.md|RECIPES\.md' docs/plans/phase-0-documentation-baseline-and-governance/public-docs-gap-analysis.md docs/plans/phase-0-documentation-baseline-and-governance/target-document-map.md
```

Expected:
- Every current public doc appears in the migration mapping, and none are left ambiguous.

- [ ] **Step 5: Commit the document map and master-plan alignment**

Run:
```bash
git add docs/plans/phase-0-documentation-baseline-and-governance/public-docs-gap-analysis.md docs/plans/phase-0-documentation-baseline-and-governance/target-document-map.md docs/plans/documentation-master-plan.md
git commit -m "docs: map documentation phase 0 target tree"
```

## Chunk 3: Status Normalization and Handoff

### Task 4: Normalize Documentation Status Signals Conservatively

**Files:**
- Create: `docs/plans/phase-0-documentation-baseline-and-governance/status-normalization-report.md`
- Modify: `docs/plans/documentation-master-plan.md`
- Modify: `AGENTS.md`
- Modify: `docs/plans/master-plan.md`
- Review: `docs/plans/phase-7-code-simplification/work-results.md`
- Review: `docs/plans/phase-7-code-simplification/risk-notes.md`
- Review: `docs/plans/phase-11-debug-fixes/plan.md`

- [ ] **Step 1: Write `status-normalization-report.md` from evidence**

The report must separate:
- proven states backed by docs or git history,
- conflicting states,
- unknown states,
- conservative correction recommendations.

- [ ] **Step 2: Apply only evidence-backed status corrections**

Rule:
- If a file claims completion but supporting records are placeholders, do not invent missing history.
- Either mark the state as needing reconciliation or leave the historical file untouched and document the inconsistency explicitly.

- [ ] **Step 3: Update only the repo-level surfaces that can be corrected safely**

Candidate surfaces:
- `docs/plans/documentation-master-plan.md`
- `AGENTS.md`
- `docs/plans/master-plan.md`

Only apply changes when the update reduces ambiguity without fabricating unsupported claims.

- [ ] **Step 4: Verify that every changed status claim has evidence**

Run:
```bash
git diff -- AGENTS.md docs/plans/master-plan.md docs/plans/documentation-master-plan.md
```

Expected:
- Every changed claim can be traced back to an existing source file, test evidence, or git history note documented in `status-normalization-report.md`.

- [ ] **Step 5: Commit the normalization work**

Run:
```bash
git add docs/plans/phase-0-documentation-baseline-and-governance/status-normalization-report.md docs/plans/documentation-master-plan.md AGENTS.md docs/plans/master-plan.md
git commit -m "docs: normalize documentation status signals"
```

Note:
- If `AGENTS.md` or `docs/plans/master-plan.md` are not changed after evidence review, omit them from `git add` and explain why in the report.

### Task 5: Close Phase 0 and Prepare Phase 1

**Files:**
- Create: `docs/plans/phase-0-documentation-baseline-and-governance/phase-1-readiness-checklist.md`
- Modify: `docs/plans/phase-0-documentation-baseline-and-governance/work-results.md`
- Modify: `docs/plans/phase-0-documentation-baseline-and-governance/risk-notes.md`
- Review: `docs/DOCUMENTATION-STANDARDS.md`
- Review: `docs/plans/phase-0-documentation-baseline-and-governance/current-state-inventory.md`
- Review: `docs/plans/phase-0-documentation-baseline-and-governance/public-docs-gap-analysis.md`
- Review: `docs/plans/phase-0-documentation-baseline-and-governance/target-document-map.md`
- Review: `docs/plans/phase-0-documentation-baseline-and-governance/status-normalization-report.md`

- [ ] **Step 1: Write `phase-1-readiness-checklist.md`**

Checklist must confirm:
- Phase 1 document scope is locked,
- entry-doc owners are clear,
- current public-doc gaps are explicitly mapped,
- rewrite order is fixed,
- unresolved normalization risks are known.

- [ ] **Step 2: Write `work-results.md` with actual outputs only**

Include:
- created files,
- modified files,
- confirmed decisions,
- verification commands run,
- remaining open items handed to Phase 1.

- [ ] **Step 3: Write `risk-notes.md` with real risks and debt**

Include:
- governance risks,
- historical inconsistency risks,
- terminology drift risks,
- link/navigation debt,
- any unresolved repo-status ambiguity.

- [ ] **Step 4: Verify the phase folder is structurally complete**

Run:
```bash
find docs/plans/phase-0-documentation-baseline-and-governance -maxdepth 1 -type f | sort
```

Expected:
- The phase folder contains `plan.md`, `work-results.md`, `risk-notes.md`, and all execution artifacts created in this phase.

- [ ] **Step 5: Verify the master plan and handoff are aligned**

Run:
```bash
rg -n 'Phase 0|Phase 1|phase-0-documentation-baseline-and-governance|phase-1' docs/plans/documentation-master-plan.md docs/plans/phase-0-documentation-baseline-and-governance/phase-1-readiness-checklist.md docs/plans/phase-0-documentation-baseline-and-governance/work-results.md
```

Expected:
- Phase 0 outputs clearly hand off into Phase 1 without ambiguity.

- [ ] **Step 6: Commit the phase closure**

Run:
```bash
git add docs/plans/phase-0-documentation-baseline-and-governance
git commit -m "docs: close documentation phase 0 baseline and governance"
```

---

## Execution Notes

- This plan intentionally avoids full public-doc rewrites. That work begins in Phase 1.
- If evidence conflicts with historical documentation, prefer explicit reconciliation notes over silent edits.
- If unexpected repo-wide doc drift appears during execution, capture it in the phase artifacts instead of expanding scope casually.

## Completion Gate

Phase 0 is complete only when:

- governance rules are written,
- the current-state inventory is evidence-backed,
- the target document map is explicit,
- repo-level status normalization has been handled conservatively,
- and Phase 1 has a clean readiness checklist.
