<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCancelled;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCreated;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

it('updates linked license status from generic billing events', function (): void {
    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase4 Listener User',
        'email' => 'phase4-listener-user@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Listener Plan',
        'slug' => 'phase4-listener-plan',
        'price' => 15.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $license = License::unguarded(static fn () => License::query()->create([
        'user_id' => $userId,
        'plan_id' => $plan->getKey(),
        'key' => 'SG.listener.license',
        'status' => 'active',
        'expires_at' => now()->addMonth(),
        'heartbeat_at' => now()->subDay(),
    ]));

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => $license->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'sub_listener_001',
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 15.00,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    event(new PaymentFailed('paytr', $subscription->getKey(), 15.00));
    expect((string) $license->fresh()->getAttribute('status'))->toBe('past_due');

    event(new PaymentCompleted('paytr', $subscription->getKey(), null, 15.00));
    expect((string) $license->fresh()->getAttribute('status'))->toBe('active');

    event(new SubscriptionCancelled('paytr', 'sub_listener_001', $subscription->getKey()));
    expect((string) $license->fresh()->getAttribute('status'))->toBe('cancelled');
});

it('creates and links a license when subscription created event is received', function (): void {
    [$publicKey, $privateKey] = makePhaseFourLifecycleEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.revocation.cache_key', 'phase4_listener_revocation_'.bin2hex(random_bytes(4)));
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'phase4_listener_heartbeat_'.bin2hex(random_bytes(4)).':');

    $suffix = bin2hex(random_bytes(4));

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase4 Bridge User '.$suffix,
        'email' => 'phase4-bridge-'.$suffix.'@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Bridge Plan '.$suffix,
        'slug' => 'phase4-bridge-plan-'.$suffix,
        'price' => 19.00,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::unguarded(static fn () => Subscription::query()->create([
        'subscribable_type' => 'App\\Models\\User',
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'license_id' => null,
        'provider' => 'paytr',
        'provider_subscription_id' => 'sub_bridge_'.$suffix,
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 19.00,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]));

    event(new SubscriptionCreated('paytr', 'sub_bridge_'.$suffix, (int) $subscription->getKey()));

    $linkedSubscription = $subscription->fresh();
    $linkedLicenseId = $linkedSubscription?->getAttribute('license_id');

    expect($linkedLicenseId)->not->toBeNull();
    expect(License::query()->find($linkedLicenseId))->not->toBeNull();
});

function makePhaseFourLifecycleEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}
