<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\ProviderEvents;

use Illuminate\Contracts\Container\Container;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\ProviderEventDispatcherInterface;

final class ProviderEventDispatcherResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(string $provider): ProviderEventDispatcherInterface
    {
        $class = config(sprintf('subscription-guard.providers.drivers.%s.event_dispatcher', $provider));

        if (is_string($class) && class_exists($class) && is_subclass_of($class, ProviderEventDispatcherInterface::class)) {
            return $this->container->make($class);
        }

        return $this->container->make(NullProviderEventDispatcher::class);
    }
}
