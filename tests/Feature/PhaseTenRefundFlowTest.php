<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;

beforeEach(function (): void {
    config([
        'subscription-guard.providers.drivers.iyzico.mock' => true,
        'subscription-guard.providers.drivers.iyzico.api_key' => null,
        'subscription-guard.providers.drivers.iyzico.secret_key' => null,
    ]);
});

it('returns a successful full refund with rf_ prefix ID in mock mode', function (): void {
    $provider = app(PaymentManager::class)->provider('iyzico');

    $response = $provider->refund('txn_mock_refund_001', 100.00);

    expect($response->success)->toBeTrue();
    expect($response->refundId)->not->toBeNull();
    expect(str_starts_with((string) $response->refundId, 'rf_'))->toBeTrue();
});

it('generates deterministic refund IDs in mock mode for the same input', function (): void {
    $provider = app(PaymentManager::class)->provider('iyzico');

    $first = $provider->refund('txn_deterministic_001', 50.00);
    $second = $provider->refund('txn_deterministic_001', 50.00);

    expect($first->refundId)->toBe($second->refundId);
});

it('generates different refund IDs for different inputs', function (): void {
    $provider = app(PaymentManager::class)->provider('iyzico');

    $first = $provider->refund('txn_different_001', 50.00);
    $second = $provider->refund('txn_different_002', 50.00);

    expect($first->refundId)->not->toBe($second->refundId);
});

it('includes transaction_id and amount in mock refund provider response', function (): void {
    $provider = app(PaymentManager::class)->provider('iyzico');

    $response = $provider->refund('txn_details_001', 75.50);

    expect($response->success)->toBeTrue();
    expect($response->providerResponse)->toBeArray();
    expect($response->providerResponse['transaction_id'])->toBe('txn_details_001');
    expect($response->providerResponse['amount'])->toBe(75.50);
});

it('handles refund with empty transaction ID and still returns a result', function (): void {
    $provider = app(PaymentManager::class)->provider('iyzico');

    $response = $provider->refund('', 25.00);

    expect($response->success)->toBeTrue();
    expect($response->refundId)->not->toBeNull();
    expect(str_starts_with((string) $response->refundId, 'rf_'))->toBeTrue();
});

it('resolves iyzico provider via PaymentManager', function (): void {
    $manager = app(PaymentManager::class);
    $provider = $manager->provider('iyzico');

    expect($provider)->toBeInstanceOf(IyzicoProvider::class);
    expect($provider->getName())->toBe('iyzico');
});

it('returns different refund IDs for same transaction but different amounts', function (): void {
    $provider = app(PaymentManager::class)->provider('iyzico');

    $full = $provider->refund('txn_partial_001', 100.00);
    $partial = $provider->refund('txn_partial_001', 50.00);

    expect($full->refundId)->not->toBe($partial->refundId);
    expect($full->success)->toBeTrue();
    expect($partial->success)->toBeTrue();
});
