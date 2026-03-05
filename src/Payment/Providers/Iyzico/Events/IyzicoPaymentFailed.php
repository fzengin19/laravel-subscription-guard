<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;

class IyzicoPaymentFailed extends PaymentFailed
{
    public readonly string $mode;

    public readonly array $details;

    public function __construct(
        int|float|string $amount,
        string $mode,
        ?string $reason = null,
        array $details = [],
    ) {
        parent::__construct(
            provider: 'iyzico',
            subscriptionId: null,
            amount: (float) $amount,
            reason: $reason,
            providerTransactionId: null,
            metadata: $details,
        );

        $this->mode = $mode;
        $this->details = $details;
    }
}
