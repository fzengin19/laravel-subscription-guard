<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use Symfony\Component\HttpFoundation\Response;

it('processes paytr success webhook end-to-end and updates transaction plus license', function (): void {
    config(['subscription-guard.providers.drivers.paytr.mock' => true]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 E2E User',
        'email' => 'phase5-e2e@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 E2E Plan',
        'slug' => 'phase5-e2e-plan',
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase5.e2e.'.bin2hex(random_bytes(6)),
        'status' => 'past_due',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now()->subDay(),
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_paytr_sub_'.bin2hex(random_bytes(4)),
        'status' => 'past_due',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
        'next_billing_date' => now()->subHour(),
    ]);

    $payload = [
        'event_id' => 'phase5_paytr_evt_'.bin2hex(random_bytes(4)),
        'merchant_oid' => (string) $subscription->getAttribute('provider_subscription_id'),
        'status' => 'success',
        'total_amount' => '10000',
        'payment_id' => 'phase5_paytr_txn_'.bin2hex(random_bytes(4)),
    ];

    $response = postPhaseFiveWebhook('paytr', $payload);

    expect($response->getStatusCode())->toBe(200);
    expect(trim($response->getContent()))->toBe('OK');

    $renewal = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'renewal')
        ->latest('id')
        ->first();

    expect($renewal)->not->toBeNull();
    expect((string) $renewal?->getAttribute('status'))->toBe('processed');
    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('active');
    expect((string) $license->fresh()?->getAttribute('status'))->toBe('active');
});

it('processes iyzico cancellation webhook end-to-end and cancels subscription plus license', function (): void {
    config(['subscription-guard.providers.drivers.iyzico.mock' => true]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 E2E Cancel User',
        'email' => 'phase5-e2e-cancel@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 E2E Cancel Plan',
        'slug' => 'phase5-e2e-cancel-plan',
        'price' => 70,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase5.e2e.cancel.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'phase5_iyz_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 70,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]);

    $payload = [
        'id' => 'phase5_iyz_evt_'.bin2hex(random_bytes(4)),
        'event_type' => 'subscription.cancelled',
        'status' => 'success',
        'subscriptionReferenceCode' => (string) $subscription->getAttribute('provider_subscription_id'),
        'paymentId' => 'phase5_iyz_txn_'.bin2hex(random_bytes(4)),
        'paymentConversationId' => 'phase5_iyz_conv_'.bin2hex(random_bytes(4)),
    ];

    $response = postPhaseFiveWebhook('iyzico', $payload);

    expect($response->getStatusCode())->toBe(202);

    expect((string) $subscription->fresh()?->getAttribute('status'))->toBe('cancelled');
    expect($subscription->fresh()?->getAttribute('cancelled_at'))->not->toBeNull();
    expect((string) $license->fresh()?->getAttribute('status'))->toBe('cancelled');
});

it('treats duplicate paytr webhook event id as no-op for transaction duplication', function (): void {
    config(['subscription-guard.providers.drivers.paytr.mock' => true]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 E2E Duplicate User',
        'email' => 'phase5-e2e-dup@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 E2E Duplicate Plan',
        'slug' => 'phase5-e2e-duplicate-plan',
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase5.e2e.dup.'.bin2hex(random_bytes(6)),
        'status' => 'past_due',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now()->subDay(),
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_paytr_dup_sub_'.bin2hex(random_bytes(4)),
        'status' => 'past_due',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
        'next_billing_date' => now()->subHour(),
    ]);

    $eventId = 'phase5_paytr_dup_evt_'.bin2hex(random_bytes(4));

    $payload = [
        'event_id' => $eventId,
        'merchant_oid' => (string) $subscription->getAttribute('provider_subscription_id'),
        'status' => 'success',
        'total_amount' => '10000',
        'payment_id' => 'phase5_paytr_dup_txn_'.bin2hex(random_bytes(4)),
    ];

    $first = postPhaseFiveWebhook('paytr', $payload);
    $second = postPhaseFiveWebhook('paytr', $payload);

    expect($first->getStatusCode())->toBe(200);
    expect($second->getStatusCode())->toBe(200);

    $transactionCount = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'renewal')
        ->count();

    expect($transactionCount)->toBe(1);
});

function postPhaseFiveWebhook(string $provider, array $payload): Response
{
    $path = '/'.trim((string) config('subscription-guard.webhooks.prefix', 'subguard/webhooks'), '/').'/'.$provider;

    $request = Request::create(
        $path,
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode($payload, JSON_THROW_ON_ERROR)
    );

    return app(Kernel::class)->handle($request);
}
