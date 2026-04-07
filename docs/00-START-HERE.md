# Docs Operating System — Work Protocol

> Read once per session. Rarely changes. This file defines the execution protocol.

## Core Rule

docs/ is an execution operating system. Every task follows three rigid phases:

1. **BIND** — Read state, load reading list, establish execution contract
2. **EXECUTE** — Work within contracted scope only
3. **SYNC** — Update state, validate, report

## File Roles

| File | Role | Read | Update |
|------|------|------|--------|
| `00-START-HERE.md` | Protocol | Once per session | When protocol changes |
| `01-CURRENT-STATE.md` | Live state | Every task start | After every task |
| `02-DECISION-BOARD.md` | Decisions | Every task start | When decisions change |
| `standards/*.md` | Governance | When in reading list | When standards evolve |
| `templates/*.md` | Blueprints | When creating docs | When templates improve |
| `plans/**` | History | When in reading list | During execution |

## Reading Discipline

1. Read `01-CURRENT-STATE.md` first — always
2. Read ONLY files listed in its "Reading List" section
3. Do NOT read entire directories unless explicitly listed
4. Task changes domain? Update reading list FIRST

## Update Discipline

After every completed task:

1. `01-CURRENT-STATE.md` — mandatory, always first
2. Domain/phase docs — if scope or risks changed
3. `02-DECISION-BOARD.md` — if new decisions made
4. Run validation: `bash ~/.claude/skills/docs-operating-system/scripts/validate-docs.sh`

## Scope Discipline

- Stay within contracted scope
- Scope expanding? STOP → update docs → checkpoint with user → continue
- Prefer follow-up tasks over silent scope creep

## Documentation Tracks

This project has two documentation tracks:

- **Public docs** (`docs/*.md`, `docs/providers/*.md`) — Package documentation for integrators. English-first. Evidence-based.
- **Internal plans** (`docs/plans/**`) — Execution history, phase plans, work results. Turkish OK.

See `docs/DOCUMENTATION-STANDARDS.md` for full writing standards.
