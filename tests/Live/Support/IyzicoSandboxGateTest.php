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

it('requires an explicit live env source and does not infer credentials from testbench config', function (): void {
    $reason = IyzicoSandboxGate::liveSkipReason([]);

    expect($reason)->toBe('Set RUN_IYZICO_LIVE_SANDBOX_TESTS=true to run iyzico live sandbox tests.');
});

it('fills missing live values from configured env file without overriding process env', function (): void {
    $fixture = phaseEightCreateLiveEnvFixture([
        'RUN_IYZICO_LIVE_SANDBOX_TESTS=true',
        'IYZICO_MOCK=true',
        'IYZICO_API_KEY=file-key',
        'IYZICO_SECRET_KEY=file-secret',
        'IYZICO_BASE_URL=https://sandbox-api.iyzipay.com',
        'IYZICO_CALLBACK_URL=https://file.example.test/subguard/payment/iyzico/callback',
    ]);

    phaseEightWithProcessEnv([
        'SUBGUARD_LIVE_ENV_FILE' => $fixture,
        'RUN_IYZICO_LIVE_SANDBOX_TESTS' => 'true',
        'IYZICO_MOCK' => 'false',
        'IYZICO_API_KEY' => 'process-key',
    ], function (): void {
        expect(IyzicoSandboxGate::liveSkipReason())->toBeNull();

        IyzicoSandboxGate::skipUnlessConfigured($this);

        expect(config('subscription-guard.providers.drivers.iyzico.api_key'))->toBe('process-key')
            ->and(config('subscription-guard.providers.drivers.iyzico.secret_key'))->toBe('file-secret')
            ->and(config('subscription-guard.providers.drivers.iyzico.mock'))->toBeFalse()
            ->and(config('subscription-guard.providers.drivers.iyzico.callback_url'))->toBe('https://file.example.test/subguard/payment/iyzico/callback');
    });
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

function phaseEightCreateLiveEnvFixture(array $lines): string
{
    $path = sys_get_temp_dir().'/phase-eight-live-gate-'.bin2hex(random_bytes(6)).'.txt';
    file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);

    return $path;
}

function phaseEightWithProcessEnv(array $values, callable $callback): void
{
    $keys = array_unique(array_merge(array_keys($values), [
        'RUN_IYZICO_LIVE_SANDBOX_TESTS',
        'IYZICO_MOCK',
        'IYZICO_API_KEY',
        'IYZICO_SECRET_KEY',
        'IYZICO_BASE_URL',
        'IYZICO_CALLBACK_URL',
        'SUBGUARD_LIVE_ENV_FILE',
    ]));

    $snapshot = [];

    foreach ($keys as $key) {
        $snapshot[$key] = [
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'getenv' => getenv($key),
        ];

        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $callback();
    } finally {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            $previous = $snapshot[$key];

            if (is_string($previous['getenv'])) {
                putenv($key.'='.$previous['getenv']);
            }

            if ($previous['env'] !== null) {
                $_ENV[$key] = (string) $previous['env'];
            }

            if ($previous['server'] !== null) {
                $_SERVER[$key] = (string) $previous['server'];
            }
        }

        @unlink($values['SUBGUARD_LIVE_ENV_FILE'] ?? '');
    }
}
