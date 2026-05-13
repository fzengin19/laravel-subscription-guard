<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

/**
 * Fired by ProcessTrialExpiryJob immediately before the package's default
 * Trialing → PastDue transition.
 *
 * Consuming applications listen for this event to decide what should happen
 * at trial end (e.g., dispatch a PaymentChargeJob to auto-charge the saved
 * payment method, send an email, extend the trial, etc). If the listener
 * transitions the subscription out of Trialing (synchronously), the
 * package's default PastDue transition is skipped.
 *
 * For provider-managed providers (iyzico) this event is NOT fired — the
 * provider's own subscription event drives the local transition via the
 * webhook intake pipeline.
 */
final class TrialEnding
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}
}
