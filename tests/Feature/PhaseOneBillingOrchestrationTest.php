<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessScheduledPlanChangeJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

it('registers phase one scheduler commands', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKeys([
        'subguard:install',
        'subguard:process-renewals',
        'subguard:process-dunning',
        'subguard:suspend-overdue',
        'subguard:process-plan-changes',
    ]);
});

it('dispatches renewal candidates only for due self-managed subscriptions', function (): void {
    Bus::fake();

    $date = Carbon::parse('2026-03-04 10:00:00');
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Renewal User',
        'email' => 'renewal@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $plan = Plan::query()->create([
        'name' => 'Starter',
        'slug' => 'starter-renewal',
        'price' => 99,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
    ]);

    $eligible = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'next_billing_date' => $date->copy()->subMinute(),
        'amount' => 99,
        'currency' => 'TRY',
    ]);

    Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'status' => 'active',
        'next_billing_date' => $date->copy()->subMinute(),
        'amount' => 99,
        'currency' => 'TRY',
    ]);

    Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'paused',
        'next_billing_date' => $date->copy()->subMinute(),
        'amount' => 99,
        'currency' => 'TRY',
    ]);

    $processed = app(SubscriptionServiceInterface::class)->processRenewals($date);

    expect($processed)->toBe(1);
    Bus::assertDispatched(ProcessRenewalCandidateJob::class, 1);
    Bus::assertDispatched(ProcessRenewalCandidateJob::class, function (ProcessRenewalCandidateJob $job) use ($eligible): bool {
        return (string) $job->subscriptionId === (string) $eligible->getKey();
    });
});

it('dispatches dunning retry jobs for due failed transactions', function (): void {
    Bus::fake();

    $date = Carbon::parse('2026-03-04 12:00:00');
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Dunning User',
        'email' => 'dunning@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transaction = Transaction::query()->create([
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 49,
        'currency' => 'TRY',
        'retry_count' => 1,
        'next_retry_at' => $date->copy()->subMinute(),
        'idempotency_key' => 'txn-dunning-1',
    ]);

    Transaction::query()->create([
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 49,
        'currency' => 'TRY',
        'retry_count' => 3,
        'next_retry_at' => $date->copy()->subMinute(),
        'idempotency_key' => 'txn-dunning-2',
    ]);

    $processed = app(SubscriptionServiceInterface::class)->processDunning($date);

    expect($processed)->toBe(1);
    Bus::assertDispatched(ProcessDunningRetryJob::class, 1);
    Bus::assertDispatched(ProcessDunningRetryJob::class, function (ProcessDunningRetryJob $job) use ($transaction): bool {
        return (string) $job->transactionId === (string) $transaction->getKey();
    });
});

it('dispatches scheduled plan change jobs for due pending changes', function (): void {
    Bus::fake();

    $date = Carbon::parse('2026-03-04 15:00:00');
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Plan Change User',
        'email' => 'plan-change@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $fromPlan = Plan::query()->create([
        'name' => 'Basic',
        'slug' => 'basic-plan-change',
        'price' => 99,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
    ]);
    $toPlan = Plan::query()->create([
        'name' => 'Pro',
        'slug' => 'pro-plan-change',
        'price' => 129,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
    ]);
    $enterprisePlan = Plan::query()->create([
        'name' => 'Enterprise',
        'slug' => 'enterprise-plan-change',
        'price' => 199,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $fromPlan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'next_billing_date' => $date->copy()->addDay(),
        'amount' => 129,
        'currency' => 'TRY',
    ]);

    $planChange = ScheduledPlanChange::query()->create([
        'subscription_id' => $subscription->getKey(),
        'from_plan_id' => $fromPlan->getKey(),
        'to_plan_id' => $toPlan->getKey(),
        'change_type' => 'upgrade',
        'status' => 'pending',
        'scheduled_at' => $date->copy()->subMinute(),
    ]);

    ScheduledPlanChange::query()->create([
        'subscription_id' => $subscription->getKey(),
        'from_plan_id' => $fromPlan->getKey(),
        'to_plan_id' => $enterprisePlan->getKey(),
        'change_type' => 'upgrade',
        'status' => 'processed',
        'scheduled_at' => $date->copy()->subMinute(),
    ]);

    $processed = app(SubscriptionServiceInterface::class)->processScheduledPlanChanges($date);

    expect($processed)->toBe(1);
    Bus::assertDispatched(ProcessScheduledPlanChangeJob::class, 1);
    Bus::assertDispatched(ProcessScheduledPlanChangeJob::class, function (ProcessScheduledPlanChangeJob $job) use ($planChange): bool {
        return (string) $job->scheduledPlanChangeId === (string) $planChange->getKey();
    });
});

it('suspends overdue subscriptions and linked license with command', function (): void {
    $date = Carbon::parse('2026-03-04 17:00:00');
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Suspend User',
        'email' => 'suspend@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $plan = Plan::query()->create([
        'name' => 'Suspend Plan',
        'slug' => 'suspend-plan',
        'price' => 79,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
    ]);

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'LIC-SUSPEND-001',
        'status' => 'active',
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'status' => 'past_due',
        'grace_ends_at' => $date->copy()->subMinute(),
        'amount' => 79,
        'currency' => 'TRY',
    ]);

    $exitCode = Artisan::call('subguard:suspend-overdue', ['--date' => $date->toDateTimeString()]);

    expect($exitCode)->toBe(0);

    expect($subscription->fresh()->status)->toBe('suspended');
    expect($license->fresh()->status)->toBe('suspended');
});
