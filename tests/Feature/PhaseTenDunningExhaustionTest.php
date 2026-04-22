<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\DunningExhausted;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\DispatchBillingNotificationsJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\PaymentChargeJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

beforeEach(function (): void {
    config([
        'subscription-guard.providers.drivers.iyzico.mock' => true,
        'subscription-guard.providers.drivers.paytr.mock' => true,
        'subscription-guard.billing.max_dunning_retries' => 3,
    ]);
});

it('suspends subscription when retry_count reaches max retries', function (): void {
    Event::fake([DunningExhausted::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Exhaustion User',
        'email' => 'exhaustion@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Exhaustion Plan',
        'slug' => 'exhaustion-plan',
        'price' => 99,
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
        'amount' => 99,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
        'grace_ends_at' => now()->addDays(3),
    ]));

    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 99,
        'currency' => 'TRY',
        'retry_count' => 3,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'idempotency_key' => 'txn-exhaust-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    $subscription->refresh();
    expect((string) $subscription->getAttribute('status'))->toBe('suspended');
});

it('suspends associated license when dunning is exhausted', function (): void {
    Event::fake([DunningExhausted::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'License Suspend User',
        'email' => 'license-suspend@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'License Suspend Plan',
        'slug' => 'license-suspend-plan',
        'price' => 79,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'LIC-EXHAUST-001',
        'status' => 'active',
        'expires_at' => now()->addMonth(),
    ]));

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'status' => 'past_due',
        'amount' => 79,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
        'grace_ends_at' => now()->addDays(3),
    ]));

    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 79,
        'currency' => 'TRY',
        'retry_count' => 3,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'idempotency_key' => 'txn-exhaust-license-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    $license->refresh();
    expect((string) $license->getAttribute('status'))->toBe('suspended');

    $subscription->refresh();
    expect((string) $subscription->getAttribute('status'))->toBe('suspended');
});

it('dispatches DunningExhausted event with correct payload', function (): void {
    Event::fake([DunningExhausted::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Event Payload User',
        'email' => 'event-payload@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Event Payload Plan',
        'slug' => 'event-payload-plan',
        'price' => 59,
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
        'amount' => 59,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 59,
        'currency' => 'TRY',
        'retry_count' => 3,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'failure_reason' => 'insufficient_funds',
        'idempotency_key' => 'txn-exhaust-event-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    Event::assertDispatched(DunningExhausted::class, function (DunningExhausted $event) use ($subscription, $transaction): bool {
        return $event->provider === 'paytr'
            && (int) $event->subscriptionId === (int) $subscription->getKey()
            && (int) $event->transactionId === (int) $transaction->getKey()
            && $event->retryCount === 3;
    });
});

it('dispatches DispatchBillingNotificationsJob for dunning.exhausted', function (): void {
    Event::fake([DunningExhausted::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Notification User',
        'email' => 'notification-exhaust@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Notification Plan',
        'slug' => 'notification-exhaust-plan',
        'price' => 49,
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
        'amount' => 49,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 49,
        'currency' => 'TRY',
        'retry_count' => 3,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'idempotency_key' => 'txn-exhaust-notif-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    Queue::assertPushed(DispatchBillingNotificationsJob::class, function (DispatchBillingNotificationsJob $job): bool {
        return $job->event === 'dunning.exhausted';
    });
});

it('clears next_retry_at to null when dunning is exhausted', function (): void {
    Event::fake([DunningExhausted::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Clear Retry User',
        'email' => 'clear-retry@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Clear Retry Plan',
        'slug' => 'clear-retry-plan',
        'price' => 89,
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
        'amount' => 89,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 89,
        'currency' => 'TRY',
        'retry_count' => 3,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'idempotency_key' => 'txn-exhaust-clear-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    $transaction->refresh();
    expect($transaction->getAttribute('next_retry_at'))->toBeNull();
});

it('dispatches PaymentChargeJob when retry_count is below max retries', function (): void {
    Bus::fake([PaymentChargeJob::class]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Normal Retry User',
        'email' => 'normal-retry@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Normal Retry Plan',
        'slug' => 'normal-retry-plan',
        'price' => 69,
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
        'amount' => 69,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 69,
        'currency' => 'TRY',
        'retry_count' => 1,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'idempotency_key' => 'txn-normal-retry-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    Bus::assertDispatched(PaymentChargeJob::class, function (PaymentChargeJob $job) use ($transaction): bool {
        return (string) $job->transactionId === (string) $transaction->getKey();
    });

    // Subscription should NOT be suspended
    $subscription->refresh();
    expect((string) $subscription->getAttribute('status'))->toBe('past_due');

    // Transaction should be marked as retrying
    $transaction->refresh();
    expect((string) $transaction->getAttribute('status'))->toBe('retrying');
});

it('respects configurable max retries where higher limit allows continued retries', function (): void {
    config(['subscription-guard.billing.max_dunning_retries' => 5]);

    Bus::fake([PaymentChargeJob::class]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Custom Max User',
        'email' => 'custom-max@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Custom Max Plan',
        'slug' => 'custom-max-plan',
        'price' => 109,
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
        'amount' => 109,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    // retry_count=4 is below the new max of 5, so it should retry normally
    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 109,
        'currency' => 'TRY',
        'retry_count' => 4,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'idempotency_key' => 'txn-custom-max-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    Bus::assertDispatched(PaymentChargeJob::class);

    // Subscription should still be past_due, not suspended
    $subscription->refresh();
    expect((string) $subscription->getAttribute('status'))->toBe('past_due');
});

it('suspends when retry_count equals configurable max retries', function (): void {
    config(['subscription-guard.billing.max_dunning_retries' => 5]);

    Event::fake([DunningExhausted::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Custom Max Exhaust User',
        'email' => 'custom-max-exhaust@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Custom Max Exhaust Plan',
        'slug' => 'custom-max-exhaust-plan',
        'price' => 109,
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
        'amount' => 109,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    // retry_count=5 equals the max of 5, so it should exhaust
    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 109,
        'currency' => 'TRY',
        'retry_count' => 5,
        'next_retry_at' => now()->subHour(),
        'last_retry_at' => now()->subDay(),
        'idempotency_key' => 'txn-custom-max-exhaust-001',
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));

    $subscription->refresh();
    expect((string) $subscription->getAttribute('status'))->toBe('suspended');

    Event::assertDispatched(DunningExhausted::class);

    $transaction->refresh();
    expect($transaction->getAttribute('next_retry_at'))->toBeNull();
});
