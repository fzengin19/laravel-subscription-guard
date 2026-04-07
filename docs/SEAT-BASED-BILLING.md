# Seat-Based Billing

Use this document to understand how the package manages seat quantity changes, proration calculations, and license synchronization for multi-seat subscriptions.

## Concept

Seat-based billing allows a subscription to represent multiple units (seats, users, licenses) with a quantity that can change mid-cycle. When seats are added or removed, the package calculates a time-based proration and synchronizes the seat count to the linked license.

## Implementation Status

Seat management is implemented at the service layer through `SeatManager`. The current implementation covers:

- Adding and removing seats
- Time-based proration calculation
- Subscription metadata updates
- License limit synchronization

The current implementation does **not** include:

- Provider-side seat synchronization
- Automatic seat-based recurring charge adjustment
- Seat-based pricing tiers
- Seat change transaction recording
- Seat change event dispatching

This means seat changes are local mutations. The billing amount is not automatically recalculated on the provider side.

## SeatManager API

### addSeat

```php
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\SeatManager;

$seatManager = app(SeatManager::class);
$success = $seatManager->addSeat($subscription, count: 2);
```

Adds seats to the subscription. Returns `false` if `count <= 0`.

### removeSeat

```php
$success = $seatManager->removeSeat($subscription, count: 1);
```

Removes seats from the subscription. Returns `false` if `count <= 0` or if the resulting quantity would drop below 1.

### calculateProration

```php
$prorationAmount = $seatManager->calculateProration($subscription, change: 3);
```

Returns the prorated charge amount for adding or removing seats based on the remaining time in the current billing period.

## How Seat Changes Work

When `addSeat()` or `removeSeat()` is called:

1. The subscription row is locked with `lockForUpdate()`
2. The `SubscriptionItem` for this subscription is located or created
   - If no item exists, one is created with `quantity = 1` and `unit_price` from the subscription's `amount`
3. The new quantity is calculated (`current + delta`)
4. If the new quantity would be less than 1, the operation is rejected
5. The `SubscriptionItem.quantity` is updated
6. Subscription metadata is updated:
   - `seat_quantity` — the new count
   - `last_seat_proration` — the calculated proration amount
7. If the subscription has a linked license:
   - The license's `limit_overrides.seats` is updated to match the new quantity

## Proration Calculation

Proration uses a time-remaining ratio:

```
unit_price = SubscriptionItem.unit_price ?? Subscription.amount
ratio = remaining_seconds_in_period / total_seconds_in_period
proration = round(unit_price × change × ratio, 2)
```

Edge cases:

- If `current_period_start` or `current_period_end` is not set, full unit price is used (no time ratio)
- If the period has already ended (`period_end <= now`), proration is `0.0`

## SubscriptionItem Model

`SubscriptionItem` is the quantity-aware line item:

| Field | Purpose |
|---|---|
| `subscription_id` | Parent subscription |
| `plan_id` | Associated plan |
| `quantity` | Current seat count |
| `unit_price` | Per-seat price |

The item is auto-created on first seat change if it does not exist.

## License Synchronization

Seat changes automatically update the linked license's `limit_overrides`:

```json
{
  "seats": 5
}
```

This allows `FeatureGate` to enforce seat limits during license checks. The license's `limit_overrides.seats` always reflects the current subscription seat count.

## Concurrency Safety

All seat mutations happen within a database transaction with `lockForUpdate()` on:

- The subscription row
- The subscription item row
- The license row (if linked)

This prevents race conditions from concurrent seat change requests.

## Limitations

- **No provider sync**: Seat changes are local only. The provider is not notified of quantity changes.
- **No automatic billing adjustment**: Adding seats does not trigger a prorated charge to the provider. The proration amount is calculated and stored in metadata, but charging it is the integrator's responsibility.
- **No seat-based pricing tiers**: All seats use the same unit price.
- **No seat change events**: Seat changes do not dispatch domain events.
- **No seat change transactions**: Seat changes are not recorded as billing transactions.
- **Minimum 1 seat**: The quantity cannot drop below 1.

## Usage Pattern

A typical integration would:

1. Call `SeatManager::addSeat()` when a user adds team members
2. Read `last_seat_proration` from subscription metadata
3. Charge the prorated amount through your own billing logic or provider API
4. On next renewal, the full seat count is reflected in the subscription amount

## Related Documents

- [Domain Billing](DOMAIN-BILLING.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
- [Data Model](DATA-MODEL.md)
