<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

uses(RefreshDatabase::class);

function sendSignatureTestWebhook(string $provider, array $payload, array $headers = []): TestResponse
{
    $serverHeaders = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    foreach ($headers as $key => $value) {
        $serverHeaders['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
    }

    $request = Request::create(
        '/subguard/webhooks/'.$provider,
        'POST',
        [],
        [],
        [],
        $serverHeaders,
        (string) json_encode($payload)
    );

    $response = app()->handle($request);

    return TestResponse::fromBaseResponse($response);
}

it('rejects webhook with invalid signature and does not store a WebhookCall record', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', 'test-secret-key');

    $payload = [
        'event_id' => 'evt_sig_reject_001',
        'event_type' => 'payment.success',
        'amount' => 50.00,
    ];

    $response = sendSignatureTestWebhook('iyzico', $payload, [
        'x-iyz-signature' => 'invalid-signature-value',
    ]);

    $response
        ->assertStatus(401)
        ->assertJson([
            'status' => 'rejected',
            'reason' => 'Invalid webhook signature.',
        ]);

    expect(WebhookCall::query()->count())->toBe(0);
});

it('accepts webhook with valid signature in mock mode and stores a WebhookCall record', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);

    $payload = [
        'event_id' => 'evt_sig_accept_001',
        'event_type' => 'payment.success',
        'amount' => 99.90,
    ];

    $response = sendSignatureTestWebhook('iyzico', $payload);

    $response
        ->assertStatus(202)
        ->assertJson([
            'status' => 'accepted',
            'provider' => 'iyzico',
            'event_id' => 'evt_sig_accept_001',
            'duplicate' => false,
        ]);

    expect(WebhookCall::query()->count())->toBe(1);

    $webhookCall = WebhookCall::query()->first();
    expect($webhookCall->event_id)->toBe('evt_sig_accept_001');
    expect($webhookCall->provider)->toBe('iyzico');
    expect(in_array($webhookCall->status, ['pending', 'processed'], true))->toBeTrue();
});

it('rejects webhook with no signature header when mock mode is off', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', 'test-secret-key');

    $payload = [
        'event_id' => 'evt_sig_no_header_001',
        'event_type' => 'subscription.cancelled',
    ];

    $response = sendSignatureTestWebhook('iyzico', $payload);

    $response
        ->assertStatus(401)
        ->assertJson([
            'status' => 'rejected',
            'reason' => 'Invalid webhook signature.',
        ]);

    expect(WebhookCall::query()->count())->toBe(0);
});

it('filters sensitive headers from stored webhook call', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);

    $payload = [
        'event_id' => 'evt_header_filter_001',
        'event_type' => 'payment.success',
    ];

    $response = sendSignatureTestWebhook('iyzico', $payload, [
        'authorization' => 'Bearer secret-token',
        'x-custom-header' => 'safe-value',
    ]);

    $response->assertStatus(202);

    $webhookCall = WebhookCall::query()->first();
    $storedHeaders = $webhookCall->headers;

    expect($storedHeaders)->not->toHaveKey('authorization');
    expect($storedHeaders)->not->toHaveKey('cookie');
    expect($storedHeaders)->not->toHaveKey('proxy-authorization');
});

it('uses configured webhook_signature_header when available', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', 'test-secret-key');
    config()->set('subscription-guard.providers.drivers.iyzico.webhook_signature_header', 'x-custom-sig');

    $payload = [
        'event_id' => 'evt_custom_header_001',
        'event_type' => 'payment.success',
    ];

    // With wrong signature in custom header, should be rejected
    $response = sendSignatureTestWebhook('iyzico', $payload, [
        'x-custom-sig' => 'wrong-signature',
    ]);

    $response->assertStatus(401);
    expect(WebhookCall::query()->count())->toBe(0);
});
