<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider;

beforeEach(function (): void {
    config([
        'subscription-guard.providers.drivers.paytr.mock' => true,
        'subscription-guard.providers.drivers.paytr.merchant_key' => 'merchant_key_test',
        'subscription-guard.providers.drivers.paytr.merchant_salt' => 'merchant_salt_test',
    ]);
});

it('validates paytr webhook hash in live mode', function (): void {
    config(['subscription-guard.providers.drivers.paytr.mock' => false]);

    $payload = [
        'merchant_oid' => 'order_1001',
        'status' => 'success',
        'total_amount' => '12990',
    ];

    $hash = paytrHash($payload, 'merchant_key_test', 'merchant_salt_test');

    $provider = app(PaymentManager::class)->provider('paytr');

    expect($provider)->toBeInstanceOf(PaytrProvider::class);
    expect($provider->validateWebhook($payload, $hash))->toBeTrue();
    expect($provider->validateWebhook($payload, 'wrong_signature'))->toBeFalse();
});

it('normalizes paytr success webhook payload into orchestration-compatible result', function (): void {
    $provider = app(PaymentManager::class)->provider('paytr');

    $result = $provider->processWebhook([
        'merchant_oid' => 'oid_sub_2001',
        'status' => 'success',
        'total_amount' => '2450',
        'payment_type' => 'subscription',
        'reference_no' => 'ref_abc_001',
        'subscription_id' => 'paytr_sub_2001',
    ]);

    expect($result->processed)->toBeTrue();
    expect($result->eventType)->toBe('subscription.order.success');
    expect($result->eventId)->toBe('oid_sub_2001');
    expect($result->subscriptionId)->toBe('paytr_sub_2001');
    expect($result->transactionId)->toBe('ref_abc_001');
    expect($result->status)->toBe('active');
    expect($result->amount)->toBe(24.50);
});

it('normalizes paytr failed webhook payload into retry-compatible result', function (): void {
    $provider = app(PaymentManager::class)->provider('paytr');

    $result = $provider->processWebhook([
        'merchant_oid' => 'oid_sub_2002',
        'status' => 'failed',
        'total_amount' => '1000',
        'failed_reason_msg' => 'insufficient_balance',
        'subscription_id' => 'paytr_sub_2002',
    ]);

    expect($result->processed)->toBeTrue();
    expect($result->eventType)->toBe('subscription.order.failure');
    expect($result->eventId)->toBe('oid_sub_2002');
    expect($result->subscriptionId)->toBe('paytr_sub_2002');
    expect($result->status)->toBe('past_due');
    expect($result->amount)->toBe(10.00);
    expect((string) $result->message)->toContain('insufficient_balance');
});

it('returns iframe token and iframe url in mock pay flow', function (): void {
    $provider = app(PaymentManager::class)->provider('paytr');

    $response = $provider->pay(149.90, [
        'currency' => 'TRY',
        'mode' => 'iframe',
    ]);

    expect($response->success)->toBeTrue();
    expect((string) $response->iframeToken)->toStartWith('paytr_iframe_');
    expect((string) $response->iframeUrl)->toContain('https://www.paytr.com/odeme/');
});

it('marks subscription as trialing in mock createSubscription when trial_ends_at is provided', function (): void {
    $provider = app(PaymentManager::class)->provider('paytr');

    $response = $provider->createSubscription([
        'name' => 'PayTR Trial Plan',
    ], [
        'trial_ends_at' => now()->addDays(7)->toDateTimeString(),
        'card_token' => 'ctoken_001',
        'customer_token' => 'utoken_001',
    ]);

    expect($response->success)->toBeTrue();
    expect((string) $response->status)->toBe('trialing');
    expect((string) ($response->providerResponse['card_token'] ?? ''))->toBe('ctoken_001');
    expect((string) ($response->providerResponse['customer_token'] ?? ''))->toBe('utoken_001');
});

function paytrHash(array $payload, string $merchantKey, string $merchantSalt): string
{
    $message = (string) ($payload['merchant_oid'] ?? '')
        .$merchantSalt
        .(string) ($payload['status'] ?? '')
        .(string) ($payload['total_amount'] ?? '');

    return base64_encode(hash_hmac('sha256', $message, $merchantKey, true));
}
