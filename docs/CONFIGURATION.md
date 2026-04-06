# Configuration

Main config file:

- `config/subscription-guard.php`

Use this document as the current public reference for configuration groups and high-impact settings.

## Providers

Provider selection and provider-specific credentials live under:

- `providers.default`
- `providers.drivers.iyzico.*`
- `providers.drivers.paytr.*`

Important settings:

- `class`
- `event_dispatcher`
- `manages_own_billing`
- `mock`
- provider credentials
- callback URL
- signature or webhook response settings

High-impact notes:

- `providers.default` controls which provider is used by default when the package needs a default adapter.
- `manages_own_billing` changes who owns renewal execution:
  - iyzico: provider-managed recurring behavior
  - PayTR: package-managed recurring behavior
- `mock` must be treated carefully. It is useful for controlled local or test flows, but it is not a substitute for real provider validation.

See [Providers](PROVIDERS.md) for current provider-specific notes.

## Webhooks

Webhook behavior lives under:

- `webhooks.auto_register_routes`
- `webhooks.prefix`
- `webhooks.middleware`
- `webhooks.rate_limit.*`

High-impact notes:

- `auto_register_routes` controls whether the package registers its webhook routes automatically.
- `prefix` controls the base path for webhook and callback surfaces.
- `middleware` should stay compatible with your API stack and rate-limiting policy.
- `rate_limit.*` affects webhook intake protection and burst handling.

Current route shapes are summarized in [API](API.md).

## Queue

Queue behavior lives under:

- `queue.connection`
- `queue.queue`
- `queue.webhooks_queue`
- `queue.notifications_queue`

Default queue names:

- `subguard-billing`
- `subguard-webhooks`
- `subguard-notifications`

High-impact notes:

- `queue.connection` controls how package jobs are executed.
- `queue.queue` is the default billing queue.
- `webhooks_queue` and `notifications_queue` let you isolate workloads by concern.

## Billing

Billing runtime settings live under:

- `billing.timezone`
- `billing.grace_period_days`
- `billing.max_dunning_retries`
- `billing.dunning_retry_interval_days`

The billing group also exposes package command-name references such as:

- `renewal_command`
- `dunning_command`
- `suspend_command`
- `metered_command`
- `plan_changes_command`

High-impact notes:

- `timezone` affects billing-date interpretation and recurring scheduling.
- `grace_period_days`, `max_dunning_retries`, and `dunning_retry_interval_days` shape overdue and retry behavior.
- command-name values are useful when you want a central reference to the package's operational command surface.

Deeper billing-flow documentation will be added in later phases. For now, see [Recipes](RECIPES.md) and [API](API.md) where relevant.

## Locks

Lock behavior lives under:

- `locks.webhook_lock_ttl`
- `locks.webhook_block_timeout`
- `locks.callback_lock_ttl`
- `locks.callback_block_timeout`
- `locks.renewal_job_lock_ttl`
- `locks.dunning_job_lock_ttl`

High-impact notes:

- webhook and callback lock settings affect duplicate intake and concurrent delivery behavior.
- renewal and dunning lock settings affect queue-worker concurrency safety.

If you tune these values, do so with a clear understanding of your queue and cache backend behavior.

## Logging

Logging channel names live under:

- `logging.payments_channel`
- `logging.webhooks_channel`
- `logging.licenses_channel`

These settings let the host application isolate package logs by concern.

## License

License and validation settings live under:

- `license.algorithm`
- `license.key_id`
- `license.default_ttl_seconds`
- `license.validation_path`
- `license.auto_register_validation_route`
- `license.keys.*`
- `license.offline.*`
- `license.revocation.*`
- `license.events.*`
- `license.rate_limit.*`

High-impact notes:

- `keys.public` and `keys.private` control signing and validation behavior.
- `offline.*` controls heartbeat freshness and stale-license handling.
- `revocation.*` controls cache, snapshot, sync, and revocation-feed behavior.
- `validation_path` and `auto_register_validation_route` affect the online validation endpoint.

Operational commands related to this area include:

- `subguard:generate-license`
- `subguard:check-license`
- `subguard:sync-license-revocations`
- `subguard:sync-license-heartbeats`

See [Licensing](LICENSING.md) for the current public licensing overview.

## Routes

Route-related package flags currently live under:

- `routes.install_portal`

This area is intentionally small in the current public package surface. Do not assume that a fully documented billing portal is part of the supported runtime surface today.

## Configuration Strategy Notes

For a safe local start:

- begin with installation and simulated webhook validation
- add real provider credentials only when you intentionally move into live provider integration
- keep secrets in your environment, not in repository docs or example files

Continue with:

- [Installation](INSTALLATION.md)
- [Quickstart](QUICKSTART.md)
- [Providers](PROVIDERS.md)
- [Licensing](LICENSING.md)
