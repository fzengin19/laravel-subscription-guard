<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use Throwable;

final class IyzicoSandboxGate
{
    public static function skipUnlessConfigured(TestCase $testCase, ?array $env = null): void
    {
        $values = self::environment($env);
        self::applyRuntimeConfiguration($values);
        $reason = self::liveSkipReason($values);

        if ($reason !== null) {
            $testCase->markTestSkipped($reason);
        }
    }

    public static function skipUnlessOperatorAssisted(TestCase $testCase, ?array $env = null, ?callable $probe = null): void
    {
        $values = self::environment($env);
        self::applyRuntimeConfiguration($values);
        $reason = self::operatorAssistedSkipReason($values, $probe);

        if ($reason !== null) {
            $testCase->markTestSkipped($reason);
        }
    }

    public static function liveSkipReason(?array $env = null): ?string
    {
        $values = self::environment($env);

        if (! self::isTruthy($values['RUN_IYZICO_LIVE_SANDBOX_TESTS'] ?? null)) {
            return 'Set RUN_IYZICO_LIVE_SANDBOX_TESTS=true to run iyzico live sandbox tests.';
        }

        if (strtolower(trim((string) ($values['IYZICO_MOCK'] ?? ''))) !== 'false') {
            return 'Set IYZICO_MOCK=false to run iyzico live sandbox tests.';
        }

        $apiKey = trim((string) ($values['IYZICO_API_KEY'] ?? ''));
        $secretKey = trim((string) ($values['IYZICO_SECRET_KEY'] ?? ''));

        if ($apiKey === '' || $secretKey === '') {
            return 'Set IYZICO_API_KEY and IYZICO_SECRET_KEY to run iyzico live sandbox tests.';
        }

        $baseUrl = trim((string) ($values['IYZICO_BASE_URL'] ?? 'https://sandbox-api.iyzipay.com'));

        if ($baseUrl === '' || ! str_contains($baseUrl, 'sandbox-api.iyzipay.com')) {
            return 'Set IYZICO_BASE_URL=https://sandbox-api.iyzipay.com for iyzico live sandbox tests.';
        }

        return null;
    }

    public static function operatorAssistedSkipReason(?array $env = null, ?callable $probe = null): ?string
    {
        $values = self::environment($env);
        $liveReason = self::liveSkipReason($values);

        if ($liveReason !== null) {
            return $liveReason;
        }

        $callbackUrl = trim((string) ($values['IYZICO_CALLBACK_URL'] ?? ''));

        if ($callbackUrl === '' || ! str_starts_with($callbackUrl, 'https://')) {
            return 'Requires public HTTPS tunnel.';
        }

        $reachabilityProbe = $probe ?? static fn (string $url): bool => self::isReachable($url);

        if (! $reachabilityProbe($callbackUrl)) {
            return 'Requires sandbox webhook delivery.';
        }

        return null;
    }

    public static function runWithTransientRetry(callable $operation, int $maxAttempts = 2, ?callable $sleep = null): mixed
    {
        $attempt = 0;
        $delay = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };

        beginning:
        $attempt++;

        try {
            $result = $operation();
        } catch (Throwable $throwable) {
            if ($attempt >= $maxAttempts || ! self::isTransientFailure($throwable)) {
                throw $throwable;
            }

            $delay(1);

            goto beginning;
        }

        if ($attempt < $maxAttempts && self::isTransientFailure($result)) {
            $delay(1);

            goto beginning;
        }

        return $result;
    }

    public static function isTransientFailure(mixed $subject): bool
    {
        $haystacks = [];

        if ($subject instanceof Throwable) {
            $haystacks[] = $subject->getMessage();
        }

        if (is_array($subject)) {
            foreach (['errorMessage', 'message', 'error', 'status', 'code'] as $key) {
                if (isset($subject[$key]) && is_scalar($subject[$key])) {
                    $haystacks[] = (string) $subject[$key];
                }
            }
        }

        if (is_object($subject)) {
            foreach (['getErrorMessage', 'getMessage', 'getStatus', 'getErrorCode'] as $method) {
                if (method_exists($subject, $method)) {
                    $value = $subject->{$method}();

                    if (is_scalar($value)) {
                        $haystacks[] = (string) $value;
                    }
                }
            }

            foreach (['failureReason'] as $property) {
                if (isset($subject->{$property}) && is_scalar($subject->{$property})) {
                    $haystacks[] = (string) $subject->{$property};
                }
            }

            if (isset($subject->providerResponse) && is_array($subject->providerResponse)) {
                foreach (['errorMessage', 'message', 'error', 'status', 'code'] as $key) {
                    if (isset($subject->providerResponse[$key]) && is_scalar($subject->providerResponse[$key])) {
                        $haystacks[] = (string) $subject->providerResponse[$key];
                    }
                }
            }
        }

        $message = strtolower(implode(' ', $haystacks));

        foreach (['500', '503', 'rate limit', 'too many requests', 'service unavailable', 'temporarily unavailable', 'timeout', 'system error', 'sistem hatası'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function environment(?array $env = null): array
    {
        $values = is_array($env) ? $env : [
            'RUN_IYZICO_LIVE_SANDBOX_TESTS' => self::readEnvironmentValue('RUN_IYZICO_LIVE_SANDBOX_TESTS'),
            'IYZICO_MOCK' => self::readEnvironmentValue('IYZICO_MOCK'),
            'IYZICO_API_KEY' => self::readEnvironmentValue('IYZICO_API_KEY'),
            'IYZICO_SECRET_KEY' => self::readEnvironmentValue('IYZICO_SECRET_KEY'),
            'IYZICO_BASE_URL' => self::readEnvironmentValue('IYZICO_BASE_URL'),
            'IYZICO_CALLBACK_URL' => self::readEnvironmentValue('IYZICO_CALLBACK_URL'),
        ];

        if (! is_array($env)) {
            foreach (self::fallbackEnvironmentValues() as $key => $value) {
                if (($values[$key] ?? null) === null) {
                    $values[$key] = $value;
                }
            }
        }

        if (! isset($values['IYZICO_BASE_URL']) || trim((string) $values['IYZICO_BASE_URL']) === '') {
            $values['IYZICO_BASE_URL'] = 'https://sandbox-api.iyzipay.com';
        }

        return $values;
    }

    private static function fallbackEnvironmentValues(): array
    {
        $file = self::readEnvironmentValue('SUBGUARD_LIVE_ENV_FILE') ?? '.env.test';
        $path = self::resolveEnvironmentFilePath($file);

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $parsed = class_exists(Dotenv::class)
            ? Dotenv::parse($contents)
            : self::parseEnvironmentContents($contents);

        $values = [];

        foreach (self::fallbackEnvironmentKeys() as $key) {
            $value = $parsed[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $trimmed = trim((string) $value);

            if ($trimmed !== '') {
                $values[$key] = $trimmed;
            }
        }

        return $values;
    }

    private static function resolveEnvironmentFilePath(string $file): ?string
    {
        $file = trim($file);

        if ($file === '') {
            return null;
        }

        if (str_starts_with($file, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $file) === 1) {
            return $file;
        }

        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$file;
    }

    private static function parseEnvironmentContents(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '') {
                continue;
            }

            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private static function fallbackEnvironmentKeys(): array
    {
        return [
            'RUN_IYZICO_LIVE_SANDBOX_TESTS',
            'IYZICO_MOCK',
            'IYZICO_API_KEY',
            'IYZICO_SECRET_KEY',
            'IYZICO_BASE_URL',
            'IYZICO_CALLBACK_URL',
        ];
    }

    private static function applyRuntimeConfiguration(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
        }

        if (! function_exists('config')) {
            return;
        }

        config()->set('subscription-guard.providers.drivers.iyzico.mock', strtolower(trim((string) ($values['IYZICO_MOCK'] ?? 'true'))) !== 'false');
        config()->set('subscription-guard.providers.drivers.iyzico.api_key', (string) ($values['IYZICO_API_KEY'] ?? ''));
        config()->set('subscription-guard.providers.drivers.iyzico.secret_key', (string) ($values['IYZICO_SECRET_KEY'] ?? ''));
        config()->set('subscription-guard.providers.drivers.iyzico.base_url', (string) ($values['IYZICO_BASE_URL'] ?? 'https://sandbox-api.iyzipay.com'));

        $callbackUrl = trim((string) ($values['IYZICO_CALLBACK_URL'] ?? ''));

        if ($callbackUrl !== '') {
            config()->set('subscription-guard.providers.drivers.iyzico.callback_url', $callbackUrl);
        }
    }

    private static function readEnvironmentValue(string $key): ?string
    {
        foreach ([$_ENV, $_SERVER] as $bag) {
            if (array_key_exists($key, $bag) && trim((string) $bag[$key]) !== '') {
                return (string) $bag[$key];
            }
        }

        if (function_exists('env')) {
            $value = env($key);

            if ($value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        $value = getenv($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function isTruthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function isReachable(string $url): bool
    {
        $headers = @get_headers($url, true);

        if (! is_array($headers) || $headers === []) {
            return false;
        }

        $statusLine = is_string($headers[0] ?? null) ? $headers[0] : '';

        if (! preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            return false;
        }

        $statusCode = (int) $matches[1];

        return ($statusCode >= 200 && $statusCode < 400) || $statusCode === 405;
    }
}
