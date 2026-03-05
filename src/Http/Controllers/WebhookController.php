<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\FinalizeWebhookEventJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use Symfony\Component\HttpFoundation\Response;

final class WebhookController
{
    public function __construct(private readonly PaymentManager $paymentManager) {}

    public function __invoke(Request $request, string $provider): JsonResponse|Response
    {
        if (! $this->paymentManager->hasProvider($provider)) {
            return response()->json([
                'status' => 'rejected',
                'provider' => $provider,
                'reason' => 'Unknown provider.',
            ], 404);
        }

        $payload = $request->all();
        $eventType = $this->resolveEventType($payload);
        $eventId = $this->resolveEventId($provider, $payload, $eventType, $request->getContent());

        $lock = cache()->lock('subguard:webhook-intake:'.$provider.':'.$eventId, 10);

        try {
            $result = $lock->block(5, function () use ($provider, $eventId, $eventType, $request, $payload): array {
                return DB::transaction(function () use ($provider, $eventId, $eventType, $request, $payload): array {
                    $existingCall = WebhookCall::query()
                        ->where('provider', $provider)
                        ->where('event_id', $eventId)
                        ->lockForUpdate()
                        ->first();

                    if ($existingCall instanceof WebhookCall) {
                        if ((string) $existingCall->getAttribute('status') === 'failed') {
                            $existingCall->setAttribute('event_type', $eventType);
                            $existingCall->setAttribute('idempotency_key', $request->header('x-idempotency-key'));
                            $existingCall->setAttribute('payload', $payload);
                            $existingCall->setAttribute('headers', $request->headers->all());
                            $existingCall->setAttribute('status', 'pending');
                            $existingCall->setAttribute('error_message', null);
                            $existingCall->setAttribute('processed_at', null);
                            $existingCall->save();

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
        } finally {
            $lock->release();
        }

        if (($result['dispatch'] ?? false) === true) {
            FinalizeWebhookEventJob::dispatch((int) $result['webhook_call_id'])
                ->onQueue($this->paymentManager->queueName('webhooks_queue', 'subguard-webhooks'));
        }

        if ($provider === 'paytr') {
            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'status' => 'accepted',
            'provider' => $provider,
            'event_id' => $eventId,
            'duplicate' => (bool) ($result['duplicate'] ?? false),
        ], (bool) ($result['duplicate'] ?? false) ? 200 : 202);
    }

    private function resolveEventId(string $provider, array $payload, string $eventType, string $rawBody): string
    {
        $candidates = [
            $payload['event_id'] ?? null,
            $payload['eventId'] ?? null,
            $payload['id'] ?? null,
            $payload['merchant_oid'] ?? null,
            $payload['reference_no'] ?? null,
            $payload['payment_id'] ?? null,
            $payload['paymentId'] ?? null,
            $payload['conversationId'] ?? null,
            $payload['referenceCode'] ?? null,
            $payload['orderReferenceCode'] ?? null,
            $payload['subscriptionReferenceCode'] ?? null,
            $payload['token'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeScalarId($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return hash('sha256', $provider.'|'.$eventType.'|'.$rawBody);
    }

    private function resolveEventType(array $payload): string
    {
        $candidate = $payload['event_type'] ?? $payload['type'] ?? 'unknown';

        return is_scalar($candidate) && (string) $candidate !== ''
            ? (string) $candidate
            : 'unknown';
    }

    private function normalizeScalarId(mixed $candidate): ?string
    {
        if (! is_scalar($candidate)) {
            return null;
        }

        $value = trim((string) $candidate);

        return $value === '' ? null : $value;
    }
}
