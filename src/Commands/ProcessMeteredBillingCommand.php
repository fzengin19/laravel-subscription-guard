<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\MeteredBillingProcessor;

final class ProcessMeteredBillingCommand extends Command
{
    protected $signature = 'subguard:process-metered-billing {--date= : Process usage due until this date-time}';

    protected $description = 'Process metered usage billing candidates and reset usage for closed periods';

    public function handle(MeteredBillingProcessor $processor): int
    {
        $date = $this->resolveDate((string) $this->option('date'));
        $count = $processor->process($date);

        $this->info(sprintf('Processed %d metered billing subscription(s).', $count));

        return self::SUCCESS;
    }

    private function resolveDate(string $date): Carbon
    {
        return $date !== '' ? Carbon::parse($date) : now();
    }
}
