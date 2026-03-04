<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;

final class ProcessPlanChangesCommand extends Command
{
    protected $signature = 'subguard:process-plan-changes {--date= : Process scheduled plan changes due until this date-time}';

    protected $description = 'Dispatch due scheduled plan change jobs';

    public function handle(SubscriptionServiceInterface $subscriptionService): int
    {
        $date = $this->resolveDate((string) $this->option('date'));
        $count = $subscriptionService->processScheduledPlanChanges($date);

        $this->info(sprintf('Dispatched %d scheduled plan change candidate(s).', $count));

        return self::SUCCESS;
    }

    private function resolveDate(string $date): Carbon
    {
        return $date !== '' ? Carbon::parse($date) : now();
    }
}
