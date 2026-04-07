<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewed;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

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

    public function handle(PaymentManager $paymentManager, SubscriptionService $subscriptionService): void
    {
        $lock = cache()->lock('subguard:charge:'.$this->transactionId, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $preparedCharge = $this->prepareChargePayload();

            if ($preparedCharge === null) {
                return;
            }

            $providerAdapter = $paymentManager->provider($preparedCharge['provider']);
            $response = $providerAdapter->chargeRecurring(
                $preparedCharge['charge_payload'],
                $preparedCharge['amount'],
                $preparedCharge['idempotency_key']
            );

            DB::transaction(function () use ($paymentManager, $subscriptionService, $response): void {
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

                $subscription = Subscription::query()->withTrashed()->find($transaction->getAttribute('subscription_id'));

                if (! $subscription instanceof Subscription) {
                    return;
                }

                $provider = (string) $transaction->getAttribute('provider');

                if ($response->success) {
                    $transaction->markProcessed(
                        $response->transactionId,
                        $response->providerResponse,
                        true,
                    );

                    $amount = (float) $transaction->getAttribute('amount');

                    $subscription->setAttribute('status', SubscriptionStatus::Active->value);
                    $subscription->setAttribute('grace_ends_at', null);

                    $nextBillingDate = $subscription->getAttribute('next_billing_date');
                    $billingPeriod = (string) ($subscription->getAttribute('billing_period') ?: 'month');
                    $billingInterval = max(1, (int) ($subscription->getAttribute('billing_interval') ?: 1));
                    $anchorDay = is_numeric($subscription->getAttribute('billing_anchor_day'))
                        ? (int) $subscription->getAttribute('billing_anchor_day')
                        : null;

                    if ($nextBillingDate instanceof Carbon) {
                        $next = $subscriptionService->advanceBillingDate($nextBillingDate, $anchorDay, $billingPeriod, $billingInterval);
                        $subscription->setAttribute('next_billing_date', $next);
                    } else {
                        $subscription->setAttribute('next_billing_date', now()->addMonth());
                    }

                    $subscription->save();

                    if (is_numeric($transaction->getAttribute('discount_id'))) {
                        $subscriptionService->markDiscountApplied((int) $transaction->getAttribute('discount_id'));
                    }

                    Event::dispatch(new PaymentCompleted($provider, $subscription->getKey(), $transaction->getKey(), $amount, $response->transactionId, $response->providerResponse));
                    Event::dispatch(new SubscriptionRenewed($provider, (string) $subscription->getAttribute('provider_subscription_id'), $subscription->getKey(), $amount, $response->transactionId, $response->providerResponse));

                    DispatchBillingNotificationsJob::dispatch('payment.completed', [
                        'transaction_id' => $transaction->getKey(),
                        'subscription_id' => $subscription->getKey(),
                        'provider' => $provider,
                        'amount' => $amount,
                    ])->onQueue($paymentManager->queueName('notifications_queue', 'subguard-notifications'));

                    return;
                }

                $retryCount = (int) $transaction->getAttribute('retry_count') + 1;
                $failureReason = (string) ($response->failureReason ?? 'Provider recurring charge failed.');
                $terminalFailure = $this->isTerminalFailure($failureReason);

                if ($terminalFailure) {
                    $retryCount = 3;
                }

                $transaction->markFailed(
                    $failureReason,
                    $retryCount,
                    $terminalFailure ? null : $this->nextRetryDate($retryCount),
                    $response->providerResponse,
                    true,
                );

                if (in_array((string) $subscription->getAttribute('status'), [SubscriptionStatus::Active->value, 'trialing'], true)) {
                    $subscription->setAttribute('status', SubscriptionStatus::PastDue->value);

                    if ($subscription->getAttribute('grace_ends_at') === null) {
                        $subscription->setAttribute('grace_ends_at', now()->addDays((int) config('subscription-guard.billing.grace_period_days', 7)));
                    }

                    $subscription->save();
                }

                DispatchBillingNotificationsJob::dispatch('payment.failed', [
                    'transaction_id' => $transaction->getKey(),
                    'subscription_id' => $subscription->getKey(),
                    'provider' => $provider,
                    'retry_count' => $retryCount,
                ])->onQueue($paymentManager->queueName('notifications_queue', 'subguard-notifications'));
            });
        } finally {
            $lock->release();
        }
    }

    private function prepareChargePayload(): ?array
    {
        return DB::transaction(function (): ?array {
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->find($this->transactionId);

            if (! $transaction instanceof Transaction) {
                return null;
            }

            $status = (string) $transaction->getAttribute('status');

            if (in_array($status, ['processed', 'succeeded', 'refunded', 'processing'], true)) {
                return null;
            }

            $subscription = Subscription::query()->withTrashed()->find($transaction->getAttribute('subscription_id'));

            if (! $subscription instanceof Subscription) {
                return null;
            }

            if ($subscription->trashed()) {
                $retryCount = (int) $transaction->getAttribute('retry_count') + 1;

                $transaction->markFailed('Subscription is deleted; recurring charge skipped.', $retryCount);

                return null;
            }

            $metadata = $subscription->getAttribute('metadata');
            $normalizedMetadata = is_array($metadata) ? $metadata : [];
            $normalizedMetadata['charge_idempotency_key'] = (string) $transaction->getKey();

            $transaction->markProcessing();

            return [
                'provider' => (string) $transaction->getAttribute('provider'),
                'amount' => (float) $transaction->getAttribute('amount'),
                'idempotency_key' => (string) $transaction->getKey(),
                'charge_payload' => [
                    'subscription_id' => $subscription->getKey(),
                    'provider_subscription_id' => $subscription->getAttribute('provider_subscription_id'),
                    'payment_method_id' => $subscription->getAttribute('payment_method_id'),
                    'metadata' => $normalizedMetadata,
                ],
            ];
        });
    }

    private function nextRetryDate(int $retryCount): ?\Illuminate\Support\Carbon
    {
        $retryDays = [2, 5, 7];

        if (! isset($retryDays[$retryCount - 1])) {
            return null;
        }

        return now()->addDays($retryDays[$retryCount - 1]);
    }

    private function isTerminalFailure(string $failureReason): bool
    {
        $normalized = strtolower(trim($failureReason));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'hard_decline',
            'do_not_honor',
            'stolen_card',
            'lost_card',
            'invalid_card',
        ], true);
    }
}
