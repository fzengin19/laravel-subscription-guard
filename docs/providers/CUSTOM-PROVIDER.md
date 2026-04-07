# Custom Provider

Use this document when you want to add a new payment provider to Laravel Subscription Guard.

The package already exposes the required contracts, but custom providers need to fit the existing orchestration model instead of bypassing it.

## When To Add A Custom Provider

You need a custom provider when:

- your payment gateway is not `iyzico` or `paytr`
- you need a provider-specific adapter that still participates in the package billing and licensing lifecycle
- you want to preserve the package's webhook, queue, and event model instead of building a separate billing subsystem

If the host application is going to own the entire billing lifecycle outside this package, a custom provider adapter may be the wrong integration point.

## Required Contract

Your adapter must implement `PaymentProviderInterface`.

Current required methods cover:

- provider identity
- billing ownership model
- payment and refund calls
- subscription create, cancel, and upgrade
- recurring charge support
- webhook validation
- webhook normalization into `WebhookResult`

The key rule is:

- provider adapters normalize provider behavior
- package services mutate package state

Do not let a custom provider directly update `Subscription`, `Transaction`, `License`, or related models.

## Choosing `manages_own_billing`

This decision is architectural, not cosmetic.

Choose `manages_own_billing = true` when:

- the remote provider owns recurring charging
- local renewals should be reflected from inbound provider events
- local recurring charge orchestration should be skipped

Choose `manages_own_billing = false` when:

- the package should discover due subscriptions
- the package should dispatch recurring charges
- local dunning and suspension should remain active

If this flag is wrong, the package will orchestrate the wrong renewal path.

## Webhook Normalization Contract

Your provider must support:

- `validateWebhook(array $payload, string $signature): bool`
- `processWebhook(array $payload): WebhookResult`

Normalization expectations:

- derive a stable event id
- map provider success and failure into package event types
- return subscription id, transaction id, amount, and status where available
- preserve the original payload in `metadata` when it is useful for later handling

The package controllers and finalization job assume the provider can turn raw webhook input into a package-readable result.

## Event Dispatcher Guidance

Provider-specific event dispatching is split from the core provider adapter.

If you want provider-specific observability, add a class that implements `ProviderEventDispatcherInterface` and register it under the provider config.

If you do not register a valid dispatcher, the resolver falls back to a null dispatcher instead of failing the orchestration path.

That fallback is useful for resilience, but a real custom provider usually benefits from explicit provider-specific events.

## Config Registration

A custom driver entry should provide, at minimum:

- `class`
- `event_dispatcher`
- `manages_own_billing`
- provider credentials and route-sensitive settings

Optional behavior can include:

- `signature_header`
- `callback_url`
- `webhook_response_format`
- `webhook_response_body`

Keep real credentials in environment variables, not in committed docs or example values.

## Testing Expectations

Before treating a custom provider as production-ready, cover at least:

- adapter contract tests
- webhook validation and normalization tests
- ingress tests for duplicate delivery and retry behavior
- ownership-model tests for renewal orchestration
- any provider-specific callback or signature edge cases

The existing `iyzico` and `paytr` feature tests are the best current examples of the level of evidence expected.

## Safe Integration Checklist

- implement `PaymentProviderInterface`
- decide billing ownership correctly
- register the provider class and optional event dispatcher in config
- keep package model mutation inside `SubscriptionService` and jobs
- add ingress and normalization tests before relying on live traffic

## Where To Go Next

- [Domain Providers](../DOMAIN-PROVIDERS.md)
- [Webhooks](../WEBHOOKS.md)
- [Callbacks](../CALLBACKS.md)
- [API](../API.md)
