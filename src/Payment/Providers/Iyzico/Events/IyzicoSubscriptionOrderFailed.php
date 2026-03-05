<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewalFailed;

class IyzicoSubscriptionOrderFailed extends SubscriptionRenewalFailed
{
    public readonly string $eventId;

    public readonly array $payload;

    public function __construct(
        string $eventId,
        ?string $subscriptionId,
        float $amount,
        array $payload = [],
    ) {
        $providerSubscriptionId = $subscriptionId ?? '';

        parent::__construct(
            provider: 'iyzico',
            providerSubscriptionId: $providerSubscriptionId,
            subscriptionId: $providerSubscriptionId,
            amount: $amount,
            reason: null,
            providerTransactionId: null,
            metadata: $payload,
        );

        $this->eventId = $eventId;
        $this->payload = $payload;
    }
}
