<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Concerns;

trait ResolvesWebhookEventId
{
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

    private function normalizeScalarId(mixed $candidate): ?string
    {
        if (! is_scalar($candidate)) {
            return null;
        }

        $value = trim((string) $candidate);

        return $value === '' ? null : $value;
    }
}
