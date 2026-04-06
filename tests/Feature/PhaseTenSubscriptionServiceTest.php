<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewalFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\DispatchBillingNotificationsJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

beforeEach(function (): void {
    config([
        'subscription-guard.providers.drivers.iyzico.mock' => true,
        'subscription-guard.providers.drivers.paytr.mock' => true,
        'subscription-guard.billing.max_dunning_retries' => 3,
    ]);
});

// --- create() ---

it('creates a pending subscription with correct attributes', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Create Test User',
        'email' => 'create-test@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Create Test Plan',
        'slug' => 'create-test-plan',
        'price' => 120,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->create($userId, $plan->getKey(), 1);

    expect($result)->toBeArray();
    expect($result['status'])->toBe('pending');
    expect((float) $result['amount'])->toBe(120.0);
    expect($result['currency'])->toBe('TRY');
    expect($result['billing_period'])->toBe('monthly');
    expect((int) $result['billing_interval'])->toBe(1);
    expect((int) $result['plan_id'])->toBe((int) $plan->getKey());
    expect((int) $result['subscribable_id'])->toBe($userId);
});

it('uses the default provider when creating a subscription', function (): void {
    config(['subscription-guard.providers.default' => 'iyzico']);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Default Provider User',
        'email' => 'default-provider@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Default Provider Plan',
        'slug' => 'default-provider-plan',
        'price' => 50,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->create($userId, $plan->getKey(), 1);

    expect($result['provider'])->toBe('iyzico');
});

it('throws on invalid plan when creating a subscription', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Invalid Plan User',
        'email' => 'invalid-plan@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(SubscriptionServiceInterface::class);
    $service->create($userId, 999999, 1);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

// --- cancel() ---

it('cancels an active subscription', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Cancel Test User',
        'email' => 'cancel-test@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Cancel Test Plan',
        'slug' => 'cancel-test-plan',
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
        'status' => 'active',
        'amount' => 80,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->cancel($subscription->getKey());

    expect($result)->toBeTrue();
    expect((string) $subscription->fresh()->getAttribute('status'))->toBe('cancelled');
    expect($subscription->fresh()->getAttribute('cancelled_at'))->not->toBeNull();
});

it('returns false when cancelling a non-existent subscription', function (): void {
    $service = app(SubscriptionServiceInterface::class);
    $result = $service->cancel(999999);

    expect($result)->toBeFalse();
});

// --- pause() ---

it('pauses an active subscription', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Pause Test User',
        'email' => 'pause-test@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Pause Test Plan',
        'slug' => 'pause-test-plan',
        'price' => 60,
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
        'status' => 'active',
        'amount' => 60,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->pause($subscription->getKey());

    expect($result)->toBeTrue();
    expect((string) $subscription->fresh()->getAttribute('status'))->toBe('paused');
});

// --- resume() ---

it('resumes a paused subscription', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Resume Test User',
        'email' => 'resume-test@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Resume Test Plan',
        'slug' => 'resume-test-plan',
        'price' => 75,
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
        'status' => 'paused',
        'amount' => 75,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->resume($subscription->getKey());

    expect($result)->toBeTrue();
    expect((string) $subscription->fresh()->getAttribute('status'))->toBe('active');
    expect($subscription->fresh()->getAttribute('resumes_at'))->toBeNull();
});

// --- upgrade() ---

it('creates a ScheduledPlanChange with now mode', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Upgrade Now User',
        'email' => 'upgrade-now@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fromPlan = Plan::query()->create([
        'name' => 'Basic Upgrade',
        'slug' => 'basic-upgrade',
        'price' => 50,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $toPlan = Plan::query()->create([
        'name' => 'Pro Upgrade',
        'slug' => 'pro-upgrade',
        'price' => 150,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $fromPlan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'amount' => 50,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->upgrade($subscription->getKey(), $toPlan->getKey(), 'now');

    expect($result)->toBeTrue();

    $change = ScheduledPlanChange::query()
        ->where('subscription_id', $subscription->getKey())
        ->first();

    expect($change)->not->toBeNull();
    expect((int) $change->getAttribute('from_plan_id'))->toBe((int) $fromPlan->getKey());
    expect((int) $change->getAttribute('to_plan_id'))->toBe((int) $toPlan->getKey());
    expect($change->getAttribute('change_type'))->toBe('upgrade');
    expect($change->getAttribute('status'))->toBe('pending');

    // The scheduled_at for 'now' mode should be very close to current time
    $scheduledAt = Carbon::parse($change->getAttribute('scheduled_at'));
    expect($scheduledAt->diffInMinutes(now()))->toBeLessThan(2);
});

it('creates a ScheduledPlanChange with next_period mode', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Upgrade Next User',
        'email' => 'upgrade-next@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fromPlan = Plan::query()->create([
        'name' => 'Basic Next',
        'slug' => 'basic-next',
        'price' => 50,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $toPlan = Plan::query()->create([
        'name' => 'Pro Next',
        'slug' => 'pro-next',
        'price' => 200,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $nextBilling = now()->addMonth();

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $fromPlan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'amount' => 50,
        'currency' => 'TRY',
        'next_billing_date' => $nextBilling,
    ]));

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->upgrade($subscription->getKey(), $toPlan->getKey(), 'next_period');

    expect($result)->toBeTrue();

    $change = ScheduledPlanChange::query()
        ->where('subscription_id', $subscription->getKey())
        ->first();

    expect($change)->not->toBeNull();
    expect($change->getAttribute('change_type'))->toBe('upgrade');

    // next_period should schedule at the next billing date
    $scheduledAt = Carbon::parse($change->getAttribute('scheduled_at'));
    expect($scheduledAt->format('Y-m-d'))->toBe($nextBilling->format('Y-m-d'));
});

it('returns false on invalid upgrade mode', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Invalid Mode User',
        'email' => 'invalid-mode@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Invalid Mode Plan',
        'slug' => 'invalid-mode-plan',
        'price' => 50,
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
        'status' => 'active',
        'amount' => 50,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->upgrade($subscription->getKey(), $plan->getKey(), 'invalid_mode');

    expect($result)->toBeFalse();
});

// --- downgrade() ---

it('delegates downgrade to upgrade with next_period mode', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Downgrade User',
        'email' => 'downgrade@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fromPlan = Plan::query()->create([
        'name' => 'Pro Downgrade',
        'slug' => 'pro-downgrade',
        'price' => 200,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $toPlan = Plan::query()->create([
        'name' => 'Basic Downgrade',
        'slug' => 'basic-downgrade',
        'price' => 50,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $nextBilling = now()->addMonth();

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $fromPlan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'amount' => 200,
        'currency' => 'TRY',
        'next_billing_date' => $nextBilling,
    ]));

    $service = app(SubscriptionServiceInterface::class);
    $result = $service->downgrade($subscription->getKey(), $toPlan->getKey());

    expect($result)->toBeTrue();

    $change = ScheduledPlanChange::query()
        ->where('subscription_id', $subscription->getKey())
        ->first();

    expect($change)->not->toBeNull();
    expect($change->getAttribute('change_type'))->toBe('upgrade');

    $scheduledAt = Carbon::parse($change->getAttribute('scheduled_at'));
    expect($scheduledAt->format('Y-m-d'))->toBe($nextBilling->format('Y-m-d'));
});

// --- handlePaymentResult() ---

it('activates subscription on successful payment and advances billing date', function (): void {
    Event::fake([PaymentCompleted::class, SubscriptionRenewed::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Payment Success User',
        'email' => 'payment-success@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Payment Success Plan',
        'slug' => 'payment-success-plan',
        'price' => 99,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $originalBillingDate = now()->subHour();

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'past_due',
        'amount' => 99,
        'currency' => 'TRY',
        'next_billing_date' => $originalBillingDate,
        'grace_ends_at' => now()->addDays(7),
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'txn_success_001',
        providerResponse: ['mock' => true],
    );

    $service = app(SubscriptionServiceInterface::class);
    $service->handlePaymentResult($paymentResult, $subscription);

    $subscription->refresh();

    expect((string) $subscription->getAttribute('status'))->toBe('active');
    expect($subscription->getAttribute('grace_ends_at'))->toBeNull();
    expect($subscription->getAttribute('next_billing_date')->gt($originalBillingDate))->toBeTrue();

    Event::assertDispatched(PaymentCompleted::class);
    Event::assertDispatched(SubscriptionRenewed::class);

    Queue::assertPushed(DispatchBillingNotificationsJob::class, function (DispatchBillingNotificationsJob $job): bool {
        return $job->event === 'payment.completed';
    });
});

it('sets subscription to past_due with grace period on failed payment', function (): void {
    Event::fake([PaymentFailed::class, SubscriptionRenewalFailed::class]);
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Payment Fail User',
        'email' => 'payment-fail@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Payment Fail Plan',
        'slug' => 'payment-fail-plan',
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
        'status' => 'active',
        'amount' => 99,
        'currency' => 'TRY',
        'next_billing_date' => now(),
    ]));

    $paymentResult = new PaymentResponse(
        success: false,
        transactionId: 'txn_fail_001',
        providerResponse: ['mock' => true],
        failureReason: 'insufficient_funds',
    );

    $service = app(SubscriptionServiceInterface::class);
    $service->handlePaymentResult($paymentResult, $subscription);

    $subscription->refresh();

    expect((string) $subscription->getAttribute('status'))->toBe('past_due');
    expect($subscription->getAttribute('grace_ends_at'))->not->toBeNull();

    Event::assertDispatched(PaymentFailed::class);
    Event::assertDispatched(SubscriptionRenewalFailed::class);

    Queue::assertPushed(DispatchBillingNotificationsJob::class, function (DispatchBillingNotificationsJob $job): bool {
        return $job->event === 'payment.failed';
    });
});

it('handles payment result idempotently with the same transaction id', function (): void {
    Event::fake();
    Queue::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Idempotent User',
        'email' => 'idempotent@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Idempotent Plan',
        'slug' => 'idempotent-plan',
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
        'next_billing_date' => now(),
    ]));

    $paymentResult = new PaymentResponse(
        success: true,
        transactionId: 'txn_idempotent_001',
        providerResponse: ['mock' => true],
    );

    $service = app(SubscriptionServiceInterface::class);
    $service->handlePaymentResult($paymentResult, $subscription);
    $service->handlePaymentResult($paymentResult, $subscription);

    $transactionCount = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'renewal')
        ->count();

    expect($transactionCount)->toBe(1);
});

// --- processRenewals() ---

it('dispatches jobs for due non-provider-managed subscriptions', function (): void {
    Bus::fake();

    $date = Carbon::parse('2026-04-06 10:00:00');

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Renewal Process User',
        'email' => 'renewal-process@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Renewal Process Plan',
        'slug' => 'renewal-process-plan',
        'price' => 99,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    // paytr: manages_own_billing=false, should be dispatched
    $eligible = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'status' => 'active',
        'next_billing_date' => $date->copy()->subMinute(),
        'amount' => 99,
        'currency' => 'TRY',
    ]));

    $processed = app(SubscriptionServiceInterface::class)->processRenewals($date);

    expect($processed)->toBe(1);
    Bus::assertDispatched(ProcessRenewalCandidateJob::class, 1);
    Bus::assertDispatched(ProcessRenewalCandidateJob::class, function (ProcessRenewalCandidateJob $job) use ($eligible): bool {
        return (string) $job->subscriptionId === (string) $eligible->getKey();
    });
});

it('skips provider-managed subscriptions during renewal processing', function (): void {
    Bus::fake();

    $date = Carbon::parse('2026-04-06 10:00:00');

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Skip Provider User',
        'email' => 'skip-provider@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Skip Provider Plan',
        'slug' => 'skip-provider-plan',
        'price' => 99,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    // iyzico: manages_own_billing=true, should be skipped
    Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'status' => 'active',
        'next_billing_date' => $date->copy()->subMinute(),
        'amount' => 99,
        'currency' => 'TRY',
    ]));

    $processed = app(SubscriptionServiceInterface::class)->processRenewals($date);

    expect($processed)->toBe(0);
    Bus::assertNotDispatched(ProcessRenewalCandidateJob::class);
});

// --- processDunning() ---

it('dispatches jobs for failed transactions ready for retry', function (): void {
    Bus::fake();

    $date = Carbon::parse('2026-04-06 12:00:00');

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Dunning Process User',
        'email' => 'dunning-process@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $eligible = Transaction::unguarded(static fn () => Transaction::query()->create([
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 49,
        'currency' => 'TRY',
        'retry_count' => 1,
        'next_retry_at' => $date->copy()->subMinute(),
        'idempotency_key' => 'txn-dunning-eligible-p10',
    ]));

    $processed = app(SubscriptionServiceInterface::class)->processDunning($date);

    expect($processed)->toBe(1);
    Bus::assertDispatched(ProcessDunningRetryJob::class, 1);
    Bus::assertDispatched(ProcessDunningRetryJob::class, function (ProcessDunningRetryJob $job) use ($eligible): bool {
        return (string) $job->transactionId === (string) $eligible->getKey();
    });
});

it('skips transactions that have reached max retries during dunning', function (): void {
    Bus::fake();

    $date = Carbon::parse('2026-04-06 12:00:00');

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Max Retry User',
        'email' => 'max-retry@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Transaction::unguarded(static fn () => Transaction::query()->create([
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'status' => 'failed',
        'provider' => 'paytr',
        'amount' => 49,
        'currency' => 'TRY',
        'retry_count' => 3,
        'next_retry_at' => $date->copy()->subMinute(),
        'idempotency_key' => 'txn-dunning-maxed-p10',
    ]));

    $processed = app(SubscriptionServiceInterface::class)->processDunning($date);

    // Exhausted transactions are now dispatched so the job can run the suspension pipeline
    expect($processed)->toBe(1);
    Bus::assertDispatched(ProcessDunningRetryJob::class);
});
