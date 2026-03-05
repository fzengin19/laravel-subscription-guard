<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Billing;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use Throwable;

final class MeteredBillingProcessor
{
    public function __construct(private readonly PaymentManager $paymentManager) {}

    public function process(DateTimeInterface $date): int
    {
        $count = 0;
        $processDate = Carbon::instance($date)->setTimezone($this->billingTimezone());

        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('license_id')
            ->where('next_billing_date', '<=', $processDate)
            ->get();

        foreach ($subscriptions as $subscription) {
            $processed = DB::transaction(function () use ($subscription, $processDate): bool {
                $locked = Subscription::query()
                    ->lockForUpdate()
                    ->find($subscription->getKey());

                if (! $locked instanceof Subscription) {
                    return false;
                }

                $licenseId = $locked->getAttribute('license_id');

                if (! is_numeric($licenseId)) {
                    return false;
                }

                $periodStart = $locked->getAttribute('current_period_start');
                $periodEnd = $locked->getAttribute('current_period_end') ?? $processDate;

                if (! $periodStart instanceof Carbon) {
                    $periodStart = $processDate->copy()->startOfMonth();
                }

                if (! $periodEnd instanceof Carbon) {
                    $periodEnd = $processDate->copy();
                }

                $usageQuery = LicenseUsage::query()
                    ->where('license_id', (int) $licenseId)
                    ->where('period_start', '>=', $periodStart)
                    ->where('period_start', '<=', $periodEnd)
                    ->lockForUpdate();

                $totalUsage = (float) $usageQuery->sum('quantity');

                if ($totalUsage <= 0) {
                    return false;
                }

                $metadata = $locked->getAttribute('metadata');
                $normalizedMetadata = is_array($metadata) ? $metadata : [];
                $pricePerUnit = max(0.0, (float) ($normalizedMetadata['metered_price_per_unit'] ?? 0.0));
                $amount = round($totalUsage * $pricePerUnit, 2);
                $periodToken = $periodEnd->copy()->setTimezone('UTC')->format('YmdHis');

                $idempotencyKey = sprintf('subguard:metered:%d:%s', (int) $locked->getKey(), $periodToken);

                $provider = (string) $locked->getAttribute('provider');
                $transaction = Transaction::unguarded(static fn (): Transaction => Transaction::query()->firstOrNew(['idempotency_key' => $idempotencyKey]));

                if ((string) $transaction->getAttribute('status') === 'processed') {
                    return false;
                }

                $baseProviderResponse = [
                    'usage_total' => $totalUsage,
                    'price_per_unit' => $pricePerUnit,
                    'period_start' => $periodStart->toIso8601String(),
                    'period_end' => $periodEnd->toIso8601String(),
                ];

                if (! $transaction->exists) {
                    $transaction->setAttribute('subscription_id', $locked->getKey());
                    $transaction->setAttribute('payable_type', (string) $locked->getAttribute('subscribable_type'));
                    $transaction->setAttribute('payable_id', (int) $locked->getAttribute('subscribable_id'));
                    $transaction->setAttribute('license_id', (int) $licenseId);
                    $transaction->setAttribute('provider', $provider);
                    $transaction->setAttribute('type', 'metered_usage');
                    $transaction->setAttribute('amount', $amount);
                    $transaction->setAttribute('tax_amount', 0);
                    $transaction->setAttribute('tax_rate', 0);
                    $transaction->setAttribute('currency', (string) $locked->getAttribute('currency'));
                    $transaction->setAttribute('metadata', [
                        'metered' => true,
                        'usage_total' => $totalUsage,
                    ]);
                }

                $requiresProviderCharge = ! $this->paymentManager->managesOwnBilling($provider);

                if ($requiresProviderCharge) {
                    $chargeResponse = $this->chargeProvider($locked, $amount, $idempotencyKey);

                    if (! $chargeResponse->success) {
                        $retryCount = max(1, (int) $transaction->getAttribute('retry_count') + 1);
                        $transaction->setAttribute('status', 'failed');
                        $transaction->setAttribute('retry_count', $retryCount);
                        $transaction->setAttribute('failure_reason', (string) ($chargeResponse->failureReason ?? 'Metered provider charge failed.'));
                        $transaction->setAttribute('last_retry_at', now());
                        $transaction->setAttribute('provider_transaction_id', $chargeResponse->transactionId);
                        $transaction->setAttribute('provider_response', array_merge($baseProviderResponse, [
                            'idempotency_key' => $idempotencyKey,
                            'charge_success' => false,
                            'charge_provider_response' => $chargeResponse->providerResponse,
                        ]));
                        $transaction->save();

                        return false;
                    }

                    $transaction->setAttribute('provider_transaction_id', $chargeResponse->transactionId);
                    $transaction->setAttribute('provider_response', array_merge($baseProviderResponse, [
                        'idempotency_key' => $idempotencyKey,
                        'charge_success' => true,
                        'charge_provider_response' => $chargeResponse->providerResponse,
                    ]));
                } else {
                    $transaction->setAttribute('provider_transaction_id', null);
                    $transaction->setAttribute('provider_response', array_merge($baseProviderResponse, [
                        'idempotency_key' => $idempotencyKey,
                        'charge_success' => true,
                        'charge_provider_response' => [],
                    ]));
                }

                $transaction->setAttribute('status', 'processed');
                $transaction->setAttribute('processed_at', now());
                $transaction->setAttribute('failure_reason', null);
                $transaction->save();

                $usageQuery->delete();

                $locked->setAttribute('current_period_start', $periodEnd->copy());
                $locked->setAttribute('current_period_end', $this->nextPeriodEnd($periodEnd, $locked));
                $locked->setAttribute('next_billing_date', $this->nextPeriodEnd($periodEnd, $locked));
                $locked->save();

                return true;
            });

            if ($processed) {
                $count++;
            }
        }

        return $count;
    }

    private function billingTimezone(): string
    {
        return (string) config('subscription-guard.billing.timezone', 'Europe/Istanbul');
    }

    private function nextPeriodEnd(Carbon $from, Subscription $subscription): Carbon
    {
        $interval = max(1, (int) $subscription->getAttribute('billing_interval'));
        $period = (string) $subscription->getAttribute('billing_period');
        $next = $from->copy();

        return match ($period) {
            'yearly', 'annual' => $next->addYears($interval),
            'weekly' => $next->addWeeks($interval),
            default => $next->addMonths($interval),
        };
    }

    private function chargeProvider(Subscription $subscription, float $amount, string $idempotencyKey): PaymentResponse
    {
        try {
            $providerName = (string) $subscription->getAttribute('provider');
            $provider = $this->paymentManager->provider($providerName);

            return $provider->chargeRecurring($subscription->toArray(), $amount, $idempotencyKey);
        } catch (Throwable $exception) {
            return new PaymentResponse(
                success: false,
                transactionId: null,
                providerResponse: [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                failureReason: 'Metered provider charge exception.'
            );
        }
    }
}
