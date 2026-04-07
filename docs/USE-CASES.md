# Use Cases

This document describes practical scenarios the package supports, with guidance on how each is implemented.

## SaaS Subscription with Iyzico

**Scenario**: A SaaS application using Iyzico for recurring billing with license-gated features.

**Setup**:
1. Configure Iyzico as default provider with `manages_own_billing = true`
2. Create plans via `subguard:sync-plans --remote`
3. Initialize subscriptions through Iyzico checkout flow
4. License is auto-generated on `SubscriptionCreated` event

**Runtime**:
- Iyzico handles recurring charges remotely
- Webhooks update local subscription and license state
- `FeatureGate` checks license for feature access
- Heartbeat sync keeps offline validation fresh

**Relevant docs**: [Iyzico Provider](providers/IYZICO.md), [Domain Licensing](DOMAIN-LICENSING.md), [Webhooks](WEBHOOKS.md)

## Self-Managed Billing with PayTR

**Scenario**: An application using PayTR where the package manages the full billing lifecycle.

**Setup**:
1. Configure PayTR with `manages_own_billing = false`
2. Create plans locally
3. Initialize subscriptions with initial PayTR payment

**Runtime**:
- Schedule `subguard:process-renewals` to dispatch charges
- Schedule `subguard:process-dunning` for failed payment retries
- Schedule `subguard:suspend-overdue` for grace period enforcement
- Invoice generation is automatic on successful charges

**Relevant docs**: [PayTR Provider](providers/PAYTR.md), [Dunning And Retries](DUNNING-AND-RETRIES.md), [Commands](COMMANDS.md)

## Usage-Based API Billing

**Scenario**: An API platform charging customers based on request volume per billing period.

**Setup**:
1. Create subscriptions with `metered_price_per_unit` in metadata
2. Link each subscription to a license
3. Record API usage as `LicenseUsage` rows in your middleware or service layer

**Runtime**:
- Application code records `LicenseUsage` rows with quantity and period_start
- Schedule `subguard:process-metered-billing` daily
- Processor aggregates unbilled usage, creates idempotent transaction, charges provider
- Usage rows are marked `billed_at` after charging

**Relevant docs**: [Metered Billing](METERED-BILLING.md), [Domain Licensing](DOMAIN-LICENSING.md)

## Team Subscription with Seats

**Scenario**: A team plan where the organization pays per seat and can add/remove members.

**Setup**:
1. Create a subscription for the organization
2. Use `SeatManager::addSeat()` when team members are added

**Runtime**:
- `SeatManager` adjusts `SubscriptionItem.quantity`
- Proration is calculated and stored in subscription metadata
- License `limit_overrides.seats` is synced automatically
- Application code uses `FeatureGate` to enforce seat limits

**Note**: Seat changes are local mutations. The prorated amount is calculated but not automatically charged — integrator must handle the charge. See [Seat-Based Billing](SEAT-BASED-BILLING.md).

## Multi-Provider Application

**Scenario**: An application offering both Iyzico and PayTR as payment options.

**Setup**:
1. Configure both providers in `config/subscription-guard.php`
2. Set default provider or let users choose at checkout

**Runtime**:
- Each subscription tracks its own `provider` field
- Renewals, dunning, and webhooks route to the correct adapter
- Generic billing events (not provider-specific) drive license lifecycle
- Both providers share the same subscription and license models

**Relevant docs**: [Domain Providers](DOMAIN-PROVIDERS.md), [Architecture](ARCHITECTURE.md)

## License-Only Distribution (No Billing)

**Scenario**: Software distribution with pre-generated license keys, no recurring billing.

**Setup**:
1. Configure license signing keys
2. Generate licenses via `subguard:generate-license`
3. Distribute keys to customers

**Runtime**:
- Customers validate licenses via the online validation endpoint or offline
- Heartbeats are refreshed on online validation
- Revocations are synced from your revocation feed
- `FeatureGate` enforces feature access based on license claims

**Note**: This scenario uses only the licensing subsystem. Billing is optional.

**Relevant docs**: [Domain Licensing](DOMAIN-LICENSING.md), [Commands](COMMANDS.md)

## E-Fatura Integration Hook

**Scenario**: Integrating with a Turkish e-fatura provider after payment completion.

**Setup**:
1. Listen to `PaymentCompleted` in your application
2. Build e-fatura payload from the transaction and invoice
3. Submit to your e-fatura provider
4. Store the response reference in your own audit table

The package provides the billing events and invoice data. E-fatura submission is the integrator's responsibility.

**Relevant docs**: [Invoicing](INVOICING.md), [Events And Jobs](EVENTS-AND-JOBS.md), [Recipes](RECIPES.md)

## Related Documents

- [Recipes](RECIPES.md)
- [FAQ](FAQ.md)
- [Architecture](ARCHITECTURE.md)
- [Configuration](CONFIGURATION.md)
