<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\ProcessTrialExpiryJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class ProcessTrialExpiryCommand extends Command
{
    protected $signature = 'subguard:process-trial-expiry';

    protected $description = 'Dispatch trial-expiry jobs for trialing subscriptions whose trial_ends_at has passed.';

    public function handle(PaymentManager $paymentManager): int
    {
        $dispatched = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->lazyById(500)
            ->each(function (Subscription $subscription) use ($paymentManager, &$dispatched): void {
                ProcessTrialExpiryJob::dispatch((int) $subscription->getKey())
                    ->onQueue($paymentManager->queueName('queue', 'subguard-main'));
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} trial expiry job(s).");

        return self::SUCCESS;
    }
}
