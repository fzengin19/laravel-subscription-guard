<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Billing;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;

final class MeteredBillingProcessor
{
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

                $transaction = Transaction::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'subscription_id' => $locked->getKey(),
                        'payable_type' => (string) $locked->getAttribute('subscribable_type'),
                        'payable_id' => (int) $locked->getAttribute('subscribable_id'),
                        'license_id' => (int) $licenseId,
                        'provider' => (string) $locked->getAttribute('provider'),
                        'provider_transaction_id' => null,
                        'type' => 'metered_usage',
                        'status' => 'processed',
                        'amount' => $amount,
                        'tax_amount' => 0,
                        'tax_rate' => 0,
                        'currency' => (string) $locked->getAttribute('currency'),
                        'processed_at' => now(),
                        'provider_response' => [
                            'usage_total' => $totalUsage,
                            'price_per_unit' => $pricePerUnit,
                            'period_start' => $periodStart->toIso8601String(),
                            'period_end' => $periodEnd->toIso8601String(),
                        ],
                        'metadata' => [
                            'metered' => true,
                            'usage_total' => $totalUsage,
                        ],
                    ]
                );

                if (! $transaction->wasRecentlyCreated) {
                    return false;
                }

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
}
