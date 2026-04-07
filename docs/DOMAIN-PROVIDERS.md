# Domain Providers

Use this document to understand the provider abstraction, ownership model differences, and the contract between provider adapters and package orchestration.

## Provider Abstraction

The package resolves payment providers through `PaymentManager`.

Each configured driver supplies:

- a provider class
- a provider-event dispatcher class
- a `manages_own_billing` flag
- provider-specific credentials and callback settings

Provider adapters must implement `PaymentProviderInterface`.

That contract currently includes:

- one-off pay
- refund
- subscription create
- subscription cancel
- subscription upgrade
- recurring charge
- webhook signature validation
- webhook normalization into `WebhookResult`

## Architectural Boundary

Provider adapters are responsible for:

- talking to remote payment APIs
- validating callback or webhook signatures
- normalizing provider payloads into package data objects
- returning provider responses without mutating package models directly

Provider adapters are not responsible for:

- changing `Subscription`, `Transaction`, or `License` records directly
- deciding domain-level lifecycle transitions
- dispatching generic billing events themselves

Those responsibilities belong to `SubscriptionService`, listeners, and jobs.

## Resolution Model

`PaymentManager` provides the package's provider lookup rules.

It currently owns:

- available provider config lookup
- default provider resolution
- provider ownership check through `managesOwnBilling()`
- adapter instantiation and interface enforcement
- queue-name lookup from config

Provider-specific event dispatchers are resolved separately through `ProviderEventDispatcherResolver`.

If no valid event dispatcher is configured, the package falls back to a null dispatcher instead of failing the orchestration path.

## Ownership Modes

The most important provider-domain concept is billing ownership.

### Provider-Managed Mode

Current example: `iyzico`

Behavior:

- recurring billing is executed remotely
- local renewals are reflected from inbound provider events
- local recurring-charge orchestration is skipped for renewal dispatch
- webhook and callback normalization is central to local truth

### Package-Managed Mode

Current example: `paytr`

Behavior:

- the package dispatches and executes recurring charges itself
- provider adapter supplies pay, recurring charge, refund, and webhook normalization surfaces
- local retry and suspension logic remains inside package jobs and commands

See [Domain Billing](DOMAIN-BILLING.md) for the consequences on renewal and dunning flow.

## Event Dispatcher Boundary

The provider event-dispatcher layer exists so the package can emit provider-specific events without pushing provider concerns into core billing events.

Current pattern:

- generic events such as `PaymentCompleted` and `SubscriptionRenewed` represent package-level meaning
- provider dispatchers translate orchestration context into provider-specific events

Examples:

- `IyzicoProviderEventDispatcher`
  Emits iyzico-specific success, failure, cancellation, and subscription-order events.
- `PaytrProviderEventDispatcher`
  Emits paytr payment and webhook events when orchestration supplies enough context.

This separation lets the package:

- keep generic billing listeners stable
- preserve provider-specific observability
- avoid coupling host applications to one provider contract

## Inbound Webhooks and Callbacks

All providers share the same inbound route shape, but the provider adapter decides how signatures and payloads are interpreted.

Current shared intake pattern:

1. request reaches webhook or callback controller
2. package checks that the provider exists
3. callback routes validate signatures before persistence
4. package derives an event id
5. package persists a `WebhookCall`
6. `FinalizeWebhookEventJob` re-validates webhook signatures and calls `processWebhook()`
7. `SubscriptionService` applies the normalized result

This means provider adapters are normalization endpoints, not billing state machines.

## iyzico Summary

Current iyzico characteristics:

- `manages_own_billing = true`
- live and mock paths both exist
- webhook signature header defaults to `x-iyz-signature-v3`
- supports direct payment, checkout form, and 3DS callback modes
- supports subscription create, cancel, upgrade, plan sync, and reconciliation helpers
- exposes stored-card helper methods through the adapter support layer

Important integration consequences:

- iyzico recurring billing is remote-first
- local renewal state is usually created from webhook or callback results
- local plan sync and live sandbox validation are distinct from the deterministic suite

## PayTR Summary

Current PayTR characteristics:

- `manages_own_billing = false`
- package-managed recurring billing model
- pay flow returns iframe-oriented response metadata
- webhook validation uses merchant key plus salt hashing rules
- webhook normalization maps success to `subscription.order.success`
- webhook normalization maps failure to `subscription.order.failure`

Important integration consequences:

- PayTR renewals, retries, and suspension behavior are package-driven
- provider adapter remains relatively thin compared with iyzico's remote recurring surface

## Mock vs Live Boundary

Both providers expose mock configuration flags.

The intent is:

- mock mode for controlled local and deterministic test scenarios
- live mode for real provider integration

Mock mode is not a production substitute.

The public safety rule remains:

- keep provider secrets in the environment
- validate live behavior intentionally
- treat live sandbox or live provider traffic as a separate operating mode

## Current Public Entry Points

For provider-related public docs, use:

- [Providers Overview](PROVIDERS.md)
- [API](API.md)
- [Architecture](ARCHITECTURE.md)
- [Domain Billing](DOMAIN-BILLING.md)

Provider-specific deep reference docs under `docs/providers/` belong to the next documentation phase.
