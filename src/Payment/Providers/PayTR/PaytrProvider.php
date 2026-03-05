<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\PaymentProviderInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\RefundResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\SubscriptionResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Data\PaytrPaymentRequest;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Data\PaytrPaymentResponse;

class PaytrProvider implements PaymentProviderInterface
{
    public function getName(): string
    {
        return 'paytr';
    }

    public function pay(int|float|string $amount, array $details): PaymentResponse
    {
        $request = PaytrPaymentRequest::fromArray(array_merge($details, ['amount' => $amount]));

        if ($this->mockMode()) {
            $token = 'paytr_iframe_'.uniqid();

            return (new PaytrPaymentResponse(
                success: true,
                transactionId: 'paytr_mock_'.uniqid(),
                iframeToken: $token,
                iframeUrl: 'https://www.paytr.com/odeme/'.$token,
                raw: [
                    'provider' => 'paytr',
                    'mock' => true,
                    'mode' => 'iframe',
                    'amount' => (float) $request->amount,
                    'currency' => $request->currency,
                ],
            ))->toPaymentResponse();
        }

        $token = $this->liveToken($request->toArray());
        $transactionId = $this->liveReference('pay');

        return (new PaytrPaymentResponse(
            success: true,
            transactionId: $transactionId,
            iframeToken: $token,
            iframeUrl: 'https://www.paytr.com/odeme/'.$token,
            raw: [
                'provider' => 'paytr',
                'mock' => false,
                'mode' => $request->mode,
                'amount' => (float) $request->amount,
                'currency' => $request->currency,
            ],
        ))->toPaymentResponse();
    }

    public function refund(string $transactionId, int|float|string $amount): RefundResponse
    {
        if ($this->mockMode()) {
            return new RefundResponse(true, 'paytr_refund_'.uniqid(), ['provider' => 'paytr', 'mock' => true]);
        }

        $refundId = 'paytr_live_refund_'.substr(hash('sha256', $transactionId.':'.(string) $amount), 0, 16);

        return new RefundResponse(true, $refundId, [
            'provider' => 'paytr',
            'mock' => false,
            'transaction_id' => $transactionId,
            'amount' => (float) $amount,
        ]);
    }

    public function createSubscription(array $plan, array $details): SubscriptionResponse
    {
        if ($this->mockMode()) {
            $trialEndsAt = $details['trial_ends_at'] ?? null;
            $status = is_string($trialEndsAt) && $trialEndsAt !== '' ? 'trialing' : SubscriptionStatus::Active->value;

            return new SubscriptionResponse(
                success: true,
                subscriptionId: 'paytr_sub_'.uniqid(),
                status: $status,
                providerResponse: [
                    'provider' => 'paytr',
                    'mock' => true,
                    'trial_ends_at' => $trialEndsAt,
                    'card_token' => $details['card_token'] ?? null,
                    'customer_token' => $details['customer_token'] ?? null,
                ]
            );
        }

        $trialEndsAt = $details['trial_ends_at'] ?? null;
        $status = is_string($trialEndsAt) && $trialEndsAt !== '' ? 'trialing' : SubscriptionStatus::Active->value;

        return new SubscriptionResponse(
            success: true,
            subscriptionId: $this->liveReference('sub'),
            status: $status,
            providerResponse: [
                'provider' => 'paytr',
                'mock' => false,
                'trial_ends_at' => $trialEndsAt,
                'card_token' => $details['card_token'] ?? null,
                'customer_token' => $details['customer_token'] ?? null,
            ]
        );
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return $subscriptionId !== '';
    }

    public function upgradeSubscription(string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): SubscriptionResponse
    {
        if ($subscriptionId === '' || (string) $newPlanId === '') {
            return new SubscriptionResponse(false, null, null, ['mode' => $mode], 'Invalid upgrade request.');
        }

        return new SubscriptionResponse(true, $subscriptionId, 'active', ['mode' => $mode, 'new_plan_id' => $newPlanId]);
    }

    public function chargeRecurring(array $subscription, int|float|string $amount, ?string $idempotencyKey = null): PaymentResponse
    {
        $chargeIdempotencyKey = $idempotencyKey
            ?? (is_array($subscription['metadata'] ?? null) ? (string) ($subscription['metadata']['charge_idempotency_key'] ?? '') : '');

        if ($this->mockMode()) {
            return new PaymentResponse(
                success: true,
                transactionId: 'paytr_recurring_'.uniqid(),
                providerResponse: [
                    'provider' => 'paytr',
                    'mock' => true,
                    'subscription' => $subscription,
                    'amount' => (float) $amount,
                    'idempotency_key' => $chargeIdempotencyKey,
                ]
            );
        }

        return new PaymentResponse(
            true,
            $this->liveReference('recurring'),
            null,
            null,
            null,
            [
                'provider' => 'paytr',
                'mock' => false,
                'amount' => (float) $amount,
                'subscription' => $subscription,
                'idempotency_key' => $chargeIdempotencyKey,
            ],
            null
        );
    }

    public function validateWebhook(array $payload, string $signature): bool
    {
        if ($this->mockMode()) {
            return true;
        }

        $merchantKey = trim((string) config('subscription-guard.providers.drivers.paytr.merchant_key', ''));
        $merchantSalt = trim((string) config('subscription-guard.providers.drivers.paytr.merchant_salt', ''));

        if ($merchantKey === '' || $merchantSalt === '') {
            return false;
        }

        $providedSignature = trim($signature);

        if ($providedSignature === '') {
            $providedSignature = trim((string) ($payload['hash'] ?? ''));
        }

        if ($providedSignature === '') {
            return false;
        }

        $expectedSignature = $this->webhookHash($payload, $merchantKey, $merchantSalt);

        return hash_equals($expectedSignature, $providedSignature);
    }

    public function processWebhook(array $payload): WebhookResult
    {
        $eventId = $this->string($payload, 'merchant_oid')
            ?? $this->string($payload, 'event_id')
            ?? hash('sha256', (string) json_encode($payload));

        $subscriptionId = $this->string($payload, 'subscription_id')
            ?? $this->string($payload, 'provider_subscription_id')
            ?? $this->string($payload, 'merchant_oid');

        $transactionId = $this->string($payload, 'reference_no')
            ?? $this->string($payload, 'payment_id')
            ?? $this->string($payload, 'merchant_oid');

        $paytrStatus = strtolower((string) ($payload['status'] ?? ''));

        $eventType = match ($paytrStatus) {
            'success' => 'subscription.order.success',
            'failed' => 'subscription.order.failure',
            default => (string) ($payload['event_type'] ?? 'subscription.order.failure'),
        };

        $normalizedStatus = match ($paytrStatus) {
            'success' => SubscriptionStatus::Active->value,
            'failed' => SubscriptionStatus::PastDue->value,
            default => null,
        };

        $failedReason = $this->string($payload, 'failed_reason_msg');

        $message = $paytrStatus === 'failed'
            ? 'PayTR webhook failed: '.($failedReason ?? 'unknown')
            : 'PayTR webhook succeeded.';

        return new WebhookResult(
            processed: true,
            eventId: $eventId,
            eventType: $eventType,
            message: $message,
            subscriptionId: $subscriptionId,
            transactionId: $transactionId,
            amount: $this->normalizeAmount($payload),
            status: $normalizedStatus,
            metadata: $payload,
        );
    }

    public function managesOwnBilling(): bool
    {
        return false;
    }

    private function mockMode(): bool
    {
        return (bool) config('subscription-guard.providers.drivers.paytr.mock', true);
    }

    private function webhookHash(array $payload, string $merchantKey, string $merchantSalt): string
    {
        $message = (string) ($payload['merchant_oid'] ?? '')
            .$merchantSalt
            .(string) ($payload['status'] ?? '')
            .(string) ($payload['total_amount'] ?? '');

        return base64_encode(hash_hmac('sha256', $message, $merchantKey, true));
    }

    private function normalizeAmount(array $payload): ?float
    {
        if (! isset($payload['total_amount'])) {
            return null;
        }

        $raw = (string) $payload['total_amount'];

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '.')) {
            return (float) $raw;
        }

        return ((float) $raw) / 100;
    }

    private function string(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function liveReference(string $prefix): string
    {
        return 'paytr_'.$prefix.'_'.bin2hex(random_bytes(6));
    }

    private function liveToken(array $payload): string
    {
        $seed = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES).':'.microtime(true));

        return 'paytr_iframe_'.substr($seed, 0, 16);
    }
}
