<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\TrialEnding;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

final class ProcessTrialExpiryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int|string $subscriptionId)
    {
        $this->onQueue((string) config('subscription-guard.queue.queue', 'subguard-main'));
    }

    public function handle(PaymentManager $paymentManager, SubscriptionService $subscriptionService): void
    {
        $lock = Cache::lock('subguard:trial-expiry:'.$this->subscriptionId, 30);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            DB::transaction(function () use ($paymentManager, $subscriptionService): void {
                $subscription = Subscription::query()->lockForUpdate()->find($this->subscriptionId);

                if (! $subscription instanceof Subscription) {
                    return;
                }

                if ((string) $subscription->getAttribute('status') !== SubscriptionStatus::Trialing->value) {
                    return;
                }

                $trialEndsAt = $subscription->getAttribute('trial_ends_at');

                if (! $trialEndsAt instanceof CarbonInterface || $trialEndsAt->isFuture()) {
                    return;
                }

                $provider = (string) $subscription->getAttribute('provider');

                if ($provider !== '' && $paymentManager->managesOwnBilling($provider)) {
                    // Provider-managed billing drives the transition via subscription.order.* webhook.
                    return;
                }

                // Application-level hook: consuming apps may listen and decide what
                // happens at trial end (auto-charge, email, extend, etc). If a listener
                // synchronously transitions the subscription out of Trialing, the
                // default PastDue path is skipped.
                Event::dispatch(new TrialEnding($subscription));

                $subscription->refresh();

                if ((string) $subscription->getAttribute('status') !== SubscriptionStatus::Trialing->value) {
                    return;
                }

                if ($subscriptionService->applySubscriptionStatus($subscription, SubscriptionStatus::PastDue, 'trial.expired')) {
                    if ($subscription->getAttribute('grace_ends_at') === null) {
                        $subscription->setAttribute(
                            'grace_ends_at',
                            now()->addDays((int) config('subscription-guard.billing.grace_period_days', 7))
                        );
                    }

                    $subscription->save();
                }
            });
        } finally {
            $lock->release();
        }
    }
}
