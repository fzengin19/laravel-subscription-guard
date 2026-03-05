<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\PaymentProviderInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\ProviderException;

final class PaymentManager
{
    public function providers(): array
    {
        $drivers = config('subscription-guard.providers.drivers', []);

        return is_array($drivers) ? $drivers : [];
    }

    public function defaultProvider(): string
    {
        return (string) config('subscription-guard.providers.default', 'iyzico');
    }

    public function providerConfig(?string $provider = null): array
    {
        $resolved = $provider ?? $this->defaultProvider();

        return $this->providers()[$resolved] ?? [];
    }

    public function hasProvider(string $provider): bool
    {
        return array_key_exists($provider, $this->providers());
    }

    public function managesOwnBilling(?string $provider = null): bool
    {
        return (bool) ($this->providerConfig($provider)['manages_own_billing'] ?? false);
    }

    public function queueName(string $key, string $fallback): string
    {
        $name = config('subscription-guard.queue.'.$key, $fallback);

        return is_string($name) && $name !== '' ? $name : $fallback;
    }

    public function provider(string $provider): PaymentProviderInterface
    {
        if (! $this->hasProvider($provider)) {
            throw new ProviderException(sprintf('Unsupported payment provider [%s].', $provider));
        }

        $providerConfig = $this->providerConfig($provider);
        $providerClass = $providerConfig['class'] ?? null;

        if (! is_string($providerClass) || $providerClass === '') {
            throw new ProviderException(sprintf('Provider class is not configured for [%s].', $provider));
        }

        $instance = app($providerClass);

        if (! $instance instanceof PaymentProviderInterface) {
            throw new ProviderException(sprintf('Provider [%s] must implement PaymentProviderInterface.', $providerClass));
        }

        return $instance;
    }

    public function defaultProviderAdapter(): PaymentProviderInterface
    {
        return $this->provider($this->defaultProvider());
    }
}
