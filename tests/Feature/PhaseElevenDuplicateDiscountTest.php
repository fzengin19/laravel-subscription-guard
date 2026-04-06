<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

it('prevents applying the same coupon to the same subscription twice', function (): void {
    $service = app(SubscriptionService::class);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase11 Duplicate Discount User',
        'email' => 'phase11-duplicate-discount@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase11 Duplicate Discount Plan',
        'slug' => 'phase11-duplicate-discount-plan',
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
        'provider_subscription_id' => 'phase11_duplicate_discount_sub_1',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    Coupon::query()->create([
        'code' => 'PHASE11DUP',
        'name' => 'Phase11 Duplicate Discount Coupon',
        'type' => 'fixed',
        'value' => 20,
        'currency' => 'TRY',
        'max_uses' => 100,
        'max_uses_per_user' => 100,
        'current_uses' => 0,
        'is_active' => true,
    ]);

    $first = $service->applyDiscount($subscription->getKey(), 'PHASE11DUP');
    $second = $service->applyDiscount($subscription->getKey(), 'PHASE11DUP');

    expect($first->applied)->toBeTrue();
    expect($second->applied)->toBeFalse();
    expect((string) ($second->reason ?? ''))->toContain('already');
});
