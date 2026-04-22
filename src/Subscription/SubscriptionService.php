<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Subscription;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\ProviderEventDispatcherInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\DiscountResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\SubGuardException;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCancelled;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCreated;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewalFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\WebhookReceived;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\DispatchBillingNotificationsJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessDunningRetryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessRenewalCandidateJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessScheduledPlanChangeJob;
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
        private readonly DiscountService $discountService,
    ) {}

    public function create(int|string $subscribableId, int|string $planId, int|string $paymentMethodId): array
    {
        $plan = Plan::query()->findOrFail($planId);

        return DB::transaction(function () use ($subscribableId, $planId, $paymentMethodId, $plan): array {
            $userModelClass = (string) config('auth.providers.users.model', 'App\\Models\\User');

            $existing = Subscription::query()
                ->where('subscribable_type', $userModelClass)
                ->where('subscribable_id', $subscribableId)
                ->where('plan_id', $planId)
                ->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::Pending->value,
                    SubscriptionStatus::PastDue->value,
                    'trialing',
                ])
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Subscription) {
                return $existing->toArray();
            }

            $subscription = Subscription::unguarded(fn (): Subscription => Subscription::query()->create([
                'subscribable_type' => $userModelClass,
                'subscribable_id' => $subscribableId,
                'plan_id' => $planId,
                'provider' => $this->paymentManager->defaultProvider(),
                'status' => SubscriptionStatus::Pending->value,
                'billing_period' => (string) $plan->getAttribute('billing_period'),
                'billing_interval' => (int) $plan->getAttribute('billing_interval'),
                'billing_anchor_day' => (int) now()->day,
                'amount' => (float) $plan->getAttribute('price'),
                'currency' => (string) $plan->getAttribute('currency'),
                'next_billing_date' => now(),
                'metadata' => [
                    'payment_method_id' => $paymentMethodId,
                ],
            ]));

            return $subscription->toArray();
        });
    }

    public function cancel(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        if ((string) $subscription->getAttribute('status') === SubscriptionStatus::Cancelled->value) {
            return true;
        }

        $lock = Cache::lock('subguard:subscription-cancel:'.$subscription->getKey(), 30);

        if (! $lock->get()) {
            return false;
        }

        try {
            $provider = (string) $subscription->getAttribute('provider');
            $providerSubscriptionId = (string) ($subscription->getAttribute('provider_subscription_id') ?? '');
            $providerManaged = $provider !== '' && $this->paymentManager->managesOwnBilling($provider);

            if ($providerManaged && $providerSubscriptionId !== '') {
                try {
                    $remoteOk = $this->paymentManager->provider($provider)->cancelSubscription($providerSubscriptionId);
                } catch (\Throwable $exception) {
                    Log::channel((string) config('subscription-guard.logging.payments_channel', 'subguard_payments'))
                        ->warning('Provider cancellation threw; aborting local cancellation.', [
                            'provider' => $provider,
                            'subscription_id' => $subscription->getKey(),
                            'error' => $exception->getMessage(),
                        ]);

                    return false;
                }

                if (! $remoteOk) {
                    Log::channel((string) config('subscription-guard.logging.payments_channel', 'subguard_payments'))
                        ->warning('Provider cancellation returned false; local subscription left untouched.', [
                            'provider' => $provider,
                            'subscription_id' => $subscription->getKey(),
                        ]);

                    return false;
                }
            } elseif ($providerManaged && $providerSubscriptionId === '') {
                $currentStatus = (string) $subscription->getAttribute('status');

                if (! in_array($currentStatus, [SubscriptionStatus::Pending->value, SubscriptionStatus::Failed->value], true)) {
                    Log::channel((string) config('subscription-guard.logging.payments_channel', 'subguard_payments'))
                        ->warning('Provider-managed subscription missing provider_subscription_id; cancellation aborted.', [
                            'provider' => $provider,
                            'subscription_id' => $subscription->getKey(),
                            'status' => $currentStatus,
                        ]);

                    return false;
                }
            }

            return DB::transaction(function () use ($subscription, $provider): bool {
                $locked = Subscription::query()->lockForUpdate()->find($subscription->getKey());

                if (! $locked instanceof Subscription) {
                    return false;
                }

                if ((string) $locked->getAttribute('status') === SubscriptionStatus::Cancelled->value) {
                    return true;
                }

                try {
                    $locked->transitionTo(SubscriptionStatus::Cancelled);
                } catch (SubGuardException) {
                    return false;
                }

                $locked->setAttribute('cancelled_at', now());
                $saved = $locked->save();

                if (! $saved) {
                    return false;
                }

                $providerSubscriptionId = (string) ($locked->getAttribute('provider_subscription_id') ?? '');
                $providerEvents = $this->providerEventDispatchers->resolve($provider);

                Event::dispatch(new SubscriptionCancelled($provider, $providerSubscriptionId, $locked->getKey()));
                $providerEvents->dispatch('subscription.cancelled', [
                    'subscription_id' => $providerSubscriptionId,
                    'metadata' => [],
                ]);

                DispatchBillingNotificationsJob::dispatch('subscription.cancelled', [
                    'provider' => $provider,
                    'subscription_id' => $locked->getKey(),
                    'provider_subscription_id' => $providerSubscriptionId,
                ])->onQueue($this->paymentManager->queueName('notifications_queue', 'subguard-notifications'));

                return true;
            });
        } finally {
            $lock->release();
        }
    }

    public function pause(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        try {
            $subscription->transitionTo(SubscriptionStatus::Paused);
        } catch (SubGuardException) {
            return false;
        }

        return $subscription->save();
    }

    public function resume(int|string $subscriptionId): bool
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        try {
            $subscription->transitionTo(SubscriptionStatus::Active);
        } catch (SubGuardException) {
            return false;
        }

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
        return $this->discountService->applyDiscount($subscriptionId, $couponOrDiscountCode);
    }

    public function resolveRenewalDiscount(Subscription $subscription, float $baseAmount): array
    {
        return $this->discountService->resolveRenewalDiscount($subscription, $baseAmount);
    }

    public function markDiscountApplied(int|string $discountId): void
    {
        $this->discountService->markDiscountApplied($discountId);
    }

    public function processRenewals(DateTimeInterface $date): int
    {
        $count = 0;
        $formattedDate = Carbon::instance($date)->setTimezone($this->billingTimezone());

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
        $formattedDate = Carbon::instance($date)->setTimezone($this->billingTimezone());

        $transactions = Transaction::query()
            ->whereIn('status', ['failed', 'retrying'])
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $formattedDate)
            ->get();

        foreach ($transactions as $transaction) {
            $provider = (string) $transaction->getAttribute('provider');

            if ($provider !== '' && $this->paymentManager->managesOwnBilling($provider)) {
                continue;
            }

            ProcessDunningRetryJob::dispatch((int) $transaction->getKey())
                ->onQueue($this->paymentManager->queueName('queue', 'subguard-main'));

            $count++;
        }

        return $count;
    }

    public function processScheduledPlanChanges(DateTimeInterface $date): int
    {
        $count = 0;
        $formattedDate = Carbon::instance($date)->setTimezone($this->billingTimezone());

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

        DB::transaction(function () use ($result, $provider, $providerEvents): void {
            $subscription = Subscription::query()
                ->where('provider', $provider)
                ->where('provider_subscription_id', $result->subscriptionId)
                ->lockForUpdate()
                ->first();

            if (! $subscription instanceof Subscription) {
                return;
            }

            $this->processWebhookForSubscription($result, $provider, $subscription, $providerEvents);
        });
    }

    private function processWebhookForSubscription(WebhookResult $result, string $provider, Subscription $subscription, ProviderEventDispatcherInterface $providerEvents): void
    {
        $eventType = strtolower((string) ($result->eventType ?? ''));

        if ($eventType === 'subscription.created') {
            try {
                $subscription->transitionTo(SubscriptionStatus::Active);
            } catch (SubGuardException) {
                return;
            }

            $subscription->save();

            Event::dispatch(new SubscriptionCreated($provider, $result->subscriptionId, $subscription->getKey()));
            $providerEvents->dispatch('subscription.created', [
                'subscription_id' => $result->subscriptionId,
                'metadata' => $result->metadata,
            ]);

            return;
        }

        if (in_array($eventType, ['subscription.canceled', 'subscription.cancelled'], true)) {
            try {
                $subscription->transitionTo(SubscriptionStatus::Cancelled);
            } catch (SubGuardException) {
                return;
            }

            $subscription->setAttribute('cancelled_at', now());
            $subscription->save();

            Event::dispatch(new SubscriptionCancelled($provider, $result->subscriptionId, $subscription->getKey()));
            $providerEvents->dispatch('subscription.cancelled', [
                'subscription_id' => $result->subscriptionId,
                'metadata' => $result->metadata,
            ]);

            DispatchBillingNotificationsJob::dispatch('subscription.cancelled', [
                'provider' => $provider,
                'subscription_id' => $subscription->getKey(),
                'provider_subscription_id' => $result->subscriptionId,
            ])->onQueue($this->paymentManager->queueName('notifications_queue', 'subguard-notifications'));

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

            if ($normalizedStatus instanceof SubscriptionStatus) {
                try {
                    $subscription->transitionTo($normalizedStatus);
                } catch (SubGuardException) {
                    return;
                }

                $subscription->save();
            }
        }
    }

    public function handlePaymentResult(PaymentResponse $result, Subscription $subscription): void
    {
        $provider = (string) $subscription->getAttribute('provider');
        $providerEvents = $this->providerEventDispatchers->resolve($provider);
        $providerTransactionId = $result->transactionId;
        $amount = (float) $subscription->getAttribute('amount');
        $resolvedDiscount = $this->resolveRenewalDiscount($subscription, $amount);
        $amount = $resolvedDiscount['amount'];

        $idempotencyKey = sprintf(
            '%s:payment:%s',
            $provider,
            $providerTransactionId !== null && $providerTransactionId !== '' ? $providerTransactionId : hash('sha256', json_encode($result->providerResponse))
        );

        $transaction = Transaction::unguarded(static fn (): Transaction => Transaction::query()->firstOrCreate(
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
                'discount_amount' => $resolvedDiscount['discount_amount'],
                'coupon_id' => $resolvedDiscount['coupon_id'],
                'discount_id' => $resolvedDiscount['discount_id'],
                'tax_amount' => (float) $subscription->getAttribute('tax_amount'),
                'tax_rate' => (float) $subscription->getAttribute('tax_rate'),
                'currency' => (string) $subscription->getAttribute('currency'),
                'processed_at' => now(),
                'retry_count' => $result->success ? 0 : 1,
                'next_retry_at' => $result->success ? null : now()->addDays((int) config('subscription-guard.billing.dunning_retry_interval_days', 2)),
                'last_retry_at' => $result->success ? null : now(),
                'provider_response' => $result->providerResponse,
            ]
        ));

        if (! $transaction->wasRecentlyCreated) {
            return;
        }

        if (is_numeric($resolvedDiscount['discount_id'])) {
            $this->markDiscountApplied((int) $resolvedDiscount['discount_id']);
        }

        if ($result->success) {
            if (! $this->applySubscriptionStatus($subscription, SubscriptionStatus::Active, 'payment_result.success')) {
                return;
            }

            $subscription->setAttribute('grace_ends_at', null);
            $nextBillingDate = $subscription->getAttribute('next_billing_date');
            $anchorDay = $subscription->getAttribute('billing_anchor_day');
            $anchor = is_numeric($anchorDay) ? (int) $anchorDay : null;

            if ($nextBillingDate instanceof Carbon) {
                $subscription->setAttribute('next_billing_date', $this->advanceBillingDate(
                    $nextBillingDate,
                    $anchor,
                    (string) $subscription->getAttribute('billing_period'),
                    max(1, (int) $subscription->getAttribute('billing_interval')),
                ));
            } else {
                $subscription->setAttribute('next_billing_date', $this->advanceBillingDate(
                    now(),
                    $anchor,
                    (string) $subscription->getAttribute('billing_period'),
                    max(1, (int) $subscription->getAttribute('billing_interval')),
                ));
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

            DispatchBillingNotificationsJob::dispatch('payment.completed', [
                'provider' => $provider,
                'subscription_id' => $subscription->getKey(),
                'transaction_id' => $transaction->getKey(),
                'provider_transaction_id' => $providerTransactionId,
                'amount' => $amount,
            ])->onQueue($this->paymentManager->queueName('notifications_queue', 'subguard-notifications'));

            return;
        }

        if (! $this->applySubscriptionStatus($subscription, SubscriptionStatus::PastDue, 'payment_result.failure')) {
            return;
        }

        if ($subscription->getAttribute('grace_ends_at') === null) {
            $subscription->setAttribute('grace_ends_at', now()->addDays((int) config('subscription-guard.billing.grace_period_days', 7)));
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

        DispatchBillingNotificationsJob::dispatch('payment.failed', [
            'provider' => $provider,
            'subscription_id' => $subscription->getKey(),
            'transaction_id' => $transaction->getKey(),
            'provider_transaction_id' => $providerTransactionId,
            'amount' => $amount,
            'reason' => $result->failureReason,
        ])->onQueue($this->paymentManager->queueName('notifications_queue', 'subguard-notifications'));
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

        return PaymentMethod::unguarded(static fn (): PaymentMethod => PaymentMethod::query()->updateOrCreate(
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
        ));
    }

    private function recordWebhookTransaction(Subscription $subscription, string $provider, WebhookResult $result, bool $success, ProviderEventDispatcherInterface $providerEvents): void
    {
        $eventId = $result->eventId ?? hash('sha256', (string) $subscription->getKey().':'.$result->eventType);
        $amount = $result->amount ?? (float) $subscription->getAttribute('amount');
        $providerTransactionId = $result->transactionId;
        $resolvedDiscount = $this->resolveRenewalDiscount($subscription, (float) $amount);
        $amount = $resolvedDiscount['amount'];

        $providerManaged = $this->paymentManager->managesOwnBilling($provider);
        $nextRetryAt = null;

        if (! $success && ! $providerManaged) {
            $nextRetryAt = now()->addDays((int) config('subscription-guard.billing.dunning_retry_interval_days', 2));
        }

        $transaction = Transaction::unguarded(static fn (): Transaction => Transaction::query()->firstOrCreate(
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
                'discount_amount' => $resolvedDiscount['discount_amount'],
                'coupon_id' => $resolvedDiscount['coupon_id'],
                'discount_id' => $resolvedDiscount['discount_id'],
                'tax_amount' => (float) $subscription->getAttribute('tax_amount'),
                'tax_rate' => (float) $subscription->getAttribute('tax_rate'),
                'currency' => (string) $subscription->getAttribute('currency'),
                'processed_at' => now(),
                'retry_count' => $success ? 0 : 1,
                'next_retry_at' => $nextRetryAt,
                'last_retry_at' => $success ? null : now(),
                'provider_response' => $result->metadata,
            ]
        ));

        if (! $transaction->wasRecentlyCreated) {
            return;
        }

        if (is_numeric($resolvedDiscount['discount_id'])) {
            $this->markDiscountApplied((int) $resolvedDiscount['discount_id']);
        }

        $providerSubscriptionId = (string) $subscription->getAttribute('provider_subscription_id');

        if ($success) {
            if (! $this->applySubscriptionStatus($subscription, SubscriptionStatus::Active, 'webhook.order.success')) {
                return;
            }

            $subscription->setAttribute('grace_ends_at', null);

            if ($result->nextBillingDate !== null && $result->nextBillingDate !== '') {
                $subscription->setAttribute(
                    'next_billing_date',
                    Carbon::parse($result->nextBillingDate, $this->billingTimezone())
                        ->setTimezone((string) config('app.timezone', 'UTC'))
                );
            } else {
                $nextBillingDate = $subscription->getAttribute('next_billing_date');
                $anchorDay = $subscription->getAttribute('billing_anchor_day');
                $anchor = is_numeric($anchorDay) ? (int) $anchorDay : null;

                if ($nextBillingDate instanceof Carbon) {
                    $subscription->setAttribute('next_billing_date', $this->advanceBillingDate(
                        $nextBillingDate,
                        $anchor,
                        (string) $subscription->getAttribute('billing_period'),
                        max(1, (int) $subscription->getAttribute('billing_interval')),
                    ));
                } else {
                    $subscription->setAttribute('next_billing_date', $this->advanceBillingDate(
                        now(),
                        $anchor,
                        (string) $subscription->getAttribute('billing_period'),
                        max(1, (int) $subscription->getAttribute('billing_interval')),
                    ));
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

        if (! $this->applySubscriptionStatus($subscription, SubscriptionStatus::PastDue, 'webhook.order.failure')) {
            return;
        }

        if ($subscription->getAttribute('grace_ends_at') === null) {
            $subscription->setAttribute('grace_ends_at', now()->addDays((int) config('subscription-guard.billing.grace_period_days', 7)));
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

    public function applySubscriptionStatus(Subscription $subscription, SubscriptionStatus $target, string $source = 'unknown'): bool
    {
        $currentRaw = (string) $subscription->getAttribute('status');

        if ($currentRaw === SubscriptionStatus::Cancelled->value && $target !== SubscriptionStatus::Cancelled) {
            Log::channel((string) config('subscription-guard.logging.payments_channel', 'subguard_payments'))
                ->info('Ignored status change on cancelled subscription.', [
                    'subscription_id' => $subscription->getKey(),
                    'target' => $target->value,
                    'source' => $source,
                ]);

            return false;
        }

        try {
            $subscription->transitionTo($target);
        } catch (SubGuardException $exception) {
            Log::channel((string) config('subscription-guard.logging.payments_channel', 'subguard_payments'))
                ->info('Subscription status transition rejected.', [
                    'subscription_id' => $subscription->getKey(),
                    'from' => $currentRaw,
                    'target' => $target->value,
                    'source' => $source,
                    'error' => $exception->getMessage(),
                ]);

            return false;
        }

        return true;
    }

    private function billingTimezone(): string
    {
        return (string) config('subscription-guard.billing.timezone', 'Europe/Istanbul');
    }

    public function advanceBillingDate(Carbon $currentDate, ?int $anchorDay = null, string $billingPeriod = 'month', int $billingInterval = 1): Carbon
    {
        $interval = max(1, $billingInterval);
        $next = $currentDate->copy();

        return match ($billingPeriod) {
            'day', 'daily' => $next->addDays($interval),
            'week', 'weekly' => $next->addWeeks($interval),
            'year', 'yearly', 'annual' => $next->addYears($interval),
            default => $this->advanceMonthly($next, $interval, $anchorDay ?? $currentDate->day),
        };
    }

    private function advanceMonthly(Carbon $date, int $interval, int $anchorDay): Carbon
    {
        if ($anchorDay < 1 || $anchorDay > 31) {
            $anchorDay = $date->day;
        }

        $next = $date->addMonthsNoOverflow($interval);
        $maxDay = $next->daysInMonth;
        $next->day = min($anchorDay, $maxDay);

        return $next;
    }
}
