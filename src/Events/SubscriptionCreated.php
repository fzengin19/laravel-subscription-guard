<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

class SubscriptionCreated
{
    public function __construct(
        public string $provider,
        public string $providerSubscriptionId,
        public int|string $subscriptionId,
    ) {}
}
