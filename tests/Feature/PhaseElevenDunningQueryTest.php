<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

uses(RefreshDatabase::class);

it('does not dispatch dunning jobs for transactions that exhausted retries without a pending next_retry_at', function () {
    Queue::fake();

    $maxRetries = (int) config('subscription-guard.billing.max_dunning_retries', 3);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Exhausted User',
        'email' => 'exhausted@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Exhausted Plan',
        'slug' => 'exhausted-plan',
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
        'status' => 'past_due',
        'amount' => 100,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'paytr',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 100.00,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => 'TRY',
        'idempotency_key' => 'dunning-exhausted-1',
        'retry_count' => $maxRetries,
        'next_retry_at' => null,
    ]));

    /** @var SubscriptionService $service */
    $service = app(SubscriptionService::class);
    $count = $service->processDunning(now());

    expect($count)->toBe(0);
    Queue::assertNotPushed(ProcessDunningRetryJob::class);
});

it('still dispatches dunning jobs for retryable transactions with due next_retry_at', function () {
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Retryable User',
        'email' => 'retryable@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Retryable Plan',
        'slug' => 'retryable-plan',
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
        'status' => 'past_due',
        'amount' => 100,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'paytr',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 100.00,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => 'TRY',
        'idempotency_key' => 'dunning-retryable-1',
        'retry_count' => 1,
        'next_retry_at' => now()->subHour(),
    ]));

    /** @var SubscriptionService $service */
    $service = app(SubscriptionService::class);
    $count = $service->processDunning(now());

    expect($count)->toBe(1);
    Queue::assertPushed(ProcessDunningRetryJob::class);
});
