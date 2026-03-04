<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;

class LaravelSubscriptionGuardCommand extends Command
{
    protected $signature = 'subguard:install';

    protected $description = 'Install and verify Laravel Subscription Guard core setup';

    public function handle(): int
    {
        $webhookPrefix = (string) config('subscription-guard.webhooks.prefix', 'subguard/webhooks');

        $this->info('Laravel Subscription Guard core setup ready.');
        $this->line('');
        $this->line('Next steps:');
        $this->line(' - php artisan vendor:publish --tag="laravel-subscription-guard-config"');
        $this->line(' - php artisan migrate');
        $this->line(' - Configure providers in config/subscription-guard.php');
        $this->line(' - Register scheduler commands in app Console Kernel');
        $this->line('');
        $this->line('Webhook endpoint prefix: /'.trim($webhookPrefix, '/'));

        return self::SUCCESS;
    }
}
