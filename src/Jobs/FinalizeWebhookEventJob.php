<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\WebhookCall;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

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

    public function handle(PaymentManager $paymentManager): void
    {
        $lock = cache()->lock('subguard:webhook:'.$this->webhookCallId, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($paymentManager): void {
                $webhookCall = WebhookCall::query()
                    ->lockForUpdate()
                    ->find($this->webhookCallId);

                if (! $webhookCall instanceof WebhookCall) {
                    return;
                }

                $status = (string) $webhookCall->getAttribute('status');

                if (in_array($status, ['processed', 'failed'], true)) {
                    return;
                }

                $provider = (string) $webhookCall->getAttribute('provider');

                if (! $paymentManager->hasProvider($provider)) {
                    $webhookCall->setAttribute('status', 'failed');
                    $webhookCall->setAttribute('error_message', 'Unknown provider.');
                    $webhookCall->setAttribute('processed_at', now());
                    $webhookCall->save();

                    return;
                }

                $webhookCall->setAttribute('status', 'processed');
                $webhookCall->setAttribute('processed_at', now());
                $webhookCall->setAttribute('error_message', null);
                $webhookCall->save();

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
}
