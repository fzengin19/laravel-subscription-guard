<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxFixtures;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

beforeEach(function (): void {
    IyzicoSandboxGate::skipUnlessConfigured($this);
});

it('creates a live non 3ds payment with the success debit card', function (): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('payment-non-3ds-success');
    $payload = IyzicoSandboxFixtures::paymentPayload('success_debit_tr', $context, ['mode' => 'non_3ds']);

    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $payload));

    expect($response->success)->toBeTrue()
        ->and($response->transactionId)->not->toBeNull()
        ->and($response->failureReason)->toBeNull();
});

it('initializes checkout form with a live sandbox token and url', function (): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('payment-checkout-form');
    $payload = IyzicoSandboxFixtures::paymentPayload('success_debit_tr', $context, ['mode' => 'checkout_form']);

    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $payload));

    expect($response->success)->toBeTrue()
        ->and($response->iframeToken)->not->toBeNull()
        ->and($response->iframeUrl)->not->toBeNull();
});

it('accepts the documented foreign success card', function (): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('payment-foreign-success');
    $payload = IyzicoSandboxFixtures::paymentPayload('success_foreign_credit', $context, ['mode' => 'non_3ds']);

    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $payload));

    expect($response->success)->toBeTrue()
        ->and($response->transactionId)->not->toBeNull();
});

it('returns a failure contract for documented iyzico failure cards', function (string $fixture): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('payment-failure-'.$fixture);
    $payload = IyzicoSandboxFixtures::paymentPayload($fixture, $context, ['mode' => 'non_3ds']);

    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $payload));

    expect($response->success)->toBeFalse()
        ->and($response->failureReason)->not->toBeNull();
})->with('phase_eight_iyzico_failure_cards');

it('returns a live edge-case response for the success but non refundable card', function (): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('payment-non-refundable-edge');
    $payload = IyzicoSandboxFixtures::paymentPayload('success_no_cancel_refund', $context, ['mode' => 'non_3ds']);

    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $payload));

    expect($response->success)->toBeTrue()
        ->and($response->transactionId)->not->toBeNull();
});

it('fails 3ds initialize on the documented card', function (): void {
    $provider = app(IyzicoProvider::class);
    $context = IyzicoSandboxRunContext::create('payment-3ds-init-failure');
    $payload = IyzicoSandboxFixtures::paymentPayload('fail_3ds_initialize', $context, ['mode' => '3ds']);

    $response = IyzicoSandboxGate::runWithTransientRetry(fn () => $provider->pay(10.0, $payload));

    expect($response->success)->toBeFalse()
        ->and($response->failureReason)->not->toBeNull();
});

dataset('phase_eight_iyzico_failure_cards', [
    'insufficient funds' => ['fail_insufficient_funds'],
    'do not honour' => ['fail_do_not_honour'],
    'invalid transaction' => ['fail_invalid_transaction'],
    'lost card' => ['fail_lost_card'],
    'stolen card' => ['fail_stolen_card'],
    'expired card' => ['fail_expired_card'],
    'invalid cvc2' => ['fail_invalid_cvc2'],
    'not permitted cardholder' => ['fail_not_permitted_to_cardholder'],
    'not permitted terminal' => ['fail_not_permitted_to_terminal'],
    'fraud suspect' => ['fail_fraud_suspect'],
    'pickup card' => ['fail_pickup_card'],
    'general error' => ['fail_general_error'],
]);
