<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;

final class ProcessDunningCommand extends Command
{
    protected $signature = 'subguard:process-dunning {--date= : Process dunning retries due until this date-time}';

    protected $description = 'Dispatch due dunning retry jobs';

    public function handle(SubscriptionServiceInterface $subscriptionService): int
    {
        $date = $this->resolveDate((string) $this->option('date'));
        $count = $subscriptionService->processDunning($date);

        $this->info(sprintf('Dispatched %d dunning retry candidate(s).', $count));

        return self::SUCCESS;
    }

    private function resolveDate(string $date): Carbon
    {
        return $date !== '' ? Carbon::parse($date) : now();
    }
}
