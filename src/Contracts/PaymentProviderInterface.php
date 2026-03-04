<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Contracts;

use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\RefundResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\SubscriptionResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;

interface PaymentProviderInterface
{
    public function getName(): string;

    public function managesOwnBilling(): bool;

    public function pay(int|float|string $amount, array $details): PaymentResponse;

    public function refund(string $transactionId, int|float|string $amount): RefundResponse;

    public function createSubscription(array $plan, array $details): SubscriptionResponse;

    public function cancelSubscription(string $subscriptionId): bool;

    public function upgradeSubscription(string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): SubscriptionResponse;

    public function chargeRecurring(array $subscription, int|float|string $amount): PaymentResponse;

    public function validateWebhook(array $payload, string $signature): bool;

    public function processWebhook(array $payload): WebhookResult;
}
