<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class DiscountResult
{
    public function __construct(
        public bool $applied,
        public float $discountAmount = 0.0,
        public ?string $code = null,
        public ?string $reason = null,
    ) {}
}
