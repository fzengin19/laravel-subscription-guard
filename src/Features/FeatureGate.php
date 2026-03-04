<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Features;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;

final class FeatureGate implements FeatureGateInterface
{
    public function can(mixed $subject, string $feature): bool
    {
        return $feature !== '';
    }

    public function limit(mixed $subject, string $limit): int
    {
        return $limit !== '' ? 1 : 0;
    }

    public function incrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool
    {
        return $limit !== '' && $amount > 0;
    }
}
