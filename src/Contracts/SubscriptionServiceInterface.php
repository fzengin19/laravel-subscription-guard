<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Contracts;

use DateTimeInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\DiscountResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\PaymentMethod;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

interface SubscriptionServiceInterface
{
    public function create(int|string $subscribableId, int|string $planId, int|string $paymentMethodId): array;

    public function cancel(int|string $subscriptionId): bool;

    public function pause(int|string $subscriptionId): bool;

    public function resume(int|string $subscriptionId): bool;

    public function upgrade(int|string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): bool;

    public function downgrade(int|string $subscriptionId, int|string $newPlanId): bool;

    public function applyDiscount(int|string $subscriptionId, string $couponOrDiscountCode): DiscountResult;

    public function processRenewals(DateTimeInterface $date): int;

    public function processDunning(DateTimeInterface $date): int;

    public function processScheduledPlanChanges(DateTimeInterface $date): int;

    public function retryPastDuePayments(int|string $subscribableId): int;

    public function handleWebhookResult(WebhookResult $result, string $provider): void;

    public function handlePaymentResult(PaymentResponse $result, Subscription $subscription): void;

    public function persistProviderPaymentMethod(string $provider, array $details): ?PaymentMethod;
}
