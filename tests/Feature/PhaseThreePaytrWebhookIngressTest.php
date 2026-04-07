<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;

it('returns plain OK for paytr webhook ingress and queues finalization', function (): void {
    config()->set('subscription-guard.providers.drivers.paytr.mock', true);
    Queue::fake();

    $request = Request::create('/subguard/webhooks/paytr', 'POST', [
        'merchant_oid' => 'paytr_oid_3001',
        'status' => 'success',
        'total_amount' => '1000',
        'hash' => 'fake_hash',
    ]);

    $response = app()->make(HttpKernel::class)->handle($request);

    expect($response->getStatusCode())->toBe(200);
    expect(trim((string) $response->getContent()))->toBe('OK');

    $webhook = WebhookCall::query()->where('provider', 'paytr')->first();
    expect($webhook)->not->toBeNull();

    Queue::assertPushed(FinalizeWebhookEventJob::class);
});
