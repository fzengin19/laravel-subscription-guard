<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class WebhookResult
{
    public function __construct(
        public bool $processed,
        public ?string $eventId = null,
        public ?string $eventType = null,
        public bool $duplicate = false,
        public ?string $message = null,
        public ?string $subscriptionId = null,
        public ?string $transactionId = null,
        public ?float $amount = null,
        public ?string $status = null,
        public ?string $nextBillingDate = null,
        public array $metadata = [],
    ) {}
}
