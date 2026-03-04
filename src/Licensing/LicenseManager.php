<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Licensing;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\ValidationResult;

final class LicenseManager implements LicenseManagerInterface
{
    public function algorithm(): string
    {
        return (string) config('subscription-guard.license.algorithm', 'ed25519');
    }

    public function generate(int|string $planId, int|string $ownerId): string
    {
        return strtoupper((string) config('app.name', 'SUBGUARD')).'-'.bin2hex(random_bytes(8));
    }

    public function validate(string $licenseKey): ValidationResult
    {
        if ($licenseKey === '') {
            return new ValidationResult(false, 'License key is empty.');
        }

        return new ValidationResult(true);
    }

    public function activate(string $licenseKey, string $domain): bool
    {
        return $licenseKey !== '' && $domain !== '';
    }

    public function deactivate(string $licenseKey, string $domain): bool
    {
        return $licenseKey !== '' && $domain !== '';
    }

    public function checkFeature(string $licenseKey, string $feature): bool
    {
        return $licenseKey !== '' && $feature !== '';
    }

    public function checkLimit(string $licenseKey, string $limit): int
    {
        return $licenseKey !== '' && $limit !== '' ? 1 : 0;
    }

    public function revoke(string $licenseKey, string $reason): bool
    {
        return $licenseKey !== '' && $reason !== '';
    }
}
