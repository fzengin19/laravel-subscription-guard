# Changelog

## 2026-03-05

### Phase 4.1 Closure

- PayTR live-path placeholder responses replaced by deterministic live DTO flows
- Revocation and heartbeat sync operations added
- Dunning terminal failure handling hardened
- Metered charge path hardened with provider-charge integration and idempotency tests

### Phase 5 Integration and Testing (ongoing)

- Added `subguard:simulate-webhook` command
- Added notification pipeline (`InvoicePaidNotification`, `SubscriptionCancelledNotification`)
- Added invoice PDF renderer with safe fallback behavior
- Added E2E and performance audit feature tests
- Expanded coupon/discount behavior and transaction propagation coverage
- Added documentation set: INSTALLATION, CONFIGURATION, LICENSING, API, PROVIDERS, RECIPES
