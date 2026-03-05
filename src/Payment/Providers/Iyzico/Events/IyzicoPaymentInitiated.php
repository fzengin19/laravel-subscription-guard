<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

final class IyzicoPaymentInitiated
{
    public function __construct(
        public readonly int|float|string $amount,
        public readonly string $mode,
        public readonly array $details = [],
    ) {}
}
