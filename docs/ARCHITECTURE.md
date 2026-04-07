# Architecture

Use this document to understand how Laravel Subscription Guard is organized as a runtime system.

It explains the package boundaries, the main orchestration path, and how synchronous HTTP intake turns into asynchronous billing and licensing work.

## System Shape

At a high level, the package is composed of six cooperating layers:

1. entry points
2. orchestration services
3. provider adapters
4. domain models
5. events and jobs
6. licensing and feature gates

The host Laravel application owns:

- the application user or billable models
- secrets and provider credentials
- database and queue infrastructure
- any application-specific UI or billing portal

The package owns:

- subscription and transaction persistence
- payment-provider adapter selection
- webhook and callback intake
- billing orchestration and retry flow
- license generation, validation, and revocation support
- operational commands and background jobs

## Runtime Actors

The main package actors are:

- `LaravelSubscriptionGuardServiceProvider`
  Registers commands, container bindings, middleware aliases, Blade directives, license listeners, and optional routes.
- `PaymentManager`
  Resolves configured providers, ownership mode, and queue names.
- `SubscriptionService`
  Coordinates local billing state, transaction creation, provider event dispatch, and webhook-result application.
- provider adapters
  Implement `PaymentProviderInterface` and handle provider API calls, signature validation, and webhook normalization.
- `LicenseManager`
  Signs, validates, activates, deactivates, and revokes license keys.
- `LicenseRevocationStore`
  Maintains revocation snapshot or delta state and heartbeat cache entries.
- Eloquent models
  Persist subscriptions, transactions, webhook calls, licenses, usage records, invoices, discounts, and related entities.
- jobs
  Execute deferred webhook finalization, renewal charging, dunning retries, scheduled plan changes, metered billing, and notifications.

## Service Provider Responsibilities

`LaravelSubscriptionGuardServiceProvider` is the package composition root.

It currently:

- registers 13 Artisan commands
- binds the payment, provider-event, billing, licensing, and feature services into the container
- defines webhook and license-validation rate limiters
- aliases middleware:
  - `subguard.feature`
  - `subguard.limit`
- defines Blade directives:
  - `@subguardfeature`
  - `@subguardlimit`
- auto-registers webhook and callback routes when enabled
- auto-registers the license validation route when enabled
- wires generic billing events to `LicenseLifecycleListener`

This means the public runtime surface is largely convention-driven from configuration, not manual host-app route registration by default.

## Route Registration Model

The package can auto-register two inbound surfaces:

- webhook and callback routes under `subscription-guard.webhooks.prefix`
- online license validation under `subscription-guard.license.validation_path`

Default webhook route shapes are:

- `POST /subguard/webhooks/{provider}`
- `POST /subguard/webhooks/{provider}/3ds/callback`
- `POST /subguard/webhooks/{provider}/checkout/callback`

Default license validation route shape is:

- `POST /subguard/licenses/validate`

See [API](API.md) for the route summary and [Events And Jobs](EVENTS-AND-JOBS.md) for what happens after intake.

## Orchestration Core

`SubscriptionService` is the main package orchestrator.

Its responsibilities include:

- creating local pending subscriptions
- cancelling, pausing, resuming, upgrading, and downgrading subscriptions
- dispatching renewal, dunning, and scheduled-plan-change jobs
- applying normalized webhook results to local state
- creating local renewal transactions for provider callbacks or direct payment results
- dispatching generic domain events and provider-specific event dispatchers
- persisting provider payment-method tokens through a controlled path

The architectural rule is:

- provider adapters normalize provider behavior
- `SubscriptionService` mutates local billing state
- listeners and follow-up jobs react to domain events

This keeps provider code from directly editing package domain models.

## Ownership Model Split

The package supports two billing ownership modes.

### Provider-Managed Billing

Current example: `iyzico`

Characteristics:

- recurring billing is managed remotely by the provider
- local renewal state is updated from webhook or callback results
- `processRenewals()` skips these subscriptions
- webhook finalization and `handleWebhookResult()` become the main local state-update path

### Package-Managed Billing

Current example: `paytr`

Characteristics:

- the package schedules and executes renewals itself
- due subscriptions produce renewal jobs and renewal transactions
- failed charges enter dunning flow
- local commands and jobs are responsible for retries and suspension

See [Domain Billing](DOMAIN-BILLING.md) and [Domain Providers](DOMAIN-PROVIDERS.md) for the behavior differences in more detail.

## Sync vs Async Boundaries

The package intentionally separates fast intake from heavier processing.

### Synchronous Boundaries

These run during the request or command call:

- route registration and request acceptance
- provider existence checks
- payload sanity checks
- callback signature validation
- `WebhookCall` persistence and duplicate detection
- command dispatch of renewal, dunning, plan-change, and metered processors
- direct license validation endpoint responses

### Asynchronous Boundaries

These usually run through queues:

- `FinalizeWebhookEventJob`
- `ProcessRenewalCandidateJob`
- `PaymentChargeJob`
- `ProcessDunningRetryJob`
- `ProcessScheduledPlanChangeJob`
- `DispatchBillingNotificationsJob`
- `SyncBillingProfileJob`

The queue split is deliberate:

- billing jobs on `subguard-billing`
- webhook finalization on `subguard-webhooks`
- notifications on `subguard-notifications`

## Main Flows

### 1. Local Subscription Creation Path

Typical shape:

1. host app chooses plan and payment method
2. `SubscriptionService::create()` creates a local pending subscription
3. provider-specific create or pay flow produces provider identifiers or payment results
4. package persists provider payment-method tokens through the service layer when applicable
5. later events or direct payment handling move the subscription into active or past-due state

### 2. Webhook or Callback Intake Path

Typical shape:

1. controller accepts inbound request
2. package derives an event id
3. package stores or reopens a `WebhookCall`
4. package queues `FinalizeWebhookEventJob`
5. finalization validates signature and calls `processWebhook()`
6. normalized `WebhookResult` is passed to `SubscriptionService::handleWebhookResult()`
7. generic domain events, provider events, transaction state, subscription state, and notifications are updated

### 3. Self-Managed Renewal Path

Typical shape:

1. `subguard:process-renewals` finds due self-managed subscriptions
2. `ProcessRenewalCandidateJob` creates an idempotent renewal transaction
3. `PaymentChargeJob` calls `chargeRecurring()` on the provider adapter
4. success marks the transaction processed and advances billing date
5. failure marks the subscription `past_due` and enters dunning

### 4. License Validation Path

Typical shape:

1. `LicenseManager` verifies signature and payload
2. expiration, revocation, and heartbeat freshness are checked
3. online endpoint refreshes heartbeat on successful validation
4. feature and limit checks build on the validated license state

See [Domain Licensing](DOMAIN-LICENSING.md).

## Safety and Consistency Rules

The architecture relies on a few explicit rules:

- provider adapters do not directly mutate package domain models
- webhook and callback intake is idempotent per provider plus event id
- background jobs take cache locks before mutating critical billing state
- renewal and webhook transaction creation is idempotent
- license state follows generic billing events through a listener bridge
- secrets stay in environment-owned configuration, not documentation or committed example values

## Where To Go Next

Use the deeper reference documents by topic:

- [Domain Billing](DOMAIN-BILLING.md)
- [Domain Providers](DOMAIN-PROVIDERS.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
- [Data Model](DATA-MODEL.md)
- [Events And Jobs](EVENTS-AND-JOBS.md)
