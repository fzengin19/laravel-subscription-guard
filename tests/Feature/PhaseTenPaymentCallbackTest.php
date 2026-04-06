<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

function postCallback(string $provider, string $path, array $payload, array $headers = []): TestResponse
{
    $server = array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $headers);

    $request = Request::create(
        '/subguard/webhooks/'.$provider.'/'.$path,
        'POST',
        [],
        [],
        [],
        $server,
        (string) json_encode($payload)
    );

    $response = app()->handle($request);

    return TestResponse::fromBaseResponse($response);
}

beforeEach(function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', true);
    config()->set('subscription-guard.providers.drivers.iyzico.api_key', null);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', null);
});

// ---------------------------------------------------------------------------
// 3DS callback tests
// ---------------------------------------------------------------------------

it('accepts a valid 3DS callback and creates a webhook call with 202', function (): void {
    Bus::fake();

    $payload = [
        'conversationId' => '3ds-phase10-001',
        'status' => 'success',
    ];

    $response = postCallback('iyzico', '3ds/callback', $payload);

    $response->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'event_id' => '3ds-phase10-001',
        'duplicate' => false,
    ]);

    $webhookCall = WebhookCall::query()
        ->where('provider', 'iyzico')
        ->where('event_id', '3ds-phase10-001')
        ->first();

    expect($webhookCall)->not->toBeNull();
    expect((string) $webhookCall?->getAttribute('event_type'))->toBe('payment.3ds.callback');
    expect((string) $webhookCall?->getAttribute('status'))->toBe('pending');
    expect($webhookCall?->getAttribute('payload'))->toBe($payload);

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});

it('returns 200 for duplicate 3DS callback without creating a second record', function (): void {
    Bus::fake();

    $payload = [
        'conversationId' => '3ds-phase10-dup-001',
        'status' => 'success',
    ];

    $first = postCallback('iyzico', '3ds/callback', $payload);
    $second = postCallback('iyzico', '3ds/callback', $payload);

    $first->assertStatus(202)->assertJson([
        'duplicate' => false,
    ]);

    $second->assertStatus(200)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'event_id' => '3ds-phase10-dup-001',
        'duplicate' => true,
    ]);

    expect(WebhookCall::query()
        ->where('provider', 'iyzico')
        ->where('event_id', '3ds-phase10-dup-001')
        ->count())->toBe(1);

    Bus::assertDispatchedTimes(FinalizeWebhookEventJob::class, 1);
});

it('resets a failed 3DS callback for retry on re-delivery', function (): void {
    Bus::fake();

    WebhookCall::query()->create([
        'provider' => 'iyzico',
        'event_type' => 'payment.3ds.callback',
        'event_id' => '3ds-phase10-retry-001',
        'idempotency_key' => 'first-attempt',
        'payload' => [
            'conversationId' => '3ds-phase10-retry-001',
            'status' => 'failure',
            'attempt' => 1,
        ],
        'headers' => [],
        'status' => 'failed',
        'error_message' => 'Processing error',
        'processed_at' => now(),
    ]);

    $response = postCallback('iyzico', '3ds/callback', [
        'conversationId' => '3ds-phase10-retry-001',
        'status' => 'success',
        'attempt' => 2,
    ]);

    $response->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'event_id' => '3ds-phase10-retry-001',
        'duplicate' => false,
    ]);

    $webhookCall = WebhookCall::query()
        ->where('provider', 'iyzico')
        ->where('event_id', '3ds-phase10-retry-001')
        ->first();

    expect(WebhookCall::query()->where('event_id', '3ds-phase10-retry-001')->count())->toBe(1);
    expect((string) $webhookCall?->getAttribute('status'))->toBe('pending');
    expect($webhookCall?->getAttribute('processed_at'))->toBeNull();
    expect($webhookCall?->getAttribute('error_message'))->toBeNull();
    expect((int) ($webhookCall?->getAttribute('payload')['attempt'] ?? 0))->toBe(2);

    Bus::assertDispatchedTimes(FinalizeWebhookEventJob::class, 1);
});

// ---------------------------------------------------------------------------
// Checkout callback tests
// ---------------------------------------------------------------------------

it('accepts a valid checkout callback and creates a webhook call', function (): void {
    Bus::fake();

    $payload = [
        'token' => 'checkout-phase10-tok-001',
        'status' => 'success',
        'paymentId' => 'pay_checkout_001',
    ];

    $response = postCallback('iyzico', 'checkout/callback', $payload);

    $response->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'duplicate' => false,
    ]);

    $webhookCall = WebhookCall::query()
        ->where('provider', 'iyzico')
        ->where('event_type', 'payment.checkout.callback')
        ->first();

    expect($webhookCall)->not->toBeNull();
    expect((string) $webhookCall?->getAttribute('status'))->toBe('pending');
    expect($webhookCall?->getAttribute('payload'))->toBe($payload);

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});

// ---------------------------------------------------------------------------
// Empty payload behavior
// ---------------------------------------------------------------------------

it('does not reject empty payload on callback controller since guard is not present', function (): void {
    // Note: The FIX-07 empty payload check was added to WebhookController only.
    // PaymentCallbackController does not have it. In mock mode, validateWebhook
    // returns true, so an empty payload will be accepted and stored.
    Bus::fake();

    $response = postCallback('iyzico', '3ds/callback', []);

    // Mock mode passes validation; the callback controller proceeds and stores the call.
    // The event_id will be a SHA-256 hash since there are no identifiable fields.
    $response->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
    ]);

    expect(WebhookCall::query()->where('event_type', 'payment.3ds.callback')->count())->toBe(1);

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});

// ---------------------------------------------------------------------------
// Unknown provider
// ---------------------------------------------------------------------------

it('returns 404 for unknown provider on 3DS callback', function (): void {
    $response = postCallback('nonexistent', '3ds/callback', [
        'conversationId' => 'unknown-conv-001',
        'status' => 'success',
    ]);

    $response->assertStatus(404)->assertJson([
        'status' => 'rejected',
        'provider' => 'nonexistent',
        'reason' => 'Unknown provider.',
    ]);
});

it('returns 404 for unknown provider on checkout callback', function (): void {
    $response = postCallback('nonexistent', 'checkout/callback', [
        'token' => 'unknown-tok-001',
        'status' => 'success',
    ]);

    $response->assertStatus(404)->assertJson([
        'status' => 'rejected',
        'provider' => 'nonexistent',
        'reason' => 'Unknown provider.',
    ]);
});

// ---------------------------------------------------------------------------
// Signature validation in live mode
// ---------------------------------------------------------------------------

it('rejects 3DS callback with invalid signature in live mode', function (): void {
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', 'live-secret');

    $response = postCallback('iyzico', '3ds/callback', [
        'iyziEventType' => 'payment.3ds.callback',
        'paymentId' => 'pay_live_001',
        'paymentConversationId' => 'conv_live_001',
        'status' => 'success',
    ], [
        'HTTP_X_IYZ_SIGNATURE_V3' => 'invalid-signature',
    ]);

    $response->assertStatus(401)->assertJson([
        'status' => 'rejected',
        'provider' => 'iyzico',
        'reason' => 'Invalid callback signature.',
    ]);
});

it('accepts checkout callback with valid signature in live mode', function (): void {
    Bus::fake();

    $secret = 'live-checkout-secret';
    config()->set('subscription-guard.providers.drivers.iyzico.mock', false);
    config()->set('subscription-guard.providers.drivers.iyzico.secret_key', $secret);

    $eventType = 'payment.checkout.callback';
    $paymentId = 'pay_checkout_live_001';
    $token = 'tok_checkout_live_001';
    $conversationId = 'conv_checkout_live_001';
    $status = 'success';

    $message = $secret.$eventType.$paymentId.$token.$conversationId.$status;
    $signature = bin2hex(hash_hmac('sha256', $message, $secret, true));

    $response = postCallback('iyzico', 'checkout/callback', [
        'iyziEventType' => $eventType,
        'paymentId' => $paymentId,
        'token' => $token,
        'paymentConversationId' => $conversationId,
        'status' => $status,
    ], [
        'HTTP_X_IYZ_SIGNATURE_V3' => $signature,
    ]);

    $response->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'duplicate' => false,
    ]);

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});
