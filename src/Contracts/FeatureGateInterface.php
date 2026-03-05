<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Contracts;

interface FeatureGateInterface
{
    public function can(mixed $subject, string $feature): bool;

    public function limit(mixed $subject, string $limit): int;

    public function currentUsage(mixed $subject, string $limit): float;

    public function incrementUsage(mixed $subject, string $limit, int|float $amount = 1): bool;
}
