<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

class DunningExhausted
{
    public function __construct(
        public readonly string $provider,
        public readonly int|string $subscriptionId,
        public readonly int|string $transactionId,
        public readonly int $retryCount,
        public readonly ?string $lastFailureReason,
    ) {}
}
