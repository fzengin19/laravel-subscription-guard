<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class PaymentChargeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int|string $transactionId)
    {
        $this->onQueue((string) config('subscription-guard.queue.queue', 'subguard-main'));
    }

    public function handle(PaymentManager $paymentManager): void
    {
        $lock = cache()->lock('subguard:charge:'.$this->transactionId, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($paymentManager): void {
                $transaction = Transaction::query()
                    ->lockForUpdate()
                    ->find($this->transactionId);

                if (! $transaction instanceof Transaction) {
                    return;
                }

                $status = (string) $transaction->getAttribute('status');

                if (in_array($status, ['processed', 'succeeded', 'refunded'], true)) {
                    return;
                }

                $retryCount = (int) $transaction->getAttribute('retry_count') + 1;

                $transaction->setAttribute('retry_count', $retryCount);
                $transaction->setAttribute('status', 'failed');
                $transaction->setAttribute('failure_reason', 'Payment provider adapter is not implemented yet.');
                $transaction->setAttribute('last_retry_at', now());
                $transaction->setAttribute('next_retry_at', $this->nextRetryDate($retryCount));
                $transaction->setAttribute('processed_at', now());
                $transaction->save();

                $subscription = $transaction->subscription()->first();

                if ($subscription instanceof Subscription && in_array((string) $subscription->getAttribute('status'), ['active', 'trialing'], true)) {
                    $subscription->setAttribute('status', 'past_due');

                    if ($subscription->getAttribute('grace_ends_at') === null) {
                        $subscription->setAttribute('grace_ends_at', now()->addDays(7));
                    }

                    $subscription->save();
                }

                $provider = (string) $transaction->getAttribute('provider');

                DispatchBillingNotificationsJob::dispatch('payment.failed', [
                    'transaction_id' => $transaction->getKey(),
                    'subscription_id' => $subscription?->getKey(),
                    'provider' => $provider,
                    'retry_count' => $retryCount,
                ])->onQueue($paymentManager->queueName('notifications_queue', 'subguard-notifications'));
            });
        } finally {
            $lock->release();
        }
    }

    private function nextRetryDate(int $retryCount): ?Carbon
    {
        $retryDays = [2, 5, 7];

        if (! isset($retryDays[$retryCount - 1])) {
            return null;
        }

        return now()->addDays($retryDays[$retryCount - 1]);
    }
}
