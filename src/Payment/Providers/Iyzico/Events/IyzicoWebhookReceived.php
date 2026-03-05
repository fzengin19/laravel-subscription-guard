<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\WebhookReceived;

class IyzicoWebhookReceived extends WebhookReceived
{
    public function __construct(
        string $eventId,
        string $eventType,
        array $payload,
    ) {
        parent::__construct(
            provider: 'iyzico',
            eventId: $eventId,
            eventType: $eventType,
            duplicate: false,
            metadata: $payload,
        );
    }
}
