<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

final class IyzicoSubscriptionUpgraded
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly int|string $newPlanId,
        public readonly string $mode,
        public readonly array $providerResponse = [],
    ) {}
}
