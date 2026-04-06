<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\ProviderException;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

final class FinalizeWebhookEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int|string $webhookCallId)
    {
        $this->onQueue((string) config('subscription-guard.queue.webhooks_queue', 'subguard-webhooks'));
    }

    public function handle(PaymentManager $paymentManager, ?SubscriptionService $subscriptionService = null): void
    {
        $subscriptionService ??= app(SubscriptionService::class);

        $lock = cache()->lock('subguard:webhook:'.$this->webhookCallId, 30);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            DB::transaction(function () use ($paymentManager, $subscriptionService): void {
                $webhookCall = WebhookCall::query()
                    ->lockForUpdate()
                    ->find($this->webhookCallId);

                if (! $webhookCall instanceof WebhookCall) {
                    return;
                }

                $status = (string) $webhookCall->getAttribute('status');

                if ($status === 'processed') {
                    return;
                }

                $provider = (string) $webhookCall->getAttribute('provider');

                if (! $paymentManager->hasProvider($provider)) {
                    $webhookCall->markFailed('Unknown provider.');

                    return;
                }

                try {
                    $providerAdapter = $paymentManager->provider($provider);
                } catch (ProviderException) {
                    $webhookCall->markFailed('Provider adapter not configured.');

                    return;
                }

                $payload = $webhookCall->getAttribute('payload');
                $normalizedPayload = is_array($payload) ? $payload : [];
                $signature = $this->extractSignature($provider, $webhookCall->getAttribute('headers'));

                if (! $providerAdapter->validateWebhook($normalizedPayload, $signature)) {
                    $webhookCall->markFailed('Invalid webhook signature.');

                    return;
                }

                $result = $providerAdapter->processWebhook($normalizedPayload);

                if (! $result->processed) {
                    $webhookCall->markFailed($result->message ?? 'Webhook processing failed.');

                    return;
                }

                $webhookCall->markProcessed($result->message);

                $subscriptionService->handleWebhookResult($result, $provider);

                $eventId = (string) $webhookCall->getAttribute('event_id');

                DispatchBillingNotificationsJob::dispatch('webhook.processed', [
                    'webhook_call_id' => $webhookCall->getKey(),
                    'provider' => $provider,
                    'event_id' => $eventId,
                ])->onQueue($paymentManager->queueName('notifications_queue', 'subguard-notifications'));
            });
        } finally {
            $lock->release();
        }
    }

    private function extractSignature(string $provider, mixed $headers): string
    {
        $normalized = is_array($headers) ? $headers : [];
        $signatureHeader = strtolower((string) config('subscription-guard.providers.drivers.'.$provider.'.signature_header', 'x-iyz-signature-v3'));

        foreach ($normalized as $key => $value) {
            if (strtolower((string) $key) !== $signatureHeader) {
                continue;
            }

            if (is_array($value)) {
                $first = $value[0] ?? '';

                return is_scalar($first) ? (string) $first : '';
            }

            return is_scalar($value) ? (string) $value : '';
        }

        return '';
    }
}
