<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;

final class CheckLicenseCommand extends Command
{
    protected $signature = 'subguard:check-license {license_key : The signed license key to validate}';

    protected $description = 'Validate a license key and print diagnostic details';

    public function handle(LicenseManagerInterface $licenseManager): int
    {
        $licenseKey = trim((string) $this->argument('license_key'));

        if ($licenseKey === '') {
            $this->error('license_key must not be empty.');

            return self::FAILURE;
        }

        $result = $licenseManager->validate($licenseKey);

        $this->line(sprintf('valid: %s', $result->valid ? 'yes' : 'no'));
        $this->line(sprintf('reason: %s', $result->reason ?? '-'));

        if ($result->metadata !== []) {
            $this->line('metadata: '.json_encode($result->metadata, JSON_UNESCAPED_SLASHES));
        }

        return $result->valid ? self::SUCCESS : self::FAILURE;
    }
}
