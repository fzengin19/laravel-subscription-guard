<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

it('marks a webhook call as failed', function (): void {
    $call = WebhookCall::query()->create([
        'provider' => 'iyzico',
        'event_type' => 'subscription.renewed',
        'event_id' => 'phase7-webhook-failed',
        'payload' => ['status' => 'pending'],
        'headers' => ['x-signature' => 'abc'],
    ]);

    $call->markFailed('Invalid signature.');

    expect($call->fresh()?->getAttribute('status'))->toBe('failed')
        ->and($call->fresh()?->getAttribute('error_message'))->toBe('Invalid signature.')
        ->and($call->fresh()?->getAttribute('processed_at'))->not->toBeNull();
});

it('marks a webhook call as processed and clears or replaces the message', function (): void {
    $call = WebhookCall::query()->create([
        'provider' => 'iyzico',
        'event_type' => 'subscription.renewed',
        'event_id' => 'phase7-webhook-processed',
        'status' => 'failed',
        'error_message' => 'Old failure',
    ]);

    $call->markProcessed();

    expect($call->fresh()?->getAttribute('status'))->toBe('processed')
        ->and($call->fresh()?->getAttribute('error_message'))->toBeNull()
        ->and($call->fresh()?->getAttribute('processed_at'))->not->toBeNull();

    $call->markProcessed('Accepted as no-op.');

    expect($call->fresh()?->getAttribute('error_message'))->toBe('Accepted as no-op.');
});

it('resets a failed webhook call for retry', function (): void {
    $call = WebhookCall::query()->create([
        'provider' => 'paytr',
        'event_type' => 'payment.failed',
        'event_id' => 'phase7-webhook-retry',
        'idempotency_key' => 'old-key',
        'payload' => ['old' => true],
        'headers' => ['old' => 'header'],
        'status' => 'failed',
        'error_message' => 'Old failure',
        'processed_at' => now(),
    ]);

    $call->resetForRetry([
        'event_type' => 'payment.success',
        'idempotency_key' => 'new-key',
        'payload' => ['new' => true],
        'headers' => ['new' => 'header'],
    ]);

    $fresh = $call->fresh();

    expect($fresh?->getAttribute('status'))->toBe('pending')
        ->and($fresh?->getAttribute('error_message'))->toBeNull()
        ->and($fresh?->getAttribute('processed_at'))->toBeNull()
        ->and($fresh?->getAttribute('event_type'))->toBe('payment.success')
        ->and($fresh?->getAttribute('idempotency_key'))->toBe('new-key')
        ->and($fresh?->getAttribute('payload'))->toBe(['new' => true])
        ->and($fresh?->getAttribute('headers'))->toBe(['new' => 'header']);
});
