<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment;

use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\ProviderException;

final class ProviderMockModeGuard
{
    public static function ensureNotProduction(string $provider): void
    {
        if (! function_exists('app')) {
            return;
        }

        if (app()->environment('production')) {
            throw new ProviderException(sprintf(
                'Provider mock mode is enabled for [%s] in production. Refusing to execute. '
                .'Disable %s_MOCK (or subscription-guard.providers.drivers.%s.mock) before deploying.',
                $provider,
                strtoupper($provider),
                $provider
            ));
        }
    }
}
