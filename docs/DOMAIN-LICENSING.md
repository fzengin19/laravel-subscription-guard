# Domain Licensing

Use this document to understand license signing, validation, activation, heartbeat freshness, revocation handling, and the bridge from billing events into license state.

## Licensing Scope

The package licensing subsystem covers:

- signed license-key generation
- signature verification
- expiration enforcement
- revocation enforcement
- heartbeat freshness checks for offline trust
- activation and deactivation against domains
- feature and limit checks
- online validation endpoint behavior
- subscription-to-license lifecycle synchronization

## Core Components

The main licensing actors are:

- `LicenseManager`
  Public licensing service for generate, validate, activate, deactivate, check, and revoke operations.
- `LicenseSignature`
  Responsible for signing and verifying canonical license payloads.
- `LicenseRevocationStore`
  Holds revocation snapshot or delta state and heartbeat cache entries.
- `LicenseLifecycleListener`
  Bridges generic billing events into persisted license status changes.
- `FeatureGate`
  Uses the validated license plus overrides to answer feature and limit checks.
- `LicenseValidationController`
  Exposes the optional online validation endpoint.

## License Key Format

Current generated license keys are:

- prefixed with `SG`
- split into three dot-separated segments
- composed of:
  - version prefix
  - URL-safe base64 payload JSON
  - URL-safe base64 detached signature

`LicenseSignature` currently:

- normalizes payload keys before signing
- signs with Ed25519 via `ext-sodium`
- verifies detached signatures with the configured public key

The configured public algorithm is currently `ed25519`.

## Canonical Payload Fields

The signed payload currently includes fields such as:

- `v`
- `alg`
- `kid`
- `license_id`
- `plan_id`
- `owner_id`
- `iat`
- `exp`
- `hb`

Meaning:

- `kid` identifies the configured key id
- `exp` is the absolute expiration timestamp
- `hb` encodes the maximum acceptable offline heartbeat staleness window

## Validation Lifecycle

`LicenseManager::validate()` is the canonical validation path.

Current validation sequence is:

1. verify key format
2. decode payload and signature
3. verify detached signature
4. verify payload version and algorithm
5. reject expired keys
6. extract `license_id`
7. reject revoked licenses
8. reject stale heartbeat
9. reject future heartbeat timestamps outside clock-skew allowance

Only after those checks does the package treat the license as valid.

## Heartbeat Freshness

Offline trust depends on heartbeat recency.

Current heartbeat behavior:

- license generation writes an initial heartbeat into `LicenseRevocationStore`
- successful online validation refreshes the heartbeat timestamp
- offline validation rejects licenses whose heartbeat is older than the allowed stale window
- cache key prefix, TTL, and clock skew are configurable

This is why offline validation is not only a signature check. It is also a freshness check.

## Revocation Store Model

`LicenseRevocationStore` keeps revocation state in cache.

It supports:

- full snapshots
- sequential deltas
- sequence tracking
- expiration of revocation state
- fail-open or fail-closed behavior on expired state

Important rules:

- full snapshots must advance the sequence
- deltas must be exactly next-sequence updates
- out-of-order or replayed deltas are ignored
- expired revocation state follows the configured fail-open rule

This lets the package consume external revocation feeds without embedding a central online dependency into every offline validation.

## License Persistence and Status

When generation has a resolvable owner and plan:

- the package persists a `License` record
- status defaults to `active`
- `expires_at` and `heartbeat_at` are populated

Persisted license state is then updated by later lifecycle events and activation activity.

Relevant persisted fields include:

- `status`
- `expires_at`
- `heartbeat_at`
- `domain`
- `max_activations`
- `current_activations`
- `feature_overrides`
- `limit_overrides`

## Activation and Deactivation

`LicenseManager` supports domain-bound activation and deactivation.

Activation rules currently include:

- license key must validate first
- persisted license record must exist
- license status must be `active` or `trialing`
- bound domain, if already set, must match
- duplicate activation for the same live domain is idempotent
- `max_activations` is enforced

Deactivation rules currently:

- locate the active domain activation row
- mark it with `deactivated_at`
- reconcile `current_activations`

## Feature and Limit Checks

There are two layers:

- `LicenseManager`
  Reads feature and limit claims directly from the signed payload.
- `FeatureGate`
  Applies persisted `feature_overrides` and `limit_overrides` first, then falls back to signed claims.

`FeatureManager` adds schedule-aware availability on top of `FeatureGate`.

Usage enforcement currently works through `LicenseUsage` rows and supports:

- current-usage reads
- usage increments with limit checks
- usage decrements as rollback rows

## Online Validation Endpoint

When enabled, the package registers `LicenseValidationController`.

Current endpoint behavior:

- requires `license_key`
- rejects oversized keys
- returns `422` for invalid licenses
- returns validation metadata for valid licenses
- refreshes heartbeat on successful validation using the resolved `license_id`

Rate limiting is configurable under `license.rate_limit.*`.

## Billing-to-License Bridge

The licensing system is intentionally linked to generic billing events, not provider-specific events.

`LicenseLifecycleListener` currently maps:

- `SubscriptionCreated`
  Generates and links a license when the subscription does not already have one.
- `PaymentCompleted`
  Marks the linked license `active` and refreshes heartbeat.
- `SubscriptionRenewed`
  Marks the linked license `active` and refreshes heartbeat.
- `PaymentFailed`
  Marks the linked license `past_due`.
- `SubscriptionRenewalFailed`
  Marks the linked license `past_due`.
- `SubscriptionCancelled`
  Marks the linked license `cancelled`.

This keeps licensing aligned with package billing meaning regardless of which payment provider produced the original event.

## Operational Commands

Current license-focused commands are:

- `subguard:generate-license`
- `subguard:check-license`
- `subguard:sync-license-revocations`
- `subguard:sync-license-heartbeats`

These commands cover key generation, local validation checks, revocation-feed sync, and heartbeat refresh from persisted licenses.

## Related Documents

Use the adjacent references when you need more context:

- [Licensing Overview](LICENSING.md)
- [Architecture](ARCHITECTURE.md)
- [Domain Billing](DOMAIN-BILLING.md)
- [Data Model](DATA-MODEL.md)
- [Events And Jobs](EVENTS-AND-JOBS.md)
- [Metered Billing](METERED-BILLING.md)
- [Seat-Based Billing](SEAT-BASED-BILLING.md)
