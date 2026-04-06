<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Subscription;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\DiscountResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Coupon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Discount;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class DiscountService
{
    public function __construct(
        private readonly PaymentManager $paymentManager,
    ) {}

    public function applyDiscount(int|string $subscriptionId, string $couponOrDiscountCode): DiscountResult
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Subscription not found.');
        }

        $subscriptionAmount = (float) $subscription->getAttribute('amount');

        return DB::transaction(function () use ($couponOrDiscountCode, $subscription, $subscriptionAmount): DiscountResult {
            $coupon = Coupon::query()
                ->lockForUpdate()
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

            if (! $this->isCouponCurrencyCompatible($coupon, $subscription)) {
                return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Coupon currency does not match subscription currency.');
            }

            $minPurchaseAmount = $coupon->getAttribute('min_purchase_amount');

            if (is_numeric($minPurchaseAmount) && $subscriptionAmount < (float) $minPurchaseAmount) {
                return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Coupon minimum purchase amount is not met.');
            }

            $maxUses = $coupon->getAttribute('max_uses');
            $currentUses = (int) $coupon->getAttribute('current_uses');

            if (is_numeric($maxUses) && $currentUses >= (int) $maxUses) {
                return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Coupon maximum usage reached.');
            }

            if (! $this->isWithinPerUserLimit($coupon, $subscription)) {
                return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Coupon per-user usage limit reached.');
            }

            if (! $this->appliesToSubscription($coupon, $subscription)) {
                return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Coupon does not apply to this subscription.');
            }

            $existingDiscount = Discount::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('discountable_type', Subscription::class)
                ->where('discountable_id', $subscription->getKey())
                ->exists();

            if ($existingDiscount) {
                return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'This coupon is already applied to this subscription.');
            }

            $discountAmount = $this->computeDiscountAmount($subscriptionAmount, (string) $coupon->getAttribute('type'), (float) $coupon->getAttribute('value'), $coupon);

            if ($discountAmount <= 0) {
                return new DiscountResult(false, 0.0, $couponOrDiscountCode, 'Coupon discount amount is zero.');
            }

            $metadata = $coupon->getAttribute('metadata');
            $normalizedMetadata = is_array($metadata) ? $metadata : [];
            $duration = strtolower(trim((string) ($normalizedMetadata['duration'] ?? 'once')));

            if (! in_array($duration, ['once', 'forever', 'repeating'], true)) {
                $duration = 'once';
            }

            $durationInMonths = $duration === 'repeating'
                ? max(1, (int) ($normalizedMetadata['duration_in_months'] ?? 1))
                : null;

            Discount::query()->create([
                'coupon_id' => $coupon->getKey(),
                'discountable_type' => Subscription::class,
                'discountable_id' => $subscription->getKey(),
                'type' => (string) $coupon->getAttribute('type'),
                'value' => (float) $coupon->getAttribute('value'),
                'currency' => (string) $coupon->getAttribute('currency'),
                'duration' => $duration,
                'duration_in_months' => $durationInMonths,
                'applied_amount' => $discountAmount,
                'description' => $coupon->getAttribute('description'),
            ]);

            $coupon->setAttribute('current_uses', $currentUses + 1);
            $coupon->save();

            return new DiscountResult(true, $discountAmount, $couponOrDiscountCode);
        });
    }

    public function resolveRenewalDiscount(Subscription $subscription, float $baseAmount): array
    {
        $provider = (string) $subscription->getAttribute('provider');

        if ($this->paymentManager->managesOwnBilling($provider)) {
            return [
                'amount' => round($baseAmount, 2),
                'discount_amount' => 0.0,
                'coupon_id' => null,
                'discount_id' => null,
            ];
        }

        $discount = Discount::query()
            ->where('discountable_type', Subscription::class)
            ->where('discountable_id', $subscription->getKey())
            ->latest('id')
            ->first();

        if (! $discount instanceof Discount || ! $this->isDiscountApplicable($discount)) {
            return [
                'amount' => round($baseAmount, 2),
                'discount_amount' => 0.0,
                'coupon_id' => null,
                'discount_id' => null,
            ];
        }

        $relatedCoupon = $discount->coupon;

        $discountAmount = $this->computeDiscountAmount(
            amount: $baseAmount,
            couponType: (string) $discount->getAttribute('type'),
            couponValue: (float) $discount->getAttribute('value'),
            coupon: $relatedCoupon instanceof Coupon ? $relatedCoupon : null,
        );

        if ($discountAmount <= 0) {
            return [
                'amount' => round($baseAmount, 2),
                'discount_amount' => 0.0,
                'coupon_id' => null,
                'discount_id' => null,
            ];
        }

        return [
            'amount' => max(0.0, round($baseAmount - $discountAmount, 2)),
            'discount_amount' => $discountAmount,
            'coupon_id' => is_numeric($discount->getAttribute('coupon_id')) ? (int) $discount->getAttribute('coupon_id') : null,
            'discount_id' => (int) $discount->getKey(),
        ];
    }

    public function markDiscountApplied(int|string $discountId): void
    {
        $discount = Discount::query()->find($discountId);

        if (! $discount instanceof Discount) {
            return;
        }

        $discount->setAttribute('applied_cycles', max(0, (int) $discount->getAttribute('applied_cycles')) + 1);
        $discount->save();
    }

    public function isCouponCurrencyCompatible(Coupon $coupon, Subscription $subscription): bool
    {
        $couponCurrency = strtoupper(trim((string) $coupon->getAttribute('currency')));
        $subscriptionCurrency = strtoupper(trim((string) $subscription->getAttribute('currency')));

        if ($couponCurrency === '' || $subscriptionCurrency === '') {
            return true;
        }

        return $couponCurrency === $subscriptionCurrency;
    }

    public function isWithinPerUserLimit(Coupon $coupon, Subscription $subscription): bool
    {
        $maxPerUser = $coupon->getAttribute('max_uses_per_user');

        if (! is_numeric($maxPerUser)) {
            return true;
        }

        $limit = max(1, (int) $maxPerUser);
        $subscriptionIds = Subscription::query()
            ->where('subscribable_type', $subscription->getAttribute('subscribable_type'))
            ->where('subscribable_id', $subscription->getAttribute('subscribable_id'))
            ->pluck('id');

        $appliedCount = Discount::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('discountable_type', Subscription::class)
            ->whereIn('discountable_id', $subscriptionIds)
            ->count();

        return $appliedCount < $limit;
    }

    public function appliesToSubscription(Coupon $coupon, Subscription $subscription): bool
    {
        $appliesTo = trim((string) $coupon->getAttribute('applies_to'));

        if ($appliesTo === '' || $appliesTo === 'all') {
            return true;
        }

        $values = array_filter(array_map('trim', explode(',', $appliesTo)));

        foreach ($values as $value) {
            if ($value === 'all') {
                return true;
            }

            if ($value === 'plan:'.(string) $subscription->getAttribute('plan_id')) {
                return true;
            }

            if ($value === 'provider:'.(string) $subscription->getAttribute('provider')) {
                return true;
            }
        }

        return false;
    }

    public function isDiscountApplicable(Discount $discount): bool
    {
        $duration = (string) $discount->getAttribute('duration');
        $appliedCycles = max(0, (int) $discount->getAttribute('applied_cycles'));

        return match ($duration) {
            'forever' => true,
            'repeating' => $appliedCycles < max(1, (int) $discount->getAttribute('duration_in_months')),
            default => $appliedCycles < 1,
        };
    }

    public function computeDiscountAmount(float $amount, string $couponType, float $couponValue, ?Coupon $coupon = null): float
    {
        $discountAmount = $couponType === 'percentage'
            ? round($amount * ($couponValue / 100), 2)
            : round($couponValue, 2);

        $maxDiscountAmount = $coupon?->getAttribute('max_discount_amount');

        if (is_numeric($maxDiscountAmount)) {
            $discountAmount = min($discountAmount, (float) $maxDiscountAmount);
        }

        return max(0.0, min(round($discountAmount, 2), $amount));
    }
}
