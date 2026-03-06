<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class ProcessDunningRetryJob implements ShouldQueue
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
        $lock = cache()->lock('subguard:dunning:'.$this->transactionId, 30);

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

                if (! in_array($status, ['failed', 'retrying'], true)) {
                    return;
                }

                $retryCount = (int) $transaction->getAttribute('retry_count');

                if ($retryCount >= 3) {
                    return;
                }

                $transaction->markRetrying();

                PaymentChargeJob::dispatch((int) $transaction->getKey())
                    ->onQueue($paymentManager->queueName('queue', 'subguard-main'));
            });
        } finally {
            $lock->release();
        }
    }
}
