<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

it('rejects coupon when min purchase amount is not met', function (): void {
    $service = app(SubscriptionService::class);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 Coupon User 1',
        'email' => 'phase5-coupon-1@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 Coupon Plan 1',
        'slug' => 'phase5-coupon-plan-1',
        'price' => 40,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_coupon_sub_1',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 40,
        'currency' => 'TRY',
        'next_billing_date' => now()->subMinute(),
    ]));

    Coupon::query()->create([
        'code' => 'PHASE5MIN',
        'name' => 'Phase5 Min Coupon',
        'type' => 'fixed',
        'value' => 10,
        'currency' => 'TRY',
        'min_purchase_amount' => 50,
        'max_uses_per_user' => 2,
        'current_uses' => 0,
        'is_active' => true,
    ]);

    $result = $service->applyDiscount($subscription->getKey(), 'PHASE5MIN');

    expect($result->applied)->toBeFalse();
    expect((string) ($result->reason ?? ''))->toContain('minimum');
});

it('enforces coupon max uses and increments current uses on apply', function (): void {
    $service = app(SubscriptionService::class);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 Coupon User 2',
        'email' => 'phase5-coupon-2@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 Coupon Plan 2',
        'slug' => 'phase5-coupon-plan-2',
        'price' => 80,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_coupon_sub_2',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 80,
        'currency' => 'TRY',
        'next_billing_date' => now()->subMinute(),
    ]));

    $coupon = Coupon::query()->create([
        'code' => 'PHASE5MAX',
        'name' => 'Phase5 Max Coupon',
        'type' => 'percentage',
        'value' => 25,
        'currency' => 'TRY',
        'max_uses' => 1,
        'max_uses_per_user' => 1,
        'current_uses' => 0,
        'is_active' => true,
    ]);

    $first = $service->applyDiscount($subscription->getKey(), 'PHASE5MAX');
    $second = $service->applyDiscount($subscription->getKey(), 'PHASE5MAX');

    expect($first->applied)->toBeTrue();
    expect($second->applied)->toBeFalse();
    expect((int) $coupon->fresh()?->getAttribute('current_uses'))->toBe(1);
});

it('applies active discount to renewal transaction and links coupon/discount ids', function (): void {
    config(['subscription-guard.providers.drivers.paytr.manages_own_billing' => false]);

    $service = app(SubscriptionService::class);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 Coupon User 3',
        'email' => 'phase5-coupon-3@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 Coupon Plan 3',
        'slug' => 'phase5-coupon-plan-3',
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_coupon_sub_3',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
        'next_billing_date' => now()->subMinute(),
    ]));

    Coupon::query()->create([
        'code' => 'PHASE5TXN',
        'name' => 'Phase5 Transaction Coupon',
        'type' => 'fixed',
        'value' => 30,
        'currency' => 'TRY',
        'max_uses_per_user' => 3,
        'current_uses' => 0,
        'is_active' => true,
        'metadata' => [
            'duration' => 'once',
        ],
    ]);

    $result = $service->applyDiscount($subscription->getKey(), 'PHASE5TXN');

    expect($result->applied)->toBeTrue();

    (new \SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob((int) $subscription->getKey()))
        ->handle(
            app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class),
            app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class)
        );

    $transaction = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'renewal')
        ->where('idempotency_key', 'like', 'renewal:%')
        ->first();

    $discount = Discount::query()
        ->where('discountable_type', Subscription::class)
        ->where('discountable_id', $subscription->getKey())
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull();
    expect((float) ($transaction?->getAttribute('amount') ?? 0))->toBe(70.0);
    expect((float) ($transaction?->getAttribute('discount_amount') ?? 0))->toBe(30.0);
    expect((int) ($transaction?->getAttribute('discount_id') ?? 0))->toBe((int) ($discount?->getKey() ?? 0));
    expect((int) ($discount?->fresh()?->getAttribute('applied_cycles') ?? 0))->toBe(1);
});

it('supports repeating discount duration with cycle limit', function (): void {
    $service = app(SubscriptionService::class);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 Coupon User 4',
        'email' => 'phase5-coupon-4@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 Coupon Plan 4',
        'slug' => 'phase5-coupon-plan-4',
        'price' => 120,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_coupon_sub_4',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 120,
        'currency' => 'TRY',
        'next_billing_date' => now()->subMinute(),
    ]));

    Coupon::query()->create([
        'code' => 'PHASE5REP',
        'name' => 'Phase5 Repeating Coupon',
        'type' => 'fixed',
        'value' => 20,
        'currency' => 'TRY',
        'max_uses_per_user' => 5,
        'current_uses' => 0,
        'is_active' => true,
        'metadata' => [
            'duration' => 'repeating',
            'duration_in_months' => 2,
        ],
    ]);

    $applied = $service->applyDiscount($subscription->getKey(), 'PHASE5REP');

    expect($applied->applied)->toBeTrue();

    $discount = Discount::query()
        ->where('discountable_type', Subscription::class)
        ->where('discountable_id', $subscription->getKey())
        ->latest('id')
        ->first();

    expect($discount)->not->toBeNull();

    $firstQuote = $service->resolveRenewalDiscount($subscription, 120);
    $service->markDiscountApplied((int) ($discount?->getKey() ?? 0));

    $secondQuote = $service->resolveRenewalDiscount($subscription, 120);
    $service->markDiscountApplied((int) ($discount?->getKey() ?? 0));

    $thirdQuote = $service->resolveRenewalDiscount($subscription, 120);

    expect((float) $firstQuote['discount_amount'])->toBe(20.0);
    expect((float) $secondQuote['discount_amount'])->toBe(20.0);
    expect((float) $thirdQuote['discount_amount'])->toBe(0.0);
});
