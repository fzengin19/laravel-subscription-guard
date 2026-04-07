<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\MeteredBillingProcessor;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

beforeEach(function (): void {
    config()->set('subscription-guard.providers.drivers.dummy', [
        'class' => \SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider::class,
        'mock' => true,
        'manages_own_billing' => false,
        'webhook_response_format' => 'json',
    ]);
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
});

// ---------------------------------------------------------------------------
// Helper: create a user
// ---------------------------------------------------------------------------
function phaseTenConcurrencyCreateUser(string $email): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => 'Concurrency User',
        'email' => $email,
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// Helper: create a plan
// ---------------------------------------------------------------------------
function phaseTenConcurrencyCreatePlan(string $slug): Plan
{
    return Plan::query()->create([
        'name' => 'Concurrency Plan',
        'slug' => $slug,
        'price' => 99.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);
}

// ===========================================================================
// Webhook deduplication
// ===========================================================================

it('returns 202 for the first webhook and 200 for a duplicate with the same event_id', function (): void {
    $payload = [
        'event_id' => 'evt-dedup-'.bin2hex(random_bytes(8)),
        'event_type' => 'payment.completed',
        'data' => ['amount' => 100],
    ];

    Queue::fake();

    $firstResponse = $this->postJson(
        route('subguard.webhooks.handle', ['provider' => 'dummy']),
        $payload
    );

    $firstResponse->assertStatus(202);
    $firstResponse->assertJson(['status' => 'accepted', 'duplicate' => false]);

    $secondResponse = $this->postJson(
        route('subguard.webhooks.handle', ['provider' => 'dummy']),
        $payload
    );

    $secondResponse->assertStatus(200);
    $secondResponse->assertJson(['status' => 'accepted', 'duplicate' => true]);
});

it('prevents duplicate WebhookCall records via unique constraint on provider and event_id', function (): void {
    $eventId = 'evt-unique-'.bin2hex(random_bytes(8));

    WebhookCall::query()->create([
        'provider' => 'dummy',
        'event_type' => 'test.event',
        'event_id' => $eventId,
        'payload' => ['test' => true],
        'headers' => [],
        'status' => 'pending',
    ]);

    $thrown = false;

    try {
        WebhookCall::query()->create([
            'provider' => 'dummy',
            'event_type' => 'test.event',
            'event_id' => $eventId,
            'payload' => ['test' => true],
            'headers' => [],
            'status' => 'pending',
        ]);
    } catch (\Illuminate\Database\QueryException $e) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();
    expect(WebhookCall::query()->where('provider', 'dummy')->where('event_id', $eventId)->count())->toBe(1);
});

// ===========================================================================
// Renewal job idempotency
// ===========================================================================

it('skips renewal processing when the cache lock is already held', function (): void {
    $userId = phaseTenConcurrencyCreateUser('renewal-lock@example.test');
    $plan = phaseTenConcurrencyCreatePlan('renewal-lock-plan');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'dummy',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 99.00,
        'currency' => 'TRY',
        'next_billing_date' => now()->subDay(),
    ]));

    Queue::fake();

    // Acquire the lock before the job runs
    $lock = Cache::lock('subguard:renewal:'.$subscription->getKey(), 30);
    $lock->acquire();

    try {
        $job = new ProcessRenewalCandidateJob($subscription->getKey());
        $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class), app(\SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService::class));
    } finally {
        $lock->release();
    }

    // No transaction should have been created because the lock blocked the job
    $transactionCount = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->count();
    expect($transactionCount)->toBe(0);
});

it('prevents duplicate transactions via idempotency_key using firstOrCreate', function (): void {
    $idempotencyKey = 'renewal:999:'.now()->format('YmdHis');

    $first = Transaction::unguarded(static fn () => Transaction::query()->firstOrCreate(
        ['idempotency_key' => $idempotencyKey],
        [
            'subscription_id' => null,
            'payable_type' => 'App\\Models\\User',
            'payable_id' => 1,
            'provider' => 'dummy',
            'type' => 'renewal',
            'status' => 'pending',
            'amount' => 99.00,
            'currency' => 'TRY',
        ]
    ));

    expect($first->wasRecentlyCreated)->toBeTrue();

    $second = Transaction::unguarded(static fn () => Transaction::query()->firstOrCreate(
        ['idempotency_key' => $idempotencyKey],
        [
            'subscription_id' => null,
            'payable_type' => 'App\\Models\\User',
            'payable_id' => 1,
            'provider' => 'dummy',
            'type' => 'renewal',
            'status' => 'pending',
            'amount' => 199.00,
            'currency' => 'TRY',
        ]
    ));

    expect($second->wasRecentlyCreated)->toBeFalse();
    expect((int) $second->getKey())->toBe((int) $first->getKey());

    // Only one record exists
    expect(Transaction::query()->where('idempotency_key', $idempotencyKey)->count())->toBe(1);
});

// ===========================================================================
// Dunning job lock
// ===========================================================================

it('skips dunning retry processing when the cache lock is already held', function (): void {
    $userId = phaseTenConcurrencyCreateUser('dunning-lock@example.test');
    $plan = phaseTenConcurrencyCreatePlan('dunning-lock-plan');

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'dummy',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 99.00,
        'currency' => 'TRY',
    ]));

    $transaction = Transaction::unguarded(static fn () => Transaction::query()->create([
        'idempotency_key' => 'dunning-lock-'.bin2hex(random_bytes(8)),
        'subscription_id' => $subscription->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'dummy',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 99.00,
        'currency' => 'TRY',
        'retry_count' => 0,
    ]));

    Queue::fake();

    // Acquire the lock before the job runs
    $lock = Cache::lock('subguard:dunning:'.$transaction->getKey(), 30);
    $lock->acquire();

    try {
        $job = new ProcessDunningRetryJob($transaction->getKey());
        $job->handle(app(\SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager::class));
    } finally {
        $lock->release();
    }

    // Transaction status should remain 'failed' (not changed to 'retrying')
    $transaction->refresh();
    expect((string) $transaction->getAttribute('status'))->toBe('failed');
});

// ===========================================================================
// Metered billing billed_at protection
// ===========================================================================

it('sets billed_at on usage records after metered billing processes, preventing double billing', function (): void {
    $userId = phaseTenConcurrencyCreateUser('metered-billed@example.test');
    $plan = phaseTenConcurrencyCreatePlan('metered-billed-plan');

    // Create license via unguarded
    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.metered-test-key-'.bin2hex(random_bytes(8)),
        'status' => 'active',
    ]));

    $periodStart = now()->subMonth()->startOfMonth();
    $periodEnd = now()->subDay();

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'license_id' => $license->getKey(),
        'plan_id' => $plan->getKey(),
        'provider' => 'dummy',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 0.00,
        'currency' => 'TRY',
        'next_billing_date' => now()->subHour(),
        'current_period_start' => $periodStart,
        'current_period_end' => $periodEnd,
        'metadata' => ['metered_price_per_unit' => 1.50],
    ]));

    // Create unbilled usage
    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 100,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'billed_at' => null,
    ]);

    // Configure dummy provider to manage own billing (skip actual charge)
    config()->set('subscription-guard.providers.drivers.dummy.manages_own_billing', true);

    $processor = app(MeteredBillingProcessor::class);

    // First run: processes the usage
    $firstCount = $processor->process(now());
    expect($firstCount)->toBe(1);

    // Verify billed_at is set on usage records
    $billedUsage = LicenseUsage::query()
        ->where('license_id', $license->getKey())
        ->whereNotNull('billed_at')
        ->count();
    expect($billedUsage)->toBe(1);

    // Second run: no unbilled usage left, should process zero
    // Reset subscription dates so it would be eligible again
    $subscription->refresh();
    $subscription->setAttribute('next_billing_date', now()->subHour());
    $subscription->save();

    $secondCount = $processor->process(now());
    expect($secondCount)->toBe(0);

    // Still only one transaction
    $transactionCount = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'metered_usage')
        ->count();
    expect($transactionCount)->toBe(1);
});
