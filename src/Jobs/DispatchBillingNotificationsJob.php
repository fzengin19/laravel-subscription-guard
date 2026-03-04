<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DispatchBillingNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $event, public array $payload = [])
    {
        $this->onQueue((string) config('subscription-guard.queue.notifications_queue', 'subguard-notifications'));
    }

    public function handle(): void
    {
        $channel = str_starts_with($this->event, 'webhook.')
            ? (string) config('subscription-guard.logging.webhooks_channel', 'subguard_webhooks')
            : (string) config('subscription-guard.logging.payments_channel', 'subguard_payments');

        Log::channel($channel)->info('SubGuard notification event dispatched.', [
            'event' => $this->event,
            'payload' => $this->payload,
        ]);
    }
}
