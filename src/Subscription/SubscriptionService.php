<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Subscription;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\DiscountResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessScheduledPlanChangeJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(private readonly PaymentManager $paymentManager) {}

    public function create(int|string $subscribableId, int|string $planId, int|string $paymentMethodId): array
    {
        $plan = Plan::query()->findOrFail($planId);

        $subscription = Subscription::query()->create([
            'subscribable_type' => (string) config('auth.providers.users.model', 'App\\Models\\User'),
            'subscribable_id' => $subscribableId,
            'plan_id' => $planId,
            'provider' => $this->paymentManager->defaultProvider(),
            'status' => 'pending',
            'billing_period' => (string) $plan->getAttribute('billing_period'),
            'billing_interval' => (int) $plan->getAttribute('billing_interval'),
            'amount' => (float) $plan->getAttribute('price'),
            'currency' => (string) $plan->getAttribute('currency'),
            'next_billing_date' => now(),
            'metadata' => [
                'payment_method_id' => $paymentMethodId,
            ],
        ]);

        return $subscription->toArray();
    }

    public function cancel(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        $subscription->setAttribute('status', 'cancelled');
        $subscription->setAttribute('cancelled_at', now());

        return $subscription->save();
    }

    public function pause(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        $subscription->setAttribute('status', 'paused');

        return $subscription->save();
    }

    public function resume(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        $subscription->setAttribute('status', 'active');
        $subscription->setAttribute('resumes_at', null);

        return $subscription->save();
    }

    public function upgrade(int|string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): bool
    {
        if (! in_array($mode, ['now', 'next_period'], true)) {
            return false;
        }

        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        $scheduledAt = $mode === 'now'
            ? Carbon::now()
            : ($subscription->getAttribute('current_period_end') ?? $subscription->getAttribute('next_billing_date') ?? Carbon::now());

        $fromPlanId = $subscription->getAttribute('plan_id');

        $change = ScheduledPlanChange::query()->create([
            'subscription_id' => $subscription->getKey(),
            'from_plan_id' => is_numeric($fromPlanId) ? (int) $fromPlanId : null,
            'to_plan_id' => $newPlanId,
            'change_type' => 'upgrade',
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
        ]);

        $subscription->setAttribute('scheduled_change_id', $change->getKey());

        return $subscription->save();
    }

    public function downgrade(int|string $subscriptionId, int|string $newPlanId): bool
    {
        return $this->upgrade($subscriptionId, $newPlanId, 'next_period');
    }

    public function applyDiscount(int|string $subscriptionId, string $couponOrDiscountCode): DiscountResult
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Subscription not found.');
        }

        $coupon = Coupon::query()
            ->where('code', $couponOrDiscountCode)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->first();

        if (! $coupon instanceof Coupon) {
            return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Coupon not found or inactive.');
        }

        $couponType = (string) $coupon->getAttribute('type');
        $couponValue = (float) $coupon->getAttribute('value');
        $subscriptionAmount = (float) $subscription->getAttribute('amount');

        $discountAmount = $couponType === 'percentage'
            ? ($subscriptionAmount * ($couponValue / 100))
            : $couponValue;

        $maxDiscountAmount = $coupon->getAttribute('max_discount_amount');

        if (is_numeric($maxDiscountAmount)) {
            $discountAmount = min($discountAmount, (float) $maxDiscountAmount);
        }

        $discountAmount = max(0.0, round($discountAmount, 2));

        Discount::query()->create([
            'coupon_id' => $coupon->getKey(),
            'discountable_type' => Subscription::class,
            'discountable_id' => $subscription->getKey(),
            'type' => $couponType,
            'value' => $couponValue,
            'currency' => (string) $coupon->getAttribute('currency'),
            'duration' => 'once',
            'applied_amount' => $discountAmount,
            'description' => $coupon->getAttribute('description'),
        ]);

        return new DiscountResult(true, $discountAmount, $couponOrDiscountCode);
    }

    public function processRenewals(DateTimeInterface $date): int
    {
        $count = 0;
        $formattedDate = Carbon::parse($date->format('Y-m-d H:i:s'));

        $subscriptions = Subscription::query()
            ->where('next_billing_date', '<=', $formattedDate)
            ->whereIn('status', ['active', 'trialing'])
            ->get();

        foreach ($subscriptions as $subscription) {
            if ($this->paymentManager->managesOwnBilling((string) $subscription->getAttribute('provider'))) {
                continue;
            }

            ProcessRenewalCandidateJob::dispatch((int) $subscription->getKey())
                ->onQueue($this->paymentManager->queueName('queue', 'subguard-main'));

            $count++;
        }

        return $count;
    }

    public function processDunning(DateTimeInterface $date): int
    {
        $count = 0;
        $formattedDate = Carbon::parse($date->format('Y-m-d H:i:s'));

        $transactions = Transaction::query()
            ->whereIn('status', ['failed', 'retrying'])
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $formattedDate)
            ->where('retry_count', '<', 3)
            ->get();

        foreach ($transactions as $transaction) {
            ProcessDunningRetryJob::dispatch((int) $transaction->getKey())
                ->onQueue($this->paymentManager->queueName('queue', 'subguard-main'));

            $count++;
        }

        return $count;
    }

    public function processScheduledPlanChanges(DateTimeInterface $date): int
    {
        $count = 0;
        $formattedDate = Carbon::parse($date->format('Y-m-d H:i:s'));

        $changes = ScheduledPlanChange::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', $formattedDate)
            ->get();

        foreach ($changes as $change) {
            ProcessScheduledPlanChangeJob::dispatch((int) $change->getKey())
                ->onQueue($this->paymentManager->queueName('queue', 'subguard-main'));

            $count++;
        }

        return $count;
    }

    public function retryPastDuePayments(int|string $subscribableId): int
    {
        $count = 0;

        $subscriptions = Subscription::query()
            ->where('subscribable_id', $subscribableId)
            ->where('status', 'past_due')
            ->get();

        foreach ($subscriptions as $subscription) {
            if ($this->paymentManager->managesOwnBilling((string) $subscription->getAttribute('provider'))) {
                continue;
            }

            ProcessRenewalCandidateJob::dispatch((int) $subscription->getKey())
                ->onQueue($this->paymentManager->queueName('queue', 'subguard-main'));

            $count++;
        }

        return $count;
    }
}
