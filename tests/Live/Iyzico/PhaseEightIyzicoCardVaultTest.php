<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxCleanupRegistry;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxFixtures;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

beforeEach(function (): void {
    IyzicoSandboxGate::skipUnlessConfigured($this);
});

it('lists and deletes stored cards after tokenization', function (): void {
    $provider = app(IyzicoProvider::class);
    $cleanup = new IyzicoSandboxCleanupRegistry;
    $context = IyzicoSandboxRunContext::create('card-vault');
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase Eight Vault User',
        'email' => sprintf('%s@example.test', $context->scopedValue('user')),
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $plan = Plan::query()->create([
        'name' => sprintf('Phase Eight Vault Plan %s', $context->runId()),
        'slug' => $context->scopedValue('vault-plan'),
        'price' => 89.0,
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

    $payload = IyzicoSandboxFixtures::subscriptionPayload('success_debit_tr', $context, $userId);
    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->createSubscription($plan->toArray(), $payload));

    if (is_string($response->subscriptionId) && $response->subscriptionId !== '') {
        $cleanup->register('remote-subscription-cancel', fn () => $provider->cancelSubscription($response->subscriptionId));
    }

    $cardPayload = is_array($response->providerResponse['card'] ?? null) ? $response->providerResponse['card'] : [];
    $customerToken = (string) ($cardPayload['provider_customer_token'] ?? '');
    $cardToken = (string) ($cardPayload['provider_card_token'] ?? '');

    if ($customerToken !== '' && $cardToken !== '') {
        $cleanup->register('remote-card-delete', fn () => $provider->deleteStoredCard($customerToken, $cardToken));
    }

    try {
        $storedCards = $provider->listStoredCards($customerToken);
        $deleted = $provider->deleteStoredCard($customerToken, $cardToken);

        expect($response->success)->toBeTrue()
            ->and($customerToken)->not->toBe('')
            ->and($cardToken)->not->toBe('')
            ->and($storedCards)->toBeArray()
            ->and($deleted)->toBeTrue();
    } finally {
        $cleanup->run();
    }
});
