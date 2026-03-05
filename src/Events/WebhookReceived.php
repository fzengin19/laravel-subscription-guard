<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Events;

class WebhookReceived
{
    public function __construct(
        public string $provider,
        public ?string $eventId = null,
        public ?string $eventType = null,
        public bool $duplicate = false,
        public array $metadata = [],
    ) {}
}
