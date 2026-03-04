<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Contracts;

use SubscriptionGuard\LaravelSubscriptionGuard\Data\ValidationResult;

interface LicenseManagerInterface
{
    public function generate(int|string $planId, int|string $ownerId): string;

    public function validate(string $licenseKey): ValidationResult;

    public function activate(string $licenseKey, string $domain): bool;

    public function deactivate(string $licenseKey, string $domain): bool;

    public function checkFeature(string $licenseKey, string $feature): bool;

    public function checkLimit(string $licenseKey, string $limit): int;

    public function revoke(string $licenseKey, string $reason): bool;
}
