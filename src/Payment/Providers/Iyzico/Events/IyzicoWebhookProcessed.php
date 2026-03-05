<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

final class IyzicoWebhookProcessed
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly bool $duplicate = false,
    ) {}
}
