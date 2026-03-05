<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;

class IyzicoPaymentCompleted extends PaymentCompleted
{
    public readonly string $mode;

    public readonly array $providerResponse;

    public function __construct(
        ?string $transactionId,
        int|float|string $amount,
        string $mode,
        array $providerResponse = [],
    ) {
        parent::__construct(
            provider: 'iyzico',
            subscriptionId: null,
            transactionId: null,
            amount: (float) $amount,
            providerTransactionId: $transactionId,
            metadata: $providerResponse,
        );

        $this->mode = $mode;
        $this->providerResponse = $providerResponse;
    }
}
