<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseSignature;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseActivation;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(8));

    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:'.$suffix);
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:test:heartbeat:'.$suffix.':');
});

// ---------------------------------------------------------------------------
// Helper: create Ed25519 key pair
// ---------------------------------------------------------------------------
function phaseTenMakeEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}

// ---------------------------------------------------------------------------
// Helper: bootstrap keys in config
// ---------------------------------------------------------------------------
function phaseTenSetupKeys(): array
{
    [$publicKey, $privateKey] = phaseTenMakeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    return [$publicKey, $privateKey];
}

// ---------------------------------------------------------------------------
// Helper: create a user + plan and generate a persisted license key
// ---------------------------------------------------------------------------
function phaseTenCreateLicenseWithRecord(string $emailSuffix = 'phase10'): array
{
    phaseTenSetupKeys();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase10 Test User',
        'email' => $emailSuffix.'-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase10 Test Plan',
        'slug' => 'phase10-test-plan-'.bin2hex(random_bytes(4)),
        'price' => 49.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate($plan->getKey(), $userId);

    $license = License::query()->where('key', $licenseKey)->first();

    return [$manager, $licenseKey, $license, $userId, $plan];
}

// ===========================================================================
// revoke() tests
// ===========================================================================

it('revokes an active license so the revocation store sees it as revoked', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('revoke-active');
    $store = app(LicenseRevocationStore::class);

    // Pre-condition: license is valid
    expect($manager->validate($licenseKey)->valid)->toBeTrue();

    // Revoke
    expect($manager->revoke($licenseKey, 'Policy violation'))->toBeTrue();

    // Post-condition: revocation store reports revoked
    $payload = json_decode(
        sodium_base642bin(explode('.', $licenseKey)[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, ''),
        true
    );
    expect($store->isRevoked($payload['license_id']))->toBeTrue();
});

it('fails validation after a license has been revoked', function (): void {
    [$manager, $licenseKey] = phaseTenCreateLicenseWithRecord('revoke-validate');

    $manager->revoke($licenseKey, 'Abuse');

    $result = $manager->validate($licenseKey);

    expect($result->valid)->toBeFalse();
    expect($result->reason)->toBe('License revoked.');
});

it('revoking an already-revoked license is idempotent', function (): void {
    [$manager, $licenseKey] = phaseTenCreateLicenseWithRecord('revoke-idempotent');

    expect($manager->revoke($licenseKey, 'First revoke'))->toBeTrue();

    // Second attempt should return false because validate() now fails (license revoked)
    expect($manager->revoke($licenseKey, 'Second revoke'))->toBeFalse();

    // License is still revoked
    $result = $manager->validate($licenseKey);
    expect($result->valid)->toBeFalse();
    expect($result->reason)->toBe('License revoked.');
});

// ===========================================================================
// activate() edge cases
// ===========================================================================

it('enforces max_activations limit and rejects activation beyond the limit', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('activate-max');

    // max_activations = 1 (the default). The first domain binds the license.
    $license->setAttribute('max_activations', 1);
    $license->save();

    // First activation succeeds and binds domain
    expect($manager->activate($licenseKey, 'only.test'))->toBeTrue();

    // Same domain returns true (idempotent), but a different domain is rejected
    // because the license is now domain-bound AND at max activations.
    expect($manager->activate($licenseKey, 'another.test'))->toBeFalse();

    // Only 1 active activation
    $activeCount = LicenseActivation::query()
        ->where('license_id', $license->getKey())
        ->whereNull('deactivated_at')
        ->count();
    expect($activeCount)->toBe(1);
});

it('rejects duplicate activation for same domain by returning true without creating a new record', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('activate-dup');

    expect($manager->activate($licenseKey, 'same.test'))->toBeTrue();
    expect($manager->activate($licenseKey, 'same.test'))->toBeTrue();

    // Should only have one activation record
    $activationCount = LicenseActivation::query()
        ->where('license_id', $license->getKey())
        ->whereNull('deactivated_at')
        ->count();
    expect($activationCount)->toBe(1);

    // current_activations should be 1
    $license->refresh();
    expect((int) $license->getAttribute('current_activations'))->toBe(1);
});

it('increments current_activations correctly when activating multiple domains', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('activate-inc');

    $license->setAttribute('max_activations', 5);
    $license->setAttribute('domain', null);
    $license->save();

    $manager->activate($licenseKey, 'one.test');
    $license->refresh();
    expect((int) $license->getAttribute('current_activations'))->toBe(1);

    // Activate on same domain again (should not increment)
    $manager->activate($licenseKey, 'one.test');
    $license->refresh();
    expect((int) $license->getAttribute('current_activations'))->toBe(1);
});

// ===========================================================================
// deactivate() edge cases
// ===========================================================================

it('deactivates an existing domain successfully', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('deactivate-ok');

    $manager->activate($licenseKey, 'active.test');
    $license->refresh();
    expect((int) $license->getAttribute('current_activations'))->toBe(1);

    expect($manager->deactivate($licenseKey, 'active.test'))->toBeTrue();

    $license->refresh();
    expect((int) $license->getAttribute('current_activations'))->toBe(0);

    $activeActivation = LicenseActivation::query()
        ->where('license_id', $license->getKey())
        ->whereNull('deactivated_at')
        ->first();
    expect($activeActivation)->toBeNull();
});

it('handles deactivation of non-existent domain gracefully', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('deactivate-nonexist');

    // Activate one domain
    $manager->activate($licenseKey, 'real.test');

    // Try deactivating a domain that was never activated
    expect($manager->deactivate($licenseKey, 'nonexistent.test'))->toBeFalse();

    // Original activation still intact
    $license->refresh();
    expect((int) $license->getAttribute('current_activations'))->toBe(1);
});

// ===========================================================================
// checkFeature() tests
// ===========================================================================

it('returns true for a feature present in the license payload features list', function (): void {
    phaseTenSetupKeys();

    $signature = app(LicenseSignature::class);
    $store = app(LicenseRevocationStore::class);

    $licenseId = (string) \Illuminate\Support\Str::uuid();
    $issuedAt = time();

    $licenseKey = $signature->sign([
        'v' => 1,
        'alg' => 'ed25519',
        'kid' => 'v1',
        'license_id' => $licenseId,
        'plan_id' => 1,
        'owner_id' => 1,
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
        'hb' => 3600,
        'features' => ['advanced-reports', 'api-access'],
    ]);

    $store->touchHeartbeat($licenseId, $issuedAt);

    $manager = app(LicenseManagerInterface::class);

    expect($manager->checkFeature($licenseKey, 'advanced-reports'))->toBeTrue();
    expect($manager->checkFeature($licenseKey, 'api-access'))->toBeTrue();
});

it('returns false for a feature not in the license payload features list', function (): void {
    phaseTenSetupKeys();

    $signature = app(LicenseSignature::class);
    $store = app(LicenseRevocationStore::class);

    $licenseId = (string) \Illuminate\Support\Str::uuid();
    $issuedAt = time();

    $licenseKey = $signature->sign([
        'v' => 1,
        'alg' => 'ed25519',
        'kid' => 'v1',
        'license_id' => $licenseId,
        'plan_id' => 1,
        'owner_id' => 1,
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
        'hb' => 3600,
        'features' => ['basic-reports'],
    ]);

    $store->touchHeartbeat($licenseId, $issuedAt);

    $manager = app(LicenseManagerInterface::class);

    expect($manager->checkFeature($licenseKey, 'advanced-reports'))->toBeFalse();
});

it('respects feature_overrides on license model via FeatureGate', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('feature-override');

    // checkFeature on LicenseManager returns false (no features in payload)
    expect($manager->checkFeature($licenseKey, 'premium-widget'))->toBeFalse();

    // Set feature_overrides on the DB model
    $license->setAttribute('feature_overrides', ['premium-widget' => true]);
    $license->save();

    // FeatureGate should respect the override
    $gate = app(FeatureGateInterface::class);
    expect($gate->can($licenseKey, 'premium-widget'))->toBeTrue();
});

// ===========================================================================
// checkLimit() tests
// ===========================================================================

it('returns the correct limit value from the license payload', function (): void {
    phaseTenSetupKeys();

    $signature = app(LicenseSignature::class);
    $store = app(LicenseRevocationStore::class);

    $licenseId = (string) \Illuminate\Support\Str::uuid();
    $issuedAt = time();

    $licenseKey = $signature->sign([
        'v' => 1,
        'alg' => 'ed25519',
        'kid' => 'v1',
        'license_id' => $licenseId,
        'plan_id' => 1,
        'owner_id' => 1,
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
        'hb' => 3600,
        'limits' => ['users' => 50, 'projects' => 10],
    ]);

    $store->touchHeartbeat($licenseId, $issuedAt);

    $manager = app(LicenseManagerInterface::class);

    expect($manager->checkLimit($licenseKey, 'users'))->toBe(50);
    expect($manager->checkLimit($licenseKey, 'projects'))->toBe(10);
});

it('returns zero for an undefined limit', function (): void {
    phaseTenSetupKeys();

    $signature = app(LicenseSignature::class);
    $store = app(LicenseRevocationStore::class);

    $licenseId = (string) \Illuminate\Support\Str::uuid();
    $issuedAt = time();

    $licenseKey = $signature->sign([
        'v' => 1,
        'alg' => 'ed25519',
        'kid' => 'v1',
        'license_id' => $licenseId,
        'plan_id' => 1,
        'owner_id' => 1,
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
        'hb' => 3600,
        'limits' => ['users' => 50],
    ]);

    $store->touchHeartbeat($licenseId, $issuedAt);

    $manager = app(LicenseManagerInterface::class);

    expect($manager->checkLimit($licenseKey, 'nonexistent-limit'))->toBe(0);
});

it('respects limit_overrides on license model via FeatureGate', function (): void {
    [$manager, $licenseKey, $license] = phaseTenCreateLicenseWithRecord('limit-override');

    // checkLimit on LicenseManager returns 0 (no limits in payload)
    expect($manager->checkLimit($licenseKey, 'seats'))->toBe(0);

    // Set limit_overrides on the DB model
    $license->setAttribute('limit_overrides', ['seats' => 100]);
    $license->save();

    // FeatureGate should respect the override
    $gate = app(FeatureGateInterface::class);
    expect($gate->limit($licenseKey, 'seats'))->toBe(100);
});
