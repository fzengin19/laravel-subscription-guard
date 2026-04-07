# Licensing

Use this document as the short licensing overview.

The canonical licensing-domain reference now lives in [Domain Licensing](DOMAIN-LICENSING.md).

## What The Licensing Layer Covers

The package uses signed license keys plus a billing-to-license lifecycle bridge.

The current licensing surface includes:

- signed key generation and verification
- persisted license records
- online validation endpoint support
- offline heartbeat freshness checks
- revocation snapshot and delta handling
- domain activation and deactivation
- feature and limit enforcement
- middleware and Blade integration through feature gates

## Core Components

- `LicenseManager`
- `LicenseSignature`
- `LicenseRevocationStore`
- `LicenseValidationController`
- `LicenseLifecycleListener`
- `FeatureGate`

## Lifecycle Mapping

Generic subscription/payment events update license status:

- payment success -> `active`
- payment failed / renewal failed -> `past_due`
- subscription cancelled -> `cancelled`

License creation is also driven from the generic `SubscriptionCreated` event when the subscription does not already have a linked license.

## Offline Validation

Offline validation uses:

- signature checks
- heartbeat freshness
- revocation snapshot/delta cache

This means offline trust is not signature-only. It also depends on revocation and heartbeat freshness.

## Operations

Commands:

- `subguard:generate-license`
- `subguard:check-license`
- `subguard:sync-license-revocations`
- `subguard:sync-license-heartbeats`

## Where To Go Next

Use the deeper references by question:

- [Domain Licensing](DOMAIN-LICENSING.md)
- [Architecture](ARCHITECTURE.md)
- [Data Model](DATA-MODEL.md)
- [Events And Jobs](EVENTS-AND-JOBS.md)
