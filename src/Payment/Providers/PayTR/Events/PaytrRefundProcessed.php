<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Events;

final class PaytrRefundProcessed
{
    public function __construct(
        public readonly ?string $refundId,
        public readonly ?string $transactionId,
        public readonly float $amount,
        public readonly array $providerResponse = [],
    ) {}
}
