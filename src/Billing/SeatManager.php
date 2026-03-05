<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\SubscriptionItem;

final class SeatManager
{
    public function addSeat(Subscription $subscription, int $count): bool
    {
        if ($count <= 0) {
            return false;
        }

        return $this->adjustSeats($subscription, $count);
    }

    public function removeSeat(Subscription $subscription, int $count): bool
    {
        if ($count <= 0) {
            return false;
        }

        return $this->adjustSeats($subscription, -$count);
    }

    public function calculateProration(Subscription $subscription, int $change): float
    {
        if ($change === 0) {
            return 0.0;
        }

        $item = SubscriptionItem::query()
            ->where('subscription_id', $subscription->getKey())
            ->first();

        $unitPrice = $item instanceof SubscriptionItem
            ? (float) $item->getAttribute('unit_price')
            : (float) $subscription->getAttribute('amount');

        $periodStart = $subscription->getAttribute('current_period_start');
        $periodEnd = $subscription->getAttribute('current_period_end') ?? $subscription->getAttribute('next_billing_date');

        if (! $periodStart instanceof Carbon || ! $periodEnd instanceof Carbon) {
            return round($unitPrice * $change, 2);
        }

        $now = now();

        if ($periodEnd->lessThanOrEqualTo($now)) {
            return 0.0;
        }

        $totalSeconds = max(1, $periodStart->diffInSeconds($periodEnd));
        $remainingSeconds = max(0, $now->diffInSeconds($periodEnd));
        $ratio = $remainingSeconds / $totalSeconds;

        return round($unitPrice * $change * $ratio, 2);
    }

    private function adjustSeats(Subscription $subscription, int $delta): bool
    {
        return DB::transaction(function () use ($subscription, $delta): bool {
            $lockedSubscription = Subscription::query()
                ->lockForUpdate()
                ->find($subscription->getKey());

            if (! $lockedSubscription instanceof Subscription) {
                return false;
            }

            $item = SubscriptionItem::query()
                ->where('subscription_id', $lockedSubscription->getKey())
                ->lockForUpdate()
                ->first();

            if (! $item instanceof SubscriptionItem) {
                $item = SubscriptionItem::query()->create([
                    'subscription_id' => $lockedSubscription->getKey(),
                    'plan_id' => (int) $lockedSubscription->getAttribute('plan_id'),
                    'quantity' => 1,
                    'unit_price' => (float) $lockedSubscription->getAttribute('amount'),
                ]);
            }

            $currentQuantity = (int) $item->getAttribute('quantity');
            $nextQuantity = $currentQuantity + $delta;

            if ($nextQuantity < 1) {
                return false;
            }

            $item->setAttribute('quantity', $nextQuantity);
            $item->save();

            $metadata = $lockedSubscription->getAttribute('metadata');
            $normalizedMetadata = is_array($metadata) ? $metadata : [];
            $normalizedMetadata['seat_quantity'] = $nextQuantity;
            $normalizedMetadata['last_seat_proration'] = $this->calculateProration($lockedSubscription, $delta);
            $lockedSubscription->setAttribute('metadata', $normalizedMetadata);
            $lockedSubscription->save();

            $licenseId = $lockedSubscription->getAttribute('license_id');

            if (! is_numeric($licenseId)) {
                return true;
            }

            $license = License::query()
                ->lockForUpdate()
                ->find((int) $licenseId);

            if (! $license instanceof License) {
                return true;
            }

            $limits = $license->getAttribute('limit_overrides');
            $normalizedLimits = is_array($limits) ? $limits : [];
            $normalizedLimits['seats'] = $nextQuantity;
            $license->setAttribute('limit_overrides', $normalizedLimits);

            return $license->save();
        });
    }
}
