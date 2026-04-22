<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\PaymentProviderInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\RefundResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\SubscriptionResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCancelled;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\ProviderException;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\DispatchBillingNotificationsJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\PaymentChargeJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

uses(RefreshDatabase::class);

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function makeUser(string $email = 'prod-readiness@example.test'): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => 'PR User',
        'email' => $email,
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makePlan(string $slug = 'prod-readiness-plan'): Plan
{
    return Plan::query()->create([
        'name' => 'PR Plan',
        'slug' => $slug,
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);
}

/**
 * @param  array<string,mixed>  $overrides
 */
function makeSubscription(int $userId, int $planId, array $overrides = []): Subscription
{
    return Subscription::unguarded(static fn () => Subscription::query()->create(array_merge([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $planId,
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_ref_'.bin2hex(random_bytes(4)),
        'status' => SubscriptionStatus::Active->value,
        'amount' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'next_billing_date' => now()->addMonth(),
    ], $overrides)));
}

// -----------------------------------------------------------------------------
// P0-01: Mock mode must fail closed in production
// -----------------------------------------------------------------------------

it('P0-01 iyzico pay throws ProviderException when mock mode enabled in production', function (): void {
    config(['subscription-guard.providers.drivers.iyzico.mock' => true]);
    app()->detectEnvironment(static fn (): string => 'production');

    $provider = app(IyzicoProvider::class);

    expect(fn () => $provider->pay(100, ['mode' => 'non_3ds']))
        ->toThrow(ProviderException::class);
});

it('P0-01 iyzico validateWebhook throws ProviderException when mock mode enabled in production', function (): void {
    config(['subscription-guard.providers.drivers.iyzico.mock' => true]);
    app()->detectEnvironment(static fn (): string => 'production');

    $provider = app(IyzicoProvider::class);

    expect(fn () => $provider->validateWebhook(['payload' => 'x'], 'sig'))
        ->toThrow(ProviderException::class);
});

it('P0-01 paytr pay throws ProviderException when mock mode enabled in production', function (): void {
    config(['subscription-guard.providers.drivers.paytr.mock' => true]);
    app()->detectEnvironment(static fn (): string => 'production');

    $provider = app(PaytrProvider::class);

    expect(fn () => $provider->pay(100, ['currency' => 'TRY', 'mode' => 'iframe']))
        ->toThrow(ProviderException::class);
});

it('P0-01 paytr validateWebhook throws ProviderException when mock mode enabled in production', function (): void {
    config(['subscription-guard.providers.drivers.paytr.mock' => true]);
    app()->detectEnvironment(static fn (): string => 'production');

    $provider = app(PaytrProvider::class);

    expect(fn () => $provider->validateWebhook(['x' => 'y'], 'sig'))
        ->toThrow(ProviderException::class);
});

it('P0-01 non-production mock mode continues to work', function (): void {
    config(['subscription-guard.providers.drivers.iyzico.mock' => true]);
    app()->detectEnvironment(static fn (): string => 'testing');

    $provider = app(IyzicoProvider::class);

    expect($provider->validateWebhook(['payload' => 'x'], 'anything'))->toBeTrue();
});

it('P0-01 default package config has both provider mock flags false', function (): void {
    $defaults = require __DIR__.'/../../config/subscription-guard.php';

    expect($defaults['providers']['drivers']['iyzico']['mock'])->toBeFalse();
    expect($defaults['providers']['drivers']['paytr']['mock'])->toBeFalse();
});

// -----------------------------------------------------------------------------
// P0-02: Provider-managed cancellation must call remote first
// -----------------------------------------------------------------------------

class FakeProviderManagedProvider implements PaymentProviderInterface
{
    public bool $cancelCalled = false;

    public bool $cancelReturn = true;

    public ?string $lastCancelledId = null;

    public function getName(): string
    {
        return 'iyzico';
    }

    public function managesOwnBilling(): bool
    {
        return true;
    }

    public function pay(int|float|string $amount, array $details): PaymentResponse
    {
        return new PaymentResponse(true);
    }

    public function refund(string $transactionId, int|float|string $amount): RefundResponse
    {
        return new RefundResponse(true);
    }

    public function createSubscription(array $plan, array $details): SubscriptionResponse
    {
        return new SubscriptionResponse(true, 'x', 'active', []);
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        $this->cancelCalled = true;
        $this->lastCancelledId = $subscriptionId;

        return $this->cancelReturn;
    }

    public function upgradeSubscription(string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): SubscriptionResponse
    {
        return new SubscriptionResponse(true, $subscriptionId, 'active', []);
    }

    public function chargeRecurring(array $subscription, int|float|string $amount, ?string $idempotencyKey = null): PaymentResponse
    {
        return new PaymentResponse(true);
    }

    public function validateWebhook(array $payload, string $signature): bool
    {
        return true;
    }

    public function processWebhook(array $payload): WebhookResult
    {
        return new WebhookResult(true);
    }
}

it('P0-02 provider-managed cancel calls provider.cancelSubscription before local finalization', function (): void {
    Event::fake([SubscriptionCancelled::class]);
    Bus::fake();

    $fake = new FakeProviderManagedProvider;
    app()->instance(IyzicoProvider::class, $fake);

    $userId = makeUser('pr-p02-ok@example.test');
    $plan = makePlan('pr-p02-ok');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_ref_ok',
    ]);

    $result = app(SubscriptionService::class)->cancel($sub->getKey());

    expect($result)->toBeTrue();
    expect($fake->cancelCalled)->toBeTrue();
    expect($fake->lastCancelledId)->toBe('iyz_ref_ok');

    $reloaded = Subscription::query()->find($sub->getKey());
    expect((string) $reloaded->getAttribute('status'))->toBe(SubscriptionStatus::Cancelled->value);
    expect($reloaded->getAttribute('cancelled_at'))->not->toBeNull();

    Event::assertDispatched(SubscriptionCancelled::class);
    Bus::assertDispatched(DispatchBillingNotificationsJob::class);
});

it('P0-02 remote cancel failure leaves local subscription untouched', function (): void {
    Event::fake([SubscriptionCancelled::class]);

    $fake = new FakeProviderManagedProvider;
    $fake->cancelReturn = false;
    app()->instance(IyzicoProvider::class, $fake);

    $userId = makeUser('pr-p02-fail@example.test');
    $plan = makePlan('pr-p02-fail');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_ref_fail',
    ]);

    $result = app(SubscriptionService::class)->cancel($sub->getKey());

    expect($result)->toBeFalse();
    expect($fake->cancelCalled)->toBeTrue();

    $reloaded = Subscription::query()->find($sub->getKey());
    expect((string) $reloaded->getAttribute('status'))->toBe(SubscriptionStatus::Active->value);
    expect($reloaded->getAttribute('cancelled_at'))->toBeNull();

    Event::assertNotDispatched(SubscriptionCancelled::class);
});

it('P0-02 self-managed cancel does not require provider call', function (): void {
    Event::fake([SubscriptionCancelled::class]);
    Bus::fake();

    $userId = makeUser('pr-p02-selfmanaged@example.test');
    $plan = makePlan('pr-p02-sm');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'paytr',
        'provider_subscription_id' => 'paytr_ref_1',
    ]);

    $result = app(SubscriptionService::class)->cancel($sub->getKey());

    expect($result)->toBeTrue();

    $reloaded = Subscription::query()->find($sub->getKey());
    expect((string) $reloaded->getAttribute('status'))->toBe(SubscriptionStatus::Cancelled->value);
});

it('P0-02 already cancelled subscription returns true idempotently', function (): void {
    $userId = makeUser('pr-p02-idem@example.test');
    $plan = makePlan('pr-p02-idem');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'iyzico',
        'status' => SubscriptionStatus::Cancelled->value,
        'cancelled_at' => now(),
    ]);

    $result = app(SubscriptionService::class)->cancel($sub->getKey());

    expect($result)->toBeTrue();
});

// -----------------------------------------------------------------------------
// P1-01: Provider-managed failures must not enter local dunning flow
// -----------------------------------------------------------------------------

it('P1-01 processDunning skips provider-managed transactions even when due', function (): void {
    Queue::fake();

    $userId = makeUser('pr-p11-iyz@example.test');
    $plan = makePlan('pr-p11-iyz');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'iyzico',
        'status' => SubscriptionStatus::PastDue->value,
    ]);

    Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => $sub->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'iyzico',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 100.00,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => 'TRY',
        'idempotency_key' => 'pr-legacy-iyz-dunning',
        'retry_count' => 1,
        'next_retry_at' => now()->subHour(),
    ]));

    $count = app(SubscriptionService::class)->processDunning(now());

    expect($count)->toBe(0);
    Queue::assertNotPushed(ProcessDunningRetryJob::class);
});

it('P1-01 legacy provider-managed dunning row is neutralized by ProcessDunningRetryJob', function (): void {
    Bus::fake([PaymentChargeJob::class]);

    $userId = makeUser('pr-p11-legacy@example.test');
    $plan = makePlan('pr-p11-legacy');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'iyzico',
        'status' => SubscriptionStatus::PastDue->value,
    ]);

    $transaction = Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => $sub->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'iyzico',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 100.00,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => 'TRY',
        'idempotency_key' => 'pr-legacy-iyz-guard',
        'retry_count' => 1,
        'next_retry_at' => now()->subHour(),
    ]));

    $job = new ProcessDunningRetryJob((int) $transaction->getKey());
    $job->handle(app(PaymentManager::class), app(SubscriptionService::class));

    $reloaded = Transaction::query()->find($transaction->getKey());
    expect($reloaded->getAttribute('next_retry_at'))->toBeNull();

    Bus::assertNotDispatched(PaymentChargeJob::class);
});

it('P1-01 self-managed transactions still enter dunning flow', function (): void {
    Queue::fake();

    $userId = makeUser('pr-p11-paytr@example.test');
    $plan = makePlan('pr-p11-paytr');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'paytr',
        'status' => SubscriptionStatus::PastDue->value,
    ]);

    Transaction::unguarded(fn () => Transaction::query()->create([
        'subscription_id' => $sub->getKey(),
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'provider' => 'paytr',
        'type' => 'renewal',
        'status' => 'failed',
        'amount' => 100.00,
        'tax_amount' => 0,
        'tax_rate' => 0,
        'currency' => 'TRY',
        'idempotency_key' => 'pr-p11-paytr-dunning',
        'retry_count' => 1,
        'next_retry_at' => now()->subHour(),
    ]));

    $count = app(SubscriptionService::class)->processDunning(now());

    expect($count)->toBe(1);
    Queue::assertPushed(ProcessDunningRetryJob::class);
});

// -----------------------------------------------------------------------------
// P1-02: Terminal state guard for late events
// -----------------------------------------------------------------------------

it('P1-02 cancelled subscription ignores late order.success webhook', function (): void {
    $userId = makeUser('pr-p12-late-success@example.test');
    $plan = makePlan('pr-p12-late-success');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_late_1',
        'status' => SubscriptionStatus::Cancelled->value,
        'cancelled_at' => now()->subDay(),
    ]);

    $result = new WebhookResult(
        processed: true,
        eventId: 'late-success-evt',
        eventType: 'subscription.order.success',
        subscriptionId: 'iyz_late_1',
        transactionId: 'txn-late-1',
        amount: 100.0,
        metadata: ['raw' => 'x'],
    );

    app(SubscriptionService::class)->handleWebhookResult($result, 'iyzico');

    $reloaded = Subscription::query()->find($sub->getKey());
    expect((string) $reloaded->getAttribute('status'))->toBe(SubscriptionStatus::Cancelled->value);
});

it('P1-02 cancelled subscription ignores late order.failure webhook', function (): void {
    $userId = makeUser('pr-p12-late-failure@example.test');
    $plan = makePlan('pr-p12-late-failure');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'iyzico',
        'provider_subscription_id' => 'iyz_late_2',
        'status' => SubscriptionStatus::Cancelled->value,
        'cancelled_at' => now()->subDay(),
    ]);

    $result = new WebhookResult(
        processed: true,
        eventId: 'late-failure-evt',
        eventType: 'subscription.order.failure',
        subscriptionId: 'iyz_late_2',
        transactionId: 'txn-late-2',
        amount: 100.0,
        metadata: ['raw' => 'y'],
    );

    app(SubscriptionService::class)->handleWebhookResult($result, 'iyzico');

    $reloaded = Subscription::query()->find($sub->getKey());
    expect((string) $reloaded->getAttribute('status'))->toBe(SubscriptionStatus::Cancelled->value);
});

it('P1-02 normal past_due to active recovery still works', function (): void {
    $userId = makeUser('pr-p12-recovery@example.test');
    $plan = makePlan('pr-p12-recovery');
    $sub = makeSubscription($userId, (int) $plan->getKey(), [
        'provider' => 'paytr',
        'provider_subscription_id' => 'paytr_recover_1',
        'status' => SubscriptionStatus::PastDue->value,
    ]);

    $result = new WebhookResult(
        processed: true,
        eventId: 'recovery-evt',
        eventType: 'subscription.order.success',
        subscriptionId: 'paytr_recover_1',
        transactionId: 'txn-recover-1',
        amount: 100.0,
        metadata: ['raw' => 'z'],
    );

    app(SubscriptionService::class)->handleWebhookResult($result, 'paytr');

    $reloaded = Subscription::query()->find($sub->getKey());
    expect((string) $reloaded->getAttribute('status'))->toBe(SubscriptionStatus::Active->value);
});
