# Documentation Phase 5: Runtime Operations, Testing, and Security

> **Status**: In Progress
> **Created**: 2026-04-07
> **Depends On**: Phase 4 (completed)

---

## Purpose

Phase 5 creates the runtime operations documentation layer (Layer 4). These docs explain how the package is operated, tested, secured, and debugged in real environments.

---

## Deliverables

| Document | Canonical Topic |
|---|---|
| `docs/COMMANDS.md` | All artisan commands with signatures, options, usage, scheduling |
| `docs/QUEUES-AND-JOBS.md` | Queue topology, job inventory, concurrency, configuration |
| `docs/LIVE-SANDBOX.md` | Iyzico sandbox testing, environment setup, test isolation |
| `docs/TESTING.md` | Test suite structure, running tests, test categories |
| `docs/SECURITY.md` | Security posture, protections, audit findings, threat model |
| `docs/TROUBLESHOOTING.md` | Common issues, debugging steps, diagnostic commands |

---

## Execution Order

1. COMMANDS.md (foundation for other runtime docs)
2. QUEUES-AND-JOBS.md (job topology referenced by commands)
3. LIVE-SANDBOX.md (test environment)
4. TESTING.md (test suite docs)
5. SECURITY.md (security posture)
6. TROUBLESHOOTING.md (diagnostic reference)
7. Bridge updates (README, cross-links)

---

## Acceptance Criteria

- All six docs created under `docs/`
- Commands verified against source signatures
- Queue names verified against config
- Security claims verified against source
- No forward links to Phase 6 docs
- docs-os validation passes
