# Documentation Phase 4: Billing and Licensing Operational Flows

> **Status**: In Progress
> **Created**: 2026-04-07
> **Depends On**: Phase 3 (completed)

---

## Purpose

Phase 4 creates the applied-workflow documentation layer (Layer 5) for billing and licensing operational flows. These docs explain how the package handles real business scenarios: failed payment recovery, usage-based billing, seat management, and invoice generation.

Phase 2 established the system model (DOMAIN-BILLING, DOMAIN-LICENSING). Phase 4 now decomposes those concepts into dedicated operational guides.

---

## Deliverables

| Document | Canonical Topic | Source Evidence |
|---|---|---|
| `docs/DUNNING-AND-RETRIES.md` | Payment failure recovery, retry scheduling, grace periods, suspension | `ProcessDunningRetryJob`, `PaymentChargeJob`, `SuspendOverdueCommand`, `SubscriptionService::processDunning()` |
| `docs/METERED-BILLING.md` | Usage tracking, period billing, provider charge, idempotency | `MeteredBillingProcessor`, `ProcessMeteredBillingCommand`, `LicenseUsage` model |
| `docs/SEAT-BASED-BILLING.md` | Seat quantity, proration, license sync | `SeatManager`, `SubscriptionItem` model |
| `docs/INVOICING.md` | Invoice generation, PDF rendering, notifications | `Invoice` model, `InvoicePdfRenderer`, `DispatchBillingNotificationsJob`, `InvoicePaidNotification` |

---

## Work Packages

### WP-A: DUNNING-AND-RETRIES.md

Content plan:
- Dunning overview: what dunning means in this package
- Config keys: `grace_period_days`, `max_dunning_retries`, `dunning_retry_interval_days`
- Commands: `subguard:process-dunning`, `subguard:suspend-overdue`
- Failure flow: PaymentChargeJob failure → past_due → grace_ends_at → retry scheduling
- Retry flow: ProcessDunningRetryJob → lock → retry check → PaymentChargeJob re-dispatch
- Exhaustion: max retries → subscription suspended → license suspended → DunningExhausted event → notification
- Grace period suspension: SuspendOverdueCommand flow
- Provider ownership: dunning only applies to self-managed (package-managed) billing
- Events: DunningExhausted, PaymentFailed
- Notifications: payment.failed, dunning.exhausted, subscription.suspended

### WP-B: METERED-BILLING.md

Content plan:
- Concept: usage-based billing on top of subscriptions
- Prerequisites: active subscription with license_id, metered_price_per_unit in metadata
- Command: `subguard:process-metered-billing`
- Processing flow: MeteredBillingProcessor.process()
  - Period resolution from subscription fields or fallback
  - LicenseUsage aggregation with billed_at null filter
  - Idempotent transaction creation
  - Provider charge for self-managed, local-only for provider-managed
  - Usage rows marked billed_at
  - Period advancement
- Config: billing.timezone
- Recording usage: LicenseUsage rows with period_start
- Double-billing protection: billed_at + transaction idempotency
- Limitations: no tiered pricing, no usage alerts, no partial period handling

### WP-C: SEAT-BASED-BILLING.md

Content plan:
- Concept: quantity-based subscription items
- SeatManager API: addSeat(), removeSeat(), calculateProration()
- SubscriptionItem model role
- Proration calculation: time-remaining ratio
- License sync: limit_overrides.seats
- Metadata: seat_quantity, last_seat_proration
- Limitations: no provider-side seat sync, no seat-based pricing tiers, limited API surface
- Honest coverage note per Decision Board blocker

### WP-D: INVOICING.md

Content plan:
- Invoice generation trigger: payment.completed event in DispatchBillingNotificationsJob
- Invoice model fields: invoice_number, subscribable, status, amounts, dates, pdf_path
- Invoice number format: INV-{date}-{random}-{transaction_id}
- PDF rendering: InvoicePdfRenderer with Spatie PDF fallback
- Storage: local disk at subguard/invoices/
- Notification: InvoicePaidNotification via mail + database channels
- Limitations: no customizable templates, minimal PDF content, no tax calculation engine

### WP-E: Bridge Updates

- Update `docs/DOMAIN-BILLING.md` related-docs section to point to new Phase 4 docs
- Update `docs/DOMAIN-LICENSING.md` related-docs section
- Update `README.md` documentation index if needed

---

## Constraints

- Document current behavior only, not planned Phase 10 fixes
- Seat-based billing is limited — state this honestly
- All config keys, commands, and class names must be verified against source
- No forward links to Phase 5+ docs that do not exist yet

---

## Execution Order

1. WP-A (dunning is the most complete flow)
2. WP-B (metered billing is fully implemented)
3. WP-C (seat billing is limited, needs honest framing)
4. WP-D (invoicing is straightforward)
5. WP-E (bridge updates after all docs exist)

---

## Acceptance Criteria

- All four docs created under `docs/`
- Each doc covers: concept, commands, flow, config, events, limitations
- Cross-links to DOMAIN-BILLING and relevant references
- No unsupported claims
- Bridge docs updated
- docs-os validation passes
