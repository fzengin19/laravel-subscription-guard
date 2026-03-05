<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\PaymentProviderInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\RefundResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\SubscriptionResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;

class PaytrProvider implements PaymentProviderInterface
{
    public function getName(): string
    {
        return 'paytr';
    }

    public function pay(int|float|string $amount, array $details): PaymentResponse
    {
        if ($this->mockMode()) {
            return new PaymentResponse(
                success: true,
                transactionId: 'paytr_mock_'.uniqid(),
                providerResponse: ['provider' => 'paytr', 'mock' => true, 'mode' => 'iframe']
            );
        }

        return new PaymentResponse(false, null, null, null, null, $details, 'PayTR live payment flow is not configured yet.');
    }

    public function refund(string $transactionId, int|float|string $amount): RefundResponse
    {
        if ($this->mockMode()) {
            return new RefundResponse(true, 'paytr_refund_'.uniqid(), ['provider' => 'paytr', 'mock' => true]);
        }

        return new RefundResponse(false, null, [], 'PayTR live refund flow is not configured yet.');
    }

    public function createSubscription(array $plan, array $details): SubscriptionResponse
    {
        if ($this->mockMode()) {
            return new SubscriptionResponse(
                success: true,
                subscriptionId: 'paytr_sub_'.uniqid(),
                status: 'active',
                providerResponse: ['provider' => 'paytr', 'mock' => true]
            );
        }

        return new SubscriptionResponse(false, null, null, $details, 'PayTR live subscription create flow is not configured yet.');
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return $subscriptionId !== '';
    }

    public function upgradeSubscription(string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): SubscriptionResponse
    {
        if ($subscriptionId === '' || (string) $newPlanId === '') {
            return new SubscriptionResponse(false, null, null, ['mode' => $mode], 'Invalid upgrade request.');
        }

        return new SubscriptionResponse(true, $subscriptionId, 'active', ['mode' => $mode, 'new_plan_id' => $newPlanId]);
    }

    public function chargeRecurring(array $subscription, int|float|string $amount): PaymentResponse
    {
        if ($this->mockMode()) {
            return new PaymentResponse(
                success: true,
                transactionId: 'paytr_recurring_'.uniqid(),
                providerResponse: [
                    'provider' => 'paytr',
                    'mock' => true,
                    'subscription' => $subscription,
                    'amount' => (float) $amount,
                ]
            );
        }

        return new PaymentResponse(false, null, null, null, null, $subscription, 'PayTR live recurring charge flow is not configured yet.');
    }

    public function validateWebhook(array $payload, string $signature): bool
    {
        if ($this->mockMode()) {
            return true;
        }

        return trim($signature) !== '';
    }

    public function processWebhook(array $payload): WebhookResult
    {
        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;
        $eventType = isset($payload['event_type']) ? (string) $payload['event_type'] : null;
        $subscriptionId = isset($payload['subscription_id']) ? (string) $payload['subscription_id'] : null;

        return new WebhookResult(
            processed: true,
            eventId: $eventId,
            eventType: $eventType,
            message: 'PayTR webhook parsed.',
            subscriptionId: $subscriptionId,
            transactionId: isset($payload['transaction_id']) ? (string) $payload['transaction_id'] : null,
            amount: isset($payload['amount']) ? (float) $payload['amount'] : null,
            status: isset($payload['status']) ? (string) $payload['status'] : null,
            metadata: $payload,
        );
    }

    public function managesOwnBilling(): bool
    {
        return false;
    }

    private function mockMode(): bool
    {
        return (bool) config('subscription-guard.providers.drivers.paytr.mock', true);
    }
}
