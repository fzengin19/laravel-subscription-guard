<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

class PaymentCompleted
{
    public function __construct(
        public string $provider,
        public int|string|null $subscriptionId,
        public int|string|null $transactionId,
        public float $amount,
        public ?string $providerTransactionId = null,
        public array $metadata = [],
    ) {}
}
