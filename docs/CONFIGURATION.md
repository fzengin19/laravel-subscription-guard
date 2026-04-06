# Configuration

Main config file: `config/subscription-guard.php`

## Providers

- `providers.default`
- `providers.drivers.iyzico.*`
- `providers.drivers.paytr.*`

Important flags:

- `manages_own_billing`
- `mock`
- signature headers and credentials

## Queue Keys

- `queue.connection`
- `queue.queue`
- `queue.webhooks_queue`
- `queue.notifications_queue`

Default queues:

- `subguard-billing`
- `subguard-webhooks`
- `subguard-notifications`

## Webhooks

- `webhooks.prefix`
- `webhooks.middleware`

## License and Offline Validation

- `license.keys.*`
- `license.offline.*`
- `license.revocation.*`

Operational commands:

- `subguard:sync-license-revocations`
- `subguard:sync-license-heartbeats`

## Billing Runtime

- `billing.retry_intervals_days`
- `billing.grace_period_days`
- `billing.timezone`
