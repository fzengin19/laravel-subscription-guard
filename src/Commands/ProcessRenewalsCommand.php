<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;

final class ProcessRenewalsCommand extends Command
{
    protected $signature = 'subguard:process-renewals {--date= : Process subscriptions due until this date-time}';

    protected $description = 'Dispatch due self-managed subscription renewals';

    public function handle(SubscriptionServiceInterface $subscriptionService): int
    {
        $date = $this->resolveDate((string) $this->option('date'));
        $count = $subscriptionService->processRenewals($date);

        $this->info(sprintf('Dispatched %d renewal candidate(s).', $count));

        return self::SUCCESS;
    }

    private function resolveDate(string $date): Carbon
    {
        return $date !== '' ? Carbon::parse($date) : now();
    }
}
