<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Contracts;

interface ProviderEventDispatcherInterface
{
    public function dispatch(string $event, array $context = []): void;
}
