<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR;

use Illuminate\Support\Facades\Event;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\ProviderEventDispatcherInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Events\PaytrPaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Events\PaytrPaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Events\PaytrWebhookReceived;

final class PaytrProviderEventDispatcher implements ProviderEventDispatcherInterface
{
    public function dispatch(string $event, array $context = []): void
    {
        $subscriptionId = $this->string($context, 'subscription_id');
        $transactionId = $this->string($context, 'transaction_id');
        $eventId = $this->string($context, 'event_id');
        $amount = (float) ($context['amount'] ?? 0);
        $reason = $this->string($context, 'reason');
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];

        match ($event) {
            'subscription.order.success', 'payment.completed' => Event::dispatch(new PaytrPaymentCompleted(
                providerSubscriptionId: $subscriptionId,
                providerTransactionId: $transactionId,
                amount: $amount,
                providerResponse: $metadata,
            )),
            'subscription.order.failure', 'payment.failed' => Event::dispatch(new PaytrPaymentFailed(
                providerSubscriptionId: $subscriptionId,
                amount: $amount,
                reason: $reason,
                providerTransactionId: $transactionId,
                providerResponse: $metadata,
            )),
            default => null,
        };

        if ($eventId !== null || isset($context['event_type'])) {
            Event::dispatch(new PaytrWebhookReceived(
                eventId: $eventId,
                eventType: $this->string($context, 'event_type') ?? $event,
                duplicate: (bool) ($context['duplicate'] ?? false),
                payload: $metadata,
            ));
        }
    }

    private function string(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
