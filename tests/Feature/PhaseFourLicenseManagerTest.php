<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\ValidationResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseActivation;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(8));

    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:'.$suffix);
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:test:heartbeat:'.$suffix.':');
});

it('generates canonical signed license key and validates it', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.key_id', 'phase4-v1');
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate(11, 42);

    expect($licenseKey)->toStartWith('SG.');
    expect(substr_count($licenseKey, '.'))->toBe(2);

    $result = $manager->validate($licenseKey);

    expect($result->valid)->toBeTrue();
    expect($result->metadata)->toHaveKey('payload');
    expect($result->metadata['payload']['plan_id'] ?? null)->toBe(11);
    expect($result->metadata['payload']['owner_id'] ?? null)->toBe(42);
    expect($result->metadata['payload']['kid'] ?? null)->toBe('phase4-v1');
});

it('rejects tampered payload even when signature part is unchanged', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate(3, 9);

    [$prefix, $payloadSegment, $signatureSegment] = explode('.', $licenseKey);
    $payload = json_decode(base64UrlDecode($payloadSegment), true, flags: JSON_THROW_ON_ERROR);
    $payload['owner_id'] = 99;

    $tamperedPayloadSegment = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $tamperedKey = implode('.', [$prefix, $tamperedPayloadSegment, $signatureSegment]);

    $result = $manager->validate($tamperedKey);

    expect($result->valid)->toBeFalse();
});

it('returns invalid for malformed key format', function (): void {
    $manager = app(LicenseManagerInterface::class);

    $result = $manager->validate('not-a-license-key');

    expect($result)->toBeInstanceOf(ValidationResult::class);
    expect($result->valid)->toBeFalse();
});

it('marks expired generated key as invalid', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', -5);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate(1, 1);

    $result = $manager->validate($licenseKey);

    expect($result->valid)->toBeFalse();
    expect($result->reason)->toBe('License key expired.');
});

it('denies feature checks by default when feature list is not present', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate(7, 8);

    expect($manager->checkFeature($licenseKey, 'advanced-reports'))->toBeFalse();
});

it('returns zero for undefined limits by default', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate(7, 8);

    expect($manager->checkLimit($licenseKey, 'users'))->toBe(0);
});

it('rejects license when revocation snapshot contains the license id', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $manager = app(LicenseManagerInterface::class);
    $store = app(LicenseRevocationStore::class);

    $licenseKey = $manager->generate(13, 34);
    $licenseId = extractLicensePayload($licenseKey)['license_id'] ?? null;

    expect(is_string($licenseId))->toBeTrue();

    $store->applyFullSnapshot(1, [$licenseId], 3600);

    $result = $manager->validate($licenseKey);

    expect($result->valid)->toBeFalse();
    expect($result->reason)->toBe('License revoked.');
});

it('ignores out-of-order delta updates for revocation sequence', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $manager = app(LicenseManagerInterface::class);
    $store = app(LicenseRevocationStore::class);

    $licenseKey = $manager->generate(4, 5);
    $licenseId = (string) (extractLicensePayload($licenseKey)['license_id'] ?? '');

    $store->applyFullSnapshot(10, [], 3600);
    $store->applyDelta(9, [$licenseId], [], 3600);

    $result = $manager->validate($licenseKey);

    expect($result->valid)->toBeTrue();
});

it('rejects same-sequence full snapshot replay attempts', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $manager = app(LicenseManagerInterface::class);
    $store = app(LicenseRevocationStore::class);

    $licenseA = $manager->generate(40, 50);
    $licenseB = $manager->generate(41, 51);
    $licenseAId = (string) (extractLicensePayload($licenseA)['license_id'] ?? '');
    $licenseBId = (string) (extractLicensePayload($licenseB)['license_id'] ?? '');

    expect($store->applyFullSnapshot(8, [$licenseAId], 3600))->toBeTrue();
    expect($store->applyFullSnapshot(8, [$licenseBId], 3600))->toBeFalse();

    expect($manager->validate($licenseA)->valid)->toBeFalse();
    expect($manager->validate($licenseB)->valid)->toBeTrue();
});

it('rejects stale heartbeat in offline validation mode', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.offline.max_stale_seconds', 1);
    config()->set('subscription-guard.license.offline.clock_skew_seconds', 0);

    $manager = app(LicenseManagerInterface::class);
    $store = app(LicenseRevocationStore::class);

    $licenseKey = $manager->generate(7, 9);
    $licenseId = (string) (extractLicensePayload($licenseKey)['license_id'] ?? '');

    $store->touchHeartbeat($licenseId, time() - 10);

    $result = $manager->validate($licenseKey);

    expect($result->valid)->toBeFalse();
    expect($result->reason)->toBe('License heartbeat is stale.');
});

it('accepts license after heartbeat refresh inside stale window', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.offline.max_stale_seconds', 30);

    $manager = app(LicenseManagerInterface::class);
    $store = app(LicenseRevocationStore::class);

    $licenseKey = $manager->generate(7, 9);
    $licenseId = (string) (extractLicensePayload($licenseKey)['license_id'] ?? '');

    $store->touchHeartbeat($licenseId, time());

    $result = $manager->validate($licenseKey);

    expect($result->valid)->toBeTrue();
});

it('persists license record on generation when owner and plan exist', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $userId = (int) \Illuminate\Support\Facades\DB::table('users')->insertGetId([
        'name' => 'Phase4 License User',
        'email' => 'phase4-license-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 License Plan',
        'slug' => 'phase4-license-plan',
        'price' => 49.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate($plan->getKey(), $userId);

    $license = License::query()->where('key', $licenseKey)->first();

    expect($license)->not->toBeNull();
    expect((int) ($license?->getAttribute('user_id') ?? 0))->toBe($userId);
    expect((int) ($license?->getAttribute('plan_id') ?? 0))->toBe((int) $plan->getKey());
});

it('activates and deactivates license with domain and max activation enforcement', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $userId = (int) \Illuminate\Support\Facades\DB::table('users')->insertGetId([
        'name' => 'Phase4 Activation User',
        'email' => 'phase4-activation-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Activation Plan',
        'slug' => 'phase4-activation-plan',
        'price' => 59.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate($plan->getKey(), $userId);

    $license = License::query()->where('key', $licenseKey)->first();
    expect($license)->not->toBeNull();

    $license?->setAttribute('max_activations', 1);
    $license?->save();

    expect($manager->activate($licenseKey, 'example.test'))->toBeTrue();
    expect($manager->activate($licenseKey, 'example.test'))->toBeTrue();
    expect($manager->activate($licenseKey, 'another.test'))->toBeFalse();

    expect((int) (License::query()->where('key', $licenseKey)->first()?->getAttribute('current_activations') ?? 0))->toBe(1);
    expect(LicenseActivation::query()->where('license_id', $license?->getKey())->whereNull('deactivated_at')->count())->toBe(1);

    expect($manager->deactivate($licenseKey, 'example.test'))->toBeTrue();
    expect((int) (License::query()->where('key', $licenseKey)->first()?->getAttribute('current_activations') ?? 0))->toBe(0);
    expect(LicenseActivation::query()->where('license_id', $license?->getKey())->whereNull('deactivated_at')->count())->toBe(0);
});

it('reconciles drifted current activations on deactivate', function (): void {
    [$publicKey, $privateKey] = makeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);

    $userId = (int) \Illuminate\Support\Facades\DB::table('users')->insertGetId([
        'name' => 'Phase4 Drift User',
        'email' => 'phase4-drift-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Drift Plan',
        'slug' => 'phase4-drift-plan',
        'price' => 79.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $manager = app(LicenseManagerInterface::class);
    $licenseKey = $manager->generate($plan->getKey(), $userId);

    expect($manager->activate($licenseKey, 'drift.test'))->toBeTrue();

    $license = License::query()->where('key', $licenseKey)->first();

    expect($license)->not->toBeNull();

    $license?->setAttribute('current_activations', 99);
    $license?->save();

    expect($manager->deactivate($licenseKey, 'drift.test'))->toBeTrue();

    expect((int) (License::query()->where('key', $licenseKey)->first()?->getAttribute('current_activations') ?? 0))->toBe(0);
    expect(LicenseActivation::query()->where('license_id', $license?->getKey())->whereNull('deactivated_at')->count())->toBe(0);
});

function makeEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}

function base64UrlDecode(string $value): string
{
    $padding = strlen($value) % 4;

    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return (string) base64_decode(strtr($value, '-_', '+/'), true);
}

function extractLicensePayload(string $licenseKey): array
{
    $parts = explode('.', $licenseKey);

    if (count($parts) !== 3) {
        return [];
    }

    $payload = json_decode(base64UrlDecode($parts[1]), true);

    return is_array($payload) ? $payload : [];
}
