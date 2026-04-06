<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;

function iyzicoProvider(): IyzicoProvider
{
    $provider = app(PaymentManager::class)->provider('iyzico');
    assert($provider instanceof IyzicoProvider);

    return $provider;
}

function hmacSignature(string $message, string $secret): string
{
    return bin2hex(hash_hmac('sha256', $message, $secret, true));
}

beforeEach(function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico', [
        'class' => IyzicoProvider::class,
        'api_key' => 'test-api-key',
        'secret_key' => 'test-secret-key',
        'merchant_id' => 'merchant123',
        'mock' => false,
        'base_url' => 'https://sandbox-api.iyzipay.com',
    ]);
});

// ---------------------------------------------------------------------------
// Pattern 1 - Subscription events
// ---------------------------------------------------------------------------

it('validates correct subscription event signature', function (): void {
    $secret = 'test-secret-key';
    $merchantId = 'merchant123';
    $eventType = 'subscription.order.success';
    $subscriptionRef = 'sub_ref_001';
    $orderRef = 'order_ref_001';
    $customerRef = 'cust_ref_001';

    $message = $merchantId.$secret.$eventType.$subscriptionRef.$orderRef.$customerRef;
    $signature = hmacSignature($message, $secret);

    $payload = [
        'iyziEventType' => $eventType,
        'subscriptionReferenceCode' => $subscriptionRef,
        'orderReferenceCode' => $orderRef,
        'customerReferenceCode' => $customerRef,
    ];

    expect(iyzicoProvider()->validateWebhook($payload, $signature))->toBeTrue();
});

it('rejects wrong signature for subscription event', function (): void {
    $payload = [
        'iyziEventType' => 'subscription.order.success',
        'subscriptionReferenceCode' => 'sub_ref_001',
        'orderReferenceCode' => 'order_ref_001',
        'customerReferenceCode' => 'cust_ref_001',
    ];

    expect(iyzicoProvider()->validateWebhook($payload, 'deadbeef'))->toBeFalse();
});

it('falls through to next pattern when merchant_id is missing for subscription event', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.merchant_id', null);

    $secret = 'test-secret-key';
    $eventType = 'subscription.order.success';
    $subscriptionRef = 'sub_ref_001';
    $orderRef = 'order_ref_001';
    $customerRef = 'cust_ref_001';

    // Build signature for pattern 1 (which will not match because merchant_id is empty)
    $message = ''.$secret.$eventType.$subscriptionRef.$orderRef.$customerRef;
    $signature = hmacSignature($message, $secret);

    $payload = [
        'iyziEventType' => $eventType,
        'subscriptionReferenceCode' => $subscriptionRef,
        'orderReferenceCode' => $orderRef,
        'customerReferenceCode' => $customerRef,
    ];

    // Pattern 1 won't match (merchant_id is empty), falls through;
    // no token in payload, so pattern 2 skips;
    // pattern 3 requires paymentId + conversationId + status which are absent, so it returns empty string => false
    expect(iyzicoProvider()->validateWebhook($payload, $signature))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Pattern 2 - Token-based
// ---------------------------------------------------------------------------

it('validates correct token-based signature', function (): void {
    $secret = 'test-secret-key';
    $eventType = 'payment.3ds.callback';
    $paymentId = 'pay_001';
    $token = 'tok_abc123';
    $conversationId = 'conv_001';
    $status = 'success';

    $message = $secret.$eventType.$paymentId.$token.$conversationId.$status;
    $signature = hmacSignature($message, $secret);

    $payload = [
        'iyziEventType' => $eventType,
        'paymentId' => $paymentId,
        'token' => $token,
        'paymentConversationId' => $conversationId,
        'status' => $status,
    ];

    expect(iyzicoProvider()->validateWebhook($payload, $signature))->toBeTrue();
});

it('rejects tampered payload for token-based signature', function (): void {
    $secret = 'test-secret-key';
    $eventType = 'payment.3ds.callback';
    $paymentId = 'pay_001';
    $token = 'tok_abc123';
    $conversationId = 'conv_001';
    $status = 'success';

    $message = $secret.$eventType.$paymentId.$token.$conversationId.$status;
    $signature = hmacSignature($message, $secret);

    $payload = [
        'iyziEventType' => $eventType,
        'paymentId' => 'pay_TAMPERED',
        'token' => $token,
        'paymentConversationId' => $conversationId,
        'status' => $status,
    ];

    expect(iyzicoProvider()->validateWebhook($payload, $signature))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Pattern 3 - Payment-based (no token)
// ---------------------------------------------------------------------------

it('validates correct payment-based signature without token', function (): void {
    $secret = 'test-secret-key';
    $eventType = 'payment.success';
    $paymentId = 'pay_002';
    $conversationId = 'conv_002';
    $status = 'success';

    $message = $secret.$eventType.$paymentId.$conversationId.$status;
    $signature = hmacSignature($message, $secret);

    $payload = [
        'iyziEventType' => $eventType,
        'paymentId' => $paymentId,
        'paymentConversationId' => $conversationId,
        'status' => $status,
    ];

    expect(iyzicoProvider()->validateWebhook($payload, $signature))->toBeTrue();
});

it('rejects payment-based signature when wrong secret is used', function (): void {
    $correctSecret = 'test-secret-key';
    $wrongSecret = 'wrong-secret-key';
    $eventType = 'payment.success';
    $paymentId = 'pay_002';
    $conversationId = 'conv_002';
    $status = 'success';

    $message = $wrongSecret.$eventType.$paymentId.$conversationId.$status;
    $signature = hmacSignature($message, $wrongSecret);

    $payload = [
        'iyziEventType' => $eventType,
        'paymentId' => $paymentId,
        'paymentConversationId' => $conversationId,
        'status' => $status,
    ];

    expect(iyzicoProvider()->validateWebhook($payload, $signature))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Edge cases
// ---------------------------------------------------------------------------

it('returns true in mock mode regardless of signature', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);

    $payload = ['iyziEventType' => 'payment.success'];

    expect(iyzicoProvider()->validateWebhook($payload, 'any-garbage-value'))->toBeTrue();
    expect(iyzicoProvider()->validateWebhook($payload, ''))->toBeTrue();
});

it('returns false when secret key is empty', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', '');

    $payload = [
        'iyziEventType' => 'payment.success',
        'paymentId' => 'pay_001',
        'paymentConversationId' => 'conv_001',
        'status' => 'success',
    ];

    expect(iyzicoProvider()->validateWebhook($payload, 'some-signature'))->toBeFalse();
});

it('returns false when signature is empty', function (): void {
    $payload = [
        'iyziEventType' => 'payment.success',
        'paymentId' => 'pay_001',
        'paymentConversationId' => 'conv_001',
        'status' => 'success',
    ];

    expect(iyzicoProvider()->validateWebhook($payload, ''))->toBeFalse();
});

it('returns false when no signature pattern can be matched', function (): void {
    $payload = [
        'iyziEventType' => 'some.event',
    ];

    expect(iyzicoProvider()->validateWebhook($payload, 'some-signature'))->toBeFalse();
});

it('compares signatures case-insensitively', function (): void {
    $secret = 'test-secret-key';
    $eventType = 'payment.success';
    $paymentId = 'pay_003';
    $conversationId = 'conv_003';
    $status = 'success';

    $message = $secret.$eventType.$paymentId.$conversationId.$status;
    $signature = hmacSignature($message, $secret);

    $payload = [
        'iyziEventType' => $eventType,
        'paymentId' => $paymentId,
        'paymentConversationId' => $conversationId,
        'status' => $status,
    ];

    expect(iyzicoProvider()->validateWebhook($payload, strtoupper($signature)))->toBeTrue();
});
