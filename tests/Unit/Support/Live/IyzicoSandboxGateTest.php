<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;

it('returns a skip reason when live sandbox tests are not enabled', function (): void {
    $reason = IyzicoSandboxGate::liveSkipReason([
        'RUN_IYZICO_LIVE_SANDBOX_TESTS' => 'false',
        'IYZICO_MOCK' => 'false',
        'IYZICO_API_KEY' => 'sandbox-key',
        'IYZICO_SECRET_KEY' => 'sandbox-secret',
        'IYZICO_BASE_URL' => 'https://sandbox-api.iyzipay.com',
    ]);

    expect($reason)->toContain('RUN_IYZICO_LIVE_SANDBOX_TESTS=true');
});

it('returns a skip reason when iyzico mock mode is still enabled', function (): void {
    $reason = IyzicoSandboxGate::liveSkipReason([
        'RUN_IYZICO_LIVE_SANDBOX_TESTS' => 'true',
        'IYZICO_MOCK' => 'true',
        'IYZICO_API_KEY' => 'sandbox-key',
        'IYZICO_SECRET_KEY' => 'sandbox-secret',
        'IYZICO_BASE_URL' => 'https://sandbox-api.iyzipay.com',
    ]);

    expect($reason)->toContain('IYZICO_MOCK=false');
});

it('returns a skip reason when sandbox credentials are incomplete', function (): void {
    $reason = IyzicoSandboxGate::liveSkipReason([
        'RUN_IYZICO_LIVE_SANDBOX_TESTS' => 'true',
        'IYZICO_MOCK' => 'false',
        'IYZICO_API_KEY' => '',
        'IYZICO_SECRET_KEY' => '',
        'IYZICO_BASE_URL' => 'https://sandbox-api.iyzipay.com',
    ]);

    expect($reason)->toContain('IYZICO_API_KEY');
});

it('returns no skip reason when live sandbox requirements are satisfied', function (): void {
    $reason = IyzicoSandboxGate::liveSkipReason([
        'RUN_IYZICO_LIVE_SANDBOX_TESTS' => 'true',
        'IYZICO_MOCK' => 'false',
        'IYZICO_API_KEY' => 'sandbox-key',
        'IYZICO_SECRET_KEY' => 'sandbox-secret',
        'IYZICO_BASE_URL' => 'https://sandbox-api.iyzipay.com',
    ]);

    expect($reason)->toBeNull();
});

it('requires explicit process env and does not infer live credentials from testbench config', function (): void {
    $reason = IyzicoSandboxGate::liveSkipReason([]);

    expect($reason)->toBe('Set RUN_IYZICO_LIVE_SANDBOX_TESTS=true to run iyzico live sandbox tests.');
});

it('returns operator-assisted skip reason when callback url is missing', function (): void {
    $reason = IyzicoSandboxGate::operatorAssistedSkipReason([
        'RUN_IYZICO_LIVE_SANDBOX_TESTS' => 'true',
        'IYZICO_MOCK' => 'false',
        'IYZICO_API_KEY' => 'sandbox-key',
        'IYZICO_SECRET_KEY' => 'sandbox-secret',
        'IYZICO_BASE_URL' => 'https://sandbox-api.iyzipay.com',
        'IYZICO_CALLBACK_URL' => '',
    ]);

    expect($reason)->toContain('Requires public HTTPS tunnel');
});

it('returns operator-assisted skip reason when callback endpoint is unreachable', function (): void {
    $reason = IyzicoSandboxGate::operatorAssistedSkipReason([
        'RUN_IYZICO_LIVE_SANDBOX_TESTS' => 'true',
        'IYZICO_MOCK' => 'false',
        'IYZICO_API_KEY' => 'sandbox-key',
        'IYZICO_SECRET_KEY' => 'sandbox-secret',
        'IYZICO_BASE_URL' => 'https://sandbox-api.iyzipay.com',
        'IYZICO_CALLBACK_URL' => 'https://example.test/subguard/live-sandbox/health',
    ], static fn (string $url): bool => $url === 'https://elsewhere.test/health');

    expect($reason)->toContain('Requires sandbox webhook delivery');
});

it('retries once on transient failures', function (): void {
    $attempts = 0;
    $sleeps = 0;

    $result = IyzicoSandboxGate::runWithTransientRetry(
        function () use (&$attempts): array {
            $attempts++;

            return $attempts === 1
                ? ['status' => 'failure', 'errorMessage' => '503 Service Unavailable']
                : ['status' => 'success'];
        },
        sleep: function (int $seconds) use (&$sleeps): void {
            expect($seconds)->toBe(1);
            $sleeps++;
        },
    );

    expect($attempts)->toBe(2)
        ->and($sleeps)->toBe(1)
        ->and($result)->toBe(['status' => 'success']);
});

it('does not retry on non transient failures', function (): void {
    $attempts = 0;

    $result = IyzicoSandboxGate::runWithTransientRetry(function () use (&$attempts): array {
        $attempts++;

        return ['status' => 'failure', 'errorMessage' => 'Invalid transaction'];
    });

    expect($attempts)->toBe(1)
        ->and($result)->toBe(['status' => 'failure', 'errorMessage' => 'Invalid transaction']);
});
