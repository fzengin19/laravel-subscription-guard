<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\RefundResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\SubscriptionResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\PaymentChargeJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

it('resolves paytr provider with self-managed billing mode', function (): void {
    $provider = app(PaymentManager::class)->provider('paytr');

    expect($provider)->toBeInstanceOf(PaytrProvider::class);
    expect($provider->managesOwnBilling())->toBeFalse();
});

it('charges pending transaction through provider and records success', function (): void {
    config([
        'subscription-guard.providers.drivers.paytr.class' => TestSuccessChargeProvider::class,
        'subscription-guard.providers.drivers.paytr.manages_own_billing' => false,
    ]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase3 Preflight User',
        'email' => 'phase3-preflight-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase3 Preflight Plan',
        'slug' => 'phase3-preflight-plan',
        'price' => 199.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'paytr_sub_001',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 199.00,
        'currency' => 'TRY',
        'next_billing_date' => now(),
    ]);

    $transaction = Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'paytr',
        'type' => 'renewal',
        'status' => 'pending',
        'amount' => 199.00,
        'currency' => 'TRY',
        'idempotency_key' => 'phase3:charge:pending:001',
    ]);

    (new PaymentChargeJob($transaction->getKey()))->handle(
        app(PaymentManager::class),
        app(SubscriptionService::class)
    );

    expect((string) $transaction->fresh()?->getAttribute('status'))->toBe('processed');
    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('active');
});

final class TestSuccessChargeProvider extends PaytrProvider
{
    public function chargeRecurring(array $subscription, int|float|string $amount): PaymentResponse
    {
        return new PaymentResponse(
            success: true,
            transactionId: 'paytr_txn_success_001',
            providerResponse: ['source' => 'phase3-preflight-test']
        );
    }

    public function pay(int|float|string $amount, array $details): PaymentResponse
    {
        return new PaymentResponse(false, null, null, null, null, [], 'not-used');
    }

    public function refund(string $transactionId, int|float|string $amount): RefundResponse
    {
        return new RefundResponse(false, null, [], 'not-used');
    }

    public function createSubscription(array $plan, array $details): SubscriptionResponse
    {
        return new SubscriptionResponse(false, null, null, [], 'not-used');
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return false;
    }

    public function upgradeSubscription(string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): SubscriptionResponse
    {
        return new SubscriptionResponse(false, null, null, [], 'not-used');
    }

    public function validateWebhook(array $payload, string $signature): bool
    {
        return false;
    }

    public function processWebhook(array $payload): WebhookResult
    {
        return new WebhookResult(false);
    }
}
