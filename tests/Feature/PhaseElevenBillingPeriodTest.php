<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

beforeEach(function (): void {
    config([
        'subscription-guard.providers.drivers.iyzico.mock' => true,
        'subscription-guard.providers.drivers.paytr.mock' => true,
    ]);

    Event::fake();
    Queue::fake();
});

it('advances next_billing_date by 1 week for a weekly subscription', function (): void {
    $plan = Plan::query()->create([
        'name' => 'Weekly Plan',
        'slug' => 'weekly-plan-bp-test',
        'price' => 25,
        'currency' => 'TRY',
        'billing_period' => 'week',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $billingDate = Carbon::parse('2026-04-06');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 1,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'week',
        'billing_interval' => 1,
        'amount' => 25,
        'currency' => 'TRY',
        'next_billing_date' => $billingDate,
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'txn_bp_weekly_001',
        providerResponse: ['mock' => true],
    );

    $service = app(SubscriptionServiceInterface::class);
    $service->handlePaymentResult($paymentResult, $subscription);

    $subscription->refresh();

    $expected = $billingDate->copy()->addWeek();
    expect($subscription->getAttribute('next_billing_date')->toDateString())->toBe($expected->toDateString());
});

it('advances next_billing_date by 1 year for a yearly subscription', function (): void {
    $plan = Plan::query()->create([
        'name' => 'Yearly Plan',
        'slug' => 'yearly-plan-bp-test',
        'price' => 999,
        'currency' => 'TRY',
        'billing_period' => 'year',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $billingDate = Carbon::parse('2026-04-06');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 2,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'year',
        'billing_interval' => 1,
        'amount' => 999,
        'currency' => 'TRY',
        'next_billing_date' => $billingDate,
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'txn_bp_yearly_001',
        providerResponse: ['mock' => true],
    );

    $service = app(SubscriptionServiceInterface::class);
    $service->handlePaymentResult($paymentResult, $subscription);

    $subscription->refresh();

    $expected = $billingDate->copy()->addYear();
    expect($subscription->getAttribute('next_billing_date')->toDateString())->toBe($expected->toDateString());
});

it('advances next_billing_date by 2 weeks for a bi-weekly subscription', function (): void {
    $plan = Plan::query()->create([
        'name' => 'Bi-Weekly Plan',
        'slug' => 'biweekly-plan-bp-test',
        'price' => 45,
        'currency' => 'TRY',
        'billing_period' => 'week',
        'billing_interval' => 2,
        'is_active' => true,
    ]);

    $billingDate = Carbon::parse('2026-04-06');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 3,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'week',
        'billing_interval' => 2,
        'amount' => 45,
        'currency' => 'TRY',
        'next_billing_date' => $billingDate,
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'txn_bp_biweekly_001',
        providerResponse: ['mock' => true],
    );

    $service = app(SubscriptionServiceInterface::class);
    $service->handlePaymentResult($paymentResult, $subscription);

    $subscription->refresh();

    $expected = $billingDate->copy()->addWeeks(2);
    expect($subscription->getAttribute('next_billing_date')->toDateString())->toBe($expected->toDateString());
});

it('advances next_billing_date by 1 month for a monthly subscription', function (): void {
    $plan = Plan::query()->create([
        'name' => 'Monthly Plan',
        'slug' => 'monthly-plan-bp-test',
        'price' => 99,
        'currency' => 'TRY',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $billingDate = Carbon::parse('2026-04-06');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => 4,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'billing_period' => 'month',
        'billing_interval' => 1,
        'amount' => 99,
        'currency' => 'TRY',
        'next_billing_date' => $billingDate,
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'txn_bp_monthly_001',
        providerResponse: ['mock' => true],
    );

    $service = app(SubscriptionServiceInterface::class);
    $service->handlePaymentResult($paymentResult, $subscription);

    $subscription->refresh();

    $expected = $billingDate->copy()->addMonthNoOverflow();
    expect($subscription->getAttribute('next_billing_date')->toDateString())->toBe($expected->toDateString());
});
