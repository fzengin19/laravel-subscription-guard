<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class ProcessRenewalCandidateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int|string $subscriptionId)
    {
        $this->onQueue((string) config('subscription-guard.queue.queue', 'subguard-main'));
    }

    public function handle(PaymentManager $paymentManager): void
    {
        $lock = cache()->lock('subguard:renewal:'.$this->subscriptionId, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($paymentManager): void {
                $subscription = Subscription::query()
                    ->lockForUpdate()
                    ->find($this->subscriptionId);

                if (! $subscription instanceof Subscription) {
                    return;
                }

                $provider = (string) $subscription->getAttribute('provider');

                if ($paymentManager->managesOwnBilling($provider)) {
                    return;
                }

                $status = (string) $subscription->getAttribute('status');

                if (! in_array($status, ['active', 'trialing'], true)) {
                    return;
                }

                $nextBillingDate = $subscription->getAttribute('next_billing_date');

                if (! $nextBillingDate instanceof CarbonInterface || $nextBillingDate->isFuture()) {
                    return;
                }

                $idempotencyKey = sprintf(
                    'renewal:%s:%s',
                    (string) $subscription->getKey(),
                    $nextBillingDate->format('YmdHis')
                );

                $subscribableType = (string) $subscription->getAttribute('subscribable_type');
                $subscribableId = (int) $subscription->getAttribute('subscribable_id');
                $licenseId = $subscription->getAttribute('license_id');
                $amount = (float) $subscription->getAttribute('amount');
                $taxAmount = (float) $subscription->getAttribute('tax_amount');
                $taxRate = (float) $subscription->getAttribute('tax_rate');
                $currency = (string) $subscription->getAttribute('currency');

                $transaction = Transaction::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'subscription_id' => $subscription->getKey(),
                        'payable_type' => $subscribableType,
                        'payable_id' => $subscribableId,
                        'license_id' => is_numeric($licenseId) ? (int) $licenseId : null,
                        'provider' => $provider,
                        'type' => 'renewal',
                        'status' => 'pending',
                        'amount' => $amount,
                        'tax_amount' => $taxAmount,
                        'tax_rate' => $taxRate,
                        'currency' => $currency,
                    ]
                );

                if (! $transaction->wasRecentlyCreated) {
                    return;
                }

                PaymentChargeJob::dispatch((int) $transaction->getKey())
                    ->onQueue($paymentManager->queueName('queue', 'subguard-main'));
            });
        } finally {
            $lock->release();
        }
    }
}
