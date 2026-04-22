<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\DunningExhausted;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

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

    public function handle(PaymentManager $paymentManager, SubscriptionService $subscriptionService): void
    {
        $lock = cache()->lock('subguard:dunning:'.$this->transactionId, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($paymentManager, $subscriptionService): void {
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

                $provider = (string) $transaction->getAttribute('provider');

                if ($provider !== '' && $paymentManager->managesOwnBilling($provider)) {
                    $transaction->setAttribute('next_retry_at', null);
                    $transaction->save();

                    Log::channel((string) config('subscription-guard.logging.payments_channel', 'subguard_payments'))
                        ->warning('Dunning retry skipped for provider-managed transaction.', [
                            'provider' => $provider,
                            'transaction_id' => $transaction->getKey(),
                        ]);

                    return;
                }

                $retryCount = (int) $transaction->getAttribute('retry_count');
                $maxRetries = (int) config('subscription-guard.billing.max_dunning_retries', 3);

                if ($retryCount >= $maxRetries) {
                    $this->handleDunningExhaustion($transaction, $paymentManager, $subscriptionService);

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

    private function handleDunningExhaustion(Transaction $transaction, PaymentManager $paymentManager, SubscriptionService $subscriptionService): void
    {
        $transaction->setAttribute('next_retry_at', null);
        $transaction->save();

        $subscriptionId = $transaction->getAttribute('subscription_id');
        $provider = (string) $transaction->getAttribute('provider');

        if (is_numeric($subscriptionId)) {
            $subscription = Subscription::query()
                ->lockForUpdate()
                ->find((int) $subscriptionId);

            if ($subscription instanceof Subscription) {
                if ($subscriptionService->applySubscriptionStatus($subscription, SubscriptionStatus::Suspended, 'dunning.exhausted')) {
                    $subscription->save();

                    $licenseId = $subscription->getAttribute('license_id');

                    if (is_numeric($licenseId)) {
                        $license = License::query()
                            ->lockForUpdate()
                            ->find((int) $licenseId);

                        if ($license instanceof License) {
                            $license->setAttribute('status', SubscriptionStatus::Suspended->value);
                            $license->save();
                        }
                    }
                }
            }
        }

        Event::dispatch(new DunningExhausted(
            provider: $provider,
            subscriptionId: $subscriptionId ?? 0,
            transactionId: $transaction->getKey(),
            retryCount: (int) $transaction->getAttribute('retry_count'),
            lastFailureReason: (string) ($transaction->getAttribute('failure_reason') ?? ''),
        ));

        DispatchBillingNotificationsJob::dispatch('dunning.exhausted', [
            'provider' => $provider,
            'subscription_id' => $subscriptionId,
            'transaction_id' => $transaction->getKey(),
            'retry_count' => (int) $transaction->getAttribute('retry_count'),
        ])->onQueue($paymentManager->queueName('notifications_queue', 'subguard-notifications'));

        Log::channel(
            (string) config('subscription-guard.logging.payments_channel', 'subguard_payments')
        )->warning('Dunning exhausted', [
            'subscription_id' => $subscriptionId,
            'transaction_id' => $transaction->getKey(),
            'retry_count' => (int) $transaction->getAttribute('retry_count'),
        ]);
    }
}
