<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico;

use Illuminate\Support\Facades\Event;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\ProviderEventDispatcherInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events\IyzicoPaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events\IyzicoPaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events\IyzicoSubscriptionCancelled;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events\IyzicoSubscriptionCreated;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events\IyzicoSubscriptionOrderFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Events\IyzicoSubscriptionOrderSucceeded;

final class IyzicoProviderEventDispatcher implements ProviderEventDispatcherInterface
{
    public function dispatch(string $event, array $context = []): void
    {
        $subscriptionId = $this->string($context, 'subscription_id');
        $transactionId = $this->string($context, 'transaction_id');
        $eventId = $this->string($context, 'event_id') ?? hash('sha256', (string) json_encode($context));
        $amount = (float) ($context['amount'] ?? 0);
        $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $reason = $this->string($context, 'reason');

        match ($event) {
            'subscription.created' => Event::dispatch(new IyzicoSubscriptionCreated($subscriptionId, [], $metadata)),
            'subscription.cancelled' => Event::dispatch(new IyzicoSubscriptionCancelled($subscriptionId ?? '')),
            'subscription.order.success' => $this->dispatchOrderSuccess($eventId, $subscriptionId, $transactionId, $amount, $metadata),
            'subscription.order.failure' => $this->dispatchOrderFailure($eventId, $subscriptionId, $amount, $reason, $metadata),
            'payment.completed' => Event::dispatch(new IyzicoPaymentCompleted($transactionId, $amount, 'service', $metadata)),
            'payment.failed' => Event::dispatch(new IyzicoPaymentFailed($amount, 'service', $reason, $metadata)),
            default => null,
        };
    }

    private function dispatchOrderSuccess(string $eventId, ?string $subscriptionId, ?string $transactionId, float $amount, array $metadata): void
    {
        Event::dispatch(new IyzicoPaymentCompleted($transactionId, $amount, 'webhook', $metadata));
        Event::dispatch(new IyzicoSubscriptionOrderSucceeded($eventId, $subscriptionId, $transactionId, $amount, $metadata));
    }

    private function dispatchOrderFailure(string $eventId, ?string $subscriptionId, float $amount, ?string $reason, array $metadata): void
    {
        Event::dispatch(new IyzicoPaymentFailed($amount, 'webhook', $reason, $metadata));
        Event::dispatch(new IyzicoSubscriptionOrderFailed($eventId, $subscriptionId, $amount, $metadata));
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
