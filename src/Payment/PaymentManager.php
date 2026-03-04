<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment;

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
}
