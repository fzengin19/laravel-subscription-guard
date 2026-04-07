# Decision Board

> Accepted architectural and process decisions. Check before making conflicting choices.

## Documentation Decisions

| ID | Decision | Status | Detail |
|----|----------|--------|--------|
| D1 | 6-layer documentation architecture | Accepted | Entry → System → Provider → Runtime → Applied → Contributor. See `plans/documentation-master-plan.md` |
| D2 | English-first public docs | Accepted | Public docs English. Internal plans may use Turkish. No mixed language in single doc. |
| D3 | Canonical source policy | Accepted | Each topic has one primary home. Others summarize + link. See `DOCUMENTATION-STANDARDS.md` |
| D4 | Phase dependency order | Accepted | Governance → Entry → System model → Provider → Business flows → Runtime → Polish |
| D5 | Evidence-before-claims | Accepted | All commands, routes, config keys, behavior verified against source code |
| D6 | No forward links | Accepted | Only link to docs that exist at current phase completion |
| D7 | Public/internal boundary | Accepted | Public docs = package as-is. Internal plans = execution history. |

## Architecture Decisions

| ID | Decision | Status | Detail |
|----|----------|--------|--------|
| A1 | Provider ownership split | Accepted | Iyzico = provider-managed billing. PayTR = package-managed. See `DOMAIN-PROVIDERS.md` |
| A2 | Webhook intake-then-finalize | Accepted | Store first, process async via FinalizeWebhookEventJob. See `WEBHOOKS.md` |
| A3 | License signing with Ed25519 | Accepted | Offline validation, heartbeat freshness. See `DOMAIN-LICENSING.md` |
| A4 | Subscription state machine | Accepted | Status transitions via SubscriptionService methods, not raw writes |
| A5 | Idempotency via firstOrCreate | Accepted | Transaction dedup with idempotency_key. Webhook dedup with provider+event_id |
| A6 | Cache locks for concurrency | Accepted | Renewal and dunning jobs use cache locks to prevent parallel execution |
| A7 | Webhook signature validation at intake | Accepted | Validate before DB persistence (security audit fix 2026-04-07) |

## Security Decisions

| ID | Decision | Status | Detail |
|----|----------|--------|--------|
| S1 | Mock mode production guard | Accepted | Critical log warning if mock=true in production |
| S2 | Card data sanitization | Accepted | SanitizesProviderData trait strips card_number, cvc, cvv from all provider responses |
| S3 | Payload size limit | Accepted | 64KB default for webhook payloads |
| S4 | SSRF protection on sync endpoints | Accepted | URL validation, HTTPS required in prod, private IP blocking |
