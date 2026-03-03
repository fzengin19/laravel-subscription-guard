<?php

namespace SubscriptionGuard\LaravelSubscriptionGuard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SubscriptionGuard\LaravelSubscriptionGuard\LaravelSubscriptionGuard
 */
class LaravelSubscriptionGuard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SubscriptionGuard\LaravelSubscriptionGuard\LaravelSubscriptionGuard::class;
    }
}
