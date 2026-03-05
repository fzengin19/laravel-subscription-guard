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
