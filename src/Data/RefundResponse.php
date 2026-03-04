<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class RefundResponse
{
    public function __construct(
        public bool $success,
        public ?string $refundId = null,
        public array $providerResponse = [],
        public ?string $failureReason = null,
    ) {}
}
