<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Middleware\LicenseLimitMiddleware;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(8));

    config()->set('subscription-guard.license.revocation.cache_key', 'subguard:test:revocation:'.$suffix);
    config()->set('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:test:heartbeat:'.$suffix.':');
});

it('blocks route when required feature is missing', function (): void {
    $license = createPhaseFourLicenseForMiddleware();

    Route::get('/phase4/mw/feature-block', static fn () => 'ok')->middleware('subguard.feature:analytics');

    $response = phaseFourMiddlewareGet('/phase4/mw/feature-block', [
        'X-SubGuard-License-Key' => (string) $license->getAttribute('key'),
    ]);

    $response->assertStatus(403);
});

it('allows route when feature override is enabled', function (): void {
    $license = createPhaseFourLicenseForMiddleware();
    $license->setAttribute('feature_overrides', ['analytics' => true]);
    $license->save();

    Route::get('/phase4/mw/feature-allow', static fn () => 'ok')->middleware('subguard.feature:analytics');

    $response = phaseFourMiddlewareGet('/phase4/mw/feature-allow', [
        'X-SubGuard-License-Key' => (string) $license->getAttribute('key'),
    ]);

    $response->assertOk();
});

it('enforces limit middleware and returns 429 on overflow', function (): void {
    $license = createPhaseFourLicenseForMiddleware();
    $license->setAttribute('limit_overrides', ['api_calls' => 3]);
    $license->save();

    Route::get('/phase4/mw/limit', static fn () => 'ok')->middleware('subguard.limit:api_calls,2');

    $first = phaseFourMiddlewareGet('/phase4/mw/limit', [
        'X-SubGuard-License-Key' => (string) $license->getAttribute('key'),
    ]);
    $second = phaseFourMiddlewareGet('/phase4/mw/limit', [
        'X-SubGuard-License-Key' => (string) $license->getAttribute('key'),
    ]);

    $first->assertOk();
    $second->assertStatus(429);
});

it('reserves usage before executing downstream handler', function (): void {
    $featureGate = new class implements FeatureGateInterface
    {
        public bool $incrementCalled = false;

        public function can(mixed $subject, string $feature): bool
        {
            return true;
        }

        public function limit(mixed $subject, string $limit): int
        {
            return 1;
        }

        public function currentUsage(mixed $subject, string $limit): float
        {
            return 0.0;
        }

        public function incrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool
        {
            $this->incrementCalled = true;

            return true;
        }

        public function decrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool
        {
            return true;
        }
    };

    $middleware = new LicenseLimitMiddleware($featureGate);
    $request = Request::create('/phase4/mw/reserve-before-next', 'GET');
    $request->headers->set('X-SubGuard-License-Key', 'lic_reserve_before_next');

    $downstreamSawReservation = false;

    $response = $middleware->handle(
        $request,
        function () use ($featureGate, &$downstreamSawReservation) {
            $downstreamSawReservation = $featureGate->incrementCalled;

            return response('ok', 200);
        },
        'api_calls',
    );

    expect($response->getStatusCode())->toBe(200);
    expect($downstreamSawReservation)->toBeTrue();
});

it('rejects request before downstream handler when reservation cannot be acquired', function (): void {
    $featureGate = new class implements FeatureGateInterface
    {
        public function can(mixed $subject, string $feature): bool
        {
            return true;
        }

        public function limit(mixed $subject, string $limit): int
        {
            return 1;
        }

        public function currentUsage(mixed $subject, string $limit): float
        {
            return 0.0;
        }

        public function incrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool
        {
            return false;
        }

        public function decrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool
        {
            return true;
        }
    };

    $middleware = new LicenseLimitMiddleware($featureGate);
    $request = Request::create('/phase4/mw/reserve-reject', 'GET');
    $request->headers->set('X-SubGuard-License-Key', 'lic_reserve_reject');

    $downstreamExecuted = false;

    $response = $middleware->handle(
        $request,
        function () use (&$downstreamExecuted) {
            $downstreamExecuted = true;

            return response('ok', 200);
        },
        'api_calls',
    );

    expect($response->getStatusCode())->toBe(429);
    expect($downstreamExecuted)->toBeFalse();
});

function createPhaseFourLicenseForMiddleware(): License
{
    [$publicKey, $privateKey] = phaseFourMiddlewareEd25519KeyPair();

    config()->set('subscription-guard.license.keys.public', $publicKey);
    config()->set('subscription-guard.license.keys.private', $privateKey);
    config()->set('subscription-guard.license.default_ttl_seconds', 3600);

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase4 Middleware User '.bin2hex(random_bytes(4)),
        'email' => 'phase4-mw-'.bin2hex(random_bytes(4)).'@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase4 Middleware Plan '.bin2hex(random_bytes(3)),
        'slug' => 'phase4-mw-plan-'.bin2hex(random_bytes(4)),
        'price' => 39.00,
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

function phaseFourMiddlewareEd25519KeyPair(): array
{
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $private = sodium_bin2base64(sodium_crypto_sign_secretkey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    return [$public, $private];
}

function phaseFourMiddlewareGet(string $uri, array $headers = []): TestResponse
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create($uri, 'GET', server: $server);
    $response = app()->handle($request);

    return TestResponse::fromBaseResponse($response);
}
