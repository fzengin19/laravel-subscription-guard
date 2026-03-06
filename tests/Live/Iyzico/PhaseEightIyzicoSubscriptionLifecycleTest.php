<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\PaymentMethod;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxCleanupRegistry;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxFixtures;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

beforeEach(function (): void {
    IyzicoSandboxGate::skipUnlessConfigured($this);
});

it('creates a remote subscription and persists local payment method tokens', function (): void {
    $provider = app(IyzicoProvider::class);
    $subscriptionService = app(SubscriptionService::class);
    $cleanup = new IyzicoSandboxCleanupRegistry;
    $context = IyzicoSandboxRunContext::create('subscription-create');
    $userId = phaseEightCreateIyzicoLiveUser($context);
    $plan = phaseEightCreateIyzicoLivePlan($context, 'subscription', 129.0);

    Artisan::call('subguard:sync-plans', [
        '--provider' => 'iyzico',
        '--remote' => true,
    ]);

    $plan->refresh();

    $payload = IyzicoSandboxFixtures::subscriptionPayload('success_debit_tr', $context, $userId);
    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->createSubscription($plan->toArray(), $payload));

    if (is_string($response->subscriptionId) && $response->subscriptionId !== '') {
        $cleanup->register('remote-subscription-cancel', fn () => $provider->cancelSubscription($response->subscriptionId));
    }

    $cardPayload = is_array($response->providerResponse['card'] ?? null) ? $response->providerResponse['card'] : [];

    $paymentMethod = $subscriptionService->persistProviderPaymentMethod('iyzico', [
        'payable_type' => 'App\\Models\\User',
        'payable_id' => $userId,
        'payment_method' => array_merge($cardPayload, [
            'card_holder_name' => $payload['payment_card']['card_holder_name'],
            'is_default' => true,
        ]),
    ]);

    $subscription = Subscription::unguarded(static fn (): Subscription => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => $response->subscriptionId,
        'status' => strtolower((string) ($response->status ?? 'active')),
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 129.0,
        'currency' => 'TRY',
    ]));

    try {
        expect($response->success)->toBeTrue()
            ->and($response->subscriptionId)->not->toBeNull()
            ->and($paymentMethod)->toBeInstanceOf(PaymentMethod::class)
            ->and((string) $paymentMethod?->getAttribute('provider_card_token'))->not->toBe('')
            ->and((string) $subscription->getAttribute('provider_subscription_id'))->toBe((string) $response->subscriptionId);
    } finally {
        $cleanup->run();
    }
});

function phaseEightCreateIyzicoLiveUser(IyzicoSandboxRunContext $context): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => 'Phase Eight Live User',
        'email' => sprintf('%s@example.test', $context->scopedValue('user')),
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function phaseEightCreateIyzicoLivePlan(IyzicoSandboxRunContext $context, string $suffix, float $price): Plan
{
    return Plan::query()->create([
        'name' => sprintf('Phase Eight Live %s %s', ucfirst($suffix), $context->runId()),
        'slug' => $context->scopedValue($suffix),
        'price' => $price,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);
}
