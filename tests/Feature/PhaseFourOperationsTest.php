<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\SeatManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Features\FeatureManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

it('registers phase four operational commands', function (): void {
    $commands = app(Illuminate\Contracts\Console\Kernel::class)->all();

    expect($commands)->toHaveKeys([
        'subguard:generate-license',
        'subguard:check-license',
        'subguard:process-metered-billing',
    ]);
});

it('generates and validates license via CLI commands', function (): void {
    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();
    [$publicKey, $privateKey] = makePhaseFourOperationalEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    Artisan::call('subguard:generate-license', [
        'plan_id' => (string) $plan->getKey(),
        'user_id' => (string) $userId,
    ]);

    $generatedOutput = Artisan::output();
    preg_match('/SG\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', $generatedOutput, $matches);

    expect($matches[0] ?? null)->not->toBeNull();

    Artisan::call('subguard:check-license', [
        'license_key' => $matches[0],
    ]);

    expect(Artisan::output())->toContain('valid: yes');
});

it('manages seats and synchronizes license limits', function (): void {
    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.operations.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'limit_overrides' => ['seats' => 1],
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'seat_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 50,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]);

    SubscriptionItem::query()->create([
        'subscription_id' => $subscription->getKey(),
        'plan_id' => $plan->getKey(),
        'quantity' => 1,
        'unit_price' => 50,
    ]);

    $seatManager = app(SeatManager::class);

    expect($seatManager->addSeat($subscription, 2))->toBeTrue();
    expect((int) $subscription->fresh()->items()->firstOrFail()->getAttribute('quantity'))->toBe(3);
    expect((int) ($license->fresh()->getAttribute('limit_overrides')['seats'] ?? 0))->toBe(3);

    expect($seatManager->removeSeat($subscription, 1))->toBeTrue();
    expect((int) $subscription->fresh()->items()->firstOrFail()->getAttribute('quantity'))->toBe(2);
    expect((int) ($license->fresh()->getAttribute('limit_overrides')['seats'] ?? 0))->toBe(2);
});

it('processes metered billing and resets period usage', function (): void {
    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.metered.'.bin2hex(random_bytes(6)),
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
        'provider_subscription_id' => 'meter_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 20,
        'currency' => 'TRY',
        'metadata' => ['metered_price_per_unit' => 2.5],
        'next_billing_date' => now()->subHour(),
    ]);

    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 8,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'metadata' => [],
    ]);

    Artisan::call('subguard:process-metered-billing', [
        '--date' => now()->toDateTimeString(),
    ]);

    $transaction = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'metered_usage')
        ->first();

    expect($transaction)->not->toBeNull();
    expect((float) $transaction->getAttribute('amount'))->toBe(20.0);
    expect(LicenseUsage::query()->where('license_id', $license->getKey())->count())->toBe(0);
});

it('returns scheduled availability for a feature through feature manager', function (): void {
    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();

    $until = now()->addDay()->startOfMinute();

    $license = License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.schedule.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'feature_overrides' => [
            'beta_dashboard' => [
                'enabled' => true,
                'until' => $until->toIso8601String(),
            ],
        ],
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]);

    $featureManager = app(FeatureManager::class);

    expect($featureManager->can((string) $license->getAttribute('key'), 'beta_dashboard'))->toBeTrue();
    expect($featureManager->availableUntil((string) $license->getAttribute('key'), 'beta_dashboard')?->toIso8601String())
        ->toBe($until->toIso8601String());
});

function createPhaseFourOperationalPlanAndUser(): array
{
    $suffix = bin2hex(random_bytes(5));

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase4 Ops User '.$suffix,
        'email' => 'phase4-ops-'.$suffix.'@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Ops Plan '.$suffix,
        'slug' => 'phase4-ops-plan-'.$suffix,
        'price' => 49.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    return [$plan, $userId];
}

function makePhaseFourOperationalEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}
