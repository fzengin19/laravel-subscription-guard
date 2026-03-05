<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\ProviderEventDispatcherInterface;

final class PaytrProviderEventDispatcher implements ProviderEventDispatcherInterface
{
    public function dispatch(string $event, array $context = []): void {}
}
