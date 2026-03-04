<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class WebhookResult
{
    public function __construct(
        public bool $processed,
        public ?string $eventId = null,
        public bool $duplicate = false,
        public ?string $message = null,
    ) {}
}
