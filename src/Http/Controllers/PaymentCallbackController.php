<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Concerns\ResolvesWebhookEventId;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class PaymentCallbackController
{
    use ResolvesWebhookEventId;

    public function __construct(private readonly PaymentManager $paymentManager) {}

    public function threeDs(Request $request, string $provider): JsonResponse
    {
        return $this->storeCallback($request, $provider, 'payment.3ds.callback');
    }

    public function checkout(Request $request, string $provider): JsonResponse
    {
        return $this->storeCallback($request, $provider, 'payment.checkout.callback');
    }

    private function storeCallback(Request $request, string $provider, string $eventType): JsonResponse
    {
        if (! $this->paymentManager->hasProvider($provider)) {
            return response()->json([
                'status' => 'rejected',
                'provider' => $provider,
                'reason' => 'Unknown provider.',
            ], 404);
        }

        $payload = $request->all();

        if ($payload === []) {
            return response()->json(['error' => 'Empty payload'], 400);
        }

        $providerAdapter = $this->paymentManager->provider($provider);
        $signatureHeader = (string) config('subscription-guard.providers.drivers.'.$provider.'.signature_header', 'x-iyz-signature-v3');
        $signature = (string) $request->header($signatureHeader, '');

        if (! $providerAdapter->validateWebhook($payload, $signature)) {
            return response()->json([
                'status' => 'rejected',
                'provider' => $provider,
                'reason' => 'Invalid callback signature.',
            ], 401);
        }

        $eventId = $this->resolveEventId($provider, $payload, $eventType, $request->getContent());

        $lockTtl = (int) config('subscription-guard.locks.callback_lock_ttl', 10);
        $blockTimeout = (int) config('subscription-guard.locks.callback_block_timeout', 5);
        $lock = cache()->lock('subguard:callback:'.$provider.':'.$eventId, $lockTtl);

        try {
            $result = $lock->block($blockTimeout, function () use ($provider, $eventType, $eventId, $request, $payload): array {
                return DB::transaction(function () use ($provider, $eventType, $eventId, $request, $payload): array {
                    $existingCall = WebhookCall::query()
                        ->where('provider', $provider)
                        ->where('event_id', $eventId)
                        ->lockForUpdate()
                        ->first();

                    if ($existingCall instanceof WebhookCall) {
                        if ((string) $existingCall->getAttribute('status') === 'failed') {
                            $existingCall->resetForRetry([
                                'event_type' => $eventType,
                                'idempotency_key' => $request->header('x-idempotency-key'),
                                'payload' => $payload,
                                'headers' => $request->headers->all(),
                            ]);

                            return [
                                'duplicate' => false,
                                'dispatch' => true,
                                'webhook_call_id' => (int) $existingCall->getKey(),
                            ];
                        }

                        return [
                            'duplicate' => true,
                            'dispatch' => false,
                            'webhook_call_id' => (int) $existingCall->getKey(),
                        ];
                    }

                    $webhookCall = WebhookCall::query()->create([
                        'provider' => $provider,
                        'event_type' => $eventType,
                        'event_id' => $eventId,
                        'idempotency_key' => $request->header('x-idempotency-key'),
                        'payload' => $payload,
                        'headers' => $request->headers->all(),
                        'status' => 'pending',
                    ]);

                    return [
                        'duplicate' => false,
                        'dispatch' => true,
                        'webhook_call_id' => (int) $webhookCall->getKey(),
                    ];
                });
            });
        } catch (LockTimeoutException) {
            Log::channel(
                (string) config('subscription-guard.logging.webhooks_channel', 'subguard_webhooks')
            )->warning('Callback lock timeout', [
                'provider' => $provider,
                'event_id' => $eventId,
            ]);

            return response()->json([
                'status' => 'retry',
                'message' => 'Server busy, please retry',
            ], 503);
        } finally {
            $lock->release();
        }

        if (($result['dispatch'] ?? false) === true) {
            FinalizeWebhookEventJob::dispatch((int) $result['webhook_call_id'])
                ->onQueue($this->paymentManager->queueName('webhooks_queue', 'subguard-webhooks'));
        }

        return response()->json([
            'status' => 'accepted',
            'provider' => $provider,
            'event_id' => $eventId,
            'duplicate' => (bool) ($result['duplicate'] ?? false),
        ], (bool) ($result['duplicate'] ?? false) ? 200 : 202);
    }
}
