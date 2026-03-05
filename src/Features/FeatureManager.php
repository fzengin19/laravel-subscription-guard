<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Features;

use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;

final class FeatureManager
{
    public function __construct(
        private readonly FeatureGateInterface $featureGate,
        private readonly ScheduleGate $scheduleGate,
    ) {}

    public function can(mixed $subject, string $feature): bool
    {
        if (! $this->featureGate->can($subject, $feature)) {
            return false;
        }

        $until = $this->scheduleGate->availableUntil($subject, $feature);

        return ! $until instanceof Carbon || $until->isFuture();
    }

    public function limit(mixed $subject, string $limit): int
    {
        return $this->featureGate->limit($subject, $limit);
    }

    public function currentUsage(mixed $subject, string $limit): float
    {
        return $this->featureGate->currentUsage($subject, $limit);
    }

    public function incrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool
    {
        return $this->featureGate->incrementUsage($subject, $limit, $amount);
    }

    public function availableUntil(mixed $subject, string $feature): ?Carbon
    {
        return $this->scheduleGate->availableUntil($subject, $feature);
    }
}
