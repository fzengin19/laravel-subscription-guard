<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;

final class GenerateLicenseCommand extends Command
{
    protected $signature = 'subguard:generate-license {plan_id : Plan identifier} {user_id : Owner/User identifier} {--domain= : Optional activation domain}';

    protected $description = 'Generate a signed license key for a plan and owner';

    public function handle(LicenseManagerInterface $licenseManager): int
    {
        $planId = (string) $this->argument('plan_id');
        $userId = (string) $this->argument('user_id');
        $licenseKey = $licenseManager->generate($planId, $userId);
        $domain = trim((string) $this->option('domain'));

        if ($domain !== '') {
            $activated = $licenseManager->activate($licenseKey, $domain);
            $this->line(sprintf('activation: %s', $activated ? 'ok' : 'failed'));
        }

        $this->info('License generated successfully.');
        $this->line('license_key: '.$licenseKey);

        return self::SUCCESS;
    }
}
