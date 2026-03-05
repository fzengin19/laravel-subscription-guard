<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

final class SubscriptionCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $provider,
        private readonly string $providerSubscriptionId,
    ) {
        $this->onQueue((string) config('subscription-guard.queue.notifications_queue', 'subguard-notifications'));
    }

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (Schema::hasTable('notifications') && method_exists($notifiable, 'getKey')) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $recipientType = get_class($notifiable);

        return (new MailMessage)
            ->subject('Subscription cancelled')
            ->line('Your subscription has been cancelled.')
            ->line('Provider: '.$this->provider)
            ->line('Reference: '.$this->providerSubscriptionId)
            ->line('Recipient type: '.$recipientType);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription.cancelled',
            'recipient_type' => get_class($notifiable),
            'provider' => $this->provider,
            'provider_subscription_id' => $this->providerSubscriptionId,
        ];
    }
}
