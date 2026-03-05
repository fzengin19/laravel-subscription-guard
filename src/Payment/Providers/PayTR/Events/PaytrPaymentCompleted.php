<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;

final class PaytrPaymentCompleted extends PaymentCompleted
{
    public function __construct(
        ?string $providerSubscriptionId,
        ?string $providerTransactionId,
        float $amount,
        array $providerResponse = [],
    ) {
        parent::__construct(
            provider: 'paytr',
            subscriptionId: $providerSubscriptionId,
            transactionId: null,
            amount: $amount,
            providerTransactionId: $providerTransactionId,
            metadata: $providerResponse,
        );
    }
}
