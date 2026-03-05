<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class PaymentCallbackController
{
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

        $eventId = (string) ($payload['event_id'] ?? $payload['conversationId'] ?? $payload['token'] ?? hash('sha256', $provider.'|'.$eventType.'|'.$request->getContent()));

        $lock = cache()->lock('subguard:callback:'.$provider.':'.$eventId, 10);

        try {
            $result = $lock->block(5, function () use ($provider, $eventType, $eventId, $request, $payload): array {
                return DB::transaction(function () use ($provider, $eventType, $eventId, $request, $payload): array {
                    $existingCall = WebhookCall::query()
                        ->where('provider', $provider)
                        ->where('event_id', $eventId)
                        ->lockForUpdate()
                        ->first();

                    if ($existingCall instanceof WebhookCall) {
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
