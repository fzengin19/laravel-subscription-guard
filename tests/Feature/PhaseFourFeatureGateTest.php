<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(8));

    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:'.$suffix);
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:test:heartbeat:'.$suffix.':');
});

it('allows configured feature from license feature overrides', function (): void {
    [$gate, $license] = createLicenseAndGate();

    $license->setAttribute('feature_overrides', ['analytics' => true]);
    $license->save();

    expect($gate->can((string) $license->getAttribute('key'), 'analytics'))->toBeTrue();
    expect($gate->can((string) $license->getAttribute('key'), 'billing-export'))->toBeFalse();
});

it('returns configured limit from license limit overrides', function (): void {
    [$gate, $license] = createLicenseAndGate();

    $license->setAttribute('limit_overrides', ['users' => 7]);
    $license->save();

    expect($gate->limit((string) $license->getAttribute('key'), 'users'))->toBe(7);
    expect($gate->limit((string) $license->getAttribute('key'), 'projects'))->toBe(0);
});

it('records usage and blocks increments beyond configured limit', function (): void {
    [$gate, $license] = createLicenseAndGate();

    $license->setAttribute('limit_overrides', ['api_calls' => 5]);
    $license->save();

    $key = (string) $license->getAttribute('key');

    expect($gate->incrementUsage($key, 'api_calls', 3))->toBeTrue();
    expect($gate->incrementUsage($key, 'api_calls', 2))->toBeTrue();
    expect($gate->incrementUsage($key, 'api_calls', 1))->toBeFalse();

    $total = (float) LicenseUsage::query()
        ->where('license_id', $license->getKey())
        ->where('metric', 'api_calls')
        ->sum('quantity');

    expect($total)->toBe(5.0);
});

it('rejects unsupported feature gate subjects', function (): void {
    $gate = app(FeatureGateInterface::class);

    expect($gate->can(['x'], 'analytics'))->toBeFalse();
    expect($gate->limit((object) ['x' => 1], 'users'))->toBe(0);
    expect($gate->incrementUsage(null, 'api_calls', 1))->toBeFalse();
});

function createLicenseAndGate(): array
{
    [$publicKey, $privateKey] = makePhaseFourFeatureEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase4 Feature User '.bin2hex(random_bytes(4)),
        'email' => 'phase4-feature-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Feature Plan '.bin2hex(random_bytes(3)),
        'slug' => 'phase4-feature-plan-'.bin2hex(random_bytes(4)),
        'price' => 99.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $manager = app(LicenseManagerInterface::class);
    $key = $manager->generate($plan->getKey(), $userId);

    $license = License::query()->where('key', $key)->first();

    if (! $license instanceof License) {
        throw new RuntimeException('License record was not created.');
    }

    return [app(FeatureGateInterface::class), $license];
}

function makePhaseFourFeatureEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}
