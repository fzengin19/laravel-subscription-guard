<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

class PaymentFailed
{
    public function __construct(
        public string $provider,
        public int|string|null $subscriptionId,
        public float $amount,
        public ?string $reason = null,
        public ?string $providerTransactionId = null,
        public array $metadata = [],
    ) {}
}
