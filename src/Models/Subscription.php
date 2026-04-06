<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\SubGuardException;

class Subscription extends Model
{
    use SoftDeletes;

    protected $guarded = [
        'id',
        'provider_subscription_id',
        'provider_customer_id',
        'status',
        'amount',
        'tax_amount',
        'tax_rate',
        'currency',
        'trial_ends_at',
        'next_billing_date',
        'current_period_start',
        'current_period_end',
        'grace_ends_at',
        'resumes_at',
        'cancels_at',
        'cancelled_at',
        'scheduled_change_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'trial_ends_at' => 'datetime',
            'next_billing_date' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'resumes_at' => 'datetime',
            'cancels_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'amount' => 'float',
            'tax_amount' => 'float',
            'tax_rate' => 'float',
        ];
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scheduledPlanChanges(): HasMany
    {
        return $this->hasMany(ScheduledPlanChange::class);
    }

    public function scheduledChange(): BelongsTo
    {
        return $this->belongsTo(ScheduledPlanChange::class, 'scheduled_change_id');
    }

    public function transitionTo(SubscriptionStatus $newStatus): void
    {
        $currentStatusValue = (string) $this->getAttribute('status');
        $currentStatus = SubscriptionStatus::normalize($currentStatusValue);

        if (! $currentStatus instanceof SubscriptionStatus) {
            $this->setAttribute('status', $newStatus->value);

            return;
        }

        if (! $currentStatus->canTransitionTo($newStatus)) {
            throw new SubGuardException(
                sprintf(
                    'Invalid subscription state transition: %s → %s (subscription #%s)',
                    $currentStatus->value,
                    $newStatus->value,
                    (string) $this->getKey()
                )
            );
        }

        $this->setAttribute('status', $newStatus->value);
    }
}
