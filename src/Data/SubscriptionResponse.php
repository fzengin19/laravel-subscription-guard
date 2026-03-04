<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class SubscriptionResponse
{
    public function __construct(
        public bool $success,
        public ?string $subscriptionId = null,
        public ?string $status = null,
        public array $providerResponse = [],
        public ?string $failureReason = null,
    ) {}
}
