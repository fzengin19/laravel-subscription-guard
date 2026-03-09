<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

it('creates a unique run context with artifact paths', function (): void {
    $context = IyzicoSandboxRunContext::create('payment-contracts');

    expect($context->phase())->toBe('payment-contracts')
        ->and($context->runId())->toStartWith('payment-contracts-')
        ->and($context->artifactsDirectory())->toContain('storage/app/testing/iyzico-sandbox')
        ->and($context->artifactPath('response.json'))->toEndWith('response.json')
        ->and($context->scopedValue('plan'))->toContain($context->runId());
});

it('writes json forensic artifacts', function (): void {
    $context = IyzicoSandboxRunContext::create('artifacts');

    $context->writeJsonArtifact('response.json', ['ok' => true]);

    expect(file_exists($context->artifactPath('response.json')))->toBeTrue()
        ->and(file_get_contents($context->artifactPath('response.json')) ?: '')->toContain('"ok": true');
});
