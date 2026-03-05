<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $guarded = [
        'id',
        'provider',
        'provider_transaction_id',
        'provider_refund_id',
        'idempotency_key',
        'type',
        'status',
        'amount',
        'tax_amount',
        'tax_rate',
        'discount_amount',
        'refunded_amount',
        'currency',
        'provider_currency',
        'exchange_rate',
        'fee',
        'retry_count',
        'next_retry_at',
        'last_retry_at',
        'failure_reason',
        'processed_at',
        'refunded_at',
        'provider_response',
        'coupon_id',
        'discount_id',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_retry_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
