<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Subscription;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\ProviderEventDispatcherInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\DiscountResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCancelled;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCreated;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewalFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\WebhookReceived;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessScheduledPlanChangeJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\PaymentMethod;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\ScheduledPlanChange;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\ProviderEvents\ProviderEventDispatcherResolver;

final class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        private readonly PaymentManager $paymentManager,
        private readonly ProviderEventDispatcherResolver $providerEventDispatchers,
    ) {}

    public function create(int|string $subscribableId, int|string $planId, int|string $paymentMethodId): array
    {
        $plan = Plan::query()->findOrFail($planId);

        $subscription = Subscription::query()->create([
            'subscribable_type' => (string) config('auth.providers.users.model', 'App\\Models\\User'),
            'subscribable_id' => $subscribableId,
            'plan_id' => $planId,
            'provider' => $this->paymentManager->defaultProvider(),
            'status' => SubscriptionStatus::Pending->value,
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

        $subscription->setAttribute('status', SubscriptionStatus::Cancelled->value);
        $subscription->setAttribute('cancelled_at', now());

        return $subscription->save();
    }

    public function pause(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        $subscription->setAttribute('status', SubscriptionStatus::Paused->value);

        return $subscription->save();
    }

    public function resume(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        $subscription->setAttribute('status', SubscriptionStatus::Active->value);
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
            'status' => SubscriptionStatus::Pending->value,
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
            ->whereIn('status', [SubscriptionStatus::Active->value, 'trialing'])
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
            ->where('status', SubscriptionStatus::Pending->value)
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
            ->where('status', SubscriptionStatus::PastDue->value)
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

    public function handleWebhookResult(WebhookResult $result, string $provider): void
    {
        if (! $result->processed) {
            return;
        }

        Event::dispatch(new WebhookReceived(
            provider: $provider,
            eventId: $result->eventId,
            eventType: $result->eventType,
            duplicate: $result->duplicate,
            metadata: $result->metadata,
        ));

        if ($result->subscriptionId === null || $result->subscriptionId === '') {
            return;
        }

        $providerEvents = $this->providerEventDispatchers->resolve($provider);

        $subscription = Subscription::query()
            ->where('provider', $provider)
            ->where('provider_subscription_id', $result->subscriptionId)
            ->first();

        if (! $subscription instanceof Subscription) {
            return;
        }

        $eventType = strtolower((string) ($result->eventType ?? ''));

        if ($eventType === 'subscription.created') {
            $subscription->setAttribute('status', SubscriptionStatus::Active->value);
            $subscription->save();

            Event::dispatch(new SubscriptionCreated($provider, $result->subscriptionId, $subscription->getKey()));
            $providerEvents->dispatch('subscription.created', [
                'subscription_id' => $result->subscriptionId,
                'metadata' => $result->metadata,
            ]);

            return;
        }

        if (in_array($eventType, ['subscription.canceled', 'subscription.cancelled'], true)) {
            $subscription->setAttribute('status', SubscriptionStatus::Cancelled->value);
            $subscription->setAttribute('cancelled_at', now());
            $subscription->save();

            Event::dispatch(new SubscriptionCancelled($provider, $result->subscriptionId, $subscription->getKey()));
            $providerEvents->dispatch('subscription.cancelled', [
                'subscription_id' => $result->subscriptionId,
                'metadata' => $result->metadata,
            ]);

            return;
        }

        if ($eventType === 'subscription.order.success') {
            $this->recordWebhookTransaction($subscription, $provider, $result, true, $providerEvents);

            return;
        }

        if ($eventType === 'subscription.order.failure') {
            $this->recordWebhookTransaction($subscription, $provider, $result, false, $providerEvents);

            return;
        }

        if ($result->status !== null && $result->status !== '') {
            $normalizedStatus = SubscriptionStatus::normalize($result->status);
            $subscription->setAttribute('status', $normalizedStatus instanceof SubscriptionStatus ? $normalizedStatus->value : $result->status);
            $subscription->save();
        }
    }

    public function handlePaymentResult(PaymentResponse $result, Subscription $subscription): void
    {
        $provider = (string) $subscription->getAttribute('provider');
        $providerEvents = $this->providerEventDispatchers->resolve($provider);
        $providerTransactionId = $result->transactionId;
        $amount = (float) $subscription->getAttribute('amount');

        $idempotencyKey = sprintf(
            '%s:payment:%s',
            $provider,
            $providerTransactionId !== null && $providerTransactionId !== '' ? $providerTransactionId : hash('sha256', json_encode($result->providerResponse))
        );

        $transaction = Transaction::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'subscription_id' => $subscription->getKey(),
                'payable_type' => (string) $subscription->getAttribute('subscribable_type'),
                'payable_id' => (int) $subscription->getAttribute('subscribable_id'),
                'license_id' => $subscription->getAttribute('license_id'),
                'provider' => $provider,
                'provider_transaction_id' => $providerTransactionId,
                'type' => 'renewal',
                'status' => $result->success ? 'processed' : 'failed',
                'amount' => $amount,
                'tax_amount' => (float) $subscription->getAttribute('tax_amount'),
                'tax_rate' => (float) $subscription->getAttribute('tax_rate'),
                'currency' => (string) $subscription->getAttribute('currency'),
                'processed_at' => now(),
                'retry_count' => $result->success ? 0 : 1,
                'next_retry_at' => $result->success ? null : now()->addDays(2),
                'last_retry_at' => $result->success ? null : now(),
                'provider_response' => $result->providerResponse,
            ]
        );

        if (! $transaction->wasRecentlyCreated) {
            return;
        }

        if ($result->success) {
            $subscription->setAttribute('status', SubscriptionStatus::Active->value);
            $subscription->setAttribute('grace_ends_at', null);
            $nextBillingDate = $subscription->getAttribute('next_billing_date');

            if ($nextBillingDate instanceof Carbon) {
                $subscription->setAttribute('next_billing_date', $nextBillingDate->copy()->addMonth());
            } else {
                $subscription->setAttribute('next_billing_date', now()->addMonth());
            }

            $subscription->save();

            Event::dispatch(new PaymentCompleted($provider, $subscription->getKey(), $transaction->getKey(), $amount, $providerTransactionId, $result->providerResponse));
            Event::dispatch(new SubscriptionRenewed($provider, (string) $subscription->getAttribute('provider_subscription_id'), $subscription->getKey(), $amount, $providerTransactionId, $result->providerResponse));
            $providerEvents->dispatch('payment.completed', [
                'subscription_id' => (string) $subscription->getAttribute('provider_subscription_id'),
                'transaction_id' => $providerTransactionId,
                'amount' => $amount,
                'metadata' => $result->providerResponse,
            ]);

            return;
        }

        $subscription->setAttribute('status', SubscriptionStatus::PastDue->value);

        if ($subscription->getAttribute('grace_ends_at') === null) {
            $subscription->setAttribute('grace_ends_at', now()->addDays(7));
        }

        $subscription->save();

        Event::dispatch(new PaymentFailed($provider, $subscription->getKey(), $amount, $result->failureReason, $providerTransactionId, $result->providerResponse));
        Event::dispatch(new SubscriptionRenewalFailed($provider, (string) $subscription->getAttribute('provider_subscription_id'), $subscription->getKey(), $amount, $result->failureReason, $providerTransactionId, $result->providerResponse));
        $providerEvents->dispatch('payment.failed', [
            'subscription_id' => (string) $subscription->getAttribute('provider_subscription_id'),
            'transaction_id' => $providerTransactionId,
            'amount' => $amount,
            'reason' => $result->failureReason,
            'metadata' => $result->providerResponse,
        ]);
    }

    public function persistProviderPaymentMethod(string $provider, array $details): ?PaymentMethod
    {
        $payableType = $details['payable_type'] ?? null;
        $payableId = $details['payable_id'] ?? null;
        $paymentMethod = $details['payment_method'] ?? [];

        if (! is_string($payableType) || $payableType === '' || ! is_numeric($payableId) || ! is_array($paymentMethod)) {
            return null;
        }

        $providerCustomerToken = $paymentMethod['provider_customer_token'] ?? null;
        $providerCardToken = $paymentMethod['provider_card_token'] ?? null;

        if (! is_string($providerCustomerToken) || $providerCustomerToken === '' || ! is_string($providerCardToken) || $providerCardToken === '') {
            return null;
        }

        $isDefault = (bool) ($paymentMethod['is_default'] ?? true);

        if ($isDefault) {
            PaymentMethod::query()
                ->where('payable_type', $payableType)
                ->where('payable_id', (int) $payableId)
                ->where('provider', $provider)
                ->update(['is_default' => false]);
        }

        return PaymentMethod::query()->updateOrCreate(
            [
                'payable_type' => $payableType,
                'payable_id' => (int) $payableId,
                'provider' => $provider,
                'provider_method_id' => (string) ($paymentMethod['provider_method_id'] ?? $providerCardToken),
            ],
            [
                'provider_customer_token' => $providerCustomerToken,
                'provider_card_token' => $providerCardToken,
                'card_last_four' => $paymentMethod['card_last_four'] ?? null,
                'card_brand' => $paymentMethod['card_brand'] ?? null,
                'card_expiry' => $paymentMethod['card_expiry'] ?? null,
                'card_holder_name' => $paymentMethod['card_holder_name'] ?? null,
                'is_default' => $isDefault,
                'is_active' => true,
            ]
        );
    }

    private function recordWebhookTransaction(Subscription $subscription, string $provider, WebhookResult $result, bool $success, ProviderEventDispatcherInterface $providerEvents): void
    {
        $eventId = $result->eventId ?? hash('sha256', (string) $subscription->getKey().':'.$result->eventType);
        $amount = $result->amount ?? (float) $subscription->getAttribute('amount');
        $providerTransactionId = $result->transactionId;

        $transaction = Transaction::query()->firstOrCreate(
            ['idempotency_key' => $provider.':webhook:'.$eventId],
            [
                'subscription_id' => $subscription->getKey(),
                'payable_type' => (string) $subscription->getAttribute('subscribable_type'),
                'payable_id' => (int) $subscription->getAttribute('subscribable_id'),
                'license_id' => $subscription->getAttribute('license_id'),
                'provider' => $provider,
                'provider_transaction_id' => $providerTransactionId,
                'type' => 'renewal',
                'status' => $success ? 'processed' : 'failed',
                'amount' => $amount,
                'tax_amount' => (float) $subscription->getAttribute('tax_amount'),
                'tax_rate' => (float) $subscription->getAttribute('tax_rate'),
                'currency' => (string) $subscription->getAttribute('currency'),
                'processed_at' => now(),
                'retry_count' => $success ? 0 : 1,
                'next_retry_at' => $success ? null : now()->addDays(2),
                'last_retry_at' => $success ? null : now(),
                'provider_response' => $result->metadata,
            ]
        );

        if (! $transaction->wasRecentlyCreated) {
            return;
        }

        $providerSubscriptionId = (string) $subscription->getAttribute('provider_subscription_id');

        if ($success) {
            $subscription->setAttribute('status', SubscriptionStatus::Active->value);
            $subscription->setAttribute('grace_ends_at', null);

            if ($result->nextBillingDate !== null && $result->nextBillingDate !== '') {
                $subscription->setAttribute('next_billing_date', Carbon::parse($result->nextBillingDate));
            } else {
                $nextBillingDate = $subscription->getAttribute('next_billing_date');

                if ($nextBillingDate instanceof Carbon) {
                    $subscription->setAttribute('next_billing_date', $nextBillingDate->copy()->addMonth());
                } else {
                    $subscription->setAttribute('next_billing_date', now()->addMonth());
                }
            }

            $subscription->save();

            Event::dispatch(new PaymentCompleted($provider, $subscription->getKey(), $transaction->getKey(), $amount, $providerTransactionId, $result->metadata));
            Event::dispatch(new SubscriptionRenewed($provider, $providerSubscriptionId, $subscription->getKey(), $amount, $providerTransactionId, $result->metadata));

            $providerEvents->dispatch('subscription.order.success', [
                'event_id' => $eventId,
                'subscription_id' => $providerSubscriptionId,
                'transaction_id' => $providerTransactionId,
                'amount' => $amount,
                'metadata' => $result->metadata,
            ]);

            return;
        }

        $subscription->setAttribute('status', SubscriptionStatus::PastDue->value);

        if ($subscription->getAttribute('grace_ends_at') === null) {
            $subscription->setAttribute('grace_ends_at', now()->addDays(7));
        }

        $subscription->save();

        Event::dispatch(new PaymentFailed($provider, $subscription->getKey(), $amount, $result->message, $providerTransactionId, $result->metadata));
        Event::dispatch(new SubscriptionRenewalFailed($provider, $providerSubscriptionId, $subscription->getKey(), $amount, $result->message, $providerTransactionId, $result->metadata));

        $providerEvents->dispatch('subscription.order.failure', [
            'event_id' => $eventId,
            'subscription_id' => $providerSubscriptionId,
            'transaction_id' => $providerTransactionId,
            'amount' => $amount,
            'reason' => $result->message,
            'metadata' => $result->metadata,
        ]);
    }
}
