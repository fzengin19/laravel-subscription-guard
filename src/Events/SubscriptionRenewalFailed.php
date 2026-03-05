<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

class SubscriptionRenewalFailed
{
    public function __construct(
        public string $provider,
        public string $providerSubscriptionId,
        public int|string $subscriptionId,
        public float $amount,
        public ?string $reason = null,
        public ?string $providerTransactionId = null,
        public array $metadata = [],
    ) {}
}
