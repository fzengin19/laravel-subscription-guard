<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\Invoices\InvoicePdfRenderer;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Notifications\InvoicePaidNotification;
use SubscriptionGuard\LaravelSubscriptionGuard\Notifications\SubscriptionCancelledNotification;

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

    public function handle(InvoicePdfRenderer $invoicePdfRenderer): void
    {
        $channel = str_starts_with($this->event, 'webhook.')
            ? (string) config('subscription-guard.logging.webhooks_channel', 'subguard_webhooks')
            : (string) config('subscription-guard.logging.payments_channel', 'subguard_payments');

        Log::channel($channel)->info('SubGuard notification event dispatched.', [
            'event' => $this->event,
            'payload' => $this->payload,
        ]);

        if ($this->event === 'payment.completed') {
            $this->handlePaymentCompleted($invoicePdfRenderer);

            return;
        }

        if ($this->event === 'subscription.cancelled') {
            $this->handleSubscriptionCancelled();
        }
    }

    private function handlePaymentCompleted(InvoicePdfRenderer $invoicePdfRenderer): void
    {
        $transactionId = (int) ($this->payload['transaction_id'] ?? 0);

        if ($transactionId <= 0) {
            return;
        }

        $transaction = Transaction::query()->find($transactionId);

        if (! $transaction instanceof Transaction) {
            return;
        }

        $invoice = Invoice::query()->firstOrCreate(
            ['transaction_id' => $transaction->getKey()],
            [
                'invoice_number' => $this->invoiceNumber($transaction),
                'subscribable_type' => (string) $transaction->getAttribute('payable_type'),
                'subscribable_id' => (int) $transaction->getAttribute('payable_id'),
                'status' => 'paid',
                'issue_date' => now(),
                'due_date' => now(),
                'paid_at' => now(),
                'subtotal' => (float) $transaction->getAttribute('amount'),
                'tax_amount' => (float) $transaction->getAttribute('tax_amount'),
                'total_amount' => (float) $transaction->getAttribute('amount') + (float) $transaction->getAttribute('tax_amount'),
                'currency' => (string) $transaction->getAttribute('currency'),
                'metadata' => ['generated_by' => 'dispatch-billing-notifications-job'],
            ]
        );

        if ((string) ($invoice->getAttribute('pdf_path') ?? '') === '') {
            $invoice->setAttribute('pdf_path', $invoicePdfRenderer->render($invoice));
            $invoice->save();
        }

        $notifiable = $this->resolveNotifiable(
            (string) $transaction->getAttribute('payable_type'),
            (int) $transaction->getAttribute('payable_id')
        );

        if ($notifiable instanceof Model && method_exists($notifiable, 'notify')) {
            $notifiable->notify(new InvoicePaidNotification(
                invoiceNumber: (string) $invoice->getAttribute('invoice_number'),
                amount: (float) $invoice->getAttribute('total_amount'),
                currency: (string) $invoice->getAttribute('currency'),
                pdfPath: (string) $invoice->getAttribute('pdf_path'),
            ));
        }
    }

    private function handleSubscriptionCancelled(): void
    {
        $subscriptionId = (int) ($this->payload['subscription_id'] ?? 0);

        if ($subscriptionId <= 0) {
            return;
        }

        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $notifiable = $this->resolveNotifiable(
            (string) $subscription->getAttribute('subscribable_type'),
            (int) $subscription->getAttribute('subscribable_id')
        );

        if ($notifiable instanceof Model && method_exists($notifiable, 'notify')) {
            $notifiable->notify(new SubscriptionCancelledNotification(
                provider: (string) ($this->payload['provider'] ?? $subscription->getAttribute('provider') ?? ''),
                providerSubscriptionId: (string) ($subscription->getAttribute('provider_subscription_id') ?? ''),
            ));
        }
    }

    private function resolveNotifiable(string $type, int $id): ?Model
    {
        if ($type === '' || $id <= 0 || ! class_exists($type) || ! is_subclass_of($type, Model::class)) {
            return null;
        }

        $model = $type::query()->find($id);

        return $model instanceof Model ? $model : null;
    }

    private function invoiceNumber(Transaction $transaction): string
    {
        return 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)).'-'.$transaction->getKey();
    }
}
