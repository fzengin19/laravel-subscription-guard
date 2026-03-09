<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxFixtures;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

beforeEach(function (): void {
    IyzicoSandboxGate::skipUnlessConfigured($this);
});

it('refunds a successful live sandbox payment on the refundable card', function (): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('refund-success');
    $paymentPayload = IyzicoSandboxFixtures::paymentPayload('success_debit_tr', $context, ['mode' => 'non_3ds']);
    $paymentResponse = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $paymentPayload));

    $context->writeJsonArtifact('refund-success-payment.json', $paymentResponse->providerResponse);

    expect($paymentResponse->success)->toBeTrue()
        ->and($paymentResponse->transactionId)->not->toBeNull();

    $refundResponse = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->refund((string) $paymentResponse->transactionId, 10.0));

    $context->writeJsonArtifact('refund-success-refund.json', $refundResponse->providerResponse);

    expect($refundResponse->success)->toBeTrue()
        ->and($refundResponse->failureReason)->toBeNull();
});

it('returns a failure contract when refund is attempted on the non refundable edge card', function (): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('refund-edge-failure');
    $paymentPayload = IyzicoSandboxFixtures::paymentPayload('success_no_cancel_refund', $context, ['mode' => 'non_3ds']);
    $paymentResponse = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $paymentPayload));

    $context->writeJsonArtifact('refund-edge-payment.json', $paymentResponse->providerResponse);

    expect($paymentResponse->success)->toBeTrue()
        ->and($paymentResponse->transactionId)->not->toBeNull();

    $refundResponse = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->refund((string) $paymentResponse->transactionId, 10.0));

    $context->writeJsonArtifact('refund-edge-refund.json', $refundResponse->providerResponse);

    expect($refundResponse->success)->toBeFalse()
        ->and($refundResponse->failureReason)->not->toBeNull();
});
