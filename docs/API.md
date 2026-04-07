# API

Use this document as the route and command index for public integration surfaces.

For transport rules, provider-specific behavior, and async intake details, use the linked deeper docs instead of treating this file as the full integration manual.

## Inbound Route Surface

The package currently exposes two inbound route groups when auto-registration is enabled:

- payment-provider webhooks and callbacks
- online license validation

## Webhook Route

- `POST /subguard/webhooks/{provider}`
- named route: `subguard.webhooks.handle`

`{provider}` supports configured driver keys such as `iyzico` and `paytr`.

See [Webhooks](WEBHOOKS.md) for duplicate handling, queue handoff, and response semantics.

## Callback Routes

- `POST /subguard/webhooks/{provider}/3ds/callback`
- `POST /subguard/webhooks/{provider}/checkout/callback`
- named routes:
  - `subguard.webhooks.3ds-callback`
  - `subguard.webhooks.checkout-callback`

Current repo coverage shows active callback-oriented behavior for `iyzico`.

See [Callbacks](CALLBACKS.md) for signature validation and acceptance behavior.

## License Validation Route

- `POST /subguard/licenses/validate`
- named route: `subscription-guard.license.validate`

This route is auto-registered only when:

- `subscription-guard.license.auto_register_validation_route = true`

It accepts `license_key` and returns:

- `422` for missing, oversized, or invalid keys
- `200` with validation metadata for valid keys

See [Domain Licensing](DOMAIN-LICENSING.md) for the validation model behind this endpoint.

## Webhook Simulator Command

```bash
php artisan subguard:simulate-webhook {provider} {event}
```

Options:

- `--event-id=`
- `--subscription-id=`
- `--transaction-id=`
- `--amount=`

The simulator dispatches a real local HTTP request against the package webhook route.

This makes it useful for:

- local webhook ingress validation
- duplicate-event testing through `--event-id`
- provider-specific signature generation in supported scenarios

See [Webhooks](WEBHOOKS.md) for the intake rules and [providers/IYZICO](providers/IYZICO.md) or [providers/PAYTR](providers/PAYTR.md) for provider context.

## Provider-Specific Commands

Current provider-specific commands:

- `subguard:sync-plans`
- `subguard:reconcile-iyzico-subscriptions`

These currently belong to the `iyzico` surface.

See [providers/IYZICO](providers/IYZICO.md) for when they matter.

## Operational Commands

- `subguard:process-renewals`
- `subguard:process-dunning`
- `subguard:suspend-overdue`
- `subguard:process-metered-billing`
- `subguard:process-plan-changes`
- `subguard:sync-license-revocations`
- `subguard:sync-license-heartbeats`

## Where To Go Next

- [Webhooks](WEBHOOKS.md)
- [Callbacks](CALLBACKS.md)
- [Providers](PROVIDERS.md)
- [Domain Providers](DOMAIN-PROVIDERS.md)
- [iyzico Provider](providers/IYZICO.md)
- [PayTR Provider](providers/PAYTR.md)
- [Custom Provider](providers/CUSTOM-PROVIDER.md)
