<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

final class InvoicePaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $invoiceNumber,
        private readonly float $amount,
        private readonly string $currency,
        private readonly ?string $pdfPath = null,
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

        $message = (new MailMessage)
            ->subject('Invoice paid: '.$this->invoiceNumber)
            ->line('Your subscription invoice has been paid successfully.')
            ->line('Invoice: '.$this->invoiceNumber)
            ->line('Amount: '.number_format($this->amount, 2).' '.$this->currency)
            ->line('Recipient type: '.$recipientType);

        if ($this->pdfPath !== null && $this->pdfPath !== '') {
            $message->line('PDF: '.$this->pdfPath);
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'invoice.paid',
            'recipient_type' => get_class($notifiable),
            'invoice_number' => $this->invoiceNumber,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'pdf_path' => $this->pdfPath,
        ];
    }
}
