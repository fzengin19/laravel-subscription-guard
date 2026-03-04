<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncBillingProfileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $billableType,
        public int|string $billableId,
    ) {
        $this->onQueue((string) config('subscription-guard.queue.notifications_queue', 'subguard-notifications'));
    }

    public function handle(): void
    {
        if (! class_exists($this->billableType)) {
            return;
        }

        if (! is_subclass_of($this->billableType, Model::class)) {
            return;
        }

        $modelClass = $this->billableType;
        $owner = $modelClass::query()->find($this->billableId);

        if ($owner === null || ! method_exists($owner, 'billingProfile')) {
            return;
        }

        $owner->billingProfile()->updateOrCreate([], [
            'name' => $owner->name ?? null,
            'email' => $owner->email ?? null,
        ]);
    }
}
