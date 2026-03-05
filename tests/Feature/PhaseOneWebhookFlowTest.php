<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

function sendWebhook(string $provider, array $payload): TestResponse
{
    $request = Request::create(
        '/subguard/webhooks/'.$provider,
        'POST',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        (string) json_encode($payload)
    );

    $response = app()->handle($request);

    return TestResponse::fromBaseResponse($response);
}

it('stores webhook call and dispatches finalization job', function (): void {
    Bus::fake();

    $response = sendWebhook('iyzico', [
        'event_id' => 'evt_iyzico_001',
        'event_type' => 'payment.success',
        'amount' => 99.90,
    ]);

    $response
        ->assertStatus(202)
        ->assertJson([
            'status' => 'accepted',
            'provider' => 'iyzico',
            'event_id' => 'evt_iyzico_001',
            'duplicate' => false,
        ]);

    expect(WebhookCall::query()->count())->toBe(1);
    expect(WebhookCall::query()->first()?->event_id)->toBe('evt_iyzico_001');

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});

it('returns duplicate response without creating second webhook record', function (): void {
    Bus::fake();

    $payload = [
        'event_id' => 'evt_iyzico_002',
        'event_type' => 'payment.success',
    ];

    sendWebhook('iyzico', $payload)->assertStatus(202);

    $duplicate = sendWebhook('iyzico', $payload);

    $duplicate
        ->assertOk()
        ->assertJson([
            'status' => 'accepted',
            'provider' => 'iyzico',
            'event_id' => 'evt_iyzico_002',
            'duplicate' => true,
        ]);

    expect(WebhookCall::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(FinalizeWebhookEventJob::class, 1);
});

it('re-opens failed webhook call and re-dispatches processing on retry', function (): void {
    Bus::fake();

    WebhookCall::query()->create([
        'provider' => 'iyzico',
        'event_type' => 'payment.success',
        'event_id' => 'evt_iyzico_failed_retry_001',
        'idempotency_key' => 'first-attempt',
        'payload' => ['event_id' => 'evt_iyzico_failed_retry_001', 'event_type' => 'payment.success', 'attempt' => 1],
        'headers' => [],
        'status' => 'failed',
        'error_message' => 'Invalid signature',
        'processed_at' => now(),
    ]);

    $response = sendWebhook('iyzico', [
        'event_id' => 'evt_iyzico_failed_retry_001',
        'event_type' => 'payment.success',
        'attempt' => 2,
    ]);

    $response->assertStatus(202)->assertJson([
        'status' => 'accepted',
        'provider' => 'iyzico',
        'event_id' => 'evt_iyzico_failed_retry_001',
        'duplicate' => false,
    ]);

    $webhookCall = WebhookCall::query()
        ->where('provider', 'iyzico')
        ->where('event_id', 'evt_iyzico_failed_retry_001')
        ->first();

    expect(WebhookCall::query()->count())->toBe(1);
    expect($webhookCall)->not->toBeNull();
    expect((string) $webhookCall?->getAttribute('status'))->toBe('pending');
    expect($webhookCall?->getAttribute('processed_at'))->toBeNull();
    expect($webhookCall?->getAttribute('error_message'))->toBeNull();
    expect((int) ($webhookCall?->getAttribute('payload')['attempt'] ?? 0))->toBe(2);

    Bus::assertDispatchedTimes(FinalizeWebhookEventJob::class, 1);
});

it('rejects webhook requests for unknown providers', function (): void {
    $response = sendWebhook('unknown', [
        'event_id' => 'evt_unknown_001',
        'event_type' => 'payment.success',
    ]);

    $response
        ->assertStatus(404)
        ->assertJson([
            'status' => 'rejected',
            'provider' => 'unknown',
            'reason' => 'Unknown provider.',
        ]);
});

it('derives webhook event id from paytr merchant reference before hash fallback', function (): void {
    $response = sendWebhook('paytr', [
        'merchant_oid' => 'paytr-order-001',
        'event_type' => 'payment.success',
    ]);

    $response->assertOk();
    expect($response->getContent())->toBe('OK');

    $webhookCall = WebhookCall::query()
        ->where('provider', 'paytr')
        ->where('event_id', 'paytr-order-001')
        ->first();

    expect($webhookCall)->not->toBeNull();
});

it('derives webhook event id from iyzico conversation identifier before hash fallback', function (): void {
    $response = sendWebhook('iyzico', [
        'conversationId' => 'iyzico-conv-001',
        'event_type' => 'payment.success',
    ]);

    $response
        ->assertStatus(202)
        ->assertJson([
            'status' => 'accepted',
            'provider' => 'iyzico',
            'event_id' => 'iyzico-conv-001',
            'duplicate' => false,
        ]);
});
