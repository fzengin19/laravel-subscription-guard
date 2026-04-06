# Licensing

The package uses signed license keys and a subscription-to-license lifecycle bridge.

## Core Components

- `LicenseManager`
- `LicenseSignature`
- `LicenseRevocationStore`
- `LicenseValidationController`
- `LicenseLifecycleListener`

## Lifecycle Mapping

Generic subscription/payment events update license status:

- payment success -> `active`
- payment failed / renewal failed -> `past_due`
- subscription cancelled -> `cancelled`

## Offline Validation

Offline validation uses:

- signature checks
- heartbeat freshness
- revocation snapshot/delta cache

## Operations

Commands:

- `subguard:generate-license`
- `subguard:check-license`
- `subguard:sync-license-revocations`
- `subguard:sync-license-heartbeats`
