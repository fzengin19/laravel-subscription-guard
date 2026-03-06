<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

beforeEach(function (): void {
    IyzicoSandboxGate::skipUnlessConfigured($this);
});

it('validates the live sandbox preflight contract', function (): void {
    $context = IyzicoSandboxRunContext::create('preflight');
    $runFlag = strtolower(trim((string) env('RUN_IYZICO_LIVE_SANDBOX_TESTS')));

    expect(in_array($runFlag, ['1', 'true'], true))->toBeTrue()
        ->and((bool) config('subscription-guard.providers.drivers.iyzico.mock'))->toBeFalse()
        ->and(trim((string) env('IYZICO_API_KEY')))->not->toBe('')
        ->and(trim((string) env('IYZICO_SECRET_KEY')))->not->toBe('')
        ->and((string) env('IYZICO_BASE_URL'))->toContain('sandbox-api.iyzipay.com')
        ->and($context->runId())->toStartWith('preflight-');
});

it('runs operator assisted callback checks only when a public https endpoint is reachable', function (): void {
    IyzicoSandboxGate::skipUnlessOperatorAssisted($this);

    expect((string) env('IYZICO_CALLBACK_URL'))->toStartWith('https://');
});
