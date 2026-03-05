<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

it('registers webhook simulator command', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('subguard:simulate-webhook');
});

it('simulates paytr webhook and dispatches finalize job', function (): void {
    Bus::fake();

    $exitCode = Artisan::call('subguard:simulate-webhook', [
        'provider' => 'paytr',
        'event' => 'payment.success',
    ]);

    expect($exitCode)->toBe(0);
    expect(WebhookCall::query()->where('provider', 'paytr')->count())->toBe(1);

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});

it('uses explicit event id and keeps duplicate idempotency semantics', function (): void {
    Bus::fake();

    $eventId = 'phase5-sim-event-001';

    $firstCode = Artisan::call('subguard:simulate-webhook', [
        'provider' => 'paytr',
        'event' => 'payment.success',
        '--event-id' => $eventId,
    ]);

    $secondCode = Artisan::call('subguard:simulate-webhook', [
        'provider' => 'paytr',
        'event' => 'payment.success',
        '--event-id' => $eventId,
    ]);

    expect($firstCode)->toBe(0);
    expect($secondCode)->toBe(0);
    expect(WebhookCall::query()->where('provider', 'paytr')->where('event_id', $eventId)->count())->toBe(1);

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});

it('simulates iyzico webhook with generated signature', function (): void {
    Bus::fake();

    config([
        'subscription-guard.providers.drivers.iyzico.mock' => false,
        'subscription-guard.providers.drivers.iyzico.secret_key' => 'secret_phase5_test',
    ]);

    $exitCode = Artisan::call('subguard:simulate-webhook', [
        'provider' => 'iyzico',
        'event' => 'payment.success',
    ]);

    expect($exitCode)->toBe(0);
    expect(WebhookCall::query()->where('provider', 'iyzico')->count())->toBe(1);

    Bus::assertDispatched(FinalizeWebhookEventJob::class, 1);
});
