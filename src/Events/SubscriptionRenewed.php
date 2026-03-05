<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

class SubscriptionRenewed
{
    public function __construct(
        public string $provider,
        public string $providerSubscriptionId,
        public int|string $subscriptionId,
        public float $amount,
        public ?string $providerTransactionId = null,
        public array $metadata = [],
    ) {}
}
