<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Features;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseUsage;

final class FeatureGate implements FeatureGateInterface
{
    public function __construct(private readonly LicenseManagerInterface $licenseManager) {}

    public function can(mixed $subject, string $feature): bool
    {
        $licenseKey = $this->resolveLicenseKey($subject);

        if ($licenseKey === null || $feature === '') {
            return false;
        }

        $license = License::query()->where('key', $licenseKey)->first();

        if ($license instanceof License) {
            $overrides = $license->getAttribute('feature_overrides');

            if (is_array($overrides) && array_key_exists($feature, $overrides)) {
                $override = $overrides[$feature];

                if (is_array($override)) {
                    return (bool) ($override['enabled'] ?? false);
                }

                return (bool) $override;
            }
        }

        return $this->licenseManager->checkFeature($licenseKey, $feature);
    }

    public function limit(mixed $subject, string $limit): int
    {
        $licenseKey = $this->resolveLicenseKey($subject);

        if ($licenseKey === null || $limit === '') {
            return 0;
        }

        $license = License::query()->where('key', $licenseKey)->first();

        if ($license instanceof License) {
            $overrides = $license->getAttribute('limit_overrides');

            if (is_array($overrides) && array_key_exists($limit, $overrides)) {
                return max(0, (int) $overrides[$limit]);
            }
        }

        return max(0, $this->licenseManager->checkLimit($licenseKey, $limit));
    }

    public function currentUsage(mixed $subject, string $limit): float
    {
        $licenseKey = $this->resolveLicenseKey($subject);

        if ($licenseKey === null || $limit === '') {
            return 0.0;
        }

        $license = License::query()->where('key', $licenseKey)->first();

        if (! $license instanceof License) {
            return 0.0;
        }

        $periodStart = Carbon::now()->startOfMonth();
        $periodEnd = (clone $periodStart)->endOfMonth();

        return (float) LicenseUsage::query()
            ->where('license_id', $license->getKey())
            ->where('metric', $limit)
            ->where('period_start', '>=', $periodStart)
            ->where('period_end', '<=', $periodEnd)
            ->sum('quantity');
    }

    public function incrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool
    {
        $licenseKey = $this->resolveLicenseKey($subject);

        if ($licenseKey === null || $limit === '' || $amount <= 0) {
            return false;
        }

        return DB::transaction(function () use ($licenseKey, $limit, $amount): bool {
            $license = License::query()
                ->where('key', $licenseKey)
                ->lockForUpdate()
                ->first();

            if (! $license instanceof License) {
                return false;
            }

            $maxAllowed = $this->limit($licenseKey, $limit);

            if ($maxAllowed <= 0) {
                return false;
            }

            $periodStart = Carbon::now()->startOfMonth();
            $periodEnd = (clone $periodStart)->endOfMonth();
            $currentUsage = (float) LicenseUsage::query()
                ->where('license_id', $license->getKey())
                ->where('metric', $limit)
                ->where('period_start', '>=', $periodStart)
                ->where('period_end', '<=', $periodEnd)
                ->lockForUpdate()
                ->sum('quantity');

            $nextUsage = $currentUsage + (float) $amount;

            if ($nextUsage > (float) $maxAllowed) {
                return false;
            }

            LicenseUsage::query()->create([
                'license_id' => $license->getKey(),
                'metric' => $limit,
                'quantity' => (float) $amount,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'metadata' => [],
            ]);

            return true;
        });
    }

    private function resolveLicenseKey(mixed $subject): ?string
    {
        if (is_string($subject)) {
            $value = trim($subject);

            return $value === '' ? null : $value;
        }

        if ($subject instanceof License) {
            $value = trim((string) $subject->getAttribute('key'));

            return $value === '' ? null : $value;
        }

        return null;
    }
}
