<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(8));

    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:'.$suffix);
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:test:heartbeat:'.$suffix.':');
});

it('validates license and refreshes heartbeat via online endpoint', function (): void {
    $licenseKey = phaseFourValidationMakeLicense();
    $payload = phaseFourValidationPayload($licenseKey);
    $licenseId = (string) ($payload['license_id'] ?? '');
    expect($licenseId)->not->toBe('');

    $store = app(LicenseRevocationStore::class);
    $store->touchHeartbeat($licenseId, time() - 300);

    $response = phaseFourValidationPostJson('/subguard/licenses/validate', [
        'license_key' => $licenseKey,
    ]);

    $response->assertOk()->assertJson([
        'valid' => true,
    ]);

    $heartbeatAt = $store->heartbeatAt($licenseId);
    expect($heartbeatAt)->not->toBeNull();
    expect($heartbeatAt)->toBeGreaterThan(time() - 10);
});

it('returns 422 for invalid license key in online endpoint', function (): void {
    $response = phaseFourValidationPostJson('/subguard/licenses/validate', [
        'license_key' => 'invalid-license',
    ]);

    $response->assertStatus(422)->assertJson([
        'valid' => false,
    ]);
});

function phaseFourValidationMakeLicense(): string
{
    [$publicKey, $privateKey] = phaseFourValidationEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase4 Validation User '.bin2hex(random_bytes(4)),
        'email' => 'phase4-validation-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Validation Plan '.bin2hex(random_bytes(3)),
        'slug' => 'phase4-validation-plan-'.bin2hex(random_bytes(4)),
        'price' => 29.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $manager = app(LicenseManagerInterface::class);

    return $manager->generate($plan->getKey(), $userId);
}

function phaseFourValidationPayload(string $licenseKey): array
{
    $parts = explode('.', $licenseKey);

    if (count($parts) !== 3) {
        return [];
    }

    $json = sodium_base642bin($parts[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
    $payload = json_decode($json, true);

    return is_array($payload) ? $payload : [];
}

function phaseFourValidationEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}

function phaseFourValidationPostJson(string $uri, array $payload): TestResponse
{
    $request = Request::create(
        $uri,
        'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: (string) json_encode($payload, JSON_THROW_ON_ERROR)
    );

    $response = app()->handle($request);

    return TestResponse::fromBaseResponse($response);
}
