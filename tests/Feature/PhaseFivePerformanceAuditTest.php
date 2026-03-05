<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;
use Symfony\Component\HttpFoundation\Response;

it('handles paytr webhook batch with acceptable average latency', function (): void {
    config(['subscription-guard.providers.drivers.paytr.mock' => true]);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 Perf User',
        'email' => 'phase5-perf@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 Perf Plan',
        'slug' => 'phase5-perf-plan',
        'price' => 100,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase5.perf.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_perf_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 100,
        'currency' => 'TRY',
        'next_billing_date' => now()->subHour(),
    ]);

    $count = 25;
    $start = microtime(true);

    for ($i = 0; $i < $count; $i++) {
        $response = postPhaseFivePerformanceWebhook('paytr', [
            'event_id' => 'phase5_perf_evt_'.$i.'_'.bin2hex(random_bytes(3)),
            'merchant_oid' => (string) $subscription->getAttribute('provider_subscription_id'),
            'status' => 'success',
            'total_amount' => '10000',
            'payment_id' => 'phase5_perf_txn_'.$i.'_'.bin2hex(random_bytes(3)),
        ]);

        expect($response->getStatusCode())->toBe(200);
    }

    $elapsedSeconds = max(0.0001, microtime(true) - $start);
    $averageMs = ($elapsedSeconds * 1000) / $count;
    $throughputPerSecond = $count / $elapsedSeconds;

    expect($averageMs)->toBeLessThan(120.0);
    expect($throughputPerSecond)->toBeGreaterThan(8.0);

    $webhookCount = WebhookCall::query()
        ->where('provider', 'paytr')
        ->count();

    $transactionCount = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'renewal')
        ->count();

    expect($webhookCount)->toBe($count);
    expect($transactionCount)->toBeGreaterThan(0);
});

function postPhaseFivePerformanceWebhook(string $provider, array $payload): Response
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
