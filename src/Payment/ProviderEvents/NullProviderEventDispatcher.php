<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\ProviderEvents;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\ProviderEventDispatcherInterface;

final class NullProviderEventDispatcher implements ProviderEventDispatcherInterface
{
    public function dispatch(string $event, array $context = []): void {}
}
