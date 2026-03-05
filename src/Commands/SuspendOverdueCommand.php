<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\DispatchBillingNotificationsJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;

final class SuspendOverdueCommand extends Command
{
    protected $signature = 'subguard:suspend-overdue {--date= : Suspend subscriptions where grace period ended before this date-time}';

    protected $description = 'Suspend overdue subscriptions after grace period';

    public function handle(): int
    {
        $date = $this->resolveDate((string) $this->option('date'));
        $count = 0;

        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', $date)
            ->get();

        foreach ($subscriptions as $subscription) {
            DB::transaction(function () use (&$count, $subscription): void {
                $locked = Subscription::query()
                    ->lockForUpdate()
                    ->find($subscription->getKey());

                if (! $locked instanceof Subscription) {
                    return;
                }

                if ((string) $locked->getAttribute('status') !== SubscriptionStatus::PastDue->value) {
                    return;
                }

                $locked->setAttribute('status', SubscriptionStatus::Suspended->value);
                $locked->save();

                $licenseId = $locked->getAttribute('license_id');

                if (is_numeric($licenseId)) {
                    $license = License::query()
                        ->lockForUpdate()
                        ->find((int) $licenseId);

                    if ($license instanceof License) {
                        $license->setAttribute('status', SubscriptionStatus::Suspended->value);
                        $license->save();
                    }
                }

                DispatchBillingNotificationsJob::dispatch('subscription.suspended', [
                    'subscription_id' => $locked->getKey(),
                    'license_id' => is_numeric($licenseId) ? (int) $licenseId : null,
                ]);

                $count++;
            });
        }

        $this->info(sprintf('Suspended %d overdue subscription(s).', $count));

        return self::SUCCESS;
    }

    private function resolveDate(string $date): Carbon
    {
        return $date !== '' ? Carbon::parse($date) : now();
    }
}
