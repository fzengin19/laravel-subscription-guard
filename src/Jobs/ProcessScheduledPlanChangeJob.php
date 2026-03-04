<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class ProcessScheduledPlanChangeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int|string $scheduledPlanChangeId)
    {
        $this->onQueue((string) config('subscription-guard.queue.queue', 'subguard-main'));
    }

    public function handle(PaymentManager $paymentManager): void
    {
        $lock = cache()->lock('subguard:plan-change:'.$this->scheduledPlanChangeId, 30);

        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($paymentManager): void {
                $change = ScheduledPlanChange::query()
                    ->lockForUpdate()
                    ->find($this->scheduledPlanChangeId);

                if (! $change instanceof ScheduledPlanChange) {
                    return;
                }

                if ((string) $change->getAttribute('status') !== 'pending') {
                    return;
                }

                $subscriptionId = (int) $change->getAttribute('subscription_id');
                $toPlanId = (int) $change->getAttribute('to_plan_id');

                $subscription = Subscription::query()
                    ->lockForUpdate()
                    ->find($subscriptionId);

                if (! $subscription instanceof Subscription) {
                    $change->setAttribute('status', 'failed');
                    $change->setAttribute('error_message', 'Subscription not found for scheduled plan change.');
                    $change->setAttribute('processed_at', now());
                    $change->save();

                    return;
                }

                $targetPlan = Plan::query()->find($toPlanId);

                $subscription->setAttribute('plan_id', $toPlanId);

                if ($targetPlan instanceof Plan) {
                    $subscription->setAttribute('amount', (float) $targetPlan->getAttribute('price'));
                    $subscription->setAttribute('billing_period', (string) $targetPlan->getAttribute('billing_period'));
                    $subscription->setAttribute('billing_interval', (int) $targetPlan->getAttribute('billing_interval'));
                    $subscription->setAttribute('currency', (string) $targetPlan->getAttribute('currency'));
                }

                $subscription->setAttribute('scheduled_change_id', null);
                $subscription->save();

                $change->setAttribute('status', 'processed');
                $change->setAttribute('processed_at', now());
                $change->setAttribute('error_message', null);
                $change->save();

                DispatchBillingNotificationsJob::dispatch('plan-change.processed', [
                    'scheduled_plan_change_id' => $change->getKey(),
                    'subscription_id' => $subscription->getKey(),
                    'to_plan_id' => $toPlanId,
                ])->onQueue($paymentManager->queueName('notifications_queue', 'subguard-notifications'));
            });
        } finally {
            $lock->release();
        }
    }
}
