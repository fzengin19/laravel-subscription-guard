<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\SeatManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Features\FeatureManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider;

it('registers phase four operational commands', function (): void {
    $commands = app(Illuminate\Contracts\Console\Kernel::class)->all();

    expect($commands)->toHaveKeys([
        'subguard:generate-license',
        'subguard:check-license',
        'subguard:sync-license-revocations',
        'subguard:sync-license-heartbeats',
        'subguard:process-metered-billing',
    ]);
});

it('syncs revocation snapshot from remote endpoint command', function (): void {
    $suffix = bin2hex(random_bytes(8));
    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:ops:'.$suffix);
    config()->set('subscription-guard.license.revocation.sync_endpoint', 'https://license.example.test/revocations');
    config()->set('subscription-guard.license.revocation.sync_timeout_seconds', 5);

    Http::fake([
        'license.example.test/*' => Http::response([
            'sequence' => 11,
            'mode' => 'full',
            'revoked' => ['lic_remote_001'],
            'ttl_seconds' => 1800,
        ], 200),
    ]);

    $exitCode = Artisan::call('subguard:sync-license-revocations');

    $store = app(LicenseRevocationStore::class);

    expect($exitCode)->toBe(0);
    expect($store->currentSequence())->toBe(11);
    expect($store->isRevoked('lic_remote_001'))->toBeTrue();
});

it('syncs heartbeat cache values from persisted licenses command', function (): void {
    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();
    [$publicKey, $privateKey] = makePhaseFourOperationalEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $suffix = bin2hex(random_bytes(8));
    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:heartbeat:'.$suffix);
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:test:heartbeat:'.$suffix.':');

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate((int) $plan->getKey(), $userId);
    $licenseId = phaseFourExtractLicenseId($licenseKey);

    expect($licenseId)->not->toBe('');

    $store = app(LicenseRevocationStore::class);
    $store->touchHeartbeat($licenseId, time() - 3600);
    $before = $store->heartbeatAt($licenseId);

    $exitCode = Artisan::call('subguard:sync-license-heartbeats');

    $after = $store->heartbeatAt($licenseId);

    expect($exitCode)->toBe(0);
    expect($before)->not->toBeNull();
    expect($after)->not->toBeNull();
    expect((int) $after)->toBeGreaterThan((int) $before);
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

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.operations.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'limit_overrides' => ['seats' => 1],
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]));

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
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
    ]));

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

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.metered.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]));

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
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
    ]));

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
    expect(LicenseUsage::query()->where('license_id', $license->getKey())->whereNull('billed_at')->count())->toBe(0);
    expect(LicenseUsage::query()->where('license_id', $license->getKey())->whereNotNull('billed_at')->count())->toBe(1);
});

it('processes metered billing through provider charge in self-managed flow', function (): void {
    config(['subscription-guard.providers.drivers.paytr.mock' => false]);

    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.metered.provider.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]));

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'meter_provider_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 20,
        'currency' => 'TRY',
        'metadata' => [
            'metered_price_per_unit' => 2.5,
            'card_token' => 'ct_metered_001',
        ],
        'next_billing_date' => now()->subHour(),
    ]));

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
    expect((string) $transaction?->getAttribute('status'))->toBe('processed');
    expect((string) ($transaction?->getAttribute('provider_transaction_id') ?? ''))->not->toBe('');
    expect((bool) (($transaction?->getAttribute('provider_response')['charge_success'] ?? false)))->toBeTrue();
    expect(LicenseUsage::query()->where('license_id', $license->getKey())->whereNull('billed_at')->count())->toBe(0);
    expect(LicenseUsage::query()->where('license_id', $license->getKey())->whereNotNull('billed_at')->count())->toBe(1);
});

it('keeps usage and marks transaction failed when metered provider charge fails', function (): void {
    config([
        'subscription-guard.providers.drivers.paytr.class' => TestMeteredFailureProvider::class,
        'subscription-guard.providers.drivers.paytr.manages_own_billing' => false,
    ]);

    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();

    $periodStart = now()->startOfMonth();
    $periodEnd = now()->endOfMonth();

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.metered.fail.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]));

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'meter_fail_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 20,
        'currency' => 'TRY',
        'metadata' => [
            'metered_price_per_unit' => 3,
            'card_token' => 'ct_metered_fail_001',
        ],
        'current_period_start' => $periodStart,
        'current_period_end' => $periodEnd,
        'next_billing_date' => now()->subHour(),
    ]));

    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 4,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'metadata' => [],
    ]);

    Artisan::call('subguard:process-metered-billing', [
        '--date' => now()->toDateTimeString(),
    ]);

    $transaction = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'metered_usage')
        ->first();

    $freshSubscription = $subscription->fresh();

    expect($transaction)->not->toBeNull();
    expect((string) $transaction?->getAttribute('status'))->toBe('failed');
    expect((string) ($transaction?->getAttribute('failure_reason') ?? ''))->toBe('metered_decline');
    expect((bool) (($transaction?->getAttribute('provider_response')['charge_success'] ?? true)))->toBeFalse();
    expect(LicenseUsage::query()->where('license_id', $license->getKey())->count())->toBe(1);
    expect($freshSubscription?->getAttribute('current_period_start')?->toIso8601String())->toBe($periodStart->toIso8601String());
    expect($freshSubscription?->getAttribute('next_billing_date')?->toIso8601String())->not->toBe('');
});

it('keeps metered billing idempotent on repeated command execution for same period', function (): void {
    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.phase4.metered.idempotent.'.bin2hex(random_bytes(6)),
        'status' => 'active',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now(),
    ]));

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'iyzico',
        'provider_subscription_id' => 'meter_idem_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 15,
        'currency' => 'TRY',
        'metadata' => ['metered_price_per_unit' => 1.5],
        'next_billing_date' => now()->subHour(),
    ]));

    LicenseUsage::query()->create([
        'license_id' => $license->getKey(),
        'metric' => 'api_calls',
        'quantity' => 10,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'metadata' => [],
    ]);

    $date = now()->toDateTimeString();

    Artisan::call('subguard:process-metered-billing', ['--date' => $date]);
    Artisan::call('subguard:process-metered-billing', ['--date' => $date]);

    $count = Transaction::query()
        ->where('subscription_id', $subscription->getKey())
        ->where('type', 'metered_usage')
        ->count();

    expect($count)->toBe(1);
});

it('returns scheduled availability for a feature through feature manager', function (): void {
    [$plan, $userId] = createPhaseFourOperationalPlanAndUser();

    $until = now()->addDay()->startOfMinute();

    $license = License::unguarded(static fn () => License::query()->create([
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
    ]));

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

function phaseFourExtractLicenseId(string $licenseKey): string
{
    $parts = explode('.', $licenseKey);

    if (count($parts) !== 3) {
        return '';
    }

    $payload = json_decode(phaseFourBase64UrlDecode($parts[1]), true);

    if (! is_array($payload)) {
        return '';
    }

    $licenseId = $payload['license_id'] ?? null;

    return is_scalar($licenseId) ? trim((string) $licenseId) : '';
}

function phaseFourBase64UrlDecode(string $value): string
{
    $padding = strlen($value) % 4;

    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return (string) base64_decode(strtr($value, '-_', '+/'), true);
}

final class TestMeteredFailureProvider extends PaytrProvider
{
    public function chargeRecurring(array $subscription, int|float|string $amount, ?string $idempotencyKey = null): PaymentResponse
    {
        return new PaymentResponse(
            success: false,
            transactionId: null,
            providerResponse: ['source' => 'phase4-metered-failure-test'],
            failureReason: 'metered_decline'
        );
    }
}
