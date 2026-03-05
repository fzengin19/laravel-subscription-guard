<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Events;

use SubscriptionGuard\LaravelSubscriptionGuard\Events\WebhookReceived;

final class PaytrWebhookReceived extends WebhookReceived
{
    public function __construct(
        ?string $eventId,
        ?string $eventType,
        bool $duplicate,
        array $payload = [],
    ) {
        parent::__construct(
            provider: 'paytr',
            eventId: $eventId,
            eventType: $eventType,
            duplicate: $duplicate,
            metadata: $payload,
        );
    }
}
