<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;

final class PaytrPaymentFailed extends PaymentFailed
{
    public function __construct(
        ?string $providerSubscriptionId,
        float $amount,
        ?string $reason = null,
        ?string $providerTransactionId = null,
        array $providerResponse = [],
    ) {
        parent::__construct(
            provider: 'paytr',
            subscriptionId: $providerSubscriptionId,
            amount: $amount,
            reason: $reason,
            providerTransactionId: $providerTransactionId,
            metadata: $providerResponse,
        );
    }
}
