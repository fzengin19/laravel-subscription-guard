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
use Symfony\Component\HttpFoundation\Response;

final class WebhookController
{
    use ResolvesWebhookEventId;

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

        if ($payload === []) {
            return response()->json(['status' => 'rejected', 'reason' => 'Empty payload.'], 400);
        }

        $maxPayloadKb = (int) config('subscription-guard.webhooks.max_payload_size_kb', 64);

        if ($maxPayloadKb > 0 && strlen($request->getContent() ?: '') > $maxPayloadKb * 1024) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'Payload exceeds maximum size.',
            ], 413);
        }

        try {
            $providerAdapter = $this->paymentManager->provider($provider);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'Provider not available.',
            ], 422);
        }

        $signatureHeader = $this->resolveSignatureHeader($request, $provider);

        if (! $providerAdapter->validateWebhook($payload, $signatureHeader)) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'Invalid webhook signature.',
            ], 401);
        }

        $eventType = $this->resolveEventType($payload);
        $eventId = $this->resolveEventId($provider, $payload, $eventType, $request->getContent());

        if ($eventType === 'unknown') {
            Log::channel(
                (string) config('subscription-guard.logging.webhooks_channel', 'subguard_webhooks')
            )->warning('Webhook received with unknown event type', [
                'provider' => $provider,
                'event_id' => $eventId,
                'payload_keys' => array_keys($payload),
            ]);
        }

        $lockTtl = (int) config('subscription-guard.locks.webhook_lock_ttl', 10);
        $blockTimeout = (int) config('subscription-guard.locks.webhook_block_timeout', 5);
        $lock = cache()->lock('subguard:webhook-intake:'.$provider.':'.$eventId, $lockTtl);

        try {
            $result = $lock->block($blockTimeout, function () use ($provider, $eventId, $eventType, $request, $payload): array {
                return DB::transaction(function () use ($provider, $eventId, $eventType, $request, $payload): array {
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
                                'headers' => $this->filterHeaders($request),
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
                        'headers' => $this->filterHeaders($request),
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
            )->warning('Webhook lock timeout', [
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

        $providerConfig = config("subscription-guard.providers.drivers.{$provider}", []);
        $responseFormat = (string) (is_array($providerConfig) ? ($providerConfig['webhook_response_format'] ?? 'json') : 'json');

        if ($responseFormat === 'text') {
            $body = (string) (is_array($providerConfig) ? ($providerConfig['webhook_response_body'] ?? 'OK') : 'OK');

            return response($body, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json([
            'status' => 'accepted',
            'provider' => $provider,
            'event_id' => $eventId,
            'duplicate' => (bool) ($result['duplicate'] ?? false),
        ], (bool) ($result['duplicate'] ?? false) ? 200 : 202);
    }

    private function resolveSignatureHeader(Request $request, string $provider): string
    {
        $configuredHeader = (string) config("subscription-guard.providers.drivers.{$provider}.webhook_signature_header", '');

        if ($configuredHeader !== '') {
            $value = (string) $request->header($configuredHeader, '');

            if ($value !== '') {
                return $value;
            }
        }

        $fallbackHeaders = [
            'x-iyz-signature',
            'x-iyz-signature-v3',
            'x-paytr-signature',
            'x-webhook-signature',
            'x-signature',
        ];

        foreach ($fallbackHeaders as $header) {
            $value = (string) $request->header($header, '');

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function filterHeaders(Request $request): array
    {
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'set-cookie',
            'proxy-authorization',
        ];

        $headers = $request->headers->all();

        foreach ($sensitiveHeaders as $header) {
            unset($headers[$header]);
        }

        return $headers;
    }

    private function resolveEventType(array $payload): string
    {
        $candidate = $payload['event_type'] ?? $payload['type'] ?? 'unknown';

        return is_scalar($candidate) && (string) $candidate !== ''
            ? (string) $candidate
            : 'unknown';
    }
}
