<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPlanChange extends Model
{
    protected $guarded = ['id'];

    public function markFailed(string $message): void
    {
        $this->setAttribute('status', 'failed');
        $this->setAttribute('error_message', $message);
        $this->setAttribute('processed_at', now());
        $this->save();
    }

    public function markProcessed(): void
    {
        $this->setAttribute('status', 'processed');
        $this->setAttribute('processed_at', now());
        $this->setAttribute('error_message', null);
        $this->save();
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'scheduled_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }
}
