<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCreated;

class IyzicoSubscriptionCreated extends SubscriptionCreated
{
    public readonly array $plan;

    public readonly array $providerResponse;

    public function __construct(
        ?string $subscriptionId,
        array $plan = [],
        array $providerResponse = [],
    ) {
        $providerSubscriptionId = $subscriptionId ?? '';

        parent::__construct(
            provider: 'iyzico',
            providerSubscriptionId: $providerSubscriptionId,
            subscriptionId: $providerSubscriptionId,
        );

        $this->plan = $plan;
        $this->providerResponse = $providerResponse;
    }
}
