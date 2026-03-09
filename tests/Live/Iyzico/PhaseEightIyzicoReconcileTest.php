<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxCleanupRegistry;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxFixtures;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

beforeEach(function (): void {
    IyzicoSandboxGate::skipUnlessConfigured($this);
});

it('reconciles a remote iyzico subscription into local status', function (): void {
    $provider = app(IyzicoProvider::class);
    $cleanup = new IyzicoSandboxCleanupRegistry;
    $context = IyzicoSandboxRunContext::create('reconcile');
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase Eight Reconcile User',
        'email' => sprintf('%s@example.test', $context->scopedValue('user')),
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $plan = Plan::query()->create([
        'name' => sprintf('Phase Eight Reconcile Plan %s', $context->runId()),
        'slug' => $context->scopedValue('reconcile-plan'),
        'price' => 119.0,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    Artisan::call('subguard:sync-plans', [
        '--provider' => 'iyzico',
        '--remote' => true,
    ]);

    $plan->refresh();

    $payload = IyzicoSandboxFixtures::subscriptionPayload('success_credit_tr', $context, $userId);
    $payload['subscription_initial_status'] = 'ACTIVE';
    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->createSubscription($plan->toArray(), $payload));

    if (is_string($response->subscriptionId) && $response->subscriptionId !== '') {
        $cleanup->register('remote-subscription-cancel', fn () => $provider->cancelSubscription($response->subscriptionId));
    }

    $subscription = Subscription::unguarded(static fn (): Subscription => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => $response->subscriptionId,
        'status' => 'pending',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 119.0,
        'currency' => 'TRY',
    ]));

    try {
        $exitCode = Artisan::call('subguard:reconcile-iyzico-subscriptions', [
            '--remote' => true,
        ]);

        $subscription->refresh();

        expect($exitCode)->toBe(0)
            ->and((string) $subscription->getAttribute('status'))->not->toBe('pending');
    } finally {
        $cleanup->run();
    }
});
