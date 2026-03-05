<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(8));

    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:'.$suffix);
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:test:heartbeat:'.$suffix.':');
});

it('renders subguardFeature directive based on license feature access', function (): void {
    $license = phaseFourBladeCreateLicense();
    $license->setAttribute('feature_overrides', ['analytics' => true]);
    $license->save();

    $key = (string) $license->getAttribute('key');
    $html = Blade::render(<<<'BLADE'
@subguardfeature($key, 'analytics')
ALLOWED
@else
NOT
@endif
BLADE, ['key' => $key]);

    expect($html)->toContain('ALLOWED');
    expect($html)->not->toContain('NOT');
});

it('renders subguardLimit directive using configured limit', function (): void {
    $license = phaseFourBladeCreateLicense();
    $license->setAttribute('limit_overrides', ['users' => 5]);
    $license->save();

    $key = (string) $license->getAttribute('key');
    $allowed = Blade::render(<<<'BLADE'
@subguardlimit($key, 'users', 4)
YES
@else
NO
@endif
BLADE, ['key' => $key]);

    $denied = Blade::render(<<<'BLADE'
@subguardlimit($key, 'users', 6)
YES
@else
NO
@endif
BLADE, ['key' => $key]);

    expect($allowed)->toContain('YES');
    expect($denied)->toContain('NO');
});

function phaseFourBladeCreateLicense(): License
{
    [$publicKey, $privateKey] = phaseFourBladeEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase4 Blade User '.bin2hex(random_bytes(4)),
        'email' => 'phase4-blade-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Blade Plan '.bin2hex(random_bytes(3)),
        'slug' => 'phase4-blade-plan-'.bin2hex(random_bytes(4)),
        'price' => 19.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $manager = app(LicenseManagerInterface::class);
    $key = $manager->generate($plan->getKey(), $userId);

    $license = License::query()->where('key', $key)->first();

    if (! $license instanceof License) {
        throw new RuntimeException('License record not found.');
    }

    return $license;
}

function phaseFourBladeEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}
