# Documentation Phase 0: Baseline and Governance - Risk Notes

> **Status**: Completed
> **Last Updated**: 2026-04-06

---

## 1) Risks Observed During the Phase

| Risk | Impact | Mitigation | Status |
|---|---|---|---|
| Historical phase-status drift across AGENTS and plan files | High | Documented explicitly in `status-normalization-report.md`; only safe corrections were applied | Open, bounded |
| Placeholder close-out files could tempt fabricated cleanup | High | Used conservative evidence rule and refused to invent historical closure | Mitigated |
| Public-doc rewrite scope could expand too early into all domains at once | High | Locked Phase 1 to entry-path docs only | Mitigated |
| New root public docs were silently ignored by `.gitignore` | High | Updated `.gitignore` to allow root `docs/*.md`, one-level public subdirs, and recursive plan docs to be versioned | Mitigated |
| New documentation tree could duplicate existing content without canonical ownership | Medium | Canonical source rules and target document map were written before rewrite work | Mitigated |
| Empty or partially reconciled phase directories could confuse later planning work | Medium | Captured as visible debt instead of silently normalizing | Open |

## 2) Technical Debt Notes

- `AGENTS.md` and `docs/plans/master-plan.md` still require a later historical reconciliation pass for later/auxiliary phases.
- `docs/plans/phase-7-code-simplification/` contains placeholder closure files that remain unresolved.
- `docs/plans/phase-9-premium-email-invoice-notification-system/` exists without the required phase artifacts.
- The repository previously ignored new root public docs under `/docs/*`; this was corrected in Phase 0, but future ignore-rule changes should be reviewed carefully.

## 3) Operational Notes

- Phase 0 changed documentation structure and governance only; it did not change runtime package behavior.
- Because no code behavior changed, structural and evidence verification were sufficient for this phase.

## 4) Recommendations for Later Phases

- Phase 1 should not promise unsupported UX surfaces or backlog items as current package capabilities.
- Phase 2 should use code and tests, not historical assumptions, when writing architecture and domain docs.
- A separate historical cleanup effort should reconcile legacy or incomplete planning directories.

## 5) Closure Rule

- Phase 0 is closed because the required governance, baseline, normalization, and handoff outputs now exist and are evidence-backed.
