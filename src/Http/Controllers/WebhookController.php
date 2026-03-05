<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $eventId = $this->resolveEventId($provider, $payload, $request->getContent());
        $eventType = $this->resolveEventType($payload);

        $existingCall = WebhookCall::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->first();

        if ($existingCall instanceof WebhookCall) {
            if ($provider === 'paytr') {
                return response('OK', 200)->header('Content-Type', 'text/plain');
            }

            return response()->json([
                'status' => 'accepted',
                'provider' => $provider,
                'event_id' => $eventId,
                'duplicate' => true,
            ]);
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

        FinalizeWebhookEventJob::dispatch((int) $webhookCall->getKey())
            ->onQueue($this->paymentManager->queueName('webhooks_queue', 'subguard-webhooks'));

        if ($provider === 'paytr') {
            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'status' => 'accepted',
            'provider' => $provider,
            'event_id' => $eventId,
            'duplicate' => false,
        ], 202);
    }

    private function resolveEventId(string $provider, array $payload, string $rawBody): string
    {
        $candidate = $payload['event_id'] ?? $payload['id'] ?? null;

        if (is_scalar($candidate) && (string) $candidate !== '') {
            return (string) $candidate;
        }

        return hash('sha256', $provider.'|'.$rawBody);
    }

    private function resolveEventType(array $payload): string
    {
        $candidate = $payload['event_type'] ?? $payload['type'] ?? 'unknown';

        return is_scalar($candidate) && (string) $candidate !== ''
            ? (string) $candidate
            : 'unknown';
    }
}
