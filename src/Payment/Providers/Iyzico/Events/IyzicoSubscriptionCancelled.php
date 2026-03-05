<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCancelled;

class IyzicoSubscriptionCancelled extends SubscriptionCancelled
{
    public function __construct(
        string $subscriptionId,
    ) {
        parent::__construct(
            provider: 'iyzico',
            providerSubscriptionId: $subscriptionId,
            subscriptionId: $subscriptionId,
        );
    }
}
