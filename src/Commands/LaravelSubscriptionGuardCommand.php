<?php

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;

class LaravelSubscriptionGuardCommand extends Command
{
    public $signature = 'laravel-subscription-guard';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
